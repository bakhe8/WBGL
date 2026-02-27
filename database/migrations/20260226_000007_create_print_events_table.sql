CREATE TABLE IF NOT EXISTS print_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NULL,
    batch_identifier TEXT NULL,
    event_type TEXT NOT NULL,
    context TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT 'browser',
    source_page TEXT NULL,
    initiated_by TEXT NOT NULL,
    payload_json TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_print_events_guarantee_id ON print_events (guarantee_id);
CREATE INDEX IF NOT EXISTS idx_print_events_batch_identifier ON print_events (batch_identifier);
CREATE INDEX IF NOT EXISTS idx_print_events_event_type ON print_events (event_type);
CREATE INDEX IF NOT EXISTS idx_print_events_created_at ON print_events (created_at);
