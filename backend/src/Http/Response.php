<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function mvt(string $tile): never
    {
        header('Content-Type: application/vnd.mapbox-vector-tile');
        // The parcel dataset revision is part of the tile URL, therefore a tile
        // URL names immutable content. Nginx bounds disk retention separately.
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $tile;
        exit;
    }
}
