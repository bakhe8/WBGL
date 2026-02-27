ALTER TABLE users
ADD COLUMN preferred_theme TEXT NOT NULL DEFAULT 'system';

ALTER TABLE users
ADD COLUMN preferred_direction TEXT NOT NULL DEFAULT 'auto';

CREATE TABLE IF NOT EXISTS audit_trail_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    actor_user_id INTEGER NULL,
    actor_display TEXT NOT NULL,
    target_type TEXT NULL,
    target_id TEXT NULL,
    action TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'info',
    details_json TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_trail_events_event_type
    ON audit_trail_events (event_type);

CREATE INDEX IF NOT EXISTS idx_audit_trail_events_actor_user_id
    ON audit_trail_events (actor_user_id);

CREATE INDEX IF NOT EXISTS idx_audit_trail_events_target
    ON audit_trail_events (target_type, target_id);

CREATE INDEX IF NOT EXISTS idx_audit_trail_events_created_at
    ON audit_trail_events (created_at);
