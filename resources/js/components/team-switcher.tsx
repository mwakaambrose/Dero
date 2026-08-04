import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import CreateTeamModal from '@/components/create-team-modal';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

/**
 * The contents of the team menu, without a trigger.
 *
 * Split out so the rail and the header can each present the switcher in their
 * own language while the switching itself lives in one place.
 */
export function TeamMenuItems() {
    const currentTeam = usePage().props.currentTeam;
    const teams = usePage().props.teams ?? [];
    const switchTeam = useSwitchTeam();

    return (
        <>
            <DropdownMenuLabel className="font-mono text-[10px] tracking-[0.16em] text-muted-foreground uppercase">
                Teams
            </DropdownMenuLabel>
            {teams.map((team) => (
                <DropdownMenuItem
                    key={team.id}
                    data-test="team-switcher-item"
                    className="cursor-pointer gap-2"
                    onSelect={() => switchTeam(team)}
                >
                    {team.name}
                    {currentTeam?.id === team.id && (
                        <Check className="ml-auto size-4 text-primary" />
                    )}
                </DropdownMenuItem>
            ))}
            <DropdownMenuSeparator />
            <CreateTeamModal>
                <DropdownMenuItem
                    data-test="team-switcher-new-team"
                    className="cursor-pointer gap-2"
                    onSelect={(event) => event.preventDefault()}
                >
                    <Plus className="size-4" />
                    <span className="text-muted-foreground">New team</span>
                </DropdownMenuItem>
            </CreateTeamModal>
        </>
    );
}

/**
 * Switching teams keeps you on the page you were reading, with the slug in the
 * URL swapped, rather than dropping you back at the overview.
 */
function useSwitchTeam(): (team: Team) => void {
    const currentTeam = usePage().props.currentTeam;

    return (team: Team) => {
        const previousTeamSlug = currentTeam?.slug;

        router.visit(switchMethod(team.slug), {
            onFinish: () => {
                if (!previousTeamSlug || typeof window === 'undefined') {
                    router.reload();

                    return;
                }

                const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
                const segment = `/${previousTeamSlug}`;

                if (currentUrl.includes(segment)) {
                    router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                        replace: true,
                    });

                    return;
                }

                router.reload();
            },
        });
    };
}

/**
 * The compact switcher used by the header layout. The rail builds its own
 * trigger and reuses {@link TeamMenuItems}.
 */
export function TeamSwitcher() {
    const currentTeam = usePage().props.currentTeam;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    data-test="team-switcher-trigger"
                    className="h-8 gap-1 px-2"
                >
                    <span className="max-w-30 truncate text-sm font-medium">
                        {currentTeam?.name ?? 'Select team'}
                    </span>
                    <ChevronsUpDown className="size-4 opacity-50" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-56" align="end">
                <TeamMenuItems />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
