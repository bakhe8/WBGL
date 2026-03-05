-- P1-26: Enforce workflow transition guards at DB level (PostgreSQL)
-- Purpose:
-- 1) Keep status/workflow/action invariants aligned.
-- 2) Prevent invalid stage jumps written directly to DB.

-- Normalize legacy drift before strict trigger enforcement.
UPDATE guarantee_decisions
SET workflow_step = 'draft',
    active_action = NULL,
    active_action_set_at = NULL,
    signatures_received = 0
WHERE status = 'pending'
  AND (
        COALESCE(workflow_step, '') <> 'draft'
     OR COALESCE(BTRIM(active_action), '') <> ''
     OR COALESCE(signatures_received, 0) <> 0
  );

UPDATE guarantee_decisions
SET workflow_step = 'draft',
    signatures_received = 0
WHERE status = 'ready'
  AND COALESCE(workflow_step, '') <> 'draft'
  AND COALESCE(BTRIM(active_action), '') = '';

DO $$
DECLARE
    active_action_set_at_udt TEXT;
BEGIN
    SELECT udt_name
    INTO active_action_set_at_udt
    FROM information_schema.columns
    WHERE table_name = 'guarantee_decisions'
      AND column_name = 'active_action_set_at'
    LIMIT 1;

    IF active_action_set_at_udt IN ('timestamp', 'timestamptz') THEN
        EXECUTE $SQL$
            UPDATE guarantee_decisions
            SET active_action = 'release',
                active_action_set_at = COALESCE(active_action_set_at, CURRENT_TIMESTAMP)
            WHERE status = 'released'
              AND COALESCE(BTRIM(active_action), '') = ''
        $SQL$;
    ELSE
        EXECUTE $SQL$
            UPDATE guarantee_decisions
            SET active_action = 'release',
                active_action_set_at = COALESCE(NULLIF(BTRIM(active_action_set_at), ''), to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD HH24:MI:SS'))
            WHERE status = 'released'
              AND COALESCE(BTRIM(active_action), '') = ''
        $SQL$;
    END IF;
END;
$$;

UPDATE guarantee_decisions
SET signatures_received = 1
WHERE workflow_step = 'signed'
  AND status IN ('ready', 'released')
  AND COALESCE(signatures_received, 0) < 1;

CREATE OR REPLACE FUNCTION wbgl_enforce_decision_workflow_guards()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_step TEXT := COALESCE(OLD.workflow_step, '');
    new_step TEXT := COALESCE(NEW.workflow_step, '');
    new_status TEXT := COALESCE(NEW.status, '');
    new_action TEXT := COALESCE(BTRIM(NEW.active_action), '');
    is_valid_forward BOOLEAN := FALSE;
BEGIN
    -- Pending decisions stay in data-entry intake scope only.
    IF new_status = 'pending' THEN
        IF new_step <> 'draft' THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: pending decision must stay in draft stage';
        END IF;
        IF new_action <> '' THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: pending decision cannot carry active_action';
        END IF;
        IF COALESCE(NEW.signatures_received, 0) <> 0 THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: pending decision must have signatures_received = 0';
        END IF;
    END IF;

    -- Workflow stages after draft are only valid for operationally-ready records.
    IF new_step IN ('audited', 'analyzed', 'supervised', 'approved', 'signed') THEN
        IF new_status NOT IN ('ready', 'released') THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: non-draft stage requires ready/released status';
        END IF;

        IF new_status = 'ready' AND new_action = '' THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: non-draft ready stage requires active_action';
        END IF;
    END IF;

    IF new_step = 'signed' AND new_status IN ('ready', 'released') AND COALESCE(NEW.signatures_received, 0) < 1 THEN
        RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: signed stage requires signatures_received >= 1';
    END IF;

    -- Enforce legal stage transitions for updates.
    IF TG_OP = 'UPDATE' AND old_step <> new_step THEN
        -- Reject/reopen loop: any stage may return to draft.
        IF new_step = 'draft' THEN
            RETURN NEW;
        END IF;

        is_valid_forward := (old_step = 'draft' AND new_step = 'audited')
            OR (old_step = 'audited' AND new_step = 'analyzed')
            OR (old_step = 'analyzed' AND new_step = 'supervised')
            OR (old_step = 'supervised' AND new_step = 'approved')
            OR (old_step = 'approved' AND new_step = 'signed');

        IF NOT is_valid_forward THEN
            RAISE EXCEPTION 'WBGL_WORKFLOW_GUARD: invalid workflow transition % -> %', old_step, new_step;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_wbgl_enforce_decision_workflow_guards ON guarantee_decisions;

CREATE TRIGGER trg_wbgl_enforce_decision_workflow_guards
BEFORE INSERT OR UPDATE ON guarantee_decisions
FOR EACH ROW
EXECUTE FUNCTION wbgl_enforce_decision_workflow_guards();

