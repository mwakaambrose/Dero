import { Head, Link, router, usePage } from '@inertiajs/react';
import { Copy, Link2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/storage/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatNumber, formatRelative } from '@/lib/format';
import { browser, shares as sharesRoute } from '@/routes/storage';
import { destroy as revokeShare } from '@/routes/storage/shares';

type ShareRow = {
    id: string;
    bucket: string;
    objectKey: string;
    url: string | null;
    createdBy: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
    downloads: number;
    revokedAt: string | null;
    isActive: boolean;
};

export default function Shares({ shares }: { shares: ShareRow[] }) {
    const slug = usePage().props.currentTeam?.slug ?? '';
    const [copiedText, copy] = useClipboard();
    const [pending, setPending] = useState<ShareRow | null>(null);

    const live = shares.filter((share) => share.isActive);

    const revoke = (share: ShareRow) => {
        router.delete(
            revokeShare({ current_team: slug, shareLink: share.id }),
            {
                preserveScroll: true,
                onFinish: () => setPending(null),
            },
        );
    };

    return (
        <>
            <Head title="Shared links" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Shared links
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {live.length === 0
                            ? 'Nothing in your buckets is reachable without a login.'
                            : `${formatNumber(live.length)} ${live.length === 1 ? 'object is' : 'objects are'} reachable by anyone holding the link, until you revoke it.`}
                    </p>
                </div>

                {shares.length === 0 ? (
                    <EmptyState
                        icon={Link2}
                        title="No shared links"
                        body="Open an object in a bucket and choose a link that lasts until revoked. Links you create that way are listed here so you can take them back."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="px-5 py-3 font-medium">
                                        Object
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium md:table-cell">
                                        Created
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium lg:table-cell">
                                        Last used
                                    </th>
                                    <th className="px-5 py-3 text-right font-medium">
                                        Uses
                                    </th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {shares.map((share) => (
                                    <tr
                                        key={share.id}
                                        className={
                                            share.isActive
                                                ? ''
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <td className="px-5 py-3.5">
                                            <Link
                                                href={browser({
                                                    current_team: slug,
                                                    bucket: share.bucket,
                                                })}
                                                className="font-mono text-[13px] break-all"
                                            >
                                                {share.bucket}/{share.objectKey}
                                            </Link>
                                            {!share.isActive ? (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2 font-normal"
                                                >
                                                    Revoked
                                                </Badge>
                                            ) : null}
                                        </td>
                                        <td className="hidden px-5 py-3.5 text-muted-foreground md:table-cell">
                                            {formatRelative(share.createdAt)}
                                            {share.createdBy
                                                ? ` by ${share.createdBy}`
                                                : ''}
                                        </td>
                                        <td className="hidden px-5 py-3.5 text-muted-foreground lg:table-cell">
                                            {share.lastUsedAt
                                                ? formatRelative(
                                                      share.lastUsedAt,
                                                  )
                                                : 'Never'}
                                        </td>
                                        <td className="px-5 py-3.5 text-right tabular-nums">
                                            {formatNumber(share.downloads)}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            {share.isActive && share.url ? (
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Copy the link to ${share.objectKey}`}
                                                        onClick={() =>
                                                            void copy(
                                                                share.url!,
                                                            )
                                                        }
                                                    >
                                                        <Copy
                                                            className={
                                                                copiedText ===
                                                                share.url
                                                                    ? 'size-4 text-primary'
                                                                    : 'size-4'
                                                            }
                                                        />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test={`revoke-${share.id}`}
                                                        onClick={() =>
                                                            setPending(share)
                                                        }
                                                    >
                                                        <XCircle className="size-3.5" />
                                                        Revoke
                                                    </Button>
                                                </div>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Revoke this link?</DialogTitle>
                        <DialogDescription>
                            The link to{' '}
                            <span className="font-mono break-all">
                                {pending?.objectKey}
                            </span>{' '}
                            stops working immediately, for everyone holding it.
                            The object itself is untouched.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter className="gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setPending(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            data-test="revoke-confirm"
                            onClick={() => pending && revoke(pending)}
                        >
                            Revoke
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Shares.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Shared links',
            href: props.currentTeam ? sharesRoute(props.currentTeam.slug) : '/',
        },
    ],
});
