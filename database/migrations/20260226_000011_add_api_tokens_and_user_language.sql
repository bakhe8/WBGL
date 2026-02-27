ALTER TABLE users
ADD COLUMN preferred_language TEXT NOT NULL DEFAULT 'ar';

CREATE TABLE IF NOT EXISTS api_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_name TEXT NOT NULL DEFAULT 'api-client',
    token_hash TEXT NOT NULL UNIQUE,
    token_prefix TEXT NOT NULL,
    abilities_json TEXT,
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_user_id
    ON api_access_tokens (user_id);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_prefix
    ON api_access_tokens (token_prefix);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_revoked
    ON api_access_tokens (revoked_at);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_expires
    ON api_access_tokens (expires_at);
