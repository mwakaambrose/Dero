# Dero — Sub-project A: S3 Data Plane

**Date:** 2026-08-04
**Status:** Approved for planning
**Depends on:** nothing (first sub-project)
**Blocks:** Sub-project B (control plane), Sub-project C (production)

---

## 1. Purpose

Build a genuinely interoperable S3 API on top of Laravel 13, backed by a swappable
storage engine writing to the local filesystem. "Genuinely interoperable" means the
unmodified AWS CLI, boto3, Laravel's Flysystem S3 driver, and rclone can talk to it
without special-casing. Nothing is claimed compatible until an interop test proves it.

This sub-project delivers no user interface. Its acceptance criterion is a passing
interop suite driven by real client software.

## 2. Decisions taken before design

| Decision | Choice | Rationale |
|---|---|---|
| Dev database | SQLite | Already configured; no local PostgreSQL. |
| Prod database | MySQL | User's target. Constrains schema (see §5.2). |
| Tenancy | Existing `Team` model | Working slug-scoped routing, roles, invitations, policies. Zero rewrite. |
| Versioning | **Out of v1.** Column reserved. | Changes the primary key of the object model; defer until the core is proven. |
| Object lock / retention | **Out of v1.** Column reserved. | Meaningless without versioning. |
| Lifecycle rules / replication | **Out of v1.** | Needs its own rules engine and job pipeline. |
| Server-side encryption | **Out of v1.** | Disk-level encryption covers the self-host threat model. |
| Addressing style | Both path and virtual-host | Clients disagree; supporting one guarantees a broken client. |

## 3. Layer separation

The single most important structural property of this project: the S3 data plane and
the dashboard share a database and nothing else.

```
Nginx
 ├── dashboard host      → routes/web.php  → group: web  (session, CSRF, Inertia, Fortify)
 └── S3_ENDPOINT_HOST    → routes/s3.php   → group: s3   (SigV4 only)
```

`routes/s3.php` is registered in `bootstrap/app.php` under its own middleware group that
inherits **nothing** from `web`. Specifically absent: `StartSession`,
`VerifyCsrfToken`, `ShareErrorsFromSession`, `HandleInertiaRequests`,
`AddQueuedCookiesToResponse`, `EncryptCookies`. A session cookie on an S3 request is
ignored; a missing CSRF token is not an error.

Binding the S3 API to its own host from `S3_ENDPOINT_HOST` means the catch-all
`/{bucket}/{key}` route cannot shadow dashboard routes. If `S3_ENDPOINT_HOST` is unset,
S3 routes are not registered at all — failing closed rather than mounting a wildcard at
the application root.

### 3.1 Middleware chain

Applied in order to every S3 route:

1. **`ResolveVirtualHostBucket`** — if `Host` matches `{bucket}.{S3_ENDPOINT_HOST}`,
   rewrite the request into path-style internally. Must run first so that signature
   verification sees the request as the client signed it (the client signed the
   *virtual-host* URI, so the canonical URI is computed before rewriting — see §7.3).
2. **`ResolveAccessKey`** — parse `Authorization` or `X-Amz-Credential`, load the
   `AccessKey`, reject `InvalidAccessKeyId` for unknown/revoked/expired keys.
   Anonymous requests pass through with no key for public-read evaluation.
3. **`VerifyAwsSignature`** — full SigV4 verification (§7). Rejects
   `SignatureDoesNotMatch`, `RequestTimeTooSkewed`, `AccessDenied` on expired presigns.
4. **`ResolveBucket`** — load the bucket, reject `NoSuchBucket`. Binds it to the
   container for controllers.
5. **`AuthorizeS3Operation`** — evaluate access-key scope, bucket policy, and tenant
   isolation. Rejects `AccessDenied`.
6. **`RecordS3Activity`** — terminable middleware writing `audit_logs` and
   `usage_records` deltas **after** the response is sent, so logging never adds latency
   to a download.

Every rejection is thrown as a typed `S3Exception` and rendered as S3 XML (§8).

### 3.2 Rate limiting

Two distinct limiters, because they defend against different things:

- **Per access key** — a generous request-rate ceiling, protecting the host from a runaway
  client loop. Exceeding it returns `SlowDown` (HTTP 503) with `Retry-After`, which is the
  code S3 clients already know to back off on. Returning HTTP 429 instead would cause many
  SDKs to treat it as a non-retryable error.
- **Per source IP on authentication failure** — a much tighter limit on requests that fail
  signature verification, to blunt credential brute-forcing. Tracked in Redis where
  available, falling back to the cache store.

Successful authenticated requests are never limited by the second limiter, so a busy
legitimate client cannot be locked out by an unrelated attacker sharing a NAT.

## 4. Storage engine

```php
interface ObjectStorageBackend
{
    public function put(string $path, mixed $stream, ?int $knownSize = null): StoredObject;
    public function readStream(string $path): mixed;
    public function readRange(string $path, int $offset, int $length): mixed;
    public function delete(string $path): void;
    public function exists(string $path): bool;
    public function size(string $path): int;
    public function copy(string $from, string $to): void;

    public function beginMultipart(string $uploadId): void;
    public function putPart(string $uploadId, int $partNumber, mixed $stream): StoredPart;
    public function completeMultipart(string $uploadId, array $parts, string $target): StoredObject;
    public function abortMultipart(string $uploadId): void;
}
```

Every byte-moving method takes or returns a **stream resource**, never a string. This is
what makes the interface honest about 1 GB objects. `StoredObject` and `StoredPart` are
readonly DTOs carrying size, MD5, and SHA-256.

`LocalFilesystemBackend` is the only v1 implementation. Future `S3Backend`,
`AzureBlobBackend` etc. implement the same contract with no change to controllers.

### 4.1 Physical layout

```
{STORAGE_ROOT}/objects/7a/1f/9c/01JKXQ...    ← 3-level shard from the object's ULID
{STORAGE_ROOT}/uploads/{upload_id}/{part_number}
{STORAGE_ROOT}/tmp/                          ← streamed writes; atomic rename on commit
```

Shard directories derive from the **server-generated ULID**, never from the user's key.

**This is the path-traversal defence, and it is structural rather than filtered.** No
byte of a user-supplied key ever reaches a filesystem call. A key of
`../../../../etc/passwd` is stored verbatim in a database column and maps to a physical
path like `objects/3c/9a/f1/01JKXQ8Z...` — the traversal sequence is data, never a path
component. Input filtering is a second layer, not the primary defence.

Writes go to `tmp/` and are `rename()`d into place only after the full body is received
and its hashes computed. A crashed upload leaves a temp file, never a corrupt object.

## 5. Schema

All tables use ULID string primary keys except pivot/log tables where auto-increment is
adequate. Every tenant-scoped table carries `team_id`.

### 5.1 Tables

**`buckets`** — `id`, `team_id`, `name` (globally unique, S3 naming rules), `region`,
`visibility` (`private`|`public-read`), `versioning_status` *(reserved, default
`Disabled`)*, `object_lock_enabled` *(reserved, default false)*, `quota_bytes` (null =
unlimited), `cors_rules` (json), `objects_count`, `storage_bytes`, timestamps.

`objects_count` and `storage_bytes` are denormalised counters maintained inside the same
transaction as the object write. Listing a bucket must never require `count(*)` over
millions of rows.

**`objects`** — `id` (ulid), `bucket_id`, `team_id`, `key` (text, `utf8mb4_bin`),
`key_hash` (binary 32), `storage_path`, `size_bytes`, `etag`, `checksum_sha256`,
`content_type`, `content_encoding`, `content_disposition`, `cache_control`,
`expires_header`, `metadata` (json), `storage_backend`, `is_multipart`, `part_count`,
`last_modified`, timestamps.

- `unique(bucket_id, key_hash)` — the exact-key lookup and uniqueness constraint.
- `index(bucket_id, key(191))` — prefix scans for `list-objects-v2`.
- `index(bucket_id, content_type)`, `index(team_id, created_at)`.

**`multipart_uploads`** — `id` (the upload ID), `bucket_id`, `team_id`, `key`,
`key_hash`, `content_type`, `metadata` (json), `access_key_id`, `expires_at`,
timestamps.

**`multipart_parts`** — `id`, `multipart_upload_id`, `part_number`, `size_bytes`,
`etag`, `checksum_md5` (binary 16), `storage_path`, timestamps.
`unique(multipart_upload_id, part_number)`.

**`access_keys`** — `id`, `team_id`, `user_id` (creator), `name`, `access_key_id` (20
chars, unique), `secret_key` (encrypted cast), `permissions` (json array of
`read`/`write`/`delete`/`list`/`admin`), `bucket_scope` (json, null = all buckets in
team), `last_used_at`, `expires_at`, `revoked_at`, timestamps.

**`bucket_policies`** — `id`, `bucket_id`, `team_id`, `principal_type`
(`team_member`|`access_key`|`public`), `principal_id`, `permissions` (json), timestamps.

**`audit_logs`** — `id`, `team_id`, `actor_type` (`user`|`access_key`|`anonymous`),
`actor_id`, `action`, `bucket_id`, `object_key`, `ip`, `user_agent`, `result`
(`success`|`denied`|`error`), `error_code`, `meta` (json), `created_at`.
`index(team_id, created_at)`, `index(bucket_id, created_at)`.

**`usage_records`** — `id`, `team_id`, `bucket_id`, `period_start` (hour granularity),
`requests`, `bytes_uploaded`, `bytes_downloaded`, `storage_bytes`, `object_count`.
`unique(team_id, bucket_id, period_start)`.

**`system_settings`** — `key`, `value` (json). Backs first-run wizard state.

### 5.2 Two constraints MySQL forces

**Key ordering.** `ListObjectsV2` must return keys in bytewise lexicographic order, and
`start-after` / `continuation-token` pagination depends on that ordering being stable and
bytewise. SQLite orders `TEXT` with `BINARY` collation natively. MySQL's default
`utf8mb4_unicode_ci` is case-insensitive and locale-aware, which produces a **different
order**. The `key` column is therefore declared `utf8mb4_bin` on MySQL. Getting this
wrong yields pagination that silently skips or repeats keys.

**Key indexing.** S3 keys are up to 1024 characters. `unique(bucket_id, key)` on a
1024-char `utf8mb4` column requires 4096 bytes and exceeds InnoDB's 3072-byte index
limit — the migration simply fails. Hence the split: full key in `text` for fidelity,
`key_hash binary(32)` (SHA-256 of the raw key bytes) for the unique constraint and point
lookups, and a 191-char prefix index for listing scans.

### 5.3 Deviation from the original brief

The brief lists an `object_metadata` table. **Not implemented as a table.** `x-amz-meta-*`
headers live in an `objects.metadata` JSON column. Metadata is always read with its
object and is never queried independently, so a separate table buys only a join on the
hottest read path. Recorded in `docs/architecture.md`.

## 6. Tenant isolation

Buckets are globally unique by name (an S3 requirement) but owned by exactly one team.
Every query that reaches an object filters on `bucket_id`, and every bucket resolution
checks `team_id` against the authenticated principal's team. Isolation is enforced in
`AuthorizeS3Operation` and in Eloquent scopes — **never** by frontend filtering.

An access key belonging to team A presenting a valid signature for a bucket owned by
team B receives `AccessDenied`, not `NoSuchBucket`. (AWS returns `NoSuchBucket` to avoid
leaking existence; we return `AccessDenied` because bucket names are globally unique and
therefore already enumerable. Documented in `docs/security.md`.)

## 7. AWS Signature V4

`App\S3\Auth\SignatureV4Verifier` — a standalone service with no framework dependencies
beyond an interface for reading request parts. Unit-testable against the AWS published
test-suite vectors.

### 7.1 Responsibilities

Canonical URI, canonical query string, canonical headers, signed headers, payload hash,
credential scope, signing-key derivation (`AWS4` → date → region → service →
`aws4_request`), HMAC-SHA256, and comparison via `hash_equals` (constant-time).

Clock skew tolerance ±15 minutes. Presigned URLs additionally validate `X-Amz-Expires`
against `X-Amz-Date`, capped by `S3_PRESIGNED_MAX_EXPIRY`.

### 7.2 Payload hash modes — all three are required

- `UNSIGNED-PAYLOAD` — used by presigned PUTs and some clients over HTTPS.
- A hex SHA-256 of the body — used for small requests and all boto3 defaults.
- **`STREAMING-AWS4-HMAC-SHA256-PAYLOAD`** — the aws-chunked framing the AWS CLI uses for
  ordinary uploads. The body arrives as length-prefixed chunks, each with its own
  chained signature, and the declared `Content-Length` covers the *framing*, not the
  object. This must be decoded on the fly while verifying each chunk signature.

  **If this is not implemented, `aws s3 cp` fails on day one.** It is the single most
  commonly skipped piece of SigV4 and the reason many "S3-compatible" servers are not.

### 7.3 Virtual-host interaction

Clients sign the URI they requested. For `bucket.s3.example.com/key`, the canonical URI
is `/key` and the `Host` header is `bucket.s3.example.com`. The canonical request must
therefore be computed from the **original** request, before `ResolveVirtualHostBucket`
rewrites it to `/bucket/key`. The verifier receives an immutable snapshot of the
inbound request taken at the very start of the chain.

### 7.4 Secret storage — an honest limitation

SigV4 verification requires re-deriving the signing key from the plaintext secret. It is
mathematically impossible to verify a SigV4 signature against a one-way hash of the
secret.

Secrets are therefore stored **encrypted at rest** (AES-256-GCM via Laravel's `encrypted`
cast, keyed by `APP_KEY`), not hashed. The consequence, stated plainly:
**compromise of `APP_KEY` plus database access is compromise of every access key.**

This is inherent to SigV4 and is the same trade every S3 implementation makes. It will
be documented in `docs/security.md` as a limitation, not glossed over. Mitigations:
`APP_KEY` lives outside the database and outside version control; secrets are displayed
once at creation and never re-displayed; keys are individually revocable; all key use is
audited.

## 8. Errors

`App\S3\Exceptions\S3Exception` base class, one subclass per S3 error code, each carrying
an HTTP status and a code string. Rendered by a dedicated exception handler registered
**only** for the `s3` middleware group, so Laravel's JSON/HTML negotiation never applies.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Error>
  <Code>NoSuchBucket</Code>
  <Message>The specified bucket does not exist.</Message>
  <BucketName>example</BucketName>
  <RequestId>01JKXQ...</RequestId>
</Error>
```

Covered: `NoSuchBucket`, `NoSuchKey`, `AccessDenied`, `InvalidAccessKeyId`,
`SignatureDoesNotMatch`, `RequestTimeTooSkewed`, `InvalidRequest`, `InvalidArgument`,
`InvalidBucketName`, `BucketAlreadyExists`, `BucketAlreadyOwnedByYou`, `BucketNotEmpty`,
`EntityTooLarge`, `EntityTooSmall`, `InvalidPart`, `InvalidPartOrder`, `NoSuchUpload`,
`MalformedXML`, `PreconditionFailed`, `InvalidRange`, `MethodNotAllowed`,
`NotImplemented`, `InternalError`.

Unimplemented operations return `NotImplemented` with a correct XML body — never a
Laravel 404 HTML page.

## 9. Operations in scope for v1

**Service:** `ListBuckets` (`GET /`).

**Bucket:** `CreateBucket`, `DeleteBucket`, `HeadBucket`, `ListObjectsV2`
(`prefix`, `delimiter`, `max-keys`, `continuation-token`, `start-after`,
`encoding-type`), `ListObjects` (v1, for older clients), `GetBucketLocation`,
`GetBucketCors`, `PutBucketCors`, `DeleteBucketCors`, `ListMultipartUploads`.

**Object:** `PutObject`, `GetObject` (incl. `Range`), `HeadObject`, `DeleteObject`,
`DeleteObjects` (bulk POST `?delete`), `CopyObject` (`x-amz-copy-source`).

**Multipart:** `CreateMultipartUpload`, `UploadPart`, `UploadPartCopy`,
`CompleteMultipartUpload`, `AbortMultipartUpload`, `ListParts`.

**Explicitly not in v1** (return `NotImplemented`, listed in the compatibility matrix):
versioning, object lock, lifecycle, replication, SSE, tagging, ACLs (bucket policy is
used instead), website hosting, inventory, analytics, notifications, `SelectObjectContent`.

## 10. Streaming and range requests

**Uploads.** The body is read from `php://input` in fixed-size chunks into a temp file,
with MD5 and SHA-256 contexts updated incrementally. `file_get_contents` is never used on
a request body. Peak memory is the chunk size, independent of object size.

**Downloads.** `StreamedResponse` reading the backing file in chunks. `Range:
bytes=0-1048575` returns `206 Partial Content` with correct `Content-Range`,
`Content-Length`, and `Accept-Ranges: bytes`. Unsatisfiable ranges return `416` with
`InvalidRange`. Multi-range requests return the full object (`200`) — permitted by
RFC 7233, documented in the compatibility matrix.

Range support is not optional polish: video playback and resumable downloads depend on it.

**Verification.** The performance suite asserts that peak PHP memory stays flat while
object size grows from 1 KB to 1 GB. A linear relationship is a failed test, not a
tuning note.

## 11. ETags

Because writes stream through a hashing context, MD5 is computed for free during upload.
So Dero can be fully honest here rather than fabricating hashes:

- **Single-part:** genuine MD5 hex of the object bytes. Matches AWS exactly.
- **Multipart:** `md5(concat(binary_md5_of_each_part))-{partCount}`. Matches AWS exactly.
- **CopyObject:** the source ETag is carried over for single-part sources.

No deviation to document. Where a future backend cannot guarantee MD5 (e.g. a remote
S3 backend re-uploading), that backend documents its own ETag behaviour.

## 12. Configuration

New `config/dero.php` reading from env, all documented in `.env.example`:

`S3_ENDPOINT_HOST`, `S3_REGION`, `STORAGE_ROOT`, `S3_MAX_UPLOAD_SIZE`,
`S3_MULTIPART_THRESHOLD`, `S3_MULTIPART_PART_SIZE`, `S3_MULTIPART_MIN_PART_SIZE`,
`S3_PRESIGNED_MAX_EXPIRY`, `S3_CLOCK_SKEW_SECONDS`, `S3_STREAM_CHUNK_SIZE`,
`S3_MULTIPART_ABANDON_AFTER`.

No hardcoded paths, sizes, or limits anywhere in application code.

## 13. Background work

**Queued:** usage aggregation, audit log post-processing. Never object writes — an
asynchronous PUT would break S3's read-after-write consistency guarantee.

**Scheduled:** abort multipart uploads older than `S3_MULTIPART_ABANDON_AFTER` and
reclaim their parts; sweep orphaned temp files; roll up `usage_records` hourly.

## 14. Testing

**Unit** — SigV4 against AWS published vectors; canonical request construction;
aws-chunked decoding; ETag computation (single and multipart); key normalisation and
hashing; bucket-name validation; policy evaluation; ULID shard-path generation.

**Feature (Pest, real HTTP through the s3 route group)** — every operation in §9, plus
prefix/delimiter/pagination semantics, range requests, metadata round-tripping, CORS
preflight, and copy.

**Security** — invalid signature, expired signature, tampered signature, tampered query
parameter, revoked key, expired key, wrong secret, clock skew beyond tolerance, path
traversal in keys, cross-tenant bucket access, unauthorised operation for key scope,
malformed XML (with external-entity expansion disabled), oversized request, rate limit.

**Interop (`tests/Interop/`)** — the acceptance gate. Real `aws-cli/2.36.15` and
`boto3 1.43.63` (both installed) driven against `php artisan serve`:

```
aws s3 mb / ls / cp / rm / sync
aws s3api create-multipart-upload / upload-part / complete-multipart-upload
aws s3 presign  → fetched with plain curl, no credentials
curl -H 'Range: bytes=0-1023'
boto3: put_object, get_object, head_object, list_objects_v2 paginator,
       upload_file (auto-multipart), generate_presigned_url
Laravel Flysystem S3 disk pointed at the local endpoint
```

**Performance** — 1 KB / 1 MB / 100 MB / 1 GB upload and download, measuring latency,
throughput, and **peak PHP memory** (§10).

No operation is marked "Supported" in `docs/s3-compatibility.md` until its interop test
passes and the output has been shown to the user.

## 15. Definition of done

1. `php artisan test` green.
2. `vendor/bin/pint` clean, `phpstan analyse` clean.
3. The full interop script runs end to end against a locally served instance, with output
   captured.
4. `docs/s3-compatibility.md` exists with a matrix whose "Supported" column reflects only
   what §14 actually proved, and which lists unsupported operations explicitly.
5. Peak memory is flat across the 1 KB → 1 GB range.
