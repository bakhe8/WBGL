# WBGL DR Drill Procedure (AR)

## 1) الهدف
اختبار جاهزية الاستعادة (Disaster Recovery) عمليًا والتأكد أن النظام يمكن:
- إعادة بنائه من الصفر.
- استعادة قاعدة البيانات.
- تشغيل الهجرات.
- إرجاع الخدمة مع سلامة أرقام وبيانات.

## 2) نطاق الاختبار
- البيئة: PostgreSQL + تطبيق WBGL.
- الاختبار يشمل:
  - Restore قاعدة البيانات
  - Migration rehearsal
  - سلامة الحوكمة
  - تحقق وظيفي أساسي

## 3) تكرار التنفيذ
- ربع سنويًا كحد أدنى.
- أو مباشرة بعد أي تغيير كبير في schema أو workflow.

## 4) مدخلات DR Drill
- نسخة backup مؤكدة الصلاحية.
- إصدار الكود المستهدف.
- أسرار البيئة (`WBGL_DB_*`).

## 5) خطوات التنفيذ

### الخطوة A: تجهيز بيئة نظيفة
```bash
php app/Scripts/migrate.php --dry-run
php app/Scripts/migrate.php
php app/Scripts/migration-status.php
```

### الخطوة B: استعادة البيانات (حسب آلية المؤسسة)
- نفّذ Restore من النسخة المعتمدة.
- تحقق من الاتصال والعدد الأساسي للسجلات.

### الخطوة C: فحص ما بعد الاستعادة
```bash
php app/Scripts/data-integrity-check.php --strict-warn --output-json=storage/logs/data-integrity-report.json --output-md=storage/logs/data-integrity-report.md
php app/Scripts/permissions-drift-report.php --strict --output-json=storage/logs/permissions-drift-report.json --output-md=storage/logs/permissions-drift-report.md
php app/Scripts/governance-summary.php --drift=storage/logs/permissions-drift-report.json --integrity=storage/logs/data-integrity-report.json --output-md=storage/logs/governance-summary.md
php app/Scripts/release-gate.php --integrity=storage/logs/data-integrity-report.json --drift=storage/logs/permissions-drift-report.json
```

### الخطوة D: اختبار وظيفي سريع
```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
php app/Scripts/seed-e2e-fixtures.php
npm run test:e2e
```

## 6) معايير النجاح (Pass Criteria)
1. الاستعادة تمت دون تدخل يدوي في الجداول الحرجة.
2. `migration-status` لا يظهر أي pending.
3. تقارير الحوكمة في حالة `pass`.
4. اختبارات Unit/Integration/E2E ناجحة.
5. لا توجد فروقات غير مفسرة في العدادات الحرجة (real/test/open/released/actionable).

## 7) معايير الفشل (Fail Criteria)
- وجود orphan/contract drift في التقارير.
- وجود تسرب اختباري إلى بيانات حقيقية.
- فشل انتقالات workflow المحمية.
- تعذر تشغيل الخدمة دون إصلاح SQL يدوي.

## 8) نموذج تقرير DR (إلزامي)
- تاريخ التنفيذ.
- نسخة backup المستخدمة.
- زمن الاستعادة (RTO).
- مدى فقد البيانات (RPO).
- نتائج الاختبارات والتقارير.
- الثغرات المكتشفة وخطة الإغلاق.

## 9) إجراءات ما بعد DR Drill
1. فتح تذاكر إغلاق للثغرات.
2. تحديث Runbook/الوثائق التشغيلية.
3. إعادة التجربة بعد الإصلاح إذا وُجد فشل حرج.
