import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    File as FileIcon,
    FileArchive,
    FileAudio,
    FileCode2,
    FileImage,
    FileJson,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Folder,
    Info,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/storage/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    fileKind,
    formatBytes,
    formatDateTime,
    formatNumber,
} from '@/lib/format';
import { browser, buckets as bucketsRoute } from '@/routes/storage';

type FileRow = {
    key: string;
    name: string;
    sizeBytes: number;
    contentType: string;
    etag: string;
    checksum: string | null;
    isMultipart: boolean;
    metadata: Record<string, string>;
    lastModified: string;
};

type Props = {
    bucket: {
        name: string;
        region: string;
        visibility: string;
        visibilityLabel: string;
        objectCount: number;
        storageBytes: number;
    };
    prefix: string;
    search: string;
    folders: { prefix: string; objectCount: number; storageBytes: number }[];
    files: FileRow[];
};

function iconFor(contentType: string) {
    if (contentType.startsWith('image/')) {
        return FileImage;
    }

    if (contentType.startsWith('video/')) {
        return FileVideo;
    }

    if (contentType.startsWith('audio/')) {
        return FileAudio;
    }

    if (contentType === 'application/pdf') {
        return FileText;
    }

    if (contentType === 'text/csv') {
        return FileSpreadsheet;
    }

    if (contentType === 'application/json') {
        return FileJson;
    }

    if (contentType.includes('gzip') || contentType.includes('zip')) {
        return FileArchive;
    }

    if (contentType.includes('javascript') || contentType.includes('css')) {
        return FileCode2;
    }

    if (contentType.startsWith('text/')) {
        return FileText;
    }

    return FileIcon;
}

export default function Browser({
    bucket,
    prefix,
    search,
    folders,
    files,
}: Props) {
    const slug = usePage().props.currentTeam?.slug ?? '';
    const [query, setQuery] = useState(search);
    const [selected, setSelected] = useState<FileRow | null>(null);

    const segments = prefix.split('/').filter(Boolean);

    const navigate = (nextPrefix: string, nextSearch = '') => {
        router.get(
            browser({ current_team: slug, bucket: bucket.name }),
            nextSearch
                ? { search: nextSearch }
                : nextPrefix
                  ? { prefix: nextPrefix }
                  : {},
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${bucket.name} — Files`} />

            <div className="flex flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {bucket.name}
                        </h1>
                        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                            <span>
                                {formatNumber(bucket.objectCount)} objects
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>{formatBytes(bucket.storageBytes)}</span>
                            <span aria-hidden="true">·</span>
                            <span>{bucket.region}</span>
                            <Badge variant="secondary" className="font-normal">
                                {bucket.visibilityLabel}
                            </Badge>
                        </p>
                    </div>

                    <form
                        className="relative w-full sm:w-72"
                        onSubmit={(event) => {
                            event.preventDefault();
                            navigate('', query);
                        }}
                    >
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search this bucket"
                            className="pl-9"
                            aria-label="Search objects by key"
                        />
                        {query ? (
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    navigate('');
                                }}
                                className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:text-foreground"
                                aria-label="Clear search"
                            >
                                <X className="size-3.5" />
                            </button>
                        ) : null}
                    </form>
                </div>

                {/* Breadcrumbs over synthesised prefixes. */}
                {search === '' ? (
                    <nav
                        aria-label="Prefix"
                        className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
                    >
                        <button
                            type="button"
                            onClick={() => navigate('')}
                            className="rounded px-1.5 py-0.5 hover:text-foreground"
                        >
                            {bucket.name}
                        </button>
                        {segments.map((segment, index) => {
                            const target = `${segments.slice(0, index + 1).join('/')}/`;

                            return (
                                <span
                                    key={target}
                                    className="flex items-center gap-1"
                                >
                                    <ChevronRight className="size-3.5" />
                                    <button
                                        type="button"
                                        onClick={() => navigate(target)}
                                        className={`rounded px-1.5 py-0.5 hover:text-foreground ${
                                            index === segments.length - 1
                                                ? 'text-foreground'
                                                : ''
                                        }`}
                                    >
                                        {segment}
                                    </button>
                                </span>
                            );
                        })}
                    </nav>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Showing matches for{' '}
                        <span className="font-mono text-foreground">
                            {search}
                        </span>{' '}
                        across the whole bucket.
                    </p>
                )}

                {folders.length === 0 && files.length === 0 ? (
                    <EmptyState
                        icon={Folder}
                        title={
                            search
                                ? 'No objects match that search'
                                : 'Nothing here yet'
                        }
                        body={
                            search
                                ? 'Try a shorter fragment of the key, or clear the search to browse by prefix.'
                                : 'Upload an object with your S3 client and it will appear here.'
                        }
                        action={
                            search ? (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setQuery('');
                                        navigate('');
                                    }}
                                >
                                    Clear search
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left">
                                    <th className="px-5 py-3 font-medium">
                                        Name
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium sm:table-cell">
                                        Type
                                    </th>
                                    <th className="px-5 py-3 text-right font-medium">
                                        Size
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium lg:table-cell">
                                        Modified
                                    </th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {folders.map((folder) => (
                                    <tr
                                        key={folder.prefix}
                                        className="cursor-pointer transition-colors hover:bg-accent/50"
                                        onClick={() => navigate(folder.prefix)}
                                    >
                                        <td className="px-5 py-3">
                                            <span className="flex items-center gap-2.5 font-medium">
                                                <Folder className="size-4 text-primary" />
                                                {folder.prefix
                                                    .slice(prefix.length)
                                                    .replace(/\/$/, '')}
                                            </span>
                                        </td>
                                        <td className="hidden px-5 py-3 text-muted-foreground sm:table-cell">
                                            Prefix
                                        </td>
                                        <td className="px-5 py-3 text-right text-muted-foreground tabular-nums">
                                            {formatBytes(folder.storageBytes)}
                                        </td>
                                        <td className="hidden px-5 py-3 text-muted-foreground lg:table-cell">
                                            {formatNumber(folder.objectCount)}{' '}
                                            objects
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <ChevronRight className="ml-auto size-4 text-muted-foreground" />
                                        </td>
                                    </tr>
                                ))}

                                {files.map((file) => {
                                    const Icon = iconFor(file.contentType);

                                    return (
                                        <tr
                                            key={file.key}
                                            className="cursor-pointer transition-colors hover:bg-accent/50"
                                            onClick={() => setSelected(file)}
                                        >
                                            <td className="px-5 py-3">
                                                <span className="flex items-center gap-2.5">
                                                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                    <span className="truncate font-mono text-[13px]">
                                                        {file.name}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="hidden px-5 py-3 text-muted-foreground sm:table-cell">
                                                {fileKind(file.contentType)}
                                            </td>
                                            <td className="px-5 py-3 text-right tabular-nums">
                                                {formatBytes(file.sizeBytes)}
                                            </td>
                                            <td className="hidden px-5 py-3 text-muted-foreground lg:table-cell">
                                                {formatDateTime(
                                                    file.lastModified,
                                                )}
                                            </td>
                                            <td className="px-5 py-3 text-right">
                                                <Info className="ml-auto size-4 text-muted-foreground" />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    {selected ? (
                        <>
                            <SheetHeader>
                                <SheetTitle className="font-mono text-sm break-all">
                                    {selected.key}
                                </SheetTitle>
                                <SheetDescription>
                                    {fileKind(selected.contentType)} ·{' '}
                                    {formatBytes(selected.sizeBytes)}
                                </SheetDescription>
                            </SheetHeader>

                            <dl className="space-y-4 px-4 pb-6 text-sm">
                                {[
                                    ['Content type', selected.contentType],
                                    [
                                        'Size',
                                        `${formatNumber(selected.sizeBytes)} bytes`,
                                    ],
                                    ['ETag', selected.etag],
                                    ['SHA-256', selected.checksum ?? '—'],
                                    [
                                        'Storage',
                                        selected.isMultipart
                                            ? 'Multipart'
                                            : 'Single part',
                                    ],
                                    [
                                        'Last modified',
                                        formatDateTime(selected.lastModified),
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="mb-1 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
                                            {label}
                                        </dt>
                                        <dd className="font-mono text-xs break-all">
                                            {value}
                                        </dd>
                                    </div>
                                ))}

                                {Object.keys(selected.metadata).length > 0 ? (
                                    <div>
                                        <dt className="mb-1.5 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
                                            Metadata
                                        </dt>
                                        <dd className="space-y-1">
                                            {Object.entries(
                                                selected.metadata,
                                            ).map(([name, value]) => (
                                                <div
                                                    key={name}
                                                    className="flex gap-2 font-mono text-xs"
                                                >
                                                    <span className="text-muted-foreground">
                                                        x-amz-meta-{name}
                                                    </span>
                                                    <span className="break-all">
                                                        {value}
                                                    </span>
                                                </div>
                                            ))}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>
                        </>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}

Browser.layout = (props: {
    currentTeam?: { slug: string } | null;
    bucket: { name: string };
}) => ({
    breadcrumbs: [
        {
            title: 'Buckets',
            href: props.currentTeam
                ? bucketsRoute(props.currentTeam.slug)
                : '/',
        },
        {
            title: props.bucket.name,
            href: props.currentTeam
                ? browser({
                      current_team: props.currentTeam.slug,
                      bucket: props.bucket.name,
                  })
                : '/',
        },
    ],
});
