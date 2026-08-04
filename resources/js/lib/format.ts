/**
 * Formatting helpers for storage values.
 *
 * Kept out of components so the same rounding shows up everywhere — a bucket
 * that reads "1.2 GB" on one screen must not read "1.18 GB" on another.
 */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

export function formatBytes(bytes: number, precision = 1): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        UNITS.length - 1,
    );
    const value = bytes / 1024 ** exponent;

    return `${value.toFixed(exponent === 0 ? 0 : precision)} ${UNITS[exponent]}`;
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(undefined).format(value);
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

export function formatRelative(iso: string | null | undefined): string {
    if (!iso) {
        return 'Never';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });

    const thresholds: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'second'],
        [3600, 'minute'],
        [86400, 'hour'],
        [2592000, 'day'],
    ];

    for (const [limit, unit] of thresholds) {
        if (Math.abs(seconds) < limit) {
            const divisor =
                limit === 60
                    ? 1
                    : limit /
                      (unit === 'minute' ? 60 : unit === 'hour' ? 3600 : 86400);

            return formatter.format(-Math.round(seconds / divisor), unit);
        }
    }

    return formatter.format(-Math.round(seconds / 2592000), 'month');
}

/**
 * A short, human label for a MIME type.
 */
export function fileKind(contentType: string): string {
    if (contentType.startsWith('image/')) {
        return 'Image';
    }

    if (contentType.startsWith('video/')) {
        return 'Video';
    }

    if (contentType.startsWith('audio/')) {
        return 'Audio';
    }

    if (contentType === 'application/pdf') {
        return 'PDF';
    }

    if (contentType === 'text/csv') {
        return 'CSV';
    }

    if (contentType === 'application/json') {
        return 'JSON';
    }

    if (contentType.startsWith('text/')) {
        return 'Text';
    }

    if (contentType.includes('gzip') || contentType.includes('zip')) {
        return 'Archive';
    }

    if (contentType.includes('javascript')) {
        return 'Script';
    }

    return 'File';
}
