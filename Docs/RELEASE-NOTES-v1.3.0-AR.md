# WBGL Release Notes — v1.3.0

تاريخ الإصدار: 2026-03-04

## ملخص الإصدار
هذا الإصدار يثبت استقرار WBGL هندسيًا وتشغيليًا بعد إغلاق دورتي:
- `v1.2`: تفكيك طبقات العرض الثقيلة + حوكمة الصلاحيات + تحسينات سلامة البيانات.
- `v1.3`: حوكمة CI متقدمة + عقد صلاحيات حرجة + مواءمة مهاجرات PostgreSQL.

## أهم ما تم
1. فصل منطق البيانات من `statistics` و`settings` إلى خدمات مخصصة.
2. إضافة تقارير حوكمة واضحة:
- `permissions-drift-report` مع فحص `critical_endpoint_contract`.
- `data-integrity-check` موسّع مع artifacts.
- `governance-summary` كملخص تنفيذي.
3. تحسين CI:
- تشغيل موحد لتقارير الحوكمة.
- دعم وضع صارم اختياري عبر `WBGL_GOVERNANCE_STRICT`.
- رفع artifacts موحدة باسم `wbgl-governance-artifacts`.
4. مواءمة المهاجرات التشغيلية المتأخرة لصيغة PostgreSQL الصريحة (`BIGSERIAL`, `TIMESTAMP`).

## نتائج التحقق النهائية
- Unit Tests: `127/127` ناجحة.
- Integration Tests (`EnterpriseApiFlowsTest`): `45/45` ناجحة.
- Permissions Drift: `PASS` (بلا انحرافات).
- Data Integrity: `Fail=0`, `Warn=0`.
- Sequential Guard: `passed`.

## الاعتماد
هذه النسخة جاهزة كتسليم مستقر للمشتري.
