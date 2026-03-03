-- Phase-1 / P1-05
-- Add missing operational indexes and domain constraints for stability.
-- Coverage: FR-25, R06, G23

-- Normalize legacy/out-of-domain values before enforcing constraints.
UPDATE guarantees
SET is_test_data = 0
WHERE is_test_data IS NULL
   OR is_test_data NOT IN (0, 1);

UPDATE guarantee_decisions
SET status = 'pending'
WHERE status IS NULL
   OR TRIM(status) = ''
   OR status NOT IN ('pending', 'ready', 'released');

UPDATE guarantee_decisions
SET workflow_step = 'draft'
WHERE workflow_step IS NULL
   OR TRIM(workflow_step) = ''
   OR workflow_step NOT IN ('draft', 'audited', 'analyzed', 'supervised', 'approved', 'signed');

UPDATE guarantee_decisions
SET active_action = NULL
WHERE active_action IS NOT NULL
  AND TRIM(active_action) <> ''
  AND active_action NOT IN ('extension', 'reduction', 'release');

UPDATE guarantee_decisions
SET signatures_received = 0
WHERE signatures_received IS NULL
   OR signatures_received < 0;

UPDATE guarantee_decisions
SET is_locked = TRUE,
    locked_reason = COALESCE(NULLIF(TRIM(locked_reason), ''), 'released')
WHERE status = 'released'
  AND COALESCE(is_locked, FALSE) = FALSE;

UPDATE undo_requests
SET status = 'pending'
WHERE status IS NULL
   OR TRIM(status) = ''
   OR status NOT IN ('pending', 'approved', 'rejected', 'executed');

-- Hot-path indexes (timeline / decisions / batch occurrences / undo governance).
CREATE INDEX IF NOT EXISTS idx_guarantee_history_gid_created_id
ON guarantee_history (guarantee_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_event_subtype
ON guarantee_history (event_subtype);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_anchor
ON guarantee_history (guarantee_id, is_anchor, id DESC);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_version
ON guarantee_history (history_version);

CREATE UNIQUE INDEX IF NOT EXISTS idx_guarantee_occurrences_guarantee_batch
ON guarantee_occurrences (guarantee_id, batch_identifier);

CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_batch_identifier
ON guarantee_occurrences (batch_identifier);

CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_batch_occurred
ON guarantee_occurrences (batch_identifier, occurred_at DESC, guarantee_id);

CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_guarantee_id
ON guarantee_occurrences (guarantee_id);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_status_workflow_lock
ON guarantee_decisions (status, workflow_step, is_locked, guarantee_id);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_active_action
ON guarantee_decisions (active_action);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_workflow_step
ON guarantee_decisions (workflow_step);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_bank_id
ON guarantee_decisions (bank_id);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_supplier_id
ON guarantee_decisions (supplier_id);

CREATE INDEX IF NOT EXISTS idx_undo_requests_status
ON undo_requests (status);

CREATE INDEX IF NOT EXISTS idx_undo_requests_guarantee
ON undo_requests (guarantee_id);

CREATE INDEX IF NOT EXISTS idx_undo_requests_created_at
ON undo_requests (created_at);

-- Domain constraints are enforced in PostgreSQL runtime.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantees_is_test_data_domain'
          AND conrelid = 'guarantees'::regclass
    ) THEN
        ALTER TABLE guarantees
            ADD CONSTRAINT chk_guarantees_is_test_data_domain
            CHECK (is_test_data IN (0, 1));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_decisions_status_domain'
          AND conrelid = 'guarantee_decisions'::regclass
    ) THEN
        ALTER TABLE guarantee_decisions
            ADD CONSTRAINT chk_guarantee_decisions_status_domain
            CHECK (status IN ('pending', 'ready', 'released'));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_decisions_workflow_step_domain'
          AND conrelid = 'guarantee_decisions'::regclass
    ) THEN
        ALTER TABLE guarantee_decisions
            ADD CONSTRAINT chk_guarantee_decisions_workflow_step_domain
            CHECK (workflow_step IN ('draft', 'audited', 'analyzed', 'supervised', 'approved', 'signed'));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_decisions_active_action_domain'
          AND conrelid = 'guarantee_decisions'::regclass
    ) THEN
        ALTER TABLE guarantee_decisions
            ADD CONSTRAINT chk_guarantee_decisions_active_action_domain
            CHECK (
                active_action IS NULL
                OR active_action = ''
                OR active_action IN ('extension', 'reduction', 'release')
            );
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_decisions_signatures_non_negative'
          AND conrelid = 'guarantee_decisions'::regclass
    ) THEN
        ALTER TABLE guarantee_decisions
            ADD CONSTRAINT chk_guarantee_decisions_signatures_non_negative
            CHECK (signatures_received >= 0);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_decisions_released_requires_lock'
          AND conrelid = 'guarantee_decisions'::regclass
    ) THEN
        ALTER TABLE guarantee_decisions
            ADD CONSTRAINT chk_guarantee_decisions_released_requires_lock
            CHECK (status <> 'released' OR COALESCE(is_locked, FALSE) = TRUE);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_undo_requests_status_domain'
          AND conrelid = 'undo_requests'::regclass
    ) THEN
        ALTER TABLE undo_requests
            ADD CONSTRAINT chk_undo_requests_status_domain
            CHECK (status IN ('pending', 'approved', 'rejected', 'executed'));
    END IF;
END $$;
