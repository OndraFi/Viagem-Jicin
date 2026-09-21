<?php
declare(strict_types=1);

namespace App\Http;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Bounded disk cache keyed by the parcel dataset revision and MVT coordinates.
 *
 * A revision makes data invalidation immediate after an import. TTL and the
 * size budget keep the cache from becoming a second, unbounded copy of the map.
 */
final class TileCache
{
    public function __construct(
        private string $directory = '/data/mvt-cache',
        private int $ttlSeconds = 900,
        private int $maxBytes = 268_435_456,
    ) {
        if ($this->ttlSeconds <= 0 || $this->maxBytes <= 0) {
            throw new \InvalidArgumentException('Tile cache TTL and size limit must be positive.');
        }
    }

    /** @param callable(): string $generate */
    public function remember(int $revision, int $z, int $x, int $y, callable $generate): string
    {
        $path = $this->path($revision, $z, $x, $y);
        $tile = $this->readFresh($path);
        if ($tile !== null) {
            return $tile;
        }

        $tile = $generate();
        if (strlen($tile) > $this->maxBytes) {
            return $tile;
        }

        $this->withLock(function () use ($revision, $path, $tile): void {
            // Another request may have filled this tile while this request was generating it.
            if ($this->readFresh($path) !== null) {
                return;
            }
            $this->removeOtherRevisions($revision);
            $this->removeExpired();
            $this->makeRoomFor(strlen($tile));
            $this->write($path, $tile);
        });
        return $tile;
    }

    private function readFresh(string $path): ?string
    {
        $modifiedAt = @filemtime($path);
        if ($modifiedAt === false) {
            return null;
        }
        if ($modifiedAt < time() - $this->ttlSeconds) {
            @unlink($path);
            return null;
        }
        $tile = @file_get_contents($path);
        return $tile === false ? null : $tile;
    }

    /** @param callable(): void $callback */
    private function withLock(callable $callback): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the MVT cache directory.');
        }
        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the MVT cache lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the MVT cache.');
            }
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $path, string $tile): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the MVT cache tile directory.');
        }
        $temporary = tempnam($directory, 'tile-');
        if ($temporary === false || file_put_contents($temporary, $tile) === false) {
            throw new RuntimeException('Cannot write the MVT cache tile.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function path(int $revision, int $z, int $x, int $y): string
    {
        return "{$this->directory}/{$revision}/{$z}/{$x}/{$y}.pbf";
    }

    private function removeOtherRevisions(int $revision): void
    {
        $entries = new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS);
        foreach ($entries as $entry) {
            if (!$entry->isDir() || !ctype_digit($entry->getFilename()) || (int) $entry->getFilename() === $revision) {
                continue;
            }
            $this->removeDirectory($entry->getPathname());
        }
    }

    private function removeExpired(): void
    {
        $expiredBefore = time() - $this->ttlSeconds;
        foreach ($this->tileFiles() as $file) {
            if ($file->getMTime() < $expiredBefore) {
                @unlink($file->getPathname());
            }
        }
        $this->removeEmptyDirectories();
    }

    private function makeRoomFor(int $incomingBytes): void
    {
        $files = $this->tileFiles();
        $total = array_sum(array_map(static fn (\SplFileInfo $file): int => $file->getSize(), $files));
        usort($files, static fn (\SplFileInfo $left, \SplFileInfo $right): int => $left->getMTime() <=> $right->getMTime());
        foreach ($files as $file) {
            if ($total + $incomingBytes <= $this->maxBytes) {
                break;
            }
            $total -= $file->getSize();
            @unlink($file->getPathname());
        }
        $this->removeEmptyDirectories();
    }

    /** @return list<\SplFileInfo> */
    private function tileFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.pbf')) {
                $files[] = $file;
            }
        }
        return $files;
    }

    private function removeEmptyDirectories(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
