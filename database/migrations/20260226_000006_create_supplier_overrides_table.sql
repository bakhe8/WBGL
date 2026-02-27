CREATE TABLE IF NOT EXISTS supplier_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL,
    supplier_id INTEGER NOT NULL,
    reason TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by TEXT,
    updated_by TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_supplier_overrides_normalized_name
ON supplier_overrides (normalized_name);

CREATE INDEX IF NOT EXISTS idx_supplier_overrides_supplier_id
ON supplier_overrides (supplier_id);

CREATE INDEX IF NOT EXISTS idx_supplier_overrides_active
ON supplier_overrides (is_active);
