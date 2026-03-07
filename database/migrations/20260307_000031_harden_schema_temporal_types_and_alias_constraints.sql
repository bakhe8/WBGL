-- A14: Schema hardening for data integrity and temporal type consistency.

CREATE OR REPLACE FUNCTION wbgl_try_timestamptz(input_value TEXT)
RETURNS TIMESTAMPTZ
LANGUAGE plpgsql
AS $$
BEGIN
    IF input_value IS NULL OR BTRIM(input_value) = '' THEN
        RETURN NULL;
    END IF;

    RETURN input_value::timestamptz;
EXCEPTION
    WHEN OTHERS THEN
        RETURN NULL;
END;
$$;

-- Normalize supplier alternative names before enforcing uniqueness/NOT NULL.
UPDATE supplier_alternative_names
SET normalized_name = LOWER(BTRIM(COALESCE(normalized_name, alternative_name)))
WHERE normalized_name IS NULL
   OR BTRIM(normalized_name) = ''
   OR normalized_name <> LOWER(BTRIM(normalized_name));

-- Keep the strongest alias row per (supplier_id, normalized_name).
WITH ranked_aliases AS (
    SELECT
        id,
        ROW_NUMBER() OVER (
            PARTITION BY supplier_id, normalized_name
            ORDER BY usage_count DESC, id ASC
        ) AS rn
    FROM supplier_alternative_names
)
DELETE FROM supplier_alternative_names san
USING ranked_aliases ranked
WHERE san.id = ranked.id
  AND ranked.rn > 1;

ALTER TABLE supplier_alternative_names
    ALTER COLUMN normalized_name SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_supplier_alternative_names_supplier_normalized_unique
    ON supplier_alternative_names (supplier_id, normalized_name);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_supplier_alt_names_normalized_not_blank'
          AND conrelid = 'supplier_alternative_names'::regclass
    ) THEN
        ALTER TABLE supplier_alternative_names
            ADD CONSTRAINT chk_supplier_alt_names_normalized_not_blank
            CHECK (BTRIM(normalized_name) <> '');
    END IF;
END $$;

-- Enforce JSON object shape for guarantees.raw_data.
UPDATE guarantees
SET raw_data = json_build_object('legacy_payload', raw_data)
WHERE raw_data IS NULL
   OR json_typeof(raw_data) IS DISTINCT FROM 'object';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantees_raw_data_object'
          AND conrelid = 'guarantees'::regclass
    ) THEN
        ALTER TABLE guarantees
            ADD CONSTRAINT chk_guarantees_raw_data_object
            CHECK (json_typeof(raw_data) = 'object');
    END IF;
END $$;

-- guarantee_decisions.active_action_set_at TEXT -> TIMESTAMPTZ.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'guarantee_decisions'
          AND column_name = 'active_action_set_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE guarantee_decisions
            ALTER COLUMN active_action_set_at TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(active_action_set_at);
    END IF;
END $$;

-- login_rate_limits temporal columns TEXT -> TIMESTAMPTZ.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'login_rate_limits'
          AND column_name = 'window_started_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE login_rate_limits
            ALTER COLUMN window_started_at TYPE TIMESTAMPTZ
            USING COALESCE(wbgl_try_timestamptz(window_started_at), CURRENT_TIMESTAMP);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'login_rate_limits'
          AND column_name = 'locked_until'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE login_rate_limits
            ALTER COLUMN locked_until TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(locked_until);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'login_rate_limits'
          AND column_name = 'last_attempt_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE login_rate_limits
            ALTER COLUMN last_attempt_at TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(last_attempt_at);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'login_rate_limits'
          AND column_name = 'created_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE login_rate_limits
            ALTER COLUMN created_at TYPE TIMESTAMPTZ
            USING COALESCE(wbgl_try_timestamptz(created_at), CURRENT_TIMESTAMP);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'login_rate_limits'
          AND column_name = 'updated_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE login_rate_limits
            ALTER COLUMN updated_at TYPE TIMESTAMPTZ
            USING COALESCE(wbgl_try_timestamptz(updated_at), CURRENT_TIMESTAMP);
    END IF;
END $$;

ALTER TABLE login_rate_limits
    ALTER COLUMN window_started_at SET NOT NULL,
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN updated_at SET NOT NULL;

ALTER TABLE login_rate_limits
    ALTER COLUMN window_started_at SET DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP;

-- undo_requests temporal columns TEXT -> TIMESTAMPTZ.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'undo_requests'
          AND column_name = 'approved_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE undo_requests
            ALTER COLUMN approved_at TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(approved_at);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'undo_requests'
          AND column_name = 'rejected_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE undo_requests
            ALTER COLUMN rejected_at TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(rejected_at);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'undo_requests'
          AND column_name = 'executed_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE undo_requests
            ALTER COLUMN executed_at TYPE TIMESTAMPTZ
            USING wbgl_try_timestamptz(executed_at);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'undo_requests'
          AND column_name = 'created_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE undo_requests
            ALTER COLUMN created_at TYPE TIMESTAMPTZ
            USING COALESCE(wbgl_try_timestamptz(created_at), CURRENT_TIMESTAMP);
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'undo_requests'
          AND column_name = 'updated_at'
          AND data_type IN ('text', 'character varying')
    ) THEN
        ALTER TABLE undo_requests
            ALTER COLUMN updated_at TYPE TIMESTAMPTZ
            USING COALESCE(wbgl_try_timestamptz(updated_at), CURRENT_TIMESTAMP);
    END IF;
END $$;

ALTER TABLE undo_requests
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN updated_at SET NOT NULL;

ALTER TABLE undo_requests
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP;

DROP FUNCTION IF EXISTS wbgl_try_timestamptz(TEXT);
