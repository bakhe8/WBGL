-- Enforce "every guarantee must have at least one occurrence" invariant.
-- This migration:
-- 1) Backfills legacy orphans into guarantee_occurrences.
-- 2) Ensures batch_metadata exists for any batch identifier in occurrence ledger.
-- 3) Adds deferred constraint triggers to prevent future orphan guarantees.

BEGIN;

-- 1) Backfill orphan guarantees (guarantees without any occurrence rows).
INSERT INTO guarantee_occurrences (guarantee_id, batch_identifier, batch_type, occurred_at)
SELECT
    g.id,
    COALESCE(NULLIF(BTRIM(g.import_source), ''), 'orphan_backfill_' || g.id::text) AS batch_identifier,
    'orphan_backfill' AS batch_type,
    COALESCE(g.imported_at, CURRENT_TIMESTAMP) AS occurred_at
FROM guarantees g
LEFT JOIN guarantee_occurrences o ON o.guarantee_id = g.id
WHERE o.guarantee_id IS NULL;

-- 2) Ensure metadata row exists for every batch in occurrence ledger.
INSERT INTO batch_metadata (import_source, batch_name, batch_notes, status)
SELECT DISTINCT
    o.batch_identifier,
    o.batch_identifier,
    'Auto-created by occurrence integrity migration',
    'active'
FROM guarantee_occurrences o
LEFT JOIN batch_metadata bm ON bm.import_source = o.batch_identifier
WHERE bm.import_source IS NULL
ON CONFLICT (import_source) DO NOTHING;

-- 3) Guarantee inserts must be paired with at least one occurrence before commit.
CREATE OR REPLACE FUNCTION wbgl_trg_require_guarantee_occurrence()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM guarantee_occurrences o
        WHERE o.guarantee_id = NEW.id
    ) THEN
        RAISE EXCEPTION
            USING ERRCODE = '23514',
                  MESSAGE = format(
                      'Guarantee %s (%s) has no occurrence row at commit time.',
                      NEW.id,
                      COALESCE(NEW.guarantee_number, '<NULL>')
                  ),
                  HINT = 'Write guarantee_occurrences in the same transaction (ImportService::recordOccurrence).';
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_wbgl_guarantee_requires_occurrence ON guarantees;
CREATE CONSTRAINT TRIGGER trg_wbgl_guarantee_requires_occurrence
AFTER INSERT ON guarantees
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION wbgl_trg_require_guarantee_occurrence();

-- 4) Do not allow deleting the last occurrence of a still-existing guarantee.
CREATE OR REPLACE FUNCTION wbgl_trg_prevent_last_occurrence_delete()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.guarantee_id IS NULL THEN
        RETURN NULL;
    END IF;

    -- If parent guarantee was deleted in same transaction (CASCADE), skip.
    IF NOT EXISTS (
        SELECT 1
        FROM guarantees g
        WHERE g.id = OLD.guarantee_id
    ) THEN
        RETURN NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM guarantee_occurrences o
        WHERE o.guarantee_id = OLD.guarantee_id
    ) THEN
        RAISE EXCEPTION
            USING ERRCODE = '23514',
                  MESSAGE = format(
                      'Deleting this occurrence would orphan guarantee %s.',
                      OLD.guarantee_id
                  ),
                  HINT = 'A guarantee must keep at least one occurrence row.';
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_wbgl_prevent_last_occurrence_delete ON guarantee_occurrences;
CREATE CONSTRAINT TRIGGER trg_wbgl_prevent_last_occurrence_delete
AFTER DELETE ON guarantee_occurrences
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION wbgl_trg_prevent_last_occurrence_delete();

COMMIT;
