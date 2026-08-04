# Dero — Sub-project C: Production Deployment & Operations

**Date:** 2026-08-04
**Status:** Approved for planning
**Depends on:** Sub-projects A and B
**Blocks:** nothing

---

## 1. Purpose

Make Dero deployable and operable on a single Ubuntu 24.04 VPS by someone who is not the
author, and make its failure modes recoverable.

Target host: 4 vCPU, 8 GB RAM, 100 GB+ storage. It must still run on smaller boxes; the
configuration is parameterised rather than sized for one machine.

Docker is **not** a dependency. It may be added later.

## 2. Stack

Ubuntu 24.04 LTS · Nginx · PHP 8.4-FPM · MySQL · Redis · systemd · certbot.

MySQL rather than PostgreSQL, per the user's decision on 2026-08-04. The schema
constraints this imposes are handled in spec A §5.2 — `utf8mb4_bin` collation on object
keys for correct S3 ordering, and hash-based unique indexes to stay inside InnoDB's
3072-byte index limit.

## 3. Request path

```
Internet
  │
  ├── https://dero.example.com     → dashboard  → PHP-FPM (web pool)
  └── https://s3.dero.example.com  → S3 API     → PHP-FPM (s3 pool)
```

Two server blocks, two FPM pools. The separation is operational, not cosmetic: a burst of
1 GB uploads must not exhaust the workers serving the dashboard, and the two have
genuinely different timeout and body-size requirements. Sharing one pool means a slow
upload can make the dashboard unreachable.

## 4. Nginx

Two server blocks plus a shared TLS and security-header snippet.

**Both:** HTTP→HTTPS redirect, TLS 1.2/1.3 with a modern cipher suite, HSTS,
`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and a Content-Security-
Policy. Access and error logs separated per host.

**Dashboard block:** conventional Laravel configuration, gzip on text assets, long-lived
cache headers on Vite's fingerprinted build output.

**S3 block, which is where the non-obvious settings live:**

- `client_max_body_size` from `S3_MAX_UPLOAD_SIZE`. Sized deliberately — not "0" to
  disable the limit, which converts a request-size bug into a disk-exhaustion incident.
- `proxy_request_buffering off` / `fastcgi_request_buffering off` — **required**. With
  buffering on, Nginx spools the entire upload to disk before PHP sees a byte, which
  defeats the streaming design in spec A §10 and doubles the write I/O for every upload.
- Extended `fastcgi_read_timeout` and `send_timeout` for large transfers.
- `gzip off` for object responses — objects are opaque bytes and are frequently already
  compressed. Re-compressing them wastes CPU and breaks `Content-Length`.
- Optional `X-Accel-Redirect` internal location so Nginx can serve object bytes directly
  and release the PHP worker early. **Documented and provided, but off by default**,
  because it bypasses PHP after the authorisation decision and needs to be understood
  before it is enabled.

All tunable values come from the install script's environment, not hardcoded.

## 5. PHP-FPM

Two pools with different characters:

- **`dero-web`** — `pm = dynamic`, ordinary worker count, `memory_limit = 256M`,
  `max_execution_time = 60`.
- **`dero-s3`** — `pm = static` with a worker count sized to CPU rather than RAM, since
  workers are I/O-blocked on streaming rather than memory-hungry. `memory_limit = 256M`,
  `max_execution_time` raised for long transfers.

`memory_limit` stays modest **on purpose**. Spec A's design streams everything, so a
large `memory_limit` would only hide a regression. If a 1 GB upload needs more than
256 MB, that is a bug to fix, not a limit to raise. The performance test in spec A §14
enforces this.

`upload_max_filesize` and `post_max_size` are set consistently with
`client_max_body_size`; a mismatch produces a truncated body with no useful error, which
is a hard failure to diagnose in the field.

## 6. systemd

- **`dero-queue.service`** — `php artisan queue:work` with `--tries`, `--max-time`, and
  `--sleep`. `Restart=always`, `RestartSec=5`, `StartLimitBurst` set so a genuinely broken
  worker stops flapping instead of hammering the box.
- **`dero-scheduler.timer` + `.service`** — `php artisan schedule:run` every minute. A
  systemd timer rather than a crontab entry, so scheduling is visible to the same tooling
  as everything else.
Laravel Horizon is deliberately not used; it would add a dependency and a Redis
requirement for queue management that a plain worker already covers at this scale.

Both units run as the unprivileged `dero` user, with `WorkingDirectory`, an `EnvironmentFile`
pointing at `.env`, and logs to journald. Hardening directives: `NoNewPrivileges`,
`PrivateTmp`, `ProtectSystem=strict` with `ReadWritePaths` limited to `STORAGE_ROOT` and
the Laravel storage directory.

## 7. Install script

`deploy/install.sh` — idempotent, re-runnable, and non-destructive. It must be safe to run
twice, because in practice it will be.

Behaviour: detect and skip already-completed steps; never overwrite an existing `.env`;
never drop a database; back up any config file before replacing it; refuse to run as root
where possible; validate assumptions before mutating anything and fail with a clear
message rather than half-applying.

Steps: verify OS and PHP version, install packages, create the `dero` user and directory
layout, install Composer and npm dependencies, build assets, generate `APP_KEY` if absent,
run migrations, install Nginx and FPM configs, install systemd units, obtain TLS
certificates via certbot, set filesystem ownership and permissions, and start services.

An `--uninstall` path is deliberately **not** provided. Automating the deletion of an
object store is a foot-gun; teardown is documented as a manual procedure instead.

## 8. Configuration

`.env.example` documents every variable with its purpose and default: `APP_*`, `DB_*`,
`REDIS_*`, `QUEUE_*`, `CACHE_*`, `SESSION_*`, `LOG_*`, `MAIL_*`, and all `S3_*` /
`STORAGE_ROOT` settings from spec A §12.

Secrets never enter version control. `.env` is `0600`, owned by `dero`.

## 9. Backups

Documented in `docs/backup.md`, with the central point stated first because it is the one
people get wrong:

> **A database backup without an object-storage backup is worthless, and an object
> backup without the database is equally worthless.** The database holds the mapping from
> S3 keys to physical paths. Restore one without the other and you have either metadata
> pointing at nothing, or an unreadable pile of ULID-named files.

- **Database** — `mysqldump --single-transaction` on a schedule, compressed, encrypted at
  rest, retained off-box.
- **Objects** — `rsync` or `restic` over `STORAGE_ROOT`. Objects are immutable once
  written (no versioning in v1), so incremental sync is cheap and safe.
- **Ordering** — snapshot the database *first*, then the object store. That ordering can
  leave objects on disk with no database row (harmless orphans, reclaimable by the sweeper
  from spec A §13); the reverse ordering leaves database rows pointing at absent files,
  which is data loss.
- **Configuration** — `.env`, Nginx configs, systemd units.

`APP_KEY` is backed up **separately from the database**. It decrypts every access-key
secret (spec A §7.4); storing them together defeats the encryption entirely.

## 10. Disaster recovery

`docs/troubleshooting.md` and a DR section covering: restoring the database, restoring the
object store, rebuilding the application from scratch, rotating `APP_KEY` and re-encrypting
secrets, verifying integrity after restore (a command that walks `objects` and confirms
each `storage_path` exists with the recorded size and checksum), and rebuilding a server
from bare Ubuntu.

**A backup is not verified until a restore has been performed.** The documentation will
say so, and the restore drill will be run at least once against a scratch instance before
any claim that backups work.

## 11. Security hardening

`docs/security.md` checklist: SSH key-only with root login disabled; UFW allowing only 22,
80, 443; **MySQL and Redis bound to localhost and never exposed**; Redis password set;
filesystem permissions with `STORAGE_ROOT` unreadable by the web user beyond what is
needed; `.env` at `0600`; automatic security updates; fail2ban on SSH and on Dero's
authentication-failure log; encrypted off-box backups; and log monitoring for signature
failures and access denials.

Application-side hardening carried from spec A: rate limiting on authentication and on the
S3 API, request size limits, XML parsing with external entity expansion disabled, path
traversal prevented structurally (spec A §4.1), constant-time signature comparison, signed
request expiry, and secret redaction in logs.

The `APP_KEY` limitation from spec A §7.4 is documented here plainly rather than buried:
compromise of `APP_KEY` together with database access compromises every access key.

## 12. Observability

Structured JSON application logs with a request ID correlating dashboard and S3 requests.

Metrics recorded to `usage_records` and exposed on an authenticated internal endpoint in a
shape Prometheus can scrape later: request counts by operation and status, bytes in and
out, object and bucket counts, active multipart uploads, authentication failures, and
latency percentiles. Prometheus itself is not a v1 dependency.

**Health endpoints:**
- `/health/live` — the process is up. No dependency checks, so a database outage does not
  cause the supervisor to kill a healthy process.
- `/health/ready` — database reachable, Redis reachable, `STORAGE_ROOT` writable, queue
  worker heartbeat recent. Returns 503 with a body naming the failed check.

The distinction matters: conflating them turns a recoverable dependency blip into a
restart loop.

## 13. Documentation

`README.md` plus `docs/`: `architecture.md`, `installation.md`, `configuration.md`,
`s3-compatibility.md`, `security.md`, `development.md`, `deployment.md`, `backup.md`,
`troubleshooting.md`, `api.md`, `roadmap.md`.

`s3-compatibility.md` is the document that determines whether developers trust this
project. Rules: a three-column matrix (Feature · Supported · Notes); nothing marked
Supported without a passing interop test behind it; unsupported operations listed
explicitly rather than omitted; deliberate deviations from AWS behaviour documented with
their reasons. **No claim of full S3 compatibility.**

## 14. Final acceptance test

Run against a clean Ubuntu 24.04 host. Every item is pass/fail, and the report records
actual output rather than assertions:

1. Install via `deploy/install.sh`; reach the dashboard over HTTPS.
2. Complete first-run setup; create bucket `production`; create an access key.
3. `aws s3 ls` against the endpoint.
4. Upload a 1 GB file; download it; **verify the checksum matches**; list it; delete it.
5. Upload a large file via multipart; fetch a byte range and verify the bytes.
6. Generate a presigned URL; open it in a browser with no dashboard session; confirm an
   expired presigned URL fails.
7. Confirm a revoked access key fails; unauthorised bucket access fails; path traversal
   fails; cross-tenant access fails.
8. Reboot the host; confirm services and queue workers return automatically.
9. Restore the database and object store from backup into a scratch instance and verify
   integrity.
10. Confirm AWS CLI and boto3 compatibility across the matrix.

Any item that cannot be run in the available environment is reported as **not tested**,
never as passing.

## 15. Definition of done

1. Nginx configs, FPM pool configs, and systemd units present and documented.
2. `install.sh` proven idempotent by running it twice on a clean host.
3. All eleven documentation files written, accurate, and free of aspirational claims.
4. `.env.example` complete.
5. Backup **and restore** both exercised.
6. §14 executed, with results — including anything untested — recorded in the final report.
