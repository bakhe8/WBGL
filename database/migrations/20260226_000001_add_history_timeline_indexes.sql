-- Improve timeline/history retrieval performance.
CREATE INDEX IF NOT EXISTS idx_guarantee_history_gid_created_id
ON guarantee_history (guarantee_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_event_subtype
ON guarantee_history (event_subtype);
