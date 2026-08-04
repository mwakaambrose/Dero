# Production deployment

Target: a single Ubuntu 24.04 VPS. 4 vCPU / 8 GB RAM / 100 GB+ storage is
comfortable; it runs on less, since everything below is parameterised rather
than sized for one machine.

Stack: Nginx · PHP 8.4-FPM · MySQL 8 · Redis · systemd · certbot. Docker is not
a dependency.

Reference configuration files referred to throughout live in
[`deploy/`](../deploy/).

---

## 1. The one decision to get right first

Dero serves two things, and they **must** be on different hostnames:

```
https://dero.example.com      → dashboard
https://s3.dero.example.com   → S3 API
```

They cannot share a hostname. The dashboard mounts team pages at the application
root (`/{team}/overview`) and the S3 API needs `/{bucket}/{key}` — a wildcard
that would swallow every dashboard URL. Dero enforces the split by registering
the S3 routes only on `S3_ENDPOINT_HOST`.

If you want virtual-host addressing (`bucket.s3.dero.example.com/key`) as well
as path style, add a wildcard record:

```
s3.dero.example.com.     A  203.0.113.10
*.s3.dero.example.com.   A  203.0.113.10
```

and include the wildcard in the certificate. Path style works without it.

> `S3_ENDPOINT_HOST` is a hostname. The port is stripped before it is used for
> route matching, so `s3.dero.example.com` is right and `s3.dero.example.com:443`
> is harmless but pointless.

---

## 2. System preparation

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server certbot python3-certbot-nginx \
  php8.4-fpm php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-bcmath php8.4-gd git unzip

sudo adduser --system --group --home /var/www/dero dero
sudo mkdir -p /srv/dero-storage
sudo chown dero:dero /srv/dero-storage
sudo chmod 750 /srv/dero-storage
```

`/srv/dero-storage` is `STORAGE_ROOT` — where object bytes live. Put it on the
volume with the space, not on the root filesystem. Moving it later means moving
every file *and* it must keep the same relative layout, because `storage_path`
in the database is relative to it.

---

## 3. Application

```bash
sudo -u dero git clone <your-fork> /var/www/dero
cd /var/www/dero
sudo -u dero composer install --no-dev --optimize-autoloader
sudo -u dero npm ci && sudo -u dero npm run build
sudo -u dero cp .env.example .env
sudo -u dero php artisan key:generate
sudo chmod 600 .env
```

### Database

```sql
CREATE DATABASE dero CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dero'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON dero.* TO 'dero'@'localhost';
FLUSH PRIVILEGES;
```

The database-level collation is `utf8mb4_unicode_ci`; the migration overrides it
to `utf8mb4_bin` on the object-key columns specifically. That override is not
optional — S3 listings must be in bytewise order or continuation-token
pagination silently returns the wrong pages. See
[architecture.md](architecture.md#the-two-mysql-constraints).

### `.env`

```dotenv
APP_NAME=Dero
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dero.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=dero
DB_USERNAME=dero
DB_PASSWORD=<strong-password>

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<redis-password>

# --- Dero object storage ---
S3_ENDPOINT_HOST=s3.dero.example.com
S3_REGION=us-east-1
STORAGE_ROOT=/srv/dero-storage

# Must agree with client_max_body_size and post_max_size. See §5.
S3_MAX_UPLOAD_SIZE=5368709120

S3_STREAM_CHUNK_SIZE=8388608
S3_CLOCK_SKEW_SECONDS=900
S3_PRESIGNED_MAX_EXPIRY=604800
S3_MULTIPART_ABANDON_AFTER=604800
S3_RATE_LIMIT_RPM=3000
S3_AUTH_FAILURE_LIMIT=20
```

```bash
sudo -u dero php artisan migrate --force
sudo -u dero php artisan config:cache
sudo -u dero php artisan route:cache
sudo -u dero php artisan view:cache
```

Re-run all three cache commands on every deploy, after `.env` changes. A stale
route cache pins the previous `S3_ENDPOINT_HOST` and every S3 request 404s.

---

## 4. Nginx

Two server blocks, from [`deploy/nginx/`](../deploy/nginx/):

```bash
sudo cp deploy/nginx/dero-dashboard.conf /etc/nginx/sites-available/
sudo cp deploy/nginx/dero-s3.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/dero-dashboard.conf /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/dero-s3.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d dero.example.com -d s3.dero.example.com
```

Four settings in the S3 block are load-bearing, and three of them fail in ways
that are hard to diagnose:

**`fastcgi_request_buffering off;`** — required. With buffering on, Nginx spools
the entire upload to disk before PHP sees a byte. That defeats the streaming
design completely, doubles the write I/O for every upload, and turns a 1 GB
upload into 1 GB of temporary file before any work starts.

**`client_max_body_size`** set deliberately from `S3_MAX_UPLOAD_SIZE`, not `0`.
Disabling the limit converts a request-size bug into a disk-exhaustion incident.

**`gzip off;`** for object responses. Objects are opaque bytes and are often
already compressed; re-compressing wastes CPU and breaks `Content-Length`, which
S3 clients rely on.

**`fastcgi_read_timeout`** raised. A 1 GB upload over a slow link legitimately
exceeds the 60-second default, and the failure looks like a truncated body with
no error.

---

## 5. PHP-FPM

Two pools, from [`deploy/php-fpm/`](../deploy/php-fpm/):

```bash
sudo cp deploy/php-fpm/dero-web.conf deploy/php-fpm/dero-s3.conf /etc/php/8.4/fpm/pool.d/
sudo systemctl restart php8.4-fpm
```

The separation is operational, not cosmetic: a burst of 1 GB uploads must not
exhaust the workers serving the dashboard, and the two have genuinely different
timeout and body-size needs. Sharing one pool means a slow upload can make the
dashboard unreachable.

`memory_limit` stays at `256M` **on purpose**. Everything streams, so a large
limit would only hide a regression. If a 1 GB upload needs more than 256 MB,
that is a bug to fix rather than a limit to raise —
[`MemoryProfileTest`](../tests/Feature/S3/MemoryProfileTest.php) exists to catch
it.

Keep these three in agreement or uploads truncate with no useful error:

| Setting | Where |
|---|---|
| `client_max_body_size` | `deploy/nginx/dero-s3.conf` |
| `post_max_size`, `upload_max_filesize` | `deploy/php-fpm/dero-s3.conf` |
| `S3_MAX_UPLOAD_SIZE` | `.env` |

---

## 6. systemd

```bash
sudo cp deploy/systemd/* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now dero-queue.service dero-scheduler.timer
```

`dero-queue.service` runs the queue worker; `dero-scheduler.timer` runs
`schedule:run` every minute, which is what drives the multipart and temp-file
sweepers. Without the scheduler, abandoned multipart parts accumulate on disk
forever.

Both units run as the unprivileged `dero` user under `ProtectSystem=strict`,
with `ReadWritePaths` limited to `STORAGE_ROOT` and Laravel's `storage/`.

Verify after a reboot — this is the step people skip:

```bash
sudo reboot
# then
systemctl status dero-queue.service dero-scheduler.timer nginx php8.4-fpm mysql redis
```

---

## 7. First run

1. Open `https://dero.example.com` and register. The first account is yours to
   secure; enable two-factor authentication under **Settings → Security**.
2. **Storage → Buckets → New bucket**.
3. **Storage → Access keys → Create key**. The secret is shown exactly once and
   is encrypted at rest — it cannot be recovered, only replaced.
4. **Storage → Connect** shows ready-made snippets for the AWS CLI, boto3, rclone
   and Laravel's `s3` disk, pre-filled with your endpoint.

Then verify from a client, not from the dashboard:

```bash
aws s3 mb s3://production --endpoint-url https://s3.dero.example.com
head -c 100M /dev/urandom > /tmp/probe.bin
aws s3 cp /tmp/probe.bin s3://production/probe.bin --endpoint-url https://s3.dero.example.com
aws s3 cp s3://production/probe.bin /tmp/probe-out.bin --endpoint-url https://s3.dero.example.com
cmp /tmp/probe.bin /tmp/probe-out.bin && echo "round-trip verified"
```

See [testing.md](testing.md) for the full acceptance walk-through.

---

## 8. Security

- SSH key-only, root login disabled.
- UFW allowing only 22, 80, 443.
- **MySQL and Redis bound to `127.0.0.1`**, never exposed. Set a Redis password.
- `.env` at `0600`, owned by `dero`.
- `STORAGE_ROOT` at `750`, owned by `dero`, not readable by anything else.
- Unattended security upgrades enabled.
- fail2ban on SSH.

Dero's own defences — SigV4 with constant-time comparison, payload-digest
verification, per-key and per-IP rate limiting, request size limits, XML parsing
with entity expansion disabled, structural path-traversal prevention, presigned
URL expiry, secret redaction in logs — are covered by
[the attack-surface suite](../tests/Feature/S3/Security/AttackSurfaceTest.php).

**The `APP_KEY` limitation, stated plainly:** access-key secrets are encrypted
rather than hashed, because SigV4 must re-derive the signing key from the
plaintext. `APP_KEY` decrypts all of them. Compromise of `APP_KEY` *together
with* database access compromises every access key in the installation. Rotating
`APP_KEY` without re-encrypting invalidates every access key — which is the
correct emergency response, and means every client needs new credentials.

Watch for authentication failures:

```bash
sudo -u dero php artisan tinker --execute \
  'App\Models\AuditLog::where("result","denied")->where("created_at",">",now()->subHour())->count();'
```

The dashboard's **Activity** page shows the same data, filtered.

---

## 9. Backups

> **A database backup without an object-storage backup is worthless, and an
> object backup without the database is equally worthless.** The database holds
> the mapping from S3 keys to physical ULID-named paths. Restore one without the
> other and you have either metadata pointing at nothing, or an unreadable pile
> of files with meaningless names.

Back up four things:

| What | How |
|---|---|
| Database | `mysqldump --single-transaction dero`, compressed, encrypted, off-box |
| Objects | `restic` or `rsync` over `STORAGE_ROOT`. Objects are immutable once written, so incremental sync is cheap and safe |
| Configuration | `.env`, Nginx configs, systemd units |
| **`APP_KEY`** | **Separately from the database** |

`APP_KEY` stored alongside the database backup defeats the encryption entirely —
whoever has one has both.

**Order matters.** Snapshot the database *first*, then the object store. That
ordering can leave objects on disk with no database row: harmless orphans, which
`SweepOrphanedTempFiles` does not touch but which cost only disk. The reverse
ordering leaves database rows pointing at files that were never captured, which
is data loss.

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

mysqldump --single-transaction dero | gzip > "/backup/db-$STAMP.sql.gz"
restic backup /srv/dero-storage
restic backup /var/www/dero/.env /etc/nginx/sites-available /etc/systemd/system/dero-*
```

**A backup is not verified until a restore has been performed.** Run the drill
against a scratch instance before you rely on it: restore the dump, restore
`STORAGE_ROOT`, restore `APP_KEY`, then confirm every object's bytes are actually
there and unchanged:

```bash
sudo -u dero php artisan tinker --execute '
$root = rtrim(config("dero.storage_root"), "/");
$missing = 0; $wrong = 0;
App\Models\S3Object::query()->chunkById(500, function ($objects) use ($root, &$missing, &$wrong) {
    foreach ($objects as $object) {
        $path = $root."/".$object->storage_path;
        if (! is_file($path)) { $missing++; continue; }
        if (filesize($path) !== $object->size_bytes) { $wrong++; }
    }
});
echo "missing: $missing, wrong size: $wrong\n";'
```

Both counts must be zero.

---

## 10. Upgrading

```bash
cd /var/www/dero
sudo -u dero php artisan down
sudo -u dero git pull
sudo -u dero composer install --no-dev --optimize-autoloader
sudo -u dero npm ci && sudo -u dero npm run build
sudo -u dero php artisan migrate --force
sudo -u dero php artisan config:cache && sudo -u dero php artisan route:cache && sudo -u dero php artisan view:cache
sudo systemctl restart dero-queue.service php8.4-fpm
sudo -u dero php artisan up
```

Restarting the queue worker is required: a running worker holds the old code in
memory.

---

## 11. Troubleshooting

| Symptom | Cause |
|---|---|
| Every S3 request returns 404 | `S3_ENDPOINT_HOST` unset, wrong, or a stale `route:cache`. Run `php artisan route:cache` after any change |
| S3 host serves the dashboard | Both hostnames resolve to the same server block. Check `server_name` |
| `SignatureDoesNotMatch` on every request | Server clock drift, or a proxy rewriting headers. Check `timedatectl` first |
| Uploads truncate silently | `client_max_body_size`, `post_max_size` and `S3_MAX_UPLOAD_SIZE` disagree |
| Large uploads are slow and disk I/O doubles | `fastcgi_request_buffering` is on |
| `aws s3 presign` URLs return `AccessDenied` | The client presigned with SigV2. Set `signature_version = s3v4` |
| Disk fills with `uploads/` directories | The scheduler is not running. `systemctl status dero-scheduler.timer` |
| 503 `SlowDown` under normal load | `S3_RATE_LIMIT_RPM` too low for your traffic |

Logs: `journalctl -u dero-queue -f`, `/var/log/nginx/dero-s3.error.log`, and
`storage/logs/laravel.log`.

---

## Not automated

An installer script (`deploy/install.sh`) is specified in
`docs/superpowers/specs/2026-08-04-dero-production-design.md` but is not built
yet — that is its own sub-project. The steps above are the manual equivalent and
are what the installer will encode.

There is deliberately no uninstall path. Automating the deletion of an object
store is a foot-gun; teardown stays manual.
