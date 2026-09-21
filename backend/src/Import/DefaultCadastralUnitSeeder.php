<?php
declare(strict_types=1);

namespace App\Import;

use JsonException;
use PDO;
use RuntimeException;

/** Seeds the intentionally small MVP scope from repository configuration. */
final class DefaultCadastralUnitSeeder
{
    public function __construct(private PDO $pdo, private string $path = '')
    {
        $this->path = $path ?: dirname(__DIR__, 2) . '/data/default-cadastral-units.json';
    }

    public function ensure(): void
    {
        $body = file_get_contents($this->path);
        if ($body === false) {
            throw new RuntimeException('Default cadastral-unit configuration cannot be read.');
        }
        try {
            $units = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Default cadastral-unit configuration is invalid JSON.', previous: $exception);
        }
        if (!is_array($units) || $units === []) {
            throw new RuntimeException('Default cadastral-unit configuration is empty.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO cadastral_units (code, name, municipality_code, district_code, enabled, updated_at)
            VALUES (:code, :name, :municipality_code, :district_code, TRUE, NOW())
            ON CONFLICT (code) DO UPDATE SET
                name = EXCLUDED.name,
                municipality_code = EXCLUDED.municipality_code,
                district_code = EXCLUDED.district_code,
                enabled = TRUE,
                updated_at = NOW()
        SQL);
        foreach ($units as $unit) {
            if (!is_array($unit) || !is_int($unit['code'] ?? null) || !is_string($unit['name'] ?? null)
                || !is_int($unit['municipality_code'] ?? null) || !is_int($unit['district_code'] ?? null)) {
                throw new RuntimeException('Default cadastral-unit configuration contains an invalid entry.');
            }
            $statement->execute($unit);
        }
        fwrite(STDOUT, 'Ensured ' . count($units) . ' default cadastral units.' . PHP_EOL);
    }
}
