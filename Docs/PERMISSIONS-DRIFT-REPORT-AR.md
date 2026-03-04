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
- `WARN` عند وجود:
  - صلاحيات في DB غير مرجعية في الكود.
  - أدوار بدون أي صلاحيات.

## الربط مع CI
تم ربط التقرير داخل `.github/workflows/ci.yml` ضمن مهمة `php-tests` مع رفع Artifact باسم:
- `wbgl-governance-artifacts`
