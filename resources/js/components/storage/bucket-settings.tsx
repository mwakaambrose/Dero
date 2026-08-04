import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { CorsRulesEditor } from '@/components/storage/cors-rules-editor';
import { Section } from '@/components/storage/section';
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
import { formatDateTime, formatNumber } from '@/lib/format';
import { destroy, update } from '@/routes/storage/buckets';
import type { CorsRuleInput, VisibilityOption } from '@/types';

type Bucket = {
    name: string;
    region: string;
    visibility: string;
    objectCount: number;
    createdAt: string | null;
    corsRules: CorsRuleInput[];
};

/**
 * Drops the blank lines the textareas keep while you type, so the server never
 * sees an empty origin or header.
 */
function clean(rules: CorsRuleInput[]): CorsRuleInput[] {
    const lines = (values: string[]) =>
        values.map((value) => value.trim()).filter((value) => value !== '');

    return rules.map((rule) => ({
        allowedOrigins: lines(rule.allowedOrigins),
        allowedMethods: rule.allowedMethods,
        allowedHeaders: lines(rule.allowedHeaders),
        exposeHeaders: lines(rule.exposeHeaders),
        maxAgeSeconds: rule.maxAgeSeconds,
    }));
}

export function BucketSettings({
    bucket,
    teamSlug,
    visibilities,
}: {
    bucket: Bucket;
    teamSlug: string;
    visibilities: VisibilityOption[];
}) {
    const [deleteOpen, setDeleteOpen] = useState(false);

    const form = useForm({
        visibility: bucket.visibility,
        corsRules: bucket.corsRules,
    });

    // The typed name lives on the form so the server's refusal to delete a
    // non-empty bucket has a field to land on.
    const deleteForm = useForm({ name: '' });

    const target = { current_team: teamSlug, bucket: bucket.name };
    const selected = visibilities.find(
        (option) => option.value === form.data.visibility,
    );

    const save = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            corsRules: clean(data.corsRules),
        }));

        form.submit(update(target), { preserveScroll: true });
    };

    const closeDelete = (open: boolean) => {
        setDeleteOpen(open);

        if (!open) {
            deleteForm.reset();
            deleteForm.clearErrors();
        }
    };

    return (
        <>
            <form onSubmit={save}>
                <Section eyebrow="General" className="border-t-0 px-0 md:px-0">
                    <div className="grid max-w-xl gap-5">
                        <div className="grid gap-1.5">
                            <Label htmlFor="visibility">Access</Label>
                            <Select
                                value={form.data.visibility}
                                onValueChange={(value) =>
                                    form.setData('visibility', value)
                                }
                            >
                                <SelectTrigger
                                    id="visibility"
                                    data-test="bucket-settings-visibility"
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
                            {selected ? (
                                <p className="text-xs text-muted-foreground">
                                    {selected.description}
                                </p>
                            ) : null}
                            <InputError message={form.errors.visibility} />
                        </div>

                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="font-mono text-[10px] tracking-[0.16em] text-muted-foreground uppercase">
                                    Region
                                </dt>
                                <dd className="mt-1">{bucket.region}</dd>
                            </div>
                            <div>
                                <dt className="font-mono text-[10px] tracking-[0.16em] text-muted-foreground uppercase">
                                    Created
                                </dt>
                                <dd className="mt-1">
                                    {formatDateTime(bucket.createdAt)}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Section>

                <Section eyebrow="CORS" className="px-0 md:px-0">
                    <div className="max-w-2xl">
                        <p className="mb-4 text-sm text-muted-foreground">
                            Which browser origins may call this bucket directly.
                            Rules are matched in order — the first rule matching
                            both the origin and the method wins.
                        </p>

                        <CorsRulesEditor
                            rules={form.data.corsRules}
                            errors={form.errors as Record<string, string>}
                            onChange={(corsRules) =>
                                form.setData('corsRules', corsRules)
                            }
                        />
                    </div>
                </Section>

                <div className="flex items-center gap-3 pt-2">
                    <Button
                        type="submit"
                        data-test="bucket-settings-save"
                        disabled={form.processing}
                    >
                        Save changes
                    </Button>
                    {form.recentlySuccessful ? (
                        <p className="text-sm text-muted-foreground">Saved</p>
                    ) : null}
                </div>
            </form>

            <Section eyebrow="Danger zone" className="mt-4 px-0 md:px-0">
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-destructive/40 p-4">
                    <div>
                        <h3 className="text-sm font-medium">Delete bucket</h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Permanently removes this bucket. It must be empty
                            first — Dero will not delete your objects for you.
                        </p>
                    </div>

                    <Button
                        variant="destructive"
                        data-test="bucket-delete"
                        onClick={() => setDeleteOpen(true)}
                    >
                        Delete bucket
                    </Button>
                </div>
            </Section>

            <Dialog open={deleteOpen} onOpenChange={closeDelete}>
                <DialogContent>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            deleteForm.submit(destroy(target), {
                                onSuccess: () => closeDelete(false),
                            });
                        }}
                        className="space-y-6"
                    >
                        <DialogHeader>
                            <DialogTitle>Are you sure?</DialogTitle>
                            <DialogDescription>
                                This cannot be undone. The bucket{' '}
                                <strong>"{bucket.name}"</strong> and its
                                settings will be permanently deleted.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2 py-2">
                            <Label htmlFor="bucket-confirmation">
                                Type <strong>"{bucket.name}"</strong> to confirm
                            </Label>
                            <Input
                                id="bucket-confirmation"
                                data-test="bucket-delete-name"
                                value={deleteForm.data.name}
                                onChange={(event) =>
                                    deleteForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                                placeholder="Enter bucket name"
                                autoComplete="off"
                            />
                            <InputError message={deleteForm.errors.name} />
                            {bucket.objectCount > 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    This bucket still holds{' '}
                                    {formatNumber(bucket.objectCount)} objects.
                                    Empty it before deleting.
                                </p>
                            ) : null}
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    Cancel
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                data-test="bucket-delete-confirm"
                                disabled={
                                    deleteForm.data.name !== bucket.name ||
                                    deleteForm.processing
                                }
                            >
                                Delete bucket
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
