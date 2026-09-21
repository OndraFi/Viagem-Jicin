<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Import\CpxFeatureParser;
use App\Http\TileCache;

$xml = <<<'XML'
<cp-ext:CadastralParcel xmlns:cp-ext="http://services.cuzk.cz/xsd/inspire/cp-ext/4.0" xmlns:cp="http://inspire.ec.europa.eu/schemas/cp/4.0" xmlns:base="http://inspire.ec.europa.eu/schemas/base/3.3" xmlns:gml="http://www.opengis.net/gml/3.2" gml:id="CPX.1">
  <cp:areaValue>100</cp:areaValue><cp:beginLifespanVersion>2026-01-01T00:00:00Z</cp:beginLifespanVersion>
  <cp:geometry><gml:Polygon><gml:exterior><gml:LinearRing><gml:posList>0 0 10 0 10 10 0 0</gml:posList></gml:LinearRing></gml:exterior><gml:interior><gml:LinearRing><gml:posList>2 2 3 2 3 3 2 2</gml:posList></gml:LinearRing></gml:interior></gml:Polygon></cp:geometry>
  <cp:inspireId><base:Identifier><base:localId>CPX.1</base:localId></base:Identifier></cp:inspireId><cp:label>42</cp:label><cp:nationalCadastralReference>659541-42</cp:nationalCadastralReference>
</cp-ext:CadastralParcel>
XML;
$document = new DOMDocument();
if (!$document->loadXML($xml) || !$document->documentElement) {
    throw new RuntimeException('Fixture cannot be parsed.');
}
$parcel = (new CpxFeatureParser())->parse($document->documentElement);
if ($parcel['cpx_id'] !== 'CPX.1' || $parcel['area_value'] !== 100.0 || !str_contains($parcel['wkt'], '),(')) {
    throw new RuntimeException('CPX parser regression test failed.');
}
fwrite(STDOUT, "CPX parser test passed." . PHP_EOL);

$cachePath = sys_get_temp_dir() . '/jicin-mvt-cache-' . bin2hex(random_bytes(6));
$cache = new TileCache($cachePath, ttlSeconds: 10, maxBytes: 100);
$generated = 0;
$first = $cache->remember(1, 13, 4445, 2762, function () use (&$generated): string {
    $generated++;
    return 'first tile';
});
$second = $cache->remember(1, 13, 4445, 2762, function () use (&$generated): string {
    $generated++;
    return 'unexpected tile';
});
$nextRevision = $cache->remember(2, 13, 4445, 2762, function () use (&$generated): string {
    $generated++;
    return 'next revision tile';
});
if ($first !== 'first tile' || $second !== 'first tile' || $nextRevision !== 'next revision tile' || $generated !== 2
    || is_file($cachePath . '/1/13/4445/2762.pbf')) {
    throw new RuntimeException('MVT cache regression test failed.');
}

$tilePath = $cachePath . '/2/13/4445/2762.pbf';
touch($tilePath, time() - 11);
$expired = $cache->remember(2, 13, 4445, 2762, function () use (&$generated): string {
    $generated++;
    return 'regenerated tile';
});
if ($expired !== 'regenerated tile' || $generated !== 3) {
    throw new RuntimeException('MVT cache TTL regression test failed.');
}

$limitedPath = sys_get_temp_dir() . '/jicin-mvt-cache-limited-' . bin2hex(random_bytes(6));
$limited = new TileCache($limitedPath, ttlSeconds: 900, maxBytes: 16);
$limited->remember(1, 13, 1, 1, static fn (): string => str_repeat('a', 8));
$limited->remember(1, 13, 1, 2, static fn (): string => str_repeat('b', 8));
$oldTilePath = $limitedPath . '/1/13/1/2.pbf';
touch($oldTilePath, time() - 60);
$limited->remember(1, 13, 1, 1, static fn (): string => 'unexpected cache miss');
$limited->remember(1, 13, 1, 3, static fn (): string => str_repeat('c', 8));
if (!is_file($limitedPath . '/1/13/1/1.pbf') || is_file($oldTilePath)
    || !is_file($limitedPath . '/1/13/1/3.pbf')) {
    throw new RuntimeException('MVT cache size-limit regression test failed.');
}

foreach ([$cachePath, $limitedPath] as $path) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($path);
}
fwrite(STDOUT, "MVT cache test passed." . PHP_EOL);
