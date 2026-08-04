import { Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UploadOptions } from '@/hooks/use-object-upload';
import { readPicked  } from '@/lib/dropped-files';
import type {DroppedFile} from '@/lib/dropped-files';
import { formatBytes } from '@/lib/format';

type Props = {
    prefix: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onUpload: (files: DroppedFile[], options: UploadOptions) => void;
};

type Pair = { name: string; value: string };

/**
 * Picking files, with the same knobs the CLI exposes on an upload.
 *
 * Metadata is collapsed by default: it is real, but wanted rarely, and an
 * upload dialog that opens onto a form is slower for the common case.
 */
export default function UploadDialog({
    prefix,
    open,
    onOpenChange,
    onUpload,
}: Props) {
    const filesRef = useRef<HTMLInputElement>(null);
    const folderRef = useRef<HTMLInputElement>(null);

    const [chosen, setChosen] = useState<DroppedFile[]>([]);
    const [contentType, setContentType] = useState('');
    const [pairs, setPairs] = useState<Pair[]>([]);

    const reset = () => {
        setChosen([]);
        setContentType('');
        setPairs([]);
    };

    const handleOpenChange = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            reset();
        }
    };

    const submit = () => {
        const metadata = Object.fromEntries(
            pairs
                .filter((pair) => pair.name.trim() !== '')
                .map((pair) => [pair.name.trim(), pair.value]),
        );

        onUpload(chosen, {
            contentType: contentType.trim() || undefined,
            metadata,
        });

        handleOpenChange(false);
    };

    const total = chosen.reduce((sum, entry) => sum + entry.file.size, 0);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Upload</DialogTitle>
                    <DialogDescription>
                        Files land under{' '}
                        <span className="font-mono text-foreground">
                            {prefix === '' ? 'the bucket root' : prefix}
                        </span>
                        .
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            data-test="upload-choose-files"
                            onClick={() => filesRef.current?.click()}
                        >
                            Choose files
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            data-test="upload-choose-folder"
                            onClick={() => folderRef.current?.click()}
                        >
                            Choose a folder
                        </Button>
                    </div>

                    <input
                        ref={filesRef}
                        type="file"
                        multiple
                        className="hidden"
                        onChange={(event) =>
                            setChosen(
                                event.target.files
                                    ? readPicked(event.target.files)
                                    : [],
                            )
                        }
                    />
                    <input
                        ref={folderRef}
                        type="file"
                        multiple
                        // Not in React's typings, but the only way to pick a
                        // directory; the relative path comes back on each file.
                        {...{ webkitdirectory: '' }}
                        className="hidden"
                        onChange={(event) =>
                            setChosen(
                                event.target.files
                                    ? readPicked(event.target.files)
                                    : [],
                            )
                        }
                    />

                    {chosen.length > 0 ? (
                        <div className="rounded-lg border border-border">
                            <p className="border-b border-border px-3 py-2 text-sm">
                                {chosen.length}{' '}
                                {chosen.length === 1 ? 'file' : 'files'} ·{' '}
                                {formatBytes(total)}
                            </p>
                            <ul className="max-h-40 overflow-y-auto px-3 py-2">
                                {chosen.slice(0, 50).map((entry) => (
                                    <li
                                        key={entry.path}
                                        className="truncate font-mono text-xs text-muted-foreground"
                                    >
                                        {entry.path}
                                    </li>
                                ))}
                                {chosen.length > 50 ? (
                                    <li className="text-xs text-muted-foreground">
                                        and {chosen.length - 50} more
                                    </li>
                                ) : null}
                            </ul>
                        </div>
                    ) : null}

                    <Collapsible>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="px-0"
                            >
                                Metadata and content type
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="grid gap-3 pt-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="upload-content-type">
                                    Content type
                                </Label>
                                <Input
                                    id="upload-content-type"
                                    value={contentType}
                                    onChange={(event) =>
                                        setContentType(event.target.value)
                                    }
                                    placeholder="Guessed from each file"
                                    className="font-mono text-xs"
                                />
                            </div>

                            {pairs.map((pair, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        aria-label="Metadata name"
                                        value={pair.name}
                                        placeholder="owner"
                                        className="font-mono text-xs"
                                        onChange={(event) =>
                                            setPairs(
                                                pairs.map((entry, position) =>
                                                    position === index
                                                        ? {
                                                              ...entry,
                                                              name: event.target
                                                                  .value,
                                                          }
                                                        : entry,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label="Metadata value"
                                        value={pair.value}
                                        placeholder="ada"
                                        className="font-mono text-xs"
                                        onChange={(event) =>
                                            setPairs(
                                                pairs.map((entry, position) =>
                                                    position === index
                                                        ? {
                                                              ...entry,
                                                              value: event
                                                                  .target.value,
                                                          }
                                                        : entry,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove this pair"
                                        onClick={() =>
                                            setPairs(
                                                pairs.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            ))}

                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setPairs([
                                            ...pairs,
                                            { name: '', value: '' },
                                        ])
                                    }
                                >
                                    <Plus className="size-3.5" />
                                    Add a pair
                                </Button>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Stored as x-amz-meta-* and returned on every
                                    GET, exactly as --metadata would.
                                </p>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <DialogFooter className="gap-2">
                    <Button
                        variant="secondary"
                        type="button"
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        data-test="upload-start"
                        disabled={chosen.length === 0}
                        onClick={submit}
                    >
                        Upload{chosen.length > 0 ? ` ${chosen.length}` : ''}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
