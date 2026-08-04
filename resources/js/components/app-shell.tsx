import type { ReactNode } from 'react';

/**
 * The outer frame for the header layout. The rail layout builds its own shell.
 */
export function AppShell({ children }: { children: ReactNode }) {
    return <div className="flex min-h-screen w-full flex-col">{children}</div>;
}
