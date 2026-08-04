import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Boxes,
    KeyRound,
    LayoutGrid,
    LifeBuoy,
    Plug,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import {
    accessKeys as storageAccessKeys,
    activity as storageActivity,
    buckets as storageBuckets,
    connect as storageConnect,
    overview as storageOverview,
} from '@/routes/storage';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const dashboardUrl = page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug)
        : '/';

    const slug = page.props.currentTeam?.slug;

    const mainNavItems: NavItem[] = slug
        ? [
              {
                  title: 'Overview',
                  href: storageOverview(slug),
                  icon: LayoutGrid,
              },
              { title: 'Buckets', href: storageBuckets(slug), icon: Boxes },
              {
                  title: 'Access keys',
                  href: storageAccessKeys(slug),
                  icon: KeyRound,
              },
              { title: 'Connect', href: storageConnect(slug), icon: Plug },
              {
                  title: 'Activity',
                  href: storageActivity(slug),
                  icon: Activity,
              },
          ]
        : [{ title: 'Dashboard', href: dashboardUrl, icon: LayoutGrid }];

    const footerNavItems: NavItem[] = [
        {
            title: 'Help',
            href: 'https://github.com/laravel/react-starter-kit',
            icon: LifeBuoy,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
