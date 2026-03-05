-- P1-08
-- Enforce hard data-isolation guards for guarantee batches:
-- 1) No mixed batches (test + real in the same batch_identifier)
-- 2) No empty batch identifiers in occurrence ledger
-- 3) No empty import_source in guarantees

-- Normalize blank import_source values before adding constraints.
UPDATE guarantees
SET import_source = 'legacy_import_' || id::text
WHERE import_source IS NULL
   OR BTRIM(import_source) = '';

-- Normalize blank occurrence batch identifiers using guarantee import_source as fallback.
UPDATE guarantee_occurrences o
SET batch_identifier = COALESCE(
    NULLIF(BTRIM(o.batch_identifier), ''),
    NULLIF(BTRIM(g.import_source), ''),
    'legacy_batch_' || o.guarantee_id::text
)
FROM guarantees g
WHERE g.id = o.guarantee_id
  AND (o.batch_identifier IS NULL OR BTRIM(o.batch_identifier) = '');

-- Normalize blank occurrence type to a stable fallback token.
UPDATE guarantee_occurrences
SET batch_type = 'unknown'
WHERE batch_type IS NULL
   OR BTRIM(batch_type) = '';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT
                o.batch_identifier,
                COUNT(DISTINCT COALESCE(g.is_test_data, 0)) AS flag_count
            FROM guarantee_occurrences o
            JOIN guarantees g ON g.id = o.guarantee_id
            GROUP BY o.batch_identifier
            HAVING COUNT(DISTINCT COALESCE(g.is_test_data, 0)) > 1
        ) mixed_batches
    ) THEN
        RAISE EXCEPTION 'Cannot enforce batch isolation: mixed batches already exist';
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantees_import_source_not_blank'
          AND conrelid = 'guarantees'::regclass
    ) THEN
        ALTER TABLE guarantees
            ADD CONSTRAINT chk_guarantees_import_source_not_blank
            CHECK (BTRIM(import_source) <> '');
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_occurrences_batch_identifier_not_blank'
          AND conrelid = 'guarantee_occurrences'::regclass
    ) THEN
        ALTER TABLE guarantee_occurrences
            ADD CONSTRAINT chk_guarantee_occurrences_batch_identifier_not_blank
            CHECK (BTRIM(batch_identifier) <> '');
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_occurrences_batch_type_not_blank'
          AND conrelid = 'guarantee_occurrences'::regclass
    ) THEN
        ALTER TABLE guarantee_occurrences
            ADD CONSTRAINT chk_guarantee_occurrences_batch_type_not_blank
            CHECK (BTRIM(batch_type) <> '');
    END IF;
END $$;

CREATE OR REPLACE FUNCTION wbgl_assert_batch_purity(p_batch_identifier TEXT)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_distinct_flags INTEGER := 0;
BEGIN
    IF p_batch_identifier IS NULL OR BTRIM(p_batch_identifier) = '' THEN
        RETURN;
    END IF;

    SELECT COUNT(DISTINCT COALESCE(g.is_test_data, 0))
      INTO v_distinct_flags
    FROM guarantee_occurrences o
    JOIN guarantees g ON g.id = o.guarantee_id
    WHERE o.batch_identifier = p_batch_identifier;

    IF v_distinct_flags > 1 THEN
        RAISE EXCEPTION 'Batch purity violation for batch_identifier=% (mixed test/real records)', p_batch_identifier;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION wbgl_trg_enforce_batch_purity_occurrences()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM wbgl_assert_batch_purity(OLD.batch_identifier);
        RETURN NULL;
    END IF;

    PERFORM wbgl_assert_batch_purity(NEW.batch_identifier);

    IF TG_OP = 'UPDATE' AND OLD.batch_identifier IS DISTINCT FROM NEW.batch_identifier THEN
        PERFORM wbgl_assert_batch_purity(OLD.batch_identifier);
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION wbgl_trg_enforce_batch_purity_guarantees()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    rec RECORD;
BEGIN
    IF NEW.is_test_data IS NOT DISTINCT FROM OLD.is_test_data THEN
        RETURN NULL;
    END IF;

    FOR rec IN
        SELECT DISTINCT o.batch_identifier
        FROM guarantee_occurrences o
        WHERE o.guarantee_id = NEW.id
    LOOP
        PERFORM wbgl_assert_batch_purity(rec.batch_identifier);
    END LOOP;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_wbgl_batch_purity_occurrences ON guarantee_occurrences;
CREATE CONSTRAINT TRIGGER trg_wbgl_batch_purity_occurrences
AFTER INSERT OR UPDATE OR DELETE ON guarantee_occurrences
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION wbgl_trg_enforce_batch_purity_occurrences();

DROP TRIGGER IF EXISTS trg_wbgl_batch_purity_guarantees ON guarantees;
CREATE CONSTRAINT TRIGGER trg_wbgl_batch_purity_guarantees
AFTER UPDATE OF is_test_data ON guarantees
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION wbgl_trg_enforce_batch_purity_guarantees();
