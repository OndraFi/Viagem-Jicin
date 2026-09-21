<?php
declare(strict_types=1);

namespace App\Repository;

use App\Domain\LandType;
use PDO;

final class ParcelRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function cadastralUnits(): array
    {
        return $this->pdo->query('SELECT code, name FROM cadastral_units WHERE enabled = TRUE ORDER BY name')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.label, p.area_value, p.national_cadastral_reference,
                   p.land_type_code, p.land_use_code, p.hilucs_land_type, p.hilucs_land_use,
                   p.cpx_id, p.begin_lifespan_version, p.end_lifespan_version,
                   cu.code AS cadastral_unit_code, cu.name AS cadastral_unit_name
            FROM parcels p
            JOIN cadastral_units cu ON cu.id = p.cadastral_unit_id
            WHERE p.id = :id AND cu.enabled = TRUE
        SQL);
        $statement->execute(['id' => $id]);
        $parcel = $statement->fetch();
        if ($parcel === false) {
            return null;
        }
        $parcel['land_type_label'] = LandType::label($parcel['land_type_code']);
        return $parcel;
    }

    public function tile(int $z, int $x, int $y): string
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            WITH bounds AS (
                SELECT ST_TileEnvelope(:z, :x, :y) AS geom_3857
            ), features AS (
                SELECT p.id, p.label,
                       ST_AsMVTGeom(ST_Transform(p.geometry, 3857), bounds.geom_3857, 4096, 64, TRUE) AS geom
                FROM parcels p
                JOIN cadastral_units cu ON cu.id = p.cadastral_unit_id
                CROSS JOIN bounds
                WHERE cu.enabled = TRUE
                  AND p.geometry && ST_Transform(bounds.geom_3857, 5514)
            )
            SELECT COALESCE(ST_AsMVT(features, 'parcels', 4096, 'geom', 'id'), ''::bytea) AS tile
            FROM features
        SQL);
        $statement->execute(['z' => $z, 'x' => $x, 'y' => $y]);
        $tile = $statement->fetchColumn();
        return is_resource($tile) ? (string) stream_get_contents($tile) : (string) $tile;
    }
}
