-- Repair permission display names that were stored as question-marks (encoding damage).
-- This migration is idempotent and safe to run multiple times.

UPDATE permissions
SET name = 'تغيير لغة الواجهة'
WHERE slug = 'ui_change_language'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'تغيير اتجاه الواجهة'
WHERE slug = 'ui_change_direction'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'تغيير مظهر الواجهة'
WHERE slug = 'ui_change_theme'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'عرض التسلسل الزمني'
WHERE slug = 'timeline_view'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'عرض الملاحظات'
WHERE slug = 'notes_view'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'إضافة الملاحظات'
WHERE slug = 'notes_create'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'عرض المرفقات'
WHERE slug = 'attachments_view'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'رفع المرفقات'
WHERE slug = 'attachments_upload'
  AND (name LIKE '%?%' OR name = '');

UPDATE permissions
SET name = 'إدارة الأدوار'
WHERE slug = 'manage_roles'
  AND (name LIKE '%?%' OR name = '');
