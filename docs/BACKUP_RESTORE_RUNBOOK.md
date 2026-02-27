# WBGL Backup/Restore Runbook (PostgreSQL Baseline)

## الهدف

توحيد النسخ الاحتياطي والاسترجاع لقاعدة PostgreSQL الحالية، مع تحقق hash واحتفاظ دوري.

## السكربتات المعتمدة

1. `maint/backup-db.php`
2. `maint/db-cutover-check.php`
3. `maint/check-migration-portability.php`
4. `maint/db-cutover-fingerprint.php`

## النسخ الاحتياطي

### أمر قياسي

```bash
php maint/backup-db.php --retention-days=30
```

### الناتج

- إنشاء ملف backup جديد داخل:
`storage/database/backups/`
- التسمية:
`wbgl_pg_YYYYMMDD_HHMMSS.sql`
- تسجيل العملية في:
`storage/database/backups/BACKUP_LOG.txt`
- تحقق SHA-256 لملف النسخة.

## الاسترجاع

الاسترجاع يتم عبر `psql` من ملف النسخة:

```bash
set PGPASSWORD=***
"C:\PostgreSQL\16\bin\psql.exe" -h 127.0.0.1 -U wbgl_user -d wbgl -f "storage/database/backups/wbgl_pg_YYYYMMDD_HHMMSS.sql"
```

ملاحظات:
1. يفضّل الاسترجاع على بيئة اختبار أولًا.
2. في بيئة الإنتاج: يجب وجود نافذة صيانة وخطة rollback معتمدة.

## فحص ما بعد الاسترجاع

```bash
php maint/db-driver-status.php
php maint/migration-status.php
php maint/db-cutover-check.php
php maint/check-migration-portability.php
php maint/db-cutover-fingerprint.php
```

يجب أن تكون:

1. `Connectivity: OK`
2. `Pending migrations: 0`
3. `Overall ready: yes`

## الجدولة التشغيلية المقترحة

1. تنفيذ backup يومي.
2. تنفيذ restore drill أسبوعي على بيئة اختبار.
3. الاحتفاظ بالنسخ 30 يومًا كحد أدنى.
4. مراجعة سجل النسخ أسبوعيًا (`BACKUP_LOG.txt`).

## تحذيرات

1. يمنع تشغيل restore مباشرة على الإنتاج بدون نافذة صيانة معتمدة.
2. لا يتم حذف أي backup يدويًا قبل تحقق retention policy.
3. أي فشل backup يجب أن يسجل كتذكرة تشغيلية عاجلة.
