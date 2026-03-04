# تقرير انحراف الصلاحيات (Permissions Drift)

## الهدف
كشف أي انحراف بين:
1. صلاحيات النظام المتوقعة من الكود (`PermissionCapabilityCatalog` + `UiPolicy` + `ApiPolicyMatrix`).
2. صلاحيات قاعدة البيانات الفعلية (`permissions`, `role_permissions`, `roles`).

## أمر التشغيل
```powershell
php app/Scripts/permissions-drift-report.php
```

أو مع مسارات إخراج مخصصة:
```powershell
php app/Scripts/permissions-drift-report.php --output-json=storage/logs/permissions-drift-report.json --output-md=storage/logs/permissions-drift-report.md
```

## مخرجات الأداة
- `storage/logs/permissions-drift-report.json`
- `storage/logs/permissions-drift-report.md`

## قواعد الحكم
- `FAIL` عند وجود:
  - صلاحيات متوقعة مفقودة في قاعدة البيانات.
  - Slugs مكررة في جدول `permissions`.
  - صفوف يتيمة في `role_permissions`.
  - انحراف في عقد صلاحيات المسارات الحرجة (Critical Endpoint Contract) داخل `ApiPolicyMatrix`.
- `WARN` عند وجود:
  - صلاحيات في DB غير مرجعية في الكود.
  - أدوار بدون أي صلاحيات.

## عقد المسارات الحرجة
التقرير يتضمن قسمًا صريحًا باسم `critical_endpoint_contract` للتحقق من ثبات صلاحيات مسارات تشغيل حرجة مثل:
- `api/save-and-next.php`
- `api/update-guarantee.php`
- `api/extend.php`
- `api/reduce.php`
- `api/release.php`
- `api/settings.php`
- `api/users/list.php`
- `api/roles/create.php`

## الربط مع CI
تم ربط التقرير داخل `.github/workflows/ci.yml` ضمن مهمة `php-tests` مع رفع Artifact باسم:
- `wbgl-governance-artifacts`
