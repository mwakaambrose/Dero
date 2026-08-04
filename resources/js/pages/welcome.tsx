import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Boxes,
    CheckCircle2,
    Clock3,
    FileKey2,
    KeyRound,
    Layers,
    MinusCircle,
    Radio,
    Scissors,
    ShieldCheck,
    Terminal,
} from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { CodeBlock } from '@/components/code-block';
import type { CodeLanguage } from '@/components/code-block';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

type Snippet = {
    id: string;
    label: string;
    filename: string;
    language: CodeLanguage;
    code: string;
};

const SNIPPETS: Snippet[] = [
    {
        id: 'cli',
        label: 'AWS CLI',
        filename: 'terminal',
        language: 'bash',
        code: `aws configure --profile dero
# Access key ID and secret come from your Dero dashboard

aws s3 mb s3://production \\
  --endpoint-url https://s3.example.com --profile dero

aws s3 cp report.pdf s3://production/report.pdf \\
  --endpoint-url https://s3.example.com --profile dero`,
    },
    {
        id: 'laravel',
        label: 'Laravel',
        filename: '.env',
        language: 'ini',
        code: `FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=DERO...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=production
AWS_ENDPOINT=https://s3.example.com
AWS_USE_PATH_STYLE_ENDPOINT=true`,
    },
    {
        id: 'python',
        label: 'Python',
        filename: 'upload.py',
        language: 'python',
        code: `import boto3

s3 = boto3.client(
    "s3",
    endpoint_url="https://s3.example.com",
    aws_access_key_id="DERO...",
    aws_secret_access_key="...",
)

s3.upload_file("report.pdf", "production", "report.pdf")`,
    },
    {
        id: 'rclone',
        label: 'rclone',
        filename: 'rclone.conf',
        language: 'ini',
        code: `[dero]
type = s3
provider = Other
endpoint = https://s3.example.com
access_key_id = DERO...
secret_access_key = ...
force_path_style = true`,
    },
];

const FEATURES = [
    {
        icon: KeyRound,
        title: 'Signature V4 authentication',
        body: 'The request signing every AWS SDK already speaks, including the chunked upload framing the CLI uses.',
    },
    {
        icon: Layers,
        title: 'Multipart uploads',
        body: 'Large objects arrive in parts and are assembled server-side, with the ETag AWS clients expect.',
    },
    {
        icon: Scissors,
        title: 'Range requests',
        body: 'Byte ranges return 206 with a correct Content-Range, so video seeking and resumable downloads work.',
    },
    {
        icon: FileKey2,
        title: 'Presigned URLs',
        body: 'Share a private object for five minutes or seven days. Opening one needs no dashboard session.',
    },
    {
        icon: Boxes,
        title: 'Streaming, end to end',
        body: 'Bytes move in fixed-size chunks. Memory stays flat whether the object is one kilobyte or one gigabyte.',
    },
    {
        icon: ShieldCheck,
        title: 'Scoped access keys',
        body: 'Grant read, write, delete or list, optionally confined to named buckets. Revoke any key instantly.',
    },
];

const COMPATIBILITY: { feature: string; supported: boolean; note: string }[] = [
    {
        feature: 'Bucket create, list, head, delete',
        supported: true,
        note: 'path and virtual-host addressing',
    },
    {
        feature: 'Object put, get, head, delete',
        supported: true,
        note: 'streamed in both directions',
    },
    {
        feature: 'ListObjectsV2',
        supported: true,
        note: 'prefix, delimiter, pagination',
    },
    {
        feature: 'Multipart upload',
        supported: true,
        note: 'AWS-compatible multipart ETag',
    },
    { feature: 'Range requests', supported: true, note: 'single range' },
    {
        feature: 'CopyObject',
        supported: true,
        note: 'server-side, metadata directives',
    },
    {
        feature: 'Versioning',
        supported: false,
        note: 'columns reserved, not implemented',
    },
    {
        feature: 'Object lock and retention',
        supported: false,
        note: 'not implemented',
    },
    {
        feature: 'Lifecycle and replication',
        supported: false,
        note: 'not implemented',
    },
    {
        feature: 'Server-side encryption',
        supported: false,
        note: 'use disk encryption on the host',
    },
];

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const [active, setActive] = useState(SNIPPETS[0].id);
    const snippet = SNIPPETS.find((item) => item.id === active) ?? SNIPPETS[0];
    const signedIn = Boolean(auth?.user);
    const primaryHref =
        signedIn && currentTeam ? dashboard(currentTeam.slug) : register();

    return (
        <>
            <Head title="Dero — object storage you keep" />

            <div className="min-h-dvh bg-background text-foreground">
                <header className="sticky top-0 z-40 border-b border-border/70 bg-background/85 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                        <span className="flex items-center gap-2.5 font-display text-lg font-semibold tracking-tight">
                            <AppLogoIcon className="size-7 text-primary" />
                            Dero
                        </span>
                        <nav className="flex items-center gap-2">
                            {signedIn ? (
                                <Button asChild size="sm">
                                    <Link href={primaryHref}>Dashboard</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={login()}>Sign in</Link>
                                    </Button>
                                    <Button asChild size="sm">
                                        <Link href={register()}>
                                            Get started
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {/* Hero. The weave motif appears here and nowhere else. */}
                <section className="relative overflow-hidden border-b border-border/70">
                    <div
                        className="dero-weave pointer-events-none absolute inset-0"
                        aria-hidden="true"
                    />
                    <div className="relative mx-auto max-w-6xl px-6 py-24 md:py-32">
                        <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-1 font-mono text-xs tracking-wide text-muted-foreground uppercase">
                            <Radio className="size-3 text-primary" />
                            Self-hosted object storage
                        </p>

                        <h1 className="max-w-3xl text-5xl leading-[1.05] font-semibold tracking-[-0.02em] text-balance md:text-7xl">
                            Storage you keep,
                            <br />
                            on a server you own.
                        </h1>

                        <p className="mt-7 max-w-xl text-lg leading-relaxed text-muted-foreground">
                            Dero speaks the S3 API, so the tools you already use
                            work unchanged. Point them at your own machine
                            instead of someone else&rsquo;s cloud.
                        </p>

                        <div className="mt-10 flex flex-wrap items-center gap-3">
                            <Button asChild size="lg" className="gap-2">
                                <Link href={primaryHref}>
                                    {signedIn
                                        ? 'Open dashboard'
                                        : 'Set up your storage'}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                            {!signedIn && (
                                <Button asChild size="lg" variant="outline">
                                    <Link href={login()}>Sign in</Link>
                                </Button>
                            )}
                        </div>

                        <p className="mt-10 max-w-md border-l-2 border-primary/50 pl-4 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                Dero
                            </span>{' '}
                            is the granary of the Luo and Acholi: a woven basket
                            raised on legs, roofed with thatch, keeping the
                            harvest dry and out of reach.
                        </p>
                    </div>
                </section>

                <section className="border-b border-border/70">
                    <div className="mx-auto grid max-w-6xl gap-10 px-6 py-20 md:grid-cols-3">
                        <h2 className="text-2xl font-semibold tracking-tight md:col-span-1">
                            Two bad options, until now
                        </h2>
                        <div className="grid gap-6 md:col-span-2 md:grid-cols-2">
                            <div className="rounded-xl border border-border bg-card p-6">
                                <p className="mb-2 font-medium">
                                    Someone else&rsquo;s cloud
                                </p>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Straightforward to start, then the egress
                                    bill arrives and your data lives somewhere
                                    you cannot inspect.
                                </p>
                            </div>
                            <div className="rounded-xl border border-border bg-card p-6">
                                <p className="mb-2 font-medium">
                                    A cluster to operate
                                </p>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Full control, at the cost of running
                                    distributed storage you now have to keep
                                    alive yourself.
                                </p>
                            </div>
                            <div className="rounded-xl border border-primary/40 bg-primary/[0.06] p-6 md:col-span-2">
                                <p className="mb-2 font-medium">
                                    Dero: one application, one server
                                </p>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Install it on a VPS, point your S3 client at
                                    it, and carry on. No cluster, no egress
                                    charges, no vendor.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="border-b border-border/70">
                    <div className="mx-auto max-w-6xl px-6 py-20">
                        <h2 className="mb-12 text-2xl font-semibold tracking-tight">
                            What it does
                        </h2>
                        <div className="grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                            {FEATURES.map((feature) => (
                                <div key={feature.title}>
                                    <feature.icon className="mb-4 size-5 text-primary" />
                                    <h3 className="mb-2 font-medium">
                                        {feature.title}
                                    </h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {feature.body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="border-b border-border/70 bg-secondary/40">
                    <div className="mx-auto max-w-6xl px-6 py-20">
                        <div className="mb-8 flex flex-wrap items-end justify-between gap-6">
                            <div>
                                <h2 className="text-2xl font-semibold tracking-tight">
                                    Works with what you already use
                                </h2>
                                <p className="mt-2 max-w-md text-sm text-muted-foreground">
                                    Change the endpoint. Everything else stays
                                    the same.
                                </p>
                            </div>
                            <div
                                role="tablist"
                                aria-label="Client examples"
                                className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1"
                            >
                                {SNIPPETS.map((option) => (
                                    <button
                                        key={option.id}
                                        role="tab"
                                        type="button"
                                        aria-selected={active === option.id}
                                        onClick={() => setActive(option.id)}
                                        className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                            active === option.id
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <CodeBlock
                            key={snippet.id}
                            code={snippet.code}
                            language={snippet.language}
                            filename={snippet.filename}
                        />
                    </div>
                </section>

                <section className="border-b border-border/70">
                    <div className="mx-auto max-w-6xl px-6 py-20">
                        <h2 className="text-2xl font-semibold tracking-tight">
                            What works, and what doesn&rsquo;t
                        </h2>
                        <p className="mt-2 max-w-lg text-sm text-muted-foreground">
                            Dero is not a full reimplementation of S3. Here is
                            the line, drawn plainly.
                        </p>

                        <div className="mt-8 overflow-x-auto">
                            <table className="w-full min-w-[34rem] border-collapse text-sm">
                                <tbody>
                                    {COMPATIBILITY.map((row) => (
                                        <tr
                                            key={row.feature}
                                            className="border-b border-border/70"
                                        >
                                            <td className="py-3 pr-4">
                                                <span className="flex items-center gap-2.5">
                                                    {row.supported ? (
                                                        <CheckCircle2 className="size-4 shrink-0 text-primary" />
                                                    ) : (
                                                        <MinusCircle className="size-4 shrink-0 text-muted-foreground/60" />
                                                    )}
                                                    <span
                                                        className={
                                                            row.supported
                                                                ? ''
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {row.feature}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="py-3 text-right font-mono text-xs text-muted-foreground">
                                                {row.note}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="border-b border-border/70">
                    <div className="mx-auto grid max-w-6xl items-center gap-10 px-6 py-20 md:grid-cols-2">
                        <div>
                            <h2 className="text-2xl font-semibold tracking-tight">
                                Runs on one ordinary server
                            </h2>
                            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                Ubuntu, Nginx, PHP-FPM, MySQL, Redis. Object
                                bytes go straight to the filesystem; the
                                database holds only the metadata that points at
                                them.
                            </p>
                            <ul className="mt-6 space-y-2.5 text-sm text-muted-foreground">
                                {[
                                    'No Docker requirement',
                                    'No cluster to operate',
                                    'Storage backends are swappable later',
                                ].map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-center gap-2.5"
                                    >
                                        <CheckCircle2 className="size-4 text-primary" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div>
                            <p className="mb-3 flex items-center gap-2 font-mono text-xs tracking-wide text-muted-foreground uppercase">
                                <Terminal className="size-3.5" />
                                Install
                            </p>
                            <CodeBlock
                                language="bash"
                                filename="install.sh"
                                code={`git clone … dero && cd dero
composer install --no-dev
npm ci && npm run build
php artisan migrate --force`}
                            />
                            <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                <Clock3 className="size-3.5" />
                                Then open the dashboard and finish setup in the
                                browser.
                            </p>
                        </div>
                    </div>
                </section>

                <footer className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-4 px-6 py-10 text-sm text-muted-foreground sm:flex-row sm:items-center">
                    <span className="flex items-center gap-2">
                        <AppLogoIcon className="size-5 text-primary" />
                        Dero — object storage you keep
                    </span>
                    <span className="font-mono text-xs">
                        Self-hosted · MIT licensed
                    </span>
                </footer>
            </div>
        </>
    );
}
