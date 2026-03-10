-- Dedicated advanced timeline visibility permission.
-- Purpose:
--   - keep timeline basic view for operational roles
--   - restrict lifecycle/cycle/batch metadata visibility to privileged roles
-- Default policy:
--   - enabled for developer/admin/system_admin
--   - disabled for all other roles unless explicitly granted

INSERT INTO permissions (name, slug, description)
VALUES (
    'عرض تفاصيل التايم لاين المتقدمة',
    'timeline_advanced_view',
    'Allow viewing advanced lifecycle metadata inside timeline events'
)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'timeline_advanced_view'
WHERE r.slug IN ('developer', 'admin', 'system_admin')
ON CONFLICT DO NOTHING;
