<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Support\StorageDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ConnectController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = StorageDashboard::team($request);

        return Inertia::render('storage/connect', [
            'endpoint' => StorageDashboard::endpoint(),
            'region' => config('dero.region'),
            'accessKeys' => AccessKey::query()
                ->where('team_id', $team->id)
                ->whereNull('revoked_at')
                ->orderBy('name')
                ->get()
                ->map(fn (AccessKey $key): array => [
                    'accessKeyId' => $key->access_key_id,
                    'name' => $key->name,
                ]),
            'buckets' => Bucket::query()
                ->where('team_id', $team->id)
                ->orderBy('name')
                ->pluck('name'),
        ]);
    }
}
