-- PostgreSQL baseline schema for WBGL core tables.
-- Generated from current runtime schema and aligned to run before incremental migrations (000001+).
-- This migration is idempotent and intentionally excludes tables/columns created by later migrations.

CREATE TABLE IF NOT EXISTS roles (
    id BIGSERIAL NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT roles_pkey PRIMARY KEY (id),
    CONSTRAINT roles_slug_key UNIQUE (slug)
);


CREATE TABLE IF NOT EXISTS permissions (
    id BIGSERIAL NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT permissions_pkey PRIMARY KEY (id),
    CONSTRAINT permissions_slug_key UNIQUE (slug)
);


CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL NOT NULL,
    username TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    email TEXT,
    role_id INTEGER,
    last_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_pkey PRIMARY KEY (id),
    CONSTRAINT users_email_key UNIQUE (email),
    CONSTRAINT users_username_key UNIQUE (username),
    CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id)
);


CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id),
    CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS user_permissions (
    id BIGSERIAL NOT NULL,
    user_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    override_type TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_permissions_pkey PRIMARY KEY (id),
    CONSTRAINT user_permissions_user_id_permission_id_key UNIQUE (user_id, permission_id),
    CONSTRAINT user_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT user_permissions_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_permissions_override_type_check CHECK (override_type = ANY (ARRAY['allow'::text, 'deny'::text]))
);


CREATE TABLE IF NOT EXISTS suppliers (
    id BIGSERIAL NOT NULL,
    official_name TEXT NOT NULL,
    display_name TEXT,
    normalized_name TEXT NOT NULL,
    supplier_normalized_key TEXT,
    is_confirmed INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    english_name TEXT,
    CONSTRAINT suppliers_pkey PRIMARY KEY (id)
);


CREATE TABLE IF NOT EXISTS banks (
    id BIGSERIAL NOT NULL,
    arabic_name TEXT,
    english_name TEXT,
    short_name TEXT,
    created_at TEXT,
    updated_at TEXT,
    department TEXT,
    address_line1 TEXT,
    contact_email TEXT,
    normalized_name TEXT,
    CONSTRAINT banks_pkey PRIMARY KEY (id)
);


CREATE TABLE IF NOT EXISTS guarantees (
    id BIGSERIAL NOT NULL,
    guarantee_number TEXT NOT NULL,
    raw_data JSON NOT NULL,
    import_source TEXT NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    imported_by TEXT,
    normalized_supplier_name TEXT,
    test_batch_id TEXT,
    test_note TEXT,
    is_test_data INTEGER DEFAULT 0 NOT NULL,
    CONSTRAINT guarantees_pkey PRIMARY KEY (id),
    CONSTRAINT guarantees_guarantee_number_key UNIQUE (guarantee_number),
    CONSTRAINT chk_guarantees_is_test_data_domain CHECK (is_test_data = ANY (ARRAY[0, 1]))
);


CREATE TABLE IF NOT EXISTS guarantee_decisions (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    status TEXT DEFAULT 'pending'::text NOT NULL,
    is_locked BOOLEAN DEFAULT false,
    locked_reason TEXT,
    supplier_id INTEGER,
    bank_id INTEGER,
    decision_source TEXT DEFAULT 'manual'::text,
    confidence_score REAL,
    decided_at TIMESTAMP,
    decided_by TEXT,
    last_modified_at TIMESTAMP,
    last_modified_by TEXT,
    manual_override BOOLEAN DEFAULT false,
    active_action TEXT,
    active_action_set_at TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    workflow_step TEXT DEFAULT 'draft'::text,
    signatures_received INTEGER DEFAULT 0,
    CONSTRAINT guarantee_decisions_pkey PRIMARY KEY (id),
    CONSTRAINT guarantee_decisions_guarantee_id_key UNIQUE (guarantee_id),
    CONSTRAINT guarantee_decisions_bank_id_fkey FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    CONSTRAINT guarantee_decisions_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    CONSTRAINT guarantee_decisions_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT chk_guarantee_decisions_active_action_domain CHECK (active_action IS NULL OR active_action = ''::text OR (active_action = ANY (ARRAY['extension'::text, 'reduction'::text, 'release'::text]))),
    CONSTRAINT chk_guarantee_decisions_released_requires_lock CHECK (status <> 'released'::text OR COALESCE(is_locked, false) = true),
    CONSTRAINT chk_guarantee_decisions_signatures_non_negative CHECK (signatures_received >= 0),
    CONSTRAINT chk_guarantee_decisions_status_domain CHECK (status = ANY (ARRAY['pending'::text, 'ready'::text, 'released'::text])),
    CONSTRAINT chk_guarantee_decisions_workflow_step_domain CHECK (workflow_step = ANY (ARRAY['draft'::text, 'audited'::text, 'analyzed'::text, 'supervised'::text, 'approved'::text, 'signed'::text]))
);

CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_active_action ON public.guarantee_decisions USING btree (active_action);
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_bank_id ON public.guarantee_decisions USING btree (bank_id);
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_status_workflow_lock ON public.guarantee_decisions USING btree (status, workflow_step, is_locked, guarantee_id);
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_supplier_id ON public.guarantee_decisions USING btree (supplier_id);
CREATE INDEX IF NOT EXISTS idx_guarantee_decisions_workflow_step ON public.guarantee_decisions USING btree (workflow_step);

CREATE TABLE IF NOT EXISTS guarantee_history (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_subtype TEXT,
    snapshot_data TEXT,
    event_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT,
    letter_snapshot TEXT,
    CONSTRAINT guarantee_history_pkey PRIMARY KEY (id),
    CONSTRAINT guarantee_history_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_guarantee_history_event_subtype ON public.guarantee_history USING btree (event_subtype);
CREATE INDEX IF NOT EXISTS idx_guarantee_history_gid_created_id ON public.guarantee_history USING btree (guarantee_id, created_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS guarantee_notes (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_by TEXT DEFAULT 'system'::text,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    CONSTRAINT guarantee_notes_pkey PRIMARY KEY (id),
    CONSTRAINT guarantee_notes_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS guarantee_attachments (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER,
    file_type TEXT,
    uploaded_by TEXT DEFAULT 'system'::text,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT guarantee_attachments_pkey PRIMARY KEY (id),
    CONSTRAINT guarantee_attachments_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS guarantee_occurrences (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    batch_identifier VARCHAR(255) NOT NULL,
    batch_type VARCHAR(50) NOT NULL,
    occurred_at TIMESTAMP NOT NULL,
    raw_hash CHAR(64),
    CONSTRAINT guarantee_occurrences_pkey PRIMARY KEY (id),
    CONSTRAINT guarantee_occurrences_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
);

CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_batch_identifier ON public.guarantee_occurrences USING btree (batch_identifier);
CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_batch_occurred ON public.guarantee_occurrences USING btree (batch_identifier, occurred_at DESC, guarantee_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_guarantee_occurrences_guarantee_batch ON public.guarantee_occurrences USING btree (guarantee_id, batch_identifier);
CREATE INDEX IF NOT EXISTS idx_guarantee_occurrences_guarantee_id ON public.guarantee_occurrences USING btree (guarantee_id);

CREATE TABLE IF NOT EXISTS batch_metadata (
    id BIGSERIAL NOT NULL,
    import_source TEXT NOT NULL,
    batch_name TEXT,
    batch_notes TEXT,
    status TEXT DEFAULT 'active'::text,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT batch_metadata_pkey PRIMARY KEY (id),
    CONSTRAINT batch_metadata_import_source_key UNIQUE (import_source),
    CONSTRAINT batch_metadata_status_check CHECK (status = ANY (ARRAY['active'::text, 'completed'::text]))
);


CREATE TABLE IF NOT EXISTS batch_actions (
    id BIGSERIAL NOT NULL,
    batch_identifier TEXT NOT NULL,
    guarantee_id INTEGER NOT NULL,
    action_type TEXT NOT NULL,
    action_payload TEXT,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    action_by TEXT,
    CONSTRAINT batch_actions_pkey PRIMARY KEY (id),
    CONSTRAINT batch_actions_batch_identifier_guarantee_id_key UNIQUE (batch_identifier, guarantee_id),
    CONSTRAINT batch_actions_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS learning_confirmations (
    id BIGSERIAL NOT NULL,
    raw_supplier_name TEXT NOT NULL,
    normalized_supplier_name TEXT,
    supplier_id INTEGER NOT NULL,
    confidence INTEGER,
    matched_anchor TEXT,
    anchor_type TEXT,
    action TEXT,
    decision_time_seconds INTEGER,
    guarantee_id INTEGER,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    CONSTRAINT learning_confirmations_pkey PRIMARY KEY (id),
    CONSTRAINT learning_confirmations_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT learning_confirmations_action_check CHECK (action = ANY (ARRAY['confirm'::text, 'reject'::text, 'correction'::text]))
);


CREATE TABLE IF NOT EXISTS supplier_alternative_names (
    id BIGSERIAL NOT NULL,
    supplier_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,
    normalized_name TEXT,
    source TEXT,
    usage_count INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT supplier_alternative_names_pkey PRIMARY KEY (id),
    CONSTRAINT supplier_alternative_names_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS bank_alternative_names (
    id BIGSERIAL NOT NULL,
    bank_id INTEGER NOT NULL,
    alternative_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT bank_alternative_names_pkey PRIMARY KEY (id),
    CONSTRAINT bank_alternative_names_bank_id_fkey FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS supplier_learning_cache (
    id BIGSERIAL NOT NULL,
    normalized_input TEXT,
    supplier_id INTEGER NOT NULL,
    fuzzy_score REAL,
    source_weight INTEGER,
    usage_count INTEGER DEFAULT 0,
    block_count INTEGER DEFAULT 0,
    last_used_at TIMESTAMP,
    CONSTRAINT supplier_learning_cache_pkey PRIMARY KEY (id),
    CONSTRAINT supplier_learning_cache_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS supplier_decisions_log (
    id BIGSERIAL NOT NULL,
    guarantee_id INTEGER NOT NULL,
    raw_input TEXT NOT NULL,
    normalized_input TEXT NOT NULL,
    chosen_supplier_id INTEGER NOT NULL,
    chosen_supplier_name TEXT NOT NULL,
    decision_source TEXT NOT NULL,
    confidence_score REAL,
    was_top_suggestion BOOLEAN DEFAULT false,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT supplier_decisions_log_pkey PRIMARY KEY (id),
    CONSTRAINT supplier_decisions_log_chosen_supplier_id_fkey FOREIGN KEY (chosen_supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT supplier_decisions_log_guarantee_id_fkey FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE,
    CONSTRAINT supplier_decisions_log_decision_source_check CHECK (decision_source = ANY (ARRAY['manual'::text, 'ai_quick'::text, 'ai_assisted'::text, 'propagated'::text, 'auto_match'::text]))
);


-- Seed baseline roles.
INSERT INTO roles (name, slug, description) VALUES
    ('مطور النظام', 'developer', ''),
    ('مدخل بيانات', 'data_entry', ''),
    ('مدقق بيانات', 'data_auditor', ''),
    ('محلل ضمانات', 'analyst', ''),
    ('مشرف ضمانات', 'supervisor', ''),
    ('مدير معتمد', 'approver', ''),
    ('المفوض بالتوقيع', 'signatory', '')
ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description;

-- Seed baseline permissions.
INSERT INTO permissions (name, slug, description) VALUES
    ('استيراد إكسل', 'import_excel', ''),
    ('إدراج يدوي', 'manual_entry', ''),
    ('تصحيح بيانات', 'manage_data', ''),
    ('تدقيق البيانات', 'audit_data', ''),
    ('تحليل الضمانات', 'analyze_guarantee', ''),
    ('الإشراف على التحليل', 'supervise_analysis', ''),
    ('اعتماد القرار المالي', 'approve_decision', ''),
    ('توقيع الخطابات', 'sign_letters', ''),
    ('إدارة المستخدمين', 'manage_users', ''),
    ('إعادة فتح الدفعات', 'reopen_batch', 'Reopen closed batches with governance reason'),
    ('إعادة فتح الضمانات', 'reopen_guarantee', 'Reopen released guarantees under governed workflow'),
    ('تجاوز الطوارئ', 'break_glass_override', 'Emergency-only override with mandatory reason and ticket'),
    ('تغيير لغة الواجهة', 'ui_change_language', 'Allow user to change interface language preference'),
    ('تغيير اتجاه الواجهة', 'ui_change_direction', 'Allow user to change RTL/LTR direction override'),
    ('تغيير مظهر الواجهة', 'ui_change_theme', 'Allow user to change UI theme'),
    ('عرض التسلسل الزمني', 'timeline_view', 'Allow viewing timeline panel and historical snapshots'),
    ('عرض الملاحظات', 'notes_view', 'Allow viewing guarantee notes section'),
    ('إضافة الملاحظات', 'notes_create', 'Allow creating guarantee notes'),
    ('عرض المرفقات', 'attachments_view', 'Allow viewing guarantee attachments section'),
    ('رفع المرفقات', 'attachments_upload', 'Allow uploading guarantee attachments'),
    ('إدارة الأدوار', 'manage_roles', 'Create/update/delete roles and assign full role permissions'),
    ('حفظ تعديلات الضمان', 'guarantee_save', 'Allow saving guarantee decision updates and save-and-next mutations'),
    ('تمديد الضمان', 'guarantee_extend', 'Allow extending guarantee expiry date'),
    ('تخفيض الضمان', 'guarantee_reduce', 'Allow reducing guarantee amount'),
    ('إفراج الضمان', 'guarantee_release', 'Allow releasing/locking guarantee'),
    ('إدارة الموردين', 'supplier_manage', 'Allow create/update/delete/merge supplier reference entities'),
    ('إدارة البنوك', 'bank_manage', 'Allow create/update/delete bank reference entities'),
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
ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description;

-- Seed baseline role-permission matrix.
WITH seed(role_slug, permission_slug) AS (
    VALUES
    ('analyst', 'analyze_guarantee'),
    ('analyst', 'attachments_upload'),
    ('analyst', 'attachments_view'),
    ('analyst', 'navigation_view_batches'),
    ('analyst', 'navigation_view_statistics'),
    ('analyst', 'notes_create'),
    ('analyst', 'notes_view'),
    ('analyst', 'timeline_export'),
    ('analyst', 'timeline_view'),
    ('analyst', 'ui_change_direction'),
    ('analyst', 'ui_change_language'),
    ('analyst', 'ui_change_theme'),
    ('approver', 'approve_decision'),
    ('approver', 'attachments_upload'),
    ('approver', 'attachments_view'),
    ('approver', 'break_glass_override'),
    ('approver', 'navigation_view_batches'),
    ('approver', 'navigation_view_statistics'),
    ('approver', 'notes_create'),
    ('approver', 'notes_view'),
    ('approver', 'reopen_batch'),
    ('approver', 'reopen_guarantee'),
    ('approver', 'timeline_export'),
    ('approver', 'timeline_view'),
    ('approver', 'ui_change_direction'),
    ('approver', 'ui_change_language'),
    ('approver', 'ui_change_theme'),
    ('data_auditor', 'attachments_upload'),
    ('data_auditor', 'attachments_view'),
    ('data_auditor', 'audit_data'),
    ('data_auditor', 'navigation_view_batches'),
    ('data_auditor', 'navigation_view_statistics'),
    ('data_auditor', 'notes_create'),
    ('data_auditor', 'notes_view'),
    ('data_auditor', 'timeline_export'),
    ('data_auditor', 'timeline_view'),
    ('data_auditor', 'ui_change_direction'),
    ('data_auditor', 'ui_change_language'),
    ('data_auditor', 'ui_change_theme'),
    ('data_entry', 'attachments_upload'),
    ('data_entry', 'attachments_view'),
    ('data_entry', 'bank_manage'),
    ('data_entry', 'guarantee_extend'),
    ('data_entry', 'guarantee_reduce'),
    ('data_entry', 'guarantee_release'),
    ('data_entry', 'guarantee_save'),
    ('data_entry', 'import_banks'),
    ('data_entry', 'import_commit_batch'),
    ('data_entry', 'import_convert_to_real'),
    ('data_entry', 'import_email'),
    ('data_entry', 'import_excel'),
    ('data_entry', 'import_matching_overrides'),
    ('data_entry', 'import_paste'),
    ('data_entry', 'import_suppliers'),
    ('data_entry', 'manage_data'),
    ('data_entry', 'manual_entry'),
    ('data_entry', 'navigation_view_batches'),
    ('data_entry', 'navigation_view_statistics'),
    ('data_entry', 'notes_create'),
    ('data_entry', 'notes_view'),
    ('data_entry', 'supplier_manage'),
    ('data_entry', 'timeline_export'),
    ('data_entry', 'timeline_view'),
    ('data_entry', 'ui_change_direction'),
    ('data_entry', 'ui_change_language'),
    ('data_entry', 'ui_change_theme'),
    ('developer', 'alerts_view'),
    ('developer', 'analyze_guarantee'),
    ('developer', 'approve_decision'),
    ('developer', 'attachments_upload'),
    ('developer', 'attachments_view'),
    ('developer', 'audit_data'),
    ('developer', 'bank_manage'),
    ('developer', 'break_glass_override'),
    ('developer', 'guarantee_extend'),
    ('developer', 'guarantee_reduce'),
    ('developer', 'guarantee_release'),
    ('developer', 'guarantee_save'),
    ('developer', 'import_banks'),
    ('developer', 'import_commit_batch'),
    ('developer', 'import_convert_to_real'),
    ('developer', 'import_email'),
    ('developer', 'import_excel'),
    ('developer', 'import_matching_overrides'),
    ('developer', 'import_paste'),
    ('developer', 'import_suppliers'),
    ('developer', 'manage_data'),
    ('developer', 'manage_roles'),
    ('developer', 'manage_users'),
    ('developer', 'manual_entry'),
    ('developer', 'metrics_view'),
    ('developer', 'navigation_view_batches'),
    ('developer', 'navigation_view_maintenance'),
    ('developer', 'navigation_view_settings'),
    ('developer', 'navigation_view_statistics'),
    ('developer', 'navigation_view_users'),
    ('developer', 'notes_create'),
    ('developer', 'notes_view'),
    ('developer', 'reopen_batch'),
    ('developer', 'reopen_guarantee'),
    ('developer', 'roles_create'),
    ('developer', 'roles_delete'),
    ('developer', 'roles_update'),
    ('developer', 'settings_audit_view'),
    ('developer', 'sign_letters'),
    ('developer', 'supervise_analysis'),
    ('developer', 'supplier_manage'),
    ('developer', 'timeline_export'),
    ('developer', 'timeline_view'),
    ('developer', 'ui_change_direction'),
    ('developer', 'ui_change_language'),
    ('developer', 'ui_change_theme'),
    ('developer', 'users_create'),
    ('developer', 'users_delete'),
    ('developer', 'users_manage_overrides'),
    ('developer', 'users_update'),
    ('signatory', 'attachments_upload'),
    ('signatory', 'attachments_view'),
    ('signatory', 'navigation_view_batches'),
    ('signatory', 'navigation_view_statistics'),
    ('signatory', 'notes_create'),
    ('signatory', 'notes_view'),
    ('signatory', 'sign_letters'),
    ('signatory', 'timeline_export'),
    ('signatory', 'timeline_view'),
    ('signatory', 'ui_change_direction'),
    ('signatory', 'ui_change_language'),
    ('signatory', 'ui_change_theme'),
    ('supervisor', 'attachments_upload'),
    ('supervisor', 'attachments_view'),
    ('supervisor', 'navigation_view_batches'),
    ('supervisor', 'navigation_view_statistics'),
    ('supervisor', 'notes_create'),
    ('supervisor', 'notes_view'),
    ('supervisor', 'reopen_batch'),
    ('supervisor', 'reopen_guarantee'),
    ('supervisor', 'supervise_analysis'),
    ('supervisor', 'timeline_export'),
    ('supervisor', 'timeline_view'),
    ('supervisor', 'ui_change_direction'),
    ('supervisor', 'ui_change_language'),
    ('supervisor', 'ui_change_theme')
)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM seed s
JOIN roles r ON r.slug = s.role_slug
JOIN permissions p ON p.slug = s.permission_slug
ON CONFLICT DO NOTHING;
