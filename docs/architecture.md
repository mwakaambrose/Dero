# Architecture

Dero is a Laravel application serving two distinct things from one codebase: a
dashboard for humans and an S3 API for machines. They share models and a
database and nothing else — not middleware, not sessions, not error rendering.

## Layers

```
                    ┌─────────────────────────────┐
  browser  ────────▶│  web routes (session, CSRF, │
                    │  Inertia + React dashboard) │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │   Eloquent models, actions   │
                    └──────────────▲──────────────┘
                                   │
                    ┌──────────────┴──────────────┐
  aws-cli  ────────▶│   s3 routes (SigV4 only,    │
  boto3             │   XML in, XML out)          │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │   ObjectStorageBackend       │
                    │   (LocalFilesystemBackend)   │
                    └─────────────────────────────┘
```

The two front doors are separated by **host**, not by path prefix. The dashboard
mounts team pages at the application root (`/{team}/overview`), and the S3 API
needs `/{bucket}/{key}` — a wildcard that would swallow every dashboard URL.
`config('dero.endpoint_host')` decides where the S3 routes live, and when it is
unset they are not registered at all. Failing closed matters here: the
alternative to "no S3 API" is "a wildcard route at the application root".

The S3 group is registered *before* the web group, because `routes/web.php` is
not host-constrained and Laravel matches in registration order. Registered the
other way round, `/` on the S3 host would serve the dashboard welcome page
instead of ListBuckets.

> The port is stripped from `endpoint_host` before it is used as a route domain.
> Route host matching compares against `Request::getHost()`, which never carries
> a port, so `127.0.0.1:8787` would match nothing and every S3 request would 404
> with no explanation.

## The request path

An S3 request passes through this chain, in this order
([`bootstrap/app.php`](../bootstrap/app.php)):

| Middleware | What it does | Why here |
|---|---|---|
| `AssignRequestId` | Stamps `x-amz-request-id`; suppresses PHP's `default_charset` | The attribute it sets is also how the exception handler recognises an S3 request |
| `EnforceRateLimits` | Per-key request rate, per-IP auth-failure rate | Before any database work, so a flood costs a cache read |
| `RecordS3Activity` | Audit log and usage counters, on `terminate()` | After the response is flushed, so logging never adds latency to a 1 GB download |
| `CaptureSignedRequest` | Snapshots the request as the client signed it | Must run before anything can mutate the request |
| `ResolveAccessKey` | Looks up the key id; no credentials is legitimately anonymous | |
| `VerifyAwsSignature` | Re-derives the signing key and compares | `hash_equals`, always |
| `ResolveBucket` | Resolves the bucket name to a model | |
| `HandleBucketCors` | Answers preflights; decorates responses | After the bucket (rules live on it), before authorization (a preflight carries no credentials by design) |
| `AuthorizeS3Operation` | Tenant isolation and permission check | Last, so it can see both key and bucket |

Nothing on this path touches the session, cookies, CSRF or Inertia.

## Errors are always XML

Every failure on an S3 route renders as an S3 error document, including 404 and
500. A Laravel HTML error page here breaks every client, because SDKs parse the
body to get the error code and retry decision.

The exception handler keys off the `s3.request_id` request attribute rather than
the host, because checking the host breaks under testing while the attribute is
exact. Anything that is not an `S3Exception` is reported and rendered as
`InternalError` — a stack trace is never returned.

## Storage

`ObjectStorageBackend` is the seam. Every byte-moving method takes or returns a
stream resource, never a string, which is what keeps the contract honest about
objects larger than memory and what lets an S3, Azure or Backblaze backend drop
in later without touching a controller.

### Why physical paths come from ULIDs

A user-supplied object key never reaches a filesystem call. Paths are derived
from a server-generated ULID:

```
01JKXQ8ZABCDEFGHJKMNPQRSTV  →  objects/ab/cd/ef/01JKXQ8ZABCDEFGHJKMNPQRSTV
```

This makes path traversal *structurally* impossible rather than merely filtered
against. A key of `../../../../tmp/pwned.txt` is stored, listed and returned
verbatim as data — the
[attack-surface suite](../tests/Feature/S3/Security/AttackSurfaceTest.php)
asserts both that it round-trips unchanged and that nothing is written outside
the storage root.

Sharding uses the **last** six characters of the ULID. The first ten encode the
millisecond timestamp, so objects created in the same millisecond share them;
Laravel's ULID factory is also monotonic, so within a millisecond it increments
the low-order random characters rather than regenerating the whole suffix. Only
the trailing characters vary on every increment. Sharding on the leading
characters would pile every concurrent upload into one directory.

### Nothing is buffered

`file_get_contents`, `$request->getContent()` and `stream_get_contents` are
banned on request and response bodies. Uploads stream from the request into a
temporary neighbour file and are renamed into place, so a crashed upload leaves a
`.part` file rather than a truncated object. Downloads stream out in chunks of
`S3_STREAM_CHUNK_SIZE`.

Peak memory is bounded by the chunk size, not by the object size.
[`MemoryProfileTest`](../tests/Feature/S3/MemoryProfileTest.php) asserts this at
1, 16, 64 and 128 MB; the [benchmark](../tests/Interop/benchmark.sh) round-trips
1 GB and compares it byte for byte.

MD5 and SHA-256 are computed by the same single pass that writes the file. That
is also what makes the `x-amz-content-sha256` check affordable: the digest the
client signed is compared against the one the write produced, and a mismatch
discards the file before anything is committed.

## Schema

Nine tables, all portable between SQLite (dev and test) and MySQL (production).
No `jsonb`, no partial indexes, no array types.

`buckets` · `s3_objects` · `multipart_uploads` · `multipart_parts` ·
`access_keys` · `bucket_policies` · `audit_logs` · `usage_records` ·
`system_settings`

Every tenant-owned table carries `team_id`, and every query filters on it.
Tenant isolation is enforced server-side on every request, never by filtering in
a client.

`buckets.objects_count` and `buckets.storage_bytes` are denormalised counters
maintained in the same transaction as the object write. Listing a bucket must
never require `count(*)` over millions of rows.

### The two MySQL constraints

**Object keys are stored twice.** `s3_objects.key` holds the full key for
fidelity; `s3_objects.key_hash` — 32 raw bytes of SHA-256 — carries the unique
index. A 1024-character `utf8mb4` column is 4096 bytes, past InnoDB's 3072-byte
index limit, so the unique index would simply fail to build.

**Keys need a binary collation.** ListObjectsV2 must return keys in bytewise
lexicographic order, and continuation-token pagination depends on that ordering
being stable. SQLite orders `TEXT` with `BINARY` collation natively; MySQL's
default `utf8mb4_unicode_ci` is case-insensitive and locale-aware, which produces
a different order and silently breaks pagination. The migration applies
`utf8mb4_bin` to the key columns on MySQL only.

## SigV4

```
secret ──▶ HMAC(date) ──▶ HMAC(region) ──▶ HMAC("s3") ──▶ HMAC("aws4_request")
                                                              │
canonical request ──▶ SHA-256 ──▶ string to sign ─────────────┴──▶ signature
```

The canonical request is rebuilt from the snapshot `CaptureSignedRequest` took,
and the resulting signature is compared with `hash_equals`. Every rule in
`CanonicalRequest` is load-bearing — S3, unlike most AWS services, does not
double-encode the path, and `~` must not be escaped. A single wrong character
produces `SignatureDoesNotMatch` and no other diagnostic.

Both the `Authorization` header form and the presigned query form are supported.
SigV2 is not, deliberately.

### Why access-key secrets are encrypted, not hashed

SigV4 verification re-derives the signing key from the plaintext secret. A
one-way hash makes that impossible, so `access_keys.secret_key` uses Laravel's
`encrypted` cast.

**This means `APP_KEY` decrypts every access-key secret in the installation.**
Compromise of `APP_KEY` *together with* database access compromises every key.
Back `APP_KEY` up separately from the database — storing them together defeats
the encryption entirely. See [deployment.md](deployment.md#backups).

## Background work

| Job | Schedule | What it reclaims |
|---|---|---|
| `AbandonExpiredMultipartUploads` | Hourly | Parts of uploads no one will ever complete. Clients abandon uploads routinely and the protocol does not oblige them to clean up |
| `SweepOrphanedTempFiles` | Daily | `tmp/` entries and `.part` files older than a day, left by interrupted writes |

Both are idempotent: a missed run costs only the disk held until the next one.
Files are deleted before rows, so a crash between the two leaves rows pointing at
absent files that the next run finishes, rather than files nothing references.

## Where things live

| Path | Contents |
|---|---|
| `app/S3/Auth/` | SigV4: canonical request, signing key derivation, verification |
| `app/S3/Http/` | Chunked decoding, range parsing, XML error rendering, response types |
| `app/S3/Values/` | `BucketName`, `ObjectKey`, `CorsRule`, listing queries — pure, no framework |
| `app/S3/Exceptions/` | One class per S3 error code |
| `app/Http/Middleware/S3/` | The chain above, one file per concern |
| `app/Http/Controllers/S3/` | Thin; logic lives in actions |
| `app/Actions/S3/` | The operations themselves, independently testable |
| `app/Storage/` | The backend contract and its local implementation |
| `app/Http/Controllers/Storage/` | The dashboard |
