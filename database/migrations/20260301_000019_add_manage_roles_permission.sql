INSERT OR IGNORE INTO permissions (name, slug, description)
VALUES
    ('إدارة الأدوار', 'manage_roles', 'Create/update/delete roles and assign full role permissions');

-- Grant role-management to any role that already has manage_users.
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_users'
JOIN permissions p_new ON p_new.slug = 'manage_roles';
