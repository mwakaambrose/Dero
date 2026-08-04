import { Head } from '@inertiajs/react';
import { Copy, Plug } from 'lucide-react';
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
import { connect as connectRoute } from '@/routes/storage';

type Props = {
    endpoint: string;
    region: string;
    accessKeys: { accessKeyId: string; name: string }[];
    buckets: string[];
};

const CLIENTS = [
    'AWS CLI',
    'Laravel',
    'Python',
    'JavaScript',
    'rclone',
] as const;

type Client = (typeof CLIENTS)[number];

function snippetFor(
    client: Client,
    endpoint: string,
    region: string,
    keyId: string,
    bucket: string,
): string {
    switch (client) {
        case 'AWS CLI':
            return `aws configure set aws_access_key_id ${keyId} --profile dero
aws configure set aws_secret_access_key YOUR_SECRET --profile dero
aws configure set region ${region} --profile dero

aws s3 ls s3://${bucket}/ \\
  --endpoint-url ${endpoint} --profile dero`;

        case 'Laravel':
            return `FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=${keyId}
AWS_SECRET_ACCESS_KEY=YOUR_SECRET
AWS_DEFAULT_REGION=${region}
AWS_BUCKET=${bucket}
AWS_ENDPOINT=${endpoint}
AWS_USE_PATH_STYLE_ENDPOINT=true`;

        case 'Python':
            return `import boto3

s3 = boto3.client(
    "s3",
    endpoint_url="${endpoint}",
    region_name="${region}",
    aws_access_key_id="${keyId}",
    aws_secret_access_key="YOUR_SECRET",
)

s3.upload_file("report.pdf", "${bucket}", "report.pdf")`;

        case 'JavaScript':
            return `import { S3Client, PutObjectCommand } from "@aws-sdk/client-s3";

const s3 = new S3Client({
  endpoint: "${endpoint}",
  region: "${region}",
  forcePathStyle: true,
  credentials: {
    accessKeyId: "${keyId}",
    secretAccessKey: "YOUR_SECRET",
  },
});

await s3.send(new PutObjectCommand({
  Bucket: "${bucket}",
  Key: "report.pdf",
  Body: contents,
}));`;

        case 'rclone':
            return `[dero]
type = s3
provider = Other
endpoint = ${endpoint}
region = ${region}
access_key_id = ${keyId}
secret_access_key = YOUR_SECRET
force_path_style = true

# rclone ls dero:${bucket}`;
    }
}

export default function Connect({
    endpoint,
    region,
    accessKeys,
    buckets,
}: Props) {
    const [client, setClient] = useState<Client>('AWS CLI');
    const [keyId, setKeyId] = useState(
        accessKeys[0]?.accessKeyId ?? 'YOUR_ACCESS_KEY_ID',
    );
    const [bucket, setBucket] = useState(buckets[0] ?? 'your-bucket');
    const [copiedText, copy] = useClipboard();

    const snippet = snippetFor(client, endpoint, region, keyId, bucket);

    return (
        <>
            <Head title="Connect a client" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Connect a client
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Configuration for this installation, filled in with your
                        endpoint and key.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <Label className="mb-1.5 block text-xs">Client</Label>
                        <Select
                            value={client}
                            onValueChange={(value) =>
                                setClient(value as Client)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CLIENTS.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label className="mb-1.5 block text-xs">
                            Access key
                        </Label>
                        <Select
                            value={keyId}
                            onValueChange={setKeyId}
                            disabled={accessKeys.length === 0}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="No keys yet" />
                            </SelectTrigger>
                            <SelectContent>
                                {accessKeys.map((key) => (
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

                    <div>
                        <Label className="mb-1.5 block text-xs">Bucket</Label>
                        <Select
                            value={bucket}
                            onValueChange={setBucket}
                            disabled={buckets.length === 0}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="No buckets yet" />
                            </SelectTrigger>
                            <SelectContent>
                                {buckets.map((name) => (
                                    <SelectItem key={name} value={name}>
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="relative">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => void copy(snippet)}
                        className="absolute top-3 right-3 gap-1.5 text-xs"
                    >
                        <Copy className="size-3.5" />
                        {copiedText === snippet ? 'Copied' : 'Copy'}
                    </Button>
                    <pre className="overflow-x-auto rounded-xl border border-border bg-card p-5 pt-12 font-mono text-[13px] leading-relaxed">
                        <code>{snippet}</code>
                    </pre>
                </div>

                <div className="flex gap-3 rounded-xl border border-border bg-secondary/40 p-4 text-sm">
                    <Plug className="mt-0.5 size-4 shrink-0 text-primary" />
                    <p className="leading-relaxed text-muted-foreground">
                        Replace{' '}
                        <code className="font-mono text-foreground">
                            YOUR_SECRET
                        </code>{' '}
                        with the secret shown when you created the key. Dero
                        stores secrets encrypted and cannot display them again.
                    </p>
                </div>
            </div>
        </>
    );
}

Connect.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Connect',
            href: props.currentTeam
                ? connectRoute(props.currentTeam.slug)
                : '/',
        },
    ],
});
