<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Team;
use Illuminate\Http\Request;
use RuntimeException;

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
