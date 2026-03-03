# خط أساس سكربتات الصيانة المعتمدة — WBGL

تاريخ الإصدار: 2026-02-28  
الحالة: **Active / Mandatory**

الغرض:
- منع الانحراف التنفيذي الناتج عن سكربتات قديمة أو غير معتمدة.
- حصر التشغيل في مجموعة سكربتات صيانة واضحة ومعتمدة فقط.

---

## 1) القاعدة الحاكمة

1. تم تقاعد سكربتات `maint/` التشغيلية بالكامل بما فيها `maint/index.php`.
2. أي سكربت تشغيل/صيانة قديم يشير إلى `maint/*` يعتبر **غير معتمد**.
3. أي سكربت جديد يضاف فقط بعد تحديث هذه الوثيقة و`Docs/WBGL-FULL-TRACEABILITY-MATRIX-AR.md`.
4. لا توجد أي استثناءات تشغيلية داخل `maint/`.

---

## 2) السكربتات المعتمدة حاليًا (Post-maint)

- `app/Scripts/notify-expiry.php`
- `app/Scripts/sequential-execution.php`

---

## 3) السكربتات المتقاعدة (Retired / Removed)

- `maint/*` (جميع السكربتات بما فيها `maint/index.php`)
- `maint/run-execution-loop.php`
- `maint/archive/bootstrap-pgsql-from-sqlite.php`
- `maint/archive/verify-pgsql-schema-parity.php`
- `maint/schedule.php`
- `maint/schedule-status.php`
- `maint/schedule-dead-letters.php`
- `maint/migrate.php`
- `maint/migration-status.php`
- `maint/create_rbac_tables.php`
- `maint/seed_permissions.php`
- `maint/seed_admin.php`
- `maint/verify_rbac.php`
- `maint/update_workflow_db.php`
- `maint/db-cutover-check.php`
- `maint/db-cutover-fingerprint.php`
- `maint/db-driver-status.php`
- `maint/check-migration-portability.php`
- `maint/history-snapshot-audit.php`
- `maint/history-hybrid-backfill.php`
- `maint/reset_for_test.php`
- `maint/list_users.php`
- `maint/backfill-occurrences-from-import-source.php`
- `maint/notify-expiry.php`

ملاحظة:
- أي إشارة لهذه السكربتات في تقارير قديمة تعتبر **تاريخية** وليست تعليمات تشغيل حالية.

---

## 4) آلية المراجعة

1. مراجعة دورية للسكربتات كل دورة تنفيذ.
2. منع أي تشغيل لأي مسار `maint/*` عبر حوكمة الفريق.
3. أي انحراف يوثق كـ Incident حوكمي ويغلق قبل متابعة المرحلة.
