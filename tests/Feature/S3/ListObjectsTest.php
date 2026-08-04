<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

/**
 * @param  array<int, string>  $keys
 */
function seedKeys(array $keys, ?Bucket $bucket = null): void
{
    $bucket ??= test()->bucket;

    foreach ($keys as $key) {
        S3Object::factory()->for($bucket)->withKey($key)->create(['team_id' => $bucket->team_id]);
    }
}

/**
 * @param  array<string, string>  $query
 */
function listObjects(array $query = [])
{
    $query = ['list-type' => '2', ...$query];
    $path = '/photos';
    $url = 'http://s3.dero.test'.$path.'?'.http_build_query($query);
    $headers = SignsRequests::headers(test()->key, 'GET', 's3.dero.test', $path, $query);

    return test()->call('GET', $url, server: SignsRequests::serverVars($headers));
}

it('lists keys in bytewise lexicographic order', function () {
    seedKeys(['b.txt', 'a.txt', 'C.txt']);

    $xml = simplexml_load_string(listObjects()->getContent());
    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($xml->Contents, false));

    // Uppercase sorts before lowercase in bytewise order. A case-insensitive
    // collation would give a.txt, b.txt, C.txt and silently break pagination.
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
        ->and((string) $xml->Contents[0]->LastModified)->not->toBeEmpty()
        ->and((string) $xml->Contents[0]->StorageClass)->toBe('STANDARD');
});

it('quotes the ETag as S3 does', function () {
    seedKeys(['a.txt']);

    expect(listObjects()->getContent())->toMatch('/<ETag>&quot;[0-9a-f]{32}&quot;<\/ETag>/');
});

it('filters by prefix', function () {
    seedKeys(['photos/a.jpg', 'photos/b.jpg', 'docs/c.pdf']);

    $xml = simplexml_load_string(listObjects(['prefix' => 'photos/'])->getContent());

    expect(\count($xml->Contents))->toBe(2);
});

it('treats a prefix containing LIKE wildcards literally', function () {
    seedKeys(['100%/a.txt', '1000/b.txt']);

    $xml = simplexml_load_string(listObjects(['prefix' => '100%/'])->getContent());

    expect(\count($xml->Contents))->toBe(1)
        ->and((string) $xml->Contents[0]->Key)->toBe('100%/a.txt');
});

it('rolls keys up into common prefixes when a delimiter is given', function () {
    seedKeys(['a/1.txt', 'a/2.txt', 'b/1.txt', 'top.txt']);

    $xml = simplexml_load_string(listObjects(['delimiter' => '/'])->getContent());
    $prefixes = array_map(fn ($p) => (string) $p->Prefix, iterator_to_array($xml->CommonPrefixes, false));

    expect($prefixes)->toBe(['a/', 'b/'])
        ->and(\count($xml->Contents))->toBe(1)
        ->and((string) $xml->Contents[0]->Key)->toBe('top.txt');
});

it('does not double-count a common prefix', function () {
    seedKeys(['a/1.txt', 'a/2.txt', 'a/3.txt']);

    $xml = simplexml_load_string(listObjects(['delimiter' => '/'])->getContent());

    expect(\count($xml->CommonPrefixes))->toBe(1);
});

it('truncates at max-keys and offers a continuation token', function () {
    seedKeys(['a.txt', 'b.txt', 'c.txt', 'd.txt']);

    $xml = simplexml_load_string(listObjects(['max-keys' => '2'])->getContent());

    expect(\count($xml->Contents))->toBe(2)
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

    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($second->Contents, false));

    expect($keys)->toBe(['c.txt', 'd.txt'])
        ->and((string) $second->IsTruncated)->toBe('false');
});

it('paginates the whole set without skipping or repeating a key', function () {
    $expected = collect(range(1, 25))->map(fn (int $i) => \sprintf('key-%02d.txt', $i))->all();
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
    $keys = array_map(fn ($c) => (string) $c->Key, iterator_to_array($xml->Contents, false));

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
        ->and(\count($xml->Contents))->toBe(0);
});

it('never lists objects from another bucket', function () {
    seedKeys(['mine.txt']);

    $other = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'other']);
    seedKeys(['theirs.txt'], $other);

    expect(listObjects()->getContent())->not->toContain('theirs.txt');
});
