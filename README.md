<div align="center">

# Dero

**Object storage you keep.**

An S3-compatible object store that runs as one Laravel application on one
server. Point the tools you already use at it and carry on.

</div>

---

*Dero* is the granary of the Luo and Acholi: a woven basket raised on legs,
roofed with thatch, keeping the harvest dry and out of reach. That is the whole
idea — your data, stored where you can see it, in something you can maintain.

Object storage usually comes two ways. Someone else's cloud is easy to start
with, until the egress bill arrives and your data lives somewhere you cannot
inspect. A distributed cluster gives you control, at the cost of operating
distributed storage. Dero is the third option: one application, one server, no
cluster, no egress charges, no vendor.

## What it is

- **A real S3 API.** SigV4, streaming uploads and downloads, multipart, ranges,
  presigned URLs, CORS. Verified against the actual AWS CLI, boto3 and curl —
  not against a mock.
- **A dashboard.** Buckets, an object browser, access keys, activity log, usage,
  teams, two-factor authentication and passkeys.
- **Multi-tenant.** Every bucket, key and object belongs to a team, and isolation
  is enforced server-side on every request.
- **Flat memory.** Nothing buffers a body. A 1 GB upload costs the same memory as
  a 1 KB one, and there is a test that fails if that stops being true.

It is **not** a complete S3 implementation and does not claim to be.
[`docs/s3-compatibility.md`](docs/s3-compatibility.md) records exactly what was
verified, how, and where behaviour deviates — including a list of what returns
`NotImplemented`.

## Quick start

```bash
git clone <your-fork> dero && cd dero
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate

# The S3 API is off until it has a hostname of its own.
echo "S3_ENDPOINT_HOST=127.0.0.1" >> .env
php artisan config:clear

composer run dev
```

| | |
|---|---|
| Dashboard | <http://localhost:8000> |
| S3 endpoint | `http://127.0.0.1:8000` |

Two hostnames for one server. They cannot be the same one: the dashboard mounts
team pages at the application root and the S3 API needs `/{bucket}/{key}`, a
wildcard that would swallow every dashboard URL.

Register, create a bucket, create an access key, then:

```bash
export AWS_ACCESS_KEY_ID=DERO...
export AWS_SECRET_ACCESS_KEY=...
export AWS_DEFAULT_REGION=us-east-1
aws configure set default.s3.addressing_style path

aws s3 cp ./cat.jpg s3://photos/cat.jpg --endpoint-url http://127.0.0.1:8000
aws s3 ls s3://photos/ --endpoint-url http://127.0.0.1:8000
```

The dashboard's **Connect** page has the same snippets for boto3, rclone and
Laravel's `s3` disk, pre-filled with your endpoint.

## Connecting a client

Two settings are required against any non-AWS endpoint, and neither fails in an
obvious way:

- **Path-style addressing**, unless you have wildcard DNS for `*.your-endpoint`.
- **SigV4.** SDKs still fall back to the deprecated SigV2 against custom
  endpoints; Dero implements only SigV4. The symptom is presigned URLs returning
  `AccessDenied` while everything else works.

```python
boto3.client("s3", endpoint_url="https://s3.example.com", ...,
             config=Config(s3={"addressing_style": "path"},
                           signature_version="s3v4"))
```

[`docs/testing.md`](docs/testing.md) has working configuration for the AWS CLI,
boto3, Laravel, rclone, s3cmd, Cyberduck and `mc`.

## Documentation

| | |
|---|---|
| [S3 compatibility](docs/s3-compatibility.md) | What is verified, what is not, and every deliberate deviation |
| [Architecture](docs/architecture.md) | How it is put together and why |
| [Deployment](docs/deployment.md) | Production on a single Ubuntu VPS, with configs in [`deploy/`](deploy/) |
| [Testing](docs/testing.md) | The suites, the dashboard, and connecting real applications |
| [Interop suite](tests/Interop/README.md) | Driving Dero with real S3 clients |

## Tests

```bash
php artisan test --compact          # 575 tests
vendor/bin/pint --test --format agent
vendor/bin/phpstan analyse

tests/Interop/serve.sh &            # then, against a real server:
tests/Interop/aws-cli.sh
.venv/bin/python -m pytest tests/Interop/test_boto3.py -v
```

The Pest suite proves Dero is self-consistent. Only the interop suite proves it
is S3-compatible, which is why the compatibility matrix cites it and not the
unit tests.

## Stack

PHP 8.4 · Laravel 13 · Inertia + React 19 · Tailwind 4 · Pest 5 · SQLite in
development, MySQL in production.
