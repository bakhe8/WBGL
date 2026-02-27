CREATE TABLE IF NOT EXISTS settings_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    change_set_token TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    old_value_json TEXT NULL,
    new_value_json TEXT NULL,
    changed_by TEXT NOT NULL,
    source_ip TEXT NULL,
    user_agent TEXT NULL,
    changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_settings_audit_logs_token ON settings_audit_logs (change_set_token);
CREATE INDEX IF NOT EXISTS idx_settings_audit_logs_key ON settings_audit_logs (setting_key);
CREATE INDEX IF NOT EXISTS idx_settings_audit_logs_changed_at ON settings_audit_logs (changed_at);
