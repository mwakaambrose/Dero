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

        // ListBuckets has no bucket whose visibility could grant anonymous
        // access, so it always requires credentials.
        if (! $key instanceof AccessKey) {
            throw new AccessDeniedException;
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
            'Buckets' => $buckets === [] ? null : ['Bucket' => $buckets],
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
