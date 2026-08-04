<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ListObjectsQuery;
use App\S3\Values\ListObjectsResult;
use App\S3\Values\ObjectKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ListObjects
{
    public function handle(Bucket $bucket, ListObjectsQuery $query): ListObjectsResult
    {
        $builder = S3Object::query()
            ->where('bucket_id', $bucket->id)
            ->orderBy('key');

        if ($query->prefix !== '') {
            // ESCAPE must be stated explicitly. MySQL treats backslash as the
            // LIKE escape character by default but SQLite does not, so
            // where('key', 'like', ...) alone would let a prefix containing %
            // or _ over-match on SQLite.
            //
            // "key" is a reserved word, so the column is quoted per driver, and
            // the SQL is kept literal rather than assembled from the grammar.
            $builder->whereRaw(
                match (DB::connection()->getDriverName()) {
                    'mysql', 'mariadb' => "`key` LIKE ? ESCAPE '!'",
                    default => '"key" LIKE ? ESCAPE \'!\'',
                },
                [$this->escapeLike($query->prefix).'%'],
            );
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
        // of rows needed to fill a page is not known in advance. Walk in chunks
        // until the page is full rather than fetching maxKeys rows and hoping.
        $builder->chunk(500, function (Collection $chunk) use (
            $query, &$objects, &$commonPrefixes, &$truncated, &$lastKey
        ): bool {
            foreach ($chunk as $object) {
                if (\count($objects) + \count($commonPrefixes) >= $query->maxKeys) {
                    $truncated = true;

                    return false;
                }

                $prefix = ObjectKey::fromString($object->key)
                    ->commonPrefix($query->prefix, $query->delimiter);

                if ($prefix !== null) {
                    if (! \in_array($prefix, $commonPrefixes, strict: true)) {
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
     *
     * Uses "!" rather than backslash as the escape character: a backslash needs
     * different quoting in MySQL and SQLite string literals, which is a
     * portability trap for no benefit.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
