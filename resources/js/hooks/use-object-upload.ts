import { useEffect, useRef, useState } from 'react';
import type { DroppedFile } from '@/lib/dropped-files';
import {
    folder as createFolder,
    store as storeObject,
} from '@/routes/storage/objects';
import {
    complete as completeUpload,
    destroy as abortUpload,
    part as sendPart,
    store as beginUpload,
} from '@/routes/storage/uploads';

export type UploadStatus =
    'queued' | 'uploading' | 'done' | 'failed' | 'cancelled';

export type UploadItem = {
    id: string;
    key: string;
    name: string;
    sizeBytes: number;
    /** Bytes accepted so far, for the progress bar. */
    sentBytes: number;
    status: UploadStatus;
    error?: string;
};

export type UploadOptions = {
    /** Overrides the type the browser guessed, as --content-type does. */
    contentType?: string;
    metadata?: Record<string, string>;
};

type Config = {
    teamSlug: string;
    bucket: string;
    /** Files at or above this size are uploaded in parts. */
    threshold: number;
    partSize: number;
    maxBytes: number;
    onFinished: () => void;
};

type Job = { item: UploadItem; file: File; options: UploadOptions };

/** How many files move at once, and how many parts within one file. */
const FILE_CONCURRENCY = 3;
const PART_CONCURRENCY = 3;

function token(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * A request that reports upload progress.
 *
 * fetch() cannot do this — it has no upload progress event — which is the whole
 * reason the queue talks to the server directly rather than through Inertia.
 */
function send(
    method: string,
    url: string,
    body: FormData | string | null,
    onProgress?: (loaded: number) => void,
): { promise: Promise<Record<string, unknown>>; abort: () => void } {
    const xhr = new XMLHttpRequest();

    const promise = new Promise<Record<string, unknown>>((resolve, reject) => {
        xhr.open(method, url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', token());

        if (typeof body === 'string') {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }

        if (onProgress) {
            xhr.upload.onprogress = (event) => onProgress(event.loaded);
        }

        xhr.onload = () => {
            let payload: Record<string, unknown> = {};

            try {
                payload = JSON.parse(xhr.responseText) as Record<
                    string,
                    unknown
                >;
            } catch {
                payload = {};
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(payload);

                return;
            }

            // Laravel nests field errors; either shape reads as one sentence.
            const errors = payload.errors as
                Record<string, string[]> | undefined;

            reject(
                new Error(
                    errors
                        ? Object.values(errors).flat()[0]
                        : ((payload.message as string) ??
                              `Upload failed (${xhr.status})`),
                ),
            );
        };

        xhr.onerror = () => reject(new Error('The connection was lost.'));
        xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));

        xhr.send(body);
    });

    return { promise, abort: () => xhr.abort() };
}

/**
 * The upload queue behind the bucket browser.
 *
 * Files under the multipart threshold go up whole; larger ones are sliced into
 * parts and driven through create/upload/complete, which is the same exchange
 * the AWS CLI has with the endpoint — and what keeps any single request small
 * enough that PHP's post_max_size never bounds the file.
 *
 * The queue itself is deliberately imperative and lives in a ref: it is a
 * running process with its own bookkeeping, and only the item list — the part
 * anything renders — is React state.
 */
export function useObjectUpload(config: Config) {
    const [items, setItems] = useState<UploadItem[]>([]);
    const pending = useRef<Job[]>([]);
    const running = useRef(0);
    const cancels = useRef(new Map<string, () => void>());
    const latest = useRef(config);

    useEffect(() => {
        latest.current = config;
    });

    const patch = (id: string, changes: Partial<UploadItem>) => {
        setItems((current) =>
            current.map((item) =>
                item.id === id ? { ...item, ...changes } : item,
            ),
        );
    };

    const target = () => ({
        current_team: latest.current.teamSlug,
        bucket: latest.current.bucket,
    });

    const uploadWhole = async (job: Job) => {
        const body = new FormData();
        body.append('file', job.file);
        body.append('key', job.item.key);
        body.append(
            'contentType',
            job.options.contentType ?? job.file.type ?? '',
        );

        Object.entries(job.options.metadata ?? {}).forEach(([name, value]) =>
            body.append(`metadata[${name}]`, value),
        );

        const request = send(
            'POST',
            storeObject(target()).url,
            body,
            (loaded) => patch(job.item.id, { sentBytes: loaded }),
        );

        cancels.current.set(job.item.id, request.abort);

        await request.promise;
    };

    const uploadInParts = async (job: Job) => {
        const begin = await send(
            'POST',
            beginUpload(target()).url,
            JSON.stringify({
                key: job.item.key,
                contentType: job.options.contentType || job.file.type,
                metadata: job.options.metadata ?? {},
            }),
        ).promise;

        const uploadId = begin.uploadId as string;
        const size = (begin.partSize as number) || latest.current.partSize;
        const count = Math.max(1, Math.ceil(job.file.size / size));

        // Progress is summed across the parts in flight, so each part reports
        // its own bytes and the total is recomputed rather than added to.
        const sent = new Array<number>(count).fill(0);
        const etags = new Array<string>(count);
        const inFlight = new Set<() => void>();
        let cancelled = false;

        cancels.current.set(job.item.id, () => {
            cancelled = true;
            inFlight.forEach((abort) => abort());

            // Best effort: the stored parts are cleaned up server side, and a
            // lost abort must not surface as an upload error.
            void send(
                'DELETE',
                abortUpload({ ...target(), upload: uploadId }).url,
                null,
            ).promise.catch(() => undefined);
        });

        let next = 0;

        const worker = async () => {
            while (next < count && !cancelled) {
                const index = next++;
                const chunk = job.file.slice(index * size, (index + 1) * size);

                const body = new FormData();
                body.append('part', chunk, `${job.item.name}.part`);
                body.append('partNumber', String(index + 1));

                const request = send(
                    'POST',
                    sendPart({ ...target(), upload: uploadId }).url,
                    body,
                    (loaded) => {
                        sent[index] = loaded;
                        patch(job.item.id, {
                            sentBytes: sent.reduce((a, b) => a + b, 0),
                        });
                    },
                );

                inFlight.add(request.abort);

                try {
                    const result = await request.promise;
                    etags[index] = result.etag as string;
                    sent[index] = chunk.size;
                } finally {
                    inFlight.delete(request.abort);
                }
            }
        };

        await Promise.all(
            Array.from({ length: Math.min(PART_CONCURRENCY, count) }, worker),
        );

        if (cancelled) {
            throw new DOMException('Aborted', 'AbortError');
        }

        await send(
            'POST',
            completeUpload({ ...target(), upload: uploadId }).url,
            JSON.stringify({
                parts: etags.map((etag, index) => ({
                    partNumber: index + 1,
                    etag,
                })),
            }),
        ).promise;
    };

    const pump = () => {
        while (
            running.current < FILE_CONCURRENCY &&
            pending.current.length > 0
        ) {
            const job = pending.current.shift();

            if (!job) {
                return;
            }

            running.current += 1;
            patch(job.item.id, { status: 'uploading' });

            const work =
                job.file.size >= latest.current.threshold
                    ? uploadInParts(job)
                    : uploadWhole(job);

            void work
                .then(() =>
                    patch(job.item.id, {
                        status: 'done',
                        sentBytes: job.file.size,
                    }),
                )
                .catch((error: unknown) =>
                    patch(job.item.id, {
                        status:
                            error instanceof DOMException &&
                            error.name === 'AbortError'
                                ? 'cancelled'
                                : 'failed',
                        error:
                            error instanceof Error ? error.message : undefined,
                    }),
                )
                .finally(() => {
                    cancels.current.delete(job.item.id);
                    running.current -= 1;

                    if (pending.current.length > 0) {
                        pump();
                    } else if (running.current === 0) {
                        latest.current.onFinished();
                    }
                });
        }
    };

    /**
     * Queue files for the given prefix. Keys keep the relative path each file
     * carried, so dropping a folder reproduces its shape in the bucket.
     */
    const enqueue = (
        files: DroppedFile[],
        prefix: string,
        options: UploadOptions = {},
    ) => {
        const jobs: Job[] = files.map(({ file, path }, index) => {
            const tooBig = file.size > latest.current.maxBytes;

            return {
                item: {
                    id: `${Date.now()}-${index}-${path}`,
                    key: `${prefix}${path}`,
                    name: path,
                    sizeBytes: file.size,
                    sentBytes: 0,
                    status: tooBig ? 'failed' : 'queued',
                    error: tooBig
                        ? 'Larger than this installation allows.'
                        : undefined,
                },
                file,
                options,
            };
        });

        setItems((current) => [...current, ...jobs.map((job) => job.item)]);
        pending.current.push(
            ...jobs.filter((job) => job.item.status === 'queued'),
        );

        pump();
    };

    const createEmptyFolder = async (key: string) => {
        await send('POST', createFolder(target()).url, JSON.stringify({ key }))
            .promise;

        latest.current.onFinished();
    };

    const cancel = (id: string) => {
        cancels.current.get(id)?.();
        pending.current = pending.current.filter((job) => job.item.id !== id);
        patch(id, { status: 'cancelled' });
    };

    const dismissFinished = () => {
        setItems((current) =>
            current.filter(
                (item) =>
                    item.status === 'queued' || item.status === 'uploading',
            ),
        );
    };

    return { items, enqueue, cancel, dismissFinished, createEmptyFolder };
}
