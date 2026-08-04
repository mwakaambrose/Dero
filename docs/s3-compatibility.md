# S3 compatibility

Dero is not a complete S3 implementation and this document does not claim it is.
It records what was verified, how, and where behaviour deviates.

Every row marked **Verified** has a passing test in
[`tests/Interop/`](../tests/Interop/) behind it — a real AWS CLI, boto3 or curl
request against a running server. Rows marked **Untested** are implemented and
covered by the Pest suite, but the Pest suite proves only that Dero is
consistent with itself. A row is never marked Verified on the strength of a unit
test.

## Verified with

| Client | Version |
|---|---|
| AWS CLI | `aws-cli/2.36.15` |
| boto3 / botocore | `boto3 1.43.63` |
| curl | `--aws-sigv4` signer |

Last run: 2026-08-04, against `php artisan serve` on macOS 15 (arm64), PHP 8.4.21.

## Service and bucket operations

| Operation | Status | Notes |
|---|---|---|
| ListBuckets | Verified | `aws s3 ls` |
| CreateBucket | Verified | `aws s3 mb`. Returns `BucketAlreadyOwnedByYou` for your own name, `BucketAlreadyExists` for someone else's |
| DeleteBucket | Verified | `aws s3 rb`. Returns `BucketNotEmpty` unless empty |
| HeadBucket | Untested | Implemented; exercised by the Pest suite only |
| GetBucketLocation | Untested | Returns the configured `S3_REGION` |
| GetBucketCors | Verified | `aws s3api get-bucket-cors` |
| PutBucketCors | Verified | `aws s3api put-bucket-cors` |
| DeleteBucketCors | Verified | `aws s3api delete-bucket-cors` |
| CORS preflight (`OPTIONS`) | Verified | curl preflight returns `Access-Control-Allow-Origin` |

## Listing

| Operation | Status | Notes |
|---|---|---|
| ListObjectsV2 | Verified | Prefix, delimiter, `max-keys`, continuation tokens. boto3 paginator verified across multiple pages |
| ListObjects (v1) | Verified | `Marker` / `NextMarker`. Present because Cyberduck, older SDKs and some rclone configurations still send it |
| CommonPrefixes (delimiter) | Verified | Both listing versions |
| ListMultipartUploads | Verified | `aws s3api list-multipart-uploads`, with `prefix` |
| ListParts | Untested | Implemented; Pest only |
| `encoding-type=url` | Untested | Implemented; Pest only |

Keys are returned in bytewise lexicographic order, which is what continuation
tokens depend on. On MySQL this requires the `utf8mb4_bin` collation the
migration applies — see [architecture.md](architecture.md#the-two-mysql-constraints).

## Object operations

| Operation | Status | Notes |
|---|---|---|
| PutObject | Verified | Including `aws-chunked` framing, which the CLI uses by default |
| GetObject | Verified | Byte-identical round-trip at 1 KB, 1 MB, 100 MB and 1 GB |
| GetObject with `Range` | Verified | Single range. See deviations below for multi-range |
| HeadObject | Verified | Size, ETag, `Content-Type` and user metadata |
| DeleteObject | Verified | Idempotent — 204 whether or not the key existed, which `aws s3 sync` depends on |
| DeleteObjects (batch) | Verified | `aws s3api delete-objects`, per-key errors reported |
| CopyObject | Verified | Server-side; bytes never travel to the client |
| User metadata (`x-amz-meta-*`) | Verified | Round-trips through boto3 |
| `Content-Type` and friends | Verified | Echoed byte for byte, including `text/*` |

## Multipart upload

| Operation | Status | Notes |
|---|---|---|
| CreateMultipartUpload | Verified | |
| UploadPart | Verified | |
| CompleteMultipartUpload | Verified | AWS-compatible composite ETag (`<md5-of-concatenated-part-digests>-<count>`) |
| AbortMultipartUpload | Verified | Parts reclaimed from disk |
| UploadPartCopy | Untested | Implemented, including `x-amz-copy-source-range`; Pest only |
| Automatic multipart via `aws s3 cp` | Verified | 100 MB and 1 GB, checksum-verified |

## Authentication

| Feature | Status | Notes |
|---|---|---|
| SigV4, `Authorization` header | Verified | AWS CLI, boto3 and curl `--aws-sigv4` |
| SigV4, presigned query | Verified | Fetched with no credentials at all |
| `aws-chunked` payload framing | Verified | `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` |
| `UNSIGNED-PAYLOAD` | Verified | |
| Payload digest verification | Untested | A body that does not match the signed `x-amz-content-sha256` is rejected with `XAmzContentSHA256Mismatch`; Pest only |
| Clock skew rejection | Untested | ±900 s by default; Pest only |
| Presigned URL expiry | Untested | Pest only |
| Anonymous read of a public bucket | Untested | Pest only |
| **SigV2** | **Not supported** | Deprecated and weaker. See the client-configuration note below |

## Addressing

| Style | Status | Notes |
|---|---|---|
| Path style (`endpoint/bucket/key`) | Verified | |
| Virtual-host style (`bucket.endpoint/key`) | Untested | Implemented and Pest-covered. Not interop-verified because it needs wildcard DNS, which the test harness does not have |

## Not implemented

These return `501 NotImplemented` with the sub-resource named in the response,
rather than silently succeeding or falling through to a different operation:

Versioning · Object lock, retention and legal hold · Lifecycle rules ·
Replication · Server-side encryption (`?encryption`) · Object and bucket tagging ·
ACLs (bucket and object) · Bucket policies over the S3 API · Public access block ·
Ownership controls · Website hosting · Inventory · Analytics · Metrics ·
Intelligent tiering · Transfer acceleration · Request payment · Server access
logging · Bucket notifications · `SelectObjectContent` · `RestoreObject` ·
`GetObjectAttributes` · BitTorrent

Storage classes are accepted and ignored; everything reports `STANDARD`.

## Deliberate deviations

**Multi-range requests return the whole object.** A `Range` header naming more
than one range is answered with `200` and the complete body rather than a
`multipart/byteranges` document. RFC 7233 permits a server to ignore a range it
does not wish to satisfy, and no common client sends multi-range to S3.

**Per-chunk signatures in `aws-chunked` bodies are parsed, not verified.** The
framing is decoded and each chunk's declared length is honoured; the
`chunk-signature` value on each chunk is not independently checked. The request
signature over the whole payload declaration is verified, so an attacker cannot
substitute a body — but a per-chunk forgery within an already-authenticated
request would not be detected.

**Cross-tenant access returns `AccessDenied` where AWS returns `NoSuchBucket`.**
Deliberate: a distinct answer for "exists but is not yours" would turn the status
code into an oracle for other tenants' bucket names. The
[attack-surface suite](../tests/Feature/S3/Security/AttackSurfaceTest.php)
asserts that a present and an absent key in another tenant's bucket answer
identically.

**Bucket names are globally unique across the installation**, as they are in AWS.
Two teams cannot both own a bucket called `production`.

**Object metadata is a JSON column, not a table.** Metadata is only ever read
with its object, so a join buys nothing.

**A multipart object sends no `x-amz-checksum-sha256`.** The stored digest covers
the assembled bytes, whereas S3's composite checksum covers the parts' digests.
Sending the former would fail a client's validation, so nothing is sent — which
clients accept.

## Client configuration

Two settings are required against a non-AWS endpoint, and neither fails
obviously:

**SigV4 must be requested explicitly.** botocore still presigns with SigV2
against a custom endpoint unless told otherwise. Dero implements only SigV4, so
`generate_presigned_url()` produces a URL Dero rejects with `AccessDenied` while
every other operation keeps working.

```python
boto3.client("s3", endpoint_url=..., config=Config(signature_version="s3v4"))
```

**Path-style addressing** is needed unless you have wildcard DNS for
`*.your-endpoint`:

```python
config=Config(s3={"addressing_style": "path"}, signature_version="s3v4")
```

```bash
aws configure set default.s3.addressing_style path
```

## Reproducing this matrix

```bash
tests/Interop/serve.sh &
tests/Interop/aws-cli.sh
.venv/bin/python -m pytest tests/Interop/test_boto3.py -v
```

If a row here is marked Verified and the corresponding assertion is not in those
files, the row is wrong. Please open an issue.
