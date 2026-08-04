<?php

declare(strict_types=1);

use App\S3\Http\ObjectMetadata;
use Illuminate\Http\Request;

function metadataFrom(array $headers): ObjectMetadata
{
    $request = Request::create('https://s3.example.com/b/k', 'PUT');
    $request->headers->replace($headers);

    return ObjectMetadata::fromRequest($request);
}

it('defaults the content type when none is supplied', function () {
    expect(metadataFrom([])->contentType)->toBe('binary/octet-stream');
});

it('keeps a supplied content type', function () {
    expect(metadataFrom(['content-type' => 'application/pdf'])->contentType)->toBe('application/pdf');
});

it('collects x-amz-meta headers with the prefix stripped', function () {
    $metadata = metadataFrom([
        'x-amz-meta-owner' => 'ambrose',
        'x-amz-meta-project' => 'dero',
        'x-not-meta' => 'ignored',
    ]);

    expect($metadata->userMetadata)->toBe(['owner' => 'ambrose', 'project' => 'dero']);
});

it('drops aws-chunked from the content encoding', function () {
    // aws-chunked describes the transfer framing, which the decoder removes.
    // Persisting it would make every GET claim an encoding the bytes lack.
    expect(metadataFrom(['content-encoding' => 'aws-chunked'])->contentEncoding)->toBeNull();
});

it('preserves a genuine encoding alongside aws-chunked', function () {
    expect(metadataFrom(['content-encoding' => 'gzip, aws-chunked'])->contentEncoding)->toBe('gzip');
});

it('leaves an unrelated content encoding alone', function () {
    expect(metadataFrom(['content-encoding' => 'gzip'])->contentEncoding)->toBe('gzip');
});

it('captures the remaining cacheable headers', function () {
    $metadata = metadataFrom([
        'cache-control' => 'max-age=3600',
        'content-disposition' => 'attachment; filename="r.pdf"',
        'expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
    ]);

    expect($metadata->cacheControl)->toBe('max-age=3600')
        ->and($metadata->contentDisposition)->toBe('attachment; filename="r.pdf"')
        ->and($metadata->expires)->toBe('Wed, 21 Oct 2026 07:28:00 GMT');
});
