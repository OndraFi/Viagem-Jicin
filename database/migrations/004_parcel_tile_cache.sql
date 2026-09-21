CREATE TABLE IF NOT EXISTS parcel_tile_state (
    singleton BOOLEAN PRIMARY KEY DEFAULT TRUE CHECK (singleton),
    revision BIGINT NOT NULL DEFAULT 1 CHECK (revision > 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO parcel_tile_state (singleton, revision)
VALUES (TRUE, 1)
ON CONFLICT (singleton) DO NOTHING;
