-- The RÚIAN catalogue is nationwide. District is not part of its published
-- UI_KATASTRALNI_UZEMI feed, so it must stay nullable until a municipality /
-- district catalogue is synchronized as well.
ALTER TABLE cadastral_units
    ALTER COLUMN enabled SET DEFAULT FALSE,
    ALTER COLUMN district_code DROP NOT NULL,
    ALTER COLUMN district_code DROP DEFAULT;

ALTER TABLE cadastral_units
    ADD COLUMN IF NOT EXISTS valid_from TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS valid_to TIMESTAMPTZ;
