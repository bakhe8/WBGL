-- Add explicit permission for bulk workflow advancement from batch surfaces.
-- Purpose:
--   - keep single-record workflow advance available via existing stage permissions
--   - add a separate high-impact permission for advancing many guarantees at once
-- Default policy:
--   - granted to developer/admin/system_admin roles only

INSERT INTO permissions (name, slug, description)
VALUES (
    'التقدم الجماعي في مراحل الاعتماد',
    'workflow_bulk_advance',
    'Allow advancing selected batch guarantees to the next workflow stage in bulk'
)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'workflow_bulk_advance'
WHERE r.slug IN ('developer', 'admin', 'system_admin')
ON CONFLICT DO NOTHING;
