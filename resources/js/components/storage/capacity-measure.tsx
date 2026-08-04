import { formatBytes } from '@/lib/format';

export type Capacity = {
    totalBytes: number | null;
    freeBytes: number | null;
};

type Props = {
    storageBytes: number;
    capacity: Capacity;
};

/**
 * How full the granary is.
 *
 * Self-hosted storage fails in one specific way — the volume fills — and a
 * per-bucket breakdown never says so. This measures what this installation
 * holds against what the disk behind it actually has, and separates out the
 * space taken by everything else on that volume, because that space is just as
 * gone. The filled portion carries the weave: the part of the basket holding
 * grain is the part that is visibly woven.
 *
 * When the platform will not report on the volume, the figure stands alone
 * rather than being paired with an invented denominator.
 */
export function CapacityMeasure({ storageBytes, capacity }: Props) {
    const total = capacity.totalBytes;
    const free = capacity.freeBytes;
    const hasVolume = total !== null && free !== null && total > 0;

    const used = hasVolume ? Math.max(0, total - free) : 0;
    const others = hasVolume ? Math.max(0, used - storageBytes) : 0;
    const held = hasVolume ? Math.min(storageBytes, total) : storageBytes;

    const share = (bytes: number) => (hasVolume ? (bytes / total) * 100 : 0);

    return (
        <div>
            <p className="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
                Held here
            </p>
            <p className="mt-2 font-display text-5xl leading-none font-semibold tracking-tight tabular-nums md:text-6xl">
                {formatBytes(storageBytes)}
            </p>

            {hasVolume ? (
                <>
                    <div
                        className="mt-7 flex h-9 gap-0.5 overflow-hidden rounded-lg bg-measure-track"
                        role="img"
                        aria-label={`${formatBytes(held)} held by this installation, ${formatBytes(others)} used by everything else on the volume, ${formatBytes(free)} free of ${formatBytes(total)}.`}
                    >
                        <Segment
                            percent={share(held)}
                            className="dero-weave-fill bg-primary"
                        />
                        <Segment
                            percent={share(others)}
                            className="bg-measure-other"
                        />
                    </div>

                    <dl className="mt-4 flex flex-wrap items-baseline gap-x-6 gap-y-2 font-mono text-xs">
                        <Key
                            swatch="dero-weave-fill bg-primary"
                            term="This installation"
                            value={`${share(held).toFixed(1)}%`}
                        />
                        <Key
                            swatch="bg-measure-other"
                            term="Everything else"
                            value={`${share(others).toFixed(1)}%`}
                        />
                        <Key
                            swatch="bg-measure-track"
                            term="Free"
                            value={formatBytes(free)}
                        />
                        <span className="text-muted-foreground">
                            {formatBytes(total)} volume
                        </span>
                    </dl>
                </>
            ) : (
                <p className="mt-4 font-mono text-xs text-muted-foreground">
                    Volume size unavailable — check that STORAGE_ROOT exists and
                    is readable.
                </p>
            )}
        </div>
    );
}

/**
 * Anything above zero keeps a sliver of width, so a small tenant never
 * disappears entirely from its own measure.
 */
function Segment({
    percent,
    className,
}: {
    percent: number;
    className: string;
}) {
    if (percent <= 0) {
        return null;
    }

    return (
        <div
            className={className}
            style={{ width: `${percent}%`, minWidth: '4px' }}
        />
    );
}

function Key({
    swatch,
    term,
    value,
}: {
    swatch: string;
    term: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-2">
            <span
                aria-hidden="true"
                className={`size-2.5 shrink-0 rounded-[3px] ring-1 ring-border ${swatch}`}
            />
            <dt className="text-muted-foreground">{term}</dt>
            <dd className="font-medium tabular-nums">{value}</dd>
        </div>
    );
}
