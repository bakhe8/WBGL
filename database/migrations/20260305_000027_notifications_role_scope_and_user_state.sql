-- Notifications v2:
-- 1) Role/User/Global targeting on notifications table.
-- 2) Per-user state table (read/hidden) so one user action does not affect others.

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS recipient_role_slug TEXT NULL;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS visibility_scope TEXT NOT NULL DEFAULT 'global';

-- Backfill legacy rows:
-- Legacy behavior used recipient_username NULL for global, non-null for user-targeted.
UPDATE notifications
SET visibility_scope = CASE
    WHEN recipient_username IS NOT NULL AND TRIM(recipient_username) <> '' THEN 'user'
    ELSE 'global'
END
WHERE visibility_scope IS NULL
   OR TRIM(visibility_scope) = '';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_notifications_visibility_scope'
    ) THEN
        ALTER TABLE notifications
            ADD CONSTRAINT chk_notifications_visibility_scope
            CHECK (visibility_scope IN ('global', 'user', 'role'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_notifications_visibility_scope ON notifications (visibility_scope);
CREATE INDEX IF NOT EXISTS idx_notifications_recipient_role_slug ON notifications (recipient_role_slug);

CREATE TABLE IF NOT EXISTS notification_user_states (
    notification_id BIGINT NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
    username TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    is_hidden INTEGER NOT NULL DEFAULT 0,
    read_at TIMESTAMP NULL,
    hidden_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, username)
);

CREATE INDEX IF NOT EXISTS idx_notification_user_states_username
    ON notification_user_states (username);

CREATE INDEX IF NOT EXISTS idx_notification_user_states_unread_visible
    ON notification_user_states (username, is_hidden, is_read);

