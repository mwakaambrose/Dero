import { useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBytes, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type SeriesPoint = {
    day: string;
    storage: number;
    objects: number;
    requests: number;
    uploaded: number;
    downloaded: number;
};

type MeasureKey = 'requests' | 'downloaded' | 'uploaded' | 'storage';

const MEASURES: {
    key: MeasureKey;
    label: string;
    caption: string;
    format: (value: number) => string;
}[] = [
    {
        key: 'requests',
        label: 'Requests',
        caption: 'S3 API calls per day',
        format: formatNumber,
    },
    {
        key: 'downloaded',
        label: 'Out',
        caption: 'Bytes served per day',
        format: (value) => formatBytes(value, 0),
    },
    {
        key: 'uploaded',
        label: 'In',
        caption: 'Bytes received per day',
        format: (value) => formatBytes(value, 0),
    },
    {
        key: 'storage',
        label: 'Held',
        caption: 'Total bytes held, end of day',
        format: (value) => formatBytes(value, 0),
    },
];

/**
 * One chart, four measures.
 *
 * Four small copies of the same chart shape teach you nothing that one chart
 * you can switch does not — and reading bytes against requests on a shared
 * axis would be a lie, so only one measure is ever plotted at a time.
 */
export function TrafficChart({ data }: { data: SeriesPoint[] }) {
    const [active, setActive] = useState<MeasureKey>('requests');
    const measure = MEASURES.find((item) => item.key === active) ?? MEASURES[0];

    return (
        <div>
            <div className="mb-6 flex flex-wrap items-baseline justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {measure.caption}
                </p>
                <div
                    role="tablist"
                    aria-label="Measure"
                    className="inline-flex rounded-md border border-border bg-secondary p-0.5 font-mono text-[11px] tracking-wider uppercase"
                >
                    {MEASURES.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            role="tab"
                            aria-selected={item.key === active}
                            onClick={() => setActive(item.key)}
                            className={cn(
                                'rounded-[5px] px-2.5 py-1 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                item.key === active
                                    ? 'bg-background text-foreground shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart
                        data={data}
                        margin={{ top: 4, right: 4, bottom: 0, left: -8 }}
                    >
                        <defs>
                            <linearGradient
                                id="dero-area"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="var(--color-chart-1)"
                                    stopOpacity={0.28}
                                />
                                <stop
                                    offset="100%"
                                    stopColor="var(--color-chart-1)"
                                    stopOpacity={0}
                                />
                            </linearGradient>
                        </defs>
                        <CartesianGrid
                            stroke="var(--color-border)"
                            strokeDasharray="2 4"
                            vertical={false}
                        />
                        <XAxis
                            dataKey="day"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={10}
                            tick={{
                                fill: 'var(--color-muted-foreground)',
                                fontSize: 11,
                                fontFamily: 'var(--font-mono)',
                            }}
                            tickFormatter={(value: string) => value.slice(5)}
                            minTickGap={28}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            width={68}
                            tick={{
                                fill: 'var(--color-muted-foreground)',
                                fontSize: 11,
                                fontFamily: 'var(--font-mono)',
                            }}
                            tickFormatter={measure.format}
                        />
                        <Tooltip
                            cursor={{
                                stroke: 'var(--color-muted-foreground)',
                                strokeWidth: 1,
                                strokeDasharray: '3 3',
                            }}
                            contentStyle={{
                                background: 'var(--color-popover)',
                                border: '1px solid var(--color-border)',
                                borderRadius: '0.5rem',
                                fontSize: '12px',
                                fontFamily: 'var(--font-mono)',
                                color: 'var(--color-popover-foreground)',
                            }}
                            labelStyle={{
                                color: 'var(--color-muted-foreground)',
                            }}
                            formatter={(value) =>
                                [
                                    measure.format(Number(value)),
                                    measure.label,
                                ] as [string, string]
                            }
                        />
                        <Area
                            type="monotone"
                            dataKey={measure.key}
                            stroke="var(--color-chart-1)"
                            strokeWidth={2}
                            fill="url(#dero-area)"
                            activeDot={{
                                r: 4,
                                strokeWidth: 2,
                                stroke: 'var(--color-background)',
                            }}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
