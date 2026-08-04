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

    S3Object::factory()->for($this->bucket)->create([
        'team_id' => $this->bucket->team_id,
        'key' => 'cat.jpg',
        'key_hash' => ObjectKey::fromString('cat.jpg')->hash(),
    ]);
});

/**
 * @param  array<string, string>  $query
 */
function unsupported(string $method, string $path, array $query)
{
    $url = 'http://s3.dero.test'.$path.'?'.http_build_query($query);
    $headers = SignsRequests::headers(test()->key, $method, 's3.dero.test', $path, $query);

    return test()->call($method, $url, server: SignsRequests::serverVars($headers));
}

it('answers NotImplemented for an unsupported bucket sub-resource', function (string $method, string $marker) {
    $response = unsupported($method, '/photos', [$marker => '']);

    // Without this the request falls through to the default arm and a client
    // asking about versioning receives a list of objects instead.
    expect($response->getStatusCode())->toBe(501)
        ->and($response->getContent())->toContain('<Code>NotImplemented</Code>')
        ->and($response->getContent())->toContain('<Operation>'.$marker.'</Operation>');
})->with([
    ['GET', 'versioning'],
    ['GET', 'acl'],
    ['GET', 'lifecycle'],
    ['GET', 'policy'],
    ['GET', 'tagging'],
    ['GET', 'encryption'],
    ['GET', 'replication'],
    ['GET', 'website'],
    ['GET', 'notification'],
    ['GET', 'object-lock'],
    ['PUT', 'versioning'],
    ['PUT', 'acl'],
    ['PUT', 'lifecycle'],
    ['PUT', 'policy'],
    ['DELETE', 'lifecycle'],
    ['DELETE', 'policy'],
    ['DELETE', 'tagging'],
]);

it('answers NotImplemented for an unsupported object sub-resource', function (string $method, string $marker) {
    $response = unsupported($method, '/photos/cat.jpg', [$marker => '']);

    expect($response->getStatusCode())->toBe(501)
        ->and($response->getContent())->toContain('<Code>NotImplemented</Code>');
})->with([
    ['GET', 'acl'],
    ['GET', 'tagging'],
    ['GET', 'attributes'],
    ['GET', 'retention'],
    ['GET', 'legal-hold'],
    ['GET', 'torrent'],
    ['PUT', 'acl'],
    ['PUT', 'tagging'],
    ['PUT', 'retention'],
    ['POST', 'restore'],
    ['POST', 'select'],
    ['DELETE', 'tagging'],
]);

it('still serves the operations that are supported', function (string $method, string $path, array $query, int $status) {
    expect(unsupported($method, $path, $query)->getStatusCode())->toBe($status);
})->with([
    ['GET', '/photos', ['list-type' => '2'], 200],
    ['GET', '/photos', ['location' => ''], 200],
    ['GET', '/photos', ['uploads' => ''], 200],
    ['GET', '/photos', ['cors' => ''], 200],
    ['GET', '/photos/cat.jpg', [], 200],
]);
