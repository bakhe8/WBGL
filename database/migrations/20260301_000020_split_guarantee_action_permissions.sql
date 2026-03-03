-- Split guarantee operational actions into dedicated permissions.
-- Keep backward compatibility by granting them to any role that already has manage_data.

INSERT INTO permissions (name, slug, description)
SELECT 'حفظ تعديلات الضمان', 'guarantee_save', 'Allow saving guarantee decision updates and save-and-next mutations'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'guarantee_save');

INSERT INTO permissions (name, slug, description)
SELECT 'تمديد الضمان', 'guarantee_extend', 'Allow extending guarantee expiry date'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'guarantee_extend');

INSERT INTO permissions (name, slug, description)
SELECT 'تخفيض الضمان', 'guarantee_reduce', 'Allow reducing guarantee amount'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'guarantee_reduce');

INSERT INTO permissions (name, slug, description)
SELECT 'إفراج الضمان', 'guarantee_release', 'Allow releasing/locking guarantee'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'guarantee_release');

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_data'
JOIN permissions p_new ON p_new.slug IN (
    'guarantee_save',
    'guarantee_extend',
    'guarantee_reduce',
    'guarantee_release'
)
LEFT JOIN role_permissions existing ON existing.role_id = rp.role_id AND existing.permission_id = p_new.id
WHERE existing.role_id IS NULL;
