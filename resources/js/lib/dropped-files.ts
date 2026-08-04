export type DroppedFile = { file: File; path: string };

/**
 * Read a drop, descending into any folders it contains.
 *
 * The path each file carries is relative to what was dropped, so dropping a
 * folder reproduces its shape as object keys — the same result as
 * `aws s3 cp --recursive`.
 */
export async function readDrop(
    items: DataTransferItemList,
): Promise<DroppedFile[]> {
    const entries: FileSystemEntry[] = [];
    const plain: File[] = [];

    // The entry list must be read synchronously: DataTransferItem objects are
    // emptied as soon as the drop handler yields.
    for (const item of Array.from(items)) {
        const entry = item.webkitGetAsEntry?.();

        if (entry) {
            entries.push(entry);

            continue;
        }

        const file = item.getAsFile();

        if (file) {
            plain.push(file);
        }
    }

    const found: DroppedFile[] = plain.map((file) => ({
        file,
        path: file.name,
    }));

    for (const entry of entries) {
        found.push(...(await walk(entry, '')));
    }

    return found;
}

/**
 * Files chosen through an <input>, keeping the relative path a directory
 * picker supplies.
 */
export function readPicked(files: FileList): DroppedFile[] {
    return Array.from(files).map((file) => ({
        file,
        path: file.webkitRelativePath || file.name,
    }));
}

async function walk(
    entry: FileSystemEntry,
    prefix: string,
): Promise<DroppedFile[]> {
    if (entry.isFile) {
        const file = await new Promise<File | null>((resolve) =>
            (entry as FileSystemFileEntry).file(resolve, () => resolve(null)),
        );

        return file ? [{ file, path: `${prefix}${entry.name}` }] : [];
    }

    const reader = (entry as FileSystemDirectoryEntry).createReader();
    const found: DroppedFile[] = [];

    // readEntries returns at most 100 entries per call and signals the end with
    // an empty batch, so a large folder needs to be drained in a loop.
    for (;;) {
        const batch = await new Promise<FileSystemEntry[]>((resolve) =>
            reader.readEntries(resolve, () => resolve([])),
        );

        if (batch.length === 0) {
            break;
        }

        for (const child of batch) {
            found.push(...(await walk(child, `${prefix}${entry.name}/`)));
        }
    }

    return found;
}
