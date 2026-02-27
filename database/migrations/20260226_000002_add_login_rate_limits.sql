CREATE TABLE IF NOT EXISTS login_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL,
    locked_until TEXT NULL,
    last_attempt_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_rate_limits_username ON login_rate_limits (username);
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_ip ON login_rate_limits (ip_address);
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_locked_until ON login_rate_limits (locked_until);
