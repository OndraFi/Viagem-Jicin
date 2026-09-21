<?php
declare(strict_types=1);

namespace App\Http;

use App\Repository\ParcelRepository;
use PDO;

final class ApiController
{
    private ParcelRepository $parcels;

    public function __construct(PDO $pdo)
    {
        $this->parcels = new ParcelRepository($pdo);
    }

    /** @return array{status: string} */
    public function health(): array
    {
        return ['status' => 'ok'];
    }

    /** @return array{tile_revision: int} */
    public function mapConfig(): array
    {
        return ['tile_revision' => $this->parcels->tileRevision()];
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

    public function tile(int $revision, int $z, int $x, int $y): string
    {
        if ($z < 0 || $z > 22 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z) {
            throw new NotFoundException();
        }
        if ($revision !== $this->parcels->tileRevision()) {
            throw new NotFoundException();
        }
        return $this->parcels->tile($z, $x, $y);
    }
}
