import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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

type Props = {
    prefix: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreate: (key: string) => Promise<void>;
};

export default function NewFolderDialog({
    prefix,
    open,
    onOpenChange,
    onCreate,
}: Props) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string>();
    const [saving, setSaving] = useState(false);

    const handleOpenChange = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            setName('');
            setError(undefined);
        }
    };

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setError(undefined);

        try {
            await onCreate(`${prefix}${name.trim()}`);
            handleOpenChange(false);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The folder could not be created.',
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-6">
                    <DialogHeader>
                        <DialogTitle>New folder</DialogTitle>
                        <DialogDescription>
                            S3 has no real folders — this writes an empty object
                            so the prefix shows up before anything is in it.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="folder-name">Name</Label>
                        <Input
                            id="folder-name"
                            data-test="folder-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="invoices"
                            autoComplete="off"
                            autoFocus
                        />
                        <InputError message={error} />
                        <p className="text-xs text-muted-foreground">
                            Created under{' '}
                            <span className="font-mono">
                                {prefix === '' ? 'the bucket root' : prefix}
                            </span>
                            .
                        </p>
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
                            type="submit"
                            data-test="folder-create"
                            disabled={name.trim() === '' || saving}
                        >
                            Create folder
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
