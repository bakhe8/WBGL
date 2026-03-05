# WBGL Operations Runbook (AR)

## 1) الغرض
هذا الدليل هو مرجع التشغيل اليومي لفريق النظام لضمان:
- استقرار البيئة.
- دقة البيانات.
- ثبات مسار الصلاحيات والحوكمة.
- جاهزية الإصدار.

## 2) المتطلبات التشغيلية
- PHP 8.3
- PostgreSQL 16
- Composer
- Node.js 20 + Playwright (لـ E2E)
- متغيرات بيئة قاعدة البيانات (`WBGL_DB_*`)

## 3) دورة التشغيل اليومية
1. تحديث قاعدة البيانات:
```bash
php app/Scripts/migrate.php
php app/Scripts/migration-status.php
```
2. فحص سلامة البيانات والصلاحيات:
```bash
php app/Scripts/data-integrity-check.php --output-json=storage/logs/data-integrity-report.json --output-md=storage/logs/data-integrity-report.md
php app/Scripts/permissions-drift-report.php --output-json=storage/logs/permissions-drift-report.json --output-md=storage/logs/permissions-drift-report.md
php app/Scripts/governance-summary.php --drift=storage/logs/permissions-drift-report.json --integrity=storage/logs/data-integrity-report.json --output-md=storage/logs/governance-summary.md
php app/Scripts/release-gate.php --integrity=storage/logs/data-integrity-report.json --drift=storage/logs/permissions-drift-report.json
```
3. تشغيل الاختبارات:
```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
```
4. تشغيل E2E (مع بذور بيانات ثابتة):
```bash
php app/Scripts/seed-e2e-fixtures.php
npm ci
npx playwright install chromium
npm run test:e2e
```

## 4) قائمة فحص قبل أي Release
1. لا توجد migrations معلقة (`Pending: 0`).
2. `data-integrity-report.json` حالته `pass`.
3. `permissions-drift-report.json` حالته `pass`.
4. جميع اختبارات Unit/Integration ناجحة.
5. E2E smoke ناجح بعد `seed-e2e-fixtures.php`.
6. لا يوجد إدخال Actor عام (`المستخدم`/`user`/`web_user`) في السجلات الجديدة.

## 5) الاستجابة للحوادث (Operational Incidents)
1. تحديد نوع الحادث:
- صلاحيات/وصول
- تباين أرقام
- خلل تدفق مرحلة
- خلل استيراد/دفعات
2. جمع الأدلة:
- `storage/logs/data-integrity-report.md`
- `storage/logs/permissions-drift-report.md`
- أحداث `Timeline` للسجل المتأثر
3. تطبيق عزل سريع:
- إيقاف أي عملية batch متسببة
- منع التعديل اليدوي المباشر على DB
4. تصحيح السبب الجذري:
- migration/patch + اختبار
5. التحقق بعد الإصلاح:
- إعادة تشغيل تقارير الحوكمة والاختبارات

## 6) سياسة الشفافية (Actor Attribution)
- أي حدث في Timeline/Notes/Attachments يجب أن يحمل هوية منفذ واضحة.
- المسموح: `full_name` أو `@username` أو `email` أو `id:<id>` أو `النظام`.
- غير المسموح: `المستخدم` أو `user` أو `web_user` أو `بواسطة المستخدم`.

## 7) سياسة بيانات الاختبار
- بيانات الاختبار يجب أن تكون موسومة بوضوح (`is_test_data=1`).
- يمنع خلط الاختباري مع الحقيقي داخل نفس الدفعة.
- أي خلط أو تسرب يعتبر حادث حوكمة ويُعالج فورًا.

## 8) المخرجات الإلزامية بعد كل تشغيل حوكمة
- `storage/logs/data-integrity-report.json`
- `storage/logs/data-integrity-report.md`
- `storage/logs/permissions-drift-report.json`
- `storage/logs/permissions-drift-report.md`
- `storage/logs/governance-summary.md`

## 9) مالك التشغيل
- فريق التطوير مسؤول عن صحة المخرجات التقنية.
- المالك التشغيلي مسؤول عن قرار الإطلاق بعد مراجعة النتائج.
