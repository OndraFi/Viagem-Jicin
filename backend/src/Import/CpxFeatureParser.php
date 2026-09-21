<?php
declare(strict_types=1);

namespace App\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class CpxFeatureParser
{
    /** @return array<string, mixed> */
    public function parse(DOMElement $feature): array
    {
        $document = new DOMDocument();
        $root = $document->importNode($feature, true);
        $document->appendChild($root);
        $xpath = new DOMXPath($document);

        $cpxId = trim($feature->getAttributeNS('http://www.opengis.net/gml/3.2', 'id'));
        $localId = $this->text($xpath, 'string(.//*[local-name()="inspireId"]//*[local-name()="localId"])');
        $label = $this->text($xpath, 'string(.//*[local-name()="label"])');
        $area = $this->text($xpath, 'string(.//*[local-name()="areaValue"])');
        if ($cpxId === '' || $localId === '' || $label === '' || !is_numeric($area) || (float) $area < 0) {
            throw new RuntimeException('Parcel is missing a required identifier, label, or valid area.');
        }

        return [
            'cpx_id' => $cpxId,
            'local_id' => $localId,
            'label' => $label,
            'national_cadastral_reference' => $this->nullableText($xpath, 'string(.//*[local-name()="nationalCadastralReference"])'),
            'area_value' => (float) $area,
            'land_type_code' => $this->lastUriSegment($this->text($xpath, 'string(.//*[local-name()="landType"]/@*[local-name()="href"])')),
            'land_use_code' => $this->lastUriSegment($this->text($xpath, 'string(.//*[local-name()="landUse"]/@*[local-name()="href"])')),
            'hilucs_land_type' => $this->lastUriSegment($this->text($xpath, 'string(.//*[local-name()="hilucsLandType"]//*[local-name()="hilucsValue"]/@*[local-name()="href"])')),
            'hilucs_land_use' => $this->lastUriSegment($this->text($xpath, 'string(.//*[local-name()="hilucsLandUse"]//*[local-name()="hilucsValue"]/@*[local-name()="href"])')),
            'begin_lifespan_version' => $this->nullableText($xpath, 'string(.//*[local-name()="beginLifespanVersion"])'),
            'end_lifespan_version' => $this->nullableText($xpath, 'string(.//*[local-name()="endLifespanVersion"])'),
            'wkt' => $this->multipolygonWkt($xpath),
            'reference_point' => $this->referencePoint($xpath),
        ];
    }

    private function multipolygonWkt(DOMXPath $xpath): string
    {
        $polygons = $xpath->query('./*[local-name()="geometry"]//*[local-name()="Polygon"]');
        if ($polygons === false || $polygons->count() === 0) {
            throw new RuntimeException('Parcel geometry does not contain a polygon.');
        }
        $parts = [];
        foreach ($polygons as $polygon) {
            $exterior = $xpath->query('./*[local-name()="exterior"]//*[local-name()="posList"]', $polygon);
            if ($exterior === false || $exterior->count() !== 1) {
                throw new RuntimeException('Parcel polygon has no exterior ring.');
            }
            $rings = [$this->ring($exterior->item(0)?->textContent ?? '')];
            $interiors = $xpath->query('./*[local-name()="interior"]//*[local-name()="posList"]', $polygon);
            if ($interiors !== false) {
                foreach ($interiors as $interior) {
                    $rings[] = $this->ring($interior->textContent);
                }
            }
            $parts[] = '(' . implode(',', $rings) . ')';
        }
        return 'MULTIPOLYGON(' . implode(',', $parts) . ')';
    }

    private function ring(string $positions): string
    {
        $values = preg_split('/\s+/', trim($positions)) ?: [];
        if (count($values) < 8 || count($values) % 2 !== 0) {
            throw new RuntimeException('Polygon ring has an invalid coordinate count.');
        }
        $coordinates = [];
        for ($index = 0; $index < count($values); $index += 2) {
            if (!is_numeric($values[$index]) || !is_numeric($values[$index + 1])) {
                throw new RuntimeException('Polygon contains a non-numeric coordinate.');
            }
            $coordinates[] = $values[$index] . ' ' . $values[$index + 1];
        }
        return '(' . implode(',', $coordinates) . ')';
    }

    /** @return array{0: float, 1: float}|null */
    private function referencePoint(DOMXPath $xpath): ?array
    {
        $value = $this->nullableText($xpath, 'string(./*[local-name()="referencePoint"]//*[local-name()="pos"])');
        if ($value === null) {
            return null;
        }
        $parts = preg_split('/\s+/', $value) ?: [];
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new RuntimeException('Reference point has invalid coordinates.');
        }
        return [(float) $parts[0], (float) $parts[1]];
    }

    private function text(DOMXPath $xpath, string $expression): string
    {
        return trim((string) $xpath->evaluate($expression));
    }

    private function nullableText(DOMXPath $xpath, string $expression): ?string
    {
        $value = $this->text($xpath, $expression);
        return $value === '' ? null : $value;
    }

    private function lastUriSegment(string $uri): ?string
    {
        if ($uri === '') {
            return null;
        }
        $segment = basename(parse_url($uri, PHP_URL_PATH) ?: $uri);
        return $segment === '' ? null : $segment;
    }
}
