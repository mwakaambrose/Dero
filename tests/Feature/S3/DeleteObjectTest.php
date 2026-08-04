<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Storage\Contracts\ObjectStorageBackend;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

function store(string $key, string $body = 'x')
{
    $path = '/photos/'.$key;
    $signed = SignsRequests::headers(test()->key, 'PUT', 's3.dero.test', $path);

    return test()->call('PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed), content: $body,
    );
}

function removeObject(string $key, ?AccessKey $as = null)
{
    $path = '/photos/'.$key;
    $signed = SignsRequests::headers($as ?? test()->key, 'DELETE', 's3.dero.test', $path);

    return test()->call('DELETE', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed),
    );
}

function deleteBatch(string $body)
{
    $path = '/photos';
    $query = ['delete' => ''];
    $signed = SignsRequests::headers(
        test()->key, 'POST', 's3.dero.test', $path, $query,
        extraHeaders: ['content-type' => 'application/xml'],
    );

    return test()->call('POST', 'http://s3.dero.test'.$path.'?delete',
        server: SignsRequests::serverVars($signed), content: $body,
    );
}

it('deletes an object and its bytes', function () {
    store('cat.jpg');
    $path = S3Object::query()->sole()->storage_path;

    expect(removeObject('cat.jpg')->getStatusCode())->toBe(204)
        ->and(S3Object::query()->count())->toBe(0)
        ->and(app(ObjectStorageBackend::class)->exists($path))->toBeFalse();
});

it('returns 204 when deleting a key that does not exist', function () {
    // S3 DeleteObject is idempotent. Returning 404 breaks `aws s3 sync`.
    expect(removeObject('never-existed.jpg')->getStatusCode())->toBe(204);
});

it('decrements the bucket counters', function () {
    store('a.txt', 'aaaa');
    removeObject('a.txt');

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(0)
        ->and($bucket->storage_bytes)->toBe(0);
});

it('deletes only the named key', function () {
    store('a.txt');
    store('b.txt');

    removeObject('a.txt');

    expect(S3Object::query()->pluck('key')->all())->toBe(['b.txt']);
});

it('denies a delete from a key without the delete permission', function () {
    store('a.txt');

    $readOnly = AccessKey::factory()
        ->withPermissions([AccessKeyPermission::Read])
        ->create(['team_id' => $this->key->team_id]);

    expect(removeObject('a.txt', $readOnly)->getStatusCode())->toBe(403)
        ->and(S3Object::query()->count())->toBe(1);
});

it('deletes several keys in one batch', function () {
    store('a.txt');
    store('b.txt');
    store('c.txt');

    $response = deleteBatch(
        '<Delete><Object><Key>a.txt</Key></Object><Object><Key>b.txt</Key></Object></Delete>'
    );

    $response->assertOk();
    expect(S3Object::query()->pluck('key')->all())->toBe(['c.txt']);
});

it('reports each deleted key in the batch result', function () {
    store('a.txt');

    $xml = simplexml_load_string(
        deleteBatch('<Delete><Object><Key>a.txt</Key></Object></Delete>')->getContent()
    );

    expect($xml->getName())->toBe('DeleteResult')
        ->and((string) $xml->Deleted[0]->Key)->toBe('a.txt');
});

it('handles a batch containing exactly one object', function () {
    // SimpleXML collapses a single repeated element to a scalar rather than a
    // one-element list, so this takes a different parsing path from a batch of
    // two and must be exercised separately.
    store('only.txt');

    deleteBatch('<Delete><Object><Key>only.txt</Key></Object></Delete>');

    expect(S3Object::query()->count())->toBe(0);
});

it('omits the deleted list in quiet mode', function () {
    store('a.txt');

    $body = deleteBatch(
        '<Delete><Quiet>true</Quiet><Object><Key>a.txt</Key></Object></Delete>'
    )->getContent();

    expect($body)->not->toContain('<Deleted>')
        ->and(S3Object::query()->count())->toBe(0);
});

it('reports a bad key as an error without failing the whole batch', function () {
    store('good.txt');

    $xml = simplexml_load_string(deleteBatch(
        '<Delete><Object><Key></Key></Object><Object><Key>good.txt</Key></Object></Delete>'
    )->getContent());

    expect((string) $xml->Deleted[0]->Key)->toBe('good.txt')
        ->and(\count($xml->Error))->toBe(1)
        ->and(S3Object::query()->count())->toBe(0);
});

it('rejects a malformed batch body', function () {
    $response = deleteBatch('<Delete><Object>');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getContent())->toContain('<Code>MalformedXML</Code>');
});

it('does not expand external entities in a batch body', function () {
    store('a.txt');

    $body = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<Delete><Object><Key>&xxe;</Key></Object></Delete>';

    $response = deleteBatch($body);

    expect($response->getContent())->not->toContain('root:')
        ->and(S3Object::query()->count())->toBe(1);
});

it('reports NotImplemented for a POST without the delete parameter', function () {
    $path = '/photos';
    $signed = SignsRequests::headers($this->key, 'POST', 's3.dero.test', $path);

    $response = test()->call('POST', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed),
    );

    expect($response->getStatusCode())->toBe(501)
        ->and($response->getContent())->toContain('<Code>NotImplemented</Code>');
});
