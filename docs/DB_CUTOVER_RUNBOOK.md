# WBGL DB Cutover Runbook

## الهدف

توحيد خطوات التحقق والتشغيل بعد تفعيل PostgreSQL كمسار تشغيل وحيد.

## الحالة الحالية

- المحرك التشغيلي الفعلي: `pgsql`
- الجاهزية: `db_cutover.ready=true` و `db_cutover.production_ready=true`
- حالة البوابة: `stage_gates.gate_d_pg_activation_passed=true`

## أوامر التشغيل والتحقق الأساسية

```bash
php maint/db-driver-status.php
php maint/db-cutover-check.php --json
php maint/migration-status.php
php maint/check-migration-portability.php
php maint/db-cutover-fingerprint.php
php maint/archive/verify-pgsql-schema-parity.php --json --host=127.0.0.1 --port=5432 --database=wbgl --user=wbgl_user --password=***
php maint/pgsql-activation-rehearsal.php --json --host=127.0.0.1 --port=5432 --database=wbgl --user=wbgl_user --password=***
```

## مخرجات النجاح المتوقعة

1. `db-driver-status`:
- يظهر `Driver: pgsql`
- `Connectivity: OK`

2. `db-cutover-check`:
- `ready=true`
- `pending_migrations_zero=true`

3. `verify-pgsql-schema-parity`:
- `schema_parity_ok=true`
- `migration_parity_ok=true`
- `runtime_ready=true`

4. `pgsql-activation-rehearsal`:
- `summary.ready_for_pg_activation=true`

## ملاحظة مهمة (Parity Waiver Governance)

فروقات المصدر التاريخية المعروفة (orphans / drift / type anomalies) يتم إدارتها عبر:

- `Docs/PGSQL_PARITY_WAIVERS.json`

ويجب تحديثه فقط مع توثيق سبب الأعمال/البيانات وتوقيعه ضمن مراجعة الكود.

## شروط الاستمرار على PostgreSQL (Post-Cutover)

1. `migration_pending_in_pgsql = 0`.
2. نجاح Unit + Integration + E2E.
3. `WBGL_EXECUTION_LOOP_STATUS.json` محدث بعد أي تعديل قاعدة بيانات.
4. وجود نسخة backup حديثة وصالحة + اختبار restore دوري.

## خطة الاستعادة السريعة (PostgreSQL)

1. اختيار آخر نسخة `wbgl_pg_YYYYMMDD_HHMMSS.sql`.
2. الاستعادة عبر `psql` على قاعدة `wbgl`.
3. تنفيذ:
   - `php maint/db-driver-status.php`
   - `php maint/migration-status.php`
   - `php maint/db-cutover-check.php --json`
4. مراجعة آخر تقارير `fingerprint/parity/rehearsal` قبل إعادة فتح النظام.
