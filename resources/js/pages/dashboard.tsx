import { Head, Link, usePage } from '@inertiajs/react';
import { Activity as ActivityIcon } from 'lucide-react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { CapacityMeasure } from '@/components/storage/capacity-measure';
import type { Capacity } from '@/components/storage/capacity-measure';
import { EmptyState } from '@/components/storage/empty-state';
import { Figure, Section } from '@/components/storage/section';
import { TrafficChart } from '@/components/storage/traffic-chart';
import type { SeriesPoint } from '@/components/storage/traffic-chart';
import { Button } from '@/components/ui/button';
import {
    fileKind,
    formatBytes,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import { dashboard } from '@/routes';
import { buckets as bucketsRoute } from '@/routes/storage';
import type { DashboardInvitation } from '@/types';

type Stats = {
    storageBytes: number;
    objectCount: number;
    bucketCount: number;
    accessKeyCount: number;
    requests: number;
    bandwidth: number;
};

type Props = {
    pendingInvitations?: DashboardInvitation[];
    stats: Stats;
    capacity: Capacity;
    series: SeriesPoint[];
    largestBuckets: {
        name: string;
        storageBytes: number;
        objectCount: number;
    }[];
    recentObjects: {
        key: string;
        bucket: string;
        sizeBytes: number;
        contentType: string;
        lastModified: string;
    }[];
    activity: {
        id: number;
        action: string;
        result: string;
        errorCode: string | null;
        objectKey: string | null;
        createdAt: string;
    }[];
};

export default function Dashboard({
    pendingInvitations = [],
    stats,
    capacity,
    series,
    largestBuckets,
    recentObjects,
    activity,
}: Props) {
    const slug = usePage().props.currentTeam?.slug ?? '';
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    const largest = largestBuckets[0]?.storageBytes || 1;

    return (
        <>
            <Head title="Overview" />

            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />

            <h1 className="sr-only">Storage overview</h1>

            <div className="px-4 pt-10 pb-9 md:px-8">
                <CapacityMeasure
                    storageBytes={stats.storageBytes}
                    capacity={capacity}
                />

                <dl className="mt-10 grid grid-cols-2 gap-x-6 gap-y-7 border-t border-border pt-7 sm:grid-cols-3 lg:grid-cols-5">
                    <Figure
                        label="Buckets"
                        value={formatNumber(stats.bucketCount)}
                    />
                    <Figure
                        label="Objects"
                        value={formatNumber(stats.objectCount)}
                    />
                    <Figure
                        label="Access keys"
                        value={formatNumber(stats.accessKeyCount)}
                        hint="Not revoked"
                    />
                    <Figure
                        label="Requests"
                        value={formatNumber(stats.requests)}
                        hint="Last 14 days"
                    />
                    <Figure
                        label="Bandwidth"
                        value={formatBytes(stats.bandwidth)}
                        hint="In and out, 14 days"
                    />
                </dl>
            </div>

            <Section eyebrow="Last 14 days">
                {series.length === 0 ? (
                    <EmptyState
                        icon={ActivityIcon}
                        title="Nothing has come through yet"
                        body="Usage is recorded as requests reach the S3 endpoint. Create a bucket, point a client at it, and this fills in."
                        action={
                            <Button asChild>
                                <Link href={bucketsRoute(slug)}>
                                    Go to buckets
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <TrafficChart data={series} />
                )}
            </Section>

            <div className="grid lg:grid-cols-5">
                <Section
                    eyebrow="Recently changed"
                    className="lg:col-span-3 lg:border-r"
                >
                    {recentObjects.length === 0 ? (
                        <Blank>Nothing uploaded yet.</Blank>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {recentObjects.map((object) => (
                                <li
                                    key={`${object.bucket}/${object.key}`}
                                    className="flex items-baseline gap-4 py-2.5 first:pt-0"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-mono text-[13px]">
                                            {object.key}
                                        </span>
                                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                            {object.bucket} ·{' '}
                                            {fileKind(object.contentType)}
                                        </span>
                                    </span>
                                    <span className="shrink-0 font-mono text-xs tabular-nums">
                                        {formatBytes(object.sizeBytes)}
                                    </span>
                                    <span className="hidden w-24 shrink-0 text-right text-xs text-muted-foreground sm:block">
                                        {formatRelative(object.lastModified)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section eyebrow="Largest buckets" className="lg:col-span-2">
                    {largestBuckets.length === 0 ? (
                        <Blank>No buckets yet.</Blank>
                    ) : (
                        <ul className="space-y-4">
                            {largestBuckets.map((bucket) => (
                                <li key={bucket.name}>
                                    <div className="mb-2 flex items-baseline justify-between gap-3">
                                        <Link
                                            href={bucketsRoute(slug)}
                                            className="truncate font-mono text-[13px] transition-colors hover:text-primary"
                                        >
                                            {bucket.name}
                                        </Link>
                                        <span className="shrink-0 font-mono text-xs text-muted-foreground tabular-nums">
                                            {formatBytes(bucket.storageBytes)}
                                        </span>
                                    </div>
                                    <div className="h-1 overflow-hidden rounded-full bg-measure-track">
                                        <div
                                            className="h-full rounded-full bg-primary"
                                            style={{
                                                width: `${Math.max(2, (bucket.storageBytes / largest) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>

            <Section eyebrow="Recent requests">
                {activity.length === 0 ? (
                    <Blank>No requests recorded yet.</Blank>
                ) : (
                    <ul className="divide-y divide-border/70 font-mono text-xs">
                        {activity.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-center gap-4 py-2 first:pt-0"
                            >
                                <span
                                    className={
                                        entry.result === 'success'
                                            ? 'w-16 shrink-0 text-muted-foreground uppercase'
                                            : 'w-16 shrink-0 font-medium text-destructive uppercase'
                                    }
                                >
                                    {entry.result}
                                </span>
                                <span className="w-40 shrink-0 truncate font-medium">
                                    {entry.action}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                    {entry.objectKey ?? entry.errorCode ?? ''}
                                </span>
                                <span className="hidden shrink-0 text-muted-foreground sm:block">
                                    {formatRelative(entry.createdAt)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </>
    );
}

function Blank({ children }: { children: string }) {
    return <p className="py-4 text-sm text-muted-foreground">{children}</p>;
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Overview',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
