CREATE TABLE IF NOT EXISTS scheduler_job_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_token TEXT NOT NULL,
    job_name TEXT NOT NULL,
    attempt INTEGER NOT NULL DEFAULT 1,
    max_attempts INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL,
    exit_code INTEGER NULL,
    command_text TEXT NULL,
    output_text TEXT NULL,
    error_text TEXT NULL,
    duration_ms INTEGER NULL,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_scheduler_job_runs_token ON scheduler_job_runs (run_token);
CREATE INDEX IF NOT EXISTS idx_scheduler_job_runs_name ON scheduler_job_runs (job_name);
CREATE INDEX IF NOT EXISTS idx_scheduler_job_runs_status ON scheduler_job_runs (status);
CREATE INDEX IF NOT EXISTS idx_scheduler_job_runs_started_at ON scheduler_job_runs (started_at);
