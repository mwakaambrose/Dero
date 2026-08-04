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
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border px-6 py-16 text-center">
            <div className="mb-4 rounded-full border border-border bg-card p-3">
                <Icon className="size-5 text-primary" />
            </div>
            <h3 className="mb-1.5 font-medium">{title}</h3>
            <p className="max-w-sm text-sm leading-relaxed text-muted-foreground">
                {body}
            </p>
            {action ? <div className="mt-6">{action}</div> : null}
        </div>
    );
}
