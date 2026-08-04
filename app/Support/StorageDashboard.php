<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\Team;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared helpers for the storage dashboard controllers.
 */
final class StorageDashboard
{
    /**
     * The team the current dashboard request belongs to.
     *
     * Resolved from the route binding that EnsureTeamMembership has already
     * verified, so every query below is tenant-scoped by construction.
     */
    public static function team(Request $request): Team
    {
        $team = $request->route('current_team');

        if ($team instanceof Team) {
            return $team;
        }

        if (\is_string($team)) {
            $resolved = Team::query()->where('slug', $team)->first();

            if ($resolved instanceof Team) {
                return $resolved;
            }
        }

        throw new RuntimeException('The dashboard request is not scoped to a team.');
    }

    /**
     * The named bucket, if the given team owns it.
     *
     * A bucket another team owns is answered as absent rather than forbidden:
     * the dashboard must not confirm that a name exists elsewhere.
     */
    public static function bucket(Team $team, string $name): Bucket
    {
        $bucket = Bucket::query()
            ->where('team_id', $team->id)
            ->where('name', $name)
            ->first();

        if (! $bucket instanceof Bucket) {
            throw new NotFoundHttpException;
        }

        return $bucket;
    }

    /**
     * Shape an audit log row for the dashboard.
     *
     * @return array<string, mixed>
     */
    public static function auditRow(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'result' => $log->result,
            'errorCode' => $log->error_code,
            'actorType' => $log->actor_type,
            'actorId' => $log->actor_id,
            'objectKey' => $log->object_key,
            'ip' => $log->ip,
            'createdAt' => $log->created_at->toIso8601String(),
        ];
    }

    /**
     * How much room the volume behind the storage root actually has.
     *
     * Self-hosted storage fails in one specific way — the disk fills — and no
     * amount of per-bucket accounting warns you about it. Both figures are null
     * when the root is not yet on disk or the platform refuses to report, and
     * the dashboard degrades to showing bytes held with no ratio.
     *
     * @return array{totalBytes: int|null, freeBytes: int|null}
     */
    public static function capacity(): array
    {
        $root = config('dero.storage_root');

        if (! \is_string($root) || ! is_dir($root)) {
            return ['totalBytes' => null, 'freeBytes' => null];
        }

        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        return [
            'totalBytes' => \is_float($total) && $total > 0 ? (int) $total : null,
            'freeBytes' => \is_float($free) && $free >= 0 ? (int) $free : null,
        ];
    }

    /**
     * The host the S3 API answers on, with no scheme.
     *
     * Signed URLs need the bare host: it is the value that goes into the signed
     * Host header, and a scheme in it would change the signature.
     */
    public static function endpointHost(): string
    {
        $host = config('dero.endpoint_host');

        if (! \is_string($host) || $host === '') {
            throw new RuntimeException('The S3 endpoint host is not configured.');
        }

        return $host;
    }

    /**
     * The S3 endpoint this installation serves, for the connection guide.
     */
    public static function endpoint(): string
    {
        $host = config('dero.endpoint_host');

        if (! \is_string($host) || $host === '') {
            return 'Not configured — set S3_ENDPOINT_HOST';
        }

        return 'https://'.$host;
    }
}
