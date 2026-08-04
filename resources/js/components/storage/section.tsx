import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A band of the working sheet.
 *
 * Sections are separated by a rule and a label, not by a box each. The rule
 * running out from the label is the only structural device on the page, and it
 * says one true thing: this is where the previous subject ended.
 */
export function Section({
    eyebrow,
    trailing,
    children,
    className,
}: {
    eyebrow: string;
    trailing?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'border-t border-border px-4 py-8 md:px-8',
                className,
            )}
        >
            <div className="mb-5 flex items-center gap-4">
                <h2 className="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
                    {eyebrow}
                </h2>
                <span aria-hidden="true" className="h-px flex-1 bg-border" />
                {trailing}
            </div>
            {children}
        </section>
    );
}

/**
 * A labelled number. No icon: an icon beside "Buckets" tells you nothing the
 * word did not.
 */
export function Figure({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div>
            <dt className="font-mono text-[10px] tracking-[0.16em] text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1.5 text-xl font-medium tracking-tight tabular-nums">
                {value}
            </dd>
            {hint ? (
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}
