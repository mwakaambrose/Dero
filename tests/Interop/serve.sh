#!/usr/bin/env bash
#
# Start a Dero instance for the interoperability suite.
#
# Runs against a scratch SQLite database and a scratch storage root, so it never
# touches your development data. Writes credentials to tests/Interop/.env.interop
# for aws-cli.sh and test_boto3.py to source.
#
# Usage:  tests/Interop/serve.sh          # runs in the foreground; Ctrl-C to stop
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HOST="${DERO_INTEROP_HOST:-127.0.0.1}"
PORT="${DERO_INTEROP_PORT:-8787}"
SCRATCH="${DERO_INTEROP_SCRATCH:-$ROOT/storage/interop}"

DB="$SCRATCH/database.sqlite"
STORAGE="$SCRATCH/objects"
ENV_FILE="$ROOT/tests/Interop/.env.interop"

rm -rf "$SCRATCH"
mkdir -p "$STORAGE"
touch "$DB"

# The S3 API is host-scoped and fails closed when no endpoint host is set, so
# the serve host is declared here. Path-style requests to it then route.
#
export S3_ENDPOINT_HOST="$HOST:$PORT"
export STORAGE_ROOT="$STORAGE"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
export APP_ENV=local
export CACHE_STORE=file
export SESSION_DRIVER=file
export QUEUE_CONNECTION=sync

cd "$ROOT"

# Config caches would pin the previous run's endpoint host.
php artisan config:clear --quiet
php artisan migrate --force --quiet

php artisan dero:seed-interop > "$ENV_FILE"
{
  echo "export DERO_ENDPOINT=http://$HOST:$PORT"
  echo "export AWS_ENDPOINT_URL=http://$HOST:$PORT"
} >> "$ENV_FILE"

echo "Dero interop server on http://$HOST:$PORT"
echo "Credentials written to $ENV_FILE"
echo

# PHP's built-in server is single-threaded per worker; the suite issues
# concurrent multipart uploads, so more than one worker is required or the
# upload deadlocks waiting on itself.
PHP_CLI_SERVER_WORKERS=8 php artisan serve --host="$HOST" --port="$PORT"
