<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Actions\S3\RecordUsage;
use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\S3\Http\OperationName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes the audit log and usage counters for every S3 request.
 *
 * Terminable: the work happens after the response has been flushed, so audit
 * writes never add latency to a 1 GB download. This is why activity logging is
 * not simply done in the controllers.
 */
final class RecordS3Activity
{
    public function __construct(private readonly RecordUsage $recordUsage) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('s3.started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->record($request, $response);
        } catch (Throwable $exception) {
            // Losing an audit row must never turn a served request into an
            // error the client sees — the response has already gone out.
            report($exception);
        }
    }

    private function record(Request $request, Response $response): void
    {
        $accessKey = $request->attributes->get('s3.access_key');
        $bucket = $request->attributes->get('s3.bucket');

        $accessKey = $accessKey instanceof AccessKey ? $accessKey : null;
        $bucket = $bucket instanceof Bucket ? $bucket : null;

        // The key's team when authenticated, otherwise the bucket's — an
        // anonymous read of a public bucket still belongs to that team's usage.
        $teamId = match (true) {
            $accessKey !== null => $accessKey->team_id,
            $bucket !== null => $bucket->team_id,
            default => null,
        };

        $status = $response->getStatusCode();

        $startedAt = $request->attributes->get('s3.started_at');
        $key = $request->route('key');

        AuditLog::create([
            'team_id' => $teamId,
            'actor_type' => $accessKey === null ? 'anonymous' : 'access_key',
            // The access key id, never the secret. The secret is encrypted at
            // rest and must not be reachable from a log row.
            'actor_id' => $accessKey?->access_key_id,
            'action' => OperationName::forRequest($request),
            'bucket_id' => $bucket?->id,
            'object_key' => \is_string($key) ? $key : null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'result' => $this->result($status),
            'error_code' => $this->errorCode($response),
            'meta' => [
                'status' => $status,
                'method' => $request->getMethod(),
                'duration_ms' => \is_float($startedAt)
                    ? (int) round((microtime(true) - $startedAt) * 1000)
                    : null,
            ],
            'created_at' => now(),
        ]);

        if ($teamId === null) {
            return;
        }

        $this->recordUsage->handle(
            $teamId,
            $bucket?->id,
            $this->bytesUploaded($request),
            $this->bytesDownloaded($request, $response),
        );
    }

    private function result(int $status): string
    {
        return match (true) {
            $status < 400 => 'success',
            $status === 403 => 'denied',
            default => 'error',
        };
    }

    /**
     * The S3 error code from the rendered XML body, when there is one.
     */
    private function errorCode(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $body = $response->getContent();

        if (! \is_string($body) || preg_match('#<Code>([^<]+)</Code>#', $body, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Bytes the client sent us.
     *
     * Controllers publish the measured size, because Content-Length is absent
     * on chunked uploads and wrong on aws-chunked ones — its value there counts
     * the framing, not the payload.
     */
    private function bytesUploaded(Request $request): int
    {
        $bytes = $request->attributes->get('s3.bytes_uploaded');

        return \is_int($bytes) ? $bytes : 0;
    }

    /**
     * Bytes we sent the client, read from the response's own Content-Length so
     * a range request is metered for the range rather than the whole object.
     */
    private function bytesDownloaded(Request $request, Response $response): int
    {
        if (! \in_array($request->getMethod(), ['GET'], true) || $response->getStatusCode() >= 300) {
            return 0;
        }

        $length = $response->headers->get('Content-Length');

        return \is_string($length) ? max(0, (int) $length) : 0;
    }
}
