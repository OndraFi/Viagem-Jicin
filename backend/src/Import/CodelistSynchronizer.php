<?php
declare(strict_types=1);

namespace App\Import;

use JsonException;
use PDO;
use RuntimeException;

final class CodelistSynchronizer
{
    /** @var array<string, string> */
    private const CUZK_SOURCES = [
        'land_type' => 'https://services.cuzk.gov.cz/registry/codelist/LandTypeValue/LandTypeValue.json',
        'land_use' => 'https://services.cuzk.gov.cz/registry/codelist/LandUseValue/LandUseValue.json',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function synchronize(): void
    {
        foreach (self::CUZK_SOURCES as $key => $sourceUrl) {
            $this->synchronizeCuzkJson($key, $sourceUrl);
        }
        $this->synchronizeSnapshot(dirname(__DIR__, 2) . '/data/codelists/hilucs-2013-cpx-snapshot.json');
        (new CadastralUnitSynchronizer($this->pdo))->synchronize();
    }

    private function synchronizeCuzkJson(string $key, string $sourceUrl): void
    {
        $body = $this->download($sourceUrl);
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$key} source did not return valid JSON.", previous: $exception);
        }
        $codelist = $payload['codelist'] ?? null;
        if (!is_array($codelist) || !isset($codelist['containeditems']) || !is_array($codelist['containeditems'])) {
            throw new RuntimeException("{$key} source has an unexpected JSON structure.");
        }
        $entries = [];
        foreach ($codelist['containeditems'] as $item) {
            $value = $item['value'] ?? null;
            if (!is_array($value) || !isset($value['id']) || !is_string($value['id'])) {
                continue;
            }
            $code = basename(parse_url($value['id'], PHP_URL_PATH) ?: $value['id']);
            if ($code === '') {
                throw new RuntimeException("{$key} source contains an entry without a code.");
            }
            $entries[] = [
                'code' => $code,
                'source_uri' => $value['id'],
                'label_cs' => $value['label']['text'] ?? null,
                'label_en' => null,
                'definition' => $value['definition']['text'] ?? null,
                'status' => $value['status']['id'] ?? 'VALID',
            ];
        }
        $this->replaceIfChanged($key, $sourceUrl, null, $body, $entries);
    }

    private function synchronizeSnapshot(string $path): void
    {
        $body = file_get_contents($path);
        if ($body === false) {
            throw new RuntimeException('HILUCS snapshot cannot be read.');
        }
        try {
            /** @var array<string, mixed> $snapshot */
            $snapshot = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('HILUCS snapshot is invalid JSON.', previous: $exception);
        }
        if (($snapshot['key'] ?? null) !== 'hilucs' || !is_string($snapshot['source_url'] ?? null) || !is_array($snapshot['entries'] ?? null)) {
            throw new RuntimeException('HILUCS snapshot has an unexpected structure.');
        }
        /** @var list<array<string, mixed>> $entries */
        $entries = $snapshot['entries'];
        $this->replaceIfChanged('hilucs', $snapshot['source_url'], $snapshot['source_version'] ?? null, $body, $entries);
    }

    /** @param list<array<string, mixed>> $entries */
    private function replaceIfChanged(string $key, string $sourceUrl, ?string $version, string $body, array $entries): void
    {
        $hash = hash('sha256', $body);
        $source = $this->pdo->prepare('SELECT source_hash FROM codelist_sources WHERE key = :key');
        $source->execute(['key' => $key]);
        if ($source->fetchColumn() === $hash) {
            $this->pdo->prepare('UPDATE codelist_sources SET fetched_at = NOW() WHERE key = :key')->execute(['key' => $key]);
            fwrite(STDOUT, "{$key}: unchanged." . PHP_EOL);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $upsertSource = $this->pdo->prepare(<<<'SQL'
                INSERT INTO codelist_sources (key, source_url, source_version, source_hash, fetched_at, changed_at)
                VALUES (:key, :source_url, :source_version, :source_hash, NOW(), NOW())
                ON CONFLICT (key) DO UPDATE SET
                    source_url = EXCLUDED.source_url,
                    source_version = EXCLUDED.source_version,
                    source_hash = EXCLUDED.source_hash,
                    fetched_at = NOW(),
                    changed_at = NOW()
            SQL);
            $upsertSource->execute(['key' => $key, 'source_url' => $sourceUrl, 'source_version' => $version, 'source_hash' => $hash]);
            $this->pdo->prepare('DELETE FROM codelist_entries WHERE source_key = :key')->execute(['key' => $key]);
            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO codelist_entries (source_key, code, source_uri, label_cs, label_en, definition, status)
                VALUES (:source_key, :code, :source_uri, :label_cs, :label_en, :definition, :status)
            SQL);
            foreach ($entries as $entry) {
                if (!is_string($entry['code'] ?? null) || !is_string($entry['source_uri'] ?? null)) {
                    throw new RuntimeException("{$key} source contains an invalid entry.");
                }
                $insert->execute([
                    'source_key' => $key,
                    'code' => $entry['code'],
                    'source_uri' => $entry['source_uri'],
                    'label_cs' => $entry['label_cs'] ?? null,
                    'label_en' => $entry['label_en'] ?? null,
                    'definition' => $entry['definition'] ?? null,
                    'status' => $entry['status'] ?? 'VALID',
                ]);
            }
            $this->pdo->commit();
            fwrite(STDOUT, "{$key}: imported " . count($entries) . " entries." . PHP_EOL);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function download(string $url): string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 30, 'header' => "Accept: application/json\r\nUser-Agent: JicinParcelMap/1.0\r\n"],
            'https' => ['timeout' => 30, 'header' => "Accept: application/json\r\nUser-Agent: JicinParcelMap/1.0\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("Unable to download codelist {$url}.");
        }
        return $body;
    }
}
