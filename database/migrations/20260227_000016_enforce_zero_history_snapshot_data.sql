-- Enforce strict Hybrid V2 storage policy:
-- snapshot_data is legacy archive field and must remain NULL operationally.
UPDATE guarantee_history
SET snapshot_data = NULL
WHERE snapshot_data IS NOT NULL
  AND TRIM(snapshot_data) <> ''
  AND snapshot_data <> 'null'
  AND snapshot_data <> '{}';
