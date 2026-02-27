CREATE TABLE IF NOT EXISTS scheduler_dead_letters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_name TEXT NOT NULL,
    run_token TEXT NOT NULL,
    last_run_id INTEGER NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 0,
    exit_code INTEGER NULL,
    failure_reason TEXT NULL,
    error_text TEXT NULL,
    output_text TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    resolution_note TEXT NULL,
    resolved_by TEXT NULL,
    resolved_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_scheduler_dead_letters_job_name ON scheduler_dead_letters (job_name);
CREATE INDEX IF NOT EXISTS idx_scheduler_dead_letters_status ON scheduler_dead_letters (status);
CREATE INDEX IF NOT EXISTS idx_scheduler_dead_letters_run_token ON scheduler_dead_letters (run_token);
CREATE INDEX IF NOT EXISTS idx_scheduler_dead_letters_created_at ON scheduler_dead_letters (created_at);
