CREATE TABLE IF NOT EXISTS guarantee_history_archive (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_history_id INTEGER NOT NULL,
    guarantee_id INTEGER NOT NULL,
    event_type TEXT,
    event_subtype TEXT,
    snapshot_data TEXT,
    event_details TEXT,
    letter_snapshot TEXT,
    history_version TEXT,
    patch_data TEXT,
    anchor_snapshot TEXT,
    is_anchor INTEGER DEFAULT 0,
    anchor_reason TEXT,
    letter_context TEXT,
    template_version TEXT,
    created_at TEXT,
    created_by TEXT,
    archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_by TEXT,
    archive_reason TEXT NOT NULL,
    source_table TEXT NOT NULL DEFAULT 'guarantee_history',
    UNIQUE (original_history_id, source_table)
);

CREATE INDEX IF NOT EXISTS idx_history_archive_guarantee
    ON guarantee_history_archive (guarantee_id, archived_at DESC);

CREATE INDEX IF NOT EXISTS idx_history_archive_original
    ON guarantee_history_archive (original_history_id);

