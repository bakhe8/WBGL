# WBGL SQL Migrations

ضع ملفات المايغريشن بصيغة `.sql` داخل هذا المجلد بالترتيب الزمني:

`YYYYMMDD_HHMMSS_description.sql`

مثال:

`20260226_000001_add_history_indexes.sql`

## التشغيل

```bash
php app/Scripts/migration-status.php
php app/Scripts/migrate.php --dry-run
php app/Scripts/migrate.php
php app/Scripts/rehearse-migrations.php
php app/Scripts/rehearse-migrations.php --with-integration
```

## Rehearsal آمن بدون CREATEDB لكل تشغيل

للتشغيل المتكرر على قاعدة فارغة دون منح `CREATEDB` لمستخدم التطبيق:

1. إنشاء قاعدة rehearsal مرة واحدة فقط بواسطة DBA/superuser:
   - `CREATE DATABASE wbgl_rehearsal OWNER wbgl_user;`
   - `GRANT ALL PRIVILEGES ON DATABASE wbgl_rehearsal TO wbgl_user;`
2. بعد ذلك شغّل:
   - `php app/Scripts/rehearse-migrations.php`
   - السكربت يعيد تهيئة `public schema` ثم يطبق كل المايغريشن.

## القواعد

- لا تعدّل ملف migration بعد تطبيقه.
- أي تعديل لاحق يكون في migration جديد.
- المايغريشن يجب أن تكون idempotent قدر الإمكان (`IF EXISTS` / `IF NOT EXISTS`).
