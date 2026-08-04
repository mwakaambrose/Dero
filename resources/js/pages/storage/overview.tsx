import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity as ActivityIcon,
    ArrowUpDown,
    Boxes,
    Files,
    HardDrive,
    Radio,
} from 'lucide-react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { EmptyState } from '@/components/storage/empty-state';
import { StatTile } from '@/components/storage/stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    fileKind,
    formatBytes,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import { buckets as bucketsRoute, overview } from '@/routes/storage';

type Stats = {
    storageBytes: number;
    objectCount: number;
    bucketCount: number;
    accessKeyCount: number;
    requests: number;
    bandwidth: number;
};

type SeriesPoint = {
    day: string;
    storage: number;
    objects: number;
    requests: number;
    uploaded: number;
    downloaded: number;
};

type Props = {
    stats: Stats;
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

function ChartCard({
    title,
    caption,
    data,
    dataKey,
    formatter,
}: {
    title: string;
    caption: string;
    data: SeriesPoint[];
    dataKey: keyof SeriesPoint;
    formatter: (value: number) => string;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="mb-4">
                <h3 className="font-medium">{title}</h3>
                <p className="text-xs text-muted-foreground">{caption}</p>
            </div>
            <div className="h-44">
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart
                        data={data}
                        margin={{ top: 4, right: 4, bottom: 0, left: -12 }}
                    >
                        <defs>
                            <linearGradient
                                id={`fill-${dataKey}`}
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="var(--color-chart-1)"
                                    stopOpacity={0.35}
                                />
                                <stop
                                    offset="100%"
                                    stopColor="var(--color-chart-1)"
                                    stopOpacity={0}
                                />
                            </linearGradient>
                        </defs>
                        <CartesianGrid
                            stroke="var(--color-border)"
                            vertical={false}
                        />
                        <XAxis
                            dataKey="day"
                            tickLine={false}
                            axisLine={false}
                            tick={{
                                fill: 'var(--color-muted-foreground)',
                                fontSize: 11,
                            }}
                            tickFormatter={(value: string) => value.slice(5)}
                            minTickGap={24}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            width={64}
                            tick={{
                                fill: 'var(--color-muted-foreground)',
                                fontSize: 11,
                            }}
                            tickFormatter={(value: number) => formatter(value)}
                        />
                        <Tooltip
                            contentStyle={{
                                background: 'var(--color-popover)',
                                border: '1px solid var(--color-border)',
                                borderRadius: '0.5rem',
                                fontSize: '12px',
                                color: 'var(--color-popover-foreground)',
                            }}
                            formatter={(value) =>
                                [formatter(Number(value)), title] as [
                                    string,
                                    string,
                                ]
                            }
                        />
                        <Area
                            type="monotone"
                            dataKey={dataKey}
                            stroke="var(--color-chart-1)"
                            strokeWidth={2}
                            fill={`url(#fill-${dataKey})`}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

export default function Overview({
    stats,
    series,
    largestBuckets,
    recentObjects,
    activity,
}: Props) {
    const slug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="Overview" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Overview
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Everything stored on this installation, over the last 14
                        days.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Stored"
                        value={formatBytes(stats.storageBytes)}
                        hint={`${formatNumber(stats.objectCount)} objects`}
                        icon={HardDrive}
                    />
                    <StatTile
                        label="Buckets"
                        value={formatNumber(stats.bucketCount)}
                        hint={`${formatNumber(stats.accessKeyCount)} active keys`}
                        icon={Boxes}
                    />
                    <StatTile
                        label="Requests"
                        value={formatNumber(stats.requests)}
                        hint="Last 14 days"
                        icon={Radio}
                    />
                    <StatTile
                        label="Bandwidth"
                        value={formatBytes(stats.bandwidth)}
                        hint="Up and down combined"
                        icon={ArrowUpDown}
                    />
                </div>

                {series.length === 0 ? (
                    <EmptyState
                        icon={ActivityIcon}
                        title="No usage recorded yet"
                        body="Charts fill in once traffic reaches the S3 endpoint. Create a bucket and upload something to get started."
                        action={
                            <Button asChild>
                                <Link href={bucketsRoute(slug)}>
                                    Go to buckets
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <ChartCard
                            title="Storage"
                            caption="Total bytes held"
                            data={series}
                            dataKey="storage"
                            formatter={(value) => formatBytes(value, 0)}
                        />
                        <ChartCard
                            title="Requests"
                            caption="S3 API calls per day"
                            data={series}
                            dataKey="requests"
                            formatter={(value) => formatNumber(value)}
                        />
                        <ChartCard
                            title="Downloaded"
                            caption="Bytes served per day"
                            data={series}
                            dataKey="downloaded"
                            formatter={(value) => formatBytes(value, 0)}
                        />
                        <ChartCard
                            title="Uploaded"
                            caption="Bytes received per day"
                            data={series}
                            dataKey="uploaded"
                            formatter={(value) => formatBytes(value, 0)}
                        />
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="rounded-xl border border-border bg-card lg:col-span-2">
                        <div className="border-b border-border px-5 py-4">
                            <h3 className="font-medium">Recently changed</h3>
                        </div>
                        {recentObjects.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                Nothing uploaded yet.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {recentObjects.map((object) => (
                                    <li
                                        key={`${object.bucket}/${object.key}`}
                                        className="flex items-center gap-4 px-5 py-3"
                                    >
                                        <Files className="size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-mono text-sm">
                                                {object.key}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {object.bucket} ·{' '}
                                                {fileKind(object.contentType)}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                            {formatBytes(object.sizeBytes)}
                                        </span>
                                        <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                                            {formatRelative(
                                                object.lastModified,
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="rounded-xl border border-border bg-card">
                        <div className="border-b border-border px-5 py-4">
                            <h3 className="font-medium">Largest buckets</h3>
                        </div>
                        {largestBuckets.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                No buckets yet.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {largestBuckets.map((bucket) => (
                                    <li key={bucket.name} className="px-5 py-3">
                                        <div className="mb-1.5 flex items-baseline justify-between gap-3">
                                            <Link
                                                href={bucketsRoute(slug)}
                                                className="truncate text-sm font-medium hover:text-primary"
                                            >
                                                {bucket.name}
                                            </Link>
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {formatBytes(
                                                    bucket.storageBytes,
                                                )}
                                            </span>
                                        </div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                                            <div
                                                className="h-full rounded-full bg-primary"
                                                style={{
                                                    width: `${Math.max(
                                                        4,
                                                        (bucket.storageBytes /
                                                            (largestBuckets[0]
                                                                ?.storageBytes ||
                                                                1)) *
                                                            100,
                                                    )}%`,
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-card">
                    <div className="border-b border-border px-5 py-4">
                        <h3 className="font-medium">Recent activity</h3>
                    </div>
                    {activity.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No requests recorded yet.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {activity.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-center gap-4 px-5 py-3"
                                >
                                    <Badge
                                        variant={
                                            entry.result === 'success'
                                                ? 'secondary'
                                                : 'destructive'
                                        }
                                        className="shrink-0 font-mono text-[10px] uppercase"
                                    >
                                        {entry.result}
                                    </Badge>
                                    <span className="shrink-0 text-sm font-medium">
                                        {entry.action}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate font-mono text-xs text-muted-foreground">
                                        {entry.objectKey ??
                                            entry.errorCode ??
                                            ''}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {formatRelative(entry.createdAt)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

Overview.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Overview',
            href: props.currentTeam ? overview(props.currentTeam.slug) : '/',
        },
    ],
});
