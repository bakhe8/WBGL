-- Split supplier/bank reference operations from manage_data into dedicated permissions.
-- Backward compatibility: grant both new permissions to any role that already has manage_data.

INSERT INTO permissions (name, slug, description)
SELECT 'إدارة الموردين', 'supplier_manage', 'Allow create/update/delete/merge supplier reference entities'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'supplier_manage');

INSERT INTO permissions (name, slug, description)
SELECT 'إدارة البنوك', 'bank_manage', 'Allow create/update/delete bank reference entities'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'bank_manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_data'
JOIN permissions p_new ON p_new.slug IN ('supplier_manage', 'bank_manage')
LEFT JOIN role_permissions existing ON existing.role_id = rp.role_id AND existing.permission_id = p_new.id
WHERE existing.role_id IS NULL;
