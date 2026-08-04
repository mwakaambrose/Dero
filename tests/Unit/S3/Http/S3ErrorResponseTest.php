<?php

declare(strict_types=1);

use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\BucketNotEmptyException;
use App\S3\Exceptions\EntityTooLargeException;
use App\S3\Exceptions\InternalErrorException;
use App\S3\Exceptions\InvalidRangeException;
use App\S3\Exceptions\NoSuchBucketException;
use App\S3\Exceptions\NoSuchKeyException;
use App\S3\Exceptions\NotImplementedException;
use App\S3\Exceptions\SignatureDoesNotMatchException;
use App\S3\Exceptions\SlowDownException;
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

it('produces a body that parses as valid XML', function () {
    $xml = simplexml_load_string(
        S3ErrorResponse::fromException(new NoSuchKeyException('a & b.txt'), 'R')->getContent()
    );

    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('Error')
        ->and((string) $xml->Key)->toBe('a & b.txt');
});

it('maps each exception to its documented code and status', function (string $class, array $args, string $code, int $status) {
    $response = S3ErrorResponse::fromException(new $class(...$args), 'R');

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getContent())->toContain('<Code>'.$code.'</Code>');
})->with([
    [NoSuchKeyException::class, ['a.txt'], 'NoSuchKey', 404],
    [AccessDeniedException::class, [], 'AccessDenied', 403],
    [SignatureDoesNotMatchException::class, [], 'SignatureDoesNotMatch', 403],
    [BucketNotEmptyException::class, ['photos'], 'BucketNotEmpty', 409],
    [EntityTooLargeException::class, [100, 50], 'EntityTooLarge', 400],
    [InvalidRangeException::class, ['bytes=99-'], 'InvalidRange', 416],
    [NotImplementedException::class, ['PutBucketVersioning'], 'NotImplemented', 501],
    [SlowDownException::class, [], 'SlowDown', 503],
    [InternalErrorException::class, [], 'InternalError', 500],
]);

it('renders the extra context elements each exception carries', function () {
    $body = S3ErrorResponse::fromException(new EntityTooLargeException(100, 50), 'R')->getContent();

    expect($body)->toContain('<ProposedSize>100</ProposedSize>')
        ->and($body)->toContain('<MaxSizeAllowed>50</MaxSizeAllowed>');
});

it('never leaks a secret in the rendered body', function () {
    expect(S3ErrorResponse::fromException(new SignatureDoesNotMatchException, 'R')->getContent())
        ->not->toContain('secret');
});
