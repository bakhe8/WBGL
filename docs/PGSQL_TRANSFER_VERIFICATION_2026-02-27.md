# تقرير تحقق نقل SQLite -> PostgreSQL (WBGL)

تاريخ التحديث: 2026-02-27 04:08  
النطاق: اكتمال النقل + سلامة البيانات + صلاحية الاستخدام

## 1) تحقق البنية (Schema/Migrations)

الأدوات:

- `php maint/archive/verify-pgsql-schema-parity.php --json --host=127.0.0.1 --port=5432 --db=wbgl --user=wbgl_user --password=***`
- `php maint/migrate.php` (على PostgreSQL)

النتيجة الحالية:

- `tables_in_sqlite = 34`
- `tables_in_pgsql = 34`
- `missing_tables_in_pgsql = 0`
- `missing_columns_in_pgsql = 0`
- `migration_pending_in_pgsql = 0`
- `schema_parity_ok = true`
- `migration_parity_ok = true`

الحكم: **PASS** (البنية والمهاجرات مكتملة).

## 2) تحقق البيانات (Data Completeness)

نتيجة parity الحالية:

- `row_count_mismatches = 0` (غير متجاوزة)
- `row_count_mismatches_waived = 5`
- `type_mismatches = 0` (غير متجاوزة)
- `type_mismatches_waived = 2`
- `waiver_active = true`

تم تجاوز الفروقات المعروفة رسميًا عبر:

- `WBGL/docs/PGSQL_PARITY_WAIVERS.json`
- `Docs/PGSQL_PARITY_WAIVERS.json`

الجداول المتجاوزة حاليًا:

1. `audit_trail_events` (Operational drift)
2. `guarantee_decisions` (SQLite orphan rows)
3. `guarantee_history` (SQLite orphan rows)
4. `learning_confirmations` (legacy invalid type rows)
5. `notifications` (operational queue drift)

الحكم: **PASS مع Waiver مُدار**  
الفروقات المعروفة لا تعطل الجاهزية التشغيلية وتظهر بوضوح كتجاوزات موثقة.

## 3) تحقق السلامة المرجعية داخل PostgreSQL

فحوصات مباشرة:

- orphan checks على `guarantee_decisions` و`guarantee_history`: كلاهما `0`.
- `schema_migrations.pending = 0`.

الحكم: **PASS** (البيانات داخل PostgreSQL سليمة مرجعيًا).

## 4) تحقق صلاحية الاستخدام الفعلي (Runtime Usability)

الأدوات:

- `php maint/pgsql-activation-rehearsal.php --json --host=127.0.0.1 --port=5432 --db=wbgl --user=wbgl_user --password=***`
- `php maint/db-cutover-check.php --json` (على PostgreSQL)

النتيجة:

- `ready_for_pg_activation = true`
- `db-cutover.ready = true`
- `connection_ok = true`
- `pending_migrations_zero = true`
- `verify-parity.runtime_ready = true` (بعد تطبيق الـ waiver)

الحكم: **PASS** (جاهزية التشغيل على PostgreSQL متحققة حاليًا).

## 5) الحكم النهائي

- هل النقل تم بشكل سليم تمامًا بما في ذلك البيانات وصلاحية الاستخدام؟ **نعم تشغيليًا، مع فروقات معروفة مُدارة عبر Waiver**.
- الحالة الدقيقة:
1. **Schema/Migrations**: مكتملة.
2. **Data integrity داخل PostgreSQL**: سليمة.
3. **Data completeness مقابل SQLite**: الفروقات المتبقية موثقة ومُدارة بآلية Waiver.
4. **Application runtime on PostgreSQL**: جاهز.

## 6) المخرجات المرجعية

- parity:
  - `WBGL/storage/database/cutover/pgsql_schema_parity_latest.json`
  - `Docs/pgsql_schema_parity_latest.json`
- rehearsal:
  - `WBGL/storage/database/cutover/pgsql_activation_rehearsal_latest.json`
- cutover check:
  - ناتج `php maint/db-cutover-check.php --json`
