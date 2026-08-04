"""boto3 interoperability tests against a running Dero instance.

Start the server first::

    tests/Interop/serve.sh &
    .venv/bin/pytest tests/Interop/test_boto3.py -v

Every assertion compares bytes or checksums, never just a status code: a 200
that returns the wrong body is the failure mode that matters.
"""

from __future__ import annotations

import hashlib
import os
import re
import urllib.request
from pathlib import Path

import boto3
import pytest
from botocore.config import Config
from botocore.exceptions import ClientError

ENV_FILE = Path(__file__).parent / ".env.interop"
BUCKET = "boto-interop"


def _load_env() -> dict[str, str]:
    if not ENV_FILE.exists():
        pytest.skip(f"{ENV_FILE} missing — run tests/Interop/serve.sh first")

    values: dict[str, str] = {}
    for line in ENV_FILE.read_text().splitlines():
        match = re.match(r"^export (\w+)=(.*)$", line.strip())
        if match:
            values[match.group(1)] = match.group(2)
    return values


ENV = _load_env()
ENDPOINT = ENV.get("DERO_ENDPOINT", "http://127.0.0.1:8787")


@pytest.fixture(scope="session")
def s3():
    client = boto3.client(
        "s3",
        endpoint_url=ENDPOINT,
        aws_access_key_id=ENV["AWS_ACCESS_KEY_ID"],
        aws_secret_access_key=ENV["AWS_SECRET_ACCESS_KEY"],
        region_name=ENV.get("AWS_DEFAULT_REGION", "us-east-1"),
        config=Config(
            # Virtual-host addressing would need bucket.127.0.0.1 to resolve.
            s3={"addressing_style": "path"},
            # Required, not incidental. Against a non-AWS endpoint botocore
            # still presigns with SigV2 unless told otherwise, and Dero
            # implements only SigV4 — SigV2 is deprecated and weaker. Without
            # this, generate_presigned_url() produces a URL Dero rejects with
            # AccessDenied, and no other operation reveals the problem.
            signature_version="s3v4",
        ),
    )

    client.create_bucket(Bucket=BUCKET)
    yield client

    # Best-effort teardown; a failed test may leave objects behind.
    try:
        keys = [
            {"Key": obj["Key"]}
            for page in client.get_paginator("list_objects_v2").paginate(Bucket=BUCKET)
            for obj in page.get("Contents", [])
        ]
        for batch in (keys[i : i + 1000] for i in range(0, len(keys), 1000)):
            client.delete_objects(Bucket=BUCKET, Delete={"Objects": batch})
        client.delete_bucket(Bucket=BUCKET)
    except ClientError:
        pass


def test_bucket_appears_in_list_buckets(s3):
    names = [b["Name"] for b in s3.list_buckets()["Buckets"]]
    assert BUCKET in names


def test_put_and_get_object_round_trips_bytes(s3):
    body = b"hello dero \xf0\x9f\x8c\xbe"
    s3.put_object(Bucket=BUCKET, Key="round-trip.bin", Body=body)

    assert s3.get_object(Bucket=BUCKET, Key="round-trip.bin")["Body"].read() == body


def test_head_object_reports_size_and_etag(s3):
    body = b"a" * 1024
    s3.put_object(Bucket=BUCKET, Key="head.bin", Body=body)

    head = s3.head_object(Bucket=BUCKET, Key="head.bin")

    assert head["ContentLength"] == 1024
    assert head["ETag"].strip('"') == hashlib.md5(body).hexdigest()


def test_user_metadata_and_content_type_survive(s3):
    s3.put_object(
        Bucket=BUCKET,
        Key="meta.txt",
        Body=b"x",
        ContentType="text/markdown",
        Metadata={"author": "dero"},
    )

    head = s3.head_object(Bucket=BUCKET, Key="meta.txt")

    assert head["ContentType"] == "text/markdown"
    assert head["Metadata"]["author"] == "dero"


def test_list_objects_v2_paginates_across_more_than_one_page(s3):
    for i in range(15):
        s3.put_object(Bucket=BUCKET, Key=f"paged/{i:03d}.txt", Body=b"x")

    pages = list(
        s3.get_paginator("list_objects_v2").paginate(
            Bucket=BUCKET, Prefix="paged/", PaginationConfig={"PageSize": 5}
        )
    )

    keys = [obj["Key"] for page in pages for obj in page["Contents"]]

    assert len(pages) > 1
    assert len(keys) == 15
    assert keys == sorted(keys)


def test_delimiter_produces_common_prefixes(s3):
    s3.put_object(Bucket=BUCKET, Key="folder/one.txt", Body=b"x")
    s3.put_object(Bucket=BUCKET, Key="top.txt", Body=b"x")

    result = s3.list_objects_v2(Bucket=BUCKET, Delimiter="/")

    assert "folder/" in [p["Prefix"] for p in result["CommonPrefixes"]]


def test_upload_file_uses_multipart_for_a_large_object(s3, tmp_path):
    source = tmp_path / "large.bin"
    source.write_bytes(os.urandom(100 * 1024 * 1024))
    expected = hashlib.sha256(source.read_bytes()).hexdigest()

    s3.upload_file(str(source), BUCKET, "large.bin")

    downloaded = tmp_path / "large-out.bin"
    s3.download_file(BUCKET, "large.bin", str(downloaded))

    assert hashlib.sha256(downloaded.read_bytes()).hexdigest() == expected
    # A multipart ETag carries a "-<partcount>" suffix; a single-part one does not.
    assert "-" in s3.head_object(Bucket=BUCKET, Key="large.bin")["ETag"]


def test_range_request_returns_only_the_requested_bytes(s3):
    s3.put_object(Bucket=BUCKET, Key="ranged.txt", Body=b"0123456789")

    response = s3.get_object(Bucket=BUCKET, Key="ranged.txt", Range="bytes=2-5")

    assert response["Body"].read() == b"2345"
    assert response["ContentRange"] == "bytes 2-5/10"


def test_presigned_url_is_fetchable_without_credentials(s3):
    s3.put_object(Bucket=BUCKET, Key="presigned.txt", Body=b"presigned body")

    url = s3.generate_presigned_url(
        "get_object", Params={"Bucket": BUCKET, "Key": "presigned.txt"}, ExpiresIn=300
    )

    with urllib.request.urlopen(url) as response:
        assert response.read() == b"presigned body"


def test_copy_object_duplicates_the_bytes(s3):
    s3.put_object(Bucket=BUCKET, Key="source.txt", Body=b"copy me")

    s3.copy_object(
        Bucket=BUCKET, Key="destination.txt", CopySource=f"{BUCKET}/source.txt"
    )

    assert s3.get_object(Bucket=BUCKET, Key="destination.txt")["Body"].read() == b"copy me"


def test_explicit_multipart_upload_assembles_in_order(s3):
    part = b"m" * (5 * 1024 * 1024)
    tail = b"tail"

    upload = s3.create_multipart_upload(Bucket=BUCKET, Key="assembled.bin")
    upload_id = upload["UploadId"]

    parts = []
    for number, chunk in enumerate([part, tail], start=1):
        result = s3.upload_part(
            Bucket=BUCKET,
            Key="assembled.bin",
            UploadId=upload_id,
            PartNumber=number,
            Body=chunk,
        )
        parts.append({"PartNumber": number, "ETag": result["ETag"]})

    s3.complete_multipart_upload(
        Bucket=BUCKET,
        Key="assembled.bin",
        UploadId=upload_id,
        MultipartUpload={"Parts": parts},
    )

    assert (
        s3.get_object(Bucket=BUCKET, Key="assembled.bin")["Body"].read()
        == part + tail
    )


def test_aborted_multipart_upload_disappears_from_the_listing(s3):
    upload_id = s3.create_multipart_upload(Bucket=BUCKET, Key="aborted.bin")["UploadId"]

    listed = s3.list_multipart_uploads(Bucket=BUCKET)
    assert upload_id in [u["UploadId"] for u in listed.get("Uploads", [])]

    s3.abort_multipart_upload(Bucket=BUCKET, Key="aborted.bin", UploadId=upload_id)

    listed = s3.list_multipart_uploads(Bucket=BUCKET)
    assert upload_id not in [u["UploadId"] for u in listed.get("Uploads", [])]


def test_delete_objects_removes_a_batch(s3):
    for name in ("batch/a.txt", "batch/b.txt"):
        s3.put_object(Bucket=BUCKET, Key=name, Body=b"x")

    result = s3.delete_objects(
        Bucket=BUCKET,
        Delete={"Objects": [{"Key": "batch/a.txt"}, {"Key": "batch/b.txt"}]},
    )

    assert len(result["Deleted"]) == 2
    assert s3.list_objects_v2(Bucket=BUCKET, Prefix="batch/").get("KeyCount", 0) == 0


def test_missing_key_raises_no_such_key(s3):
    with pytest.raises(ClientError) as error:
        s3.get_object(Bucket=BUCKET, Key="definitely-not-here.txt")

    assert error.value.response["Error"]["Code"] == "NoSuchKey"


def test_missing_bucket_raises_no_such_bucket(s3):
    with pytest.raises(ClientError) as error:
        s3.list_objects_v2(Bucket="no-such-bucket-anywhere")

    assert error.value.response["Error"]["Code"] in ("NoSuchBucket", "AccessDenied")
