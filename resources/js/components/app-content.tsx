import type * as React from 'react';

export function AppContent({
    children,
    ...props
}: React.ComponentProps<'main'>) {
    return (
        <main
            className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4"
            {...props}
        >
            {children}
        </main>
    );
}
