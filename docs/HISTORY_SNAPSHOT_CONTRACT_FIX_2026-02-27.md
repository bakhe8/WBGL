# إصلاح عقد Snapshot للأحداث (WBGL)

تاريخ التنفيذ: 2026-02-27

## الهدف

توحيد معنى `snapshot_data` ليكون دائمًا:

- **حالة الضمان قبل التغيير** (Before Change State)

بدل السلوك المختلط القديم (أحيانًا قبل التغيير وأحيانًا بعده).

## ما تم التحقق منه (الطريقة القديمة)

تم تشغيل تدقيق تاريخي عبر:

```bash
php maint/history-snapshot-audit.php --json
```

نتيجة البيانات التاريخية الحالية (قبل الإصلاح على مستوى التسجيل):

- `events_total = 2922`
- `events_with_change = 2327`
- `before_match_ratio = 0.5681`
- `after_match_ratio = 0.5526`

هذا يؤكد وجود **تضارب فعلي** في اتجاه السنابشوت.

## التصحيح المطبق

### 1) توحيد الكتابة (Write Path)

تم تعديل `TimelineRecorder` ليكتب `snapshot_data` كحالة **قبل التغيير** في مسارات الأحداث الأساسية:

- `recordExtensionEvent`
- `recordReductionEvent`
- `recordReleaseEvent`
- `recordDecisionEvent`
- `recordStatusTransitionEvent`
- `recordWorkflowEvent`
- `recordReopenEvent`
- `recordManualEditEvent` (مع تمرير `oldSnapshot` من API قبل التعديل)

وأضيف تمرير حالة ما بعد التغيير داخليًا فقط لـ Hybrid patch عبر وسيط `postSnapshot`.

### 2) توحيد القراءة (Read Path) دون العبث بالتاريخ القديم

تم تعديل `TimelineHybridLedger::resolveEventSnapshot` بحيث:

- إذا كان `snapshot_data` قديمًا ومخالفًا (بعد التغيير)، يتم **تطبيعه** إلى قبل التغيير باستخدام `event_details.changes.old_value`.
- للأحداث بدون snapshot، يتم إعادة بناء حالة **ما قبل الحدث**.

وتم ربط `TimelineDisplayService` بهذا resolver لضمان عرض متسق.

### 3) جاهزية Hybrid

تم تحسين `TimelineHybridLedger` ليكون:

- مدعومًا على SQLite وPostgreSQL في فحص أعمدة `guarantee_history`.
- غير معتمد على قاعدة “وجود snapshot يعني anchor دائمًا”.

## ملفات تم تعديلها

- `WBGL/app/Services/TimelineRecorder.php`
- `WBGL/app/Services/TimelineHybridLedger.php`
- `WBGL/app/Services/TimelineDisplayService.php`
- `WBGL/api/update-guarantee.php`
- `WBGL/api/commit-batch-draft.php`
- `WBGL/api/workflow-advance.php`
- `WBGL/maint/history-snapshot-audit.php` (جديد)

## تحقق ما بعد الإصلاح

1. فحص الصياغة:

```bash
php -l app/Services/TimelineRecorder.php
php -l app/Services/TimelineHybridLedger.php
php -l app/Services/TimelineDisplayService.php
```

2. اختبارات الوحدة:

```bash
vendor/bin/phpunit --testsuite Unit
```

النتيجة: **PASS** (`77 tests, 592 assertions`).

## ملاحظة مهمة

- لم يتم تعديل سجلات التاريخ القديمة داخل قاعدة البيانات (عدم العبث بالأثر التاريخي).
- تم تصحيح القراءة لتكون Before-Contract بشكل متسق، مع إصلاح الكتابة للأحداث الجديدة.

---

## تحديث تحقق لاحق (2026-02-27 04:02)

### 1) Backfill هجيني (Patch + Anchor) - حالة التنفيذ

تم التشغيل الفعلي ثم التحقق من idempotence:

- SQLite:
  - `php maint/history-hybrid-backfill.php --apply --json`
  - نتيجة التطبيق: `events_rewritten=12`
  - إعادة الفحص Dry-run مباشرة: `events_rewritten=0` (idempotent)
- PostgreSQL:
  - `php maint/history-hybrid-backfill.php --json` (مع إعدادات PG)
  - النتيجة: `events_rewritten=0` (مطبّق بالكامل)

### 2) جودة عقد Before Snapshot بعد التصحيح

نتيجة `php maint/history-snapshot-audit.php --json`:

- SQLite:
  - `events_total=2934`
  - `events_with_resolved_snapshot=2934`
  - `before_match_ratio=0.9910`
- PostgreSQL:
  - `events_total=2908`
  - `events_with_resolved_snapshot=2908`
  - `before_match_ratio=0.9948`

هذا يؤكد أن الاسترجاع التاريخي يعمل على Before-contract فعليًا عبر الـ resolver والهجين.

### 3) تغطية تكامل عملية لدورة حياة الضمان

تمت إضافة اختبار تكاملي عملي يغطي تسلسل:

- `extend -> reduce -> release -> reopen`

مع التحقق من:

- تغيرات البيانات الفعلية.
- حالة القرار (released ثم pending بعد reopen).
- وجود أحداث Timeline الفرعية: `extension`, `reduction`, `release`, `reopened`.
- وسم الأحداث الجديدة كـ `history_version=v2` مع `is_anchor=1` ووجود `anchor_snapshot`.

الملف:

- `WBGL/tests/Integration/EnterpriseApiFlowsTest.php`
