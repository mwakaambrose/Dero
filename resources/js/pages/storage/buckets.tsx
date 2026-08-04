import { Head, Link, usePage } from '@inertiajs/react';
import { Boxes, ChevronRight, Globe, Lock } from 'lucide-react';
import { EmptyState } from '@/components/storage/empty-state';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatDateTime, formatNumber } from '@/lib/format';
import { browser, buckets as bucketsRoute } from '@/routes/storage';

type BucketRow = {
    name: string;
    region: string;
    visibility: string;
    visibilityLabel: string;
    objectCount: number;
    storageBytes: number;
    createdAt: string | null;
};

export default function Buckets({ buckets }: { buckets: BucketRow[] }) {
    const slug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="Buckets" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Buckets
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Buckets are created through the S3 API — run{' '}
                        <code className="rounded bg-secondary px-1.5 py-0.5 font-mono text-xs">
                            aws s3 mb
                        </code>{' '}
                        against your endpoint.
                    </p>
                </div>

                {buckets.length === 0 ? (
                    <EmptyState
                        icon={Boxes}
                        title="No buckets yet"
                        body="Create your first bucket with the AWS CLI, then it will appear here with its contents."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="px-5 py-3 font-medium">
                                        Name
                                    </th>
                                    <th className="px-5 py-3 text-right font-medium">
                                        Objects
                                    </th>
                                    <th className="px-5 py-3 text-right font-medium">
                                        Size
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium md:table-cell">
                                        Access
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium lg:table-cell">
                                        Created
                                    </th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {buckets.map((bucket) => (
                                    <tr
                                        key={bucket.name}
                                        className="group transition-colors hover:bg-accent/50"
                                    >
                                        <td className="px-5 py-3.5">
                                            <Link
                                                href={browser({
                                                    current_team: slug,
                                                    bucket: bucket.name,
                                                })}
                                                className="flex items-center gap-2.5 font-medium"
                                            >
                                                <Boxes className="size-4 text-primary" />
                                                {bucket.name}
                                            </Link>
                                        </td>
                                        <td className="px-5 py-3.5 text-right text-muted-foreground tabular-nums">
                                            {formatNumber(bucket.objectCount)}
                                        </td>
                                        <td className="px-5 py-3.5 text-right tabular-nums">
                                            {formatBytes(bucket.storageBytes)}
                                        </td>
                                        <td className="hidden px-5 py-3.5 md:table-cell">
                                            <Badge
                                                variant="secondary"
                                                className="gap-1.5 font-normal"
                                            >
                                                {bucket.visibility ===
                                                'public-read' ? (
                                                    <Globe className="size-3" />
                                                ) : (
                                                    <Lock className="size-3" />
                                                )}
                                                {bucket.visibilityLabel}
                                            </Badge>
                                        </td>
                                        <td className="hidden px-5 py-3.5 text-muted-foreground lg:table-cell">
                                            {formatDateTime(bucket.createdAt)}
                                        </td>
                                        <td className="px-5 py-3.5 text-right">
                                            <Link
                                                href={browser({
                                                    current_team: slug,
                                                    bucket: bucket.name,
                                                })}
                                                className="inline-flex items-center text-muted-foreground transition-colors group-hover:text-primary"
                                                aria-label={`Open ${bucket.name}`}
                                            >
                                                <ChevronRight className="size-4" />
                                            </Link>
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

Buckets.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Buckets',
            href: props.currentTeam
                ? bucketsRoute(props.currentTeam.slug)
                : '/',
        },
    ],
});
