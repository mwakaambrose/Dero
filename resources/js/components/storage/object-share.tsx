import { router, usePage } from '@inertiajs/react';
import { Copy, Globe, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime } from '@/lib/format';
import { accessKeys as accessKeysRoute } from '@/routes/storage';
import { link as signLink } from '@/routes/storage/objects';
import {
    destroy as revokeShare,
    store as createShare,
} from '@/routes/storage/shares';

export type SigningKey = { accessKeyId: string; name: string };
export type ExpiryOption = { value: number; label: string };
export type ShareLinkRow = {
    id: string;
    objectKey: string;
    url: string;
    createdAt: string | null;
    lastUsedAt: string | null;
    downloads: number;
};

/** The select value that means a share link rather than a presigned URL. */
const NEVER = 'never';

type Props = {
    teamSlug: string;
    bucket: string;
    isPublic: boolean;
    /** Null when this installation has no S3 endpoint configured. */
    endpoint: string | null;
    objectKey: string;
    signingKeys: SigningKey[];
    expiryOptions: ExpiryOption[];
    shareLinks: ShareLinkRow[];
};

function CopyField({ label, value }: { label: string; value: string }) {
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

/**
 * Percent-encode each segment while keeping the separators.
 *
 * The key is a path in the URL, so its slashes must survive encoding while
 * everything else in a segment does not.
 */
function encodeKey(key: string): string {
    return key.split('/').map(encodeURIComponent).join('/');
}

/**
 * Links to one object.
 *
 * Two different things share this panel because they answer the same question
 * from opposite sides: a public bucket already has a URL, and a private one
 * needs a link that expires. Which you get is decided by the bucket, not by a
 * choice made here — that is what keeps the panel honest about the difference.
 */
export function ObjectShare({
    teamSlug,
    bucket,
    isPublic,
    endpoint,
    objectKey,
    signingKeys,
    expiryOptions,
    shareLinks,
}: Props) {
    const page = usePage();
    // On the page, not among its props. page.props.objectLink is always
    // undefined, which is a silent nothing rather than an error.
    const flashed = page.flash?.objectLink;

    // Share links are stored, so they are listed for the object being viewed;
    // presigned URLs are not stored and cannot be listed at all.
    const live = shareLinks.filter((link) => link.objectKey === objectKey);

    const [accessKeyId, setAccessKeyId] = useState(
        signingKeys[0]?.accessKeyId ?? '',
    );
    const [expiresIn, setExpiresIn] = useState(
        String(expiryOptions[0]?.value ?? 3600),
    );
    const [signing, setSigning] = useState(false);

    // The flash survives one response, so it is matched to the object that
    // asked for it rather than shown against whatever is open.
    const signed = flashed?.key === objectKey ? flashed : undefined;

    if (endpoint === null) {
        return (
            <p className="text-xs text-muted-foreground">
                This installation has no S3 endpoint configured, so objects have
                no external URL.
            </p>
        );
    }

    const create = () => {
        setSigning(true);

        const options = {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSigning(false),
        };

        // Two genuinely different things behind one control. A presigned URL
        // is standard S3 and dies on its own; a share link is issued by Dero
        // and dies only when revoked, which is why it is stored and listed.
        if (expiresIn === NEVER) {
            router.post(
                createShare({ current_team: teamSlug, bucket }),
                { key: objectKey },
                options,
            );

            return;
        }

        router.post(
            signLink({ current_team: teamSlug, bucket }),
            { key: objectKey, accessKeyId, expiresIn: Number(expiresIn) },
            options,
        );
    };

    const revoke = (id: string) => {
        router.delete(revokeShare({ current_team: teamSlug, shareLink: id }), {
            preserveScroll: true,
        });
    };

    return (
        <div className="space-y-4">
            {isPublic ? (
                <>
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Globe className="size-3.5 text-primary" />
                        This bucket is public, so anyone with the URL can read
                        this object.
                    </p>
                    <CopyField
                        label="Public URL"
                        value={`${endpoint}/${bucket}/${encodeKey(objectKey)}`}
                    />
                </>
            ) : (
                <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <Lock className="mt-px size-3.5 shrink-0" />
                    This bucket is private. Share it with a link that either
                    expires on its own or lasts until you revoke it.
                </p>
            )}

            {signed ? (
                <div className="space-y-1.5">
                    <CopyField
                        label={signed.expiresAt ? 'Signed link' : 'Share link'}
                        value={signed.url}
                    />
                    <p className="text-xs text-muted-foreground">
                        {signed.expiresAt
                            ? `Expires ${formatDateTime(signed.expiresAt)}. Revoking the key that signed it kills the link immediately.`
                            : 'Never expires. It works until it is revoked, so treat it as a credential.'}
                    </p>
                </div>
            ) : null}

            {live.length > 0 ? (
                <div className="space-y-2 rounded-lg border border-border p-3">
                    <h4 className="font-mono text-[10px] tracking-[0.16em] text-muted-foreground uppercase">
                        Live links to this object
                    </h4>
                    {live.map((link) => (
                        <div
                            key={link.id}
                            className="flex items-center gap-2 text-xs"
                        >
                            <span className="flex-1 text-muted-foreground">
                                Created {formatDateTime(link.createdAt)} ·{' '}
                                {link.downloads}{' '}
                                {link.downloads === 1 ? 'use' : 'uses'}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-test={`revoke-${link.id}`}
                                onClick={() => revoke(link.id)}
                            >
                                Revoke
                            </Button>
                        </div>
                    ))}
                </div>
            ) : null}

            <div className="grid gap-3">
                {expiresIn !== NEVER ? (
                    signingKeys.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            A presigned URL is signed by one of your access
                            keys, and this team has none that can read this
                            bucket.{' '}
                            <a
                                href={accessKeysRoute(teamSlug).url}
                                className="text-primary underline underline-offset-2"
                            >
                                Create one
                            </a>
                            , or choose “Until revoked”.
                        </p>
                    ) : (
                        <div className="grid gap-1.5">
                            <Label htmlFor="signing-key">Signed by</Label>
                            <Select
                                value={accessKeyId}
                                onValueChange={setAccessKeyId}
                            >
                                <SelectTrigger
                                    id="signing-key"
                                    data-test="signing-key"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {signingKeys.map((key) => (
                                        <SelectItem
                                            key={key.accessKeyId}
                                            value={key.accessKeyId}
                                        >
                                            {key.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )
                ) : null}

                <div className="grid gap-1.5">
                    <Label htmlFor="link-expiry">Valid for</Label>
                    <Select value={expiresIn} onValueChange={setExpiresIn}>
                        <SelectTrigger
                            id="link-expiry"
                            data-test="link-expiry"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {expiryOptions.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={String(option.value)}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                            <SelectItem value={NEVER}>Until revoked</SelectItem>
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        {expiresIn === NEVER
                            ? 'A Dero link that keeps working until you revoke it. Listed on Shared links so you can find it again.'
                            : 'A standard S3 presigned URL. It expires on its own and needs nothing stored.'}
                    </p>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    data-test="create-signed-link"
                    disabled={
                        signing ||
                        (expiresIn !== NEVER && signingKeys.length === 0)
                    }
                    onClick={create}
                >
                    {signed ? 'Create another link' : 'Create a link'}
                </Button>
            </div>
        </div>
    );
}
