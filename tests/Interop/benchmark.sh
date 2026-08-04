#!/usr/bin/env bash
#
# Throughput and concurrency measurement against a running Dero instance.
#
#   tests/Interop/serve.sh &
#   tests/Interop/benchmark.sh | tee tests/Interop/benchmark-results.txt
#
# These are measurements of a specific machine and server, not a claim about
# Dero in general. Record what the header prints alongside the numbers.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/tests/Interop/.env.interop"

ENDPOINT="${DERO_ENDPOINT:?run tests/Interop/serve.sh first}"
BUCKET="bench"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export AWS_EC2_METADATA_DISABLED=true
aws configure set default.s3.addressing_style path
s3() { aws s3 "$@" --endpoint-url "$ENDPOINT" --quiet; }

# The built-in PHP server is not a production target. Numbers from it are a
# floor, not a benchmark of a real deployment behind Nginx and PHP-FPM.
echo "Dero benchmark"
echo "  date:     $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "  host:     $(uname -mrs)"
echo "  cpu:      $(sysctl -n machdep.cpu.brand_string 2>/dev/null || uname -p)"
echo "  php:      $(php -r 'echo PHP_VERSION;')"
echo "  server:   php artisan serve (built-in), $ENDPOINT"
echo

aws s3 rb "s3://$BUCKET" --force --endpoint-url "$ENDPOINT" >/dev/null 2>&1 || true
aws s3 mb "s3://$BUCKET" --endpoint-url "$ENDPOINT" >/dev/null

elapsed() {
  local start=$1 end=$2
  # bash has no float arithmetic; awk does.
  awk -v s="$start" -v e="$end" 'BEGIN { printf "%.2f", e - s }'
}

throughput() {
  local bytes=$1 seconds=$2
  awk -v b="$bytes" -v s="$seconds" 'BEGIN {
    if (s <= 0) { print "n/a"; exit }
    printf "%.1f MB/s", (b / 1048576) / s
  }'
}

printf '%-10s %12s %12s %14s %14s\n' SIZE UPLOAD DOWNLOAD "UP RATE" "DOWN RATE"

for spec in "1KB:1024" "1MB:1048576" "100MB:104857600" "1GB:1073741824"; do
  label="${spec%%:*}"
  bytes="${spec##*:}"
  file="$WORK/$label.bin"

  # /dev/urandom rather than /dev/zero: a compressible body would measure the
  # transport's compression rather than Dero's throughput.
  dd if=/dev/urandom of="$file" bs=1m count=$((bytes / 1048576)) 2>/dev/null \
    || head -c "$bytes" /dev/urandom > "$file"

  start=$(python3 -c 'import time; print(time.time())')
  s3 cp "$file" "s3://$BUCKET/$label.bin"
  mid=$(python3 -c 'import time; print(time.time())')
  s3 cp "s3://$BUCKET/$label.bin" "$WORK/$label-out.bin"
  end=$(python3 -c 'import time; print(time.time())')

  # Correctness is part of the measurement: a fast wrong answer is not a result.
  cmp -s "$file" "$WORK/$label-out.bin" || { echo "MISMATCH at $label" >&2; exit 1; }

  up=$(elapsed "$start" "$mid")
  down=$(elapsed "$mid" "$end")

  printf '%-10s %11ss %11ss %14s %14s\n' \
    "$label" "$up" "$down" "$(throughput "$bytes" "$up")" "$(throughput "$bytes" "$down")"

  rm -f "$file" "$WORK/$label-out.bin"
done

echo
echo "20 concurrent 1 MB uploads"
head -c 1048576 /dev/urandom > "$WORK/concurrent.bin"

start=$(python3 -c 'import time; print(time.time())')
for i in $(seq 1 20); do
  s3 cp "$WORK/concurrent.bin" "s3://$BUCKET/concurrent-$i.bin" &
done
wait
end=$(python3 -c 'import time; print(time.time())')

echo "  wall clock: $(elapsed "$start" "$end")s for 20 MB across 20 requests"
echo "  aggregate:  $(throughput $((20 * 1048576)) "$(elapsed "$start" "$end")")"

aws s3 rb "s3://$BUCKET" --force --endpoint-url "$ENDPOINT" >/dev/null
