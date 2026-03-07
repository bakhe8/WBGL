-- Add rate limiting state table for bearer token authentication attempts.

CREATE TABLE IF NOT EXISTS api_token_rate_limits (
    id BIGSERIAL PRIMARY KEY,
    identifier TEXT NOT NULL UNIQUE,
    token_fingerprint TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    user_agent_hash TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    last_attempt_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_token_rate_limits_fingerprint
    ON api_token_rate_limits (token_fingerprint);

CREATE INDEX IF NOT EXISTS idx_api_token_rate_limits_locked_until
    ON api_token_rate_limits (locked_until);

CREATE INDEX IF NOT EXISTS idx_api_token_rate_limits_window_started_at
    ON api_token_rate_limits (window_started_at);
