-- Add explicit exception permission for batch operations outside default roles.
-- Default policy keeps batch surfaces for data_entry/developer via role-based guard.

INSERT INTO permissions (name, slug, description)
VALUES (
    'استثناء عمليات الدفعات الكاملة',
    'batch_full_operations_override',
    'Allow non-default roles to access full batch operation surfaces'
)
ON CONFLICT (slug) DO NOTHING;

