import { Head, router, usePage } from '@inertiajs/react';
import { Activity as ActivityIcon } from 'lucide-react';
import { EmptyState } from '@/components/storage/empty-state';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatNumber } from '@/lib/format';
import { activity as activityRoute } from '@/routes/storage';

type Entry = {
    id: number;
    action: string;
    result: string;
    errorCode: string | null;
    actorType: string;
    actorId: string | null;
    objectKey: string | null;
    ip: string | null;
    createdAt: string;
};

type Props = {
    entries: Entry[];
    filter: string;
    counts: { success: number; denied: number; error: number };
};

const FILTERS = [
    { value: '', label: 'All' },
    { value: 'success', label: 'Succeeded' },
    { value: 'denied', label: 'Denied' },
    { value: 'error', label: 'Errored' },
];

export default function Activity({ entries, filter, counts }: Props) {
    const slug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="Activity" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Activity
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Every request the S3 endpoint handled, with the outcome.{' '}
                        {formatNumber(counts.denied)} denied,{' '}
                        {formatNumber(counts.error)} errored.
                    </p>
                </div>

                <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1 sm:w-fit">
                    {FILTERS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() =>
                                router.get(
                                    activityRoute(slug),
                                    option.value
                                        ? { result: option.value }
                                        : {},
                                    { preserveState: true, replace: true },
                                )
                            }
                            className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                filter === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                {entries.length === 0 ? (
                    <EmptyState
                        icon={ActivityIcon}
                        title="Nothing recorded"
                        body="Requests to the S3 endpoint appear here, including the ones that were refused."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border bg-card">
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="px-5 py-3 font-medium">
                                        When
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        Action
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        Result
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        Object
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        Actor
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        IP
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {entries.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className={
                                            entry.result === 'success'
                                                ? ''
                                                : 'bg-destructive/[0.04]'
                                        }
                                    >
                                        <td className="px-5 py-3 whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(entry.createdAt)}
                                        </td>
                                        <td className="px-5 py-3 font-medium">
                                            {entry.action}
                                        </td>
                                        <td className="px-5 py-3">
                                            <Badge
                                                variant={
                                                    entry.result === 'success'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                                className="font-mono text-[10px] uppercase"
                                            >
                                                {entry.errorCode ??
                                                    entry.result}
                                            </Badge>
                                        </td>
                                        <td className="max-w-xs truncate px-5 py-3 font-mono text-xs text-muted-foreground">
                                            {entry.objectKey ?? '—'}
                                        </td>
                                        <td className="px-5 py-3 text-xs text-muted-foreground">
                                            {entry.actorType}
                                        </td>
                                        <td className="px-5 py-3 font-mono text-xs text-muted-foreground">
                                            {entry.ip ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Activity.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Activity',
            href: props.currentTeam
                ? activityRoute(props.currentTeam.slug)
                : '/',
        },
    ],
});
