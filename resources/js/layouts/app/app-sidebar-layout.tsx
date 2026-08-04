import { useState } from 'react';
import { AppRail } from '@/components/app-rail';
import { AppTopbar } from '@/components/app-topbar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import type { AppLayoutProps } from '@/types';

/**
 * Rail and sheet.
 *
 * A charcoal rail flush against a lit working sheet, no gap and no float
 * between them — one hard edge, so the app reads as a console rather than as
 * panels arranged on a page. Below md the rail slides in over the sheet.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [navOpen, setNavOpen] = useState(false);

    return (
        <div className="flex min-h-screen bg-background">
            <aside className="hidden w-60 shrink-0 bg-sidebar md:block">
                <div className="sticky top-0 h-screen">
                    <AppRail />
                </div>
            </aside>

            <Sheet open={navOpen} onOpenChange={setNavOpen}>
                <SheetContent
                    side="left"
                    className="w-64 border-none bg-sidebar p-0 text-sidebar-foreground"
                >
                    <SheetTitle className="sr-only">Navigation</SheetTitle>
                    <AppRail onNavigate={() => setNavOpen(false)} />
                </SheetContent>
            </Sheet>

            <div className="flex min-w-0 flex-1 flex-col overflow-x-hidden">
                <AppTopbar
                    breadcrumbs={breadcrumbs}
                    onOpenNav={() => setNavOpen(true)}
                />
                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
