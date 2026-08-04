<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
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
function seedLegacyKeys(array $keys): void
{
    foreach ($keys as $key) {
        S3Object::factory()->for(test()->bucket)->create([
            'team_id' => test()->bucket->team_id,
            'key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
        ]);
    }
}

/**
 * GET /photos with no list-type parameter — the v1 listing.
 *
 * @param  array<string, string>  $query
 */
function listV1(array $query = [])
{
    $path = '/photos';
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers(test()->key, 'GET', 's3.dero.test', $path, $query);

    return test()->call('GET', $url, server: SignsRequests::serverVars($headers));
}

it('serves ListObjects v1 for clients that do not send list-type', function () {
    seedLegacyKeys(['a.txt', 'b.txt']);

    $xml = simplexml_load_string(listV1()->getContent());

    // v1 uses Marker/NextMarker rather than continuation tokens, and the
    // root element name is the same. Older clients and Cyberduck rely on this.
    expect($xml->getName())->toBe('ListBucketResult')
        ->and((string) $xml->Marker)->toBe('')
        ->and(count($xml->Contents))->toBe(2);
});

it('does not emit v2-only elements in a v1 response', function () {
    seedLegacyKeys(['a.txt']);

    $body = listV1()->getContent();

    expect($body)->not->toContain('<KeyCount>')
        ->and($body)->not->toContain('<ContinuationToken>')
        ->and($body)->not->toContain('<NextContinuationToken>');
});

it('paginates v1 with NextMarker', function () {
    seedLegacyKeys(['a.txt', 'b.txt', 'c.txt']);

    $first = simplexml_load_string(listV1(['max-keys' => '2'])->getContent());

    expect((string) $first->IsTruncated)->toBe('true')
        ->and((string) $first->NextMarker)->toBe('b.txt');

    $second = simplexml_load_string(listV1(['marker' => 'b.txt'])->getContent());

    expect((string) $second->Contents[0]->Key)->toBe('c.txt');
});

it('echoes the marker it was given', function () {
    seedLegacyKeys(['a.txt', 'b.txt', 'c.txt']);

    $xml = simplexml_load_string(listV1(['marker' => 'a.txt'])->getContent());

    expect((string) $xml->Marker)->toBe('a.txt')
        ->and((string) $xml->IsTruncated)->toBe('false');
});

it('rolls keys up into common prefixes with a delimiter', function () {
    seedLegacyKeys(['2026/a.txt', '2026/b.txt', 'root.txt']);

    $xml = simplexml_load_string(listV1(['delimiter' => '/'])->getContent());

    expect((string) $xml->CommonPrefixes[0]->Prefix)->toBe('2026/')
        ->and(count($xml->Contents))->toBe(1);
});

it('still serves v2 when list-type=2 is present', function () {
    seedLegacyKeys(['a.txt']);

    $xml = simplexml_load_string(listV1(['list-type' => '2'])->getContent());

    expect((string) $xml->KeyCount)->toBe('1');
});
