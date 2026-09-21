<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Http\ApiController;
use App\Http\Response;

header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: 'http://localhost:5173'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $controller = new ApiController(Database::connect());
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'GET') {
        Response::json(['error' => 'Method not allowed'], 405);
    } elseif ($path === '/api/health') {
        Response::json($controller->health());
    } elseif ($path === '/api/cadastral-units') {
        Response::json($controller->cadastralUnits());
    } elseif (preg_match('#^/api/parcels/(\\d+)$#', $path, $matches)) {
        Response::json($controller->parcel((int) $matches[1]));
    } elseif (preg_match('#^/api/tiles/parcels/(\\d+)/(\\d+)/(\\d+)\\.pbf$#', $path, $matches)) {
        Response::mvt($controller->tile((int) $matches[1], (int) $matches[2], (int) $matches[3]));
    } else {
        Response::json(['error' => 'Not found'], 404);
    }
} catch (App\Http\NotFoundException) {
    Response::json(['error' => 'Not found'], 404);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::json(['error' => 'Internal server error'], 500);
}
