import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Boxes,
    ChevronsUpDown,
    Gauge,
    KeyRound,
    Link2,
    Plug,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { TeamMenuItems } from '@/components/team-switcher';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    accessKeys as storageAccessKeys,
    activity as storageActivity,
    buckets as storageBuckets,
    connect as storageConnect,
    shares as storageShares,
} from '@/routes/storage';
import type { NavItem } from '@/types';

type RailItem = NavItem & { icon: LucideIcon };

/**
 * The nav rail.
 *
 * Charcoal in both themes, so it frames the working sheet rather than
 * competing with it. Built from plain elements — the only borrowed pieces are
 * the two dropdowns, where the behaviour is worth more than the markup.
 */
export function AppRail({ onNavigate }: { onNavigate?: () => void }) {
    const page = usePage();
    const { auth, currentTeam } = page.props;
    const getInitials = useInitials();

    const slug = currentTeam?.slug;
    const dashboardUrl = slug ? dashboard(slug) : '/';

    const items: RailItem[] = slug
        ? [
              { title: 'Overview', href: dashboardUrl, icon: Gauge },
              { title: 'Buckets', href: storageBuckets(slug), icon: Boxes },
              {
                  title: 'Access keys',
                  href: storageAccessKeys(slug),
                  icon: KeyRound,
              },
              { title: 'Shared links', href: storageShares(slug), icon: Link2 },
              { title: 'Connect', href: storageConnect(slug), icon: Plug },
              {
                  title: 'Activity',
                  href: storageActivity(slug),
                  icon: Activity,
              },
          ]
        : [{ title: 'Overview', href: dashboardUrl, icon: Gauge }];

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            <div className="dero-weave-rail px-4 pt-6 pb-5">
                <Link
                    href={dashboardUrl}
                    prefetch
                    onClick={onNavigate}
                    className="inline-flex items-center gap-2.5 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar"
                >
                    <AppLogoIcon className="size-6 text-sidebar-primary" />
                    <span className="font-display text-lg leading-none font-semibold">
                        {page.props.name}
                    </span>
                </Link>
            </div>

            <div className="px-3 pb-4">
                <DropdownMenu>
                    <DropdownMenuTrigger
                        data-test="team-switcher-trigger"
                        className="flex w-full items-center gap-2 rounded-md border border-sidebar-border bg-sidebar-accent/50 px-3 py-2 text-left transition-colors outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring data-[state=open]:bg-sidebar-accent"
                    >
                        <span className="min-w-0 flex-1">
                            <span className="block font-mono text-[10px] tracking-[0.16em] text-sidebar-muted uppercase">
                                Team
                            </span>
                            <span className="mt-0.5 block truncate text-sm font-medium">
                                {currentTeam?.name ?? 'Select team'}
                            </span>
                        </span>
                        <ChevronsUpDown className="size-3.5 shrink-0 text-sidebar-muted" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-60">
                        <TeamMenuItems />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <nav className="flex-1 space-y-0.5 px-3" aria-label="Storage">
                {items.map((item) => (
                    <RailLink
                        key={item.title}
                        item={item}
                        onNavigate={onNavigate}
                    />
                ))}
            </nav>

            <div className="border-t border-sidebar-border p-3">
                <DropdownMenu>
                    <DropdownMenuTrigger
                        data-test="sidebar-menu-button"
                        className="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left transition-colors outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring data-[state=open]:bg-sidebar-accent"
                    >
                        <Avatar className="size-7 shrink-0 overflow-hidden rounded-md">
                            {auth.user.avatar ? (
                                <AvatarImage
                                    src={auth.user.avatar}
                                    alt={auth.user.name}
                                />
                            ) : null}
                            <AvatarFallback className="rounded-md bg-sidebar-accent text-[11px] text-sidebar-accent-foreground">
                                {getInitials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium">
                                {auth.user.name}
                            </span>
                            <span className="block truncate text-xs text-sidebar-muted">
                                {auth.user.email}
                            </span>
                        </span>
                        <ChevronsUpDown className="size-3.5 shrink-0 text-sidebar-muted" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        side="top"
                        className="w-60"
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    );
}

function RailLink({
    item,
    onNavigate,
}: {
    item: RailItem;
    onNavigate?: () => void;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const isActive = isCurrentUrl(item.href);

    return (
        <Link
            href={item.href}
            prefetch
            onClick={onNavigate}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'group relative flex items-center gap-3 rounded-md py-2 pr-3 pl-4 text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                isActive
                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                    : 'text-sidebar-muted hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
            )}
        >
            {isActive ? (
                <span
                    aria-hidden="true"
                    className="absolute top-1/2 left-0 h-4 w-0.75 -translate-y-1/2 rounded-r-full bg-sidebar-primary"
                />
            ) : null}
            <item.icon
                className={cn(
                    'size-4 shrink-0 transition-colors',
                    isActive
                        ? 'text-sidebar-primary'
                        : 'text-sidebar-muted group-hover:text-sidebar-accent-foreground',
                )}
            />
            {item.title}
        </Link>
    );
}
