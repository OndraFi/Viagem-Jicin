<?php
declare(strict_types=1);

namespace App\Import;

use App\Config\Database;
use PDO;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class CpxImporter
{
    private const SOURCE_URL = 'https://services.cuzk.gov.cz/gml/inspire/cpx/epsg-5514/%d.zip';

    public function __construct(
        private PDO $pdo,
        private CpxFeatureParser $parser = new CpxFeatureParser(),
        private string $cacheDirectory = '/data/cpx',
    ) {
    }

    public function importEnabledUnits(bool $refresh = false): void
    {
        $units = $this->pdo->query('SELECT id, code, name FROM cadastral_units WHERE enabled = TRUE ORDER BY code')->fetchAll();
        foreach ($units as $unit) {
            $this->importUnit((int) $unit['id'], (int) $unit['code'], (string) $unit['name'], $refresh);
        }
    }

    private function importUnit(int $unitId, int $code, string $name, bool $refresh): void
    {
        fwrite(STDOUT, "Preparing {$name} ({$code})…" . PHP_EOL);
        $zipPath = $this->download($code, $refresh);
        $recordsPath = $this->parseToRecords($zipPath);
        try {
            $this->replaceUnit($unitId, $recordsPath, hash_file('sha256', $zipPath) ?: null);
            fwrite(STDOUT, "Imported {$name}." . PHP_EOL);
        } finally {
            @unlink($recordsPath);
        }
    }

    private function download(int $code, bool $refresh): string
    {
        if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0775, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Cannot create CPX cache directory.');
        }
        $path = $this->cacheDirectory . '/' . $code . '.zip';
        if (!$refresh && is_file($path)) {
            return $path;
        }
        $temporary = $path . '.download';
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open CPX download target.');
        }
        $context = stream_context_create(['http' => ['timeout' => 90], 'https' => ['timeout' => 90]]);
        $stream = @fopen(sprintf(self::SOURCE_URL, $code), 'rb', false, $context);
        if ($stream === false) {
            fclose($handle);
            @unlink($temporary);
            throw new RuntimeException("Unable to download CPX data for {$code}.");
        }
        stream_copy_to_stream($stream, $handle);
        fclose($stream);
        fclose($handle);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot persist CPX download.');
        }
        return $path;
    }

    private function parseToRecords(string $zipPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('CPX download is not a valid ZIP archive.');
        }
        $xmlIndex = null;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            if ($entry !== false && str_ends_with(strtolower($entry), '.xml') && !str_contains($entry, '..')) {
                $xmlIndex = $index;
                break;
            }
        }
        if ($xmlIndex === null) {
            $zip->close();
            throw new RuntimeException('CPX ZIP does not contain an XML file.');
        }
        $stream = $zip->getStream($zip->getNameIndex($xmlIndex));
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException('Cannot read CPX XML from ZIP.');
        }
        $recordsPath = tempnam(sys_get_temp_dir(), 'cpx-records-');
        $output = $recordsPath === false ? false : fopen($recordsPath, 'wb');
        if ($output === false) {
            fclose($stream);
            $zip->close();
            throw new RuntimeException('Cannot create temporary CPX records file.');
        }
        $reader = new XMLReader();
        if (!$reader->open('php://memory')) {
            throw new RuntimeException('Cannot initialize XML reader.');
        }
        // XMLReader needs a URI; keep the large source stream out of memory by extracting one temp XML file.
        $xmlPath = tempnam(sys_get_temp_dir(), 'cpx-source-');
        $xmlOutput = $xmlPath === false ? false : fopen($xmlPath, 'wb');
        if ($xmlOutput === false) {
            throw new RuntimeException('Cannot create temporary CPX XML file.');
        }
        stream_copy_to_stream($stream, $xmlOutput);
        fclose($xmlOutput);
        fclose($stream);
        $zip->close();
        $reader->close();
        $reader = new XMLReader();
        if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Cannot open CPX XML.');
        }
        $count = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'CadastralParcel') {
                    continue;
                }
                $feature = $reader->expand();
                if (!$feature instanceof \DOMElement) {
                    throw new RuntimeException('Cannot expand CPX parcel feature.');
                }
                fwrite($output, json_encode($this->parser->parse($feature), JSON_THROW_ON_ERROR) . "\n");
                $count++;
            }
            if ($count === 0) {
                throw new RuntimeException('CPX XML contains no parcel features.');
            }
        } finally {
            $reader->close();
            fclose($output);
            @unlink($xmlPath);
        }
        fwrite(STDOUT, "Parsed {$count} parcels." . PHP_EOL);
        return $recordsPath;
    }

    private function replaceUnit(int $unitId, string $recordsPath, ?string $sourceVersion): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM parcels WHERE cadastral_unit_id = :unit_id');
            $delete->execute(['unit_id' => $unitId]);
            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO parcels (
                    cadastral_unit_id, cpx_id, local_id, label, national_cadastral_reference, area_value,
                    land_type_code, land_use_code, hilucs_land_type, hilucs_land_use,
                    begin_lifespan_version, end_lifespan_version, geometry, reference_point
                )
                SELECT :unit_id, :cpx_id, :local_id, :label, :national_cadastral_reference, :area_value,
                       :land_type_code, :land_use_code, :hilucs_land_type, :hilucs_land_use,
                       :begin_lifespan_version, :end_lifespan_version, ST_Multi(geometry), reference_point
                FROM (
                    SELECT ST_GeomFromText(:wkt, 5514) AS geometry,
                           CASE WHEN CAST(:reference_test AS double precision) IS NULL THEN NULL
                                ELSE ST_SetSRID(ST_MakePoint(CAST(:reference_x AS double precision), CAST(:reference_y AS double precision)), 5514) END AS reference_point
                ) prepared
                WHERE ST_IsValid(geometry)
            SQL);
            $records = fopen($recordsPath, 'rb');
            if ($records === false) {
                throw new RuntimeException('Cannot read prepared CPX records.');
            }
            while (($line = fgets($records)) !== false) {
                /** @var array<string, mixed> $parcel */
                $parcel = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $point = $parcel['reference_point'];
                $insert->execute([
                    'unit_id' => $unitId,
                    'cpx_id' => $parcel['cpx_id'], 'local_id' => $parcel['local_id'], 'label' => $parcel['label'],
                    'national_cadastral_reference' => $parcel['national_cadastral_reference'], 'area_value' => $parcel['area_value'],
                    'land_type_code' => $parcel['land_type_code'], 'land_use_code' => $parcel['land_use_code'],
                    'hilucs_land_type' => $parcel['hilucs_land_type'], 'hilucs_land_use' => $parcel['hilucs_land_use'],
                    'begin_lifespan_version' => $parcel['begin_lifespan_version'], 'end_lifespan_version' => $parcel['end_lifespan_version'],
                    'wkt' => $parcel['wkt'],
                    'reference_test' => $point[0] ?? null, 'reference_x' => $point[0] ?? null, 'reference_y' => $point[1] ?? null,
                ]);
                if ($insert->rowCount() !== 1) {
                    throw new RuntimeException('CPX parcel has an invalid geometry.');
                }
            }
            fclose($records);
            $update = $this->pdo->prepare(<<<'SQL'
                UPDATE cadastral_units
                SET source_version = :source_version, last_import_at = NOW(), last_import_status = 'success', updated_at = NOW()
                WHERE id = :id
            SQL);
            $update->execute(['source_version' => $sourceVersion, 'id' => $unitId]);
            $this->pdo->exec('UPDATE parcel_tile_state SET revision = revision + 1, updated_at = NOW() WHERE singleton = TRUE');
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $failure = $this->pdo->prepare("UPDATE cadastral_units SET last_import_status = 'failed', updated_at = NOW() WHERE id = :id");
            $failure->execute(['id' => $unitId]);
            throw $exception;
        }
    }
}
