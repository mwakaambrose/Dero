# Interoperability suite

The Pest suite proves Dero is self-consistent. Only this suite proves it is
S3-compatible: it drives a running instance with the real AWS CLI, real boto3,
and curl's own SigV4 signer.

## Running it

```bash
tests/Interop/serve.sh &          # starts on 127.0.0.1:8787
tests/Interop/aws-cli.sh
.venv/bin/python -m pytest tests/Interop/test_boto3.py -v
```

`serve.sh` uses a scratch SQLite database and a scratch storage root under
`storage/interop/`, so it never touches your development data. It writes
credentials to `tests/Interop/.env.interop`, which both other scripts read.

Stop it with `Ctrl-C`, or `pkill -f 'artisan serve.*8787'`.

## Verified with

- `aws-cli/2.36.15`
- `boto3 1.43.63`
- `curl` (`--aws-sigv4`)

## Client configuration these scripts rely on

Two settings are not optional, and neither fails in an obvious way:

**Path-style addressing.** Virtual-host addressing sends
`bucket.127.0.0.1`, which does not resolve. Set
`addressing_style: path` (boto3) or `aws configure set
default.s3.addressing_style path` (CLI). Against a real deployment with
wildcard DNS, either style works.

**SigV4.** botocore still presigns with the deprecated SigV2 against a custom
endpoint unless told otherwise. Dero implements only SigV4, so
`generate_presigned_url()` produces a URL Dero rejects with `AccessDenied` —
and nothing else in the client misbehaves, which makes it a confusing failure.
Pass `signature_version="s3v4"`.

## What the interop run found that the unit tests could not

Four defects, all in the wire format rather than the logic, and all invisible to
tests that talk to the application through Laravel's test client:

1. `x-amz-checksum-sha256` was emitted as hex. AWS specifies base64, so the CLI
   failed every download with a checksum mismatch.
2. The same header travelled with `206 Partial Content` responses, where it
   describes the whole object rather than the bytes sent. boto3 validated it
   against the partial body and failed every range request.
3. Symfony's `Response::prepare()` appended `; charset=UTF-8` to `text/*`
   content types, so an object stored as `text/markdown` read back as something
   else.
4. PHP's SAPI appended `;charset=UTF-8` again, at header-send time, below every
   layer of PHP code.

The endpoint host must also be configured without a port: route host matching
compares against `Request::getHost()`, which never carries one.
