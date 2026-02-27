# WBGL SQL Migrations

ضع ملفات المايغريشن بصيغة `.sql` داخل هذا المجلد بالترتيب الزمني:

`YYYYMMDD_HHMMSS_description.sql`

مثال:

`20260226_000001_add_history_indexes.sql`

## التشغيل

```bash
php maint/migration-status.php
php maint/migrate.php --dry-run
php maint/migrate.php
```

## القواعد

- لا تعدّل ملف migration بعد تطبيقه.
- أي تعديل لاحق يكون في migration جديد.
- المايغريشن يجب أن تكون idempotent قدر الإمكان (`IF EXISTS` / `IF NOT EXISTS`).
