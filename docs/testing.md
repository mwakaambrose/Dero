# Testing Dero

Three ways to exercise it: the automated suites, the dashboard by hand, and real
third-party applications pointed at your endpoint. The third is the one that
matters — an S3 server that only its own tests can talk to is not an S3 server.

---

## 1. Automated suites

```bash
php artisan test --compact          # 575 tests
vendor/bin/pint --test --format agent
vendor/bin/phpstan analyse
```

Then the interoperability suite, which drives a running server with real
clients:

```bash
tests/Interop/serve.sh &            # scratch DB + scratch storage on :8787
tests/Interop/aws-cli.sh
.venv/bin/python -m pytest tests/Interop/test_boto3.py -v
```

And the benchmark, which round-trips 1 KB through 1 GB and compares every byte:

```bash
tests/Interop/benchmark.sh
```

See [`tests/Interop/README.md`](../tests/Interop/README.md).

---

## 2. Running the app locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
composer run dev      # serves the app and Vite together
```

### Turning the S3 API on for local use

The S3 API is **off by default**: with no `S3_ENDPOINT_HOST` set, its routes are
not registered at all. That is deliberate — the alternative to "no S3 API" is a
wildcard `/{bucket}/{key}` route at the application root, which would swallow
every dashboard URL.

Locally you need two hostnames pointing at the same server. The simplest pair
needs no `/etc/hosts` entry at all, because `localhost` and `127.0.0.1` are
different *names* for the same address:

```dotenv
# .env
S3_ENDPOINT_HOST=127.0.0.1
```

```bash
php artisan config:clear   # a cached config pins the old value
composer run dev
```

Now:

| | |
|---|---|
| Dashboard | <http://localhost:8000> |
| S3 endpoint | `http://127.0.0.1:8000` |

Visiting `http://127.0.0.1:8000` in a browser will *not* show the dashboard — it
is the S3 host, and it only speaks S3. That is the separation working.

If you would rather use real names, add to `/etc/hosts`:

```
127.0.0.1  dero.test s3.dero.test
```

and set `S3_ENDPOINT_HOST=s3.dero.test`.

---

## 3. Walking the dashboard

With the app running and signed in:

**Register and secure an account** — `/register`. Then **Settings → Security**:
enable two-factor authentication, scan the QR code, confirm a code, and save the
recovery codes. Add a passkey if your browser supports it. Sign out and back in
to confirm the challenge appears.

**Teams** — **Settings → Teams**. Create a second team, invite an address, and
check the invitation appears for the invited user. Every bucket, key and object
belongs to exactly one team; the team switcher in the sidebar changes which one
you are looking at.

**Overview** — `/{team}/overview`. Totals for buckets, objects and bytes stored.
Empty on a fresh install; the numbers below should move as you go.

**Buckets** — create one called `photos`. Bucket names follow AWS rules (3–63
characters, lowercase, no underscores, must not look like an IP address), and
they are globally unique across the installation, so a name another team already
holds is refused.

**Access keys** — **Storage → Access keys → Create key**. Choose permissions
(Read, Write, Delete, List, or Admin) and optionally scope the key to specific
buckets. **The secret is shown exactly once.** Copy it now: it is encrypted at
rest and cannot be recovered, only replaced. Test revocation later — a revoked
key must stop working immediately.

**Connect** — **Storage → Connect**. Ready-made snippets for the AWS CLI, boto3,
rclone and Laravel's `s3` disk, pre-filled with your endpoint and region. This is
the page to send someone who asks "how do I use this?".

**Browser** — **Storage → Buckets → photos**. After you upload something in §4,
this lists it with size, type and last-modified, and folder-style navigation
across `/`-delimited prefixes.

**Activity** — `/{team}/activity`. Every S3 request, with the operation name, the
key that made it, the object, the source IP and the result. Filter by
success / denied / error. This is where a failing client tells you *why*: an
`AccessDenied` row with the right key name means permissions; no row at all means
the request never reached Dero.

> Activity and usage rows are written by terminable middleware, after the
> response is flushed. Under `php artisan serve` they appear immediately; the
> point of the design is that a 1 GB download does not wait for a log write.

---

## 4. Testing with other applications

Everything below assumes:

```bash
export AWS_ACCESS_KEY_ID=DERO...          # from Storage → Access keys
export AWS_SECRET_ACCESS_KEY=...
export AWS_DEFAULT_REGION=us-east-1
export DERO=http://127.0.0.1:8000         # or https://s3.dero.example.com
```

Two client settings are required and neither fails obviously — see
[s3-compatibility.md](s3-compatibility.md#client-configuration):

- **Path-style addressing**, unless you have wildcard DNS for `*.your-endpoint`.
- **SigV4**. SDKs still fall back to the deprecated SigV2 against custom
  endpoints, and Dero implements only SigV4. The symptom is presigned URLs
  returning `AccessDenied` while everything else works.

### AWS CLI

```bash
aws configure set default.s3.addressing_style path

aws s3 mb s3://photos --endpoint-url $DERO
aws s3 cp ./cat.jpg s3://photos/cat.jpg --endpoint-url $DERO
aws s3 ls s3://photos/ --endpoint-url $DERO
aws s3 sync ./album s3://photos/album/ --endpoint-url $DERO

# Round-trip a large file through the automatic multipart path and verify it.
head -c 200M /dev/urandom > /tmp/big.bin
aws s3 cp /tmp/big.bin s3://photos/big.bin --endpoint-url $DERO
aws s3 cp s3://photos/big.bin /tmp/big-out.bin --endpoint-url $DERO
cmp /tmp/big.bin /tmp/big-out.bin && echo "byte-identical"

# A presigned URL anyone can fetch, with no credentials.
aws s3 presign s3://photos/cat.jpg --expires-in 300 --endpoint-url $DERO
```

### boto3 / Python

```python
import boto3
from botocore.config import Config

s3 = boto3.client(
    "s3",
    endpoint_url="http://127.0.0.1:8000",
    aws_access_key_id="DERO...",
    aws_secret_access_key="...",
    region_name="us-east-1",
    config=Config(s3={"addressing_style": "path"}, signature_version="s3v4"),
)

s3.upload_file("cat.jpg", "photos", "cat.jpg")
print(s3.head_object(Bucket="photos", Key="cat.jpg")["ContentLength"])
print(s3.generate_presigned_url("get_object",
      Params={"Bucket": "photos", "Key": "cat.jpg"}, ExpiresIn=300))
```

### Laravel (`s3` filesystem disk)

```dotenv
AWS_ACCESS_KEY_ID=DERO...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=photos
AWS_ENDPOINT=https://s3.dero.example.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

```php
Storage::disk('s3')->put('reports/q1.pdf', $contents);
Storage::disk('s3')->temporaryUrl('reports/q1.pdf', now()->addMinutes(5));
```

`AWS_USE_PATH_STYLE_ENDPOINT=true` is not optional unless you have wildcard DNS.

### rclone

```ini
[dero]
type = s3
provider = Other
access_key_id = DERO...
secret_access_key = ...
endpoint = https://s3.dero.example.com
region = us-east-1
force_path_style = true
```

```bash
rclone ls dero:photos
rclone sync ./album dero:photos/album --progress
rclone mount dero:photos /mnt/photos   # requires FUSE
```

### s3cmd

```ini
# ~/.s3cfg
access_key = DERO...
secret_key = ...
host_base = s3.dero.example.com
host_bucket = s3.dero.example.com/%(bucket)
signature_v2 = False
use_https = True
```

`signature_v2 = False` is required — Dero does not implement SigV2.

### Cyberduck / Mountain Duck

Bookmark type **S3**, server `s3.dero.example.com`, port 443, your access key and
secret. Under *More Options*, tick **Path style requests**. Cyberduck sends
ListObjects v1, which Dero supports precisely so that clients like it work.

### MinIO client (`mc`)

```bash
mc alias set dero https://s3.dero.example.com DERO... ...
mc ls dero/photos
mc cp ./cat.jpg dero/photos/
```

### Browser uploads (CORS)

A browser will not `PUT` to a presigned URL until the bucket allows its origin:

```bash
aws s3api put-bucket-cors --bucket photos --endpoint-url $DERO \
  --cors-configuration '{"CORSRules":[{
    "AllowedOrigins":["https://app.example.com"],
    "AllowedMethods":["GET","PUT"],
    "AllowedHeaders":["*"],
    "ExposeHeaders":["ETag"],
    "MaxAgeSeconds":3000}]}'
```

Confirm the preflight:

```bash
curl -i -X OPTIONS "$DERO/photos/cat.jpg" \
  -H 'Origin: https://app.example.com' \
  -H 'Access-Control-Request-Method: PUT'
```

`Access-Control-Allow-Origin` in the response means a browser will proceed.

---

## 5. Confirming the security properties

Worth running once against your own installation rather than trusting the test
suite:

```bash
# A revoked key stops working immediately.
#   Revoke it in Storage → Access keys, then:
aws s3 ls s3://photos --endpoint-url $DERO           # expect AccessDenied

# An expired presigned URL fails.
URL=$(aws s3 presign s3://photos/cat.jpg --expires-in 5 --endpoint-url $DERO)
sleep 6 && curl -s "$URL" | grep Code                # expect AccessDenied

# A tampered presigned URL fails.
curl -s "$URL&extra=1" | grep Code                   # expect AccessDenied

# Another team's bucket is invisible, whether or not the key exists.
aws s3 ls s3://someone-elses-bucket --endpoint-url $DERO   # expect AccessDenied

# A traversal key is data, not a path — it round-trips and escapes nothing.
aws s3 cp ./cat.jpg 's3://photos/../../etc/passwd' --endpoint-url $DERO
aws s3 cp 's3://photos/../../etc/passwd' - --endpoint-url $DERO | cmp - ./cat.jpg
ls /etc/passwd                                       # untouched

# Errors are always S3 XML, never an HTML page.
curl -s "$DERO/photos/nope.txt" | head -2
```

Every one of these also has a test in
[`tests/Feature/S3/Security/AttackSurfaceTest.php`](../tests/Feature/S3/Security/AttackSurfaceTest.php).

---

## 6. When a client will not connect

Work down this list; it is ordered by how often each one is the answer.

| Symptom | Look at |
|---|---|
| Everything 404s | `S3_ENDPOINT_HOST` unset or wrong. Run `php artisan config:clear` — a cached config pins the old value |
| The endpoint serves the dashboard | You are pointing at the dashboard hostname. They must differ |
| `SignatureDoesNotMatch` on every request | Clock drift between client and server, or a proxy rewriting headers |
| `AccessDenied` from presigned URLs only | The client presigned with SigV2. Force `s3v4` |
| `NoSuchBucket` for a bucket you can see in the dashboard | The key belongs to a different team, or is scoped to other buckets |
| Connection works, no Activity rows | The request never reached Dero. Check DNS and the endpoint URL |
| `503 SlowDown` | Rate limit. Raise `S3_RATE_LIMIT_RPM` |
| Uploads truncate | `client_max_body_size`, `post_max_size` and `S3_MAX_UPLOAD_SIZE` disagree |

The **Activity** page is the fastest diagnostic: it distinguishes "rejected" from
"never arrived", which is most of the problem.
