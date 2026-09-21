<?php
declare(strict_types=1);

namespace App\Config;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $pdo = new PDO(
            getenv('DATABASE_URL') ?: 'pgsql:host=postgres;port=5432;dbname=jicin_parcels',
            getenv('DB_USER') ?: 'jicin',
            getenv('DB_PASSWORD') ?: 'jicin',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }
}
