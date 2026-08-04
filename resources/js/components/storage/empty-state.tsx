import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    icon: LucideIcon;
    title: string;
    body: string;
    action?: ReactNode;
};

/**
 * An empty screen is an invitation to act, so every one of these says what to
 * do next rather than only reporting that nothing is here.
 */
export function EmptyState({ icon: Icon, title, body, action }: Props) {
    return (
        <div className="flex flex-col items-start rounded-lg border border-dashed border-border bg-secondary/40 px-6 py-10">
            <Icon
                className="size-5 text-primary"
                aria-hidden="true"
                strokeWidth={1.75}
            />
            <h3 className="mt-4 font-display text-lg font-semibold tracking-tight">
                {title}
            </h3>
            <p className="mt-1.5 max-w-md text-sm leading-relaxed text-muted-foreground">
                {body}
            </p>
            {action ? <div className="mt-6">{action}</div> : null}
        </div>
    );
}
