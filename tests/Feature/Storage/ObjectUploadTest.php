<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\MultipartUpload;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\UsageRecord;
use App\Models\User;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Http\UploadedFile;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->user = User::factory()->create();
    $this->team = Team::create(['name' => 'Harvest', 'slug' => 'harvest']);
    $this->team->members()->attach($this->user->id, ['role' => TeamRole::Owner->value]);
    $this->user->forceFill(['current_team_id' => $this->team->id])->save();

    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'photos',
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

/**
 * @param  array<string, mixed>  $extra
 */
function dashUpload(string $key, string $body, array $extra = [])
{
    return test()->actingAs(test()->user)->post('/harvest/buckets/photos/objects', [
        'file' => UploadedFile::fake()->createWithContent(basename($key), $body),
        'key' => $key,
        ...$extra,
    ]);
}

it('uploads an object and returns its ETag', function () {
    $response = dashUpload('cat.jpg', 'image-bytes');

    $response->assertCreated()->assertJson([
        'key' => 'cat.jpg',
        'etag' => md5('image-bytes'),
        'sizeBytes' => 11,
    ]);

    $object = S3Object::query()->where('key', 'cat.jpg')->first();

    expect($object->bucket_id)->toBe($this->bucket->id)
        ->and($object->checksum_sha256)->toBe(hash('sha256', 'image-bytes'))
        ->and($object->storage_backend)->toBe('local');
});

it('stores bytes the object read path serves back unchanged', function () {
    dashUpload('cat.jpg', 'image-bytes');

    $object = S3Object::query()->where('key', 'cat.jpg')->first();
    $stream = app(ObjectStorageBackend::class)->readStream($object->storage_path);

    expect(stream_get_contents($stream))->toBe('image-bytes');
});

it('produces the same object as the S3 API given the same bytes', function () {
    $body = str_repeat('parity', 500);

    // The dashboard upload goes first: calling the data plane rebases the test
    // client's URL root on the endpoint host, after which a relative dashboard
    // path would be routed as an S3 request.
    dashUpload('cat.jpg', $body, ['contentType' => 'image/jpeg'])->assertCreated();

    // Now the same bytes through the signed data plane, into a second bucket.
    $accessKey = AccessKey::factory()->create(['team_id' => $this->team->id, 'user_id' => $this->user->id]);
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'mirror']);

    $signed = SignsRequests::headers(
        $accessKey, 'PUT', 's3.dero.test', '/mirror/cat.jpg',
        extraHeaders: ['Content-Type' => 'image/jpeg'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );

    $this->call('PUT', 'http://s3.dero.test/mirror/cat.jpg',
        server: SignsRequests::serverVars($signed), content: $body,
    )->assertOk();

    $viaApi = S3Object::query()->where('key', 'cat.jpg')->where('bucket_id', '!=', $this->bucket->id)->first();
    $viaDashboard = S3Object::query()->where('bucket_id', $this->bucket->id)->first();

    expect($viaDashboard->etag)->toBe($viaApi->etag)
        ->and($viaDashboard->checksum_sha256)->toBe($viaApi->checksum_sha256)
        ->and($viaDashboard->size_bytes)->toBe($viaApi->size_bytes)
        ->and($viaDashboard->content_type)->toBe($viaApi->content_type)
        ->and($viaDashboard->is_multipart)->toBe($viaApi->is_multipart);
});

it('defaults the content type the way the S3 API defaults it', function () {
    dashUpload('notes', 'plain', ['contentType' => '']);

    expect(S3Object::query()->where('key', 'notes')->first()->content_type)
        ->toBe('binary/octet-stream');
});

it('stores user metadata under lower-cased names', function () {
    dashUpload('cat.jpg', 'bytes', ['metadata' => ['Owner' => 'ada', 'Project' => 'atlas']]);

    expect(S3Object::query()->where('key', 'cat.jpg')->first()->metadata)
        ->toBe(['owner' => 'ada', 'project' => 'atlas']);
});

it('overwrites an existing key without double-counting the bucket', function () {
    dashUpload('cat.jpg', 'first version');
    dashUpload('cat.jpg', 'second');

    $this->bucket->refresh();

    expect(S3Object::query()->where('bucket_id', $this->bucket->id)->count())->toBe(1)
        ->and($this->bucket->objects_count)->toBe(1)
        ->and($this->bucket->storage_bytes)->toBe(6);
});

it('keeps the key the folder drop supplied', function () {
    dashUpload('photos/2026/a.jpg', 'bytes')->assertCreated();

    expect(S3Object::query()->where('key', 'photos/2026/a.jpg')->exists())->toBeTrue();
});

it('records the upload on the activity feed and usage counters', function () {
    dashUpload('cat.jpg', 'image-bytes');

    $log = AuditLog::query()->where('action', 'PutObject')->first();

    expect($log->actor_type)->toBe('user')
        ->and($log->object_key)->toBe('cat.jpg')
        ->and(UsageRecord::query()->sum('bytes_uploaded'))->toBe(11);
});

it('rejects an upload past the configured maximum', function () {
    config()->set('dero.max_upload_size', 4);

    dashUpload('cat.jpg', 'more than four bytes')->assertStatus(400);

    expect(S3Object::query()->count())->toBe(0);
});

it('creates an empty folder as a zero-byte marker', function () {
    $this->actingAs($this->user)
        ->post('/harvest/buckets/photos/folders', ['key' => 'drafts'])
        ->assertCreated();

    $object = S3Object::query()->where('key', 'drafts/')->first();

    expect($object)->not->toBeNull()
        ->and($object->size_bytes)->toBe(0)
        ->and($object->content_type)->toBe('application/x-directory');
});

it('shows an empty folder as a prefix rather than a nameless file', function () {
    $this->actingAs($this->user)->post('/harvest/buckets/photos/folders', ['key' => 'drafts']);

    $this->actingAs($this->user)
        ->get('/harvest/buckets/photos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folders.0.prefix', 'drafts/')
            ->where('files', []));

    // And inside it, the marker is not listed as a file of its own.
    $this->actingAs($this->user)
        ->get('/harvest/buckets/photos?prefix=drafts/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('files', []));
});

it('downloads an object with its stored bytes and content type', function () {
    dashUpload('cat.jpg', 'image-bytes', ['contentType' => 'image/jpeg']);

    $response = $this->actingAs($this->user)
        ->get('/harvest/buckets/photos/object?key=cat.jpg')
        ->assertOk();

    expect($response->streamedContent())->toBe('image-bytes')
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Content-Disposition'))->toContain('cat.jpg');
});

it('deletes an object and gives the bucket its space back', function () {
    dashUpload('cat.jpg', 'image-bytes');

    $this->actingAs($this->user)
        ->delete('/harvest/buckets/photos/object?key=cat.jpg')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->bucket->refresh();

    expect(S3Object::query()->count())->toBe(0)
        ->and($this->bucket->objects_count)->toBe(0)
        ->and($this->bucket->storage_bytes)->toBe(0)
        ->and(AuditLog::query()->where('action', 'DeleteObject')->exists())->toBeTrue();
});

it('answers a delete from the page with a redirect, not JSON', function () {
    dashUpload('cat.jpg', 'image-bytes');

    // The browser deletes through Inertia, which rejects a plain JSON body.
    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->delete('/harvest/buckets/photos/object?key=cat.jpg')
        ->assertRedirect();
});

it('answers 404 for a key that is not in the bucket', function () {
    $this->actingAs($this->user)->get('/harvest/buckets/photos/object?key=missing.jpg')->assertNotFound();
    $this->actingAs($this->user)->delete('/harvest/buckets/photos/object?key=missing.jpg')->assertNotFound();
});

it('does not reach into another team\'s bucket', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    Bucket::factory()->create(['team_id' => $other->id, 'name' => 'rival-data']);

    $this->actingAs($this->user)->post('/harvest/buckets/rival-data/objects', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'bytes'),
        'key' => 'a.txt',
    ])->assertNotFound();

    expect(S3Object::query()->count())->toBe(0);
});

it('keeps a non-member out', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->post('/harvest/buckets/photos/objects', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'bytes'),
        'key' => 'a.txt',
    ])->assertForbidden();
});

describe('chunked uploads', function () {
    beforeEach(function () {
        // Real parts are 5 MiB minimum; the rule is exercised on its own below
        // rather than by moving megabytes through every test.
        config()->set('dero.multipart.min_part_size', 4);
    });

    function dashBeginUpload(array $extra = []): string
    {
        return test()->actingAs(test()->user)
            ->postJson('/harvest/buckets/photos/uploads', ['key' => 'big.bin', ...$extra])
            ->assertCreated()
            ->json('uploadId');
    }

    function dashSendPart(string $uploadId, int $number, string $body): string
    {
        return test()->actingAs(test()->user)
            ->post("/harvest/buckets/photos/uploads/{$uploadId}", [
                'part' => UploadedFile::fake()->createWithContent('part', $body),
                'partNumber' => $number,
            ])
            ->assertOk()
            ->json('etag');
    }

    it('assembles the parts into one object', function () {
        $uploadId = dashBeginUpload(['contentType' => 'application/octet-stream']);

        $first = dashSendPart($uploadId, 1, 'aaaaaaaa');
        $second = dashSendPart($uploadId, 2, 'bbbb');

        $this->actingAs($this->user)
            ->postJson("/harvest/buckets/photos/uploads/{$uploadId}/complete", [
                'parts' => [
                    ['partNumber' => 1, 'etag' => $first],
                    ['partNumber' => 2, 'etag' => $second],
                ],
            ])
            ->assertCreated()
            ->assertJson(['key' => 'big.bin', 'sizeBytes' => 12]);

        $object = S3Object::query()->where('key', 'big.bin')->first();
        $stream = app(ObjectStorageBackend::class)->readStream($object->storage_path);

        expect(stream_get_contents($stream))->toBe('aaaaaaaabbbb')
            ->and($object->is_multipart)->toBeTrue();
    });

    it('produces the multipart ETag shape the AWS CLI produces', function () {
        $uploadId = dashBeginUpload();

        $first = dashSendPart($uploadId, 1, 'aaaaaaaa');
        $second = dashSendPart($uploadId, 2, 'bbbb');

        $this->actingAs($this->user)->postJson("/harvest/buckets/photos/uploads/{$uploadId}/complete", [
            'parts' => [
                ['partNumber' => 1, 'etag' => $first],
                ['partNumber' => 2, 'etag' => $second],
            ],
        ]);

        // md5 over the concatenated raw part digests, suffixed with the count.
        $expected = md5(hex2bin(md5('aaaaaaaa')).hex2bin(md5('bbbb'))).'-2';

        expect(S3Object::query()->where('key', 'big.bin')->first()->etag)->toBe($expected);
    });

    it('carries the metadata given at the start onto the finished object', function () {
        $uploadId = dashBeginUpload([
            'contentType' => 'application/x-tar',
            'metadata' => ['Owner' => 'ada'],
        ]);

        $etag = dashSendPart($uploadId, 1, 'aaaaaaaa');

        $this->actingAs($this->user)->postJson("/harvest/buckets/photos/uploads/{$uploadId}/complete", [
            'parts' => [['partNumber' => 1, 'etag' => $etag]],
        ])->assertCreated();

        $object = S3Object::query()->where('key', 'big.bin')->first();

        expect($object->content_type)->toBe('application/x-tar')
            ->and($object->metadata)->toBe(['owner' => 'ada']);
    });

    it('refuses a part whose ETag does not match what was stored', function () {
        $uploadId = dashBeginUpload();
        dashSendPart($uploadId, 1, 'aaaaaaaa');

        $this->actingAs($this->user)
            ->postJson("/harvest/buckets/photos/uploads/{$uploadId}/complete", [
                'parts' => [['partNumber' => 1, 'etag' => md5('something else')]],
            ])
            ->assertStatus(400);

        expect(S3Object::query()->count())->toBe(0);
    });

    it('enforces the minimum size on every part but the last', function () {
        config()->set('dero.multipart.min_part_size', 5 * 1024 * 1024);

        $uploadId = dashBeginUpload();
        $first = dashSendPart($uploadId, 1, 'too small');
        $second = dashSendPart($uploadId, 2, 'last');

        $this->actingAs($this->user)
            ->postJson("/harvest/buckets/photos/uploads/{$uploadId}/complete", [
                'parts' => [
                    ['partNumber' => 1, 'etag' => $first],
                    ['partNumber' => 2, 'etag' => $second],
                ],
            ])
            ->assertStatus(400);
    });

    it('abandons an upload and its parts on abort', function () {
        $uploadId = dashBeginUpload();
        dashSendPart($uploadId, 1, 'aaaaaaaa');

        $this->actingAs($this->user)
            ->delete("/harvest/buckets/photos/uploads/{$uploadId}")
            ->assertOk();

        expect(MultipartUpload::query()->count())->toBe(0)
            ->and(S3Object::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'AbortMultipartUpload')->exists())->toBeTrue();
    });

    it('does not accept parts for an upload in another bucket', function () {
        $uploadId = dashBeginUpload();

        Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'elsewhere']);

        $this->actingAs($this->user)
            ->post("/harvest/buckets/elsewhere/uploads/{$uploadId}", [
                'part' => UploadedFile::fake()->createWithContent('part', 'aaaa'),
                'partNumber' => 1,
            ])
            ->assertNotFound();
    });
});
