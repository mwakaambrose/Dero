<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates a team, a user and an access key with known credentials, and prints
 * them as shell exports for the interoperability scripts to source.
 */
#[Signature('dero:seed-interop
    {--email=interop@dero.test : The user to create or reuse}
    {--team=Interop : The team name}')]
#[Description('Seed a team, user and access key for the interoperability suite')]
final class DeroSeedInteropCommand extends Command
{
    public function handle(CreateTeam $createTeam): int
    {
        // This command mints a credential and prints its secret to stdout.
        // There is no situation in which that is correct in production.
        if (app()->environment('production')) {
            $this->components->error('dero:seed-interop refuses to run in production.');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $teamName = (string) $this->option('team');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Interop Runner',
                'password' => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
            ],
        );

        $team = Team::query()->where('name', $teamName)->first()
            ?? $createTeam->handle($user, $teamName);

        $secret = Str::random(40);

        $key = AccessKey::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Interop suite',
            'access_key_id' => 'DERO'.strtoupper(Str::random(16)),
            'secret_key' => $secret,
            'permissions' => [AccessKeyPermission::Admin->value],
            'bucket_scope' => null,
        ]);

        // Shell exports, so a script can source this output directly.
        $this->line('export AWS_ACCESS_KEY_ID='.$key->access_key_id);
        $this->line('export AWS_SECRET_ACCESS_KEY='.$secret);
        $this->line('export AWS_DEFAULT_REGION='.config('dero.region'));
        $this->line('export DERO_TEAM_SLUG='.$team->slug);
        $this->line('export DERO_USER_EMAIL='.$user->email);

        return self::SUCCESS;
    }
}
