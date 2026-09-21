# Parcely Jičín

Lokálně spustitelná webová aplikace pro zobrazení katastrálních parcel v okrese Jičín. Po kliknutí do parcely zobrazí její číslo, výměru, katastrální území, druh pozemku, způsob využití a HILUCS.

Výchozí rozsah pokrývá požadované minimum: katastrální území Jičín, Holín, Valdice a Železnice. Aplikace pracuje plynule nad celým tímto rozsahem; rozšíření na celý okres je popsáno níže jako volitelný krok.

## Technologie

- Backend: čisté PHP 8.4.
- Databáze: PostgreSQL 17 s PostGIS 3.5.
- Frontend: Vue 3, Vite, TypeScript a MapLibre GL JS.
- Provoz: Docker Compose.

PHP, PostgreSQL i Node.js jsou součástí kontejnerů. Na hostiteli je potřeba jen Docker Desktop a při prvním startu přístup k ČÚZK a OpenStreetMap.

## Spuštění

```bash
docker compose up -d
```

Jednorázová služba `bootstrap` automaticky provede migrace, synchronizuje číselníky, nakonfiguruje výchozí čtyři KÚ a stáhne jejich CPX data. Průběh je k dispozici přes:

```bash
docker compose logs -f bootstrap
```

Po zprávě `Bootstrap completed.` je aplikace připravená:

- Frontend: `http://localhost:5173`
- API healthcheck: `http://localhost:8080/api/health`

Konfigurace má bezpečné výchozí hodnoty, takže `.env` není nutný. Soubor `.env.example` slouží pouze pro případné přepsání konfigurace. Další spuštění již znovu neimportuje úspěšně načtená CPX data. Aplikaci lze zastavit příkazem `docker compose down`; databázový volume a lokální cache zůstanou zachované.

## Data a import

Parcely pocházejí z předpřipravených CPX ZIP souborů ČÚZK v EPSG:5514:

```text
https://services.cuzk.gov.cz/gml/inspire/cpx/epsg-5514/{KOD_KU}.zip
```

Importer ZIP nejprve stáhne a XML zpracuje po jednotlivých parcelách. Teprve potom v jedné DB transakci pro konkrétní KÚ nahradí staré parcely novými. Selhání proto ponechá předchozí konzistentní data. Stažené ZIPy zůstávají v `data/cpx/`; pro vynucení nového stažení lze použít:

```bash
docker compose run --rm backend php bin/import-cpx.php --refresh
```

CPX je jediným zdrojem parcelní geometrie i parcelních atributů. Číselníky druhu pozemku a způsobu využití se synchronizují z oficiálních JSON endpointů ČÚZK. HILUCS používá verzovaný snapshot podle nařízení EU 32013R1253 pro hodnoty skutečně přítomné v importovaných datech.

## Architektura a výkon

Mapa nepracuje s GeoJSON celého okresu. PHP API vytváří MVT dlaždice z PostGIS pouze pro aktuální viewport:

- Geometrie zůstávají v EPSG:5514 a GiST index filtruje kandidátní parcely.
- Do webové dlaždice se transformují až vybrané geometrie a API vrací jen ID a geometrii; detail se načítá až po kliknutí.
- Parcely se stahují od zoomu 13, jemné hranice se kreslí až od zoomu 15.
- Vytvořené MVT dlaždice se ukládají do diskové cache podle revize parcelních dat. Úspěšný import KÚ revizi zvýší, takže se nemůže vrátit zastaralá dlaždice. Cache má TTL 15 minut a výchozí limit 256 MB; starší revize a nejstarší dlaždice se průběžně odstraňují.

Tento přístup odděluje náročný import od běžného pohybu po mapě a omezuje jak velikost přenosu, tak počet renderovaných prvků.

## Rozhodnutí a omezení

Podrobnější rozhodnutí jsou v [DECISIONS.md](DECISIONS.md): CPX jako jediný zdroj parcel, předem stažená data místo runtime WFS, PostGIS/MVT, Vue a transakční import.

RÚIAN budovy, adresy ani parcely nejsou součástí MVP, aby nevznikl druhý zdroj pravdy. Volitelný import celého okresu používá pouze číselníky RÚIAN KÚ a obcí pro určení okresu:

```bash
docker compose run --rm backend php bin/import-jicin-district.php
```

S více časem by dávalo smysl doplnit předgenerované generalizované geometrie pro nižší zoomy, metriky importů, vyhledání parcely podle čísla a volitelné vrstvy budov či adres.

## Ověření

```bash
docker compose run --rm backend php bin/test.php
```

Test ověřuje parsing CPX geometrie včetně vnitřního prstence a chování verzované MVT cache.
