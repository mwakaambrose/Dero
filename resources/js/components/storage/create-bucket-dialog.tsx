import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeBucket } from '@/routes/storage/buckets';
import type { VisibilityOption } from '@/types/storage';

type Props = {
    teamSlug: string;
    region: string;
    visibilities: VisibilityOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function CreateBucketDialog({
    teamSlug,
    region,
    visibilities,
    open,
    onOpenChange,
}: Props) {
    const [visibility, setVisibility] = useState('private');

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setVisibility('private');
        }
    };

    const selected = visibilities.find((option) => option.value === visibility);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...storeBucket.form(teamSlug)}
                    className="space-y-6"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New bucket</DialogTitle>
                                <DialogDescription>
                                    Bucket names are shared across all of Dero
                                    and cannot be changed later.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="bucket-name">Name</Label>
                                    <Input
                                        id="bucket-name"
                                        name="name"
                                        data-test="bucket-name"
                                        placeholder="my-application-assets"
                                        autoComplete="off"
                                        autoFocus
                                        required
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-xs text-muted-foreground">
                                        3–63 characters. Lowercase letters,
                                        numbers, dots and hyphens.
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="bucket-visibility">
                                        Access
                                    </Label>
                                    <Select
                                        name="visibility"
                                        data-test="bucket-visibility"
                                        value={visibility}
                                        onValueChange={setVisibility}
                                    >
                                        <SelectTrigger
                                            id="bucket-visibility"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {visibilities.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.visibility} />
                                    {selected ? (
                                        <p className="text-xs text-muted-foreground">
                                            {selected.description}
                                        </p>
                                    ) : null}
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Region{' '}
                                    <span className="font-mono text-foreground">
                                        {region}
                                    </span>
                                </p>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="bucket-create-confirm"
                                    disabled={processing}
                                >
                                    Create bucket
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
