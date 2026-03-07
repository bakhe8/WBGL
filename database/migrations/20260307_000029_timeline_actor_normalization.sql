-- Normalize timeline actor metadata for consistent rendering/audit.
-- Adds canonical actor columns to both live and archive history tables.

ALTER TABLE guarantee_history
    ADD COLUMN IF NOT EXISTS actor_kind TEXT NOT NULL DEFAULT 'system';

ALTER TABLE guarantee_history
    ADD COLUMN IF NOT EXISTS actor_display TEXT;

ALTER TABLE guarantee_history
    ADD COLUMN IF NOT EXISTS actor_user_id BIGINT;

ALTER TABLE guarantee_history
    ADD COLUMN IF NOT EXISTS actor_username TEXT;

ALTER TABLE guarantee_history
    ADD COLUMN IF NOT EXISTS actor_email TEXT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_history_actor_kind'
    ) THEN
        ALTER TABLE guarantee_history
            ADD CONSTRAINT chk_guarantee_history_actor_kind
            CHECK (actor_kind IN ('system', 'user', 'service'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_guarantee_history_actor_kind
    ON guarantee_history (actor_kind);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_actor_user_id
    ON guarantee_history (actor_user_id);

ALTER TABLE guarantee_history_archive
    ADD COLUMN IF NOT EXISTS actor_kind TEXT NOT NULL DEFAULT 'system';

ALTER TABLE guarantee_history_archive
    ADD COLUMN IF NOT EXISTS actor_display TEXT;

ALTER TABLE guarantee_history_archive
    ADD COLUMN IF NOT EXISTS actor_user_id BIGINT;

ALTER TABLE guarantee_history_archive
    ADD COLUMN IF NOT EXISTS actor_username TEXT;

ALTER TABLE guarantee_history_archive
    ADD COLUMN IF NOT EXISTS actor_email TEXT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_guarantee_history_archive_actor_kind'
    ) THEN
        ALTER TABLE guarantee_history_archive
            ADD CONSTRAINT chk_guarantee_history_archive_actor_kind
            CHECK (actor_kind IN ('system', 'user', 'service'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_history_archive_actor_kind
    ON guarantee_history_archive (actor_kind);

CREATE INDEX IF NOT EXISTS idx_history_archive_actor_user_id
    ON guarantee_history_archive (actor_user_id);

