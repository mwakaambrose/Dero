<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\MalformedXmlException;
use App\S3\Values\CorsRule;
use App\S3\Xml\XmlReader;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The ?cors sub-resource: GetBucketCors, PutBucketCors, DeleteBucketCors.
 */
final class BucketCorsController extends Controller
{
    private const string XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';

    /**
     * PUT /{bucket}?cors
     */
    public function put(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $parsed = XmlReader::parse($request->getContent());
        $rules = [];

        foreach (XmlReader::repeated($parsed, 'CORSRule') as $entry) {
            if (! \is_array($entry)) {
                throw new MalformedXmlException;
            }

            $rules[] = (new CorsRule(
                allowedOrigins: $this->values($entry, 'AllowedOrigin'),
                allowedMethods: array_map(strtoupper(...), $this->values($entry, 'AllowedMethod')),
                allowedHeaders: $this->values($entry, 'AllowedHeader'),
                exposeHeaders: $this->values($entry, 'ExposeHeader'),
                maxAgeSeconds: isset($entry['MaxAgeSeconds']) ? (int) $entry['MaxAgeSeconds'] : null,
            ))->toArray();
        }

        if ($rules === []) {
            throw new MalformedXmlException;
        }

        $bucket->update(['cors_rules' => $rules]);

        return new Response('', 200);
    }

    /**
     * GET /{bucket}?cors
     */
    public function get(Request $request): Response
    {
        $bucket = $this->bucket($request);

        $rules = array_map(function (array $rule): array {
            $cors = CorsRule::fromArray($rule);

            return [
                'AllowedOrigin' => $cors->allowedOrigins,
                'AllowedMethod' => $cors->allowedMethods,
                'AllowedHeader' => $cors->allowedHeaders === [] ? null : $cors->allowedHeaders,
                'ExposeHeader' => $cors->exposeHeaders === [] ? null : $cors->exposeHeaders,
                'MaxAgeSeconds' => $cors->maxAgeSeconds,
            ];
        }, $bucket->cors_rules ?? []);

        $xml = XmlWriter::render('CORSConfiguration', [
            'CORSRule' => $rules,
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * DELETE /{bucket}?cors
     */
    public function delete(Request $request): Response
    {
        $this->bucket($request)->update(['cors_rules' => null]);

        return new Response('', 204);
    }

    /**
     * Read a repeated child element as a list of strings.
     *
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    private function values(array $rule, string $element): array
    {
        $value = $rule[$element] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map(strval(...), (array) $value));
    }

    private function bucket(Request $request): Bucket
    {
        $bucket = $request->attributes->get('s3.bucket');

        if (! $bucket instanceof Bucket) {
            throw new AccessDeniedException;
        }

        return $bucket;
    }
}
