import { AlertCircle, Check, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { UploadItem } from '@/hooks/use-object-upload';
import { formatBytes } from '@/lib/format';

/**
 * The docked progress list.
 *
 * It stays out of the way of the file table because an upload is something you
 * start and then keep working around, not something you watch.
 */
export function UploadDock({
    items,
    onCancel,
    onDismiss,
}: {
    items: UploadItem[];
    onCancel: (id: string) => void;
    onDismiss: () => void;
}) {
    if (items.length === 0) {
        return null;
    }

    const active = items.filter(
        (item) => item.status === 'queued' || item.status === 'uploading',
    );

    return (
        <div
            data-test="upload-dock"
            className="fixed right-4 bottom-4 z-50 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-card shadow-lg"
        >
            <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
                <h2 className="text-sm font-medium">
                    {active.length > 0
                        ? `Uploading ${active.length} ${active.length === 1 ? 'file' : 'files'}`
                        : 'Uploads finished'}
                </h2>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onDismiss}
                    aria-label="Dismiss finished uploads"
                >
                    <X className="size-4" />
                </Button>
            </div>

            <ul className="max-h-72 divide-y divide-border overflow-y-auto">
                {items.map((item) => {
                    const ratio =
                        item.sizeBytes === 0
                            ? 1
                            : Math.min(1, item.sentBytes / item.sizeBytes);

                    return (
                        <li key={item.id} className="px-4 py-2.5">
                            <div className="flex items-center gap-2">
                                <span className="flex-1 truncate font-mono text-xs">
                                    {item.name}
                                </span>

                                {item.status === 'done' ? (
                                    <Check className="size-3.5 text-primary" />
                                ) : null}

                                {item.status === 'failed' ? (
                                    <AlertCircle className="size-3.5 text-destructive" />
                                ) : null}

                                {item.status === 'queued' ||
                                item.status === 'uploading' ? (
                                    <button
                                        type="button"
                                        onClick={() => onCancel(item.id)}
                                        className="text-muted-foreground hover:text-foreground"
                                        aria-label={`Cancel ${item.name}`}
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                ) : null}
                            </div>

                            {item.status === 'uploading' ||
                            item.status === 'queued' ? (
                                <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-secondary">
                                    <div
                                        className="h-full bg-primary transition-[width]"
                                        style={{ width: `${ratio * 100}%` }}
                                    />
                                </div>
                            ) : null}

                            <p className="mt-1 text-xs text-muted-foreground">
                                {item.status === 'failed'
                                    ? (item.error ?? 'Upload failed.')
                                    : item.status === 'cancelled'
                                      ? 'Cancelled'
                                      : item.status === 'done'
                                        ? formatBytes(item.sizeBytes)
                                        : `${formatBytes(item.sentBytes)} of ${formatBytes(item.sizeBytes)}`}
                            </p>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
