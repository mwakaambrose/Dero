#!/usr/bin/env bash
#
# Drive Dero with the real AWS CLI. Everything Pest proves is self-consistency;
# this is the only thing that proves S3 compatibility.
#
# Start the server first:  tests/Interop/serve.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/tests/Interop/.env.interop"

ENDPOINT="${DERO_ENDPOINT:?run tests/Interop/serve.sh first}"
BUCKET="interop"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Path-style addressing: virtual-host style would need bucket.127.0.0.1 to
# resolve, which it does not.
export AWS_EC2_METADATA_DISABLED=true
aws configure set default.s3.addressing_style path

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
s3() { aws s3 "$@" --endpoint-url "$ENDPOINT"; }
s3api() { aws s3api "$@" --endpoint-url "$ENDPOINT"; }

# Output is captured into a variable before being matched, never piped into
# grep. `grep -q` exits at the first match and closes the pipe, and the AWS CLI
# turns the resulting SIGPIPE into a BrokenPipeError and a non-zero exit — which
# under `set -e` fails the suite on an assertion that actually passed.
contains() {
  local haystack="$1" needle="$2"
  if [[ "$haystack" != *"$needle"* ]]; then
    printf '\033[1;31mexpected output to contain: %s\033[0m\n%s\n' "$needle" "$haystack" >&2
    return 1
  fi
}

lacks() {
  local haystack="$1" needle="$2"
  if [[ "$haystack" == *"$needle"* ]]; then
    printf '\033[1;31mexpected output NOT to contain: %s\033[0m\n%s\n' "$needle" "$haystack" >&2
    return 1
  fi
}

# curl's own SigV4 signer. A third independent client, and the only one here
# that shows the wire format rather than botocore's normalised view of it.
sigv4() {
  curl -sf --aws-sigv4 "aws:amz:$AWS_DEFAULT_REGION:s3" \
    --user "$AWS_ACCESS_KEY_ID:$AWS_SECRET_ACCESS_KEY" \
    -H "x-amz-content-sha256: $(printf '' | shasum -a 256 | cut -d' ' -f1)" "$@"
}

# Best-effort teardown of a previous run, so the suite is re-runnable against a
# server that is already up.
s3 rb "s3://$BUCKET" --force >/dev/null 2>&1 || true

step "CreateBucket / ListBuckets"
s3 mb "s3://$BUCKET"
contains "$(s3 ls)" "$BUCKET"

step "PutObject / ListObjects / GetObject round-trip"
echo "hello dero" > "$WORK/test.txt"
s3 cp "$WORK/test.txt" "s3://$BUCKET/test.txt"
contains "$(s3 ls "s3://$BUCKET/")" "test.txt"
s3 cp "s3://$BUCKET/test.txt" "$WORK/test-out.txt"
diff "$WORK/test-out.txt" "$WORK/test.txt"

step "HeadObject"
contains "$(s3api head-object --bucket "$BUCKET" --key test.txt)" '"ContentLength": 11'

step "ListObjectsV2 and the v1 listing"
contains "$(s3api list-objects-v2 --bucket "$BUCKET")" '"Key": "test.txt"'
contains "$(s3api list-objects --bucket "$BUCKET")" '"Key": "test.txt"'

# botocore maps both listings onto the same response shape, so the CLI alone
# cannot tell them apart. Check the XML directly.
contains "$(sigv4 "$ENDPOINT/$BUCKET?list-type=2")" '<KeyCount>'
V1_BODY="$(sigv4 "$ENDPOINT/$BUCKET")"
contains "$V1_BODY" '<Marker>'
lacks "$V1_BODY" '<KeyCount>'

step "Multipart upload of 100 MB (automatic, via aws-chunked framing)"
dd if=/dev/urandom of="$WORK/large.bin" bs=1m count=100 2>/dev/null
s3 cp "$WORK/large.bin" "s3://$BUCKET/large.bin" --quiet
s3 cp "s3://$BUCKET/large.bin" "$WORK/large-out.bin" --quiet
[ "$(shasum -a 256 < "$WORK/large.bin")" = "$(shasum -a 256 < "$WORK/large-out.bin")" ]

# A multipart ETag ends in "-<partcount>". A plain MD5 here would mean the
# assembled object was hashed as a whole, which no S3 client expects.
contains "$(s3api head-object --bucket "$BUCKET" --key large.bin)" '-'

step "Range request"
RANGE_URL="$(s3 presign "s3://$BUCKET/large.bin")"
[ "$(curl -sf -H 'Range: bytes=0-9' "$RANGE_URL" | wc -c | tr -d ' ')" = "10" ]

step "Presigned URL fetched with no credentials at all"
env -u AWS_ACCESS_KEY_ID -u AWS_SECRET_ACCESS_KEY \
  curl -sf -o "$WORK/presigned.txt" "$(s3 presign "s3://$BUCKET/test.txt")"
diff "$WORK/presigned.txt" "$WORK/test.txt"

step "CopyObject"
contains "$(s3api copy-object --bucket "$BUCKET" --key copied.txt --copy-source "$BUCKET/test.txt")" 'ETag'
s3 cp "s3://$BUCKET/copied.txt" "$WORK/copied.txt" --quiet
diff "$WORK/copied.txt" "$WORK/test.txt"

step "sync"
mkdir -p "$WORK/sync" && echo one > "$WORK/sync/a.txt" && echo two > "$WORK/sync/b.txt"
s3 sync "$WORK/sync" "s3://$BUCKET/synced/" --quiet
contains "$(s3 ls "s3://$BUCKET/synced/")" 'a.txt'

step "Delimiter listing"
contains "$(s3api list-objects-v2 --bucket "$BUCKET" --delimiter /)" '"Prefix": "synced/"'

step "DeleteObjects (batch)"
contains "$(s3api delete-objects --bucket "$BUCKET" \
  --delete 'Objects=[{Key=synced/a.txt},{Key=synced/b.txt}],Quiet=false')" 'Deleted'

step "CORS configuration"
s3api put-bucket-cors --bucket "$BUCKET" --cors-configuration \
  '{"CORSRules":[{"AllowedOrigins":["https://app.example.com"],"AllowedMethods":["GET","PUT"],"AllowedHeaders":["*"],"ExposeHeaders":["ETag"],"MaxAgeSeconds":3000}]}'
contains "$(s3api get-bucket-cors --bucket "$BUCKET")" 'app.example.com'
contains "$(curl -sf -o /dev/null -D - -X OPTIONS \
  -H 'Origin: https://app.example.com' -H 'Access-Control-Request-Method: GET' \
  "$ENDPOINT/$BUCKET/test.txt")" 'Access-Control-Allow-Origin: https://app.example.com'
s3api delete-bucket-cors --bucket "$BUCKET"

step "Explicit multipart lifecycle and ListMultipartUploads"
UPLOAD_ID="$(s3api create-multipart-upload --bucket "$BUCKET" --key manual.bin \
  --query UploadId --output text)"
contains "$(s3api list-multipart-uploads --bucket "$BUCKET")" "$UPLOAD_ID"
s3api abort-multipart-upload --bucket "$BUCKET" --key manual.bin --upload-id "$UPLOAD_ID"
lacks "$(s3api list-multipart-uploads --bucket "$BUCKET")" "$UPLOAD_ID"

step "Cross-tenant access is denied"
if sigv4 -o /dev/null "$ENDPOINT/definitely-not-your-bucket" 2>/dev/null; then
  echo "expected a request for another tenant's bucket to fail" >&2
  exit 1
fi

step "DeleteObject / DeleteBucket"
s3 rm "s3://$BUCKET/test.txt"
s3 rb "s3://$BUCKET" --force

printf '\n\033[1;32mAWS CLI interop suite passed.\033[0m\n'
