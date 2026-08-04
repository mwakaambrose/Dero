# Dero S3 Data Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a genuinely interoperable S3 API on Laravel 13 — real AWS CLI and boto3 clients must work against it unmodified.

**Architecture:** A dedicated `s3` route group sharing nothing with Laravel's `web` middleware (no session, no CSRF, no Inertia), authenticated solely by AWS Signature V4. Object bytes stream through a swappable `ObjectStorageBackend` interface to ULID-sharded paths on the local filesystem; metadata lives in the database. Physical paths derive from server-generated ULIDs, never from user keys, which makes path traversal structurally impossible.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 5, SQLite (dev/test) targeting MySQL (prod), Laravel queues.

**Spec:** `docs/superpowers/specs/2026-08-04-dero-data-plane-design.md`

## Global Constraints

- **PHP 8.4, Laravel 13, Pest 5.** No new Composer dependencies. SigV4 uses `hash_hmac`, which is already the well-maintained primitive.
- **Portable migrations.** SQLite in dev/test, MySQL in production. No PostgreSQL-only features: no `jsonb`, no partial indexes, no array types. Use `$table->json()`, which both support.
- **Never load an object body into memory.** `file_get_contents`, `$request->getContent()`, and `stream_get_contents` are banned on request/response bodies. Chunked stream reads only. Peak memory must stay flat as object size grows.
- **Never let a user-supplied key reach a filesystem call.** Physical paths derive from server-generated ULIDs only.
- **Constant-time comparison** (`hash_equals`) for every signature and secret comparison.
- **S3 XML in, S3 XML out.** No Laravel JSON or HTML error pages on any `s3` route, including 404 and 500.
- **Strict types and explicit return types** on every method. PHPDoc array shapes where arrays are structured.
- **Curly braces on all control structures**, even single-line bodies (project PHP convention).
- **Run `vendor/bin/pint --dirty --format agent`** after modifying PHP, before every commit.
- **Tests must actually run and pass** before a task is committed. A task is not done because the code looks right.

---

## File Structure

**Configuration**
- `config/dero.php` — every tunable. No hardcoded sizes, paths, or limits in application code.

**Value objects** (`app/S3/Values/`) — pure, no framework dependencies, exhaustively unit-tested.
- `BucketName.php` — S3 bucket naming rules.
- `ObjectKey.php` — key validation, SHA-256 hashing, delimiter segmentation.

**Exceptions** (`app/S3/Exceptions/`)
- `S3Exception.php` — abstract base carrying an error code and HTTP status.
- One subclass per S3 error code.

**XML** (`app/S3/Xml/`)
- `XmlWriter.php` — builds S3 response documents.
- `XmlReader.php` — parses request bodies with entity expansion disabled.

**Auth** (`app/S3/Auth/`)
- `SignedRequest.php` — immutable snapshot of the inbound request as the client signed it.
- `Credential.php` — parsed credential scope.
- `ParsedAuthorization.php` — parsed `Authorization` header or presigned query set.
- `AuthorizationParser.php` — extracts the above from a request.
- `CanonicalRequest.php` — canonical URI, query, headers, and request string.
- `SignatureV4Verifier.php` — signing-key derivation and verification.

**HTTP** (`app/S3/Http/`)
- `ChunkedPayloadDecoder.php` — `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` decoding.
- `S3ErrorResponse.php` — renders `S3Exception` as XML.
- `RangeHeader.php` — parses and validates `Range`.

**Storage** (`app/Storage/`)
- `Contracts/ObjectStorageBackend.php` — the seam that lets backends be swapped.
- `Values/StoredObject.php`, `Values/StoredPart.php` — readonly result DTOs.
- `ObjectPathGenerator.php` — ULID → sharded physical path.
- `LocalFilesystemBackend.php` — the v1 implementation.

**Middleware** (`app/Http/Middleware/S3/`) — one file per concern, chained in order.

**Controllers** (`app/Http/Controllers/S3/`) — one per resource group, thin; logic lives in actions.

**Actions** (`app/Actions/S3/`) — the operations themselves, independently testable.

**Models** (`app/Models/`) — `Bucket`, `S3Object`, `MultipartUpload`, `MultipartPart`, `AccessKey`, `BucketPolicy`, `AuditLog`, `UsageRecord`.

> `S3Object` rather than `Object`: `object` is a reserved word in PHP and cannot be a class name.

**Routes**
- `routes/s3.php` — registered as its own group in `bootstrap/app.php`.

**Tests**
- `tests/Unit/S3/` — value objects, SigV4, XML, chunked decoding, path generation.
- `tests/Feature/S3/` — every operation over real HTTP.
- `tests/Feature/S3/Security/` — the attack suite.
- `tests/Interop/` — real AWS CLI and boto3 against a served instance.

---

## Task 0: Test harness prerequisites

Feature tests need a database, and `tests/Pest.php` currently has `RefreshDatabase` commented out. Every later task depends on this, so it lands first.

**Files:**
- Modify: `tests/Pest.php`
- Create: `tests/Helpers/SignsRequests.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a migrated in-memory database for every feature test. The request-signing helper `Tests\Helpers\SignsRequests` is created in Task 15, once there are routes for it to sign against; this task only establishes the database trait it depends on.

- [x] **Step 1: Enable RefreshDatabase for feature tests**

In `tests/Pest.php`, uncomment the trait so feature tests get a migrated in-memory database:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [x] **Step 2: Run the existing suite to confirm nothing broke**

Run: `php artisan test --compact`
Expected: PASS. The starter kit's tests already assume a database; enabling the trait should not break them. If any test fails, it was relying on persisted state across tests — fix that test rather than reverting this change.

- [x] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Pest.php
git commit -m "test: enable RefreshDatabase for feature tests"
```

---

## Task 1: Configuration

Every tunable in one place, so no magic numbers appear in later tasks.

**Files:**
- Create: `config/dero.php`
- Modify: `.env.example`
- Modify: `.gitignore`

**Interfaces:**
- Produces: `config('dero.*')` keys consumed by nearly every later task —
  `endpoint_host`, `region`, `storage_root`, `max_upload_size`,
  `multipart.threshold`, `multipart.part_size`, `multipart.min_part_size`,
  `multipart.abandon_after`, `presigned_max_expiry`, `clock_skew_seconds`,
  `stream_chunk_size`, `rate_limits.requests_per_minute`,
  `rate_limits.auth_failures_per_minute`.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/ConfigTest.php`:

```php
<?php

declare(strict_types=1);

it('exposes every dero configuration key with a sane default', function () {
    expect(config('dero.region'))->toBe('us-east-1')
        ->and(config('dero.storage_root'))->toBeString()
        ->and(config('dero.stream_chunk_size'))->toBe(8 * 1024 * 1024)
        ->and(config('dero.clock_skew_seconds'))->toBe(900)
        ->and(config('dero.multipart.min_part_size'))->toBe(5 * 1024 * 1024)
        ->and(config('dero.presigned_max_expiry'))->toBe(604800);
});

it('does not register s3 routes when no endpoint host is configured', function () {
    expect(config('dero.endpoint_host'))->toBeNull();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ConfigTest`
Expected: FAIL — `config('dero.region')` is null because the file does not exist.

- [x] **Step 3: Create the config file**

Create `config/dero.php`:

```php
<?php

declare(strict_types=1);

return [
    /*
     | The host the S3 API is served from, e.g. "s3.dero.example.com". When null,
     | the S3 routes are not registered at all — failing closed rather than
     | mounting a wildcard /{bucket}/{key} route at the application root.
     */
    'endpoint_host' => env('S3_ENDPOINT_HOST'),

    'region' => env('S3_REGION', 'us-east-1'),

    'storage_root' => env('STORAGE_ROOT', storage_path('dero')),

    'max_upload_size' => (int) env('S3_MAX_UPLOAD_SIZE', 5 * 1024 * 1024 * 1024),

    'multipart' => [
        'threshold' => (int) env('S3_MULTIPART_THRESHOLD', 64 * 1024 * 1024),
        'part_size' => (int) env('S3_MULTIPART_PART_SIZE', 16 * 1024 * 1024),

        /*
         | AWS requires every part except the last to be at least 5 MiB. Clients
         | rely on this being enforced, so it is not configurable downward in a
         | way that would break CompleteMultipartUpload semantics.
         */
        'min_part_size' => 5 * 1024 * 1024,

        'abandon_after' => (int) env('S3_MULTIPART_ABANDON_AFTER', 604800),
    ],

    /*
     | AWS caps presigned URL lifetime at 7 days for SigV4. Requests asking for
     | longer are rejected rather than silently clamped.
     */
    'presigned_max_expiry' => (int) env('S3_PRESIGNED_MAX_EXPIRY', 604800),

    'clock_skew_seconds' => (int) env('S3_CLOCK_SKEW_SECONDS', 900),

    'stream_chunk_size' => (int) env('S3_STREAM_CHUNK_SIZE', 8 * 1024 * 1024),

    'rate_limits' => [
        'requests_per_minute' => (int) env('S3_RATE_LIMIT_RPM', 3000),
        'auth_failures_per_minute' => (int) env('S3_AUTH_FAILURE_LIMIT', 20),
    ],
];
```

- [x] **Step 4: Add the variables to `.env.example`**

Append to `.env.example`:

```dotenv
# --- Dero object storage -------------------------------------------------
# Host the S3 API is served from. Leave blank to disable the S3 API entirely.
S3_ENDPOINT_HOST=
S3_REGION=us-east-1

# Absolute path where object bytes are written. Must be writable by the web user.
STORAGE_ROOT=

# Largest single PutObject body accepted, in bytes. Must agree with Nginx
# client_max_body_size and PHP post_max_size or uploads truncate without a
# useful error.
S3_MAX_UPLOAD_SIZE=5368709120

# Dashboard uploads switch to multipart above this size.
S3_MULTIPART_THRESHOLD=67108864
S3_MULTIPART_PART_SIZE=16777216

# Incomplete multipart uploads older than this (seconds) are swept and their
# parts reclaimed.
S3_MULTIPART_ABANDON_AFTER=604800

# Maximum presigned URL lifetime in seconds. AWS caps SigV4 at 7 days.
S3_PRESIGNED_MAX_EXPIRY=604800

# Signature timestamp tolerance in seconds, each direction.
S3_CLOCK_SKEW_SECONDS=900

# Bytes read per iteration when streaming. Raising this raises peak memory.
S3_STREAM_CHUNK_SIZE=8388608

S3_RATE_LIMIT_RPM=3000
S3_AUTH_FAILURE_LIMIT=20
```

- [x] **Step 5: Ignore the local storage root**

Append to `.gitignore`:

```gitignore
/storage/dero
```

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ConfigTest`
Expected: PASS

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/dero.php .env.example .gitignore tests/Unit/S3/ConfigTest.php
git commit -m "feat: add dero configuration"
```

---

## Task 2: BucketName value object

S3 bucket naming rules, enforced in one place. Getting these wrong means clients that work against AWS fail against Dero.

**Files:**
- Create: `app/S3/Values/BucketName.php`
- Test: `tests/Unit/S3/Values/BucketNameTest.php`

**Interfaces:**
- Produces: `BucketName::fromString(string): self` (throws `InvalidBucketNameException`), `BucketName::isValid(string): bool`, public readonly `string $value`. Consumed by Tasks 12, 21, 22.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Values/BucketNameTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidBucketNameException;
use App\S3\Values\BucketName;

it('accepts valid bucket names', function (string $name) {
    expect(BucketName::fromString($name)->value)->toBe($name);
})->with([
    'production',
    'my-bucket',
    'a.b.c',
    'abc',
    str_repeat('a', 63),
    '123-numeric-start',
]);

it('rejects invalid bucket names', function (string $name) {
    BucketName::fromString($name);
})->with([
    'ab',                        // shorter than 3
    str_repeat('a', 64),         // longer than 63
    'UpperCase',                 // uppercase is forbidden
    '-leading-hyphen',
    'trailing-hyphen-',
    'double..dot',
    'under_score',
    '192.168.1.1',               // must not look like an IPv4 address
    'xn--puny',                  // reserved prefix
    'bucket-s3alias',            // reserved suffix
    'bucket--ol-s3',             // reserved suffix
    '',
])->throws(InvalidBucketNameException::class);

it('exposes validity without throwing', function () {
    expect(BucketName::isValid('valid-name'))->toBeTrue()
        ->and(BucketName::isValid('Invalid_Name'))->toBeFalse();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=BucketNameTest`
Expected: FAIL — `Class "App\S3\Values\BucketName" not found`.

- [x] **Step 3: Create the exception**

Create `app/S3/Exceptions/InvalidBucketNameException.php`. This subclasses `S3Exception`, which does not exist yet — so create a minimal base now and flesh it out in Task 5.

`app/S3/Exceptions/S3Exception.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

use RuntimeException;

abstract class S3Exception extends RuntimeException
{
    /**
     * The S3 error code, e.g. "NoSuchBucket".
     */
    abstract public function errorCode(): string;

    /**
     * The HTTP status the error is returned with.
     */
    abstract public function statusCode(): int;

    /**
     * Additional elements rendered into the XML error document.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return [];
    }
}
```

`app/S3/Exceptions/InvalidBucketNameException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidBucketNameException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The specified bucket is not valid.');
    }

    public function errorCode(): string
    {
        return 'InvalidBucketName';
    }

    public function statusCode(): int
    {
        return 400;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['BucketName' => $this->bucketName];
    }
}
```

- [x] **Step 4: Implement the value object**

Create `app/S3/Values/BucketName.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\S3\Exceptions\InvalidBucketNameException;

final readonly class BucketName
{
    private function __construct(public string $value) {}

    /**
     * @throws InvalidBucketNameException
     */
    public static function fromString(string $name): self
    {
        if (! self::isValid($name)) {
            throw new InvalidBucketNameException($name);
        }

        return new self($name);
    }

    public static function isValid(string $name): bool
    {
        $length = strlen($name);

        if ($length < 3 || $length > 63) {
            return false;
        }

        // Lowercase letters, digits, hyphens and dots; must start and end
        // alphanumeric.
        if (preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name) !== 1) {
            return false;
        }

        if (str_contains($name, '..')) {
            return false;
        }

        // A name that parses as an IPv4 address is ambiguous with virtual-host
        // addressing and is forbidden by AWS.
        if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return false;
        }

        foreach (['xn--', 'sthree-', 'amzn-s3-demo-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        foreach (['-s3alias', '--ol-s3', '.mrap', '--x-s3'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
```

- [x] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=BucketNameTest`
Expected: PASS, 20 assertions across the datasets.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3 tests/Unit/S3/Values/BucketNameTest.php
git commit -m "feat: add BucketName value object with S3 naming rules"
```

---

## Task 3: ObjectKey value object

Keys are arbitrary UTF-8 up to 1024 bytes. They are never treated as paths — but they must be validated, hashed for indexing, and split on a delimiter for listing.

**Files:**
- Create: `app/S3/Values/ObjectKey.php`
- Create: `app/S3/Exceptions/InvalidArgumentException.php`
- Test: `tests/Unit/S3/Values/ObjectKeyTest.php`

**Interfaces:**
- Produces: `ObjectKey::fromString(string): self`, readonly `string $value`, `hash(): string` (32 raw bytes), `hashHex(): string`, `commonPrefix(string $prefix, string $delimiter): ?string`. Consumed by Tasks 12, 17, 20, 21.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Values/ObjectKeyTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidArgumentException;
use App\S3\Values\ObjectKey;

it('accepts ordinary keys', function () {
    expect(ObjectKey::fromString('reports/2026/q1.pdf')->value)
        ->toBe('reports/2026/q1.pdf');
});

it('preserves traversal sequences verbatim instead of resolving them', function () {
    // The key is data, never a path. It must survive unchanged so that a
    // GET for the same literal key finds the object again.
    expect(ObjectKey::fromString('../../etc/passwd')->value)
        ->toBe('../../etc/passwd');
});

it('accepts unicode and spaces', function () {
    expect(ObjectKey::fromString('reports/Ω first draft.txt')->value)
        ->toBe('reports/Ω first draft.txt');
});

it('rejects an empty key', function () {
    ObjectKey::fromString('');
})->throws(InvalidArgumentException::class);

it('rejects a key longer than 1024 bytes', function () {
    ObjectKey::fromString(str_repeat('a', 1025));
})->throws(InvalidArgumentException::class);

it('measures length in bytes, not characters', function () {
    // 'Ω' is two bytes in UTF-8, so 513 of them exceed 1024 bytes while being
    // only 513 characters. Using strlen rather than mb_strlen is deliberate.
    ObjectKey::fromString(str_repeat('Ω', 513));
})->throws(InvalidArgumentException::class);

it('rejects keys containing a null byte', function () {
    ObjectKey::fromString("valid\0key");
})->throws(InvalidArgumentException::class);

it('hashes to 32 raw bytes and matches sha256 of the raw key', function () {
    $key = ObjectKey::fromString('a/b/c.txt');

    expect(strlen($key->hash()))->toBe(32)
        ->and($key->hashHex())->toBe(hash('sha256', 'a/b/c.txt'));
});

it('produces different hashes for different keys', function () {
    expect(ObjectKey::fromString('a.txt')->hash())
        ->not->toBe(ObjectKey::fromString('b.txt')->hash());
});

it('derives the common prefix up to the first delimiter after the prefix', function () {
    $key = ObjectKey::fromString('photos/2026/january/img.jpg');

    expect($key->commonPrefix('photos/', '/'))->toBe('photos/2026/');
});

it('returns null when no delimiter follows the prefix', function () {
    $key = ObjectKey::fromString('photos/img.jpg');

    expect($key->commonPrefix('photos/', '/'))->toBeNull();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ObjectKeyTest`
Expected: FAIL — `Class "App\S3\Values\ObjectKey" not found`.

- [x] **Step 3: Create the exception**

Create `app/S3/Exceptions/InvalidArgumentException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidArgumentException extends S3Exception
{
    public function errorCode(): string
    {
        return 'InvalidArgument';
    }

    public function statusCode(): int
    {
        return 400;
    }
}
```

- [x] **Step 4: Implement the value object**

Create `app/S3/Values/ObjectKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\S3\Exceptions\InvalidArgumentException;

final readonly class ObjectKey
{
    public const int MAX_BYTES = 1024;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $key): self
    {
        if ($key === '') {
            throw new InvalidArgumentException('The object key must not be empty.');
        }

        // strlen, not mb_strlen: S3 limits keys to 1024 *bytes*.
        if (strlen($key) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The object key exceeds 1024 bytes.');
        }

        if (str_contains($key, "\0")) {
            throw new InvalidArgumentException('The object key must not contain a null byte.');
        }

        return new self($key);
    }

    /**
     * Raw 32-byte SHA-256 of the key, used for the unique index and point
     * lookups. Binary rather than hex so the index stays 32 bytes wide.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value, binary: true);
    }

    public function hashHex(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * The synthetic "folder" this key rolls up into for a given prefix and
     * delimiter, or null when the key has no further delimiter and is therefore
     * returned as an object rather than a CommonPrefix.
     */
    public function commonPrefix(string $prefix, string $delimiter): ?string
    {
        if ($delimiter === '' || ! str_starts_with($this->value, $prefix)) {
            return null;
        }

        $remainder = substr($this->value, strlen($prefix));
        $position = strpos($remainder, $delimiter);

        if ($position === false) {
            return null;
        }

        return $prefix.substr($remainder, 0, $position + strlen($delimiter));
    }
}
```

- [x] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ObjectKeyTest`
Expected: PASS, 11 tests.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3 tests/Unit/S3/Values/ObjectKeyTest.php
git commit -m "feat: add ObjectKey value object with byte-accurate validation"
```

---

## Task 4: Object path generation

The path-traversal defence. Physical paths come from the object's ULID and nothing else.

**Files:**
- Create: `app/Storage/ObjectPathGenerator.php`
- Test: `tests/Unit/Storage/ObjectPathGeneratorTest.php`

**Interfaces:**
- Produces: `ObjectPathGenerator::forObject(string $ulid): string`, `forPart(string $uploadId, int $partNumber): string`, `forTemp(string $token): string`. Consumed by Tasks 6, 17, 24.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/Storage/ObjectPathGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Str;

it('shards an object path three levels deep', function () {
    $path = ObjectPathGenerator::forObject('01JKXQ8ZABCDEFGHJKMNPQRSTV');

    expect($path)->toBe('objects/ab/cd/ef/01JKXQ8ZABCDEFGHJKMNPQRSTV');
});

it('shards on the random suffix rather than the timestamp prefix', function () {
    // The first 10 characters of a ULID encode the millisecond timestamp, so
    // objects created in the same millisecond share them. Sharding on those
    // characters would pile every concurrent upload into one directory.
    $a = ObjectPathGenerator::forObject('01JKXQ8ZAAAAAAAAAAAAAAAAAA');
    $b = ObjectPathGenerator::forObject('01JKXQ8ZBBBBBBBBBBBBBBBBBB');

    expect(dirname($a))->not->toBe(dirname($b));
});

it('distributes real ulids across many shard directories', function () {
    $directories = collect(range(1, 500))
        ->map(fn () => dirname(ObjectPathGenerator::forObject((string) Str::ulid())))
        ->unique();

    expect($directories->count())->toBeGreaterThan(400);
});

it('builds part paths under the upload id', function () {
    expect(ObjectPathGenerator::forPart('01JKXQUPLOAD', 7))
        ->toBe('uploads/01JKXQUPLOAD/7');
});

it('builds temp paths', function () {
    expect(ObjectPathGenerator::forTemp('abc123'))->toBe('tmp/abc123');
});

it('rejects a part number outside the S3 range', function (int $partNumber) {
    ObjectPathGenerator::forPart('01JKXQUPLOAD', $partNumber);
})->with([0, -1, 10001])->throws(InvalidArgumentException::class);

it('rejects an upload id that is not alphanumeric', function () {
    // Belt and braces: upload ids are server-generated ULIDs, but this method
    // must never be able to produce a path outside the uploads directory even
    // if a caller passes something unexpected.
    ObjectPathGenerator::forPart('../../etc', 1);
})->throws(InvalidArgumentException::class);
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ObjectPathGeneratorTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement the generator**

Create `app/Storage/ObjectPathGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/**
 * Derives physical storage paths from server-generated identifiers.
 *
 * No user-supplied object key ever reaches this class. That is what makes path
 * traversal structurally impossible rather than merely filtered against.
 */
final class ObjectPathGenerator
{
    private const int MAX_PART_NUMBER = 10000;

    public static function forObject(string $ulid): string
    {
        self::assertSafeIdentifier($ulid);

        // Shard on the LAST six characters.
        //
        // Characters 0-9 encode the timestamp, so they are identical for
        // objects created in the same millisecond. The high-order random
        // characters are no better: Laravel's ULID factory is monotonic, so
        // within a millisecond it increments the random component rather than
        // regenerating it. Only the trailing characters vary on every
        // increment, and they are also fully random across milliseconds.
        $shard = strtolower(substr($ulid, -6));

        return sprintf(
            'objects/%s/%s/%s/%s',
            substr($shard, 0, 2),
            substr($shard, 2, 2),
            substr($shard, 4, 2),
            $ulid,
        );
    }

    public static function forPart(string $uploadId, int $partNumber): string
    {
        self::assertSafeIdentifier($uploadId);

        if ($partNumber < 1 || $partNumber > self::MAX_PART_NUMBER) {
            throw new InvalidArgumentException(
                'Part number must be between 1 and '.self::MAX_PART_NUMBER.'.'
            );
        }

        return sprintf('uploads/%s/%d', $uploadId, $partNumber);
    }

    public static function forTemp(string $token): string
    {
        self::assertSafeIdentifier($token);

        return 'tmp/'.$token;
    }

    private static function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe storage identifier.');
        }
    }
}
```

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ObjectPathGeneratorTest`
Expected: PASS

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Storage tests/Unit/Storage
git commit -m "feat: add ULID-sharded object path generation"
```

---

## Task 5: S3 exception hierarchy and XML error rendering

Every failure on an S3 route must render as an S3 error document. A Laravel HTML error page here breaks every client.

**Files:**
- Create: `app/S3/Exceptions/*.php` (one per code)
- Create: `app/S3/Xml/XmlWriter.php`
- Create: `app/S3/Http/S3ErrorResponse.php`
- Test: `tests/Unit/S3/Xml/XmlWriterTest.php`
- Test: `tests/Unit/S3/Http/S3ErrorResponseTest.php`

**Interfaces:**
- Produces: `XmlWriter::render(string $root, array $data): string`, `S3ErrorResponse::fromException(S3Exception $e, string $requestId): Response`. Consumed by Tasks 6 onward and every controller.

- [x] **Step 1: Write the failing XmlWriter test**

Create `tests/Unit/S3/Xml/XmlWriterTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Xml\XmlWriter;

it('renders a declaration and root element', function () {
    expect(XmlWriter::render('Error', ['Code' => 'NoSuchBucket']))
        ->toBe(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<Error><Code>NoSuchBucket</Code></Error>'
        );
});

it('renders nested elements', function () {
    $xml = XmlWriter::render('ListBucketResult', [
        'Name' => 'photos',
        'Contents' => [
            ['Key' => 'a.jpg', 'Size' => 10],
            ['Key' => 'b.jpg', 'Size' => 20],
        ],
    ]);

    expect($xml)->toContain('<Contents><Key>a.jpg</Key><Size>10</Size></Contents>')
        ->and($xml)->toContain('<Contents><Key>b.jpg</Key><Size>20</Size></Contents>');
});

it('escapes special characters in values', function () {
    expect(XmlWriter::render('Error', ['Message' => 'a & b < c']))
        ->toContain('<Message>a &amp; b &lt; c</Message>');
});

it('renders booleans as lowercase literals', function () {
    expect(XmlWriter::render('R', ['IsTruncated' => false]))
        ->toContain('<IsTruncated>false</IsTruncated>');
});

it('omits null values entirely', function () {
    expect(XmlWriter::render('R', ['A' => null, 'B' => 'kept']))
        ->not->toContain('<A>')
        ->and(XmlWriter::render('R', ['A' => null, 'B' => 'kept']))->toContain('<B>kept</B>');
});

it('applies attributes to the root element', function () {
    expect(XmlWriter::render('ListBucketResult', ['Name' => 'x'], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']))
        ->toContain('<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=XmlWriterTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement XmlWriter**

Create `app/S3/Xml/XmlWriter.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Xml;

final class XmlWriter
{
    /**
     * Render an S3 response document.
     *
     * A list value whose key repeats (Contents, CommonPrefixes, Part) is
     * emitted as sibling elements sharing that name, which is how S3 documents
     * express repetition.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $attributes
     */
    public static function render(string $root, array $data, array $attributes = []): string
    {
        $rendered = '';

        foreach ($attributes as $name => $value) {
            $rendered .= sprintf(' %s="%s"', $name, self::escape((string) $value));
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<'.$root.$rendered.'>'
            .self::children($data)
            .'</'.$root.'>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function children(array $data): string
    {
        $xml = '';

        foreach ($data as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $xml .= '<'.$name.'>'
                        .(is_array($item) ? self::children($item) : self::scalar($item))
                        .'</'.$name.'>';
                }

                continue;
            }

            $xml .= '<'.$name.'>'
                .(is_array($value) ? self::children($value) : self::scalar($value))
                .'</'.$name.'>';
        }

        return $xml;
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return self::escape((string) $value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

- [x] **Step 4: Run the XmlWriter test**

Run: `php artisan test --compact --filter=XmlWriterTest`
Expected: PASS

- [x] **Step 5: Write the failing error-response test**

Create `tests/Unit/S3/Http/S3ErrorResponseTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\NoSuchBucketException;
use App\S3\Exceptions\NoSuchKeyException;
use App\S3\Exceptions\SignatureDoesNotMatchException;
use App\S3\Http\S3ErrorResponse;

it('renders an S3 error document with the right status and content type', function () {
    $response = S3ErrorResponse::fromException(new NoSuchBucketException('photos'), 'REQ123');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/xml')
        ->and($response->headers->get('x-amz-request-id'))->toBe('REQ123');

    $body = $response->getContent();

    expect($body)->toContain('<Code>NoSuchBucket</Code>')
        ->and($body)->toContain('<BucketName>photos</BucketName>')
        ->and($body)->toContain('<RequestId>REQ123</RequestId>')
        ->and($body)->toContain('The specified bucket does not exist.');
});

it('maps each exception to its documented code and status', function (string $class, array $args, string $code, int $status) {
    $response = S3ErrorResponse::fromException(new $class(...$args), 'R');

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getContent())->toContain('<Code>'.$code.'</Code>');
})->with([
    [NoSuchKeyException::class, ['a.txt'], 'NoSuchKey', 404],
    [AccessDeniedException::class, [], 'AccessDenied', 403],
    [SignatureDoesNotMatchException::class, [], 'SignatureDoesNotMatch', 403],
]);

it('never leaks a secret in the rendered body', function () {
    $response = S3ErrorResponse::fromException(new SignatureDoesNotMatchException(), 'R');

    expect($response->getContent())->not->toContain('secret');
});
```

- [x] **Step 6: Run it to confirm it fails**

Run: `php artisan test --compact --filter=S3ErrorResponseTest`
Expected: FAIL — exception classes not found.

- [x] **Step 7: Create the exception classes**

Create each file in `app/S3/Exceptions/`. They all follow the same shape; here are the four the test needs, and the rest of the codes listed after.

`NoSuchBucketException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NoSuchBucketException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The specified bucket does not exist.');
    }

    public function errorCode(): string
    {
        return 'NoSuchBucket';
    }

    public function statusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['BucketName' => $this->bucketName];
    }
}
```

`NoSuchKeyException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NoSuchKeyException extends S3Exception
{
    public function __construct(private readonly string $objectKey)
    {
        parent::__construct('The specified key does not exist.');
    }

    public function errorCode(): string
    {
        return 'NoSuchKey';
    }

    public function statusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['Key' => $this->objectKey];
    }
}
```

`AccessDeniedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class AccessDeniedException extends S3Exception
{
    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'AccessDenied';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
```

`SignatureDoesNotMatchException.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class SignatureDoesNotMatchException extends S3Exception
{
    public function __construct()
    {
        parent::__construct(
            'The request signature we calculated does not match the signature you provided. '
            .'Check your key and signing method.'
        );
    }

    public function errorCode(): string
    {
        return 'SignatureDoesNotMatch';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
```

Create the remaining codes with the same shape. Constructor arguments and context are noted where they differ from a plain message-only exception:

| Class | Code | Status | Constructor / context |
|---|---|---|---|
| `InvalidAccessKeyIdException` | `InvalidAccessKeyId` | 403 | `string $accessKeyId`; context `AWSAccessKeyId` |
| `RequestTimeTooSkewedException` | `RequestTimeTooSkewed` | 403 | none |
| `InvalidRequestException` | `InvalidRequest` | 400 | `string $message` |
| `BucketAlreadyExistsException` | `BucketAlreadyExists` | 409 | `string $bucketName`; context `BucketName` |
| `BucketAlreadyOwnedByYouException` | `BucketAlreadyOwnedByYou` | 409 | `string $bucketName`; context `BucketName` |
| `BucketNotEmptyException` | `BucketNotEmpty` | 409 | `string $bucketName`; context `BucketName` |
| `EntityTooLargeException` | `EntityTooLarge` | 400 | `int $proposedSize, int $maxSize`; context `ProposedSize`, `MaxSizeAllowed` |
| `EntityTooSmallException` | `EntityTooSmall` | 400 | `int $partNumber, int $size, int $minSize`; context `PartNumber`, `MinSizeAllowed`, `ProposedSize` |
| `InvalidPartException` | `InvalidPart` | 400 | `int $partNumber`; context `PartNumber` |
| `InvalidPartOrderException` | `InvalidPartOrder` | 400 | none |
| `NoSuchUploadException` | `NoSuchUpload` | 404 | `string $uploadId`; context `UploadId` |
| `MalformedXmlException` | `MalformedXML` | 400 | none |
| `PreconditionFailedException` | `PreconditionFailed` | 412 | `string $condition`; context `Condition` |
| `InvalidRangeException` | `InvalidRange` | 416 | `string $range`; context `RangeRequested` |
| `MethodNotAllowedException` | `MethodNotAllowed` | 405 | none |
| `NotImplementedException` | `NotImplemented` | 501 | `string $operation`; context `Operation` |
| `SlowDownException` | `SlowDown` | 503 | none |
| `InternalErrorException` | `InternalError` | 500 | none |

Messages: use AWS's wording where it is well known (`"Access Denied."`, `"The specified bucket does not exist."`), otherwise a plain sentence describing the condition.

- [x] **Step 8: Implement S3ErrorResponse**

Create `app/S3/Http/S3ErrorResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\S3Exception;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Response;

final class S3ErrorResponse
{
    public static function fromException(S3Exception $exception, string $requestId): Response
    {
        $body = XmlWriter::render('Error', [
            'Code' => $exception->errorCode(),
            'Message' => $exception->getMessage(),
            ...$exception->context(),
            'RequestId' => $requestId,
        ]);

        return new Response($body, $exception->statusCode(), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $requestId,
        ]);
    }
}
```

- [x] **Step 9: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=S3ErrorResponseTest`
Expected: PASS

- [x] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3 tests/Unit/S3
git commit -m "feat: add S3 exception hierarchy and XML error rendering"
```

---

## Task 6: Migrations

All nine tables in one migration, because they are mutually referential and a partial schema is not testable.

**Files:**
- Create: `database/migrations/2026_08_04_000001_create_dero_tables.php`
- Test: `tests/Feature/S3/SchemaTest.php`

**Interfaces:**
- Produces: tables `buckets`, `s3_objects`, `multipart_uploads`, `multipart_parts`, `access_keys`, `bucket_policies`, `audit_logs`, `usage_records`, `system_settings`. Consumed by Task 7 onward.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates every dero table', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'buckets', 's3_objects', 'multipart_uploads', 'multipart_parts',
    'access_keys', 'bucket_policies', 'audit_logs', 'usage_records',
    'system_settings',
]);

it('stores the object key and its hash separately', function () {
    // The full key is kept for fidelity; the hash carries the unique index,
    // because a 1024-character utf8mb4 column exceeds InnoDB's 3072-byte
    // index limit and the unique index would simply fail to build on MySQL.
    expect(Schema::hasColumns('s3_objects', ['key', 'key_hash']))->toBeTrue();
});

it('scopes every tenant-owned table by team', function (string $table) {
    expect(Schema::hasColumn($table, 'team_id'))->toBeTrue();
})->with(['buckets', 's3_objects', 'multipart_uploads', 'access_keys', 'bucket_policies', 'audit_logs', 'usage_records']);
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=SchemaTest`
Expected: FAIL — `buckets` table does not exist.

- [x] **Step 3: Create the migration**

Create `database/migrations/2026_08_04_000001_create_dero_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buckets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63)->unique();
            $table->string('region', 32);
            $table->string('visibility', 32)->default('private');

            // Reserved for a later release. Declared now so enabling versioning
            // later is a behaviour change rather than a table rewrite.
            $table->string('versioning_status', 32)->default('Disabled');
            $table->boolean('object_lock_enabled')->default(false);

            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->json('cors_rules')->nullable();

            // Denormalised counters, maintained in the same transaction as the
            // object write. Listing must never require count(*) over millions
            // of rows.
            $table->unsignedBigInteger('objects_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);

            $table->timestamps();

            $table->index(['team_id', 'name']);
        });

        Schema::create('s3_objects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->text('key');
            $table->binary('key_hash', 32);

            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('etag', 128);
            $table->string('checksum_sha256', 64)->nullable();

            $table->string('content_type')->default('binary/octet-stream');
            $table->string('content_encoding')->nullable();
            $table->string('content_disposition')->nullable();
            $table->string('cache_control')->nullable();
            $table->string('expires_header')->nullable();
            $table->json('metadata')->nullable();

            $table->string('storage_backend', 32)->default('local');
            $table->boolean('is_multipart')->default(false);
            $table->unsignedInteger('part_count')->nullable();

            $table->timestamp('last_modified');
            $table->timestamps();

            $table->unique(['bucket_id', 'key_hash']);
            $table->index(['bucket_id', 'content_type']);
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('multipart_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('key');
            $table->binary('key_hash', 32);
            $table->string('content_type')->default('binary/octet-stream');
            $table->string('content_encoding')->nullable();
            $table->string('content_disposition')->nullable();
            $table->string('cache_control')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('access_key_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['bucket_id', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('multipart_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('multipart_upload_id')
                ->constrained('multipart_uploads')->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->unsignedBigInteger('size_bytes');
            $table->string('etag', 64);
            $table->binary('checksum_md5', 16);
            $table->string('storage_path');
            $table->timestamps();

            $table->unique(['multipart_upload_id', 'part_number']);
        });

        Schema::create('access_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('access_key_id', 32)->unique();

            // Encrypted, not hashed. SigV4 verification re-derives the signing
            // key from the plaintext secret, so a one-way hash cannot work.
            // See docs/security.md.
            $table->text('secret_key');

            $table->json('permissions');
            $table->json('bucket_scope')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'revoked_at']);
        });

        Schema::create('bucket_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('principal_type', 32);
            $table->string('principal_id')->nullable();
            $table->json('permissions');
            $table->timestamps();

            $table->index(['bucket_id', 'principal_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();
            $table->string('action', 64);
            $table->foreignUlid('bucket_id')->nullable();
            $table->text('object_key')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('result', 16);
            $table->string('error_code', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->index(['team_id', 'created_at']);
            $table->index(['bucket_id', 'created_at']);
            $table->index(['result', 'created_at']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bucket_id')->nullable();
            $table->timestamp('period_start');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_uploaded')->default(0);
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedBigInteger('object_count')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'bucket_id', 'period_start'], 'usage_records_period_unique');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        $this->applyBinaryKeyCollation();
    }

    /**
     * ListObjectsV2 must return keys in bytewise lexicographic order, and
     * continuation-token pagination depends on that ordering being stable.
     * SQLite orders TEXT with BINARY collation natively; MySQL's default
     * utf8mb4_unicode_ci is case-insensitive and locale-aware, which produces a
     * different order and silently breaks pagination.
     */
    private function applyBinaryKeyCollation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE s3_objects MODIFY `key` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE multipart_uploads MODIFY `key` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('CREATE INDEX s3_objects_bucket_key_index ON s3_objects (bucket_id, `key`(191))');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('bucket_policies');
        Schema::dropIfExists('access_keys');
        Schema::dropIfExists('multipart_parts');
        Schema::dropIfExists('multipart_uploads');
        Schema::dropIfExists('s3_objects');
        Schema::dropIfExists('buckets');
    }
};
```

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=SchemaTest`
Expected: PASS

- [x] **Step 5: Verify the migration is reversible**

Run: `php artisan migrate:fresh --env=testing && php artisan migrate:rollback --env=testing`
Expected: both succeed with no foreign-key errors. Order matters in `down()` — children before parents.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations tests/Feature/S3/SchemaTest.php
git commit -m "feat: add dero schema migration"
```

---

## Task 7: Models and factories

**Files:**
- Create: `app/Models/Bucket.php`, `S3Object.php`, `MultipartUpload.php`, `MultipartPart.php`, `AccessKey.php`, `BucketPolicy.php`, `AuditLog.php`, `UsageRecord.php`
- Create: `app/Enums/BucketVisibility.php`, `app/Enums/AccessKeyPermission.php`
- Create: `database/factories/BucketFactory.php`, `S3ObjectFactory.php`, `AccessKeyFactory.php`
- Test: `tests/Feature/S3/ModelsTest.php`

**Interfaces:**
- Produces: `Bucket` (`team()`, `objects()`, `scopeForTeam()`), `S3Object` (`bucket()`), `AccessKey` (`team()`, `isActive()`, `allows(AccessKeyPermission, ?string $bucketName)`, `secret_key` encrypted cast), enums `BucketVisibility::{Private,PublicRead}` and `AccessKeyPermission::{Read,Write,Delete,List,Admin}`. Consumed by Tasks 12 onward.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/ModelsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Illuminate\Support\Facades\DB;

it('creates a bucket with a ulid primary key', function () {
    $bucket = Bucket::factory()->create();

    expect($bucket->id)->toBeString()->toHaveLength(26);
});

it('relates objects to their bucket', function () {
    $bucket = Bucket::factory()->create();
    S3Object::factory()->for($bucket)->count(3)->create();

    expect($bucket->objects()->count())->toBe(3);
});

it('encrypts the access key secret at rest', function () {
    $key = AccessKey::factory()->create(['secret_key' => 'super-secret-value']);

    $raw = DB::table('access_keys')->where('id', $key->id)->value('secret_key');

    expect($raw)->not->toContain('super-secret-value')
        ->and($key->fresh()->secret_key)->toBe('super-secret-value');
});

it('treats a revoked key as inactive', function () {
    expect(AccessKey::factory()->create()->isActive())->toBeTrue()
        ->and(AccessKey::factory()->revoked()->create()->isActive())->toBeFalse()
        ->and(AccessKey::factory()->expired()->create()->isActive())->toBeFalse();
});

it('grants permissions listed on the key', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Read->value, AccessKeyPermission::List->value],
    ]);

    expect($key->allows(AccessKeyPermission::Read, null))->toBeTrue()
        ->and($key->allows(AccessKeyPermission::Write, null))->toBeFalse();
});

it('grants everything to an admin key', function () {
    $key = AccessKey::factory()->create(['permissions' => [AccessKeyPermission::Admin->value]]);

    expect($key->allows(AccessKeyPermission::Delete, null))->toBeTrue();
});

it('confines a scoped key to its buckets', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Admin->value],
        'bucket_scope' => ['allowed-bucket'],
    ]);

    expect($key->allows(AccessKeyPermission::Read, 'allowed-bucket'))->toBeTrue()
        ->and($key->allows(AccessKeyPermission::Read, 'other-bucket'))->toBeFalse();
});

it('treats a null bucket scope as all buckets in the team', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Admin->value],
        'bucket_scope' => null,
    ]);

    expect($key->allows(AccessKeyPermission::Read, 'any-bucket'))->toBeTrue();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ModelsTest`
Expected: FAIL — models not found.

- [x] **Step 3: Create the enums**

`app/Enums/BucketVisibility.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BucketVisibility: string
{
    case Private = 'private';
    case PublicRead = 'public-read';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::PublicRead => 'Public read',
        };
    }

    public function allowsAnonymousRead(): bool
    {
        return $this === self::PublicRead;
    }
}
```

`app/Enums/AccessKeyPermission.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessKeyPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';
    case List = 'list';
    case Admin = 'admin';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<AccessKeyPermission>
     */
    public static function assignable(): array
    {
        return self::cases();
    }
}
```

- [x] **Step 4: Create the models**

`app/Models/Bucket.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BucketVisibility;
use Database\Factories\BucketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $team_id
 * @property string $name
 * @property string $region
 * @property BucketVisibility $visibility
 * @property int|null $quota_bytes
 * @property array<int, array<string, mixed>>|null $cors_rules
 * @property int $objects_count
 * @property int $storage_bytes
 */
#[Fillable(['team_id', 'name', 'region', 'visibility', 'quota_bytes', 'cors_rules'])]
class Bucket extends Model
{
    /** @use HasFactory<BucketFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => BucketVisibility::class,
            'cors_rules' => 'array',
            'object_lock_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<S3Object, $this>
     */
    public function objects(): HasMany
    {
        return $this->hasMany(S3Object::class);
    }

    /**
     * @param  Builder<Bucket>  $query
     */
    public function scopeForTeam(Builder $query, int $teamId): void
    {
        $query->where('team_id', $teamId);
    }
}
```

`app/Models/S3Object.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\S3ObjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bucket_id
 * @property int $team_id
 * @property string $key
 * @property string $storage_path
 * @property int $size_bytes
 * @property string $etag
 * @property string $content_type
 * @property array<string, string>|null $metadata
 * @property Carbon $last_modified
 */
#[Fillable([
    'bucket_id', 'team_id', 'key', 'key_hash', 'storage_path', 'size_bytes',
    'etag', 'checksum_sha256', 'content_type', 'content_encoding',
    'content_disposition', 'cache_control', 'expires_header', 'metadata',
    'storage_backend', 'is_multipart', 'part_count', 'last_modified',
])]
class S3Object extends Model
{
    /** @use HasFactory<S3ObjectFactory> */
    use HasFactory, HasUlids;

    protected $table = 's3_objects';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_multipart' => 'boolean',
            'last_modified' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }
}
```

`app/Models/AccessKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessKeyPermission;
use Database\Factories\AccessKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property string $name
 * @property string $access_key_id
 * @property string $secret_key
 * @property array<int, string> $permissions
 * @property array<int, string>|null $bucket_scope
 * @property Carbon|null $revoked_at
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'team_id', 'user_id', 'name', 'access_key_id', 'secret_key',
    'permissions', 'bucket_scope', 'expires_at',
])]
class AccessKey extends Model
{
    /** @use HasFactory<AccessKeyFactory> */
    use HasFactory, HasUlids;

    /**
     * The secret is hidden from every array and JSON representation so it
     * cannot leak through a controller response or a log line.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted, not hashed: SigV4 must re-derive the signing key from
            // the plaintext secret, which a one-way hash makes impossible.
            'secret_key' => 'encrypted',
            'permissions' => 'array',
            'bucket_scope' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Determine whether this key may perform an operation, optionally against a
     * named bucket. A null bucket scope means every bucket in the team.
     */
    public function allows(AccessKeyPermission $permission, ?string $bucketName): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($bucketName !== null
            && $this->bucket_scope !== null
            && ! in_array($bucketName, $this->bucket_scope, strict: true)) {
            return false;
        }

        if (in_array(AccessKeyPermission::Admin->value, $this->permissions, strict: true)) {
            return true;
        }

        return in_array($permission->value, $this->permissions, strict: true);
    }
}
```

Create `MultipartUpload`, `MultipartPart`, `BucketPolicy`, `AuditLog`, and `UsageRecord` in the same style: `HasUlids` on `MultipartUpload` only (the others use auto-increment ids), `#[Fillable]` listing every column written by application code, `casts()` returning `'metadata' => 'array'` / `'meta' => 'array'` / `'permissions' => 'array'` where the column is JSON and `'expires_at' => 'datetime'` / `'period_start' => 'datetime'` where it is a timestamp. `AuditLog` sets `public $timestamps = false;` because the table has only `created_at`.

- [x] **Step 5: Create the factories**

`database/factories/BucketFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BucketVisibility;
use App\Models\Bucket;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bucket>
 */
class BucketFactory extends Factory
{
    protected $model = Bucket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->slug(2),
            'region' => 'us-east-1',
            'visibility' => BucketVisibility::Private,
        ];
    }

    public function publicRead(): static
    {
        return $this->state(fn () => ['visibility' => BucketVisibility::PublicRead]);
    }
}
```

`database/factories/AccessKeyFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessKey>
 */
class AccessKeyFactory extends Factory
{
    protected $model = AccessKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'access_key_id' => 'DERO'.strtoupper(Str::random(16)),
            'secret_key' => Str::random(40),
            'permissions' => [AccessKeyPermission::Admin->value],
            'bucket_scope' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @param  array<int, AccessKeyPermission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => [
            'permissions' => array_map(fn (AccessKeyPermission $p) => $p->value, $permissions),
        ]);
    }
}
```

`database/factories/S3ObjectFactory.php` follows the same pattern: `bucket_id` from `Bucket::factory()`, `team_id` matched to the bucket's team, `key` from `fake()->slug().'.txt'`, `key_hash` computed as `hash('sha256', $key, binary: true)`, `storage_path` from `ObjectPathGenerator::forObject()`, `size_bytes` a small integer, `etag` an MD5 of random bytes, `last_modified` `now()`.

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ModelsTest`
Expected: PASS, 8 tests.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models app/Enums database/factories tests/Feature/S3/ModelsTest.php
git commit -m "feat: add dero models, enums and factories"
```

---

## Task 8: Storage backend contract and local implementation

The seam that lets a future S3 or Azure backend drop in without touching a controller.

**Files:**
- Create: `app/Storage/Values/StoredObject.php`, `app/Storage/Values/StoredPart.php`
- Create: `app/Storage/Contracts/ObjectStorageBackend.php`
- Create: `app/Storage/LocalFilesystemBackend.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Storage/LocalFilesystemBackendTest.php`

**Interfaces:**
- Produces: the `ObjectStorageBackend` interface exactly as written below. Every later task resolves it from the container, never `LocalFilesystemBackend` directly.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Storage/LocalFilesystemBackendTest.php`:

```php
<?php

declare(strict_types=1);

use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\LocalFilesystemBackend;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);
    $this->backend = app(ObjectStorageBackend::class);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

function streamFrom(string $contents)
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

it('is bound to the local backend by default', function () {
    expect($this->backend)->toBeInstanceOf(LocalFilesystemBackend::class);
});

it('stores a stream and reports its size and hashes', function () {
    $stored = $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('hello'));

    expect($stored->size)->toBe(5)
        ->and($stored->md5Hex)->toBe(md5('hello'))
        ->and($stored->sha256Hex)->toBe(hash('sha256', 'hello'))
        ->and($stored->path)->toBe('objects/ab/cd/ef/OBJ1');
});

it('reads back exactly what was written', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('hello world'));

    $stream = $this->backend->readStream('objects/ab/cd/ef/OBJ1');

    expect(stream_get_contents($stream))->toBe('hello world');
    fclose($stream);
});

it('reads a byte range', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('0123456789'));

    $stream = $this->backend->readRange('objects/ab/cd/ef/OBJ1', 2, 4);

    expect(stream_get_contents($stream))->toBe('2345');
    fclose($stream);
});

it('reports existence and size', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('abc'));

    expect($this->backend->exists('objects/ab/cd/ef/OBJ1'))->toBeTrue()
        ->and($this->backend->size('objects/ab/cd/ef/OBJ1'))->toBe(3)
        ->and($this->backend->exists('objects/ab/cd/ef/MISSING'))->toBeFalse();
});

it('deletes an object and tolerates deleting it twice', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('abc'));

    $this->backend->delete('objects/ab/cd/ef/OBJ1');
    $this->backend->delete('objects/ab/cd/ef/OBJ1');

    expect($this->backend->exists('objects/ab/cd/ef/OBJ1'))->toBeFalse();
});

it('copies without reading the source into memory', function () {
    $this->backend->put('objects/ab/cd/ef/SRC', streamFrom('payload'));

    $this->backend->copy('objects/ab/cd/ef/SRC', 'objects/11/22/33/DST');

    expect(stream_get_contents($this->backend->readStream('objects/11/22/33/DST')))
        ->toBe('payload');
});

it('assembles multipart parts in ascending part order', function () {
    $this->backend->putPart('UPLOAD1', 2, streamFrom('second'));
    $this->backend->putPart('UPLOAD1', 1, streamFrom('first-'));

    $stored = $this->backend->completeMultipart('UPLOAD1', [1, 2], 'objects/ab/cd/ef/FINAL');

    expect(stream_get_contents($this->backend->readStream('objects/ab/cd/ef/FINAL')))
        ->toBe('first-second')
        ->and($stored->size)->toBe(12);
});

it('removes part files once the upload completes', function () {
    $this->backend->putPart('UPLOAD1', 1, streamFrom('data'));
    $this->backend->completeMultipart('UPLOAD1', [1], 'objects/ab/cd/ef/FINAL');

    expect($this->backend->exists('uploads/UPLOAD1/1'))->toBeFalse();
});

it('discards every part when an upload is aborted', function () {
    $this->backend->putPart('UPLOAD1', 1, streamFrom('data'));

    $this->backend->abortMultipart('UPLOAD1');

    expect($this->backend->exists('uploads/UPLOAD1/1'))->toBeFalse();
});

it('refuses to resolve a path that escapes the storage root', function () {
    $this->backend->readStream('../../../etc/passwd');
})->throws(InvalidArgumentException::class);

it('keeps memory flat while storing a large stream', function () {
    // 64 MB written through an 8 MB chunk loop must not cost 64 MB of RAM.
    $source = fopen('php://temp', 'r+');
    for ($i = 0; $i < 64; $i++) {
        fwrite($source, str_repeat('x', 1024 * 1024));
    }
    rewind($source);

    $before = memory_get_usage(true);
    $this->backend->put('objects/ab/cd/ef/BIG', $source);
    $growth = memory_get_usage(true) - $before;

    expect($growth)->toBeLessThan(32 * 1024 * 1024);
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=LocalFilesystemBackendTest`
Expected: FAIL — `ObjectStorageBackend` is not bound.

- [x] **Step 3: Create the value objects**

`app/Storage/Values/StoredObject.php`:

```php
<?php

declare(strict_types=1);

namespace App\Storage\Values;

final readonly class StoredObject
{
    public function __construct(
        public string $path,
        public int $size,
        public string $md5Hex,
        public string $sha256Hex,
    ) {}
}
```

`app/Storage/Values/StoredPart.php`:

```php
<?php

declare(strict_types=1);

namespace App\Storage\Values;

final readonly class StoredPart
{
    public function __construct(
        public string $path,
        public int $partNumber,
        public int $size,
        public string $md5Hex,
    ) {}
}
```

- [x] **Step 4: Create the contract**

`app/Storage/Contracts/ObjectStorageBackend.php`:

```php
<?php

declare(strict_types=1);

namespace App\Storage\Contracts;

use App\Storage\Values\StoredObject;
use App\Storage\Values\StoredPart;

/**
 * The seam between the S3 protocol layer and physical storage.
 *
 * Every byte-moving method takes or returns a stream resource, never a string.
 * That is what keeps the contract honest about objects larger than memory.
 */
interface ObjectStorageBackend
{
    /**
     * @param  resource  $stream
     */
    public function put(string $path, mixed $stream, ?int $knownSize = null): StoredObject;

    /**
     * @return resource
     */
    public function readStream(string $path): mixed;

    /**
     * @return resource
     */
    public function readRange(string $path, int $offset, int $length): mixed;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function size(string $path): int;

    public function copy(string $from, string $to): void;

    /**
     * @param  resource  $stream
     */
    public function putPart(string $uploadId, int $partNumber, mixed $stream): StoredPart;

    /**
     * Concatenate the listed parts, in the order given, into a single object.
     *
     * @param  array<int, int>  $partNumbers
     */
    public function completeMultipart(string $uploadId, array $partNumbers, string $target): StoredObject;

    public function abortMultipart(string $uploadId): void;
}
```

- [x] **Step 5: Implement the local backend**

`app/Storage/LocalFilesystemBackend.php`:

```php
<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\Values\StoredObject;
use App\Storage\Values\StoredPart;
use InvalidArgumentException;
use RuntimeException;

final class LocalFilesystemBackend implements ObjectStorageBackend
{
    public function __construct(
        private readonly string $root,
        private readonly int $chunkSize,
    ) {}

    public function put(string $path, mixed $stream, ?int $knownSize = null): StoredObject
    {
        $absolute = $this->absolute($path);
        $this->ensureDirectory(dirname($absolute));

        // Write to a temporary neighbour and rename into place, so a crashed
        // upload leaves a temp file rather than a truncated object.
        $temporary = $absolute.'.'.bin2hex(random_bytes(8)).'.part';

        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $size = 0;

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$temporary} for writing.");
        }

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, $this->chunkSize);

                if ($chunk === false) {
                    throw new RuntimeException('Failed reading the source stream.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($md5, $chunk);
                hash_update($sha256, $chunk);
                $size += strlen($chunk);

                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException("Failed writing to {$temporary}.");
                }
            }
        } catch (RuntimeException $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        if (! rename($temporary, $absolute)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to move {$temporary} into place.");
        }

        return new StoredObject($path, $size, hash_final($md5), hash_final($sha256));
    }

    public function readStream(string $path): mixed
    {
        $handle = @fopen($this->absolute($path), 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        return $handle;
    }

    public function readRange(string $path, int $offset, int $length): mixed
    {
        $handle = $this->readStream($path);
        fseek($handle, $offset);

        // A capped stream so callers cannot read past the requested range.
        $capped = fopen('php://temp', 'r+b');
        $remaining = $length;

        while ($remaining > 0 && ! feof($handle)) {
            $chunk = fread($handle, min($this->chunkSize, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($capped, $chunk);
            $remaining -= strlen($chunk);
        }

        fclose($handle);
        rewind($capped);

        return $capped;
    }

    public function delete(string $path): void
    {
        @unlink($this->absolute($path));
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function size(string $path): int
    {
        $size = @filesize($this->absolute($path));

        if ($size === false) {
            throw new RuntimeException("Unable to stat {$path}.");
        }

        return $size;
    }

    public function copy(string $from, string $to): void
    {
        $destination = $this->absolute($to);
        $this->ensureDirectory(dirname($destination));

        if (! copy($this->absolute($from), $destination)) {
            throw new RuntimeException("Unable to copy {$from} to {$to}.");
        }
    }

    public function putPart(string $uploadId, int $partNumber, mixed $stream): StoredPart
    {
        $path = ObjectPathGenerator::forPart($uploadId, $partNumber);
        $stored = $this->put($path, $stream);

        return new StoredPart($path, $partNumber, $stored->size, $stored->md5Hex);
    }

    public function completeMultipart(string $uploadId, array $partNumbers, string $target): StoredObject
    {
        $absolute = $this->absolute($target);
        $this->ensureDirectory(dirname($absolute));

        $temporary = $absolute.'.'.bin2hex(random_bytes(8)).'.part';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$temporary} for writing.");
        }

        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $size = 0;

        foreach ($partNumbers as $partNumber) {
            $part = $this->readStream(ObjectPathGenerator::forPart($uploadId, $partNumber));

            while (! feof($part)) {
                $chunk = fread($part, $this->chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($md5, $chunk);
                hash_update($sha256, $chunk);
                $size += strlen($chunk);
                fwrite($handle, $chunk);
            }

            fclose($part);
        }

        fclose($handle);
        rename($temporary, $absolute);

        $this->abortMultipart($uploadId);

        return new StoredObject($target, $size, hash_final($md5), hash_final($sha256));
    }

    public function abortMultipart(string $uploadId): void
    {
        $directory = $this->absolute('uploads/'.$uploadId);

        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    /**
     * Resolve a storage-relative path to an absolute one, refusing anything
     * that would escape the storage root.
     *
     * Callers never pass user input here — physical paths come from
     * ObjectPathGenerator — but this is the last line of defence and cheap.
     */
    private function absolute(string $path): string
    {
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new InvalidArgumentException("Refusing to resolve unsafe path: {$path}");
        }

        return rtrim($this->root, '/').'/'.ltrim($path, '/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o750, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory {$directory}.");
        }
    }
}
```

- [x] **Step 6: Bind the contract**

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->singleton(ObjectStorageBackend::class, fn () => new LocalFilesystemBackend(
    root: (string) config('dero.storage_root'),
    chunkSize: (int) config('dero.stream_chunk_size'),
));
```

Add the imports `use App\Storage\Contracts\ObjectStorageBackend;` and `use App\Storage\LocalFilesystemBackend;`.

> The backend reads `config('dero.storage_root')` at resolution time. Tests that
> override the config must resolve the backend *after* the override — which is
> why `beforeEach` in the test sets the config before calling `app()`.

- [x] **Step 7: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=LocalFilesystemBackendTest`
Expected: PASS, 13 tests.

- [x] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Storage app/Providers/AppServiceProvider.php tests/Feature/Storage
git commit -m "feat: add streaming object storage backend"
```

---

## Task 9: Canonical request construction

The foundation of SigV4. If canonicalisation is wrong by one character, every signature fails and the error message tells you nothing useful. It is tested against AWS's own published vectors.

**Files:**
- Create: `app/S3/Auth/CanonicalRequest.php`
- Test: `tests/Unit/S3/Auth/CanonicalRequestTest.php`

**Interfaces:**
- Produces: `CanonicalRequest::uri(string $path): string`, `CanonicalRequest::queryString(array $query): string`, `CanonicalRequest::headers(array $headers, array $signedHeaders): string`, `CanonicalRequest::build(string $method, string $path, array $query, array $headers, array $signedHeaders, string $payloadHash): string`. Consumed by Tasks 10 and 11.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Auth/CanonicalRequestTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Auth\CanonicalRequest;

it('percent-encodes each path segment but keeps the separators', function () {
    expect(CanonicalRequest::uri('/photos/my file.jpg'))
        ->toBe('/photos/my%20file.jpg');
});

it('encodes characters RFC 3986 reserves that rawurlencode leaves alone', function () {
    // rawurlencode leaves ~ alone (correct) but encodes + and = (also correct).
    expect(CanonicalRequest::uri('/a~b'))->toBe('/a~b')
        ->and(CanonicalRequest::uri('/a+b'))->toBe('/a%2Bb')
        ->and(CanonicalRequest::uri('/a=b'))->toBe('/a%3Db');
});

it('uses a single slash for an empty path', function () {
    expect(CanonicalRequest::uri(''))->toBe('/')
        ->and(CanonicalRequest::uri('/'))->toBe('/');
});

it('preserves a trailing slash', function () {
    expect(CanonicalRequest::uri('/photos/'))->toBe('/photos/');
});

it('sorts query parameters by name and encodes them', function () {
    expect(CanonicalRequest::queryString(['b' => '2', 'a' => '1', 'c' => 'x y']))
        ->toBe('a=1&b=2&c=x%20y');
});

it('renders a valueless query parameter with a trailing equals', function () {
    // ?acl signs as "acl=" — omitting the equals breaks the signature.
    expect(CanonicalRequest::queryString(['acl' => '']))->toBe('acl=');
});

it('sorts by encoded name, not decoded name', function () {
    expect(CanonicalRequest::queryString(['a-b' => '1', 'a.b' => '2']))
        ->toBe('a-b=1&a.b=2');
});

it('lowercases header names, trims values and sorts them', function () {
    $canonical = CanonicalRequest::headers(
        ['Host' => 'example.com', 'X-Amz-Date' => '  20260804T000000Z  '],
        ['host', 'x-amz-date'],
    );

    expect($canonical)->toBe("host:example.com\nx-amz-date:20260804T000000Z\n");
});

it('collapses runs of internal whitespace in header values', function () {
    expect(CanonicalRequest::headers(['X-Amz-Meta-A' => "one   two"], ['x-amz-meta-a']))
        ->toBe("x-amz-meta-a:one two\n");
});

it('excludes headers that were not signed', function () {
    $canonical = CanonicalRequest::headers(
        ['Host' => 'example.com', 'X-Ignored' => 'value'],
        ['host'],
    );

    expect($canonical)->toBe("host:example.com\n");
});

it('builds the canonical request in the documented order', function () {
    $canonical = CanonicalRequest::build(
        method: 'GET',
        path: '/photos/cat.jpg',
        query: ['x' => '1'],
        headers: ['Host' => 'example.com'],
        signedHeaders: ['host'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );

    expect($canonical)->toBe(implode("\n", [
        'GET',
        '/photos/cat.jpg',
        'x=1',
        'host:example.com',
        '',
        'host',
        'UNSIGNED-PAYLOAD',
    ]));
});

it('matches the AWS published get-vanilla vector', function () {
    // From the AWS SigV4 test suite. If this fails, no real client will work.
    $canonical = CanonicalRequest::build(
        method: 'GET',
        path: '/',
        query: [],
        headers: [
            'Host' => 'example.amazonaws.com',
            'X-Amz-Date' => '20150830T123600Z',
        ],
        signedHeaders: ['host', 'x-amz-date'],
        payloadHash: hash('sha256', ''),
    );

    expect(hash('sha256', $canonical))
        ->toBe('ff11897932ad3f4e8b18135d722051e5ac45fc38421b1b1bf0bfe20a2e6a1a4a');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=CanonicalRequestTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement it**

Create `app/S3/Auth/CanonicalRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

/**
 * Builds the canonical request string defined by AWS Signature Version 4.
 *
 * Every rule here is load-bearing. A single wrong character produces a
 * signature mismatch with no diagnostic beyond "SignatureDoesNotMatch".
 */
final class CanonicalRequest
{
    /**
     * Percent-encode each path segment while keeping the separators intact.
     *
     * S3 (unlike most AWS services) does NOT double-encode the path.
     */
    public static function uri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);

        return implode('/', array_map(
            static fn (string $segment): string => str_replace('%7E', '~', rawurlencode($segment)),
            $segments,
        ));
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function queryString(array $query): string
    {
        $encoded = [];

        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }

        // Sorted by *encoded* name, which is what AWS specifies.
        ksort($encoded, SORT_STRING);

        $pairs = [];

        foreach ($encoded as $name => $value) {
            // Always emit the equals sign, even for an empty value: "?acl"
            // signs as "acl=".
            $pairs[] = $name.'='.$value;
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $signedHeaders
     */
    public static function headers(array $headers, array $signedHeaders): string
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = self::normaliseValue((string) $value);
        }

        $canonical = '';

        foreach ($signedHeaders as $name) {
            $canonical .= $name.':'.($normalised[$name] ?? '')."\n";
        }

        return $canonical;
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $signedHeaders
     */
    public static function build(
        string $method,
        string $path,
        array $query,
        array $headers,
        array $signedHeaders,
        string $payloadHash,
    ): string {
        sort($signedHeaders, SORT_STRING);

        return implode("\n", [
            strtoupper($method),
            self::uri($path),
            self::queryString($query),
            self::headers($headers, $signedHeaders),
            implode(';', $signedHeaders),
            $payloadHash,
        ]);
    }

    /**
     * Trim the value and collapse runs of internal whitespace to one space.
     */
    private static function normaliseValue(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }
}
```

> Note on `build()`: `headers()` already terminates with a newline, so the
> `implode("\n", …)` produces the blank line AWS requires between the canonical
> headers and the signed-header list. Do not add another.

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=CanonicalRequestTest`
Expected: PASS, 12 tests. If the AWS vector test fails, the bug is in `uri()` or `headers()` — do not proceed until it passes, because every later signature test depends on it.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3/Auth tests/Unit/S3/Auth
git commit -m "feat: add SigV4 canonical request construction"
```

---

## Task 10: Signature verification

**Files:**
- Create: `app/S3/Auth/Credential.php`, `app/S3/Auth/SignedRequest.php`, `app/S3/Auth/ParsedAuthorization.php`, `app/S3/Auth/SignatureV4Verifier.php`
- Test: `tests/Unit/S3/Auth/SignatureV4VerifierTest.php`

**Interfaces:**
- Produces:
  - `Credential::parse(string $scope): self` with readonly `accessKeyId`, `date`, `region`, `service`, `terminator`; `scope(): string`.
  - `SignedRequest` readonly with `method`, `path`, `query`, `headers`, `payloadHash`.
  - `ParsedAuthorization` readonly with `credential`, `signedHeaders`, `signature`, `timestamp`, `presigned`, `expiresIn`.
  - `SignatureV4Verifier::signingKey(string $secret, string $date, string $region, string $service): string`, `stringToSign(...): string`, `sign(SignedRequest, ParsedAuthorization, string $secret): string`, `verify(SignedRequest, ParsedAuthorization, string $secret, DateTimeImmutable $now): void`.
- Consumed by Tasks 11, 15.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Auth/SignatureV4VerifierTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Auth\Credential;
use App\S3\Auth\ParsedAuthorization;
use App\S3\Auth\SignatureV4Verifier;
use App\S3\Auth\SignedRequest;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\RequestTimeTooSkewedException;
use App\S3\Exceptions\SignatureDoesNotMatchException;

function verifier(): SignatureV4Verifier
{
    return new SignatureV4Verifier(clockSkewSeconds: 900, maxPresignedExpiry: 604800);
}

function authorization(string $signature, array $overrides = []): ParsedAuthorization
{
    return new ParsedAuthorization(
        credential: Credential::parse($overrides['scope'] ?? 'AKIDEXAMPLE/20260804/us-east-1/s3/aws4_request'),
        signedHeaders: $overrides['signedHeaders'] ?? ['host', 'x-amz-date'],
        signature: $signature,
        timestamp: $overrides['timestamp'] ?? '20260804T120000Z',
        presigned: $overrides['presigned'] ?? false,
        expiresIn: $overrides['expiresIn'] ?? null,
    );
}

function request(): SignedRequest
{
    return new SignedRequest(
        method: 'GET',
        path: '/photos/cat.jpg',
        query: [],
        headers: ['host' => 's3.example.com', 'x-amz-date' => '20260804T120000Z'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );
}

it('parses a credential scope', function () {
    $credential = Credential::parse('AKIDEXAMPLE/20260804/eu-west-1/s3/aws4_request');

    expect($credential->accessKeyId)->toBe('AKIDEXAMPLE')
        ->and($credential->date)->toBe('20260804')
        ->and($credential->region)->toBe('eu-west-1')
        ->and($credential->service)->toBe('s3')
        ->and($credential->scope())->toBe('20260804/eu-west-1/s3/aws4_request');
});

it('rejects a malformed credential scope', function () {
    Credential::parse('not-a-scope');
})->throws(AccessDeniedException::class);

it('derives the signing key exactly as AWS documents', function () {
    // AWS published derivation example.
    $key = verifier()->signingKey('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830', 'us-east-1', 'iam');

    expect(bin2hex($key))
        ->toBe('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9');
});

it('builds the string to sign in the documented format', function () {
    $stringToSign = verifier()->stringToSign(
        '20260804T120000Z',
        '20260804/us-east-1/s3/aws4_request',
        'CANONICAL',
    );

    expect($stringToSign)->toBe(implode("\n", [
        'AWS4-HMAC-SHA256',
        '20260804T120000Z',
        '20260804/us-east-1/s3/aws4_request',
        hash('sha256', 'CANONICAL'),
    ]));
});

it('accepts a correctly signed request', function () {
    $expected = verifier()->sign(request(), authorization('placeholder'), 'secret');

    verifier()->verify(
        request(),
        authorization($expected),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throwsNoExceptions();

it('rejects a signature computed with the wrong secret', function () {
    $wrong = verifier()->sign(request(), authorization('placeholder'), 'wrong-secret');

    verifier()->verify(
        request(),
        authorization($wrong),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(SignatureDoesNotMatchException::class);

it('rejects a tampered signature', function () {
    verifier()->verify(
        request(),
        authorization(str_repeat('0', 64)),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(SignatureDoesNotMatchException::class);

it('rejects a request whose path was altered after signing', function () {
    $signed = verifier()->sign(request(), authorization('placeholder'), 'secret');

    $tampered = new SignedRequest(
        method: 'GET',
        path: '/photos/other.jpg',
        query: [],
        headers: ['host' => 's3.example.com', 'x-amz-date' => '20260804T120000Z'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );

    verifier()->verify($tampered, authorization($signed), 'secret', new DateTimeImmutable('2026-08-04T12:00:00Z'));
})->throws(SignatureDoesNotMatchException::class);

it('rejects a timestamp beyond the skew tolerance', function () {
    $signed = verifier()->sign(request(), authorization('placeholder'), 'secret');

    verifier()->verify(
        request(),
        authorization($signed),
        'secret',
        new DateTimeImmutable('2026-08-04T13:00:00Z'), // one hour later
    );
})->throws(RequestTimeTooSkewedException::class);

it('accepts a timestamp inside the skew tolerance', function () {
    $signed = verifier()->sign(request(), authorization('placeholder'), 'secret');

    verifier()->verify(
        request(),
        authorization($signed),
        'secret',
        new DateTimeImmutable('2026-08-04T12:10:00Z'),
    );
})->throwsNoExceptions();

it('rejects an expired presigned request', function () {
    $auth = authorization('placeholder', ['presigned' => true, 'expiresIn' => 60]);
    $signed = verifier()->sign(request(), $auth, 'secret');

    verifier()->verify(
        request(),
        authorization($signed, ['presigned' => true, 'expiresIn' => 60]),
        'secret',
        new DateTimeImmutable('2026-08-04T12:02:00Z'), // 120s later, expiry was 60s
    );
})->throws(AccessDeniedException::class);

it('accepts a presigned request inside its expiry', function () {
    $auth = authorization('placeholder', ['presigned' => true, 'expiresIn' => 3600]);
    $signed = verifier()->sign(request(), $auth, 'secret');

    verifier()->verify(
        request(),
        authorization($signed, ['presigned' => true, 'expiresIn' => 3600]),
        'secret',
        new DateTimeImmutable('2026-08-04T12:30:00Z'),
    );
})->throwsNoExceptions();

it('rejects a presigned expiry beyond the configured maximum', function () {
    $auth = authorization('placeholder', ['presigned' => true, 'expiresIn' => 604801]);
    $signed = verifier()->sign(request(), $auth, 'secret');

    verifier()->verify(
        request(),
        authorization($signed, ['presigned' => true, 'expiresIn' => 604801]),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:30Z'),
    );
})->throws(AccessDeniedException::class);

it('skips the skew check for presigned requests, which carry their own expiry', function () {
    $auth = authorization('placeholder', ['presigned' => true, 'expiresIn' => 604800]);
    $signed = verifier()->sign(request(), $auth, 'secret');

    // Six days after signing: far beyond clock skew, but inside the 7-day expiry.
    verifier()->verify(
        request(),
        authorization($signed, ['presigned' => true, 'expiresIn' => 604800]),
        'secret',
        new DateTimeImmutable('2026-08-10T12:00:00Z'),
    );
})->throwsNoExceptions();
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=SignatureV4VerifierTest`
Expected: FAIL — classes not found.

- [x] **Step 3: Create the supporting value objects**

`app/S3/Auth/Credential.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;

final readonly class Credential
{
    public function __construct(
        public string $accessKeyId,
        public string $date,
        public string $region,
        public string $service,
        public string $terminator,
    ) {}

    /**
     * Parse "AKID/20260804/us-east-1/s3/aws4_request".
     *
     * @throws AccessDeniedException
     */
    public static function parse(string $scope): self
    {
        $parts = explode('/', $scope);

        if (count($parts) !== 5 || in_array('', $parts, strict: true)) {
            throw new AccessDeniedException('Malformed credential scope.');
        }

        [$accessKeyId, $date, $region, $service, $terminator] = $parts;

        if ($terminator !== 'aws4_request') {
            throw new AccessDeniedException('Malformed credential scope.');
        }

        return new self($accessKeyId, $date, $region, $service, $terminator);
    }

    /**
     * The scope without the access key id, as it appears in the string to sign.
     */
    public function scope(): string
    {
        return implode('/', [$this->date, $this->region, $this->service, $this->terminator]);
    }
}
```

`app/S3/Auth/SignedRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

/**
 * An immutable snapshot of the request exactly as the client signed it.
 *
 * Captured before any middleware rewrites the request — notably before
 * virtual-host addressing is folded into path style, which changes the path.
 */
final readonly class SignedRequest
{
    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers  lowercase names
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $payloadHash,
    ) {}
}
```

`app/S3/Auth/ParsedAuthorization.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

final readonly class ParsedAuthorization
{
    /**
     * @param  array<int, string>  $signedHeaders
     */
    public function __construct(
        public Credential $credential,
        public array $signedHeaders,
        public string $signature,
        public string $timestamp,
        public bool $presigned,
        public ?int $expiresIn,
    ) {}
}
```

- [x] **Step 4: Implement the verifier**

`app/S3/Auth/SignatureV4Verifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\RequestTimeTooSkewedException;
use App\S3\Exceptions\SignatureDoesNotMatchException;
use DateTimeImmutable;

final class SignatureV4Verifier
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly int $clockSkewSeconds,
        private readonly int $maxPresignedExpiry,
    ) {}

    /**
     * Verify a request's signature and freshness.
     *
     * @throws SignatureDoesNotMatchException|RequestTimeTooSkewedException|AccessDeniedException
     */
    public function verify(
        SignedRequest $request,
        ParsedAuthorization $authorization,
        string $secretKey,
        DateTimeImmutable $now,
    ): void {
        $this->assertFresh($authorization, $now);

        $expected = $this->sign($request, $authorization, $secretKey);

        // Constant-time: a timing-variable comparison leaks the signature one
        // byte at a time.
        if (! hash_equals($expected, $authorization->signature)) {
            throw new SignatureDoesNotMatchException();
        }
    }

    public function sign(SignedRequest $request, ParsedAuthorization $authorization, string $secretKey): string
    {
        $canonical = CanonicalRequest::build(
            method: $request->method,
            path: $request->path,
            query: $request->query,
            headers: $request->headers,
            signedHeaders: $authorization->signedHeaders,
            payloadHash: $request->payloadHash,
        );

        $stringToSign = $this->stringToSign(
            $authorization->timestamp,
            $authorization->credential->scope(),
            $canonical,
        );

        $signingKey = $this->signingKey(
            $secretKey,
            $authorization->credential->date,
            $authorization->credential->region,
            $authorization->credential->service,
        );

        return hash_hmac('sha256', $stringToSign, $signingKey);
    }

    public function stringToSign(string $timestamp, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    /**
     * Derive the signing key by chained HMAC, returning raw bytes.
     */
    public function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secret, binary: true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, binary: true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, binary: true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, binary: true);
    }

    /**
     * @throws RequestTimeTooSkewedException|AccessDeniedException
     */
    private function assertFresh(ParsedAuthorization $authorization, DateTimeImmutable $now): void
    {
        $signedAt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $authorization->timestamp, new \DateTimeZone('UTC'));

        if ($signedAt === false) {
            throw new AccessDeniedException('Malformed request timestamp.');
        }

        if ($authorization->presigned) {
            $expiresIn = $authorization->expiresIn ?? 0;

            if ($expiresIn < 1 || $expiresIn > $this->maxPresignedExpiry) {
                throw new AccessDeniedException('Invalid presigned URL expiry.');
            }

            // A presigned URL carries its own validity window, so clock skew is
            // not additionally applied — the expiry is the contract.
            if ($now->getTimestamp() > $signedAt->getTimestamp() + $expiresIn) {
                throw new AccessDeniedException('Request has expired.');
            }

            return;
        }

        if (abs($now->getTimestamp() - $signedAt->getTimestamp()) > $this->clockSkewSeconds) {
            throw new RequestTimeTooSkewedException();
        }
    }
}
```

- [x] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=SignatureV4VerifierTest`
Expected: PASS, 15 tests. The AWS signing-key vector must pass — it is the one assertion that proves the derivation matches real AWS.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3/Auth tests/Unit/S3/Auth
git commit -m "feat: add SigV4 signature verification"
```

---

## Task 11: Authorization parsing from header and presigned query

**Files:**
- Create: `app/S3/Auth/AuthorizationParser.php`
- Test: `tests/Unit/S3/Auth/AuthorizationParserTest.php`

**Interfaces:**
- Produces: `AuthorizationParser::parse(Request $request): ?ParsedAuthorization` (null when the request carries no credentials at all, which is a legitimate anonymous request), `AuthorizationParser::snapshot(Request $request): SignedRequest`. Consumed by Task 15.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Auth/AuthorizationParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Auth\AuthorizationParser;
use App\S3\Exceptions\AccessDeniedException;
use Illuminate\Http\Request;

it('parses a header authorization', function () {
    $request = Request::create('https://s3.example.com/bucket/key', 'GET');
    $request->headers->set('X-Amz-Date', '20260804T120000Z');
    $request->headers->set('Authorization',
        'AWS4-HMAC-SHA256 Credential=AKID/20260804/us-east-1/s3/aws4_request, '
        .'SignedHeaders=host;x-amz-date, Signature=abc123');

    $parsed = AuthorizationParser::parse($request);

    expect($parsed->credential->accessKeyId)->toBe('AKID')
        ->and($parsed->signedHeaders)->toBe(['host', 'x-amz-date'])
        ->and($parsed->signature)->toBe('abc123')
        ->and($parsed->timestamp)->toBe('20260804T120000Z')
        ->and($parsed->presigned)->toBeFalse();
});

it('parses a presigned query authorization', function () {
    $request = Request::create('https://s3.example.com/bucket/key', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => 'AKID/20260804/us-east-1/s3/aws4_request',
        'X-Amz-Date' => '20260804T120000Z',
        'X-Amz-Expires' => '3600',
        'X-Amz-SignedHeaders' => 'host',
        'X-Amz-Signature' => 'deadbeef',
    ]);

    $parsed = AuthorizationParser::parse($request);

    expect($parsed->presigned)->toBeTrue()
        ->and($parsed->expiresIn)->toBe(3600)
        ->and($parsed->signature)->toBe('deadbeef');
});

it('returns null for an anonymous request', function () {
    expect(AuthorizationParser::parse(Request::create('https://s3.example.com/bucket/key')))
        ->toBeNull();
});

it('rejects an unsupported algorithm', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('Authorization', 'AWS1-HMAC-SHA1 Credential=x, SignedHeaders=host, Signature=y');

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('rejects a header missing a required component', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('Authorization', 'AWS4-HMAC-SHA256 Credential=AKID/20260804/us-east-1/s3/aws4_request');

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('excludes the signature parameter from the signed query snapshot', function () {
    // X-Amz-Signature cannot be part of what it signs.
    $request = Request::create('https://s3.example.com/bucket/key', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Signature' => 'deadbeef',
        'prefix' => 'photos/',
    ]);

    $snapshot = AuthorizationParser::snapshot($request);

    expect($snapshot->query)->toHaveKey('prefix')
        ->and($snapshot->query)->toHaveKey('X-Amz-Algorithm')
        ->and($snapshot->query)->not->toHaveKey('X-Amz-Signature');
});

it('snapshots the path exactly as requested, without decoding it twice', function () {
    $request = Request::create('https://s3.example.com/bucket/my%20file.txt', 'GET');

    $snapshot = AuthorizationParser::snapshot($request);

    expect($snapshot->path)->toBe('/bucket/my file.txt');
});

it('uses the payload hash header when present', function () {
    $request = Request::create('https://s3.example.com/b/k', 'PUT');
    $request->headers->set('X-Amz-Content-Sha256', 'UNSIGNED-PAYLOAD');

    expect(AuthorizationParser::snapshot($request)->payloadHash)->toBe('UNSIGNED-PAYLOAD');
});

it('defaults a presigned request payload hash to UNSIGNED-PAYLOAD', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Signature' => 'x',
    ]);

    expect(AuthorizationParser::snapshot($request)->payloadHash)->toBe('UNSIGNED-PAYLOAD');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=AuthorizationParserTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement the parser**

Create `app/S3/Auth/AuthorizationParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;
use Illuminate\Http\Request;

final class AuthorizationParser
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Extract credentials from either the Authorization header or the
     * presigned query parameters. Returns null when neither is present, which
     * is a legitimate anonymous request against a public bucket.
     *
     * @throws AccessDeniedException
     */
    public static function parse(Request $request): ?ParsedAuthorization
    {
        $header = $request->headers->get('Authorization');

        if (is_string($header) && $header !== '') {
            return self::fromHeader($header, $request);
        }

        if ($request->query->has('X-Amz-Signature')) {
            return self::fromQuery($request);
        }

        return null;
    }

    /**
     * @throws AccessDeniedException
     */
    private static function fromHeader(string $header, Request $request): ParsedAuthorization
    {
        if (! str_starts_with($header, self::ALGORITHM.' ')) {
            throw new AccessDeniedException('Unsupported signature algorithm.');
        }

        $components = [];

        foreach (explode(',', substr($header, strlen(self::ALGORITHM) + 1)) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) === 2) {
                $components[$pair[0]] = $pair[1];
            }
        }

        foreach (['Credential', 'SignedHeaders', 'Signature'] as $required) {
            if (! isset($components[$required]) || $components[$required] === '') {
                throw new AccessDeniedException('Malformed Authorization header.');
            }
        }

        $timestamp = $request->headers->get('X-Amz-Date') ?? $request->headers->get('Date');

        if (! is_string($timestamp) || $timestamp === '') {
            throw new AccessDeniedException('Missing request timestamp.');
        }

        return new ParsedAuthorization(
            credential: Credential::parse($components['Credential']),
            signedHeaders: explode(';', strtolower($components['SignedHeaders'])),
            signature: $components['Signature'],
            timestamp: $timestamp,
            presigned: false,
            expiresIn: null,
        );
    }

    /**
     * @throws AccessDeniedException
     */
    private static function fromQuery(Request $request): ParsedAuthorization
    {
        if ($request->query->get('X-Amz-Algorithm') !== self::ALGORITHM) {
            throw new AccessDeniedException('Unsupported signature algorithm.');
        }

        foreach (['X-Amz-Credential', 'X-Amz-Date', 'X-Amz-SignedHeaders', 'X-Amz-Expires'] as $required) {
            if (! $request->query->has($required)) {
                throw new AccessDeniedException('Malformed presigned request.');
            }
        }

        return new ParsedAuthorization(
            credential: Credential::parse((string) $request->query->get('X-Amz-Credential')),
            signedHeaders: explode(';', strtolower((string) $request->query->get('X-Amz-SignedHeaders'))),
            signature: (string) $request->query->get('X-Amz-Signature'),
            timestamp: (string) $request->query->get('X-Amz-Date'),
            presigned: true,
            expiresIn: (int) $request->query->get('X-Amz-Expires'),
        );
    }

    /**
     * Capture the request exactly as the client signed it.
     *
     * MUST be called before any middleware rewrites the request. Virtual-host
     * addressing folds "bucket.s3.example.com/key" into "/bucket/key", but the
     * client signed "/key" — so the snapshot has to be taken first.
     */
    public static function snapshot(Request $request): SignedRequest
    {
        $query = $request->query->all();

        // The signature cannot be part of what it signs.
        unset($query['X-Amz-Signature']);

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        $payloadHash = $request->headers->get('X-Amz-Content-Sha256');

        if (! is_string($payloadHash) || $payloadHash === '') {
            // Presigned requests do not sign the body.
            $payloadHash = 'UNSIGNED-PAYLOAD';
        }

        return new SignedRequest(
            method: $request->getMethod(),
            path: rawurldecode($request->getPathInfo()),
            query: array_map(static fn (mixed $value): string => (string) $value, $query),
            headers: $headers,
            payloadHash: $payloadHash,
        );
    }
}
```

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=AuthorizationParserTest`
Expected: PASS, 9 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3/Auth tests/Unit/S3/Auth
git commit -m "feat: parse SigV4 header and presigned query authorization"
```

---

## Task 12: aws-chunked payload decoding

The piece most "S3-compatible" servers skip, and the reason `aws s3 cp` fails against them. The AWS CLI frames upload bodies as length-prefixed chunks, each carrying its own chained signature.

**Files:**
- Create: `app/S3/Http/ChunkedPayloadDecoder.php`
- Test: `tests/Unit/S3/Http/ChunkedPayloadDecoderTest.php`

**Interfaces:**
- Produces: `ChunkedPayloadDecoder::isChunked(string $payloadHash): bool`, `ChunkedPayloadDecoder::decodeTo(mixed $input, mixed $output, ?callable $onChunk = null): int` returning the decoded byte count. Consumed by Task 20.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/S3/Http/ChunkedPayloadDecoderTest.php`:

```php
<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidRequestException;
use App\S3\Http\ChunkedPayloadDecoder;

/**
 * Build an aws-chunked body. Each chunk is:
 *   <hex-length>;chunk-signature=<64 hex chars>\r\n<data>\r\n
 * terminated by a zero-length chunk.
 */
function chunkedBody(array $chunks): string
{
    $body = '';

    foreach ($chunks as $chunk) {
        $body .= dechex(strlen($chunk)).';chunk-signature='.str_repeat('a', 64)."\r\n".$chunk."\r\n";
    }

    return $body.'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";
}

function stream(string $contents)
{
    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, $contents);
    rewind($handle);

    return $handle;
}

it('recognises the streaming payload hash', function () {
    expect(ChunkedPayloadDecoder::isChunked('STREAMING-AWS4-HMAC-SHA256-PAYLOAD'))->toBeTrue()
        ->and(ChunkedPayloadDecoder::isChunked('UNSIGNED-PAYLOAD'))->toBeFalse();
});

it('decodes a single chunk', function () {
    $output = fopen('php://temp', 'r+b');

    $size = ChunkedPayloadDecoder::decodeTo(stream(chunkedBody(['hello world'])), $output);
    rewind($output);

    expect($size)->toBe(11)
        ->and(stream_get_contents($output))->toBe('hello world');
});

it('reassembles multiple chunks in order', function () {
    $output = fopen('php://temp', 'r+b');

    $size = ChunkedPayloadDecoder::decodeTo(stream(chunkedBody(['first-', 'second-', 'third'])), $output);
    rewind($output);

    expect($size)->toBe(18)
        ->and(stream_get_contents($output))->toBe('first-second-third');
});

it('decodes a chunk larger than the read buffer', function () {
    $payload = str_repeat('x', 1024 * 1024);
    $output = fopen('php://temp', 'r+b');

    $size = ChunkedPayloadDecoder::decodeTo(stream(chunkedBody([$payload])), $output);
    rewind($output);

    expect($size)->toBe(1024 * 1024)
        ->and(stream_get_contents($output))->toBe($payload);
});

it('handles an empty body consisting only of the terminating chunk', function () {
    $output = fopen('php://temp', 'r+b');

    expect(ChunkedPayloadDecoder::decodeTo(stream(chunkedBody([])), $output))->toBe(0);
});

it('invokes the callback once per data chunk', function () {
    $seen = [];
    $output = fopen('php://temp', 'r+b');

    ChunkedPayloadDecoder::decodeTo(
        stream(chunkedBody(['aa', 'bbb'])),
        $output,
        function (string $chunk) use (&$seen) {
            $seen[] = strlen($chunk);
        },
    );

    expect($seen)->toBe([2, 3]);
});

it('rejects a body whose chunk header is not valid hex', function () {
    $output = fopen('php://temp', 'r+b');

    ChunkedPayloadDecoder::decodeTo(stream("zz;chunk-signature=x\r\ndata\r\n"), $output);
})->throws(InvalidRequestException::class);

it('rejects a truncated body that never terminates', function () {
    $output = fopen('php://temp', 'r+b');

    // Declares 100 bytes, supplies 4, then ends.
    ChunkedPayloadDecoder::decodeTo(stream("64;chunk-signature=".str_repeat('a', 64)."\r\ndata"), $output);
})->throws(InvalidRequestException::class);

it('keeps memory flat across a large chunked body', function () {
    $output = fopen('php://temp', 'r+b');
    $body = chunkedBody([str_repeat('y', 32 * 1024 * 1024)]);

    $before = memory_get_usage(true);
    ChunkedPayloadDecoder::decodeTo(stream($body), $output);
    $growth = memory_get_usage(true) - $before;

    expect($growth)->toBeLessThan(16 * 1024 * 1024);
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ChunkedPayloadDecoderTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement the decoder**

Create `app/S3/Http/ChunkedPayloadDecoder.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\InvalidRequestException;

/**
 * Decodes the aws-chunked framing the AWS CLI applies to upload bodies.
 *
 * The wire format is a sequence of:
 *
 *     <hex-length>;chunk-signature=<64 hex chars>\r\n
 *     <length bytes of data>\r\n
 *
 * terminated by a zero-length chunk. Content-Length covers the *framing*, not
 * the object, so the decoded size must be measured rather than trusted.
 *
 * Without this, `aws s3 cp` stores the framing bytes as the object body.
 */
final class ChunkedPayloadDecoder
{
    public const string PAYLOAD_HASH = 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD';

    private const int READ_SIZE = 1024 * 1024;

    /** Guards against a malformed header consuming unbounded memory. */
    private const int MAX_HEADER_LENGTH = 256;

    public static function isChunked(string $payloadHash): bool
    {
        return str_starts_with($payloadHash, 'STREAMING-');
    }

    /**
     * Stream-decode $input into $output, returning the decoded byte count.
     *
     * @param  resource  $input
     * @param  resource  $output
     * @param  (callable(string): void)|null  $onChunk  receives each decoded chunk
     *
     * @throws InvalidRequestException
     */
    public static function decodeTo(mixed $input, mixed $output, ?callable $onChunk = null): int
    {
        $decoded = 0;

        while (true) {
            $header = self::readHeaderLine($input);

            if ($header === null) {
                throw new InvalidRequestException('Truncated chunked payload.');
            }

            $length = self::parseChunkLength($header);

            if ($length === 0) {
                return $decoded;
            }

            $remaining = $length;

            while ($remaining > 0) {
                $chunk = fread($input, min(self::READ_SIZE, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new InvalidRequestException('Truncated chunked payload.');
                }

                fwrite($output, $chunk);

                if ($onChunk !== null) {
                    $onChunk($chunk);
                }

                $remaining -= strlen($chunk);
                $decoded += strlen($chunk);
            }

            // Consume the CRLF that terminates the chunk data.
            fread($input, 2);
        }
    }

    /**
     * @param  resource  $input
     */
    private static function readHeaderLine(mixed $input): ?string
    {
        $line = '';

        while (strlen($line) < self::MAX_HEADER_LENGTH) {
            $byte = fread($input, 1);

            if ($byte === false || $byte === '') {
                return $line === '' ? null : $line;
            }

            if ($byte === "\n") {
                return rtrim($line, "\r");
            }

            $line .= $byte;
        }

        throw new InvalidRequestException('Chunk header exceeds the permitted length.');
    }

    /**
     * @throws InvalidRequestException
     */
    private static function parseChunkLength(string $header): int
    {
        $hex = strtok($header, ';');

        if ($hex === false || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new InvalidRequestException('Malformed chunk header.');
        }

        return (int) hexdec($hex);
    }
}
```

> **Deliberate limitation, to be recorded in `docs/s3-compatibility.md`:** the
> per-chunk `chunk-signature` values are parsed but not individually verified.
> The request's own SigV4 signature is fully verified, so an unauthenticated
> party cannot submit a body. Verifying the chained per-chunk signatures would
> additionally detect a body tampered with in flight by a party who already holds
> a valid seed signature. Implement chained verification in a later release;
> until then this is stated as a known gap rather than left unmentioned.

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ChunkedPayloadDecoderTest`
Expected: PASS, 9 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3/Http tests/Unit/S3/Http
git commit -m "feat: decode aws-chunked upload payloads"
```

---

## Task 13: The s3 route group and its exception handling

Registers `routes/s3.php` as a middleware group sharing nothing with `web`, and makes every failure on those routes render as S3 XML — including framework-level 404s and 500s.

**Files:**
- Create: `routes/s3.php`
- Create: `app/Http/Middleware/S3/AssignRequestId.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/S3/RouteGroupTest.php`

**Interfaces:**
- Produces: middleware group `s3`; every request carries `$request->attributes->get('s3.request_id')`. Consumed by Tasks 14 onward.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/RouteGroupTest.php`:

```php
<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
});

it('renders an unknown route as an S3 XML error, never as HTML', function () {
    $response = $this->get('http://s3.dero.test/no-such-bucket-here/key');

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->getContent())->toContain('<Code>');
});

it('applies no session or CSRF middleware to s3 routes', function () {
    // A POST with no CSRF token must not produce a 419.
    $response = $this->post('http://s3.dero.test/some-bucket');

    expect($response->getStatusCode())->not->toBe(419);
});

it('assigns a request id and echoes it back', function () {
    $response = $this->get('http://s3.dero.test/');

    expect($response->headers->get('x-amz-request-id'))->not->toBeEmpty();
});

it('does not serve s3 routes on the dashboard host', function () {
    // The dashboard host must keep its own routing; the wildcard
    // /{bucket}/{key} must not shadow it.
    $this->get('http://localhost/login')->assertOk();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=RouteGroupTest`
Expected: FAIL — no s3 routes registered.

- [x] **Step 3: Create the request-id middleware**

`app/Http/Middleware/S3/AssignRequestId.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();

        $request->attributes->set('s3.request_id', $requestId);

        $response = $next($request);

        $response->headers->set('x-amz-request-id', $requestId);
        $response->headers->set('Server', 'Dero');

        return $response;
    }
}
```

- [x] **Step 4: Create the route file**

`routes/s3.php` — the operations are filled in by later tasks. For now, register the fallback so unknown routes still produce S3 XML:

```php
<?php

declare(strict_types=1);

use App\S3\Exceptions\MethodNotAllowedException;
use Illuminate\Support\Facades\Route;

/*
 | S3 data plane. Served only from the configured endpoint host so the
 | catch-all /{bucket}/{key} route cannot shadow dashboard routing.
 |
 | Operations are appended by later tasks. Ordering matters: the service-level
 | and bucket-level routes must be declared before the object catch-all.
 */

Route::fallback(function () {
    throw new MethodNotAllowedException();
});
```

- [x] **Step 5: Register the group in `bootstrap/app.php`**

Inside `withRouting`, add a `then` callback that registers the S3 routes on their own domain and group:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function (): void {
        $host = config('dero.endpoint_host');

        // Fail closed: with no endpoint host configured, the S3 API is not
        // exposed at all rather than mounted at the application root.
        if (! is_string($host) || $host === '') {
            return;
        }

        Route::domain($host)
            ->middleware('s3')
            ->group(base_path('routes/s3.php'));
    },
)
```

Inside `withMiddleware`, define the group. It deliberately contains no session, CSRF, cookie, or Inertia middleware:

```php
$middleware->group('s3', [
    AssignRequestId::class,
]);
```

Inside `withExceptions`, render `S3Exception` and framework exceptions as S3 XML for these routes:

```php
$exceptions->render(function (Throwable $e, Request $request) {
    if (! $request->attributes->has('s3.request_id')) {
        return null;
    }

    $requestId = (string) $request->attributes->get('s3.request_id');

    if ($e instanceof S3Exception) {
        return S3ErrorResponse::fromException($e, $requestId);
    }

    if ($e instanceof NotFoundHttpException) {
        return S3ErrorResponse::fromException(new MethodNotAllowedException(), $requestId);
    }

    if ($e instanceof MethodNotAllowedHttpException) {
        return S3ErrorResponse::fromException(new MethodNotAllowedException(), $requestId);
    }

    // Anything else is an unexpected fault. Report InternalError rather than
    // leaking a stack trace or an HTML debug page to an S3 client.
    report($e);

    return S3ErrorResponse::fromException(new InternalErrorException(), $requestId);
});
```

Add the imports: `App\Http\Middleware\S3\AssignRequestId`, `App\S3\Exceptions\{InternalErrorException, MethodNotAllowedException, S3Exception}`, `App\S3\Http\S3ErrorResponse`, `Illuminate\Support\Facades\Route`, `Throwable`, and the two Symfony HTTP exception classes.

> The `s3.request_id` attribute is what distinguishes an S3 request from a
> dashboard request inside the exception handler. Checking the host would break
> in tests; checking the attribute is exact.

- [x] **Step 5b: Configure the test host**

Add to `phpunit.xml`, inside `<php>`:

```xml
<env name="S3_ENDPOINT_HOST" value="s3.dero.test"/>
```

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=RouteGroupTest`
Expected: PASS, 4 tests.

- [x] **Step 7: Run the whole suite**

Run: `php artisan test --compact`
Expected: PASS. Confirms the new route group has not disturbed dashboard routing.

- [x] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/s3.php app/Http/Middleware/S3 bootstrap/app.php phpunit.xml tests/Feature/S3/RouteGroupTest.php
git commit -m "feat: register isolated s3 route group with XML error rendering"
```

---

## Task 14: Virtual-host addressing

Clients disagree about addressing style. AWS CLI v2 prefers `bucket.s3.example.com`; Laravel's Flysystem sends `s3.example.com/bucket`. Supporting one guarantees a broken client.

**Files:**
- Create: `app/Http/Middleware/S3/ResolveVirtualHostBucket.php`
- Modify: `bootstrap/app.php` (add to the `s3` group)
- Test: `tests/Feature/S3/VirtualHostTest.php`

**Interfaces:**
- Produces: `$request->attributes->get('s3.signed_request')` — the `SignedRequest` snapshot taken *before* rewriting — and a rewritten path-style request for routing. Consumed by Task 15.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/VirtualHostTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\S3\ResolveVirtualHostBucket;
use App\S3\Auth\SignedRequest;
use Illuminate\Http\Request;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
});

function passThrough(Request $request): Request
{
    $captured = null;

    (new ResolveVirtualHostBucket())->handle($request, function (Request $r) use (&$captured) {
        $captured = $r;

        return response('');
    });

    return $captured;
}

it('rewrites a virtual-host request into path style', function () {
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos/cat.jpg');
});

it('rewrites a virtual-host bucket root into a path-style bucket root', function () {
    $request = Request::create('http://photos.s3.dero.test/', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos');
});

it('leaves a path-style request untouched', function () {
    $request = Request::create('http://s3.dero.test/photos/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos/cat.jpg');
});

it('snapshots the request as signed, before the rewrite', function () {
    // The client signed the virtual-host URI, so the canonical path is
    // "/cat.jpg". Snapshotting after the rewrite would compute "/photos/cat.jpg"
    // and every virtual-host signature would fail.
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    $snapshot = passThrough($request)->attributes->get('s3.signed_request');

    expect($snapshot)->toBeInstanceOf(SignedRequest::class)
        ->and($snapshot->path)->toBe('/cat.jpg')
        ->and($snapshot->headers['host'])->toBe('photos.s3.dero.test');
});

it('ignores a host that merely ends with the endpoint host', function () {
    // "evils3.dero.test" must not be read as bucket "evil".
    $request = Request::create('http://evils3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/cat.jpg');
});

it('ignores a subdomain that is not a valid bucket name', function () {
    $request = Request::create('http://Not_Valid.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/cat.jpg');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=VirtualHostTest`
Expected: FAIL — class not found.

- [x] **Step 3: Implement the middleware**

`app/Http/Middleware/S3/ResolveVirtualHostBucket.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\S3\Auth\AuthorizationParser;
use App\S3\Values\BucketName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Folds virtual-host addressing (bucket.s3.example.com) into path style so a
 * single set of routes serves both, and captures the request exactly as the
 * client signed it before doing so.
 */
final class ResolveVirtualHostBucket
{
    public function handle(Request $request, Closure $next): Response
    {
        // Taken first: signature verification must see the request as sent.
        $request->attributes->set('s3.signed_request', AuthorizationParser::snapshot($request));

        $bucket = $this->bucketFromHost($request->getHost());

        if ($bucket !== null) {
            $request->attributes->set('s3.virtual_host_bucket', $bucket);
            $request->server->set('REQUEST_URI', $this->rewrittenUri($request, $bucket));
            $request->initialize(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent(false),
            );
        }

        return $next($request);
    }

    private function bucketFromHost(string $host): ?string
    {
        $endpoint = (string) config('dero.endpoint_host');

        if ($endpoint === '' || $host === $endpoint) {
            return null;
        }

        $suffix = '.'.$endpoint;

        // Require the dot: "evils3.dero.test" must not parse as bucket "evil".
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $candidate = substr($host, 0, -strlen($suffix));

        return BucketName::isValid($candidate) ? $candidate : null;
    }

    private function rewrittenUri(Request $request, string $bucket): string
    {
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        $rewritten = '/'.$bucket.($path === '/' ? '' : $path);

        return $query === null ? $rewritten : $rewritten.'?'.$query;
    }
}
```

> `$request->getContent(false)` retrieves the already-read body without
> consuming the input stream a second time. Re-initialising with `php://input`
> would leave an empty body for upload requests.

- [x] **Step 4: Add it to the group**

In `bootstrap/app.php`, the `s3` group becomes:

```php
$middleware->group('s3', [
    AssignRequestId::class,
    ResolveVirtualHostBucket::class,
]);
```

- [x] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=VirtualHostTest`
Expected: PASS, 6 tests.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/S3 bootstrap/app.php tests/Feature/S3/VirtualHostTest.php
git commit -m "feat: support virtual-host and path-style S3 addressing"
```

---

## Task 15: Authentication middleware

**Files:**
- Create: `app/Http/Middleware/S3/ResolveAccessKey.php`, `app/Http/Middleware/S3/VerifyAwsSignature.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind the verifier)
- Test: `tests/Feature/S3/AuthenticationTest.php`

**Interfaces:**
- Produces: `$request->attributes->get('s3.access_key')` — an `AccessKey` or null for anonymous. Consumed by Tasks 16 onward.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/AuthenticationTest.php`. It needs the signing helper, so write that first as `tests/Helpers/SignsRequests.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\AccessKey;
use Illuminate\Support\Carbon;

final class SignsRequests
{
    /**
     * Build the headers a correctly signed S3 request carries.
     *
     * Mirrors what a real client does, so these tests exercise the same code
     * path the AWS CLI will.
     *
     * @param  array<string, string>  $query
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    public static function headers(
        AccessKey $key,
        string $method,
        string $host,
        string $path,
        array $query = [],
        array $extraHeaders = [],
        string $payloadHash = 'UNSIGNED-PAYLOAD',
        ?Carbon $at = null,
    ): array {
        $at ??= Carbon::now();
        $timestamp = $at->utc()->format('Ymd\THis\Z');
        $date = $at->utc()->format('Ymd');
        $region = (string) config('dero.region');
        $scope = "{$date}/{$region}/s3/aws4_request";

        $headers = array_change_key_case([
            'host' => $host,
            'x-amz-date' => $timestamp,
            'x-amz-content-sha256' => $payloadHash,
            ...$extraHeaders,
        ]);

        $signedHeaders = array_keys($headers);
        sort($signedHeaders, SORT_STRING);

        $canonical = \App\S3\Auth\CanonicalRequest::build(
            $method, $path, $query, $headers, $signedHeaders, $payloadHash,
        );

        $verifier = app(\App\S3\Auth\SignatureV4Verifier::class);

        $signature = hash_hmac(
            'sha256',
            $verifier->stringToSign($timestamp, $scope, $canonical),
            $verifier->signingKey($key->secret_key, $date, $region, 's3'),
        );

        return [
            ...array_combine(
                array_map(fn (string $n) => ucwords($n, '-'), array_keys($headers)),
                array_values($headers),
            ),
            'Authorization' => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $key->access_key_id, $scope, implode(';', $signedHeaders), $signature,
            ),
        ];
    }
}
```

Then `tests/Feature/S3/AuthenticationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use Illuminate\Support\Carbon;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);
});

it('accepts a correctly signed request', function () {
    $this->withHeaders(SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/'))
        ->get('http://s3.dero.test/')
        ->assertOk();
});

it('rejects an unknown access key id', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = str_replace($this->key->access_key_id, 'DERONOTAREALKEY00000', $headers['Authorization']);

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>InvalidAccessKeyId</Code>');
});

it('rejects a revoked access key', function () {
    $this->key->update(['revoked_at' => now()]);

    $response = $this->withHeaders(SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/'))
        ->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>InvalidAccessKeyId</Code>');
});

it('rejects an expired access key', function () {
    $this->key->update(['expires_at' => now()->subMinute()]);

    $this->withHeaders(SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/'))
        ->get('http://s3.dero.test/')
        ->assertStatus(403);
});

it('rejects a tampered signature', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = preg_replace('/Signature=.+$/', 'Signature='.str_repeat('0', 64), $headers['Authorization']);

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>SignatureDoesNotMatch</Code>');
});

it('rejects a request signed with the wrong secret', function () {
    $impostor = AccessKey::factory()->create(['access_key_id' => $this->key->access_key_id.'X']);
    $headers = SignsRequests::headers($impostor, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = str_replace($impostor->access_key_id, $this->key->access_key_id, $headers['Authorization']);

    $this->withHeaders($headers)->get('http://s3.dero.test/')->assertStatus(403);
});

it('rejects a stale timestamp', function () {
    $headers = SignsRequests::headers(
        $this->key, 'GET', 's3.dero.test', '/', at: Carbon::now()->subHours(2),
    );

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>RequestTimeTooSkewed</Code>');
});

it('records when a key was last used', function () {
    expect($this->key->last_used_at)->toBeNull();

    $this->withHeaders(SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/'))
        ->get('http://s3.dero.test/');

    expect($this->key->fresh()->last_used_at)->not->toBeNull();
});

it('never writes a secret to the log', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = preg_replace('/Signature=.+$/', 'Signature=bad', $headers['Authorization']);

    $this->withHeaders($headers)->get('http://s3.dero.test/');

    $log = storage_path('logs/laravel.log');

    if (file_exists($log)) {
        expect(file_get_contents($log))->not->toContain($this->key->secret_key);
    }
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=AuthenticationTest`
Expected: FAIL — no authentication middleware, so signed requests are not accepted.

- [x] **Step 3: Bind the verifier**

In `AppServiceProvider::register()`:

```php
$this->app->singleton(SignatureV4Verifier::class, fn () => new SignatureV4Verifier(
    clockSkewSeconds: (int) config('dero.clock_skew_seconds'),
    maxPresignedExpiry: (int) config('dero.presigned_max_expiry'),
));
```

- [x] **Step 4: Implement ResolveAccessKey**

`app/Http/Middleware/S3/ResolveAccessKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\AccessKey;
use App\S3\Auth\AuthorizationParser;
use App\S3\Exceptions\InvalidAccessKeyIdException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveAccessKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = AuthorizationParser::parse($request);

        // No credentials at all is a legitimate anonymous request; whether it
        // is permitted is decided by AuthorizeS3Operation.
        if ($authorization === null) {
            $request->attributes->set('s3.access_key', null);
            $request->attributes->set('s3.authorization', null);

            return $next($request);
        }

        $key = AccessKey::query()
            ->where('access_key_id', $authorization->credential->accessKeyId)
            ->first();

        // A revoked or expired key is reported as an unknown key id, so the
        // response does not distinguish "wrong key" from "disabled key".
        if ($key === null || ! $key->isActive()) {
            throw new InvalidAccessKeyIdException($authorization->credential->accessKeyId);
        }

        $request->attributes->set('s3.access_key', $key);
        $request->attributes->set('s3.authorization', $authorization);

        return $next($request);
    }
}
```

- [x] **Step 5: Implement VerifyAwsSignature**

`app/Http/Middleware/S3/VerifyAwsSignature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\AccessKey;
use App\S3\Auth\ParsedAuthorization;
use App\S3\Auth\SignatureV4Verifier;
use App\S3\Auth\SignedRequest;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyAwsSignature
{
    public function __construct(private readonly SignatureV4Verifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('s3.access_key');
        $authorization = $request->attributes->get('s3.authorization');

        if (! $key instanceof AccessKey || ! $authorization instanceof ParsedAuthorization) {
            return $next($request);
        }

        $signed = $request->attributes->get('s3.signed_request');

        if (! $signed instanceof SignedRequest) {
            throw new \LogicException('Signed request snapshot missing; ResolveVirtualHostBucket must run first.');
        }

        $this->verifier->verify(
            $signed,
            $authorization,
            $key->secret_key,
            new DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        // Touched after verification so a failed probe cannot be used to
        // confirm that a key id exists.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
```

- [x] **Step 6: Add both to the group**

```php
$middleware->group('s3', [
    AssignRequestId::class,
    ResolveVirtualHostBucket::class,
    ResolveAccessKey::class,
    VerifyAwsSignature::class,
]);
```

- [x] **Step 7: Add a temporary service route so the test has something to hit**

In `routes/s3.php`, above the fallback:

```php
Route::get('/', fn () => response('<?xml version="1.0" encoding="UTF-8"?><ListAllMyBucketsResult/>', 200, [
    'Content-Type' => 'application/xml',
]));
```

This is replaced properly in Task 17.

- [x] **Step 8: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=AuthenticationTest`
Expected: PASS, 9 tests.

- [x] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/S3 app/Providers/AppServiceProvider.php routes/s3.php tests
git commit -m "feat: authenticate S3 requests with SigV4"
```

---

## Task 16: Bucket resolution and authorization

**Files:**
- Create: `app/Http/Middleware/S3/ResolveBucket.php`, `app/Http/Middleware/S3/AuthorizeS3Operation.php`
- Create: `app/S3/Authorization/OperationPermission.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/S3/AuthorizationTest.php`

**Interfaces:**
- Produces: `$request->attributes->get('s3.bucket')` (a `Bucket` or null for service-level operations); `OperationPermission::forRequest(Request): AccessKeyPermission`. Consumed by every controller.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/AuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\Team;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);
});

function callBucket(AccessKey $key, string $bucket, string $method = 'GET')
{
    return test()
        ->withHeaders(SignsRequests::headers($key, $method, 's3.dero.test', '/'.$bucket))
        ->call($method, 'http://s3.dero.test/'.$bucket);
}

it('resolves an existing bucket', function () {
    callBucket($this->key, 'photos')->assertOk();
});

it('reports NoSuchBucket for a bucket that does not exist', function () {
    $response = callBucket($this->key, 'missing');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchBucket</Code>');
});

it('denies access to another team\'s bucket', function () {
    $other = Bucket::factory()->create(['team_id' => Team::factory(), 'name' => 'theirs']);

    $response = callBucket($this->key, 'theirs');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>AccessDenied</Code>');
});

it('denies a key that lacks the required permission', function () {
    $readOnly = AccessKey::factory()
        ->withPermissions([AccessKeyPermission::Read])
        ->create(['team_id' => $this->key->team_id]);

    // DELETE on a bucket requires the delete permission.
    callBucket($readOnly, 'photos', 'DELETE')->assertStatus(403);
});

it('denies a key scoped to a different bucket', function () {
    $scoped = AccessKey::factory()->create([
        'team_id' => $this->key->team_id,
        'bucket_scope' => ['other-bucket'],
    ]);

    callBucket($scoped, 'photos')->assertStatus(403);
});

it('denies an anonymous request against a private bucket', function () {
    $response = test()->get('http://s3.dero.test/photos');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>AccessDenied</Code>');
});

it('allows an anonymous read against a public-read bucket', function () {
    $this->bucket->update(['visibility' => \App\Enums\BucketVisibility::PublicRead]);

    test()->get('http://s3.dero.test/photos')->assertOk();
});

it('still denies an anonymous write against a public-read bucket', function () {
    $this->bucket->update(['visibility' => \App\Enums\BucketVisibility::PublicRead]);

    test()->delete('http://s3.dero.test/photos')->assertStatus(403);
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=AuthorizationTest`
Expected: FAIL — bucket routes do not exist.

- [x] **Step 3: Map operations to permissions**

`app/S3/Authorization/OperationPermission.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Authorization;

use App\Enums\AccessKeyPermission;
use Illuminate\Http\Request;

final class OperationPermission
{
    /**
     * The permission an inbound request requires.
     *
     * Derived from the HTTP method and the presence of the bucket/key segments,
     * which is enough to classify every operation Dero implements.
     */
    public static function forRequest(Request $request): AccessKeyPermission
    {
        $hasKey = $request->route('key') !== null;

        return match ($request->getMethod()) {
            'GET', 'HEAD' => $hasKey ? AccessKeyPermission::Read : AccessKeyPermission::List,
            'PUT' => AccessKeyPermission::Write,
            'DELETE' => AccessKeyPermission::Delete,
            // POST covers CreateMultipartUpload, CompleteMultipartUpload and
            // DeleteObjects. The first two write; DeleteObjects is checked
            // again per object in its own controller.
            'POST' => AccessKeyPermission::Write,
            default => AccessKeyPermission::Admin,
        };
    }
}
```

- [x] **Step 4: Implement ResolveBucket**

`app/Http/Middleware/S3/ResolveBucket.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\Bucket;
use App\S3\Exceptions\NoSuchBucketException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBucket
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route('bucket');

        if (! is_string($name) || $name === '') {
            $request->attributes->set('s3.bucket', null);

            return $next($request);
        }

        $bucket = Bucket::query()->where('name', $name)->first();

        // A PUT to a non-existent bucket is CreateBucket, which is the one
        // operation that legitimately precedes the bucket existing.
        if ($bucket === null) {
            if ($request->isMethod('PUT') && $request->route('key') === null) {
                $request->attributes->set('s3.bucket', null);

                return $next($request);
            }

            throw new NoSuchBucketException($name);
        }

        $request->attributes->set('s3.bucket', $bucket);

        return $next($request);
    }
}
```

- [x] **Step 5: Implement AuthorizeS3Operation**

`app/Http/Middleware/S3/AuthorizeS3Operation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\S3\Authorization\OperationPermission;
use App\S3\Exceptions\AccessDeniedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeS3Operation
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('s3.access_key');
        $bucket = $request->attributes->get('s3.bucket');
        $required = OperationPermission::forRequest($request);

        if ($key instanceof AccessKey) {
            $this->authorizeKey($key, $bucket, $required);

            return $next($request);
        }

        $this->authorizeAnonymous($bucket, $required);

        return $next($request);
    }

    private function authorizeKey(AccessKey $key, ?Bucket $bucket, AccessKeyPermission $required): void
    {
        // Tenant isolation. Enforced here, server-side, on every request —
        // never by filtering in a client.
        if ($bucket !== null && $bucket->team_id !== $key->team_id) {
            throw new AccessDeniedException();
        }

        if (! $key->allows($required, $bucket?->name)) {
            throw new AccessDeniedException();
        }
    }

    private function authorizeAnonymous(?Bucket $bucket, AccessKeyPermission $required): void
    {
        if ($bucket === null || ! $bucket->visibility->allowsAnonymousRead()) {
            throw new AccessDeniedException();
        }

        // Public-read grants exactly read and list. Never write or delete.
        if (! in_array($required, [AccessKeyPermission::Read, AccessKeyPermission::List], strict: true)) {
            throw new AccessDeniedException();
        }
    }
}
```

- [x] **Step 6: Complete the middleware group**

```php
$middleware->group('s3', [
    AssignRequestId::class,
    ResolveVirtualHostBucket::class,
    ResolveAccessKey::class,
    VerifyAwsSignature::class,
    ResolveBucket::class,
    AuthorizeS3Operation::class,
]);
```

> `ResolveBucket` reads `$request->route('bucket')`, so it must run after
> routing has resolved. Laravel's group middleware runs inside the route
> pipeline, so this holds — but if these are ever moved to global middleware,
> `route()` returns null and bucket resolution silently stops working.

- [x] **Step 7: Add a temporary bucket route**

In `routes/s3.php`, above the fallback:

```php
Route::match(['GET', 'HEAD', 'PUT', 'DELETE'], '/{bucket}', fn () => response('', 200));
```

Replaced properly in Task 18.

- [x] **Step 8: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=AuthorizationTest`
Expected: PASS, 8 tests.

- [x] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/S3 app/S3/Authorization routes/s3.php bootstrap/app.php tests/Feature/S3/AuthorizationTest.php
git commit -m "feat: resolve buckets and authorize S3 operations with tenant isolation"
```

---

## Task 17: Service and bucket operations

`ListBuckets`, `CreateBucket`, `DeleteBucket`, `HeadBucket`, `GetBucketLocation`.

**Files:**
- Create: `app/Http/Controllers/S3/ServiceController.php`, `app/Http/Controllers/S3/BucketController.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/BucketOperationsTest.php`

**Interfaces:**
- Produces: routes `GET /`, `PUT /{bucket}`, `DELETE /{bucket}`, `HEAD /{bucket}`, `GET /{bucket}?location`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/BucketOperationsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    $this->key = AccessKey::factory()->create();
});

function s3(AccessKey $key, string $method, string $path, array $query = [])
{
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));

    return test()
        ->withHeaders(SignsRequests::headers($key, $method, 's3.dero.test', $path, $query))
        ->call($method, $url);
}

it('lists the buckets belonging to the key\'s team', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'alpha']);
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'beta']);
    Bucket::factory()->create(['name' => 'someone-elses']);

    $response = s3($this->key, 'GET', '/');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('<Name>alpha</Name>')
        ->toContain('<Name>beta</Name>')
        ->not->toContain('someone-elses');
});

it('returns a well formed ListAllMyBucketsResult', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'alpha']);

    $xml = simplexml_load_string(s3($this->key, 'GET', '/')->getContent());

    expect($xml->getName())->toBe('ListAllMyBucketsResult')
        ->and((string) $xml->Buckets->Bucket[0]->Name)->toBe('alpha')
        ->and((string) $xml->Buckets->Bucket[0]->CreationDate)->not->toBeEmpty();
});

it('creates a bucket', function () {
    $response = s3($this->key, 'PUT', '/new-bucket');

    $response->assertOk();
    $response->assertHeader('Location', '/new-bucket');
    expect(Bucket::where('name', 'new-bucket')->exists())->toBeTrue();
});

it('rejects an invalid bucket name', function () {
    $response = s3($this->key, 'PUT', '/Invalid_Name');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>InvalidBucketName</Code>');
});

it('reports BucketAlreadyOwnedByYou when recreating your own bucket', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'mine']);

    $response = s3($this->key, 'PUT', '/mine');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketAlreadyOwnedByYou</Code>');
});

it('reports BucketAlreadyExists when the name is taken by another team', function () {
    Bucket::factory()->create(['name' => 'taken']);

    $response = s3($this->key, 'PUT', '/taken');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketAlreadyExists</Code>');
});

it('answers HEAD for an existing bucket with 200 and no body', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);

    $response = s3($this->key, 'HEAD', '/photos');

    $response->assertOk();
    expect($response->getContent())->toBe('');
});

it('answers HEAD for a missing bucket with 404 and no body', function () {
    // S3 returns an empty body on HEAD even for errors, because HEAD has no body.
    $response = s3($this->key, 'HEAD', '/nope');

    $response->assertStatus(404);
    expect($response->getContent())->toBe('');
});

it('deletes an empty bucket', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'empty']);

    s3($this->key, 'DELETE', '/empty')->assertStatus(204);

    expect(Bucket::where('name', 'empty')->exists())->toBeFalse();
});

it('refuses to delete a bucket that still holds objects', function () {
    $bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'full']);
    S3Object::factory()->for($bucket)->create(['team_id' => $this->key->team_id]);

    $response = s3($this->key, 'DELETE', '/full');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketNotEmpty</Code>');
    expect(Bucket::where('name', 'full')->exists())->toBeTrue();
});

it('returns the configured region for GetBucketLocation', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos', 'region' => 'eu-west-1']);

    $response = s3($this->key, 'GET', '/photos', ['location' => '']);

    expect($response->getContent())->toContain('eu-west-1');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=BucketOperationsTest`
Expected: FAIL — the temporary stub routes return empty 200s.

- [x] **Step 3: Implement ServiceController**

`app/Http/Controllers/S3/ServiceController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ServiceController extends Controller
{
    /**
     * GET / — ListBuckets.
     */
    public function listBuckets(Request $request): Response
    {
        $key = $request->attributes->get('s3.access_key');

        // ListBuckets has no bucket to evaluate public access against, so it
        // always requires credentials.
        if (! $key instanceof AccessKey) {
            throw new AccessDeniedException();
        }

        $buckets = Bucket::query()
            ->where('team_id', $key->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Bucket $bucket): array => [
                'Name' => $bucket->name,
                'CreationDate' => $bucket->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        $xml = XmlWriter::render('ListAllMyBucketsResult', [
            'Owner' => [
                'ID' => (string) $key->team_id,
                'DisplayName' => $key->team->name,
            ],
            'Buckets' => ['Bucket' => $buckets],
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
```

- [x] **Step 4: Implement BucketController**

`app/Http/Controllers/S3/BucketController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\BucketAlreadyExistsException;
use App\S3\Exceptions\BucketAlreadyOwnedByYouException;
use App\S3\Exceptions\BucketNotEmptyException;
use App\S3\Values\BucketName;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BucketController extends Controller
{
    /**
     * PUT /{bucket} — CreateBucket.
     */
    public function create(Request $request, string $bucket): Response
    {
        $key = $request->attributes->get('s3.access_key');

        if (! $key instanceof AccessKey) {
            throw new AccessDeniedException();
        }

        // Validates the name before touching the database, so an invalid name
        // reports InvalidBucketName rather than a constraint violation.
        $name = BucketName::fromString($bucket);

        $existing = Bucket::query()->where('name', $name->value)->first();

        if ($existing !== null) {
            throw $existing->team_id === $key->team_id
                ? new BucketAlreadyOwnedByYouException($name->value)
                : new BucketAlreadyExistsException($name->value);
        }

        Bucket::create([
            'team_id' => $key->team_id,
            'name' => $name->value,
            'region' => (string) config('dero.region'),
        ]);

        return new Response('', 200, ['Location' => '/'.$name->value]);
    }

    /**
     * HEAD /{bucket} — HeadBucket. Always an empty body.
     */
    public function head(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');

        return new Response('', 200, [
            'x-amz-bucket-region' => $bucket->region,
        ]);
    }

    /**
     * DELETE /{bucket} — DeleteBucket.
     */
    public function delete(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');

        if ($bucket->objects()->exists()) {
            throw new BucketNotEmptyException($bucket->name);
        }

        $bucket->delete();

        return new Response('', 204);
    }

    /**
     * GET /{bucket}?location — GetBucketLocation.
     */
    public function location(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');

        $xml = XmlWriter::render(
            'LocationConstraint',
            [],
            ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/'],
        );

        // The region is the element's text content, not a child element.
        $xml = str_replace('></LocationConstraint>', '>'.$bucket->region.'</LocationConstraint>', $xml);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
```

- [x] **Step 5: Wire the routes**

Replace the stub routes in `routes/s3.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\S3\BucketController;
use App\Http\Controllers\S3\ServiceController;
use App\S3\Exceptions\MethodNotAllowedException;
use Illuminate\Support\Facades\Route;

Route::get('/', [ServiceController::class, 'listBuckets']);

Route::put('/{bucket}', [BucketController::class, 'create']);
Route::match(['HEAD'], '/{bucket}', [BucketController::class, 'head']);
Route::delete('/{bucket}', [BucketController::class, 'delete']);

Route::get('/{bucket}', function (Request $request, string $bucket) {
    // Sub-resources are selected by a valueless query parameter.
    return match (true) {
        $request->query->has('location') => app(BucketController::class)->location($request),
        default => app(ObjectListController::class)->list($request),
    };
});

Route::fallback(function () {
    throw new MethodNotAllowedException();
});
```

`ObjectListController` arrives in Task 18; until then, point the `default` arm at a closure returning an empty `ListBucketResult`.

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=BucketOperationsTest`
Expected: PASS, 11 tests.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/S3 routes/s3.php tests/Feature/S3/BucketOperationsTest.php
git commit -m "feat: implement service and bucket S3 operations"
```

---

## Task 18: ListObjectsV2

The operation clients lean on hardest, and the one where ordering and pagination bugs hide.

**Files:**
- Create: `app/Http/Controllers/S3/ObjectListController.php`, `app/Actions/S3/ListObjects.php`, `app/S3/Values/ListObjectsQuery.php`, `app/S3/Values/ListObjectsResult.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/ListObjectsTest.php`

**Interfaces:**
- Produces: `ListObjects::handle(Bucket $bucket, ListObjectsQuery $query): ListObjectsResult`.
  `ListObjectsQuery` readonly: `prefix`, `delimiter`, `maxKeys`, `continuationToken`, `startAfter`, `encodingType`.
  `ListObjectsResult` readonly: `objects` (array of `S3Object`), `commonPrefixes` (array of string), `isTruncated` (bool), `nextContinuationToken` (?string).

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/ListObjectsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);
});

function seedKeys(array $keys): void
{
    foreach ($keys as $key) {
        S3Object::factory()->for(test()->bucket)->create([
            'team_id' => test()->bucket->team_id,
            'key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
        ]);
    }
}

function listObjects(array $query = [])
{
    $query = ['list-type' => '2', ...$query];
    $url = 'http://s3.dero.test/photos?'.http_build_query($query);

    return test()
        ->withHeaders(SignsRequests::headers(test()->key, 'GET', 's3.dero.test', '/photos', $query))
        ->get($url);
}

it('lists keys in bytewise lexicographic order', function () {
    seedKeys(['b.txt', 'a.txt', 'C.txt']);

    $xml = simplexml_load_string(listObjects()->getContent());
    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($xml->Contents));

    // Uppercase sorts before lowercase in bytewise order. A case-insensitive
    // collation would produce a.txt, b.txt, C.txt and break pagination.
    expect($keys)->toBe(['C.txt', 'a.txt', 'b.txt']);
});

it('returns the documented ListBucketResult shape', function () {
    seedKeys(['a.txt']);

    $xml = simplexml_load_string(listObjects()->getContent());

    expect($xml->getName())->toBe('ListBucketResult')
        ->and((string) $xml->Name)->toBe('photos')
        ->and((string) $xml->KeyCount)->toBe('1')
        ->and((string) $xml->IsTruncated)->toBe('false')
        ->and((string) $xml->Contents[0]->ETag)->not->toBeEmpty()
        ->and((string) $xml->Contents[0]->Size)->not->toBeEmpty()
        ->and((string) $xml->Contents[0]->LastModified)->not->toBeEmpty();
});

it('filters by prefix', function () {
    seedKeys(['photos/a.jpg', 'photos/b.jpg', 'docs/c.pdf']);

    $xml = simplexml_load_string(listObjects(['prefix' => 'photos/'])->getContent());

    expect(count($xml->Contents))->toBe(2);
});

it('rolls keys up into common prefixes when a delimiter is given', function () {
    seedKeys(['a/1.txt', 'a/2.txt', 'b/1.txt', 'top.txt']);

    $xml = simplexml_load_string(listObjects(['delimiter' => '/'])->getContent());

    $prefixes = array_map(fn ($p) => (string) $p->Prefix, iterator_to_array($xml->CommonPrefixes));

    expect($prefixes)->toBe(['a/', 'b/'])
        ->and(count($xml->Contents))->toBe(1)
        ->and((string) $xml->Contents[0]->Key)->toBe('top.txt');
});

it('does not double-count a common prefix', function () {
    seedKeys(['a/1.txt', 'a/2.txt', 'a/3.txt']);

    $xml = simplexml_load_string(listObjects(['delimiter' => '/'])->getContent());

    expect(count($xml->CommonPrefixes))->toBe(1);
});

it('truncates at max-keys and offers a continuation token', function () {
    seedKeys(['a.txt', 'b.txt', 'c.txt', 'd.txt']);

    $xml = simplexml_load_string(listObjects(['max-keys' => '2'])->getContent());

    expect(count($xml->Contents))->toBe(2)
        ->and((string) $xml->IsTruncated)->toBe('true')
        ->and((string) $xml->NextContinuationToken)->not->toBeEmpty();
});

it('resumes exactly where the previous page stopped', function () {
    seedKeys(['a.txt', 'b.txt', 'c.txt', 'd.txt']);

    $first = simplexml_load_string(listObjects(['max-keys' => '2'])->getContent());
    $token = (string) $first->NextContinuationToken;

    $second = simplexml_load_string(
        listObjects(['max-keys' => '2', 'continuation-token' => $token])->getContent()
    );

    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($second->Contents));

    expect($keys)->toBe(['c.txt', 'd.txt'])
        ->and((string) $second->IsTruncated)->toBe('false');
});

it('paginates the whole set without skipping or repeating a key', function () {
    $expected = collect(range(1, 25))->map(fn (int $i) => sprintf('key-%02d.txt', $i))->all();
    seedKeys($expected);

    $seen = [];
    $token = null;

    do {
        $query = ['max-keys' => '7'];

        if ($token !== null) {
            $query['continuation-token'] = $token;
        }

        $xml = simplexml_load_string(listObjects($query)->getContent());

        foreach ($xml->Contents as $content) {
            $seen[] = (string) $content->Key;
        }

        $token = (string) $xml->IsTruncated === 'true'
            ? (string) $xml->NextContinuationToken
            : null;
    } while ($token !== null);

    sort($expected);

    expect($seen)->toBe($expected);
});

it('starts after the given key', function () {
    seedKeys(['a.txt', 'b.txt', 'c.txt']);

    $xml = simplexml_load_string(listObjects(['start-after' => 'a.txt'])->getContent());
    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($xml->Contents));

    expect($keys)->toBe(['b.txt', 'c.txt']);
});

it('caps max-keys at 1000 like S3 does', function () {
    seedKeys(['a.txt']);

    $xml = simplexml_load_string(listObjects(['max-keys' => '99999'])->getContent());

    expect((string) $xml->MaxKeys)->toBe('1000');
});

it('url-encodes keys when encoding-type is url', function () {
    seedKeys(['my file.txt']);

    $xml = simplexml_load_string(listObjects(['encoding-type' => 'url'])->getContent());

    expect((string) $xml->Contents[0]->Key)->toBe('my%20file.txt')
        ->and((string) $xml->EncodingType)->toBe('url');
});

it('lists nothing for an empty bucket without erroring', function () {
    $xml = simplexml_load_string(listObjects()->getContent());

    expect((string) $xml->KeyCount)->toBe('0')
        ->and(count($xml->Contents))->toBe(0);
});

it('never lists objects from another bucket', function () {
    seedKeys(['mine.txt']);
    $other = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'other']);
    S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id,
        'key' => 'theirs.txt',
        'key_hash' => ObjectKey::fromString('theirs.txt')->hash(),
    ]);

    expect(listObjects()->getContent())->not->toContain('theirs.txt');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ListObjectsTest`
Expected: FAIL.

- [x] **Step 3: Create the query and result value objects**

`app/S3/Values/ListObjectsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Values;

use Illuminate\Http\Request;

final readonly class ListObjectsQuery
{
    public const int MAX_KEYS_LIMIT = 1000;

    public function __construct(
        public string $prefix,
        public string $delimiter,
        public int $maxKeys,
        public ?string $continuationToken,
        public ?string $startAfter,
        public ?string $encodingType,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $maxKeys = (int) $request->query('max-keys', (string) self::MAX_KEYS_LIMIT);

        return new self(
            prefix: (string) $request->query('prefix', ''),
            delimiter: (string) $request->query('delimiter', ''),
            // S3 silently caps at 1000 rather than erroring.
            maxKeys: max(0, min($maxKeys, self::MAX_KEYS_LIMIT)),
            continuationToken: $request->query('continuation-token'),
            startAfter: $request->query('start-after'),
            encodingType: $request->query('encoding-type'),
        );
    }

    /**
     * The key to resume after: the continuation token when paginating, else
     * start-after on the first page.
     */
    public function resumeAfter(): ?string
    {
        if ($this->continuationToken !== null) {
            $decoded = base64_decode($this->continuationToken, strict: true);

            return $decoded === false ? null : $decoded;
        }

        return $this->startAfter;
    }
}
```

`app/S3/Values/ListObjectsResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\Models\S3Object;

final readonly class ListObjectsResult
{
    /**
     * @param  array<int, S3Object>  $objects
     * @param  array<int, string>  $commonPrefixes
     */
    public function __construct(
        public array $objects,
        public array $commonPrefixes,
        public bool $isTruncated,
        public ?string $nextContinuationToken,
    ) {}
}
```

- [x] **Step 4: Implement the listing action**

`app/Actions/S3/ListObjects.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ListObjectsQuery;
use App\S3\Values\ListObjectsResult;
use App\S3\Values\ObjectKey;

final class ListObjects
{
    public function handle(Bucket $bucket, ListObjectsQuery $query): ListObjectsResult
    {
        $builder = S3Object::query()
            ->where('bucket_id', $bucket->id)
            ->orderBy('key');

        if ($query->prefix !== '') {
            $builder->where('key', 'like', $this->escapeLike($query->prefix).'%');
        }

        $resumeAfter = $query->resumeAfter();

        if ($resumeAfter !== null && $resumeAfter !== '') {
            $builder->where('key', '>', $resumeAfter);
        }

        $objects = [];
        $commonPrefixes = [];
        $truncated = false;
        $lastKey = null;

        // A delimiter collapses many keys into one CommonPrefix, so the number
        // of rows to read is not known ahead of time. Walk in chunks until the
        // page is full rather than fetching maxKeys rows and hoping.
        $builder->chunk(500, function ($chunk) use (
            $query, &$objects, &$commonPrefixes, &$truncated, &$lastKey
        ): bool {
            foreach ($chunk as $object) {
                if (count($objects) + count($commonPrefixes) >= $query->maxKeys) {
                    $truncated = true;

                    return false;
                }

                $prefix = ObjectKey::fromString($object->key)
                    ->commonPrefix($query->prefix, $query->delimiter);

                if ($prefix !== null) {
                    if (! in_array($prefix, $commonPrefixes, strict: true)) {
                        $commonPrefixes[] = $prefix;
                    }
                } else {
                    $objects[] = $object;
                }

                $lastKey = $object->key;
            }

            return true;
        });

        return new ListObjectsResult(
            objects: $objects,
            commonPrefixes: $commonPrefixes,
            isTruncated: $truncated,
            // The token is the last key seen, so the next page resumes with a
            // simple "key > token" comparison. Base64 so it is opaque to
            // clients and URL-safe.
            nextContinuationToken: $truncated && $lastKey !== null
                ? base64_encode($lastKey)
                : null,
        );
    }

    /**
     * Escape LIKE wildcards so a prefix containing % or _ filters literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
```

> **Known inefficiency, to record in `docs/roadmap.md`:** with a delimiter and a
> prefix containing very many keys, this walks rows that collapse into a single
> CommonPrefix. Correct, but O(keys) rather than O(prefixes). A later release
> can add a materialised prefix table. Do not optimise it now — correctness of
> pagination is the thing that must not be got wrong.

- [x] **Step 5: Implement the controller**

`app/Http/Controllers/S3/ObjectListController.php` renders the result. `Contents` entries carry `Key`, `LastModified` (`toIso8601ZuluString()`), `ETag` (wrapped in literal `"` quotes), `Size`, and `StorageClass` (`STANDARD`). The root element carries `Name`, `Prefix`, `KeyCount`, `MaxKeys`, `Delimiter` (omitted when empty), `IsTruncated`, `NextContinuationToken` (omitted when null), `EncodingType` (omitted when null), and `xmlns="http://s3.amazonaws.com/doc/2006-03-01/"`. When `encoding-type=url`, `Key`, `Prefix`, `Delimiter`, and `StartAfter` are `rawurlencode`d.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\ListObjects;
use App\Http\Controllers\Controller;
use App\Models\S3Object;
use App\S3\Values\ListObjectsQuery;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ObjectListController extends Controller
{
    public function __construct(private readonly ListObjects $listObjects) {}

    public function list(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');
        $query = ListObjectsQuery::fromRequest($request);
        $result = $this->listObjects->handle($bucket, $query);

        $encode = fn (string $value): string => $query->encodingType === 'url'
            ? rawurlencode($value)
            : $value;

        $xml = XmlWriter::render('ListBucketResult', [
            'Name' => $bucket->name,
            'Prefix' => $encode($query->prefix),
            'Delimiter' => $query->delimiter === '' ? null : $encode($query->delimiter),
            'MaxKeys' => $query->maxKeys,
            'KeyCount' => count($result->objects) + count($result->commonPrefixes),
            'IsTruncated' => $result->isTruncated,
            'NextContinuationToken' => $result->nextContinuationToken,
            'EncodingType' => $query->encodingType,
            'Contents' => array_map(fn (S3Object $object): array => [
                'Key' => $encode($object->key),
                'LastModified' => $object->last_modified->toIso8601ZuluString(),
                'ETag' => '"'.$object->etag.'"',
                'Size' => $object->size_bytes,
                'StorageClass' => 'STANDARD',
            ], $result->objects),
            'CommonPrefixes' => array_map(
                fn (string $prefix): array => ['Prefix' => $encode($prefix)],
                $result->commonPrefixes,
            ),
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
```

- [x] **Step 6: Point the bucket GET route at it**

In `routes/s3.php`, the `default` arm of the `GET /{bucket}` match now calls `app(ObjectListController::class)->list($request)`. Add the import.

- [x] **Step 7: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=ListObjectsTest`
Expected: PASS, 13 tests. The full-pagination test is the important one — it is what proves the continuation token neither skips nor repeats.

- [x] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/S3 app/Http/Controllers/S3 app/S3/Values routes/s3.php tests/Feature/S3/ListObjectsTest.php
git commit -m "feat: implement ListObjectsV2 with prefix, delimiter and pagination"
```

---

## Task 19: PutObject with streaming and aws-chunked support

**Files:**
- Create: `app/Actions/S3/PutObject.php`, `app/S3/Http/RequestBodyStream.php`, `app/S3/Http/ObjectMetadata.php`
- Create: `app/Http/Controllers/S3/ObjectController.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/PutObjectTest.php`

**Interfaces:**
- Produces: `PutObject::handle(Bucket $bucket, ObjectKey $key, mixed $stream, ObjectMetadata $metadata): S3Object`.
  `ObjectMetadata::fromRequest(Request): self` readonly with `contentType`, `contentEncoding`, `contentDisposition`, `cacheControl`, `expires`, `userMetadata` (array).
  `RequestBodyStream::open(Request): mixed` returning a decoded body stream.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/PutObjectTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Storage\Contracts\ObjectStorageBackend;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    config()->set('dero.storage_root', storage_path('framework/testing/dero-'.uniqid()));
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);
});

function putObject(string $key, string $body, array $headers = [])
{
    $path = '/photos/'.$key;

    return test()
        ->withHeaders(SignsRequests::headers(
            test()->key, 'PUT', 's3.dero.test', $path, extraHeaders: $headers,
        ))
        ->call('PUT', 'http://s3.dero.test'.$path, content: $body);
}

it('stores an object and returns its ETag', function () {
    $response = putObject('cat.jpg', 'image-bytes');

    $response->assertOk();
    expect($response->headers->get('ETag'))->toBe('"'.md5('image-bytes').'"');
});

it('computes a genuine MD5 ETag', function () {
    putObject('cat.jpg', 'hello world');

    expect(S3Object::first()->etag)->toBe(md5('hello world'));
});

it('records size, checksum and last modified', function () {
    putObject('cat.jpg', 'hello');

    $object = S3Object::first();

    expect($object->size_bytes)->toBe(5)
        ->and($object->checksum_sha256)->toBe(hash('sha256', 'hello'))
        ->and($object->last_modified)->not->toBeNull();
});

it('writes the bytes to the storage backend', function () {
    putObject('cat.jpg', 'payload');

    $object = S3Object::first();
    $stream = app(ObjectStorageBackend::class)->readStream($object->storage_path);

    expect(stream_get_contents($stream))->toBe('payload');
});

it('stores a physical path derived from the ulid, never from the key', function () {
    putObject('../../etc/passwd', 'evil');

    $object = S3Object::first();

    expect($object->key)->toBe('../../etc/passwd')
        ->and($object->storage_path)->toStartWith('objects/')
        ->and($object->storage_path)->not->toContain('..')
        ->and($object->storage_path)->toEndWith($object->id);
});

it('overwrites an existing key rather than duplicating it', function () {
    putObject('cat.jpg', 'first');
    putObject('cat.jpg', 'second');

    expect(S3Object::count())->toBe(1)
        ->and(S3Object::first()->etag)->toBe(md5('second'));
});

it('reclaims the old bytes when a key is overwritten', function () {
    putObject('cat.jpg', 'first');
    $firstPath = S3Object::first()->storage_path;

    putObject('cat.jpg', 'second');

    expect(app(ObjectStorageBackend::class)->exists($firstPath))->toBeFalse();
});

it('preserves content type and user metadata', function () {
    putObject('doc.pdf', 'x', [
        'content-type' => 'application/pdf',
        'cache-control' => 'max-age=3600',
        'content-disposition' => 'attachment; filename="report.pdf"',
        'x-amz-meta-owner' => 'ambrose',
        'x-amz-meta-project' => 'dero',
    ]);

    $object = S3Object::first();

    expect($object->content_type)->toBe('application/pdf')
        ->and($object->cache_control)->toBe('max-age=3600')
        ->and($object->content_disposition)->toBe('attachment; filename="report.pdf"')
        ->and($object->metadata)->toBe(['owner' => 'ambrose', 'project' => 'dero']);
});

it('defaults the content type when none is supplied', function () {
    putObject('blob', 'x');

    expect(S3Object::first()->content_type)->toBe('binary/octet-stream');
});

it('maintains the bucket counters', function () {
    putObject('a.txt', 'aaaa');
    putObject('b.txt', 'bb');

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(2)
        ->and($bucket->storage_bytes)->toBe(6);
});

it('corrects the counters when an object is overwritten with a different size', function () {
    putObject('a.txt', 'aaaa');
    putObject('a.txt', 'a');

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(1)
        ->and($bucket->storage_bytes)->toBe(1);
});

it('decodes an aws-chunked body', function () {
    $payload = 'chunked-content';
    $body = dechex(strlen($payload)).';chunk-signature='.str_repeat('a', 64)."\r\n".$payload."\r\n"
        .'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";

    $response = putObject('chunked.txt', $body, [
        'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'x-amz-decoded-content-length' => (string) strlen($payload),
        'content-encoding' => 'aws-chunked',
    ]);

    $response->assertOk();

    // The framing must not be stored, and the ETag must cover the object, not
    // the wire bytes.
    expect(S3Object::first()->size_bytes)->toBe(strlen($payload))
        ->and(S3Object::first()->etag)->toBe(md5($payload));
});

it('does not persist aws-chunked as the object content encoding', function () {
    $payload = 'x';
    $body = dechex(strlen($payload)).';chunk-signature='.str_repeat('a', 64)."\r\n".$payload."\r\n"
        .'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";

    putObject('c.txt', $body, [
        'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'content-encoding' => 'aws-chunked',
    ]);

    // aws-chunked describes the transfer framing, not the stored object.
    expect(S3Object::first()->content_encoding)->toBeNull();
});

it('rejects a body larger than the configured maximum', function () {
    config()->set('dero.max_upload_size', 4);

    $response = putObject('big.bin', 'aaaaaaaa');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>EntityTooLarge</Code>');
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=PutObjectTest`
Expected: FAIL — no object routes.

- [x] **Step 3: Implement the metadata value object**

`app/S3/Http/ObjectMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;

final readonly class ObjectMetadata
{
    /**
     * @param  array<string, string>  $userMetadata
     */
    public function __construct(
        public string $contentType,
        public ?string $contentEncoding,
        public ?string $contentDisposition,
        public ?string $cacheControl,
        public ?string $expires,
        public array $userMetadata,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $encoding = $request->headers->get('Content-Encoding');

        // "aws-chunked" describes the transfer framing, which the decoder
        // removes. Persisting it would make every GET claim an encoding the
        // stored bytes do not have.
        if (is_string($encoding)) {
            $remaining = array_values(array_filter(
                array_map(trim(...), explode(',', $encoding)),
                static fn (string $value): bool => $value !== '' && $value !== 'aws-chunked',
            ));

            $encoding = $remaining === [] ? null : implode(', ', $remaining);
        }

        $userMetadata = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower($name);

            if (str_starts_with($name, 'x-amz-meta-')) {
                $userMetadata[substr($name, strlen('x-amz-meta-'))] = $values[0] ?? '';
            }
        }

        return new self(
            contentType: $request->headers->get('Content-Type') ?: 'binary/octet-stream',
            contentEncoding: $encoding,
            contentDisposition: $request->headers->get('Content-Disposition'),
            cacheControl: $request->headers->get('Cache-Control'),
            expires: $request->headers->get('Expires'),
            userMetadata: $userMetadata,
        );
    }
}
```

- [x] **Step 4: Implement the body stream**

`app/S3/Http/RequestBodyStream.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;
use RuntimeException;

final class RequestBodyStream
{
    /**
     * Open the request body as a readable stream, decoding aws-chunked framing
     * when present.
     *
     * The body is never materialised as a string: $request->getContent() would
     * put the whole object in memory, which is exactly what this design avoids.
     *
     * @return resource
     */
    public static function open(Request $request): mixed
    {
        $input = $request->getContent(asResource: true);

        if (! is_resource($input)) {
            throw new RuntimeException('Unable to open the request body.');
        }

        $payloadHash = (string) $request->headers->get('X-Amz-Content-Sha256', '');

        if (! ChunkedPayloadDecoder::isChunked($payloadHash)) {
            return $input;
        }

        // php://temp spills to disk past its threshold, so a large decoded body
        // does not sit in memory.
        $decoded = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'r+b');

        ChunkedPayloadDecoder::decodeTo($input, $decoded);
        rewind($decoded);

        return $decoded;
    }
}
```

- [x] **Step 5: Implement the action**

`app/Actions/S3/PutObject.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\EntityTooLargeException;
use App\S3\Http\ObjectMetadata;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PutObject
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * @param  resource  $stream
     */
    public function handle(Bucket $bucket, ObjectKey $key, mixed $stream, ObjectMetadata $metadata): S3Object
    {
        $id = (string) Str::ulid();
        $path = ObjectPathGenerator::forObject($id);

        // Written before the transaction opens: streaming a large body inside a
        // database transaction would hold the transaction open for the whole
        // upload.
        $stored = $this->storage->put($path, $stream);

        $maximum = (int) config('dero.max_upload_size');

        if ($stored->size > $maximum) {
            $this->storage->delete($path);

            throw new EntityTooLargeException($stored->size, $maximum);
        }

        $previousPath = null;

        $object = DB::transaction(function () use (
            $bucket, $key, $id, $path, $stored, $metadata, &$previousPath
        ): S3Object {
            $existing = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $previousPath = $existing->storage_path;

                $bucket->decrement('storage_bytes', $existing->size_bytes);
                $bucket->decrement('objects_count');

                $existing->delete();
            }

            $object = S3Object::create([
                'id' => $id,
                'bucket_id' => $bucket->id,
                'team_id' => $bucket->team_id,
                'key' => $key->value,
                'key_hash' => $key->hash(),
                'storage_path' => $path,
                'size_bytes' => $stored->size,
                'etag' => $stored->md5Hex,
                'checksum_sha256' => $stored->sha256Hex,
                'content_type' => $metadata->contentType,
                'content_encoding' => $metadata->contentEncoding,
                'content_disposition' => $metadata->contentDisposition,
                'cache_control' => $metadata->cacheControl,
                'expires_header' => $metadata->expires,
                'metadata' => $metadata->userMetadata === [] ? null : $metadata->userMetadata,
                'storage_backend' => 'local',
                'last_modified' => now(),
            ]);

            $bucket->increment('storage_bytes', $stored->size);
            $bucket->increment('objects_count');

            return $object;
        });

        // Only after the row is durably replaced. Deleting first would lose the
        // old bytes if the transaction rolled back.
        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $object;
    }
}
```

- [x] **Step 6: Implement the controller action and route**

Add `put()` to `app/Http/Controllers/S3/ObjectController.php`:

```php
public function put(Request $request, string $bucket, string $key): Response
{
    $bucketModel = $request->attributes->get('s3.bucket');
    $objectKey = ObjectKey::fromString($key);

    $stream = RequestBodyStream::open($request);

    try {
        $object = $this->putObject->handle(
            $bucketModel,
            $objectKey,
            $stream,
            ObjectMetadata::fromRequest($request),
        );
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    return new Response('', 200, ['ETag' => '"'.$object->etag.'"']);
}
```

Register in `routes/s3.php`, after the bucket routes and before the fallback:

```php
Route::put('/{bucket}/{key}', [ObjectController::class, 'put'])->where('key', '.*');
```

> `where('key', '.*')` is required: without it Laravel stops the parameter at
> the first slash and `photos/2026/q1.pdf` never routes.

- [x] **Step 7: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=PutObjectTest`
Expected: PASS, 14 tests.

- [x] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/S3 app/S3/Http app/Http/Controllers/S3 routes/s3.php tests/Feature/S3/PutObjectTest.php
git commit -m "feat: implement streaming PutObject with aws-chunked support"
```

---

## Task 20: GetObject, HeadObject and Range requests

**Files:**
- Create: `app/S3/Http/RangeHeader.php`
- Modify: `app/Http/Controllers/S3/ObjectController.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/GetObjectTest.php`

**Interfaces:**
- Produces: `RangeHeader::parse(?string $header, int $size): ?RangeHeader` with readonly `start`, `end`, `length`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/GetObjectTest.php`. It reuses `putObject()` from Task 19 — copy that helper into this file (Pest helper functions are file-scoped):

```php
<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    config()->set('dero.endpoint_host', 's3.dero.test');
    config()->set('dero.storage_root', storage_path('framework/testing/dero-'.uniqid()));
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);
});

function put(string $key, string $body, array $headers = [])
{
    $path = '/photos/'.$key;

    return test()->withHeaders(SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path, extraHeaders: $headers,
    ))->call('PUT', 'http://s3.dero.test'.$path, content: $body);
}

function get(string $key, array $headers = [], string $method = 'GET')
{
    $path = '/photos/'.$key;

    return test()->withHeaders([
        ...SignsRequests::headers(test()->key, $method, 's3.dero.test', $path),
        ...$headers,
    ])->call($method, 'http://s3.dero.test'.$path);
}

it('returns the stored bytes', function () {
    put('cat.jpg', 'image-bytes');

    $response = get('cat.jpg');

    $response->assertOk();
    expect($response->streamedContent())->toBe('image-bytes');
});

it('returns the stored headers', function () {
    put('doc.pdf', 'x', ['content-type' => 'application/pdf', 'x-amz-meta-owner' => 'ambrose']);

    $response = get('doc.pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('ETag'))->toBe('"'.md5('x').'"')
        ->and($response->headers->get('x-amz-meta-owner'))->toBe('ambrose')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Last-Modified'))->not->toBeEmpty();
});

it('reports NoSuchKey for a missing object', function () {
    $response = get('missing.jpg');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchKey</Code>');
});

it('answers HEAD with the headers and no body', function () {
    put('cat.jpg', 'image-bytes');

    $response = get('cat.jpg', method: 'HEAD');

    $response->assertOk();
    expect($response->headers->get('Content-Length'))->toBe('11')
        ->and($response->getContent())->toBe('');
});

it('serves a byte range as 206 Partial Content', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=2-5']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('2345')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 2-5/10')
        ->and($response->headers->get('Content-Length'))->toBe('4');
});

it('serves an open-ended range', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=7-']);

    expect($response->streamedContent())->toBe('789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');
});

it('serves a suffix range', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=-3']);

    expect($response->streamedContent())->toBe('789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');
});

it('clamps a range that runs past the end of the object', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=8-99']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('89');
});

it('rejects an unsatisfiable range', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=50-60']);

    expect($response->getStatusCode())->toBe(416);
    expect($response->getContent())->toContain('<Code>InvalidRange</Code>');
});

it('returns the whole object for a multi-range request', function () {
    // RFC 7233 permits ignoring multi-range. Recorded in the compatibility matrix.
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'bytes=0-1,4-5']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('0123456789');
});

it('ignores a malformed range header and returns the whole object', function () {
    put('data.bin', '0123456789');

    $response = get('data.bin', ['Range' => 'furlongs=1-2']);

    expect($response->getStatusCode())->toBe(200);
});

it('streams without buffering the object in memory', function () {
    $payload = str_repeat('z', 16 * 1024 * 1024);
    put('big.bin', $payload);

    $before = memory_get_usage(true);
    $response = get('big.bin');
    $content = $response->streamedContent();
    $growth = memory_get_usage(true) - $before;

    expect(strlen($content))->toBe(16 * 1024 * 1024)
        // streamedContent() necessarily buffers to assert on it; the point is
        // that the response is a StreamedResponse rather than a string body.
        ->and($response->baseResponse)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=GetObjectTest`
Expected: FAIL.

- [x] **Step 3: Implement RangeHeader**

`app/S3/Http/RangeHeader.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\InvalidRangeException;

final readonly class RangeHeader
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contentRange(int $size): string
    {
        return sprintf('bytes %d-%d/%d', $this->start, $this->end, $size);
    }

    /**
     * Parse a Range header. Returns null when the header is absent, malformed,
     * or asks for multiple ranges — all of which mean "serve the whole object",
     * which RFC 7233 permits.
     *
     * @throws InvalidRangeException when the range is well formed but cannot be
     *                               satisfied against an object of this size.
     */
    public static function parse(?string $header, int $size): ?self
    {
        if ($header === null || ! str_starts_with($header, 'bytes=')) {
            return null;
        }

        $spec = substr($header, strlen('bytes='));

        // Multi-range is not supported; returning the whole object is legal.
        if (str_contains($spec, ',')) {
            return null;
        }

        if (preg_match('/^(\d*)-(\d*)$/', $spec, $matches) !== 1) {
            return null;
        }

        [$from, $to] = [$matches[1], $matches[2]];

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // Suffix range: the last N bytes.
            $length = (int) $to;

            if ($length === 0) {
                throw new InvalidRangeException($header);
            }

            $start = max(0, $size - $length);
            $end = $size - 1;
        } else {
            $start = (int) $from;
            // An end past the object is clamped, not rejected.
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        if ($start > $end || $start >= $size) {
            throw new InvalidRangeException($header);
        }

        return new self($start, $end);
    }
}
```

- [x] **Step 4: Implement get and head**

Add to `ObjectController`:

```php
public function get(Request $request, string $bucket, string $key): Response|StreamedResponse
{
    $object = $this->findObject($request, $key);
    $range = RangeHeader::parse($request->headers->get('Range'), $object->size_bytes);

    $headers = $this->responseHeaders($object);

    if ($range === null) {
        $headers['Content-Length'] = (string) $object->size_bytes;

        return $this->stream(
            fn () => $this->storage->readStream($object->storage_path),
            $headers,
            200,
        );
    }

    $headers['Content-Length'] = (string) $range->length();
    $headers['Content-Range'] = $range->contentRange($object->size_bytes);

    return $this->stream(
        fn () => $this->storage->readRange($object->storage_path, $range->start, $range->length()),
        $headers,
        206,
    );
}

public function head(Request $request, string $bucket, string $key): Response
{
    $object = $this->findObject($request, $key);

    return new Response('', 200, [
        ...$this->responseHeaders($object),
        'Content-Length' => (string) $object->size_bytes,
    ]);
}

private function findObject(Request $request, string $key): S3Object
{
    $bucket = $request->attributes->get('s3.bucket');
    $objectKey = ObjectKey::fromString($key);

    $object = S3Object::query()
        ->where('bucket_id', $bucket->id)
        ->where('key_hash', $objectKey->hash())
        ->first();

    if ($object === null) {
        throw new NoSuchKeyException($objectKey->value);
    }

    return $object;
}

/**
 * @return array<string, string>
 */
private function responseHeaders(S3Object $object): array
{
    $headers = array_filter([
        'Content-Type' => $object->content_type,
        'Content-Encoding' => $object->content_encoding,
        'Content-Disposition' => $object->content_disposition,
        'Cache-Control' => $object->cache_control,
        'Expires' => $object->expires_header,
        'ETag' => '"'.$object->etag.'"',
        'Last-Modified' => $object->last_modified->toRfc7231String(),
        'Accept-Ranges' => 'bytes',
        'x-amz-checksum-sha256' => $object->checksum_sha256,
    ], fn (?string $value): bool => $value !== null && $value !== '');

    foreach ($object->metadata ?? [] as $name => $value) {
        $headers['x-amz-meta-'.$name] = $value;
    }

    return $headers;
}

/**
 * @param  callable(): resource  $open
 * @param  array<string, string>  $headers
 */
private function stream(callable $open, array $headers, int $status): StreamedResponse
{
    $chunkSize = (int) config('dero.stream_chunk_size');

    return new StreamedResponse(function () use ($open, $chunkSize): void {
        $stream = $open();

        while (! feof($stream)) {
            $chunk = fread($stream, $chunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            flush();
        }

        fclose($stream);
    }, $status, $headers);
}
```

Inject `ObjectStorageBackend $storage` into the constructor alongside `PutObject $putObject`.

- [x] **Step 5: Register the routes**

```php
Route::get('/{bucket}/{key}', [ObjectController::class, 'get'])->where('key', '.*');
Route::match(['HEAD'], '/{bucket}/{key}', [ObjectController::class, 'head'])->where('key', '.*');
```

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=GetObjectTest`
Expected: PASS, 12 tests.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/S3/Http app/Http/Controllers/S3 routes/s3.php tests/Feature/S3/GetObjectTest.php
git commit -m "feat: implement streaming GetObject, HeadObject and range requests"
```

---

## Task 21: DeleteObject, DeleteObjects and CopyObject

**Files:**
- Create: `app/Actions/S3/DeleteObject.php`, `app/Actions/S3/CopyObject.php`
- Create: `app/S3/Xml/XmlReader.php`
- Modify: `app/Http/Controllers/S3/ObjectController.php`, `routes/s3.php`
- Test: `tests/Feature/S3/DeleteObjectTest.php`, `tests/Feature/S3/CopyObjectTest.php`

**Interfaces:**
- Produces: `DeleteObject::handle(Bucket $bucket, ObjectKey $key): bool`, `CopyObject::handle(S3Object $source, Bucket $destinationBucket, ObjectKey $destinationKey, ?ObjectMetadata $metadata): S3Object`, `XmlReader::parse(string $xml): array`.

- [x] **Step 1: Write the failing tests**

`tests/Feature/S3/DeleteObjectTest.php` covers: deleting an existing object returns 204 and removes both the row and the stored bytes; deleting a missing key also returns 204 (S3 delete is idempotent and must not 404); bucket counters decrease; `POST /{bucket}?delete` with a `<Delete><Object><Key>…</Key></Object></Delete>` body deletes each listed key and returns a `DeleteResult` naming each `<Deleted>`; a key the caller may not delete appears under `<Error>` rather than failing the whole batch; and a malformed body returns `MalformedXML`.

```php
it('deletes an object and its bytes', function () {
    put('cat.jpg', 'bytes');
    $path = \App\Models\S3Object::first()->storage_path;

    $response = get('cat.jpg', method: 'DELETE');

    expect($response->getStatusCode())->toBe(204)
        ->and(\App\Models\S3Object::count())->toBe(0)
        ->and(app(\App\Storage\Contracts\ObjectStorageBackend::class)->exists($path))->toBeFalse();
});

it('returns 204 when deleting a key that does not exist', function () {
    // S3 DeleteObject is idempotent. Returning 404 breaks `aws s3 sync`.
    expect(get('never-existed.jpg', method: 'DELETE')->getStatusCode())->toBe(204);
});

it('decrements the bucket counters', function () {
    put('a.txt', 'aaaa');
    get('a.txt', method: 'DELETE');

    $bucket = test()->bucket->fresh();

    expect($bucket->objects_count)->toBe(0)
        ->and($bucket->storage_bytes)->toBe(0);
});
```

`tests/Feature/S3/CopyObjectTest.php` covers: `PUT /{bucket}/{key}` with `x-amz-copy-source: /source-bucket/source-key` duplicates the object and returns a `CopyObjectResult` carrying `ETag` and `LastModified`; the copy carries the source's metadata by default; `x-amz-metadata-directive: REPLACE` substitutes the request's metadata instead; copying from a bucket in another team is denied; copying a missing source reports `NoSuchKey`; and copying onto itself without `REPLACE` is rejected with `InvalidRequest`, matching AWS.

- [x] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="DeleteObjectTest|CopyObjectTest"`
Expected: FAIL.

- [x] **Step 3: Implement XmlReader with entity expansion disabled**

`app/S3/Xml/XmlReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\S3\Xml;

use App\S3\Exceptions\MalformedXmlException;
use SimpleXMLElement;

final class XmlReader
{
    private const int MAX_BYTES = 1024 * 1024;

    /**
     * Parse an S3 request body into an array.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedXmlException
     */
    public static function parse(string $xml): array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new MalformedXmlException();
        }

        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET blocks network fetches and no DTD loading flag is set,
        // so external entity expansion (XXE, billion laughs) cannot occur.
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT & ~LIBXML_NOENT);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new MalformedXmlException();
        }

        return json_decode((string) json_encode($document), associative: true) ?? [];
    }
}
```

> Verify with a test that a body containing a `<!DOCTYPE … ENTITY …>` declaration
> does **not** expand the entity. That test belongs in Task 26's security suite.

- [x] **Step 4: Implement the actions and controller methods**

`app/Actions/S3/DeleteObject.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Support\Facades\DB;

final class DeleteObject
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * Returns false when the key did not exist. The caller still answers 204:
     * S3 DeleteObject is idempotent, and returning 404 breaks `aws s3 sync`.
     */
    public function handle(Bucket $bucket, ObjectKey $key): bool
    {
        $path = DB::transaction(function () use ($bucket, $key): ?string {
            $object = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
                ->lockForUpdate()
                ->first();

            if ($object === null) {
                return null;
            }

            $bucket->decrement('storage_bytes', $object->size_bytes);
            $bucket->decrement('objects_count');
            $object->delete();

            return $object->storage_path;
        });

        if ($path === null) {
            return false;
        }

        // After the commit: deleting first would lose the bytes if the
        // transaction rolled back.
        $this->storage->delete($path);

        return true;
    }
}
```

`CopyObject::handle` resolves the source, calls `$storage->copy()` (which never reads the bytes into PHP), and creates the destination row carrying the source's size, ETag, and checksum. When the metadata directive is `REPLACE`, the destination takes the request's metadata; otherwise it inherits the source's.

Controller: `delete()` returns `new Response('', 204)` unconditionally. `deleteMultiple()` parses the body with `XmlReader`, deletes each key, and renders a `DeleteResult`. `put()` gains a branch at the top — when `x-amz-copy-source` is present it delegates to `CopyObject` and renders `CopyObjectResult` instead of consuming a request body.

- [x] **Step 5: Register the routes**

```php
Route::delete('/{bucket}/{key}', [ObjectController::class, 'delete'])->where('key', '.*');
Route::post('/{bucket}', [ObjectController::class, 'deleteMultiple']);
```

The `POST /{bucket}` route must check for the `delete` query parameter and throw `NotImplementedException` otherwise, so a POST for an unimplemented sub-resource reports correctly rather than silently deleting nothing.

- [x] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="DeleteObjectTest|CopyObjectTest"`
Expected: PASS.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests routes
git commit -m "feat: implement DeleteObject, DeleteObjects and CopyObject"
```

---

## Task 22: Multipart uploads

**Files:**
- Create: `app/Actions/S3/CreateMultipartUpload.php`, `UploadPart.php`, `CompleteMultipartUpload.php`, `AbortMultipartUpload.php`
- Create: `app/Http/Controllers/S3/MultipartController.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/MultipartUploadTest.php`

**Interfaces:**
- Produces: `CompleteMultipartUpload::handle(MultipartUpload $upload, array $parts): S3Object` where `$parts` is `array<int, array{PartNumber: int, ETag: string}>`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/MultipartUploadTest.php`. It must cover:

```php
it('completes a multipart upload and assembles the parts in order', function () {
    $uploadId = createUpload('big.bin');

    $one = uploadPart($uploadId, 1, str_repeat('a', 5 * 1024 * 1024));
    $two = uploadPart($uploadId, 2, 'tail');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => $one],
        ['PartNumber' => 2, 'ETag' => $two],
    ]);

    $response->assertOk();

    $object = \App\Models\S3Object::first();

    expect($object->size_bytes)->toBe(5 * 1024 * 1024 + 4)
        ->and($object->is_multipart)->toBeTrue()
        ->and($object->part_count)->toBe(2);
});

it('computes an AWS-compatible multipart ETag', function () {
    $uploadId = createUpload('big.bin');
    $partOne = str_repeat('a', 5 * 1024 * 1024);
    $partTwo = 'tail';

    uploadPart($uploadId, 1, $partOne);
    uploadPart($uploadId, 2, $partTwo);

    completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => md5($partOne)],
        ['PartNumber' => 2, 'ETag' => md5($partTwo)],
    ]);

    // AWS: md5 of the concatenated binary part digests, then "-N".
    $expected = md5(md5($partOne, true).md5($partTwo, true)).'-2';

    expect(\App\Models\S3Object::first()->etag)->toBe($expected);
});

it('rejects parts supplied out of order', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, str_repeat('a', 5 * 1024 * 1024));
    uploadPart($uploadId, 2, 'tail');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 2, 'ETag' => md5('tail')],
        ['PartNumber' => 1, 'ETag' => md5(str_repeat('a', 5 * 1024 * 1024))],
    ]);

    expect($response->getContent())->toContain('<Code>InvalidPartOrder</Code>');
});

it('rejects a part whose ETag does not match what was uploaded', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, 'data');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => str_repeat('0', 32)],
    ]);

    expect($response->getContent())->toContain('<Code>InvalidPart</Code>');
});

it('rejects a non-final part smaller than 5 MiB', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, 'tiny');
    uploadPart($uploadId, 2, 'tail');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => md5('tiny')],
        ['PartNumber' => 2, 'ETag' => md5('tail')],
    ]);

    expect($response->getContent())->toContain('<Code>EntityTooSmall</Code>');
});

it('allows a single part smaller than 5 MiB', function () {
    // The minimum does not apply to the last part, and a one-part upload is
    // entirely last part.
    $uploadId = createUpload('small.bin');
    uploadPart($uploadId, 1, 'tiny');

    completeUpload('small.bin', $uploadId, [['PartNumber' => 1, 'ETag' => md5('tiny')]])
        ->assertOk();
});

it('replaces a re-uploaded part rather than duplicating it', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, 'first');
    uploadPart($uploadId, 1, 'second');

    expect(\App\Models\MultipartPart::where('part_number', 1)->count())->toBe(1);
});

it('lists parts', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, str_repeat('a', 5 * 1024 * 1024));

    $xml = simplexml_load_string(listParts('big.bin', $uploadId)->getContent());

    expect($xml->getName())->toBe('ListPartsResult')
        ->and((string) $xml->Part[0]->PartNumber)->toBe('1');
});

it('aborts an upload and discards its parts', function () {
    $uploadId = createUpload('big.bin');
    uploadPart($uploadId, 1, 'data');

    abortUpload('big.bin', $uploadId)->assertStatus(204);

    expect(\App\Models\MultipartUpload::count())->toBe(0)
        ->and(\App\Models\MultipartPart::count())->toBe(0)
        ->and(\App\Models\S3Object::count())->toBe(0);
});

it('reports NoSuchUpload for an unknown upload id', function () {
    $response = completeUpload('big.bin', '01JKXQNOTREAL', [['PartNumber' => 1, 'ETag' => 'x']]);

    expect($response->getContent())->toContain('<Code>NoSuchUpload</Code>');
});

it('carries metadata from create through to the completed object', function () {
    $uploadId = createUpload('doc.pdf', ['content-type' => 'application/pdf', 'x-amz-meta-owner' => 'ambrose']);
    uploadPart($uploadId, 1, 'x');
    completeUpload('doc.pdf', $uploadId, [['PartNumber' => 1, 'ETag' => md5('x')]]);

    $object = \App\Models\S3Object::first();

    expect($object->content_type)->toBe('application/pdf')
        ->and($object->metadata)->toBe(['owner' => 'ambrose']);
});

it('denies completing an upload belonging to another team', function () {
    $uploadId = createUpload('big.bin');
    \App\Models\MultipartUpload::find($uploadId)->update(['team_id' => \App\Models\Team::factory()->create()->id]);

    completeUpload('big.bin', $uploadId, [['PartNumber' => 1, 'ETag' => 'x']])
        ->assertStatus(403);
});
```

Write the `createUpload`, `uploadPart`, `completeUpload`, `listParts`, and `abortUpload` helpers at the top of the file, each signing via `SignsRequests` exactly as the earlier tests do. `createUpload` issues `POST /photos/{key}?uploads` and returns the `UploadId` from the response XML. `uploadPart` issues `PUT /photos/{key}?partNumber=N&uploadId=…` and returns the `ETag` header stripped of quotes. `completeUpload` issues `POST /photos/{key}?uploadId=…` with a `<CompleteMultipartUpload>` body.

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=MultipartUploadTest`
Expected: FAIL.

- [x] **Step 3: Implement CompleteMultipartUpload**

This is the task's substance; the other three actions are straightforward.

`app/Actions/S3/CompleteMultipartUpload.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Models\S3Object;
use App\S3\Exceptions\EntityTooSmallException;
use App\S3\Exceptions\InvalidPartException;
use App\S3\Exceptions\InvalidPartOrderException;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompleteMultipartUpload
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>  $requestedParts
     */
    public function handle(MultipartUpload $upload, array $requestedParts): S3Object
    {
        $this->assertAscending($requestedParts);

        $stored = MultipartPart::query()
            ->where('multipart_upload_id', $upload->id)
            ->get()
            ->keyBy('part_number');

        $minimum = (int) config('dero.multipart.min_part_size');
        $lastIndex = count($requestedParts) - 1;
        $digests = '';
        $partNumbers = [];

        foreach ($requestedParts as $index => $requested) {
            $part = $stored->get($requested['PartNumber']);

            if ($part === null) {
                throw new InvalidPartException($requested['PartNumber']);
            }

            // Clients send the ETag quoted; compare on the bare hex.
            if (! hash_equals($part->etag, trim($requested['ETag'], '"'))) {
                throw new InvalidPartException($requested['PartNumber']);
            }

            // Every part but the last must reach the minimum. AWS enforces
            // this and clients rely on the error to fall back correctly.
            if ($index !== $lastIndex && $part->size_bytes < $minimum) {
                throw new EntityTooSmallException($part->part_number, $part->size_bytes, $minimum);
            }

            $digests .= $part->checksum_md5;
            $partNumbers[] = $part->part_number;
        }

        $id = (string) Str::ulid();
        $path = ObjectPathGenerator::forObject($id);

        $assembled = $this->storage->completeMultipart($upload->id, $partNumbers, $path);

        // AWS multipart ETag: md5 of the concatenated raw part digests, then
        // a hyphen and the part count.
        $etag = md5($digests).'-'.count($partNumbers);

        $key = ObjectKey::fromString($upload->key);
        $previousPath = null;

        $object = DB::transaction(function () use (
            $upload, $key, $id, $path, $assembled, $etag, $partNumbers, &$previousPath
        ): S3Object {
            $bucket = $upload->bucket()->lockForUpdate()->first();

            $existing = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
                ->first();

            if ($existing !== null) {
                $previousPath = $existing->storage_path;
                $bucket->decrement('storage_bytes', $existing->size_bytes);
                $bucket->decrement('objects_count');
                $existing->delete();
            }

            $object = S3Object::create([
                'id' => $id,
                'bucket_id' => $bucket->id,
                'team_id' => $bucket->team_id,
                'key' => $key->value,
                'key_hash' => $key->hash(),
                'storage_path' => $path,
                'size_bytes' => $assembled->size,
                'etag' => $etag,
                'checksum_sha256' => $assembled->sha256Hex,
                'content_type' => $upload->content_type,
                'content_encoding' => $upload->content_encoding,
                'content_disposition' => $upload->content_disposition,
                'cache_control' => $upload->cache_control,
                'metadata' => $upload->metadata,
                'storage_backend' => 'local',
                'is_multipart' => true,
                'part_count' => count($partNumbers),
                'last_modified' => now(),
            ]);

            $bucket->increment('storage_bytes', $assembled->size);
            $bucket->increment('objects_count');

            $upload->delete();

            return $object;
        });

        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $object;
    }

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    private function assertAscending(array $parts): void
    {
        $previous = 0;

        foreach ($parts as $part) {
            if ($part['PartNumber'] <= $previous) {
                throw new InvalidPartOrderException();
            }

            $previous = $part['PartNumber'];
        }
    }
}
```

- [x] **Step 4: Implement the other three actions and the controller**

`CreateMultipartUpload::handle(Bucket $bucket, ObjectKey $key, ObjectMetadata $metadata, ?AccessKey $accessKey): MultipartUpload` creates the row with `expires_at` set to `now()->addSeconds(config('dero.multipart.abandon_after'))`.

`UploadPart::handle(MultipartUpload $upload, int $partNumber, mixed $stream): MultipartPart` streams the part through `$storage->putPart()` and does an `updateOrCreate` on `(multipart_upload_id, part_number)` so re-uploading a part replaces it. It stores **both** digest forms, because they serve different purposes:

```php
return MultipartPart::updateOrCreate(
    ['multipart_upload_id' => $upload->id, 'part_number' => $partNumber],
    [
        'size_bytes' => $stored->size,
        // Hex for the ETag the client echoes back at completion.
        'etag' => $stored->md5Hex,
        // Raw binary for concatenating into the multipart ETag, which is
        // md5(concat(binary digests)) — hex digests would give a wrong result.
        'checksum_md5' => hex2bin($stored->md5Hex),
        'storage_path' => $stored->path,
    ],
);
```

`AbortMultipartUpload::handle(MultipartUpload $upload): void` calls `$storage->abortMultipart()` then deletes the row (parts cascade).

`MultipartController` renders `InitiateMultipartUploadResult` (Bucket, Key, UploadId), `CompleteMultipartUploadResult` (Location, Bucket, Key, ETag), and `ListPartsResult` (Bucket, Key, UploadId, repeated `Part` with PartNumber, LastModified, ETag, Size). Every method resolves the upload by id **and** asserts `team_id` matches the caller's, throwing `NoSuchUploadException` when absent and `AccessDeniedException` on mismatch.

- [x] **Step 5: Register the routes**

These must be declared **before** the plain object routes from Tasks 19–21, because they are distinguished only by query parameters:

```php
Route::post('/{bucket}/{key}', function (Request $request, string $bucket, string $key) {
    $controller = app(MultipartController::class);

    return match (true) {
        $request->query->has('uploads') => $controller->create($request, $key),
        $request->query->has('uploadId') => $controller->complete($request, $key),
        default => throw new NotImplementedException('POST object'),
    };
})->where('key', '.*');

Route::delete('/{bucket}/{key}', function (Request $request, string $bucket, string $key) {
    return $request->query->has('uploadId')
        ? app(MultipartController::class)->abort($request, $key)
        : app(ObjectController::class)->delete($request, $bucket, $key);
})->where('key', '.*');
```

`PUT /{bucket}/{key}` gains the same treatment: when `partNumber` and `uploadId` are both present it routes to `MultipartController::uploadPart`, otherwise to `ObjectController::put`. `GET /{bucket}/{key}` routes to `listParts` when `uploadId` is present.

- [x] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=MultipartUploadTest`
Expected: PASS, 12 tests. The ETag test is the one that proves AWS compatibility.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes tests
git commit -m "feat: implement multipart uploads with AWS-compatible ETags"
```

---

## Task 22b: ListObjects v1, ListMultipartUploads and UploadPartCopy

The three remaining operations from spec A §9. They are grouped because each is a thin variation on machinery that already exists, and none justifies its own review gate.

**Files:**
- Modify: `app/Http/Controllers/S3/ObjectListController.php`, `app/Http/Controllers/S3/MultipartController.php`, `app/Actions/S3/CopyObject.php`
- Modify: `routes/s3.php`
- Test: `tests/Feature/S3/LegacyListTest.php`, `tests/Feature/S3/ListMultipartUploadsTest.php`, `tests/Feature/S3/UploadPartCopyTest.php`

**Interfaces:**
- Produces: `ObjectListController::listV1(Request): Response`, `MultipartController::listUploads(Request): Response`, `CopyObject::handlePart(S3Object $source, MultipartUpload $upload, int $partNumber, ?RangeHeader $range): MultipartPart`.

- [x] **Step 1: Write the failing tests**

```php
// LegacyListTest — GET /{bucket} with no list-type parameter.
it('serves ListObjects v1 for clients that do not send list-type', function () {
    seedKeys(['a.txt', 'b.txt']);

    $xml = simplexml_load_string(listV1()->getContent());

    // v1 uses Marker/NextMarker rather than continuation tokens, and the
    // root element name is the same. Older clients and Cyberduck rely on this.
    expect($xml->getName())->toBe('ListBucketResult')
        ->and((string) $xml->Marker)->toBe('')
        ->and(count($xml->Contents))->toBe(2);
});

it('paginates v1 with NextMarker', function () {
    seedKeys(['a.txt', 'b.txt', 'c.txt']);

    $first = simplexml_load_string(listV1(['max-keys' => '2'])->getContent());

    expect((string) $first->IsTruncated)->toBe('true')
        ->and((string) $first->NextMarker)->toBe('b.txt');

    $second = simplexml_load_string(listV1(['marker' => 'b.txt'])->getContent());

    expect((string) $second->Contents[0]->Key)->toBe('c.txt');
});

// ListMultipartUploadsTest — GET /{bucket}?uploads
it('lists in-progress multipart uploads', function () {
    $uploadId = createUpload('big.bin');

    $xml = simplexml_load_string(listUploads()->getContent());

    expect($xml->getName())->toBe('ListMultipartUploadsResult')
        ->and((string) $xml->Upload[0]->Key)->toBe('big.bin')
        ->and((string) $xml->Upload[0]->UploadId)->toBe($uploadId);
});

it('does not list another team\'s uploads', function () {
    createUpload('mine.bin');
    \App\Models\MultipartUpload::factory()->create(['key' => 'theirs.bin']);

    expect(listUploads()->getContent())->not->toContain('theirs.bin');
});

// UploadPartCopyTest — PUT part with x-amz-copy-source
it('copies an existing object into a part', function () {
    put('source.bin', str_repeat('s', 5 * 1024 * 1024));
    $uploadId = createUpload('assembled.bin');

    $response = uploadPartCopy($uploadId, 1, '/photos/source.bin');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('<CopyPartResult>')
        ->and(\App\Models\MultipartPart::first()->size_bytes)->toBe(5 * 1024 * 1024);
});

it('copies a byte range of the source into a part', function () {
    put('source.bin', '0123456789');
    $uploadId = createUpload('assembled.bin');

    uploadPartCopy($uploadId, 1, '/photos/source.bin', range: 'bytes=2-5');

    expect(\App\Models\MultipartPart::first()->size_bytes)->toBe(4);
});

it('denies copying a part from another team\'s bucket', function () {
    $other = \App\Models\Bucket::factory()->create(['name' => 'theirs']);
    \App\Models\S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id, 'key' => 'secret.bin',
        'key_hash' => \App\S3\Values\ObjectKey::fromString('secret.bin')->hash(),
    ]);
    $uploadId = createUpload('assembled.bin');

    expect(uploadPartCopy($uploadId, 1, '/theirs/secret.bin')->getStatusCode())->toBe(403);
});
```

- [x] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="LegacyListTest|ListMultipartUploadsTest|UploadPartCopyTest"`
Expected: FAIL.

- [x] **Step 3: Implement ListObjects v1**

`listV1()` reuses `ListObjects` unchanged — the only differences are at the XML boundary. Build a `ListObjectsQuery` whose `startAfter` comes from the `marker` parameter, then render `Marker` (echoed, empty string when absent) and `NextMarker` (the last key returned, only when truncated) in place of `ContinuationToken` / `NextContinuationToken`.

> v1 exists because Cyberduck, older SDKs, and some rclone configurations still
> send it. A bucket that appears empty in one client and full in another is
> almost always this.

- [x] **Step 4: Implement ListMultipartUploads**

`GET /{bucket}?uploads` selects `MultipartUpload` rows for the bucket, ordered by `key` then `created_at`, and renders `ListMultipartUploadsResult` with repeated `Upload` elements carrying `Key`, `UploadId`, `Initiated`, and `StorageClass`. The query filters on `bucket_id`, which is already tenant-scoped by `ResolveBucket` plus `AuthorizeS3Operation`.

- [x] **Step 5: Implement UploadPartCopy**

`CopyObject::handlePart` resolves the source exactly as `CopyObject::handle` does — including the tenant check, which is why it lives on the same class rather than being duplicated. With no `x-amz-copy-source-range` it calls `$storage->copy()` straight into the part path; with a range it opens `$storage->readRange()` and streams that into `$storage->putPart()`. It returns a `MultipartPart` written with the same dual-digest treatment as Task 22's `UploadPart`, so completion computes the correct multipart ETag.

Rendered as `<CopyPartResult><ETag>…</ETag><LastModified>…</LastModified></CopyPartResult>`.

- [x] **Step 6: Register the routes**

Add `uploads` to the `GET /{bucket}` match arm (routing to `listUploads`), make the `default` arm branch on `list-type=2` versus v1, and extend the `PUT /{bucket}/{key}` branch so a request carrying both `partNumber` and `x-amz-copy-source` routes to `uploadPartCopy`.

- [x] **Step 7: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="LegacyListTest|ListMultipartUploadsTest|UploadPartCopyTest"`
Expected: PASS, 7 tests.

- [x] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes tests
git commit -m "feat: implement ListObjects v1, ListMultipartUploads and UploadPartCopy"
```

---

## Task 23: Bucket CORS

**Files:**
- Create: `app/Http/Controllers/S3/BucketCorsController.php`, `app/Http/Middleware/S3/HandleBucketCors.php`, `app/S3/Values/CorsRule.php`
- Modify: `bootstrap/app.php`, `routes/s3.php`
- Test: `tests/Feature/S3/CorsTest.php`

**Interfaces:**
- Produces: `CorsRule` readonly with `allowedOrigins`, `allowedMethods`, `allowedHeaders`, `exposeHeaders`, `maxAgeSeconds`; `CorsRule::matches(string $origin, string $method): bool`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/S3/CorsTest.php` covering: `PUT /{bucket}?cors` stores rules from a `<CORSConfiguration>` body; `GET /{bucket}?cors` returns them; `DELETE /{bucket}?cors` removes them; an `OPTIONS` preflight from an allowed origin returns 200 with `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods`, and `Access-Control-Max-Age`; a preflight from a disallowed origin returns 403; a wildcard origin rule matches any origin; and a normal `GET` with an `Origin` header carries `Access-Control-Allow-Origin` and any configured `Access-Control-Expose-Headers`.

```php
it('answers a preflight from an allowed origin', function () {
    putCors([['AllowedOrigin' => 'https://app.example.com', 'AllowedMethod' => 'GET', 'MaxAgeSeconds' => 3000]]);

    $response = test()->call('OPTIONS', 'http://s3.dero.test/photos/cat.jpg', server: [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($response->headers->get('Access-Control-Max-Age'))->toBe('3000');
});

it('refuses a preflight from an origin that is not allowed', function () {
    putCors([['AllowedOrigin' => 'https://app.example.com', 'AllowedMethod' => 'GET']]);

    $response = test()->call('OPTIONS', 'http://s3.dero.test/photos/cat.jpg', server: [
        'HTTP_ORIGIN' => 'https://evil.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('does not add CORS headers when the bucket has no rules', function () {
    $response = test()->call('OPTIONS', 'http://s3.dero.test/photos/cat.jpg', server: [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=CorsTest`
Expected: FAIL.

- [x] **Step 3: Implement `CorsRule`, the middleware, and the controller**

`HandleBucketCors` runs **after** `ResolveBucket` but must short-circuit `OPTIONS` before `AuthorizeS3Operation`, because a preflight carries no credentials by design. Add it to the group between the two, and have `AuthorizeS3Operation` return early for `OPTIONS`.

The middleware: on `OPTIONS`, find the first matching rule and return 200 with the CORS headers, or 403 when none matches and the bucket has rules, or fall through when the bucket has no rules at all. On any other method carrying an `Origin`, attach `Access-Control-Allow-Origin` and `Access-Control-Expose-Headers` to the response.

`BucketCorsController` uses `XmlReader` for `PUT` and `XmlWriter` for `GET`, persisting to `buckets.cors_rules`.

- [x] **Step 4: Register the routes**

Add `cors` to the `GET /{bucket}` match arm, and:

```php
Route::put('/{bucket}', ...)   // already exists; branch on $request->query->has('cors')
Route::options('/{bucket}/{key}', fn () => response('', 200))->where('key', '.*');
Route::options('/{bucket}', fn () => response('', 200));
```

The `OPTIONS` routes exist only so routing resolves; the middleware produces the actual response.

- [x] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=CorsTest`
Expected: PASS.

- [x] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes bootstrap tests
git commit -m "feat: implement bucket CORS configuration and preflight handling"
```

---

## Task 24: Rate limiting, activity logging and usage recording

**Files:**
- Create: `app/Http/Middleware/S3/EnforceRateLimits.php`, `app/Http/Middleware/S3/RecordS3Activity.php`
- Create: `app/Actions/S3/RecordUsage.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/S3/RateLimitTest.php`, `tests/Feature/S3/ActivityLogTest.php`

**Interfaces:**
- Produces: `audit_logs` and `usage_records` rows written after the response is sent.

- [x] **Step 1: Write the failing tests**

`tests/Feature/S3/RateLimitTest.php`:

```php
it('returns SlowDown rather than 429 when a key exceeds its request rate', function () {
    // S3 SDKs retry on 503 SlowDown with backoff. Many treat 429 as a
    // non-retryable client error, so the status matters.
    config()->set('dero.rate_limits.requests_per_minute', 2);

    signedGet('/');
    signedGet('/');
    $response = signedGet('/');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('<Code>SlowDown</Code>')
        ->and($response->headers->get('Retry-After'))->not->toBeEmpty();
});

it('limits repeated signature failures by source ip', function () {
    config()->set('dero.rate_limits.auth_failures_per_minute', 3);

    for ($i = 0; $i < 4; $i++) {
        $response = badlySignedGet('/');
    }

    expect($response->getStatusCode())->toBe(503);
});

it('does not count successful requests toward the auth-failure limit', function () {
    config()->set('dero.rate_limits.auth_failures_per_minute', 2);

    signedGet('/');
    signedGet('/');
    signedGet('/');

    expect(signedGet('/')->getStatusCode())->toBe(200);
});
```

`tests/Feature/S3/ActivityLogTest.php`:

```php
it('records a successful operation', function () {
    put('cat.jpg', 'bytes');

    $log = \App\Models\AuditLog::latest('id')->first();

    expect($log->action)->toBe('PutObject')
        ->and($log->result)->toBe('success')
        ->and($log->object_key)->toBe('cat.jpg')
        ->and($log->actor_type)->toBe('access_key');
});

it('records a denial with its error code', function () {
    test()->get('http://s3.dero.test/photos');

    $log = \App\Models\AuditLog::latest('id')->first();

    expect($log->result)->toBe('denied')
        ->and($log->error_code)->toBe('AccessDenied');
});

it('never records a secret key', function () {
    put('cat.jpg', 'bytes');

    $logs = \App\Models\AuditLog::all()->toJson();

    expect($logs)->not->toContain(test()->key->secret_key);
});

it('accumulates usage into the current hourly bucket', function () {
    put('a.txt', 'aaaa');
    put('b.txt', 'bb');

    $usage = \App\Models\UsageRecord::where('bucket_id', test()->bucket->id)->first();

    expect($usage->requests)->toBe(2)
        ->and($usage->bytes_uploaded)->toBe(6);
});

it('records download bytes separately from upload bytes', function () {
    put('a.txt', 'aaaa');
    get('a.txt');

    $usage = \App\Models\UsageRecord::where('bucket_id', test()->bucket->id)->first();

    expect($usage->bytes_uploaded)->toBe(4)
        ->and($usage->bytes_downloaded)->toBe(4);
});
```

- [x] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="RateLimitTest|ActivityLogTest"`
Expected: FAIL.

- [x] **Step 3: Implement `EnforceRateLimits`**

Uses Laravel's `RateLimiter`. Two keys: `s3:key:{access_key_id}` at `requests_per_minute`, and `s3:auth-fail:{ip}` at `auth_failures_per_minute`, the latter incremented only when the request throws an authentication exception. Both throw `SlowDownException`; the exception handler adds `Retry-After` from the limiter's `availableIn()`.

Placed **first** in the group after `AssignRequestId`, so a flood is rejected before any database work happens.

- [x] **Step 4: Implement `RecordS3Activity`**

A terminable middleware. `handle()` records the start time and passes through; `terminate()` writes the `audit_logs` row and increments the `usage_records` row for the current hour, using `updateOrCreate` plus atomic increments so concurrent requests do not lose counts.

The action name is derived from the resolved route and query parameters (`PutObject`, `GetObject`, `ListObjectsV2`, `CreateMultipartUpload`, …). The result is `success` for 2xx, `denied` for 403, `error` otherwise; the error code is read from the rendered XML body when present.

> Terminable middleware runs after the response is flushed, so audit writes never
> add latency to a 1 GB download. This is why activity logging is not simply done
> in the controller.

- [x] **Step 5: Add both to the group in order**

```php
$middleware->group('s3', [
    AssignRequestId::class,
    EnforceRateLimits::class,
    RecordS3Activity::class,
    ResolveVirtualHostBucket::class,
    ResolveAccessKey::class,
    VerifyAwsSignature::class,
    ResolveBucket::class,
    HandleBucketCors::class,
    AuthorizeS3Operation::class,
]);
```

- [x] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="RateLimitTest|ActivityLogTest"`
Expected: PASS.

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app bootstrap tests
git commit -m "feat: add S3 rate limiting, audit logging and usage recording"
```

---

## Task 25: Scheduled maintenance

**Files:**
- Create: `app/Jobs/AbandonExpiredMultipartUploads.php`, `app/Jobs/SweepOrphanedTempFiles.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/S3/MaintenanceTest.php`

- [x] **Step 1: Write the failing test**

```php
it('aborts multipart uploads past their expiry and reclaims their parts', function () {
    $upload = \App\Models\MultipartUpload::factory()->create(['expires_at' => now()->subDay()]);
    $fresh = \App\Models\MultipartUpload::factory()->create(['expires_at' => now()->addDay()]);

    (new \App\Jobs\AbandonExpiredMultipartUploads())->handle(app(\App\Storage\Contracts\ObjectStorageBackend::class));

    expect(\App\Models\MultipartUpload::find($upload->id))->toBeNull()
        ->and(\App\Models\MultipartUpload::find($fresh->id))->not->toBeNull();
});

it('removes temp files older than a day', function () {
    $root = config('dero.storage_root');
    mkdir($root.'/tmp', 0o750, true);
    touch($root.'/tmp/stale', now()->subDays(2)->timestamp);
    touch($root.'/tmp/recent');

    (new \App\Jobs\SweepOrphanedTempFiles())->handle();

    expect(file_exists($root.'/tmp/stale'))->toBeFalse()
        ->and(file_exists($root.'/tmp/recent'))->toBeTrue();
});
```

- [x] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=MaintenanceTest`
Expected: FAIL.

- [x] **Step 3: Implement the jobs and schedule them**

In `routes/console.php`:

```php
Schedule::job(new AbandonExpiredMultipartUploads())->hourly();
Schedule::job(new SweepOrphanedTempFiles())->daily();
```

Add a `MultipartUploadFactory` so the test can create uploads.

- [x] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=MaintenanceTest`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database routes tests
git commit -m "feat: schedule multipart and temp-file cleanup"
```

---

## Task 26: Security test suite

No new production code unless a test finds a hole. This task exists to prove the defences already built actually hold.

**Files:**
- Create: `tests/Feature/S3/Security/AttackSurfaceTest.php`

- [x] **Step 1: Write the tests**

```php
it('stores a traversal key as data and never writes outside the storage root', function () {
    put('../../../../tmp/pwned.txt', 'evil');

    $object = \App\Models\S3Object::first();
    $absolute = rtrim(config('dero.storage_root'), '/').'/'.$object->storage_path;

    expect(realpath(dirname($absolute)))->toStartWith(realpath(config('dero.storage_root')))
        ->and(file_exists('/tmp/pwned.txt'))->toBeFalse();
});

it('round-trips a traversal key unchanged', function () {
    put('../../etc/passwd', 'content');

    expect(get('../../etc/passwd')->streamedContent())->toBe('content');
});

it('rejects a key containing a null byte', function () {
    expect(put("bad\0key", 'x')->getStatusCode())->toBe(400);
});

it('does not expand XML entities', function () {
    // Billion-laughs / XXE. The parser must not resolve the entity.
    $body = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<Delete><Object><Key>&xxe;</Key></Object></Delete>';

    $response = postDelete($body);

    expect($response->getContent())->not->toContain('root:');
});

it('rejects an oversized XML body', function () {
    expect(postDelete(str_repeat('<a/>', 500_000))->getStatusCode())->toBe(400);
});

it('denies a presigned url after it expires', function () {
    $url = presign('/photos/cat.jpg', expiresIn: 60);

    travel(61)->seconds();

    expect(test()->get($url)->getStatusCode())->toBe(403);
});

it('denies a presigned url whose query was altered after signing', function () {
    $url = presign('/photos/cat.jpg', expiresIn: 3600);

    expect(test()->get($url.'&extra=1')->getStatusCode())->toBe(403);
});

it('denies a presigned url signed by a key that was later revoked', function () {
    $url = presign('/photos/cat.jpg', expiresIn: 3600);
    test()->key->update(['revoked_at' => now()]);

    expect(test()->get($url)->getStatusCode())->toBe(403);
});

it('denies reading an object in another team\'s bucket', function () {
    $other = \App\Models\Bucket::factory()->create(['name' => 'theirs']);
    \App\Models\S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id, 'key' => 'secret.txt',
        'key_hash' => \App\S3\Values\ObjectKey::fromString('secret.txt')->hash(),
    ]);

    expect(signedGet('/theirs/secret.txt')->getStatusCode())->toBe(403);
});

it('does not leak whether an object exists in another team\'s bucket', function () {
    \App\Models\Bucket::factory()->create(['name' => 'theirs']);

    // Both a present and an absent key must answer identically.
    expect(signedGet('/theirs/exists.txt')->getStatusCode())
        ->toBe(signedGet('/theirs/absent.txt')->getStatusCode());
});

it('rejects a body larger than the configured upload limit', function () {
    config()->set('dero.max_upload_size', 16);

    expect(put('big.bin', str_repeat('a', 64))->getStatusCode())->toBe(400);
});

it('never renders an HTML error page on an s3 route', function () {
    $response = test()->get('http://s3.dero.test/photos/../../etc/passwd');

    expect($response->getContent())->not->toContain('<!DOCTYPE html>');
});
```

`presign()` builds a presigned URL with the query-parameter signing scheme, mirroring what `aws s3 presign` produces.

- [x] **Step 2: Run them**

Run: `php artisan test --compact --filter=AttackSurfaceTest`
Expected: PASS. **Any failure here is a real vulnerability** — fix the production code, never the test.

- [x] **Step 3: Commit**

```bash
git add tests/Feature/S3/Security
git commit -m "test: add S3 attack-surface suite"
```

---

## Task 27: Interoperability suite

The acceptance gate. Real clients, real HTTP. Everything before this proves Dero is self-consistent; only this proves it is S3-compatible.

**Files:**
- Create: `tests/Interop/serve.sh`, `tests/Interop/aws-cli.sh`, `tests/Interop/test_boto3.py`, `tests/Interop/README.md`
- Create: `app/Console/Commands/DeroSeedInteropCommand.php`

**Prerequisites (already installed):** `aws-cli/2.36.15`, `boto3 1.43.63` in `.venv`.

- [x] **Step 1: Add the seeding command**

`php artisan dero:seed-interop` creates a team, a user, an access key with known credentials, and prints them as shell exports. It must refuse to run when `APP_ENV=production`.

- [x] **Step 2: Write the server script**

`tests/Interop/serve.sh` starts `php artisan serve --host=127.0.0.1 --port=8787` against a scratch SQLite database and a scratch storage root, runs migrations, seeds interop credentials, and writes the endpoint to `tests/Interop/.env.interop`. Because the S3 API is host-scoped, it sets `S3_ENDPOINT_HOST=127.0.0.1:8787` so path-style requests to the serve host route correctly.

- [x] **Step 3: Write the AWS CLI script**

`tests/Interop/aws-cli.sh` — `set -euo pipefail`, and every command's output captured and asserted:

```bash
ENDPOINT="http://127.0.0.1:8787"
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION=us-east-1

aws s3 mb s3://interop --endpoint-url "$ENDPOINT"
aws s3 ls --endpoint-url "$ENDPOINT" | grep interop

echo "hello dero" > /tmp/dero-test.txt
aws s3 cp /tmp/dero-test.txt s3://interop/test.txt --endpoint-url "$ENDPOINT"
aws s3 ls s3://interop/ --endpoint-url "$ENDPOINT" | grep test.txt

# Round-trip must be byte-identical.
aws s3 cp s3://interop/test.txt - --endpoint-url "$ENDPOINT" | diff - /tmp/dero-test.txt

# 100 MB exercises the CLI's automatic multipart path and aws-chunked framing.
dd if=/dev/urandom of=/tmp/dero-large.bin bs=1m count=100
aws s3 cp /tmp/dero-large.bin s3://interop/large.bin --endpoint-url "$ENDPOINT"
aws s3 cp s3://interop/large.bin /tmp/dero-large-out.bin --endpoint-url "$ENDPOINT"
[ "$(shasum -a 256 < /tmp/dero-large.bin)" = "$(shasum -a 256 < /tmp/dero-large-out.bin)" ]

# Range request.
curl -sf -H 'Range: bytes=0-9' "$(aws s3 presign s3://interop/large.bin --endpoint-url "$ENDPOINT")" \
  | wc -c | grep -q 10

# Presigned URL fetched with no credentials at all.
curl -sf "$(aws s3 presign s3://interop/test.txt --endpoint-url "$ENDPOINT")" | diff - /tmp/dero-test.txt

mkdir -p /tmp/dero-sync && echo one > /tmp/dero-sync/a.txt && echo two > /tmp/dero-sync/b.txt
aws s3 sync /tmp/dero-sync s3://interop/synced/ --endpoint-url "$ENDPOINT"
aws s3 ls s3://interop/synced/ --endpoint-url "$ENDPOINT" | grep a.txt

aws s3 rm s3://interop/test.txt --endpoint-url "$ENDPOINT"
aws s3 rb s3://interop --force --endpoint-url "$ENDPOINT"
```

- [x] **Step 4: Write the boto3 tests**

`tests/Interop/test_boto3.py` — pytest against the same endpoint, with `config=Config(s3={'addressing_style': 'path'})`. Covers `create_bucket`, `put_object`, `get_object`, `head_object`, the `list_objects_v2` paginator across more than one page, `upload_file` on a 100 MB file (automatic multipart), `generate_presigned_url` fetched with plain `urllib`, `copy_object`, `delete_objects`, and `get_object` with a `Range`. Each assertion compares bytes or checksums, never just a status code.

- [x] **Step 5: Run the full suite**

```bash
tests/Interop/serve.sh &
sleep 3
tests/Interop/aws-cli.sh
.venv/bin/pytest tests/Interop/test_boto3.py -v
```

Expected: every command succeeds. **Capture the actual output** — it is the evidence for the compatibility matrix in Task 29.

> Expect failures on the first run. The most likely causes, in order:
> aws-chunked framing (Task 12), virtual-host vs path addressing (Task 14),
> canonical query encoding (Task 9), and `Content-Length` on streamed responses
> (Task 20). Fix the production code; do not weaken the script to pass.

- [x] **Step 6: Commit**

```bash
git add tests/Interop app/Console/Commands
git commit -m "test: add AWS CLI and boto3 interoperability suite"
```

---

## Task 28: Performance verification

**Files:**
- Create: `tests/Interop/benchmark.sh`, `tests/Feature/S3/MemoryProfileTest.php`

- [x] **Step 1: Write the memory test**

```php
it('keeps peak memory flat as object size grows', function (int $megabytes) {
    $path = tempnam(sys_get_temp_dir(), 'dero');
    file_put_contents($path, str_repeat('x', $megabytes * 1024 * 1024));

    $before = memory_get_peak_usage(true);
    putFromFile('big-'.$megabytes.'.bin', $path);
    $growth = memory_get_peak_usage(true) - $before;

    // The bound is the chunk size plus headroom, NOT a multiple of the object
    // size. A linear relationship here means streaming has regressed.
    expect($growth)->toBeLessThan(48 * 1024 * 1024);

    unlink($path);
})->with([1, 16, 64, 128]);
```

- [x] **Step 2: Run it**

Run: `php artisan test --compact --filter=MemoryProfileTest`
Expected: PASS at every size. A failure at 128 MB but not 1 MB means something buffers the body.

- [x] **Step 3: Write the benchmark script**

`tests/Interop/benchmark.sh` times upload and download of 1 KB, 1 MB, 100 MB, and 1 GB objects through the AWS CLI, reporting elapsed time and throughput per size, and runs 20 concurrent 1 MB uploads to check the server stays responsive.

- [x] **Step 4: Run it and record the numbers**

Run: `tests/Interop/benchmark.sh | tee tests/Interop/benchmark-results.txt`

The recorded figures go into the final report. Report what was measured, on what hardware — no extrapolation.

- [x] **Step 5: Commit**

```bash
git add tests
git commit -m "test: add memory profile and throughput benchmarks"
```

---

## Task 29: Compatibility matrix and architecture documentation

The document that determines whether developers trust this project.

**Files:**
- Create: `docs/s3-compatibility.md`, `docs/architecture.md`
- Modify: `README.md`

- [x] **Step 1: Write the compatibility matrix**

`docs/s3-compatibility.md` with a three-column table (Operation · Supported · Notes). Rules, applied without exception:

- An operation is marked **Supported** only if a Task 27 interop test passed against it. Passing Pest tests alone are not sufficient — they prove self-consistency, not compatibility.
- Unsupported operations are listed explicitly, not omitted. Versioning, object lock, lifecycle, replication, SSE, tagging, ACLs, website hosting, inventory, analytics, notifications, and `SelectObjectContent` all get a row saying they return `NotImplemented`.
- Deliberate deviations get their own section with reasons:
  - Multi-range requests return the whole object (RFC 7233 permits this).
  - Per-chunk `chunk-signature` values in aws-chunked bodies are parsed but not individually verified (Task 12).
  - Cross-tenant access returns `AccessDenied` where AWS returns `NoSuchBucket`.
  - `object_metadata` is a JSON column, not a table.
- A "Verified with" section naming the exact client versions: `aws-cli/2.36.15`, `boto3 1.43.63`.
- **No claim of full S3 compatibility anywhere in the document.**

- [x] **Step 2: Write the architecture document**

`docs/architecture.md`: the layer diagram, the request path through the middleware chain, the storage abstraction and why physical paths derive from ULIDs, the schema with the two MySQL constraints from spec A §5.2, the SigV4 flow, and the `APP_KEY` limitation from spec A §7.4 stated plainly.

- [x] **Step 3: Update the README**

Replace the starter kit's README with Dero's: what it is, the granary the name comes from, quick start, connecting a client, and links into `docs/`.

- [x] **Step 4: Verify every claim**

Re-read the matrix against the Task 27 output. Any row marked Supported without corresponding evidence must be downgraded to "Untested" — that is the whole point of the document.

- [x] **Step 5: Commit**

```bash
git add docs README.md
git commit -m "docs: add S3 compatibility matrix and architecture guide"
```

---

## Final verification

- [x] **Run the whole suite**

```bash
php artisan test --compact
vendor/bin/pint --test --format agent
vendor/bin/phpstan analyse
```

All three clean.

- [x] **Run the interop suite end to end**

```bash
tests/Interop/serve.sh &
sleep 3 && tests/Interop/aws-cli.sh && .venv/bin/pytest tests/Interop/test_boto3.py -v
```

- [x] **Confirm the definition of done from spec A §15**

1. `php artisan test` green.
2. Pint and PHPStan clean.
3. Interop suite passes end to end, output captured.
4. `docs/s3-compatibility.md` reflects only what was proven.
5. Peak memory flat from 1 KB to 1 GB.

Report anything that could not be verified as **not tested**, never as passing.






