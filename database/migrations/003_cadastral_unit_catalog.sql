-- The RÚIAN catalogue is nationwide. District is derived by the explicit
-- catalogue synchronizer from UI_OBEC, rather than manufactured as a schema
-- default for every KÚ.
ALTER TABLE cadastral_units
    ALTER COLUMN enabled SET DEFAULT FALSE,
    ALTER COLUMN district_code DROP NOT NULL,
    ALTER COLUMN district_code DROP DEFAULT;

ALTER TABLE cadastral_units
    ADD COLUMN IF NOT EXISTS valid_from TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS valid_to TIMESTAMPTZ;
