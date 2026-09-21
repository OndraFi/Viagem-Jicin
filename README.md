# Parcely Jičín

Lokálně spustitelná mapa parcel nad čtyřmi katastrálními územími okresu Jičín: Jičín, Holín, Valdice a Železnice. Po kliknutí zobrazí základní údaje parcely. Backend je čisté PHP 8.4, data leží v PostgreSQL/PostGIS a frontend je Vue 3 + Vite + MapLibre GL JS.

## Spuštění

Potřebujete Docker Desktop a Make. Zkopírujte `.env.example` do `.env` (výchozí hodnoty fungují), pak spusťte:

```bash
make up
```

V druhém terminálu proveďte migraci a import dat:

```bash
make migrate
make import-codelists
make import-cpx
```

Frontend běží na `http://localhost:5173`, API na `http://localhost:8080/api/health`.

První import stáhne CPX ZIP soubory ČÚZK do `data/cpx/`; adresář je záměrně ignorovaný Gitem. Další import využije lokální cache. Pro vynucení nového stažení spusťte `docker compose run --rm backend php bin/import-cpx.php --refresh`.

## Architektura a rozhodnutí

- **CPX je jediný zdroj parcel.** Obsahuje geometrii i základní atributy včetně druhu a způsobu využití. RÚIAN je vědomě mimo MVP, aby nevznikly dva zdroje pravdy.
- **Předem stažená data místo runtime WFS.** Importer stahuje CPX při explicitním importu, nikdy při pohybu po mapě.
- **PostGIS + MVT.** Geometrie zůstávají v EPSG:5514, prostorový GiST index filtruje parcelní data pro konkrétní dlaždici. API vrací MVT, nikoli GeoJSON celého okresu.
- **Vue 3 místo Next.js.** Zadání frontend neomezuje. Vue + Vite snižuje složitost SPA a umožňuje soustředit se na mapu, PHP a výkon.
- **Transakční idempotentní import.** XML se streamově převede do dočasné dávky před transakcí. Následně se pro každé KÚ v jedné transakci nahradí jeho data `DELETE + INSERT`; chyba ponechá předchozí stav.
- **KÚ jsou konfigurace databáze.** Další území se přidá vložením záznamu do `cadastral_units`; frontend ani importer neobsahují podmínky pro konkrétní název KÚ.

## Data a omezení

CPX: `https://services.cuzk.gov.cz/gml/inspire/cpx/epsg-5514/{KOD_KU}.zip`.

České názvy druhu pozemku a způsobu využití se synchronizují z oficiálních JSON číselníků ČÚZK. HILUCS používá verzovaný snapshot podle nařízení EU 32013R1253 pro hodnoty skutečně přítomné v importovaných CPX datech. `make import-codelists` také načte celý oficiální katalog RÚIAN `UI_KATASTRALNI_UZEMI`; nové KÚ jsou vždy neaktivní, čtyři katastry MVP zůstávají aktivní. Příkaz každý zdroj stáhne, ale DB změní jen pokud se změní SHA-256 obsahu; ČÚZK pro JSON endpointy neposkytuje použitelný `ETag` ani `Last-Modified`.

Při více času bych doplnil předgenerování nízko-zoomových generalizovaných vrstev, trvalé metriky importů, vyhledání podle čísla parcely a volitelné vrstvy budov/adres z RÚIAN.

## Ověření

```bash
make test
```

Před odevzdáním doporučuji naimportovat všechna čtyři KÚ, vyzkoušet rychlé posouvání/zoomování v Chrome i Firefoxu a zkontrolovat MVT SQL přes `EXPLAIN ANALYZE` na naplněné databázi.
