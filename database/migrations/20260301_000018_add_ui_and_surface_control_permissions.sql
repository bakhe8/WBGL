-- UI and surface behavior controls (view + interaction)
INSERT OR IGNORE INTO permissions (name, slug, description)
VALUES
    ('تغيير لغة الواجهة', 'ui_change_language', 'Allow user to change interface language preference'),
    ('تغيير اتجاه الواجهة', 'ui_change_direction', 'Allow user to change RTL/LTR direction override'),
    ('تغيير مظهر الواجهة', 'ui_change_theme', 'Allow user to change UI theme'),
    ('عرض التسلسل الزمني', 'timeline_view', 'Allow viewing timeline panel and historical snapshots'),
    ('عرض الملاحظات', 'notes_view', 'Allow viewing guarantee notes section'),
    ('إضافة الملاحظات', 'notes_create', 'Allow creating guarantee notes'),
    ('عرض المرفقات', 'attachments_view', 'Allow viewing guarantee attachments section'),
    ('رفع المرفقات', 'attachments_upload', 'Allow uploading guarantee attachments');

-- Keep current behavior unchanged by granting these controls to all current operational roles.
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'ui_change_language',
    'ui_change_direction',
    'ui_change_theme',
    'timeline_view',
    'notes_view',
    'notes_create',
    'attachments_view',
    'attachments_upload'
)
WHERE r.slug IN (
    'developer',
    'data_entry',
    'data_auditor',
    'analyst',
    'supervisor',
    'approver',
    'signatory'
);
