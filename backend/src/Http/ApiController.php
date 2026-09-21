<?php
declare(strict_types=1);

namespace App\Http;

use App\Repository\ParcelRepository;
use PDO;

final class ApiController
{
    private ParcelRepository $parcels;
    private TileCache $tileCache;

    public function __construct(PDO $pdo, ?TileCache $tileCache = null)
    {
        $this->parcels = new ParcelRepository($pdo);
        $this->tileCache = $tileCache ?? new TileCache(getenv('TILE_CACHE_DIR') ?: '/data/mvt-cache');
    }

    /** @return array{status: string} */
    public function health(): array
    {
        return ['status' => 'ok'];
    }

    /** @return list<array<string, mixed>> */
    public function cadastralUnits(): array
    {
        return $this->parcels->cadastralUnits();
    }

    /** @return array<string, mixed> */
    public function parcel(int $id): array
    {
        return $this->parcels->find($id) ?? throw new NotFoundException();
    }

    public function tile(int $z, int $x, int $y): string
    {
        if ($z < 0 || $z > 22 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z) {
            throw new NotFoundException();
        }
        $revision = $this->parcels->tileRevision();
        return $this->tileCache->remember($revision, $z, $x, $y, fn (): string => $this->parcels->tile($z, $x, $y));
    }
}
