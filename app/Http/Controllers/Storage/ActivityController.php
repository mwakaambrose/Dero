<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\StorageDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = StorageDashboard::team($request);
        $result = (string) $request->query('result', '');

        $query = AuditLog::query()
            ->where('team_id', $team->id)
            ->latest('created_at');

        if (\in_array($result, ['success', 'denied', 'error'], true)) {
            $query->where('result', $result);
        }

        return Inertia::render('storage/activity', [
            'entries' => $query->limit(200)->get()->map(StorageDashboard::auditRow(...)),
            'filter' => $result,
            'counts' => [
                'success' => AuditLog::query()->where('team_id', $team->id)->where('result', 'success')->count(),
                'denied' => AuditLog::query()->where('team_id', $team->id)->where('result', 'denied')->count(),
                'error' => AuditLog::query()->where('team_id', $team->id)->where('result', 'error')->count(),
            ],
        ]);
    }
}
