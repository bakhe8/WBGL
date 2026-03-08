-- Dedicated print permission to decouple letter printing from broad data-entry operations.
-- Default policy:
--   - enabled for system manager role(s)
--   - disabled for data-entry role
-- Can be overridden later from Roles/Users management UI.

INSERT INTO permissions (name, slug, description)
VALUES (
    'طباعة الخطابات',
    'letters_print',
    'Allow printing letter previews and batch print surfaces'
)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'letters_print'
WHERE r.slug IN ('developer', 'admin', 'system_admin')
ON CONFLICT DO NOTHING;

DELETE FROM role_permissions
WHERE role_id IN (
    SELECT id FROM roles WHERE slug = 'data_entry'
)
AND permission_id = (
    SELECT id FROM permissions WHERE slug = 'letters_print'
);
