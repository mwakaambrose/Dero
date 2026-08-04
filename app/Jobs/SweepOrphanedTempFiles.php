<?php

declare(strict_types=1);

namespace App\Jobs;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deletes the debris of interrupted writes.
 *
 * Two kinds. Files under tmp/, and the ".part" neighbours the local backend
 * writes an object into before renaming it into place — a process killed
 * mid-upload leaves one behind, and nothing else will ever reference it.
 *
 * The age threshold is what keeps this safe to run against a live server: a
 * ".part" file being written right now is minutes old, never a day.
 */
final class SweepOrphanedTempFiles implements ShouldQueue
{
    use Queueable;

    private const int MAX_AGE_SECONDS = 86400;

    public function handle(): void
    {
        $root = rtrim((string) config('dero.storage_root'), '/');

        if ($root === '' || ! is_dir($root)) {
            return;
        }

        $cutoff = time() - self::MAX_AGE_SECONDS;

        $this->sweepDirectory($root.'/tmp', $cutoff, fn (): bool => true);
        $this->sweepDirectory(
            $root.'/objects',
            $cutoff,
            fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.part'),
        );
    }

    /**
     * @param  callable(SplFileInfo): bool  $shouldDelete
     */
    private function sweepDirectory(string $directory, int $cutoff, callable $shouldDelete): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($file->getMTime() >= $cutoff || ! $shouldDelete($file)) {
                continue;
            }

            @unlink($file->getPathname());
        }
    }
}
