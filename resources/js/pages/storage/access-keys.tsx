import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Copy,
    KeyRound,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/storage/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { formatRelative } from '@/lib/format';
import { accessKeys as accessKeysRoute } from '@/routes/storage';
import {
    store as storeKey,
    revoke as revokeKey,
    destroy as destroyKey,
} from '@/routes/storage/access-keys';

type AccessKeyRow = {
    id: string;
    name: string;
    accessKeyId: string;
    permissions: string[];
    bucketScope: string[] | null;
    lastUsedAt: string | null;
    createdAt: string | null;
    revokedAt: string | null;
    isActive: boolean;
};

type Props = {
    accessKeys: AccessKeyRow[];
    permissions: { value: string; label: string }[];
    buckets: string[];
    endpoint: string;
    region: string;
};

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copiedText, copy] = useClipboard();

    return (
        <div>
            <Label className="mb-1.5 block font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </Label>
            <div className="flex gap-2">
                <code className="flex-1 truncate rounded-md border border-border bg-secondary px-3 py-2 font-mono text-xs">
                    {value}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => void copy(value)}
                    aria-label={`Copy ${label}`}
                >
                    <Copy className="size-4" />
                </Button>
            </div>
            {copiedText === value ? (
                <p className="mt-1 text-xs text-primary">Copied</p>
            ) : null}
        </div>
    );
}

export default function AccessKeys({
    accessKeys,
    permissions,
    buckets,
    endpoint,
    region,
}: Props) {
    const page = usePage();
    const slug = page.props.currentTeam?.slug ?? '';
    // On the page, not among its props. page.props.newAccessKey is always
    // undefined, which is a silent nothing rather than an error.
    const newKey = page.flash?.newAccessKey;

    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Access keys" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Access keys
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Credentials for the S3 API. A secret is shown once,
                            when the key is created.
                        </p>
                    </div>
                    <Button onClick={() => setCreating(true)} className="gap-2">
                        <Plus className="size-4" />
                        Create key
                    </Button>
                </div>

                {accessKeys.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        title="No access keys yet"
                        body="Create a key to let the AWS CLI, rclone, or your application talk to this storage."
                        action={
                            <Button onClick={() => setCreating(true)}>
                                Create key
                            </Button>
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
                                    <th className="hidden px-5 py-3 font-medium md:table-cell">
                                        Key ID
                                    </th>
                                    <th className="hidden px-5 py-3 font-medium lg:table-cell">
                                        Permissions
                                    </th>
                                    <th className="px-5 py-3 font-medium">
                                        Last used
                                    </th>
                                    <th className="px-5 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {accessKeys.map((key) => (
                                    <tr
                                        key={key.id}
                                        className="transition-colors hover:bg-accent/50"
                                    >
                                        <td className="px-5 py-3.5">
                                            <span className="flex items-center gap-2 font-medium">
                                                {key.name}
                                                {!key.isActive ? (
                                                    <Badge
                                                        variant="destructive"
                                                        className="font-normal"
                                                    >
                                                        Revoked
                                                    </Badge>
                                                ) : null}
                                            </span>
                                            <span className="text-xs text-muted-foreground md:hidden">
                                                {key.accessKeyId}
                                            </span>
                                        </td>
                                        <td className="hidden px-5 py-3.5 font-mono text-xs md:table-cell">
                                            {key.accessKeyId}
                                        </td>
                                        <td className="hidden px-5 py-3.5 lg:table-cell">
                                            <span className="flex flex-wrap gap-1">
                                                {key.permissions.map(
                                                    (permission) => (
                                                        <Badge
                                                            key={permission}
                                                            variant="secondary"
                                                            className="font-normal"
                                                        >
                                                            {permission}
                                                        </Badge>
                                                    ),
                                                )}
                                                {key.bucketScope ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="font-normal"
                                                    >
                                                        {key.bucketScope.join(
                                                            ', ',
                                                        )}
                                                    </Badge>
                                                ) : null}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3.5 text-muted-foreground">
                                            {formatRelative(key.lastUsedAt)}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <div className="flex items-center justify-end gap-1">
                                                {key.isActive ? (
                                                    <Form
                                                        action={revokeKey({
                                                            current_team: slug,
                                                            accessKey: key.id,
                                                        })}
                                                        method="post"
                                                    >
                                                        <Button
                                                            type="submit"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="gap-1.5 text-muted-foreground"
                                                        >
                                                            <XCircle className="size-3.5" />
                                                            Revoke
                                                        </Button>
                                                    </Form>
                                                ) : null}
                                                <Form
                                                    action={destroyKey({
                                                        current_team: slug,
                                                        accessKey: key.id,
                                                    })}
                                                    method="delete"
                                                >
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground hover:text-destructive"
                                                        aria-label={`Delete ${key.name}`}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </Form>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="rounded-xl border border-border bg-card p-5">
                    <h2 className="mb-4 font-medium">Endpoint</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <CopyRow label="Endpoint URL" value={endpoint} />
                        <CopyRow label="Region" value={region} />
                    </div>
                </div>
            </div>

            {/* Create */}
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-lg">
                    <Form
                        action={storeKey(slug)}
                        method="post"
                        onSuccess={() => setCreating(false)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Create access key</DialogTitle>
                                    <DialogDescription>
                                        The secret appears once, immediately
                                        after creation.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-5 py-4">
                                    <div>
                                        <Label
                                            htmlFor="name"
                                            className="mb-1.5 block"
                                        >
                                            Name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            placeholder="Production backups"
                                            required
                                        />
                                        {errors.name ? (
                                            <p className="mt-1 text-xs text-destructive">
                                                {errors.name}
                                            </p>
                                        ) : null}
                                    </div>

                                    <fieldset>
                                        <legend className="mb-2 text-sm font-medium">
                                            Permissions
                                        </legend>
                                        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                                            {permissions.map((permission) => (
                                                <label
                                                    key={permission.value}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        name="permissions[]"
                                                        value={permission.value}
                                                        defaultChecked={
                                                            permission.value ===
                                                                'read' ||
                                                            permission.value ===
                                                                'list'
                                                        }
                                                    />
                                                    {permission.label}
                                                </label>
                                            ))}
                                        </div>
                                        {errors.permissions ? (
                                            <p className="mt-1 text-xs text-destructive">
                                                {errors.permissions}
                                            </p>
                                        ) : null}
                                    </fieldset>

                                    {buckets.length > 0 ? (
                                        <fieldset>
                                            <legend className="mb-1 text-sm font-medium">
                                                Limit to buckets
                                            </legend>
                                            <p className="mb-2 text-xs text-muted-foreground">
                                                Leave all unchecked to allow
                                                every bucket in this team.
                                            </p>
                                            <div className="grid grid-cols-2 gap-2.5">
                                                {buckets.map((bucket) => (
                                                    <label
                                                        key={bucket}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            name="bucketScope[]"
                                                            value={bucket}
                                                        />
                                                        {bucket}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    ) : null}
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCreating(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Create key
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            {/* Secret, shown once */}
            <Dialog open={Boolean(newKey)} onOpenChange={() => router.reload()}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Save your secret key</DialogTitle>
                        <DialogDescription>
                            This is the only time the secret is shown. Store it
                            somewhere safe before closing.
                        </DialogDescription>
                    </DialogHeader>

                    {newKey ? (
                        <div className="space-y-4 py-2">
                            <div className="flex gap-3 rounded-lg border border-primary/40 bg-primary/[0.06] p-3">
                                <AlertTriangle className="size-4 shrink-0 text-primary" />
                                <p className="text-xs leading-relaxed">
                                    Dero stores the secret encrypted and cannot
                                    show it again. Lose it and you will need to
                                    create a new key.
                                </p>
                            </div>
                            <CopyRow
                                label="Access key ID"
                                value={newKey.accessKeyId}
                            />
                            <CopyRow
                                label="Secret access key"
                                value={newKey.secretKey}
                            />
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button onClick={() => router.reload()}>
                            I&rsquo;ve saved it
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

AccessKeys.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Access keys',
            href: props.currentTeam
                ? accessKeysRoute(props.currentTeam.slug)
                : '/',
        },
    ],
});
