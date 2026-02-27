CREATE TABLE IF NOT EXISTS break_glass_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action_name TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id TEXT,
    requested_by TEXT NOT NULL,
    reason TEXT NOT NULL,
    ticket_ref TEXT,
    ttl_minutes INTEGER NOT NULL DEFAULT 60,
    expires_at DATETIME NOT NULL,
    payload_json TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_break_glass_events_action ON break_glass_events (action_name);
CREATE INDEX IF NOT EXISTS idx_break_glass_events_target ON break_glass_events (target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_break_glass_events_requested_by ON break_glass_events (requested_by);
CREATE INDEX IF NOT EXISTS idx_break_glass_events_created_at ON break_glass_events (created_at);

CREATE TABLE IF NOT EXISTS batch_audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_source TEXT NOT NULL,
    event_type TEXT NOT NULL,
    reason TEXT,
    initiated_by TEXT NOT NULL,
    payload_json TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_batch_audit_events_source ON batch_audit_events (import_source);
CREATE INDEX IF NOT EXISTS idx_batch_audit_events_type ON batch_audit_events (event_type);
CREATE INDEX IF NOT EXISTS idx_batch_audit_events_created_at ON batch_audit_events (created_at);

INSERT OR IGNORE INTO permissions (name, slug, description)
VALUES
    ('إعادة فتح الدفعات', 'reopen_batch', 'Reopen closed batches with governance reason'),
    ('إعادة فتح الضمانات', 'reopen_guarantee', 'Reopen released guarantees under governed workflow'),
    ('تجاوز الطوارئ', 'break_glass_override', 'Emergency-only override with mandatory reason and ticket');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'reopen_batch'
WHERE r.slug IN ('developer', 'supervisor', 'approver');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'reopen_guarantee'
WHERE r.slug IN ('developer', 'supervisor', 'approver');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'break_glass_override'
WHERE r.slug IN ('developer', 'approver');
