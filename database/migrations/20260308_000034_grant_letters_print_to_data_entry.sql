-- Enable signed-stage printing for data-entry role.
-- This keeps print gated by workflow (signed + action selected + signatures_received)
-- while allowing the dedicated print button to appear for operational users.

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'letters_print'
WHERE r.slug = 'data_entry'
ON CONFLICT DO NOTHING;
