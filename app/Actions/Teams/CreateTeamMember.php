<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeamMember
{
    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Create a verified user with a personal team and add them to the team.
     *
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function handle(Team $team, array $attributes, TeamRole $role): User
    {
        return DB::transaction(function () use ($team, $attributes, $role) {
            $user = User::create($attributes);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
            $user->switchTeam($team);

            return $user;
        });
    }
}
