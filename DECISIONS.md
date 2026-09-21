# Rozhodnutí

## ADR-001: CPX je zdrojem parcel

CPX poskytuje geometrii parcel i atributy potřebné pro MVP. RÚIAN není součástí první verze, aby nevznikly dva zdroje pravdy.

## ADR-002: Predownload místo live WFS

CPX se stahuje explicitním CLI importem a cacheuje do `data/cpx`. Běžný pohyb na mapě nikdy nezávisí na dostupnosti externí služby ČÚZK.

## ADR-003: PostGIS a MVT

Geometrie jsou uloženy v EPSG:5514 s GiST indexem. Mapa odebírá pouze MVT dlaždice pro aktuální viewport, ne celý okres jako GeoJSON.

## ADR-004: Vue 3 + Vite

Frontend zadání neurčuje. Vue používáme proto, že je v něm implementátor produktivnější; Vite udržuje SPA mapu jednoduchou bez serverového renderování.

## ADR-005: Transakční import po KÚ

XML se připraví před DB transakcí. Poté `DELETE + INSERT` v jedné transakci nahradí data konkrétního KÚ; selhání uchová předchozí konzistentní data.

## ADR-006: Databázové číselníky s content-hash synchronizací

`LandTypeValue` a `LandUseValue` se stahují z JSON endpointů ČÚZK a ukládají do `codelist_entries`. Zdroj nepředává použitelný `ETag` ani `Last-Modified`, proto importer porovnává SHA-256 odpovědi. HILUCS je pro aktuálně importované hodnoty uložen jako verzovaný snapshot podle nařízení EU 32013R1253; automatické čtení EU webového UI se vědomě nepoužívá.

## ADR-007: Nginx cache verzovaných MVT

MVT se při prvním požadavku vytvoří z PostGIS a Nginx ji uloží do vlastního cache volume. Klíč URL obsahuje revizi parcelních dat a souřadnice `z/x/y`. Úspěšný import jednoho KÚ zvýší revizi ve stejné DB transakci jako výměna parcel, takže nová mapa žádá jiný, neměnný klíč a nemůže získat dlaždici z předchozího datového stavu. Nginx cache má výchozí neaktivní TTL 15 minut, limit 256 MB, LRU evikci a cache lock proti současnému generování téhož tile. PHP neimplementuje vlastní souborovou cache; Redis ani samostatný tile server pro lokální projekt nepřidáváme.
