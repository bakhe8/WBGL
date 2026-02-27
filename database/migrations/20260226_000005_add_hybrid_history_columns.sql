ALTER TABLE guarantee_history ADD COLUMN history_version TEXT DEFAULT 'v1';
ALTER TABLE guarantee_history ADD COLUMN patch_data TEXT;
ALTER TABLE guarantee_history ADD COLUMN anchor_snapshot TEXT;
ALTER TABLE guarantee_history ADD COLUMN is_anchor INTEGER DEFAULT 0;
ALTER TABLE guarantee_history ADD COLUMN anchor_reason TEXT;
ALTER TABLE guarantee_history ADD COLUMN letter_context TEXT;
ALTER TABLE guarantee_history ADD COLUMN template_version TEXT;

UPDATE guarantee_history
SET history_version = 'v1'
WHERE history_version IS NULL OR TRIM(history_version) = '';

CREATE INDEX IF NOT EXISTS idx_guarantee_history_anchor
ON guarantee_history (guarantee_id, is_anchor, id DESC);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_version
ON guarantee_history (history_version);
