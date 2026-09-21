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
