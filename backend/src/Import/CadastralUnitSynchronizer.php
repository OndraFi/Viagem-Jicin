<?php
declare(strict_types=1);

namespace App\Import;

use PDO;
use RuntimeException;
use ZipArchive;

/** Synchronizes the nationwide RÚIAN UI_KATASTRALNI_UZEMI catalogue. */
final class CadastralUnitSynchronizer
{
    private const SOURCE_KEY = 'cadastral_units';
    private const CADASTRAL_UNITS_SOURCE_URL = 'https://services.cuzk.cz/sestavy/cis/UI_KATASTRALNI_UZEMI.zip';
    private const MUNICIPALITIES_SOURCE_URL = 'https://services.cuzk.cz/sestavy/cis/UI_OBEC.zip';

    public function __construct(private PDO $pdo)
    {
    }

    public function synchronize(): void
    {
        $cadastralUnitsBody = $this->download(self::CADASTRAL_UNITS_SOURCE_URL);
        $municipalitiesBody = $this->download(self::MUNICIPALITIES_SOURCE_URL);
        $hash = hash('sha256', $cadastralUnitsBody . "\0" . $municipalitiesBody);
        $source = $this->pdo->prepare('SELECT source_hash FROM codelist_sources WHERE key = :key');
        $source->execute(['key' => self::SOURCE_KEY]);
        if ($source->fetchColumn() === $hash) {
            $this->pdo->prepare('UPDATE codelist_sources SET fetched_at = NOW() WHERE key = :key')
                ->execute(['key' => self::SOURCE_KEY]);
            fwrite(STDOUT, "cadastral_units: unchanged." . PHP_EOL);
            return;
        }

        $municipalityDistricts = $this->parseMunicipalityDistricts($municipalitiesBody);
        $units = $this->parse($cadastralUnitsBody, $municipalityDistricts);
        if ($units === []) {
            throw new RuntimeException('RÚIAN cadastral-unit catalogue is empty.');
        }

        $this->pdo->beginTransaction();
        try {
            $upsertSource = $this->pdo->prepare(<<<'SQL'
                INSERT INTO codelist_sources (key, source_url, source_version, source_hash, fetched_at, changed_at)
                VALUES (:key, :source_url, NULL, :source_hash, NOW(), NOW())
                ON CONFLICT (key) DO UPDATE SET
                    source_url = EXCLUDED.source_url,
                    source_version = NULL,
                    source_hash = EXCLUDED.source_hash,
                    fetched_at = NOW(),
                    changed_at = NOW()
            SQL);
            $upsertSource->execute([
                'key' => self::SOURCE_KEY,
                'source_url' => self::CADASTRAL_UNITS_SOURCE_URL . ';' . self::MUNICIPALITIES_SOURCE_URL,
                'source_hash' => $hash,
            ]);

            $upsertUnit = $this->pdo->prepare(<<<'SQL'
                INSERT INTO cadastral_units (code, name, municipality_code, district_code, valid_from, valid_to, enabled, updated_at)
                VALUES (:code, :name, :municipality_code, :district_code, :valid_from, :valid_to, FALSE, NOW())
                ON CONFLICT (code) DO UPDATE SET
                    name = EXCLUDED.name,
                    municipality_code = EXCLUDED.municipality_code,
                    district_code = EXCLUDED.district_code,
                    valid_from = EXCLUDED.valid_from,
                    valid_to = EXCLUDED.valid_to,
                    updated_at = NOW()
            SQL);
            foreach ($units as $unit) {
                $upsertUnit->execute($unit);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        fwrite(STDOUT, 'cadastral_units: imported ' . count($units) . ' entries.' . PHP_EOL);
    }

    /**
     * @param array<int, int> $municipalityDistricts municipality code => district code
     * @return list<array{code:int,name:string,municipality_code:int,district_code:int,valid_from:?string,valid_to:?string}>
     */
    private function parse(string $body, array $municipalityDistricts): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ruian-katastralni-uzemi-');
        if ($path === false || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Cannot create a temporary RÚIAN catalogue file.');
        }

        $zip = new ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('RÚIAN cadastral-unit catalogue is not a valid ZIP archive.');
            }
            $entryName = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name !== false && str_ends_with(strtolower($name), '.csv') && !str_contains($name, '..')) {
                    $entryName = $name;
                    break;
                }
            }
            if ($entryName === null || ($stream = $zip->getStream($entryName)) === false) {
                throw new RuntimeException('RÚIAN cadastral-unit ZIP does not contain a CSV file.');
            }

            $header = fgetcsv($stream, separator: ';', escape: '');
            if ($header !== ['KOD', 'NAZEV', 'OBEC_KOD', 'PLATI_OD', 'PLATI_DO', 'DATUM_VZNIKU']) {
                throw new RuntimeException('RÚIAN cadastral-unit CSV has an unexpected header.');
            }

            $units = [];
            while (($row = fgetcsv($stream, separator: ';', escape: '')) !== false) {
                if (count($row) !== 6 || !ctype_digit($row[0]) || !ctype_digit($row[2])) {
                    throw new RuntimeException('RÚIAN cadastral-unit CSV contains an invalid row.');
                }
                $name = iconv('Windows-1250', 'UTF-8//IGNORE', $row[1]);
                if ($name === false || $name === '') {
                    throw new RuntimeException('RÚIAN cadastral-unit CSV contains an invalid name.');
                }
                $municipalityCode = (int) $row[2];
                $districtCode = $municipalityDistricts[$municipalityCode] ?? null;
                if ($districtCode === null) {
                    throw new RuntimeException("RÚIAN municipality {$municipalityCode} has no district mapping.");
                }
                $units[] = [
                    'code' => (int) $row[0],
                    'name' => $name,
                    'municipality_code' => $municipalityCode,
                    'district_code' => $districtCode,
                    'valid_from' => $this->parseDate($row[3]),
                    'valid_to' => $this->parseDate($row[4]),
                ];
            }
            fclose($stream);
            return $units;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return array<int, int> municipality code => district code */
    private function parseMunicipalityDistricts(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ruian-obce-');
        if ($path === false || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Cannot create a temporary RÚIAN municipality file.');
        }

        $zip = new ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('RÚIAN municipality catalogue is not a valid ZIP archive.');
            }
            $entryName = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name !== false && str_ends_with(strtolower($name), '.csv') && !str_contains($name, '..')) {
                    $entryName = $name;
                    break;
                }
            }
            if ($entryName === null || ($stream = $zip->getStream($entryName)) === false) {
                throw new RuntimeException('RÚIAN municipality ZIP does not contain a CSV file.');
            }

            $header = fgetcsv($stream, separator: ';', escape: '');
            if ($header !== ['KOD', 'NAZEV', 'STATUS_KOD', 'POU_KOD', 'OKRES_KOD', 'CLENENI_SM_ROZSAH_KOD', 'CLENENI_SM_TYP_KOD', 'PLATI_OD', 'PLATI_DO', 'DATUM_VZNIKU']) {
                throw new RuntimeException('RÚIAN municipality CSV has an unexpected header.');
            }

            $districts = [];
            while (($row = fgetcsv($stream, separator: ';', escape: '')) !== false) {
                if (count($row) !== 10 || !ctype_digit($row[0]) || !ctype_digit($row[4])) {
                    throw new RuntimeException('RÚIAN municipality CSV contains an invalid row.');
                }
                $districts[(int) $row[0]] = (int) $row[4];
            }
            fclose($stream);
            return $districts;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException("RÚIAN cadastral-unit CSV contains an invalid date: {$value}.");
        }
        return $date->format('c');
    }

    private function download(string $url): string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 90, 'header' => "User-Agent: JicinParcelMap/1.0\r\n"],
            'https' => ['timeout' => 90, 'header' => "User-Agent: JicinParcelMap/1.0\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Unable to download the RÚIAN cadastral-unit catalogue.');
        }
        return $body;
    }
}
