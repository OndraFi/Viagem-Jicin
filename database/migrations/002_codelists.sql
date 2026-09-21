CREATE TABLE IF NOT EXISTS codelist_sources (
    key TEXT PRIMARY KEY,
    source_url TEXT NOT NULL,
    source_version TEXT,
    source_hash CHAR(64) NOT NULL,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS codelist_entries (
    source_key TEXT NOT NULL REFERENCES codelist_sources(key) ON DELETE CASCADE,
    code TEXT NOT NULL,
    source_uri TEXT NOT NULL,
    label_cs TEXT,
    label_en TEXT,
    definition TEXT,
    status TEXT NOT NULL DEFAULT 'VALID',
    valid_from DATE,
    valid_to DATE,
    PRIMARY KEY (source_key, code)
);

CREATE INDEX IF NOT EXISTS codelist_entries_lookup_idx
    ON codelist_entries (source_key, code);
