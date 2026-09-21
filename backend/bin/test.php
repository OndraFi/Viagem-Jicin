<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Import\CpxFeatureParser;

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
