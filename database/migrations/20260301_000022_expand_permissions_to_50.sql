-- Expand permissions matrix from 27 to 50 (add 23 planned permissions).
-- PostgreSQL-only migration.

WITH seed(name, slug, description) AS (
    VALUES
        ('عرض الدفعات في الملاحة', 'navigation_view_batches', 'Allow seeing/opening batches navigation entry and page'),
        ('عرض الإحصائيات في الملاحة', 'navigation_view_statistics', 'Allow seeing/opening statistics navigation entry and page'),
        ('عرض الإعدادات في الملاحة', 'navigation_view_settings', 'Allow seeing/opening settings navigation entry and page'),
        ('عرض المستخدمين في الملاحة', 'navigation_view_users', 'Allow seeing/opening users navigation entry and page'),
        ('عرض الصيانة في الملاحة', 'navigation_view_maintenance', 'Allow seeing/opening maintenance navigation entry and page'),
        ('عرض مؤشرات النظام', 'metrics_view', 'Allow viewing system metrics endpoints and dashboards'),
        ('عرض تنبيهات النظام', 'alerts_view', 'Allow viewing system alerts endpoints and dashboards'),
        ('عرض سجل إعدادات النظام', 'settings_audit_view', 'Allow viewing settings audit logs and related endpoints'),
        ('إنشاء مستخدمين', 'users_create', 'Allow creating users'),
        ('تحديث المستخدمين', 'users_update', 'Allow updating users'),
        ('حذف المستخدمين', 'users_delete', 'Allow deleting users'),
        ('إدارة تخصيص صلاحيات المستخدم', 'users_manage_overrides', 'Allow managing per-user permission overrides'),
        ('إنشاء الأدوار', 'roles_create', 'Allow creating roles'),
        ('تحديث الأدوار', 'roles_update', 'Allow updating roles'),
        ('حذف الأدوار', 'roles_delete', 'Allow deleting roles'),
        ('استيراد عبر اللصق', 'import_paste', 'Allow parsing/importing pasted text payloads'),
        ('استيراد عبر البريد', 'import_email', 'Allow importing guarantees from email channel'),
        ('استيراد الموردين', 'import_suppliers', 'Allow importing supplier reference data'),
        ('استيراد البنوك', 'import_banks', 'Allow importing bank reference data'),
        ('استيراد قواعد التطابق', 'import_matching_overrides', 'Allow importing matching-override rules'),
        ('اعتماد دفعة المسودة', 'import_commit_batch', 'Allow committing draft import batches'),
        ('تحويل دفعة إلى حقيقية', 'import_convert_to_real', 'Allow converting simulated/draft batches to real'),
        ('تصدير التسلسل الزمني', 'timeline_export', 'Allow exporting/printing timeline events')
)
INSERT INTO permissions (name, slug, description)
SELECT s.name, s.slug, s.description
FROM seed s
ON CONFLICT (slug) DO NOTHING;

-- Navigation basics (batches/statistics) granted to all existing roles.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('navigation_view_batches', 'navigation_view_statistics')
ON CONFLICT DO NOTHING;

-- Permissions that fall back to manage_users.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_users'
JOIN permissions p_new ON p_new.slug IN (
    'navigation_view_settings',
    'navigation_view_users',
    'navigation_view_maintenance',
    'metrics_view',
    'alerts_view',
    'settings_audit_view',
    'users_create',
    'users_update',
    'users_delete',
    'users_manage_overrides'
)
ON CONFLICT DO NOTHING;

-- Permissions that fall back to manage_roles.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_roles'
JOIN permissions p_new ON p_new.slug IN ('roles_create', 'roles_update', 'roles_delete')
ON CONFLICT DO NOTHING;

-- Permissions that fall back to import_excel.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'import_excel'
JOIN permissions p_new ON p_new.slug IN ('import_paste', 'import_email', 'import_suppliers', 'import_banks')
ON CONFLICT DO NOTHING;

-- Permissions that fall back to manage_data.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'manage_data'
JOIN permissions p_new ON p_new.slug IN ('import_matching_overrides', 'import_commit_batch', 'import_convert_to_real')
ON CONFLICT DO NOTHING;

-- Permissions that fall back to timeline_view.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p_new.id
FROM role_permissions rp
JOIN permissions p_current ON p_current.id = rp.permission_id AND p_current.slug = 'timeline_view'
JOIN permissions p_new ON p_new.slug IN ('timeline_export')
ON CONFLICT DO NOTHING;
