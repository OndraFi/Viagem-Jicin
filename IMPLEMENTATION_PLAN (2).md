# Implementační plán – Jičín Parcel Map

> Implementační plán pro AI agenta. Projekt je rozdělen na **MUST HAVE** část, která má splnit interview zadání, a **NAD RÁMEC** část, která se implementuje až po dokončení a ověření MVP.
>
> Každá fáze má skončit funkčním stavem projektu a samostatným smysluplným commitem. Agent má průběžně kontrolovat kvalitu kódu, čitelnost a jednoduchost řešení. Nemá vytvářet zbytečnou abstrakci jen pro hypotetické budoucí potřeby.

---

# 1. Cíl projektu

Vytvořit lokálně spustitelnou webovou aplikaci, která zobrazí katastrální parcely na mapě a po kliknutí na parcelu zobrazí její základní údaje.

Interview zadání požaduje minimálně katastrální území Jičín + 3 další katastrální území v okrese Jičín a plynulou práci s mapou nad zvoleným rozsahem. Backend musí být v PHP; frontend a ostatní technologie jsou volné. Zadání zároveň očekává README, vysvětlení hlavních rozhodnutí a vítá průběžnou commit history.

Počáteční MUST HAVE rozsah:

| Katastrální území | Kód KÚ | Kód obce |
|---|---:|---:|
| Jičín | 659541 | 572659 |
| Holín | 641243 | 572900 |
| Valdice | 776530 | 573701 |
| Železnice | 796123 | 573825 |

Architektura musí být generická tak, aby přidání dalšího KÚ znamenalo pouze přidání konfigurace / dat, nikoli změnu aplikační logiky.

---

# 2. Rozdělení rozsahu

## 2.1 MUST HAVE – první hotová verze

Do první verze patří pouze věci nutné k přesvědčivému splnění zadání:

- čisté PHP 8.4 backend
- Vue 3 + Vite + TypeScript frontend
- PostgreSQL + PostGIS
- Docker Compose
- CPX jako zdroj parcel
- import minimálně 4 KÚ s tím, že veřejně dostupný rozsah je řízen `cadastral_units.enabled`
- jednoduchý DELETE + INSERT import v DB transakci pro každé KÚ
- idempotentní import
- PostGIS prostorové indexy
- REST API pro detail parcely
- výkonné načítání mapových dat pomocí MVT
- MapLibre mapa
- kliknutí na parcelu a detail
- základní české názvy atributů z oficiálních číselníků
- kvalitní README + decision log
- průběžná commit history
- základní testy kritické importní a API logiky

### Co v MUST HAVE naopak není

RÚIAN není podmínkou první verze. Do MVP se proto nebude importovat, pokud není potřeba pro základní funkčnost parcelní mapy.

Stejně tak nebudeme v první verzi stavět:

- budovy z RÚIAN
- adresní místa z RÚIAN
- ulice z RÚIAN
- vlastnické údaje
- LV
- historii změn parcel
- live dotazování na ČÚZK při každém requestu
- vlastní tile server mimo PostGIS API
- složitý background queue systém

---

## 2.2 NAD RÁMEC – po dokončení MVP

Po dokončení, otestování a stabilizaci MUST HAVE části je možné přidat:

1. **RÚIAN** jako doplňkový zdroj stavebních objektů a adresních míst.
2. volitelné vrstvy budov a adresních bodů na mapě.
3. propojení parcela → stavební objekt → adresní místo tam, kde je vazba jednoznačně dostupná.
4. více KÚ / automatické pokrytí celého okresu Jičín.
5. vyhledávání parcely podle čísla.
6. vyhledávání adresy.
7. caching MVT tiles.
8. automatické pravidelné aktualizace CPX/RÚIAN.
9. pokročilejší historizaci a audit změn.

Požadavkem je nejprve dokončit MUST HAVE. Nadstavba nesmí rozbít jednoduchost a stabilitu první verze.

---

# 3. Finálně zvolený stack

## Backend

- PHP 8.4
- čisté PHP bez frameworku
- PDO + PostgreSQL
- XMLReader / DOM pro GML/XML
- Composer pouze pro autoloading a případné malé podpůrné knihovny
- REST API
- CLI importéry oddělené od HTTP vrstvy

## Frontend

- Vue 3 + Vite
- TypeScript
- MapLibre GL JS
- mapová komponenta inicializovaná v lifecycle hooku `onMounted`

## Databáze

- PostgreSQL
- PostGIS
- GiST prostorové indexy

## Infrastructure

- Docker
- Docker Compose
- Makefile
- `.env.example`

---

# 4. Finální datová architektura

```text
                         ČÚZK
                          │
                         CPX
                          │
                    GML po KÚ / denně
                          │
                          ▼
                  PHP CPX importer
                          │
                          ▼
                 PostgreSQL + PostGIS
                          │
              ┌───────────┴───────────┐
              │                       │
          REST API                MVT API
              │                       │
              └───────────┬───────────┘
                          ▼
                   Vue 3 + Vite
                          │
                       MapLibre
                          │
                         OSM

NAD RÁMEC:

                         RÚIAN
                          │
                měsíční VFR po obcích
                          │
                    PHP RÚIAN importer
                          │
                          ▼
                 doplňkové DB tabulky
                          │
                          ▼
                 volitelné FE vrstvy
```

### Zásadní pravidla

1. **CPX je source of truth pro parcely a jejich geometrii.**
2. **RÚIAN není v první verzi potřeba a bude až rozšířením.**
3. Pokud se RÚIAN později přidá, jeho parcely se **nebudou duplicitně importovat** do tabulky `parcels`.
4. GML/XML se nikdy neparsuje během běžného requestu uživatele.
5. Mapa nebude v runtime stahovat celý okres jako jeden GeoJSON.
6. Interní geometrie budou drženy v EPSG:5514, do mapových tiles se data transformují podle potřeby do EPSG:3857.

---

# 5. Zdrojová data – přesně co odkud bereme

## 5.1 CPX – MUST HAVE

CPX (Cadastral Parcels Extended) je hlavní datový zdroj projektu. Z dodaného vzorku bylo ověřeno, že obsahuje parcelní identifikátor, parcelní číslo, výměru, polygonovou geometrii, KÚ, reference a další rozšířené atributy.

Oficiální zdroje:

- CPX WFS capabilities:
  `https://services.cuzk.gov.cz/wfs/inspire-CPX-wfs.asp?service=WFS&request=getCapabilities`
- CPX předdefinované GML v EPSG:5514:
  `https://services.cuzk.gov.cz/gml/inspire/cpx/epsg-5514/{KOD_KU}.zip`
- CPX ATOM:
  `https://atom.cuzk.cz/CPX/CPX.xml`

### Strategie získání dat

Pro runtime aplikace použít **předem stažené CPX GML**, nikoli live WFS requesty.

Důvod:

- lepší výkon při práci s mapou
- nezávislost frontend/backend runtime na dostupnosti WFS
- možnost importu do PostGIS a použití prostorových indexů
- deterministická data během běhu aplikace
- jednodušší lokální spuštění

WFS zůstává zdrojem pro dokumentaci, kontrolu endpointu a případné budoucí nástroje, ale ne pro každý uživatelský request.

### Z CPX ukládáme do `parcels`

- stabilní CPX/inspire identifikátor
- `localId`
- `label` = číslo parcely
- `nationalCadastralReference`
- `areaValue`
- `geometry`
- `referencePoint`
- `landType`
- `landUse`
- HILUCS (`hilucsLandType`, případně `hilucsLandUse`)
- `beginLifespanVersion`
- `endLifespanVersion`
- vazbu na KÚ
- případně další atribut jen tehdy, pokud bude mít konkrétní využití v UI nebo API

### Z CPX zobrazujeme na FE

Na mapě:

- parcelní polygon
- hranici parcely
- při vhodném zoomu parcelní číslo

Po kliknutí:

- číslo parcely
- výměra v m²
- katastrální území
- druh pozemku
- způsob využití, pokud je dostupný
- HILUCS, pokud je dostupný
- případně technický identifikátor

---

## 5.2 RÚIAN – NAD RÁMEC

RÚIAN se v první verzi neimportuje. Je připraven jako samostatná nadstavba.

Pro budoucí rozšíření použít **kompletní měsíční VFR za obec (`OB_XXXXXX_UKSH`)**, nikoli omezený SHP export obce.

Relevantní produkt obsahuje mimo jiné:

- stavební objekty
- adresní místa
- ulice a jejich definiční čáry
- územní vazby
- také parcely a jejich geometrie

### Důležitá zásada

RÚIAN sice obsahuje i parcely, ale jejich import bude záměrně vynechán. Parcelní tabulka bude mít pouze jeden source of truth: **CPX**.

RÚIAN bude sloužit pro:

- budovy
- adresní místa
- případné územní vztahy
- budoucí vyhledávání adres

### Budoucí FE využití RÚIAN

- volitelná vrstva budov
- volitelná vrstva adresních bodů
- v detailu parcely informace o budově/adrese pouze při jednoznačné vazbě

Nebude se „hádat“ adresa pouze podle prostorového překryvu, pokud zdrojová vazba není jednoznačná.

---

# 6. Databázový model

## 6.0 Před finálním schématem: ověření skutečného CPX GML

Projekt už obsahuje skutečný CPX GML vzorek z KÚ Jičín (`Pasted text(1).txt`). GML obsahuje také `xsi:schemaLocation` s odkazy na oficiální XSD pro CPX 4.0 a související typy. Při návrhu databáze proto platí pořadí:

```text
skutečný CPX GML
      ↓
ově ověřené atributy / kardinality / formáty
      ↓
finální DB schema
      ↓
migrace
```

Před vytvořením finální migrace pro `parcels` musí agent ověřit minimálně:

- stabilní CPX identifikátor a jeho skutečný formát
- vztah mezi `gml:id`, `base:Identifier/localId` a dalšími identifikátory
- kardinality a optionalitu používaných atributů
- strukturu geometrie včetně `exterior` a případných `interior` ringů
- skutečné hodnoty a odkazy `landType`, `landUse` a HILUCS
- vazbu parcely na KÚ přes `cp:zoning`

Samostatný XSD soubor nebyl v dosavadních předaných souborech uložen jako vlastní příloha; přesné XSD URL jsou ale uvedené přímo ve skutečném GML. Na samotný implementační plán tedy není potřeba další příloha. Při implementaci je agent povinen použít skutečný GML a podle potřeby oficiální XSD; nic z toho si nesmí domýšlet.

`parcels.id` **není v této fázi definitivně určeno jako `BIGINT`**. Typ primárního klíče se rozhodne až po tomto ověření.

## 6.1 `cadastral_units` – MUST HAVE

Katalog podporovaných KÚ.

```sql
CREATE TABLE cadastral_units (
    id BIGSERIAL PRIMARY KEY,
    code INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    municipality_code INTEGER,
    district_code INTEGER NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    geometry geometry(MultiPolygon, 5514),
    source_version TEXT,
    last_import_at TIMESTAMPTZ,
    last_import_status TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Obsahuje pouze konfiguraci a metadata importu. Konkrétní KÚ se nesmí rozhodovat natvrdo v PHP ani Vue kódu.

---

## 6.2 `parcels` – MUST HAVE

Primární aplikační tabulka parcel. **Typ a význam primárního klíče `parcels.id` zatím nejsou definitivně schválené.** Před vytvořením finální migrace musí agent nejprve projít skutečný CPX GML vzorek a jeho `schemaLocation` / odpovídající oficiální XSD. Nesmí si typ ID domýšlet jen z názvu XML elementu nebo ukázkové číselné hodnoty.

Z dostupného GML už víme, že parcela obsahuje mimo jiné:

- `gml:id` / CPX identifikátor
- `base:Identifier/localId`
- `cp:label`
- `cp:nationalCadastralReference`
- `cp:areaValue`
- `cp:geometry`
- `cp:referencePoint`
- `cp:zoning`
- rozšířené `landType`, `landUse` a HILUCS hodnoty
- `beginLifespanVersion` / `endLifespanVersion`

Finální schema se navrhne **až po ověření skutečného datového kontraktu**. Minimální logická struktura je: `parcels` + vazba na `cadastral_units` + CPX source ID + parcelní atributy + geometrie.

### Indexy

Po schválení finálního typu PK musí být minimálně: prostorový GiST index, index vazby na KÚ a unikátní index na stabilní CPX identifikátor.

```sql
CREATE INDEX parcels_geometry_gix
    ON parcels USING GIST (geometry);

CREATE INDEX parcels_cadastral_unit_idx
    ON parcels (cadastral_unit_id);
```

Konkrétní názvy `id`, `cpx_id`, `local_id` apod. a jejich datové typy agent definitivně stanoví až po ověření GML/XSD.

---

## 6.3 Budoucí RÚIAN tabulky – NAD RÁMEC

### `ruian_buildings`

```sql
CREATE TABLE ruian_buildings (
    id BIGSERIAL PRIMARY KEY,
    ruian_id BIGINT NOT NULL UNIQUE,
    cadastral_unit_id BIGINT REFERENCES cadastral_units(id),
    building_type_code TEXT,
    house_number_type TEXT,
    descriptive_number TEXT,
    orientation_number TEXT,
    geometry geometry(MultiPolygon, 5514),
    source_version TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### `ruian_addresses`

```sql
CREATE TABLE ruian_addresses (
    id BIGSERIAL PRIMARY KEY,
    ruian_id BIGINT NOT NULL UNIQUE,
    building_id BIGINT REFERENCES ruian_buildings(id),
    cadastral_unit_id BIGINT REFERENCES cadastral_units(id),
    street_name TEXT,
    municipality_name TEXT,
    postal_code TEXT,
    house_number TEXT,
    geometry geometry(Point, 5514),
    source_version TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Budoucí tabulky se mohou rozšířit podle skutečných polí ve VFR. Agent nemá vytvářet desítky sloupců jen proto, že je datový zdroj obsahuje.

---

# 7. Codelisty

## 7.1 MUST HAVE – druh pozemku

Použít oficiální ČÚZK číselník:

`https://services.cuzk.gov.cz/registry/codelist/LandTypeValue/LandTypeValue.xml`

Do projektu uložit lokální kopii / importovaný mapping, například:

```text
backend/data/codelists/land-type.xml
```

API může vracet současně:

- raw kód
- český uživatelský label

Překlady se nemají natvrdo rozmísťovat po PHP kódu.

## 7.2 HILUCS

HILUCS je INSPIRE codelist. Do první verze je možné uložit raw URI/kód a český překlad doplnit lokálním codelistem, pokud je jeho přesný obsah pro použité hodnoty ověřený.

Agent nesmí vymýšlet překlady neověřených hodnot.

---

# 8. Implementační fáze – MUST HAVE

---

## Fáze 0 – průzkum, rozhodnutí a skeleton

### Úkoly

- vytvořit repozitář
- vytvořit základní adresářovou strukturu
- založit `README.md`
- založit `DECISIONS.md`
- zaznamenat hlavní rozhodnutí
- založit Composer projekt
- založit Vue 3 + Vite projekt
- vytvořit `.env.example`
- připravit Docker Compose

### Počáteční architektura

```text
project/
├── backend/
├── frontend/
├── database/
├── data/
├── docker/
├── docker-compose.yml
├── Makefile
├── README.md
└── DECISIONS.md
```

### Povinné rozhodovací záznamy

- CPX vs RÚIAN jako zdroj parcel
- predownload vs live WFS
- PostGIS
- interní CRS
- MVT
- čisté PHP
- generická konfigurace KÚ
- RÚIAN jako rozšíření, ne MVP

### Commit

```text
chore: initialize project and document architecture
```

---

## Fáze 1 – Docker Compose a lokální infrastruktura

### Úkoly

Zprovoznit minimálně:

```text
frontend
backend
postgres/postgis
```

Podle potřeby může být importer pouze CLI entrypoint stejného PHP image, není nutné vytvářet samostatný dlouhodobě běžící container.

### Akceptační kritéria

- `docker compose up` nastartuje služby
- backend odpoví jednoduchým health endpointem
- frontend odpoví výchozí stránkou
- PostgreSQL je dostupný z backendu
- PostGIS je aktivní

### Důraz na kvalitu

Compose konfigurace má být jednoduchá a čitelná. Nepřidávat služby, které projekt aktuálně nepotřebuje.

### Commit

```text
chore: add dockerized development environment
```

---

## Fáze 2 – ověření CPX dat + databázové migrace a KÚ konfigurace

### Úkoly

1. projít skutečný CPX GML vzorek z projektu
2. projít `xsi:schemaLocation` a podle potřeby odpovídající oficiální XSD
3. definitivně určit datový model potřebný pro MVP
4. teprve potom vytvořit migraci pro `cadastral_units`
5. vytvořit migraci pro `parcels` podle ověřeného datového kontraktu
6. aktivovat PostGIS extension
7. přidat GiST indexy a další potřebné indexy podle skutečných access patternů
8. vložit 4 podporovaná KÚ
9. připravit obecnou datovou strukturu pro další KÚ

### Důležitá zásada

Migrace `parcels` se nesmí napsat jen podle aktuální pracovní hypotézy o CPX ID. Konkrétní typ `parcels.id` se určí až po ověření skutečného GML/XSD.

### Akceptační kritéria

Přidání dalšího KÚ musí být možné změnou konfigurace / DB dat bez změny aplikace. Pouze KÚ s `enabled = true` je součástí podporovaného a veřejně zobrazovaného rozsahu.

### Commit

```text
feat(db): add PostGIS schema and cadastral unit configuration
```

---

## Fáze 3 – CPX downloader a transakční importer

Toto je jedna z nejdůležitějších fází projektu.

### Úkoly

Implementovat PHP CLI importer, který:

1. načte KÚ s `enabled = true` z `cadastral_units`
2. sestaví CPX download URL z kódu KÚ
3. stáhne ZIP **mimo DB transakci**
4. bezpečně rozbalí GML
5. ověří skutečnou strukturu GML proti použitému datovému kontraktu
6. zpracuje GML streamově
7. převede geometrie do PostGIS
8. namapuje atributy do databáze
9. provede validaci vstupu
10. provede jednoduchý **DELETE + INSERT v jedné transakci pro konkrétní KÚ**
11. po úspěchu zapíše metadata importu

### Přesný transakční model

Pro interview použít jednoduchý model bez staging tabulky:

```text
BEGIN
  DELETE stará data konkrétního KÚ
  INSERT nová data konkrétního KÚ
  UPDATE metadata posledního importu
COMMIT
```

Pokud cokoliv během replace části selže:

```text
ROLLBACK
```

Díky transakci se DELETE při chybě také vrátí zpět a **stará data zůstanou dostupná**. Staging tabulka není pro interview MVP potřeba a agent ji nemá přidávat bez konkrétního výkonového nebo provozního důvodu.

Každé KÚ se importuje ve vlastní transakci. Chyba jednoho KÚ proto nesmí rollbacknout dříve úspěšně importované jiné KÚ.

### Idempotence

Stejný CPX dataset lze importovat znovu a výsledkem je stejný stav databáze bez duplicit. DELETE + INSERT tuto vlastnost řeší přirozeně.

### Důležité omezení

Síťový download, ZIP rozbalování a případná předběžná kontrola souboru nemají běžet uvnitř DB transakce. Transakce má pokrývat pouze výměnu konkrétní sady dat v databázi.

### Idempotence

Stejný CPX soubor lze importovat vícekrát bez vzniku duplicit.

### Stream parsing

Nepoužívat načtení celého GML dokumentu do paměti, pokud to není nutné. Preferovat XMLReader a zpracování po feature.

### Geometrie

CPX data jsou ve vzorku v EPSG:5514. Importované geometrie se ukládají do PostGIS v EPSG:5514.

### Validace

Minimálně:

- chybějící ID
- chybějící geometrie u povinného parcelního objektu
- neplatná geometrie
- neznámý KÚ
- chybějící povinné XML prvky
- neplatné / neočekávané hodnoty výměry

Chyba jednoho kritického importu nesmí být zamaskována jako úspěch.

### Acceptance criteria

- importer načte všechny 4 KÚ
- databáze obsahuje parcely
- geometrie jsou validní
- druhý import stejného datasetu nevytvoří duplicity
- chyba během importu rollbackne změny daného importu
- import je spustitelný jako CLI příkaz

### Commit

```text
feat(import): add transactional CPX parcel importer
```

---

## Fáze 4 – PHP REST API

### Úkoly

Implementovat jednoduchou a čitelnou HTTP vrstvu bez frameworku.

Endpointy:

```text
GET /api/health
GET /api/cadastral-units
GET /api/parcels/{id}
```

Pro mapu bude následovat samostatný MVT endpoint ve fázi 5.

### Detail parcely

`GET /api/parcels/{id}` vrátí minimálně:

- ID
- číslo parcely
- výměru
- KÚ
- druh pozemku
- způsob využití
- HILUCS raw hodnotu, pokud existuje

API nemá vracet zbytečně obrovské datové objekty.

### Architektura PHP

Použít jasné oddělení:

```text
Controller
   ↓
Service
   ↓
Repository
   ↓
PDO
```

Nemíchat SQL, HTTP odpovědi, validaci a business logiku do jednoho souboru.

### Kvalita kódu

- PSR-4 autoloading
- strict types tam, kde dávají smysl
- malé metody
- typované návratové hodnoty
- rozumné exception handling
- PDO prepared statements
- žádné SQL stringy skládané z uživatelského vstupu
- žádné globální spaghetti dependency

### Commit

```text
feat(api): add parcel REST API
```

---

## Fáze 5 – MVT mapový endpoint a výkonová vrstva

Tato fáze je stále MUST HAVE, protože zadání výslovně požaduje plynulou práci s mapou.

### Úkoly

Implementovat:

```text
GET /api/tiles/parcels/{z}/{x}/{y}.pbf
```

Endpoint použije PostGIS a SQL funkci `ST_AsMVT` / související funkce.

### Pravidla

- vracet pouze prvky relevantní pro daný tile
- používat prostorový index
- do MVT neposílat zbytečné atributy
- použít vhodnou generalizaci / zoom strategii
- na nízkém zoomu neřešit detail každého polygonu stejně jako na vysokém zoomu

### Příklad principu

```text
low zoom
  → méně detailu / méně feature

medium zoom
  → parcely se základními atributy

high zoom
  → detailní geometrie
```

Přesné zoom thresholdy nastavit podle reálného výkonu, ne podle odhadu.

### SQL

Použít prostorový filtr odpovídající konkrétnímu tile a ověřit query plán přes `EXPLAIN ANALYZE`.

### Acceptance criteria

- libovolný tile v podporovaném území vrátí validní MVT
- dotaz využívá prostorový index nebo je jeho nepoužití vědomě zdůvodněné
- velikost response je rozumná
- nepřenášejí se data celého okresu v jediném requestu

### Commit

```text
feat(api): add PostGIS vector tile endpoint
```

---

## Fáze 6 – Vue 3 + Vite + MapLibre mapa

### Úkoly

Vytvořit například:

```text
frontend/
├── src/
├── components/
│   ├── MapView.vue
│   ├── ParcelLayer.vue
│   └── ParcelDetails.vue
├── lib/
│   └── api.ts
└── types/
```

Implementovat:

- fullscreen map
- OSM basemap s korektní atribucí
- MapLibre vector source
- MVT endpoint jako zdroj parcel
- parcel fill + outline
- hover stav
- selected stav
- zoom/pan bez reloadu stránky
- startovní pohled na Jičín
- zobrazit všechny KÚ, které mají v databázi `enabled = true`
- KÚ s `enabled = false` nejsou součástí mapových dat ani UI
- uživatel KÚ nepřepíná přes selector; podporovaný rozsah je řízen databázovou konfigurací

### Vue 3

MapLibre je browser-only. Mapová část se inicializuje až v `onMounted` a při unmountu se korektně uklidí.

### Acceptance criteria

- mapa funguje v Chrome/Firefox
- parcely se načítají z vlastního API
- při změně viewportu se dotazují pouze potřebné tiles
- není načítán celý okres jako jeden GeoJSON

### Commit

```text
feat(frontend): add Vue MapLibre cadastral map
```

---

## Fáze 7 – detail parcely a UI

### Úkoly

Po kliknutí na polygon:

1. získat CPX ID feature
2. zavolat `/api/parcels/{id}`
3. zobrazit detail panel
4. zvýraznit vybraný polygon
5. při změně výběru detail aktualizovat
6. při zavření detail odstranit highlight

### Detail

```text
Parcela
├── Číslo parcely
├── Výměra
├── Katastrální území
├── Druh pozemku
├── Způsob využití
└── HILUCS
```

Technické hodnoty mohou být sekundární:

```text
Technické informace
├── CPX ID
├── National cadastral reference
├── platnost od
└── platnost do
```

### Codelist mapping

Druh pozemku zobrazovat přes lokální oficiální číselník.

### UX zásady

- detail nesmí překrývat celou mapu na desktopu
- loading stav musí být viditelný
- chyba API nesmí shodit celou mapu
- UI má zůstat jednoduché

### Commit

```text
feat(frontend): add parcel detail panel
```

---

## Fáze 8 – výkonové testování a optimalizace

Tato fáze je součást MUST HAVE, ne volitelný bonus.

### Testovací scénáře

1. celý rozsah 4 KÚ v jednom pohledu
2. výrazné přiblížení Jičína
3. rychlé posouvání mapy
4. rychlé zoomování
5. opakované klikání na parcely
6. současné načítání více tiles

### Měřit

- MVT response size
- API latency
- SQL `EXPLAIN ANALYZE`
- počet renderovaných feature
- browser rendering performance
- množství přenesených dat

### Optimalizace podle výsledků

- upravit zoom thresholds
- upravit generalizaci geometrie
- omezit atributy MVT
- doplnit indexy
- cache headers
- zamezit duplicitním requestům při rychlém pohybu mapy

### Acceptance criteria

Aplikace musí zůstat použitelná a plynulá nad celým podporovaným rozsahem 4 KÚ.

### Commit

```text
perf(map): optimize spatial queries and parcel rendering
```

---

## Fáze 9 – testy, robustnost a finální MUST HAVE import workflow

### Backend testy

Testovat minimálně:

- parsing čísla parcely
- parsing výměry
- parsing polygonu
- polygon s inner ring
- `NULL` atributy
- validaci ID
- mapování codelistu
- BBOX / tile parametrů

### Import testy

- stejný import dvakrát
- import novější verze
- nevalidní ZIP
- nevalidní GML
- chyba během SQL operace
- rollback

### Důraz

Testy mají být cílené na skutečně riziková místa. Nemá vzniknout test suite jen pro „coverage číslo“.

### Commit

```text
test: add importer and API regression tests
```

---

## Fáze 10 – README, decision log a finální Docker UX

### README musí obsahovat

1. co aplikace dělá
2. jak aplikaci spustit
3. požadavky
4. Docker postup
5. jak vytvořit DB
6. jak spustit migrace
7. jak spustit CPX import
8. jak přidat další KÚ
9. zdroje dat
10. CPX vs RÚIAN
11. predownload vs live WFS
12. proč PostGIS
13. proč MVT
14. jak je řešen výkon
15. známá omezení
16. co by bylo řešeno s více časem

### DECISIONS.md

Minimálně:

- `ADR-001`: CPX jako source of truth pro parcely
- `ADR-002`: RÚIAN až jako rozšíření
- `ADR-003`: predownload místo live WFS v runtime
- `ADR-004`: EPSG:5514 interně
- `ADR-005`: MVT + MapLibre
- `ADR-006`: čisté PHP bez frameworku
- `ADR-007`: generická konfigurace KÚ
- `ADR-008`: transakční a idempotentní import

### Makefile

Například:

```bash
make up
make down
make migrate
make import-cpx
make test
```

### Acceptance criteria

Nový developer může:

```bash
make up
make migrate
make import-cpx
```

a dostat funkční lokální aplikaci bez ruční konfigurace databáze.

### Commit

```text
docs: finalize README and decision log
```

---

# 9. Implementační fáze – NAD RÁMEC

Tyto fáze začínají **až po dokončení a otestování MUST HAVE**.

---

## Fáze A – RÚIAN importer

### Úkoly

Implementovat samostatný PHP CLI importer pro kompletní měsíční VFR za obec:

```text
OB_XXXXXX_UKSH.xml.zip
```

Importer:

1. načte podporované obce z konfigurace
2. stáhne VFR
3. streamově zpracuje relevantní prvky
4. vloží stavební objekty
5. vloží adresní místa
6. propojí vazby podle zdrojových identifikátorů
7. použije transakci pro celý import dané obce
8. zaznamená verzi zdroje

### Co se záměrně neimportuje

- RÚIAN parcely, protože parcelní source of truth zůstává CPX
- irelevantní entity jen proto, že jsou ve VFR dostupné

### Commit

```text
feat(ruian): add transactional buildings and addresses importer
```

---

## Fáze B – RÚIAN vrstvy na mapě

Přidat volitelné vrstvy:

```text
☑ Parcely
☐ Budovy
☐ Adresní místa
```

Budovy a adresní místa zobrazovat pouze při vhodném zoomu.

### Commit

```text
feat(frontend): add optional RUIAN map layers
```

---

## Fáze C – propojení parcely s budovou/adresou

Pouze při jednoznačné vazbě ze zdrojových dat.

V detailu parcely například:

```text
Budova
└── číslo / typ

Adresa
└── ulice + číslo + obec
```

Bez prostorového hádání.

### Commit

```text
feat(api): add RUIAN building and address context
```

---

## Fáze D – automatické aktualizace

### CPX

- kontrola nového datasetu pro každé aktivní KÚ
- pokud není nový zdroj → nic nedělat
- pokud je nový zdroj → spustit transakční import

### RÚIAN

- kontrola nové měsíční verze
- import jen pokud se změnila verze

Automatizace může běžet přes host cron, CI scheduler nebo jednoduchý scheduler container. Pro interview není potřeba stavět enterprise queue.

### Commit

```text
feat(import): add scheduled data refresh
```

---

## Fáze E – rozšíření na celý okres Jičín

Přidat další KÚ pouze přes konfiguraci.

Frontend ani backend nesmí obsahovat podmínky typu:

```php
if ($katastralniUzemi === 'Jicin') { ... }
```

Rozsah mapy je výsledkem konfigurace v `cadastral_units`. Pouze KÚ s `enabled = true` je součástí importovaného a veřejně zobrazovaného rozsahu. Uživatel nemá KÚ přepínat přes samostatný selector.

### Commit

```text
feat(data): expand supported cadastral units
```

---

## Fáze F – další produktové funkce

Možné pozdější rozšíření:

- vyhledávání parcely podle čísla
- vyhledávání adresy
- lepší práce s KÚ
- uložení posledního pohledu
- caching MVT
- monitoring importů
- auditní log změn
- historické snapshoty

Tyto funkce nejsou součástí interview MVP.

---

# 10. Požadovaná kvalita kódu

Toto je průběžný požadavek pro **všechny fáze**, nikoli samostatná fáze.

## PHP

- čisté PHP 8.4
- malé a srozumitelné třídy
- jedna odpovědnost třídy
- jasné názvy
- žádná zbytečná abstrakce
- repository vrstva pro DB přístup
- service vrstva pro business/import logiku
- controller vrstva pro HTTP
- žádná business logika v `public/index.php`
- žádné SQL ve Vue kódu
- prepared statements
- validace vstupu
- konzistentní exception handling
- logování chyb

## Import

- streamové zpracování velkých XML
- transakce
- idempotence
- rollback
- validace zdrojových dat
- oddělení download / parse / persistence / reporting

## Frontend

- TypeScript, ne `any` všude
- mapová logika oddělená od UI
- malé komponenty
- žádné zbytečné globální state management řešení
- jasné API typy
- ošetření loading/error stavů

## Obecně

Před každým commitem agent:

1. zkontroluje build
2. zkontroluje syntax / testy
3. zkontroluje, že přibyla pouze nezbytná komplexita
4. aktualizuje dokumentaci, pokud se změnilo architektonické rozhodnutí

---

# 11. Doporučená commit historie

## MUST HAVE

```text
chore: initialize project and document architecture
chore: add dockerized development environment
feat(db): add PostGIS schema and cadastral unit configuration
feat(import): add transactional CPX parcel importer
feat(api): add parcel REST API
feat(api): add PostGIS vector tile endpoint
feat(frontend): add Vue MapLibre cadastral map
feat(frontend): add parcel detail panel
perf(map): optimize spatial queries and parcel rendering
test: add importer and API regression tests
docs: finalize README and decision log
```

## NAD RÁMEC

```text
feat(ruian): add transactional buildings and addresses importer
feat(frontend): add optional RUIAN map layers
feat(api): add RUIAN building and address context
feat(import): add scheduled data refresh
feat(data): expand supported cadastral units
```

Commit messages mají být malé, konkrétní a mají odpovídat skutečnému obsahu commitu. Neudělat jeden obří commit typu `final project`.

---

# 12. Rozšiřitelnost přidáním dalšího KÚ

Nové KÚ se přidává konfigurací:

```sql
INSERT INTO cadastral_units (
    code,
    name,
    municipality_code,
    district_code,
    enabled
) VALUES (
    123456,
    'Nové KÚ',
    123456,
    3604,
    TRUE
);
```

CPX importer sestaví URL z `code`:

```text
https://services.cuzk.gov.cz/gml/inspire/cpx/epsg-5514/{code}.zip
```

Frontend se o konkrétních KÚ nesmí rozhodovat v kódu.

RÚIAN, pokud bude později zapojen, bude používat příslušný kód obce a `OB_XXXXXX_UKSH` dataset.

---

# 13. Co vědomě není součástí interview MVP

- celý okres Jičín jako povinný rozsah
- RÚIAN budovy a adresy
- RÚIAN parcely jako druhý zdroj
- vlastnické údaje
- list vlastnictví
- editace katastrálních dat
- účty uživatelů
- full-textové vyhledávání vlastníků
- historizace všech změn
- live WFS request při každém kliknutí / pohybu mapy
- vlastní distribuovaný tile server
- message broker
- složitý background-job systém

Tyto věci mohou být v README popsány jako možné další kroky.

---

# 14. Kritéria hotového MUST HAVE projektu

Projekt je hotový, pokud platí vše:

- [ ] `docker compose up` funguje
- [ ] PostGIS je aktivní
- [ ] migrace proběhnou bez ručního zásahu do DB
- [ ] jsou nakonfigurována 4 KÚ
- [ ] pouze KÚ s `enabled = true` jsou součástí importovaného a zobrazovaného rozsahu
- [ ] CPX importer načte všechny 4 KÚ
- [ ] import je transakční
- [ ] import je idempotentní
- [ ] chyba importu vede k rollbacku
- [ ] geometrie jsou v PostGIS validní
- [ ] existuje prostorový index
- [ ] PHP API vrací detail parcely
- [ ] MVT endpoint vrací pouze relevantní data pro tile
- [ ] Vue + MapLibre mapa funguje v Chrome/Firefox
- [ ] parcely jsou interaktivní
- [ ] kliknutí otevře detail
- [ ] české názvy hlavních atributů se řeší přes codelist
- [ ] celý podporovaný rozsah 4 KÚ je použitelný bez výrazného zamrzání
- [ ] existují základní testy importu a API
- [ ] README obsahuje přesné spuštění
- [ ] DECISIONS.md vysvětluje hlavní rozhodnutí
- [ ] commit history ukazuje průběžný postup

---

# 15. Poznámka pro AI agenta

Agent má postupovat **MUST HAVE → ověření → až potom NAD RÁMEC**.

Nesmí začít implementovat RÚIAN jen proto, že je datově zajímavý. RÚIAN je připravený jako pozdější rozšíření.

Při implementaci vždy preferovat jednoduché a čitelné řešení vhodné pro interview rozsah 4–12 hodin. Nemá vzniknout „enterprise architektura“ bez reálné potřeby.

Současně se nesmí porušit tyto invariants:

1. **CPX je jediný source of truth pro parcely.**
2. **Import je transakční a idempotentní.**
3. **Runtime mapa používá PostGIS prostorové dotazy a MVT, ne kompletní okres jako jeden GeoJSON.**
4. **KÚ jsou datová konfigurace, nikoli hardcoded podmínky v business logice.**
5. **RÚIAN je rozšíření a pokud se přidá, nesmí vytvořit druhou paralelní tabulku parcel jako zdroj pravdy.**
6. **Neznámé XML elementy, datové typy nebo kardinality se nejprve ověřují ve skutečném CPX GML vzorku a podle potřeby v oficiálním XSD; agent si je nesmí domýšlet. `parcels.id` není před kontrolou zdroje definitivně určené jako `BIGINT`.**
7. **Frontend zobrazuje celý rozsah KÚ s `enabled = true`; KÚ se uživatelsky nepřepínají.**
8. **Po každé fázi musí být projekt v použitelném stavu a změna musí být zaznamenána samostatným commitem.**
