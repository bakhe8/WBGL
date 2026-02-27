CREATE TABLE IF NOT EXISTS undo_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    requested_by TEXT NOT NULL,
    approved_by TEXT NULL,
    rejected_by TEXT NULL,
    executed_by TEXT NULL,
    decision_note TEXT NULL,
    approved_at TEXT NULL,
    rejected_at TEXT NULL,
    executed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_undo_requests_status ON undo_requests (status);
CREATE INDEX IF NOT EXISTS idx_undo_requests_guarantee ON undo_requests (guarantee_id);
CREATE INDEX IF NOT EXISTS idx_undo_requests_created_at ON undo_requests (created_at);
