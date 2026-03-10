-- Grant timeline_advanced_view to administrative roles.
-- Rule:
--   1) Explicit privileged slugs (developer/admin/system_admin)
--   2) Any role that already has manage_users or manage_roles
-- This keeps assignment robust even when role slugs differ across environments.

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p_adv.id
FROM roles r
JOIN permissions p_adv ON p_adv.slug = 'timeline_advanced_view'
WHERE r.slug IN ('developer', 'admin', 'system_admin')
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, p_adv.id
FROM role_permissions rp
JOIN permissions p_gate ON p_gate.id = rp.permission_id
JOIN permissions p_adv ON p_adv.slug = 'timeline_advanced_view'
WHERE p_gate.slug IN ('manage_users', 'manage_roles')
ON CONFLICT DO NOTHING;
