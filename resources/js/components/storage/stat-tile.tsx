import type { LucideIcon } from 'lucide-react';

type Props = {
    label: string;
    value: string;
    hint?: string;
    icon: LucideIcon;
};

export function StatTile({ label, value, hint, icon: Icon }: Props) {
    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="mb-3 flex items-center justify-between">
                <span className="font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
                    {label}
                </span>
                <Icon className="size-4 text-primary" />
            </div>
            <p className="text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {hint ? (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}
