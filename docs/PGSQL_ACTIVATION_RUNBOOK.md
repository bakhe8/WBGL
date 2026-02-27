# WBGL PostgreSQL Activation Runbook

## الهدف

تجهيز تفعيل PostgreSQL عمليًا بطريقة آمنة وقابلة للرجوع، مع توثيق حالة ما بعد التفعيل الفعلي.

## الحالة الحالية

- تفعيل PostgreSQL تم بنجاح.
- `db_cutover.active_driver=pgsql`
- `stage_gates.gate_d_pg_activation_passed=true`

## أوامر التمهيد (Rehearsal)

```bash
php maint/archive/verify-pgsql-schema-parity.php --json
php maint/archive/bootstrap-pgsql-from-sqlite.php --json
php maint/pgsql-activation-rehearsal.php --skip-connectivity
php maint/pgsql-activation-rehearsal.php
php maint/pgsql-activation-rehearsal.php --apply-migrations
```

خيارات مخصصة:

```bash
php maint/pgsql-activation-rehearsal.php \
  --host=127.0.0.1 \
  --port=5432 \
  --database=wbgl \
  --user=wbgl_user \
  --password=secret \
  --sslmode=prefer \
  --apply-migrations
```

## كيف نتحقق أن Schema صحيحة وكاملة

التحقق يكون آليًا عبر:

1. `verify-pgsql-schema-parity.php` لمقارنة:
- عدد الجداول (SQLite vs PostgreSQL)
- الأعمدة الناقصة
- الفروقات النوعية (type family)
- حالة migrations (pending = 0)
- فرق عدد الصفوف (لرصد مشاكل جودة البيانات القديمة)

2. معيار النجاح الإلزامي للـ schema:
- `missing_tables_in_pgsql = 0`
- `missing_columns_in_pgsql = 0`
- `migration_pending_in_pgsql = 0`

ملاحظة: `row_count_mismatches` لا يعني خلل schema، بل يشير غالبًا إلى بيانات تاريخية غير متسقة في SQLite (orphans/type anomalies).

## ماذا يفحص السكربت

1. توفر امتدادات `pdo_pgsql` و `pgsql`.
2. قابلية المهاجرات الحالية عبر `check-migration-portability`.
3. الاتصال الفعلي بالقاعدة الهدف عبر `db-driver-status`.
4. تشغيل `migrate.php` (dry-run أو apply).
5. التحقق من `migration-status`.
6. توليد `db-cutover-fingerprint` على PostgreSQL.

## ملفات التقرير

- تقرير زمني:
  - `storage/database/cutover/pgsql_activation_rehearsal_<timestamp>.json`
- مؤشر آخر تشغيل:
  - `storage/database/cutover/pgsql_activation_rehearsal_latest.json`

المؤشر الحاسم داخل التقرير:

- `summary.ready_for_pg_activation=true`

## شروط إغلاق Gate-D PG Activation (مرجع تحقق)

لا تُغلق هذه البوابة إلا عند تحقق الشرطين معًا:

1. `summary.ready_for_pg_activation=true` في أحدث تقرير rehearsal.
2. تحويل التشغيل الفعلي إلى PostgreSQL (`DB_DRIVER=pgsql`) مع نجاح `php maint/run-execution-loop.php` وظهور:
   - `stage_gates.gate_d_pg_activation_passed=true`

الحالة الحالية: **مغلق (Pass)**.

## خطة الرجوع السريع

1. إعادة `DB_DRIVER=sqlite` من الإعدادات.
2. إعادة تشغيل التطبيق.
3. التحقق عبر:
   - `php maint/db-driver-status.php`
   - `php maint/db-cutover-check.php`
4. مراجعة تقرير rehearsal الأخير لمعرفة سبب الفشل قبل أي محاولة جديدة.
