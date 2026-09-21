CREATE EXTENSION IF NOT EXISTS postgis;

CREATE TABLE IF NOT EXISTS cadastral_units (
    id BIGSERIAL PRIMARY KEY,
    code INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    municipality_code INTEGER,
    district_code INTEGER NOT NULL DEFAULT 3604,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    source_version TEXT,
    last_import_at TIMESTAMPTZ,
    last_import_status TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS parcels (
    id BIGSERIAL PRIMARY KEY,
    cadastral_unit_id BIGINT NOT NULL REFERENCES cadastral_units(id) ON DELETE CASCADE,
    cpx_id TEXT NOT NULL UNIQUE,
    local_id TEXT NOT NULL,
    label TEXT NOT NULL,
    national_cadastral_reference TEXT,
    area_value NUMERIC(14, 2) NOT NULL CHECK (area_value >= 0),
    land_type_code TEXT,
    land_use_code TEXT,
    hilucs_land_type TEXT,
    hilucs_land_use TEXT,
    begin_lifespan_version TIMESTAMPTZ,
    end_lifespan_version TIMESTAMPTZ,
    geometry geometry(MultiPolygon, 5514) NOT NULL,
    reference_point geometry(Point, 5514),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS parcels_geometry_gix ON parcels USING GIST (geometry);
CREATE INDEX IF NOT EXISTS parcels_cadastral_unit_idx ON parcels (cadastral_unit_id);
