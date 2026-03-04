# تقرير سلامة البيانات (Data Integrity)

## الهدف
فحص invariants الحرجة في قاعدة البيانات قبل الدمج للتأكد من:
1. عدم وجود صفوف يتيمة في الجداول التشغيلية الحساسة.
2. التزام الحقول الدومينية (`status`, `workflow_step`, `active_action`, ...).
3. اكتشاف المخاطر التحذيرية مبكرًا (مثل السجلات الناقصة في حقول العرض/التتبع).

## أمر التشغيل
```powershell
php app/Scripts/data-integrity-check.php
```

أو مع إخراج Artifact:
```powershell
php app/Scripts/data-integrity-check.php --output-json=storage/logs/data-integrity-report.json --output-md=storage/logs/data-integrity-report.md
```

## وضع التحذيرات الصارم
```powershell
php app/Scripts/data-integrity-check.php --strict-warn
```
- عند تفعيل `--strict-warn` يتم اعتبار التحذيرات فشلًا.

## مخرجات الأداة
- `storage/logs/data-integrity-report.json`
- `storage/logs/data-integrity-report.md`

## الربط مع CI
تم ربط الفحص في `.github/workflows/ci.yml` ضمن `php-tests`، ويتم رفع النتائج ضمن Artifact:
- `wbgl-governance-artifacts`
