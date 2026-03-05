-- Add permission-based exception for full index filters and non-task-only scope.
-- Default behavior remains role-based (data_entry/developer).

INSERT INTO permissions (name, slug, description)
VALUES (
    'استثناء عرض الفلاتر الكاملة',
    'ui_full_filters_view',
    'Allow full index filters and bypass task-only scope clamp'
)
ON CONFLICT (slug) DO NOTHING;

-- Keep baseline behavior explicit for roles that already have full scope by design.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'ui_full_filters_view'
WHERE r.slug IN ('developer', 'data_entry')
ON CONFLICT DO NOTHING;
