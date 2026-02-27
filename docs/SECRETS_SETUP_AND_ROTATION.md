# WBGL Secrets Setup And Rotation

تاريخ: 2026-02-27

## 1) الهدف
منع تخزين كلمات المرور الحساسة داخل الملفات المتتبعة في Git.

## 2) ما تم اعتماده
1. إزالة `DB_PASS` من `WBGL/storage/settings.json`.
2. اعتماد ملف محلي غير متتبع:
   - `WBGL/storage/settings.local.json`
3. اعتماد GitHub Secret لخطوط CI/CD:
   - `WBGL_CI_DB_PASSWORD`

## 3) إعداد البيئة المحلية
أنشئ الملف:

`WBGL/storage/settings.local.json`

بالمحتوى:

```json
{
  "DB_PASS": "YOUR_REAL_PASSWORD"
}
```

ملاحظة: هذا الملف غير متتبع عبر `.gitignore`.

## 4) إعداد GitHub Secrets
في إعدادات المستودع (Settings -> Secrets and variables -> Actions):

1. أضف:
   - `WBGL_CI_DB_PASSWORD`
2. استخدم قيمة قوية مخصصة لبيئة CI.

الـ workflows تستخدم هذا السر تلقائيًا، مع fallback development-only إذا لم يكن السر معرفًا.

## 5) تدوير كلمة المرور (Rotation)
يُنفذ دوريًا أو فور أي اشتباه تسريب:

```sql
ALTER ROLE wbgl_user WITH PASSWORD 'NEW_STRONG_PASSWORD';
```

ثم:
1. تحديث `WBGL/storage/settings.local.json` محليًا.
2. تحديث `WBGL_CI_DB_PASSWORD` في GitHub Secrets.
3. تشغيل:
   - `php maint/db-driver-status.php --json`
   - `php maint/db-cutover-check.php --json`

## 6) ضوابط إلزامية
1. يمنع إدراج أي قيمة سرية مباشرة داخل:
   - `storage/settings.json`
   - `.github/workflows/*.yml`
2. أي PR يحتوي أسرارًا صريحة يُرفض مباشرة.
