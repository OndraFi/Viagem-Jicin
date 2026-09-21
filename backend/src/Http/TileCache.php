<?php
declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Disk cache keyed by the parcel dataset revision and MVT coordinates. */
final class TileCache
{
    public function __construct(private string $directory = '/data/mvt-cache')
    {
    }

    /** @param callable(): string $generate */
    public function remember(int $revision, int $z, int $x, int $y, callable $generate): string
    {
        $path = $this->path($revision, $z, $x, $y);
        $tile = @file_get_contents($path);
        if ($tile !== false) {
            return $tile;
        }

        $tile = $generate();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the MVT cache directory.');
        }
        $temporary = tempnam($directory, 'tile-');
        if ($temporary === false || file_put_contents($temporary, $tile) === false) {
            throw new RuntimeException('Cannot write the MVT cache tile.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
        return $tile;
    }

    private function path(int $revision, int $z, int $x, int $y): string
    {
        return "{$this->directory}/{$revision}/{$z}/{$x}/{$y}.pbf";
    }
}
