import { Link } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { Fragment } from 'react';
import type { BreadcrumbItem } from '@/types';

/**
 * The trail across the top of the working sheet.
 *
 * Set as a path rather than as prose — the same shape as the object keys the
 * product deals in — so it reads as a location, not a heading. Page titles
 * belong to the pages themselves.
 */
export function AppTopbar({
    breadcrumbs = [],
    onOpenNav,
}: {
    breadcrumbs?: BreadcrumbItem[];
    onOpenNav: () => void;
}) {
    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-sm md:px-8">
            <button
                type="button"
                onClick={onOpenNav}
                className="-ml-1 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:hidden"
            >
                <Menu className="size-5" />
                <span className="sr-only">Open navigation</span>
            </button>

            {breadcrumbs.length > 0 ? (
                <nav
                    aria-label="Breadcrumb"
                    className="flex min-w-0 items-center gap-2 font-mono text-[11px] tracking-[0.14em] uppercase"
                >
                    {breadcrumbs.map((item, index) => {
                        const isLast = index === breadcrumbs.length - 1;

                        return (
                            <Fragment key={index}>
                                {isLast ? (
                                    <span
                                        aria-current="page"
                                        className="truncate text-foreground"
                                    >
                                        {item.title}
                                    </span>
                                ) : (
                                    <>
                                        <Link
                                            href={item.href}
                                            className="truncate text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {item.title}
                                        </Link>
                                        <span
                                            aria-hidden="true"
                                            className="text-primary"
                                        >
                                            /
                                        </span>
                                    </>
                                )}
                            </Fragment>
                        );
                    })}
                </nav>
            ) : null}
        </header>
    );
}
