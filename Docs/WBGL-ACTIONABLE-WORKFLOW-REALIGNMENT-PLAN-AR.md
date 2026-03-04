# WBGL - خطة تنفيذ تقنية ملزمة (Runbook)

> الحالة: **ملزمة التنفيذ**  
> المرجع التنفيذي الوحيد: هذا الملف  
> مراجع غير ملزمة:  
> - `Docs/VISION-NON-BINDING-DEEPSEEK.md`  
> - `Docs/VISION-NON-BINDING-GPT.md`

---

## 0) طريقة العمل المعتمدة
1. التنفيذ تتابعي فقط: `A -> B -> C -> D`.
2. لا توجد مدة زمنية ثابتة. الانتقال يكون عند تحقق معيار القبول.
3. لا تنفيذ مسارين إعادة هيكلة كبيرين بالتوازي.
4. لا تغيير سلوك خارجي متفق عليه بدون توافق رجعي واضح.

## 1) تعريف "انتهت المهمة"
تعتبر المهمة منتهية فقط عند تحقق كل ما يلي:
1. التعديلات مطبقة على الملفات المحددة في المهمة.
2. أوامر التحقق في المهمة نجحت.
3. معيار القبول في المهمة تحقق بالكامل.
4. تم تحديث هذا الملف بحالة المهمة (`DONE`).

## 2) أوامر التحقق القياسية (تستخدم في كل المهام)
```powershell
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
php -l api/reopen.php
php -l api/save-and-next.php
php -l api/update-guarantee.php
```

---

## 3) لوحة المهام التنفيذية

### TASK A-001 (DONE) - إصلاح drift لمسار `reopen`
- الهدف: توحيد سلوك الصلاحيات في `reopen` وإغلاق فشل التكامل.
- الملفات:
  - `api/reopen.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php` (تحقق فقط)
- التحقق:
  - `Unit` أخضر.
  - `EnterpriseApiFlowsTest` أخضر.
- ملاحظة: منجزة بالفعل.

---

### TASK A-010 (DONE) - توحيد ترتيب فحوصات الصلاحيات في endpoints الحرجة
- الهدف: فرض تسلسل فحص موحد لكل endpoint حساس:
  1. `wbgl_api_require_login()`
  2. `wbgl_api_require_guarantee_visibility()` عند وجود `guarantee_id`
  3. `wbgl_api_require_permission()` أو policy check
  4. تنفيذ mutation
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `api/update-guarantee.php`
  - `api/workflow-advance.php`
  - `api/extend.php`
  - `api/reduce.php`
  - `api/release.php`
  - `api/batches.php`
- خطوات التنفيذ:
  1. حصر نقاط الفحص الحالية داخل كل ملف.
  2. إزالة الفحوصات المتضاربة أو المكررة.
  3. توحيد ترتيب الفحوصات حسب التسلسل أعلاه.
  4. الحفاظ على الرسائل الحالية قدر الإمكان لتجنب كسر الواجهة.
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l api/update-guarantee.php
php -l api/workflow-advance.php
php -l api/extend.php
php -l api/reduce.php
php -l api/release.php
php -l api/batches.php
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا فشل تكاملي متعلق بـ `403/401` غير متوقع في المسارات الحرجة.
  2. نفس الدور يعطي نفس القرار عبر endpoints المتقاربة.

- ملخص التنفيذ:
  1. تم توحيد الترتيب في الملفات المستهدفة بحيث أصبح الفحص يمر عبر `login -> visibility -> permission/policy -> mutation`.
  2. تم تعديل: `save-and-next`, `update-guarantee`, `extend`, `reduce`, `release`, `batches` بدون تغيير منطق الأعمال.
  3. التحقق نجح: Syntax أخضر + `EnterpriseApiFlowsTest` أخضر + `Unit` أخضر.

---

### TASK A-020 (DONE) - توحيد Contract الاستجابات للمسارات الحرجة (بتوافق رجعي)
- الهدف: تقليل تباين JSON بدون كسر الواجهة الحالية.
- النطاق:
  - `api/save-and-next.php`
  - `api/update-guarantee.php`
  - `api/workflow-advance.php`
- قاعدة التنفيذ:
  1. اعتماد envelope موحد داخليًا.
  2. عند وجود مستهلك حالي يعتمد شكل قديم، يتم إبقاء مفاتيح legacy مؤقتًا.
  3. توحيد أخطاء: `validation`, `permission`, `not_found`, `conflict`, `internal`.
- خطوات التنفيذ:
  1. جرد أشكال success/error الحالية في الملفات الثلاثة.
  2. إنشاء mapping واضح بين الخطأ الداخلي والكود/الرسالة الخارجية.
  3. تطبيق التوحيد ملفًا ملفًا.
  4. إضافة اختبارات تكامل تغطي الشكل الناتج.
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l api/update-guarantee.php
php -l api/workflow-advance.php
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. المسارات الثلاثة تعيد شكلًا متسقًا.
  2. لا كسر في UI الحالي.

- ملخص التنفيذ:
  1. تمت إضافة helpers موحدة في `api/_bootstrap.php`: `wbgl_api_compat_success` و`wbgl_api_compat_fail` مع `error_type` وتصنيف قياسي.
  2. تم تطبيق العقد المتوافق رجعيًا على `save-and-next`, `update-guarantee`, `workflow-advance` مع الإبقاء على المفاتيح القديمة أعلى المستوى.
  3. تمت إضافة تغطية تكاملية للعقد الجديد في `EnterpriseApiFlowsTest` (حالتا `workflow-advance` و`update-guarantee`) وجميع الاختبارات خضراء.

---

### TASK A-030 (DONE) - توسيع العقد المتوافق إلى مسارات Lifecycle (`extend/reduce/release`)
- الهدف: إزالة تباين الاستجابات في مسارات دورة الحياة بدون كسر الواجهة الحالية.
- الملفات المستهدفة:
  - `api/extend.php`
  - `api/reduce.php`
  - `api/release.php`
- خطوات التنفيذ:
  1. استبدال أخطاء `echo json_encode` اليدوية بـ `wbgl_api_compat_fail(...)`.
  2. استبدال نجاح `wbgl_api_success(...)` بـ `wbgl_api_compat_success(...)`.
  3. الإبقاء على نفس الحقول legacy (`html`, `status`, `policy`, `surface`, `reasons`) لتوافق رجعي كامل.
- أوامر التحقق:
```powershell
php -l api/extend.php
php -l api/reduce.php
php -l api/release.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي الخارجي للمسارات الثلاثة.
  2. الاستجابات تتبع envelope متوافق موحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم تحويل نقاط الفشل في `extend/reduce/release` إلى `wbgl_api_compat_fail` مع نفس `reason_code` والرسائل.
  2. تم تحويل نجاح المسارات الثلاثة إلى `wbgl_api_compat_success` مع بقاء الحقول القديمة في أعلى المستوى وداخل `data`.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `830 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`, `errors=0`, `failures=0`).

---

### TASK A-040 (DONE) - توحيد Contract مسار `reopen` (بتوافق رجعي)
- الهدف: توحيد استجابات `reopen` على `compat envelope` بدون تغيير سلوك الحوكمة (`undo_request` / `break_glass`).
- الملفات المستهدفة:
  - `api/reopen.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. استبدال `wbgl_api_fail` بـ `wbgl_api_compat_fail` في مسارات الأخطاء.
  2. استبدال استجابات النجاح اليدوية بـ `wbgl_api_compat_success`.
  3. الإبقاء على الحقول legacy (`mode`, `request_id`, `break_glass`) في أعلى المستوى مع تكرارها داخل `data`.
  4. إضافة تحقق تكاملي لعقد `reopen` (مطابقة `mode` بين top-level و`data` + `error_type=validation` لحالة ticket المفقود).
- أوامر التحقق:
```powershell
php -l api/reopen.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك الحوكمة الحالي بدون regressions.
  2. استجابات `reopen` متوافقة مع `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/reopen.php` على `wbgl_api_compat_success/fail` مع الحفاظ على مفاتيح `mode/request_id/break_glass`.
  2. تم توسيع اختبار التكامل لتأكيد التوافق (`top-level` + `data`) لحالتي supervisor reopen وbreak-glass reopen.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `219 assertions`, `errors=0`, `failures=0`).

---

### TASK A-050 (DONE) - توحيد Contract مسار `batches` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/batches.php` على `compat envelope` بدون كسر الحقول legacy الحالية.
- الملفات المستهدفة:
  - `api/batches.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل إخراج POST/GET من `echo json_encode(...)` إلى `wbgl_api_compat_success/fail`.
  2. الحفاظ على الحقول القديمة القادمة من `BatchService` (`success`, `error`, `extended_count`, ... إلخ) عبر تمرير payload كما هو.
  3. إبقاء دلالة الأكواد كما هي (حالات business-failure في POST بقيت `200` مع `success=false`).
  4. إضافة تحقق تكاملي لعقد الاستجابة في `batch reopen` (`request_id` + `data.success`).
- أوامر التحقق:
```powershell
php -l api/batches.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير سلوكي في عمليات الدُفعات.
  2. وجود envelope متوافق موحد في كل استجابات `batches`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/batches.php` على `wbgl_api_compat_success/fail` مع الإبقاء على payload legacy كما هو.
  2. تم تعزيز اختبار التكامل `testBatchReopenAllowsSupervisorWithoutManageDataAndRecordsAudit` للتحقق من التوافق (`request_id`, `data.success`).
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `223 assertions`, `errors=0`, `failures=0`).

---

### TASK A-060 (DONE) - توحيد Contract مسار `undo-requests` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/undo-requests.php` على `compat envelope` بدون تغيير منطق الحوكمة (dual-control).
- الملفات المستهدفة:
  - `api/undo-requests.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح GET/POST من `echo json_encode(...)` إلى `wbgl_api_compat_success`.
  2. تحويل أخطاء `method/action/runtime` إلى `wbgl_api_compat_fail`.
  3. الإبقاء على الحقول legacy للمستهلكين الحاليين (خصوصًا `request_id` في submit و`data` في list).
  4. إضافة تحقق تكاملي لعقد `undo-requests` (`data` + `request_id` + `error_type` في حالة self-approval).
- أوامر التحقق:
```powershell
php -l api/undo-requests.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير سلوك workflow (`submit -> approve/reject/execute`) وظيفيًا.
  2. الاستجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/undo-requests.php` على `wbgl_api_compat_success/fail` لكل المسارات.
  2. تم تعزيز اختبار التكامل `testUndoGovernanceWorkflowEnforcesDualControl` للتحقق من التوافق (`data`, `request_id`, `error_type`).
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `232 assertions`, `errors=0`, `failures=0`).

---

### TASK A-070 (DONE) - توحيد Contract مسار `scheduler-dead-letters` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/scheduler-dead-letters.php` على `compat envelope` دون تغيير منطق التشغيل.
- الملفات المستهدفة:
  - `api/scheduler-dead-letters.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح GET/POST إلى `wbgl_api_compat_success`.
  2. تحويل أخطاء method/action/runtime إلى `wbgl_api_compat_fail`.
  3. الحفاظ على شكل payload الحالي (`data` لنتائج GET/retry) لتوافق رجعي.
  4. إضافة تحقق تكاملي لعقد المسار (`request_id`, `error=null`, `data`).
- أوامر التحقق:
```powershell
php -l api/scheduler-dead-letters.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك التشغيلي لمسار dead letters.
  2. استجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/scheduler-dead-letters.php` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم تعزيز اختبار `testSchedulerDeadLetterResolveFlow` للتحقق من `request_id` و`error` و`data` في الاستجابات.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `239 assertions`, `errors=0`, `failures=0`).

---

### TASK A-080 (DONE) - توحيد Contract مسار `notifications` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/notifications.php` على `compat envelope` مع الحفاظ على سلوك inbox الحالي.
- الملفات المستهدفة:
  - `api/notifications.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح GET/POST إلى `wbgl_api_compat_success`.
  2. تحويل أخطاء method/action/runtime إلى `wbgl_api_compat_fail`.
  3. الحفاظ على payload الحالي (`data` للـ list و`updated` لـ `mark_all_read`).
  4. إضافة اختبار تكاملي جديد لمسار الإشعارات (`list unread` + `mark_read`) مع تنظيف بيانات الاختبار.
- أوامر التحقق:
```powershell
php -l api/notifications.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير وظيفي في inbox operations.
  2. الاستجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/notifications.php` على `wbgl_api_compat_success/fail` لكل المسارات.
  2. تمت إضافة اختبار `testNotificationsInboxListAndMarkReadUseCompatEnvelope` مع تنظيف `notifications` الناتجة عن الاختبار في `tearDownAfterClass`.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `251 assertions`, `errors=0`, `failures=0`).

---

### TASK A-090 (DONE) - توحيد Contract مسار `print-events` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/print-events.php` على `compat envelope` بدون تغيير سلوك التسجيل/الاستعلام.
- الملفات المستهدفة:
  - `api/print-events.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح GET/POST إلى `wbgl_api_compat_success`.
  2. تحويل أخطاء method/runtime إلى `wbgl_api_compat_fail`.
  3. الحفاظ على payload الحالي (`data.inserted` في POST و`data` قائمة الأحداث في GET).
  4. تعزيز اختبار `testPrintEventsApiFlow` للتحقق من `request_id` و`error=null`.
- أوامر التحقق:
```powershell
php -l api/print-events.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير وظيفي لمسار print events.
  2. الاستجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/print-events.php` على `wbgl_api_compat_success/fail`.
  2. تم تعزيز `testPrintEventsApiFlow` للتحقق من `request_id` و`error` للعقد المتوافق.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `255 assertions`, `errors=0`, `failures=0`).

---

### TASK A-100 (DONE) - توحيد Contract مساري `metrics` و`alerts` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/metrics.php` و`api/alerts.php` على `compat envelope` مع الحفاظ على سلوك الصلاحيات وpayload الحالي.
- الملفات المستهدفة:
  - `api/metrics.php`
  - `api/alerts.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح GET إلى `wbgl_api_compat_success`.
  2. تحويل أخطاء method إلى `wbgl_api_compat_fail(405, ...)`.
  3. تحويل أخطاء البناء الداخلية إلى `wbgl_api_compat_fail(..., 'internal')` مع نفس الرسائل الحالية.
  4. تعزيز اختبارات التكامل (`metrics` و`alerts`) للتحقق من `request_id`, `error`, `data` إضافةً إلى فحص الصلاحيات.
- أوامر التحقق:
```powershell
php -l api/metrics.php
php -l api/alerts.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك الصلاحيات (`manage_users`) دون تغيير.
  2. الاستجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `metrics.php` و`alerts.php` على `wbgl_api_compat_success/fail` مع إبقاء payload كما هو.
  2. تم توسيع اختباري `testMetricsEndpointRequiresManageUsersAndReturnsSnapshot` و`testAlertsEndpointRequiresManageUsersAndReturnsAlertPayload` لتثبيت العقد المتوافق.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `271 assertions`, `errors=0`, `failures=0`).

---

### TASK A-110 (DONE) - توحيد Contract مساري `save-note` و`upload-attachment` (بتوافق رجعي)
- الهدف: توحيد استجابات مسارات الكتابة الحساسة `save-note` و`upload-attachment` على `compat envelope` مع الحفاظ على منطق policy/visibility الحالي.
- الملفات المستهدفة:
  - `api/save-note.php`
  - `api/upload-attachment.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح/فشل `save-note` إلى `wbgl_api_compat_success/fail`.
  2. تحويل نجاح/فشل `upload-attachment` إلى `wbgl_api_compat_success/fail`.
  3. الحفاظ على نفس payload legacy (`note`/`file` + `policy/surface/reasons`) لضمان التوافق الرجعي.
  4. تعزيز اختبارات الرفض لعدم الرؤية في التكامل (`error_type`, `data`, `request_id`).
- أوامر التحقق:
```powershell
php -l api/save-note.php
php -l api/upload-attachment.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك الرفض/النجاح الوظيفي في المسارين.
  2. الاستجابات متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `save-note.php` و`upload-attachment.php` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم تثبيت عقد الرفض في التكامل لحالتي عدم الرؤية على المسارين (`permission`, `data=null`, `request_id`).
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `277 assertions`, `errors=0`, `failures=0`).

---

### TASK A-120 (DONE) - توحيد Contract مسار `me` وتثبيت `request_id` في الاستجابة
- الهدف: جعل `api/me.php` يلتزم بالكامل بـ `compat envelope` مع الحفاظ على حقول المستخدم legacy أعلى المستوى.
- الملفات المستهدفة:
  - `api/me.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل حالة عدم التوثيق في `me.php` إلى `wbgl_api_compat_fail(401, ..., 'permission')`.
  2. تحويل النجاح إلى `wbgl_api_compat_success` مع إبقاء `user` أعلى المستوى وداخل `data`.
  3. تعزيز اختباري `auth flow` و`request-id` للتحقق من `error/error_type/data/request_id`.
  4. إصلاح assertion تكاملي غير مهيأ في اختبار `reopen` كان يسبب فشلًا كاذبًا.
- أوامر التحقق:
```powershell
php -l api/me.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. `GET /api/me.php` يعيد envelope متوافقًا في حالتي guest وauthed.
  2. `X-Request-Id` يُعاد في الهيدر ويظهر في جسم الاستجابة لنفس الطلب.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/me.php` على `wbgl_api_compat_success/fail` بدون كسر payload المستخدم الحالي.
  2. تم توسيع اختبارات التكامل لتثبيت سلوك `request_id` وحقول envelope في مسار `me`.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `286 assertions`, `errors=0`, `failures=0`).

---

### TASK A-130 (DONE) - توحيد Contract مسار `save-import` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/save-import.php` على `compat envelope` بدون تغيير سلوك الاستيراد.
- الملفات المستهدفة:
  - `api/save-import.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل مسارات النجاح من `wbgl_api_success` إلى `wbgl_api_compat_success`.
  2. تحويل مسار الفشل العام من `wbgl_api_fail` إلى `wbgl_api_compat_fail`.
  3. تثبيت اختبار التكامل للتحقق من `error_type` في الإدخال غير الصالح.
  4. تثبيت توافق الحقول legacy عبر مطابقة `saved_count` بين top-level و`data`.
- أوامر التحقق:
```powershell
php -l api/save-import.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك `save-import` الوظيفي (حفظ/إعادة توجيه draft) بدون regressions.
  2. استجابات النجاح/الفشل متوافقة مع envelope الموحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/save-import.php` على `wbgl_api_compat_success/fail` مع إبقاء payload الحالي.
  2. تم تعزيز `testSaveImportUsesUnifiedEnvelope` للتحقق من `error_type=validation` ومطابقة `saved_count` بين `data` وtop-level.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`21/21`, `289 assertions`, `errors=0`, `failures=0`).

---

### TASK A-140 (DONE) - توحيد Contract مسار `user-preferences` (بتوافق رجعي)
- الهدف: توحيد `api/user-preferences.php` على `compat envelope` في مساري القراءة والتحديث دون تغيير قواعد التفضيلات.
- الملفات المستهدفة:
  - `api/user-preferences.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل `GET/POST` الناجح إلى `wbgl_api_compat_success`.
  2. تحويل فحوصات الخطأ (`unauthorized`, `method`, `permission`, `validation`) إلى `wbgl_api_compat_fail`.
  3. الحفاظ على الحقل legacy `preferences` أعلى المستوى مع نسخه داخل `data`.
  4. إضافة اختبار تكامل يغطي `guest unauthorized` و`authed read` و`validation error`.
- أوامر التحقق:
```powershell
php -l api/user-preferences.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي لمسار التفضيلات.
  2. الاستجابات متوافقة مع `compat envelope` في حالات النجاح والخطأ.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/user-preferences.php` بالكامل على `wbgl_api_compat_success/fail` مع الحفاظ على `preferences` legacy.
  2. تم إضافة اختبار `testUserPreferencesUsesCompatEnvelopeForReadAndValidationErrors` لتثبيت العقد المتوافق على المسار.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`22/22`, `312 assertions`, `errors=0`, `failures=0`).

---

### TASK A-150 (DONE) - توحيد Contract مسار `get-current-state` (بتوافق رجعي)
- الهدف: توحيد استجابات `api/get-current-state.php` على `compat envelope` مع الحفاظ على payload الحالي (`snapshot`).
- الملفات المستهدفة:
  - `api/get-current-state.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل أخطاء الإدخال/الصلاحية/عدم الوجود/الأخطاء الداخلية إلى `wbgl_api_compat_fail`.
  2. تحويل استجابة النجاح إلى `wbgl_api_compat_success` مع إبقاء `snapshot` أعلى المستوى.
  3. إضافة اختبار تكاملي للتحقق من:
     - حالة الإدخال غير الصالح (`400`, `error_type=validation`)
     - حالة النجاح (`200`) مع مطابقة `snapshot` بين top-level و`data`.
- أوامر التحقق:
```powershell
php -l api/get-current-state.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير وظيفي في استرجاع الحالة الحالية.
  2. الاستجابات متوافقة مع `compat envelope` في كل السيناريوهات الأساسية.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/get-current-state.php` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testGetCurrentStateUsesCompatEnvelope` لتثبيت العقد الجديد في حالتي الخطأ والنجاح.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`23/23`, `327 assertions`, `errors=0`, `failures=0`).

---

### TASK A-160 (DONE) - توحيد Contract مسارات `roles` (create/update/delete) بتوافق رجعي
- الهدف: توحيد استجابات إدارة الأدوار الحساسة (`api/roles/create.php`, `update.php`, `delete.php`) على `compat envelope`.
- الملفات المستهدفة:
  - `api/roles/create.php`
  - `api/roles/update.php`
  - `api/roles/delete.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل جميع نقاط `wbgl_api_success/fail` إلى `wbgl_api_compat_success/fail`.
  2. الإبقاء على payload legacy (`message`, `role`) أعلى المستوى مع نسخه داخل `data`.
  3. إضافة اختبار تكاملي CRUD يغطي:
     - حدود الصلاحيات (guest/operator)
     - إنشاء/تحديث/حذف دور بواسطة admin
     - ثبات envelope (`error/error_type/request_id/data`)
  4. إضافة تنظيف تلقائي للأدوار التجريبية داخل `tearDownAfterClass`.
- أوامر التحقق:
```powershell
php -l api/roles/create.php
php -l api/roles/update.php
php -l api/roles/delete.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير سلوك إدارة الأدوار وظيفيًا.
  2. استجابات CRUD للأدوار متوافقة مع `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد مسارات `roles` الثلاثة على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testRolesCrudUsesCompatEnvelopeAndPermissionBoundaries` مع تنظيف تلقائي للأدوار المنشأة في الاختبار.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`24/24`, `360 assertions`, `errors=0`, `failures=0`).

---

### TASK A-170 (DONE) - توحيد Contract مسار `users/list` (بتوافق رجعي)
- الهدف: توحيد الاستجابة الإدارية لمسار `api/users/list.php` على `compat envelope` مع الحفاظ على الحقول الحالية (`users/roles/permissions/overrides/permission_catalog`).
- الملفات المستهدفة:
  - `api/users/list.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل استجابة النجاح إلى `wbgl_api_compat_success` مع نفس payload.
  2. تحويل مسار الخطأ الداخلي إلى `wbgl_api_compat_fail(..., 'internal')`.
  3. إضافة اختبار تكامل لصلاحيات `manage_users` على المسار:
     - `operator` -> `403`
     - `admin` -> `200`
  4. تثبيت التوافق بين top-level و`data` (خصوصًا `users`).
- أوامر التحقق:
```powershell
php -l api/users/list.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك عرض المستخدمين/الأدوار دون انحدار.
  2. الاستجابة متوافقة مع `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/users/list.php` على `wbgl_api_compat_success/fail`.
  2. تمت إضافة اختبار `testUsersListRequiresManageUsersAndUsesCompatEnvelope` في التكامل.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`25/25`, `380 assertions`, `errors=0`, `failures=0`).

---

### TASK A-180 (DONE) - توحيد Contract مسارات `users/create|update|delete` (بتوافق رجعي)
- الهدف: توحيد استجابات إدارة المستخدمين الكتابية على `compat envelope` دون تغيير قواعد العمل الحالية.
- الملفات المستهدفة:
  - `api/users/create.php`
  - `api/users/update.php`
  - `api/users/delete.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نقاط `echo json_encode` اليدوية إلى `wbgl_api_compat_success/fail`.
  2. إبقاء رسائل الأعمال الحالية (`message`, أخطاء التحقق والصلاحيات) كما هي.
  3. تطبيع الإدخال في endpoints الثلاثة (`json_decode` -> مصفوفة) قبل القراءة.
  4. إضافة اختبار تكاملي CRUD للمستخدمين يغطي:
     - حدود الصلاحيات (`guest`, `operator`).
     - إنشاء/تحديث/حذف مستخدم بواسطة `admin`.
     - ثبات `compat envelope` (`error/error_type/data/request_id`).
  5. إضافة تنظيف تلقائي للحسابات الاختبارية في `tearDownAfterClass`.
- أوامر التحقق:
```powershell
php -l api/users/create.php
php -l api/users/update.php
php -l api/users/delete.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي لإدارة المستخدمين.
  2. الاستجابات متوافقة مع `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `users/create|update|delete` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testUsersCrudUsesCompatEnvelopeAndPermissionBoundaries` مع تنظيف تلقائي للمستخدمين التجريبيين.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`26/26`, `419 assertions`, `errors=0`, `failures=0`).

---

### TASK A-190 (DONE) - توحيد Contract مساري `settings` و`settings-audit` (بتوافق رجعي)
- الهدف: توحيد عقود الاستجابة لمسارات الإعدادات التشغيلية على `compat envelope` مع إبقاء الحقول legacy.
- الملفات المستهدفة:
  - `api/settings.php`
  - `api/settings-audit.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل `settings.php` (GET/POST/errors) إلى `wbgl_api_compat_success/fail`.
  2. الحفاظ على `settings` و`errors` كحقول legacy أعلى المستوى لضمان توافق الواجهة.
  3. تحويل `settings-audit.php` إلى `wbgl_api_compat_success/fail` مع الحفاظ على `data` كما هو.
  4. إضافة اختبار تكاملي يغطي:
     - حدود الصلاحيات (`operator` مرفوض).
     - نجاح `GET` لـ `settings` و`settings-audit`.
     - رفض `POST` على `settings-audit` (`405`).
- أوامر التحقق:
```powershell
php -l api/settings.php
php -l api/settings-audit.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير سلوكي في إدارة الإعدادات.
  2. العقود متوافقة مع `compat envelope` في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/settings.php` و`api/settings-audit.php` على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testSettingsEndpointsUseCompatEnvelopeAndPermissionBoundaries` لتثبيت الصلاحيات والعقد.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`27/27`, `456 assertions`, `errors=0`, `failures=0`).

---

### TASK A-200 (DONE) - توحيد Contract مسارات `banks/suppliers` (create/update/delete) بتوافق رجعي
- الهدف: توحيد مسارات إدارة البنوك والموردين (`create/update/delete`) على `compat envelope` مع الحفاظ على payload legacy.
- الملفات المستهدفة:
  - `api/create-bank.php`
  - `api/update_bank.php`
  - `api/delete_bank.php`
  - `api/create-supplier.php`
  - `api/update_supplier.php`
  - `api/delete_supplier.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل جميع نقاط `echo json_encode` اليدوية إلى `wbgl_api_compat_success/fail`.
  2. الإبقاء على الحقول legacy مثل `bank_id`, `supplier_id`, `updated` أعلى المستوى.
  3. تحسين فحوصات الإدخال الحرجة (`Missing ID`/`Official name`) لتُعاد كـ `400` متوافقة.
  4. إضافة اختبار تكاملي CRUD للبنوك والموردين يشمل:
     - `guest` غير مصرح (`401`) لمسارات الإنشاء.
     - إنشاء/تحديث/حذف كيانين فعليًا بواسطة `admin`.
     - تثبيت envelope (`error/error_type/request_id/data`) مع تنظيف تلقائي للكيانات التجريبية.
- أوامر التحقق:
```powershell
php -l api/create-bank.php
php -l api/update_bank.php
php -l api/delete_bank.php
php -l api/create-supplier.php
php -l api/update_supplier.php
php -l api/delete_supplier.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم كسر سلوك إدارة البنوك/الموردين.
  2. التزام المسارات الستة بـ `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد مسارات البنوك/الموردين الستة على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testBankAndSupplierCrudUsesCompatEnvelopeAndPermissionBoundaries` مع cleanup للبيانات التجريبية.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`28/28`, `512 assertions`, `errors=0`, `failures=0`).

---

### TASK A-210 (DONE) - توحيد Contract مساري `create-guarantee` و`convert-to-real` (بتوافق رجعي)
- الهدف: توحيد مساري الإنشاء اليدوي والتحويل من test->real على `compat envelope` مع الحفاظ على الحقول legacy (`id`, `message`).
- الملفات المستهدفة:
  - `api/create-guarantee.php`
  - `api/convert-to-real.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل جميع الاستجابات اليدوية في المسارين إلى `wbgl_api_compat_success/fail`.
  2. تثبيت التصنيف المناسب للأخطاء:
     - `Method not allowed` -> `405`
     - `Missing guarantee_id` -> `400` (`validation`)
     - أخطاء داخلية التحويل -> `500` (`internal`)
  3. إضافة اختبار تكاملي End-to-End:
     - فحص `guest` على `convert-to-real` (`401`)
     - إنشاء ضمان جديد عبر `create-guarantee`
     - وضعه مؤقتًا كـ `test_data` ثم تحويله عبر `convert-to-real`
     - التحقق من DB أن `is_test_data=0` وحقول الاختبار أصبحت `NULL`.
- أوامر التحقق:
```powershell
php -l api/create-guarantee.php
php -l api/convert-to-real.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي لمساري الإنشاء والتحويل.
  2. التزام الاستجابات بـ `compat envelope`.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/create-guarantee.php` و`api/convert-to-real.php` على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testCreateGuaranteeAndConvertToRealUseCompatEnvelope` لتثبيت السلوك والعقد والتحقق القاعدي.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`29/29`, `548 assertions`, `errors=0`, `failures=0`).

---

### TASK A-220 (DONE) - توحيد Contract مسار `commit-batch-draft` (بتوافق رجعي)
- الهدف: توحيد مسار تحويل draft batch إلى guarantees فعلية على `compat envelope` مع إبقاء الحقول legacy (`message`, `redirect_id`).
- الملفات المستهدفة:
  - `api/commit-batch-draft.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل نجاح المسار إلى `wbgl_api_compat_success`.
  2. تحويل مسار الفشل العام إلى `wbgl_api_compat_fail(400, ...)` للحفاظ على سلوك الإدخال الحالي.
  3. إضافة اختبار تكاملي يغطي:
     - `guest` غير مصرح (`401`)
     - فشل الإدخال الناقص (`400`, `validation`)
     - نجاح commit فعلي مع التحقق من تحديث `guarantee_number` و`raw_data.bg_number`.
- أوامر التحقق:
```powershell
php -l api/commit-batch-draft.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير السلوك الوظيفي لمسار commit.
  2. استجابة متوافقة مع `compat envelope` في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/commit-batch-draft.php` على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testCommitBatchDraftUsesCompatEnvelopeAndPermissionBoundaries` مع تحقق قاعدي بعد commit.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`30/30`, `577 assertions`, `errors=0`, `failures=0`).

---

### TASK A-230 (DONE) - توحيد Contract مسار `merge-suppliers` (بتوافق رجعي)
- الهدف: توحيد endpoint دمج الموردين `api/merge-suppliers.php` على `compat envelope` مع الحفاظ على payload النجاح الحالي.
- الملفات المستهدفة:
  - `api/merge-suppliers.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل method guard إلى `wbgl_api_compat_fail(405, ...)`.
  2. تحويل validation guard (`source_id/target_id`) إلى `wbgl_api_compat_fail(400, ...)`.
  3. تحويل النجاح إلى `wbgl_api_compat_success(['success' => true])`.
  4. إضافة اختبار تكاملي يغطي:
     - `guest` غير مصرح (`401`)
     - إدخال غير مكتمل (`400`, `validation`)
     - merge فعلي بين موردين مع تحقق قاعدي أن المصدر حُذف والهدف بقي.
- أوامر التحقق:
```powershell
php -l api/merge-suppliers.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير سلوك الدمج الوظيفي.
  2. الاستجابة متوافقة مع `compat envelope` في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/merge-suppliers.php` على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testMergeSuppliersUsesCompatEnvelope` مع إنشاء مصدر/هدف ودمجهما والتحقق القاعدي.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`31/31`, `607 assertions`, `errors=0`, `failures=0`).

---

### TASK A-240 (DONE) - توحيد Contract مساري `learning-data` و`learning-action` (بتوافق رجعي)
- الهدف: توحيد endpoints التعلم التشغيلي على `compat envelope` مع الإبقاء على payloads الحالية (`confirmations/rejections`, `message`) لضمان عدم كسر الواجهة.
- الملفات المستهدفة:
  - `api/learning-data.php`
  - `api/learning-action.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل استجابات النجاح في `learning-data` إلى `wbgl_api_compat_success` بدل `echo json_encode` المباشر.
  2. توحيد method/validation/errors في `learning-action` عبر `wbgl_api_compat_fail` (`405/400/500`) مع نفس الرسائل الوظيفية.
  3. تحويل نجاح الحذف في `learning-action` إلى `wbgl_api_compat_success(['message' => 'Item deleted'])`.
  4. إضافة اختبار تكاملي يغطي:
     - رفض guest (`401`) مع envelope موحد.
     - نجاح admin في `learning-data`.
     - رفض GET على `learning-action` (`405`).
     - رفض POST ناقص (`400`, `validation`).
     - حذف فعلي لسجل `learning_confirmations` مع تحقق قاعدي.
- أوامر التحقق:
```powershell
php -l api/learning-data.php
php -l api/learning-action.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير السلوك الوظيفي لمساري التعلم.
  2. الاستجابة متوافقة مع `compat envelope` في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/learning-data.php` و`api/learning-action.php` على `wbgl_api_compat_success/fail` مع الحفاظ على payloads القديمة.
  2. تم إضافة اختبار `testLearningDataAndActionUseCompatEnvelope` وتضمين cleanup آمن لبيانات الاختبار في teardown.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`32/32`, `649 assertions`, `errors=0`, `failures=0`).

---

### TASK A-250 (DONE) - توحيد Contract مسار `matching-overrides` (بتوافق رجعي)
- الهدف: توحيد endpoint إدارة overrides (`list/create/update/delete`) على `compat envelope` مع الإبقاء على payloadات الحالية (`items`, `count`, `item`, `deleted`) لتفادي أي كسر على الواجهة.
- الملفات المستهدفة:
  - `api/matching-overrides.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل كل مسارات النجاح (`GET/POST/PUT/PATCH/DELETE`) إلى `wbgl_api_compat_success`.
  2. تحويل method guard إلى `wbgl_api_compat_fail(405, 'Method Not Allowed')`.
  3. تحويل مسار الأخطاء إلى `wbgl_api_compat_fail(400, ..., 'validation')` مع الحفاظ على الرسائل الحالية.
  4. إضافة اختبار تكاملي يغطي:
     - رفض guest (`401`) مع envelope موحد.
     - نجاح `GET` للـ admin.
     - فشل validation عند نقص `supplier_id` (`400`).
     - إنشاء override فعلي ثم حذفه والتحقق من الحذف في DB.
     - cleanup تلقائي لأي records اختبارية.
- أوامر التحقق:
```powershell
php -l api/matching-overrides.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي لمسار إدارة overrides.
  2. توافق كامل مع `compat envelope` في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/matching-overrides.php` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testMatchingOverridesUsesCompatEnvelope` مع cleanup آمن لجدول `supplier_overrides`.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`33/33`, `693 assertions`, `errors=0`, `failures=0`).

---

### TASK A-260 (DONE) - توحيد Contract مسار `import_matching_overrides` (بتوافق رجعي)
- الهدف: توحيد مسار استيراد overrides (multipart JSON) على `compat envelope` مع الحفاظ على الحقول الحالية (`message`, `stats`) لضمان توافق الواجهة.
- الملفات المستهدفة:
  - `api/import_matching_overrides.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل method guard إلى `wbgl_api_compat_fail(405, 'Method Not Allowed')`.
  2. تحويل نجاح الاستيراد إلى `wbgl_api_compat_success(['message' => ..., 'stats' => ...])`.
  3. تحويل أخطاء التحقق/الملف إلى `wbgl_api_compat_fail(400, ..., 'validation')`.
  4. إضافة اختبار تكاملي multipart يغطي:
     - رفض guest (`401`).
     - رفض method غير مدعوم (`405`).
     - رفض طلب بدون ملف (`400`).
     - استيراد فعلي لصف واحد والتحقق من إنشاء row في `supplier_overrides`.
- أوامر التحقق:
```powershell
php -l api/import_matching_overrides.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس سلوك الاستيراد الوظيفي مع contract موحد.
  2. بقاء payloadات النجاح كما هي مع نسخة `data` المتوافقة.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/import_matching_overrides.php` بالكامل على `wbgl_api_compat_success/fail`.
  2. تم إضافة اختبار `testImportMatchingOverridesUsesCompatEnvelope` باستخدام `requestMultipart` مع تحقق قاعدي وcleanup.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`34/34`, `728 assertions`, `errors=0`, `failures=0`).

---

### TASK A-270 (DONE) - توحيد Contract مساري `import_banks` و`import_suppliers` (بتوافق رجعي)
- الهدف: توحيد مسارات الاستيراد JSON للبنوك والموردين على `compat envelope` مع الحفاظ على الرسائل الحالية وإضافة counters صريحة (`inserted/updated/aliases_inserted`).
- الملفات المستهدفة:
  - `api/import_banks.php`
  - `api/import_suppliers.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل method guard في المسارين إلى `wbgl_api_compat_fail(405, 'Method Not Allowed')`.
  2. تحويل أخطاء الملف/تنسيق JSON إلى `wbgl_api_compat_fail(400, ..., 'validation')`.
  3. تحويل النجاح إلى `wbgl_api_compat_success` مع الإبقاء على `message` وإضافة counters.
  4. إضافة اختبارين تكامليين multipart:
     - `testImportBanksUsesCompatEnvelope`
     - `testImportSuppliersUsesCompatEnvelope`
     مع تحقق قاعدي (وجود السجل المستورد) وcleanup تلقائي.
- أوامر التحقق:
```powershell
php -l api/import_banks.php
php -l api/import_suppliers.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير السلوك التشغيلي للاستيراد.
  2. توحيد العقد في النجاح والفشل مع توافق رجعي.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/import_banks.php` و`api/import_suppliers.php` على `wbgl_api_compat_success/fail`.
  2. تمت إضافة تغطية تكاملية multipart للمسارين مع تحقق إدخال فعلي في DB.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`36/36`, `797 assertions`, `errors=0`, `failures=0`).

---

### TASK A-280 (DONE) - توحيد Contract مسار `smart-paste-confidence` (بتوافق رجعي)
- الهدف: إدخال endpoint الثقة في التحليل النصي ضمن العقد الموحد مع الحفاظ على payload `data.supplier` و`data.confidence_thresholds`.
- الملفات المستهدفة:
  - `api/smart-paste-confidence.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل method guard إلى `wbgl_api_compat_fail(405, 'Method not allowed')`.
  2. تحويل validation (`No text provided`) إلى `wbgl_api_compat_fail(400, ...)`.
  3. تحويل النجاح إلى `wbgl_api_compat_success` مع الحفاظ على هيكل `data`.
  4. تحويل أخطاء runtime إلى `wbgl_api_compat_fail(500, ..., 'internal')`.
  5. إضافة اختبار تكاملي `testSmartPasteConfidenceUsesCompatEnvelope` يغطي:
     - guest غير مصرح (`401`).
     - method غير مدعوم (`405`).
     - validation (`400`).
     - نجاح parsing (`200`) مع payload ثقة صحيح.
- أوامر التحقق:
```powershell
php -l api/smart-paste-confidence.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس السلوك الوظيفي لمسار الثقة مع contract موحد.
  2. عدم كسر الواجهة التي تعتمد على `data` الحالية.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/smart-paste-confidence.php` على `wbgl_api_compat_success/fail`.
  2. تمت إضافة اختبار تكاملي شامل للمسار مع تصحيح حالة guest لتفادي اعتراض CSRF على `POST` غير المصرح.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`37/37`, `827 assertions`, `errors=0`, `failures=0`).

---

### TASK A-290 (DONE) - توحيد Contract مسار `history` (Legacy Retired Endpoint)
- الهدف: توحيد endpoint المتقاعد `api/history.php` على `compat fail envelope` مع الحفاظ على حقول التوجيه الحالية (`code`, `replacement`).
- الملفات المستهدفة:
  - `api/history.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. استبدال `http_response_code + echo json_encode` بـ `wbgl_api_compat_fail(410, ...)`.
  2. الإبقاء على payload المتقاعد:
     - `code = LEGACY_ENDPOINT_RETIRED`
     - `replacement.timeline`
     - `replacement.snapshot`
  3. إضافة اختبار تكاملي `testHistoryLegacyEndpointUsesCompatEnvelope` يغطي:
     - guest (`401`)
     - admin (`410`) مع تحقق payload الكامل.
- أوامر التحقق:
```powershell
php -l api/history.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. endpoint يبقى متقاعدًا وظيفيًا بنفس الرسالة.
  2. الالتزام بعقد API موحد لردود الفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/history.php` على `wbgl_api_compat_fail` مع نفس payload التقاعد.
  2. تمت إضافة تغطية تكاملية لمسار التقاعد (`401/410`) مع فحص الحقول البديلة.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`38/38`, `845 assertions`, `errors=0`, `failures=0`).

---

### TASK A-300 (DONE) - توحيد Contract مسار `import-email` (بتوافق رجعي)
- الهدف: توحيد endpoint استيراد البريد (`.msg`) على `compat envelope` مع الحفاظ على payload النجاح الذي يرجعه `EmailImportService`.
- الملفات المستهدفة:
  - `api/import-email.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل method guard إلى `wbgl_api_compat_fail(405, 'Method Not Allowed')`.
  2. تحويل فشل الملف/الامتداد إلى `wbgl_api_compat_fail(400, ..., 'validation')`.
  3. تحويل نجاح المعالجة إلى `wbgl_api_compat_success($result)` مع الاحتفاظ بالمخرجات الأصلية.
  4. تحويل الاستثناءات العامة إلى `wbgl_api_compat_fail(500, ..., 'internal')`.
  5. إضافة اختبار تكاملي `testImportEmailUsesCompatEnvelope` يغطي:
     - guest (`401`)
     - method (`405`)
     - missing file (`400`)
     - امتداد غير مدعوم (`400`).
- أوامر التحقق:
```powershell
php -l api/import-email.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. بقاء السلوك الوظيفي لمسار import-email.
  2. توحيد العقود في النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/import-email.php` على `wbgl_api_compat_success/fail` مع إبقاء آلية `ob_clean()` لحماية المخرجات.
  2. تمت إضافة تغطية تكاملية لحدود المسار (صلاحية/طريقة/ملف).
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`39/39`, `873 assertions`, `errors=0`, `failures=0`).

---

### TASK A-310 (DONE) - توحيد Contract مساري `parse-paste` و`parse-paste-v2` (بتوافق رجعي)
- الهدف: إدخال مسارات التحليل النصي في العقد الموحد مع الحفاظ على payload الأصلي (`extracted`, `field_status`, `confidence`) في حالات الفشل.
- الملفات المستهدفة:
  - `api/parse-paste.php`
  - `api/parse-paste-v2.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحويل رفض `is_test_data` في وضع الإنتاج إلى `wbgl_api_compat_fail(403, ..., 'permission')`.
  2. تحويل مخرجات `ParseCoordinatorService`:
     - success => `wbgl_api_compat_success($result)`
     - failure => `wbgl_api_compat_fail(400, ..., $result, 'validation')`
  3. توحيد catch blocks في المسارين على `wbgl_api_compat_fail(400, ..., payload validation)`.
  4. إضافة اختبار تكاملي `testParsePasteEndpointsUseCompatEnvelopeForValidationErrors` يغطي:
     - guest (`401`) للمسارين.
     - validation (`400`) عند إرسال body فارغ للمسارين.
     - ثبات الحقول `extracted/field_status/confidence` في الرد.
- أوامر التحقق:
```powershell
php -l api/parse-paste.php
php -l api/parse-paste-v2.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. الحفاظ على منطق التحليل نفسه بدون تغيير سلوكي.
  2. توحيد عقد النجاح/الفشل في كلا المسارين.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/parse-paste.php` و`api/parse-paste-v2.php` على `wbgl_api_compat_success/fail`.
  2. تمت إضافة تغطية تكاملية لسيناريوهات guest والـ validation errors للمسارين.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`40/40`, `907 assertions`, `errors=0`, `failures=0`).

---

### TASK A-320 (DONE) - توحيد Contract مسار `import.php` (Excel Import Endpoint)
- الهدف: توحيد endpoint الاستيراد الرئيسي للـ Excel على `compat envelope` مع الحفاظ على payload النجاح الحالي (`data + message`) بدون كسر الواجهة.
- الملفات المستهدفة:
  - `api/import.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. إضافة method guard صريح (`POST` فقط) عبر `wbgl_api_compat_fail(405, 'Method Not Allowed')`.
  2. تحويل أخطاء الملف/الامتداد إلى `wbgl_api_compat_fail(400, ..., 'validation')`.
  3. تحويل رفض `is_test_data` في وضع الإنتاج إلى `wbgl_api_compat_fail(403, ..., 'permission')`.
  4. تحويل النجاح إلى `wbgl_api_compat_success([...])` مع الإبقاء على `data` و`message`.
  5. تحويل catch العام إلى `wbgl_api_compat_fail(500, ..., payload internal)`.
  6. إضافة اختبار تكاملي `testImportExcelEndpointUsesCompatEnvelope` يغطي:
     - guest (`401`)
     - method (`405`)
     - missing file (`400`)
     - bad extension (`400`).
- أوامر التحقق:
```powershell
php -l api/import.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. الحفاظ على منطق الاستيراد وسير المعالجة كما هو.
  2. توحيد عقد API في كل مسارات النجاح والفشل.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/import.php` على `wbgl_api_compat_success/fail` مع الحفاظ على هيكل payload الأصلي.
  2. تمت إضافة تغطية تكاملية لحدود endpoint بدون المساس بمسار الاستيراد الحقيقي للملف.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`41/41`, `935 assertions`, `errors=0`, `failures=0`).

---

### TASK A-330 (DONE) - توحيد Contract مساري `login` و`logout` (بتوافق رجعي)
- الهدف: إدخال مساري المصادقة في العقد الموحد مع الحفاظ على تجربة الواجهة (`message`, `user`) وسلوك redirect في `logout` للمتصفح.
- الملفات المستهدفة:
  - `api/login.php`
  - `api/logout.php`
  - `api/_bootstrap.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. تحديث `login.php` لاستخدام `wbgl_api_compat_success/fail` لكل الحالات:
     - CSRF invalid (`419`)
     - validation (`400`)
     - rate limit (`429` + `retry_after`)
     - invalid credentials (`401`)
     - success (`200`) مع الحفاظ على `message`, `user`, وحقول token.
  2. تحديث `logout.php` لاستخدام `wbgl_api_compat_success` عند طلبات API مع الحفاظ على redirect في non-API.
  3. إضافة خيار opt-out مضبوط في `_bootstrap.php` عبر `WBGL_API_SKIP_GLOBAL_CSRF` للمسارات الخاصة (مثل login/logout) للحفاظ على السلوك القديم بدون كسر.
  4. إضافة اختبار تكاملي `testLoginAndLogoutUseCompatEnvelope` يغطي:
     - فشل CSRF في login (`419`)
     - validation في login (`400`)
     - نجاح login مع إصدار token (`200`)
     - نجاح logout (`200`)
     - التحقق من إبطال token بعد logout (`401` على `/api/me.php`).
- أوامر التحقق:
```powershell
php -l api/_bootstrap.php
php -l api/login.php
php -l api/logout.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم كسر تجربة تسجيل الدخول الحالية في الواجهة.
  2. توحيد contract للمسارين مع توافق رجعي للحقول المستهلكة.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `api/login.php` و`api/logout.php` على `wbgl_api_compat_success/fail` مع الحفاظ على payloadات legacy.
  2. تم إضافة دعم opt-out آمن لـ CSRF global في `_bootstrap.php` لحالات خاصة.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`42/42`, `976 assertions`, `errors=0`, `failures=0`).

---

### TASK A-340 (DONE) - توحيد مسارات `export_*` بصيغة Hybrid آمنة
- الهدف: إغلاق مسارات التصدير الثلاثة بدون كسر عملاء التحميل الحاليين:
  - success يبقى raw JSON attachment (بدون envelope)
  - failure يتحول إلى `compat fail envelope`
- الملفات المستهدفة:
  - `api/export_banks.php`
  - `api/export_suppliers.php`
  - `api/export_matching_overrides.php`
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. الحفاظ على payload النجاح الحالي في endpoints الثلاثة كما هو (قائمة raw).
  2. توحيد catch/failure إلى `wbgl_api_compat_fail(500, ..., 'internal')`.
  3. إزالة `Content-Disposition` في مسار الفشل (`header_remove`) حتى لا يُرسل خطأ بصيغة attachment.
  4. إضافة اختبار تكاملي `testExportEndpointsKeepRawPayloadAndRespectAuthBoundaries` يغطي:
     - guest (`401`)
     - admin (`200`) مع التحقق أن success payload بقي raw list (ليس envelope).
- أوامر التحقق:
```powershell
php -l api/export_banks.php
php -l api/export_suppliers.php
php -l api/export_matching_overrides.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم كسر صيغة التصدير المعتمدة حاليًا.
  2. توحيد أخطاء التصدير في envelope موحد.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم اعتماد نمط Hybrid لمسارات `export_*`: raw success + compat failure.
  2. تمت إضافة تغطية تكاملية تثبت حدود المصادقة وثبات payload النجاح الخام.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`43/43`, `1000 assertions`, `errors=0`, `failures=0`).

---

### TASK A-350 (DONE) - استكمال إغلاق موجة A لمساري `manual-entry` و`suggestions-learning`
- الهدف: إغلاق آخر جيوب الاستجابات اليدوية داخل مسارات API غير المغطاة سابقًا.
- الملفات المستهدفة:
  - `api/manual-entry.php`
  - `api/suggestions-learning.php`
  - `api/import.php` (fatal shutdown fallback)
  - `tests/Integration/EnterpriseApiFlowsTest.php`
- خطوات التنفيذ:
  1. توحيد `manual-entry` على `wbgl_api_compat_success/fail` مع:
     - method guard (`405`)
     - validation (`400`) للـ payload الفارغ.
  2. تحويل missing-param في `suggestions-learning` إلى `wbgl_api_compat_fail(400, ...)`.
  3. تحويل fatal fallback في `import.php` إلى `wbgl_api_compat_fail(500, ...)`.
  4. إضافة تغطية تكاملية:
     - `testManualEntryUsesCompatEnvelopeForMethodAndValidation`
     - `testSuggestionsLearningUsesCompatEnvelopeForValidation`
- أوامر التحقق:
```powershell
php -l api/manual-entry.php
php -l api/suggestions-learning.php
php -l api/import.php
php -l tests/Integration/EnterpriseApiFlowsTest.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم وجود ردود JSON يدوية جديدة خارج الاستثناءات المقررة.
  2. توحيد method/validation/error contracts للمسارات المذكورة.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم توحيد `manual-entry` و`suggestions-learning` وإزالة الردود اليدوية منهما.
  2. تم توحيد fallback الحرج في `import.php` عند fatal errors.
  3. التحقق ناجح: `Unit` أخضر (`118/118`, `894 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`, `errors=0`, `failures=0`).

---

### TASK E-010 (DONE) - إضافة حارس استدامة لعقود API
- الهدف: منع regression بعد إغلاق موجة A عبر اختبار وحدات آلي يفشل مباشرة إذا عاد أي endpoint إلى `echo json_encode` خارج الاستثناءات.
- الملفات المستهدفة:
  - `tests/Unit/ApiContractGuardWiringTest.php`
- خطوات التنفيذ:
  1. إضافة فحص آلي لكل ملفات `api/` يمنع `echo json_encode(` خارج allowlist مضبوطة.
  2. إضافة فحص آلي يضمن أن `WBGL_API_SKIP_GLOBAL_CSRF` معرف فقط في `login/logout`.
  3. إدخال الاختبار ضمن `Unit` suite ليعمل تلقائيًا في كل دورة تحقق.
- أوامر التحقق:
```powershell
php -l tests/Unit/ApiContractGuardWiringTest.php
php vendor/bin/phpunit --testsuite Unit
```
- معيار القبول:
  1. اكتشاف أي رجوع لنمط الردود اليدوية مبكرًا.
  2. منع توسع استثناء تجاوز CSRF global خارج المسارات المقصودة.

- ملخص التنفيذ:
  1. تمت إضافة `ApiContractGuardWiringTest` كحارس معماري دائم.
  2. تم ضبطه لتمرير الحالة الحالية وإيقاف أي regression مستقبلي.
  3. ضمن آخر تشغيل: `Unit` أخضر (`118/118`) ويدمج الحارس الجديد بشكل ناجح.

---

### TASK E-020 (DONE) - توحيد `wbgl_api_fail` مركزيًا على `compat fail`
- الهدف: إزالة آخر مصدر تباين في أخطاء المصادقة/الصلاحيات عبر تحويل helper المركزي القديم إلى نفس عقد `compat`.
- الملفات المستهدفة:
  - `api/_bootstrap.php`
- خطوات التنفيذ:
  1. الإبقاء على مسار التدقيق الأمني (AuditTrail) كما هو داخل `wbgl_api_fail`.
  2. استبدال `wbgl_api_envelope` داخل `wbgl_api_fail` بـ `wbgl_api_compat_fail`.
  3. تمرير `error_type` حسب `wbgl_api_error_type_from_status` لضمان اتساق كل الأكواد.
- أوامر التحقق:
```powershell
php -l api/_bootstrap.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. أي endpoint ما زال يستخدم `wbgl_api_fail` يخرج بنفس contract المتوافق.
  2. عدم كسر سلوك التدقيق الأمني الحالي.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم تحويل `wbgl_api_fail` إلى wrapper فوق `wbgl_api_compat_fail` مع الحفاظ على `AuditTrail`.
  2. تم توحيد `error_type` مركزيًا لكل أخطاء helper القديم.
  3. التحقق ناجح: `Unit` أخضر (`118/118`, `894 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`, `errors=0`, `failures=0`).

---

### TASK F-010 (DONE) - تفعيل ملفات التنفيذ المتسلسل (Manifest/State/Log)
- الهدف: إعادة تفعيل مسار `sequential-execution` من CLI بعد أن كان معطلاً لغياب ملفاته المرجعية.
- الملفات المستهدفة:
  - `Docs/WBGL-EXECUTION-SEQUENCE-AR.json`
  - `Docs/WBGL-EXECUTION-STATE-AR.json`
  - `Docs/WBGL-EXECUTION-LOG-AR.md`
- خطوات التنفيذ:
  1. إنشاء manifest رسمي بنسخة `1.0.0` يربط المراحل P1..P5 بـ coverage IDs الفعلية.
  2. إنشاء state أولية متطابقة مع الواقع الحالي (كل الخطوات مكتملة مع evidence/refs صالحة).
  3. إنشاء execution log تاريخي متسق مع state.
  4. إصلاح مراجع state/log لتفادي أي ref لملفات محذوفة.
  5. التحقق العملي عبر:
     - `php app/Scripts/sequential-execution.php status`
     - `php app/Scripts/sequential-execution.php guard`
- أوامر التحقق:
```powershell
php app/Scripts/sequential-execution.php status
php app/Scripts/sequential-execution.php guard
```
- معيار القبول:
  1. عدم ظهور خطأ `Manifest not found`.
  2. أمر `status` يعكس حالة مكتملة متسقة.
  3. أمر `guard` يمر بنجاح.

- ملخص التنفيذ:
  1. تم إنشاء ملفات manifest/state/log المطلوبة وتشغيل مسار CLI المتسلسل فعليًا.
  2. تم حل تعثر `guard` الناتج عن ref لملف محذوف عبر تحديث المراجع.
  3. الحالة النهائية: `status` يعمل، و`guard` أخضر (`Guard passed: sequential state is valid.`).

---

### TASK F-020 (DONE) - ربط `sequential-execution guard` مع CI الرئيسي
- الهدف: ضمان فشل مبكر في CI عند أي انحراف في حالة التنفيذ المتسلسل قبل تشغيل المايغريشن/الاختبارات.
- الملفات المستهدفة:
  - `.github/workflows/ci.yml`
- خطوات التنفيذ:
  1. إضافة خطوة `Validate sequential execution state` داخل job `php-tests`.
  2. تنفيذ `php app/Scripts/sequential-execution.php guard` بعد تثبيت الاعتمادات وقبل `migrate/tests`.
  3. الإبقاء على `release-readiness` و`change-gate` كما هما (كانا مرتبطين بالـ guard مسبقًا).
- معيار القبول:
  1. `ci.yml` يحتوي guard step واضحة في pipeline الرئيسي.
  2. أي انحراف في state/refs يوقف CI مبكرًا.

- ملخص التنفيذ:
  1. تم تحديث `ci.yml` بإضافة خطوة guard داخل `php-tests`.
  2. أصبح guard مفعّلًا الآن في: `change-gate` + `release-readiness` + `ci`.
  3. النتيجة: تغطية تشغيلية متسقة لمسار التنفيذ المتسلسل عبر كل البوابات الأساسية.

---

### TASK F-030 (DONE) - تفعيل دورة v1.1 وضبط Change Gate للعمل المستمر
- الهدف: بدء دورة تنفيذ جديدة دون كسر الحوكمة، عبر ترقية manifest إلى `v1.1.0` وتعديل شرط البوابة من `all-done only` إلى `active-loop`.
- الملفات المستهدفة:
  - `Docs/WBGL-EXECUTION-SEQUENCE-AR.json`
  - `Docs/WBGL-EXECUTION-STATE-AR.json`
  - `Docs/WBGL-EXECUTION-LOG-AR.md`
  - `.github/workflows/change-gate.yml`
- خطوات التنفيذ:
  1. ترقية manifest إلى `1.1.0` وإضافة مراحل `P6..P8` (G-010..G-050).
  2. تفعيل state تلقائيًا عبر `sequential-execution.php status` لتعيين أول خطوة جديدة `P6-01` كـ `in_progress`.
  3. تحديث `change-gate` بحيث:
     - يمنع وجود `blocked`.
     - يفرض `exactly one in_progress` عند وجود `pending`.
     - يسمح بحالة `all done` أو `active loop` بدل فرض `all done` فقط.
  4. التحقق عبر:
     - `php app/Scripts/sequential-execution.php status`
     - `php app/Scripts/sequential-execution.php guard`
- معيار القبول:
  1. بدء دورة v1.1 فعليًا مع خطوة نشطة واضحة.
  2. استمرار صرامة البوابة بدون تجميد التطوير.
  3. guard يمر بنجاح.

- ملخص التنفيذ:
  1. تم إطلاق `v1.1.0` مع تفعيل `P6-01` كخطوة نشطة (`WIP`) وإضافة entry في execution log.
  2. تم تحديث `change-gate` لدعم نمط التنفيذ المتسلسل المستمر بدل الإغلاق التام فقط.
  3. الحالة النهائية: `status` يظهر `5/10 done` و`Active step: P6-01`، و`guard` أخضر.

---

### ما تبقى من موجة A
- لا يوجد. موجة A أُغلقت بالكامل (مع اعتماد Hybrid export pattern كقرار تنفيذي).

### TASK B-010 (DONE) - تفكيك `save-and-next` إلى خدمة تطبيقية (الدفعة 1)
- الهدف: تقليل هشاشة endpoint الأكبر بدون تغيير العقد الخارجي.
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `app/Services/` (إضافة خدمة تطبيقية جديدة)
  - `tests/Integration/EnterpriseApiFlowsTest.php` (توسعة تحقق)
- خطوات التنفيذ:
  1. إنشاء خدمة تطبيقية جديدة (مثال: `SaveAndNextApplicationService`).
  2. نقل أول كتلة orchestration كبيرة من endpoint إلى الخدمة.
  3. إبقاء endpoint كـ adapter فقط (قراءة input + استدعاء الخدمة + response).
  4. إعادة التشغيل على نفس حالات التكامل الحالية.
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. السلوك الخارجي unchanged.
  2. انخفاض التعقيد داخل `api/save-and-next.php` بشكل واضح (نقل block فعلي وليس تجميلي).

- ملخص التنفيذ:
  1. تمت إضافة خدمة تطبيقية جديدة: `app/Services/SaveAndNextApplicationService.php`.
  2. تم استخراج كتلة orchestration الخاصة ببناء استجابة `finished/next record` من endpoint إلى الخدمة.
  3. تم إبقاء `api/save-and-next.php` كـ adapter لنفس العقد الخارجي، والاختبارات `Unit + Integration` خضراء بعد التغيير.
  4. تم استخراج كتلة `supplier/bank resolution + change detection` بالكامل إلى الخدمة مع نفس رسائل الأخطاء والسلوك.

---

### TASK B-020 (DONE) - تفكيك `save-and-next` إلى خدمة تطبيقية (الدفعة 2)
- الهدف: نقل منطق الـ mutation والـ side-effects (timeline + learning) من endpoint إلى خدمة تطبيقية مع إبقاء العقد الخارجي ثابتًا.
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `app/Services/SaveAndNextApplicationService.php`
- خطوات التنفيذ:
  1. استخراج كتلة حفظ القرار في `guarantee_decisions` إلى الخدمة.
  2. استخراج تسجيل أحداث timeline (decision/status transition) إلى الخدمة.
  3. استخراج feedback loop الخاص بالتعلم إلى الخدمة.
  4. إبقاء endpoint كـ adapter (input/policy/navigation/response).
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l app/Services/SaveAndNextApplicationService.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير السلوك الخارجي لـ `save-and-next`.
  2. اختبارات Unit + Integration خضراء بالكامل.

- ملخص التنفيذ:
  1. تم إضافة `SaveAndNextApplicationService::persistDecisionAndRecord(...)` وتجميع منطق `decision upsert + active_action clear + timeline + learning` داخلها.
  2. تم تقليص `api/save-and-next.php` بإزالة الكتلة الثقيلة واستبدالها باستدعاء خدمة واحد.
  3. التحقق ناجح: `Unit` أخضر (`116/116`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`).

---

### TASK B-030 (DONE) - تفكيك `save-and-next` إلى خدمة تطبيقية (الدفعة 3)
- الهدف: إخراج منطق الملاحة (تحديد `nextId` وبناء استجابة `finished/next`) من endpoint إلى الخدمة.
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `app/Services/SaveAndNextApplicationService.php`
- خطوات التنفيذ:
  1. إضافة دالة خدمة موحدة لاستجابة ما بعد الحفظ.
  2. نقل استخدام `NavigationService::getNavigationInfo` من endpoint إلى الخدمة.
  3. إبقاء endpoint كـ adapter يمرر الإدخال والسياق فقط.
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l app/Services/SaveAndNextApplicationService.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس العقد الخارجي لـ `save-and-next` بدون تغيير.
  2. اختبارات Unit + Integration خضراء بالكامل.

- ملخص التنفيذ:
  1. تمت إضافة `SaveAndNextApplicationService::buildPostSaveResponse(...)` لتجميع منطق الملاحة وبناء `finished/next`.
  2. تم تبسيط `api/save-and-next.php` بإزالة منطق `nextId` المباشر والاكتفاء باستدعاء الخدمة.
  3. التحقق ناجح: `Unit` أخضر (`116/116`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`).

---

### TASK B-040 (DONE) - تفكيك `save-and-next` إلى خدمة تطبيقية (الدفعة 4)
- الهدف: نقل بوابات الأهلية (`surface + mutation policy`) من endpoint إلى الخدمة لتقليل التشابك في `api/save-and-next.php`.
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `app/Services/SaveAndNextApplicationService.php`
  - `tests/Unit/Services/GovernancePolicyWiringTest.php` (مواءمة wiring بعد النقل)
- خطوات التنفيذ:
  1. إضافة `resolveCurrentWorkflowStep(...)` في الخدمة بدل closure داخل endpoint.
  2. إضافة `ensureMutationAllowed(...)` في الخدمة لتجميع:
     - فحص `can_execute_actions`
     - تحميل السجل الحالي
     - تقييم `GuaranteeMutationPolicyService::evaluate`
  3. تعديل endpoint ليصبح adapter أنحف: قراءة input + استدعاء الخدمة + compat response.
  4. حذف اعتماد `resolveDecisionInputs` على callback، والاكتفاء باستدعاء helper داخلي من الخدمة.
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l app/Services/SaveAndNextApplicationService.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. عدم تغيير السلوك الخارجي لـ `save-and-next`.
  2. نقل فحوصات الأهلية/السياسة من endpoint إلى الخدمة بشكل قابل للاختبار.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تم نقل منطق الأهلية إلى `SaveAndNextApplicationService::ensureMutationAllowed(...)` وإضافة `resolveCurrentWorkflowStep(...)`.
  2. تم تبسيط `api/save-and-next.php` بإزالة تقييمات policy المباشرة والـ closure المحلي.
  3. تم تحديث اختبار الحوكمة ليتتبع wiring في الخدمة بعد النقل.
  4. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`, `errors=0`, `failures=0`).

---

### TASK B-050 (DONE) - تفكيك `save-and-next` إلى خدمة تطبيقية (الدفعة 5)
- الهدف: تحويل endpoint إلى adapter شبه خالص عبر مسار تنفيذ واحد داخل الخدمة.
- الملفات المستهدفة:
  - `api/save-and-next.php`
  - `app/Services/SaveAndNextApplicationService.php`
- خطوات التنفيذ:
  1. إضافة `executeSaveAndNext(...)` في الخدمة لتجميع السلسلة كاملة:
     - `ensureMutationAllowed`
     - `resolveDecisionInputs`
     - `persistDecisionAndRecord`
     - `buildPostSaveResponse`
  2. تبسيط endpoint ليقوم فقط بـ:
     - قراءة input
     - فحص login/visibility/permission
     - استدعاء `executeSaveAndNext`
     - إخراج `wbgl_api_compat_success/fail`
- أوامر التحقق:
```powershell
php -l api/save-and-next.php
php -l app/Services/SaveAndNextApplicationService.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. نفس العقد والسلوك الخارجي للمسار.
  2. انخفاض إضافي في تعقيد endpoint.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تمت إضافة `SaveAndNextApplicationService::executeSaveAndNext(...)` كمسار orchestration موحد.
  2. تم تقليص `api/save-and-next.php` إلى adapter صغير (~`67` سطر) مع compat envelope موحد.
  3. التحقق ناجح: `Unit` أخضر (`116/116`, `831 assertions`) و`EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`, `errors=0`, `failures=0`).

---

### TASK C-010 (DONE) - baseline migration PostgreSQL
- الهدف: تمكين بناء DB من الصفر بثقة.
- الملفات المستهدفة:
  - `database/migrations/` (إضافة baseline)
  - `app/Scripts/migrate.php` (تحقق فقط)
  - `app/Scripts/migration-status.php` (تحقق فقط)
- خطوات التنفيذ:
  1. إنشاء ملف baseline schema شامل للجداول الأساسية.
  2. مراجعة توافق SQL مع PostgreSQL فقط.
  3. إبقاء المهاجرات اللاحقة incremental كما هي.
  4. تنفيذ rehearsal على قاعدة فارغة.
- أوامر التحقق:
```powershell
php app/Scripts/migration-status.php
php app/Scripts/migrate.php --dry-run
php app/Scripts/migrate.php
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. `migrate` ينجح من قاعدة فارغة بدون تدخل يدوي.
  2. اختبار التكامل ينجح بعد البناء الجديد.

- ملخص التنفيذ:
  1. تمت إضافة baseline رسمي: `database/migrations/20260225_000000_pgsql_baseline_core_schema.sql` يغطي الجداول الأساسية + seed للأدوار/الصلاحيات + role-permission matrix، مع idempotency (`IF NOT EXISTS` / `ON CONFLICT`).
  2. تم تطبيق baseline مع كل الـ incremental migrations المعلقة بنجاح عبر `php app/Scripts/migrate.php`، وأصبحت الحالة `Pending: 0` في `migration-status`.
  3. تم التحقق التشغيلي بعد التطبيق: `EnterpriseApiFlowsTest` أخضر (`20/20`) و`Unit` أخضر (`116/116`).
  4. تم حل قيد `CREATEDB` عبر إعداد قاعدة ثابتة `wbgl_rehearsal` بواسطة DBA/superuser.
  5. تم إضافة سكربت rehearsal آمن: `php app/Scripts/rehearse-migrations.php` (يعيد تهيئة `public schema` ثم يطبق المايغريشن).
  6. تم إثبات empty-db rehearsal فعليًا: `migrate` أخضر + `migration-status` (`Pending: 0`) + `EnterpriseApiFlowsTest` أخضر (`20/20`, `213 assertions`) على قاعدة rehearsal.

---

### TASK D-010 (DONE) - تنظيف Legacy/Dead Code (دفعات صغيرة)
- الهدف: إزالة التشويش البنيوي تدريجيًا بدون كسر runtime.
- مرشح البداية:
  - `app/Services/AutoAcceptService.php` (تحقق قابلية الحذف أو العزل)
- خطوات التنفيذ:
  1. إثبات عدم الاستخدام عبر البحث والاختبارات.
  2. إما حذف آمن أو وضع deprecation واضح.
  3. إعادة تشغيل Unit + Integration.
- أوامر التحقق:
```powershell
rg -n "AutoAcceptService|LearningLogRepository|updateDecision\\(" app api tests
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا reference حي للكود المتقادم.
  2. لا regression بعد التنظيف.

- ملخص التنفيذ:
  1. تم إثبات أن `app/Services/AutoAcceptService.php` يتيم بالكامل (لا يوجد أي استدعاء حي) ويعتمد على `LearningLogRepository` غير موجود في المشروع.
  2. تم حذف `AutoAcceptService.php` كتنظيف آمن للـ dead code.
  3. تحقق ما بعد الحذف: `Unit` أخضر (`116/116`) و`EnterpriseApiFlowsTest` أخضر (`20/20`) بدون regressions.

---

## 3.1) المرحلة التالية v1.1 (مكتملة)

### TASK G-010 (DONE) - فصل بناء `record-form` عن endpoint `get-record`
- الهدف: تقليل تعقيد `api/get-record.php` عبر استخراج rendering/context assembly إلى خدمة/Presenter مع إبقاء نفس مخرجات HTML.
- الملفات المستهدفة:
  - `api/get-record.php`
  - `app/Services/` (إضافة presenter/service)
  - `tests/Integration/EnterpriseApiFlowsTest.php` (حالات smoke على الـ read path)
- أوامر التحقق:
```powershell
php -l api/get-record.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير وظيفي في HTML الناتج للمسار.
  2. انخفاض ملموس في منطق orchestration داخل endpoint.
  3. `Unit + Integration` خضراء بالكامل.

- ملخص التنفيذ:
  1. تمت إضافة خدمة جديدة `app/Services/GetRecordPresentationService.php` لتجميع hydration + matching + rendering لمسار `get-record`.
  2. تم تقليص `api/get-record.php` ليعمل كـ adapter (parsing + policy gate + service call) بدون منطق العرض الداخلي الثقيل.
  3. التحقق ناجح: `Unit` أخضر (`118/118`, `894 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`, `errors=0`, `failures=0`).

### TASK G-020 (DONE) - فصل timeline/history rendering عن endpoints الخاصة بها
- الهدف: نقل منطق بناء snapshot/timeline من `api/get-timeline.php` و`api/get-history-snapshot.php` إلى خدمات متخصصة.
- الملفات المستهدفة:
  - `api/get-timeline.php`
  - `api/get-history-snapshot.php`
  - `app/Services/`

- ملخص التنفيذ:
  1. تمت إضافة `app/Services/TimelineReadPresentationService.php` لتجميع منطق قراءة/عرض timeline وhistory snapshot.
  2. تم تحويل `api/get-timeline.php` و`api/get-history-snapshot.php` إلى adapters نحيفة تعتمد service واحدة مع إبقاء سلوك الوصول/الردود.
  3. تم تحديث اختبارات wiring لتدعم النمط المستخرج، والتحقق ناجح: `Unit` أخضر (`118/118`, `896 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`).

### TASK G-030 (DONE) - توحيد transaction boundaries لعمليات lifecycle الحرجة
- الهدف: تعريف حدود معاملات صريحة وموحدة لمسارات `extend/reduce/release/reopen/save-and-next`.
- الملفات المستهدفة:
  - `api/extend.php`
  - `api/reduce.php`
  - `api/release.php`
  - `api/reopen.php`
  - `api/save-and-next.php`

- ملخص التنفيذ:
  1. تمت إضافة `app/Support/TransactionBoundary.php` كغلاف موحد للمعاملات مع دعم الحالات المتداخلة (`inTransaction`).
  2. تم تطبيق حدود المعاملة على المسارات الحرجة: `extend/reduce/release` + `SaveAndNextApplicationService` + `UndoRequestService` (مساري execute/direct reopen).
  3. تمت إضافة حارس wiring جديد (`TransactionBoundaryWiringTest`) والتحقق ناجح: `Unit` أخضر (`120/120`, `912 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`).

### TASK G-040 (DONE) - إضافة فحص سلامة بيانات وتشغيله ضمن CI
- الهدف: إضافة script لفحص invariants القاعدية الحرجة (status/snapshot/actionability) وربطه ضمن workflow.
- الملفات المستهدفة:
  - `app/Scripts/`
  - `.github/workflows/ci.yml`

- ملخص التنفيذ:
  1. تمت إضافة `app/Scripts/data-integrity-check.php` لفحص invariants الحرجة (decision status/action domains, ready completeness, released lock, undo integrity).
  2. تم ربط الفحص داخل CI (`.github/workflows/ci.yml`) بعد اختبارات التكامل لضمان gate تشغيلي قبل النجاح.
  3. التحقق ناجح: `data-integrity-check` أخضر (`Fail violations: 0`) + `Unit` أخضر (`120/120`, `912 assertions`) + `EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`).

### TASK G-050 (DONE) - إغلاق التوثيق التشغيلي للمرحلة v1.1
- الهدف: تحديث docs/state/log بالأدلة النهائية بعد إنهاء مهام G-010..G-040.
- الملفات المستهدفة:
  - `Docs/WBGL-EXECUTION-STATE-AR.json`
  - `Docs/WBGL-EXECUTION-LOG-AR.md`
  - `Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md`

- ملخص التنفيذ:
  1. تم إغلاق مسار v1.1 بالكامل في خطة التنفيذ مع تحويل جميع مهام G إلى `DONE`.
  2. تم تحديث state/log عبر `sequential-execution` مع أدلة لكل خطوة (`P6-01`..`P7-02`) والانتقال للخطوة النهائية.
  3. الحالة النهائية للمرحلة: جميع خطوات v1.1 مكتملة وقابلة للتحقق عبر `status/guard` قبل الدمج.

---

## 3.2) المرحلة التالية v1.2 (جارية)

### TASK H-010 (IN_PROGRESS) - فصل منطق بيانات شاشة `statistics` إلى خدمة مخصصة
- الهدف: تقليل تعقيد `views/statistics.php` عبر نقل استعلامات وتجميع بيانات اللوحة إلى `StatisticsDashboardService`.
- الملفات المستهدفة:
  - `views/statistics.php`
  - `app/Services/StatisticsDashboardService.php` (جديد/توسعة)
  - `tests/Unit/Services/` (اختبار wiring)
- أوامر التحقق:
```powershell
php -l views/statistics.php
php -l app/Services/StatisticsDashboardService.php
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/EnterpriseApiFlowsTest.php --log-junit storage/logs/phpunit-enterprise.xml
```
- معيار القبول:
  1. لا تغيير وظيفي ظاهر في صفحة الإحصائيات.
  2. انخفاض ملموس في حجم منطق SQL داخل `views/statistics.php`.
  3. `Unit + Integration` خضراء بالكامل.

- تقدم التنفيذ الحالي:
  1. تم إنشاء `app/Services/StatisticsDashboardService.php`.
  2. تم نقل كتلة `overview metrics` وحساب `efficiencyRatio` من `views/statistics.php` إلى الخدمة.
  3. تم نقل كتلة `batch + suppliers/banks` إلى الخدمة (`fetchBatchAndSupplierBlocks`) مع إبقاء السلوك كما هو.
  4. تم نقل كتلة `time/performance` إلى الخدمة (`fetchTimePerformanceBlocks`) مع الإبقاء على حسابات العرض نفسها داخل الصفحة.
  5. تم نقل كتلة `expiration/actions` إلى الخدمة (`fetchExpirationActionBlocks`) مع الحفاظ على نفس ناتج المؤشرات.
  6. تمت إضافة/تحديث اختبار wiring (`StatisticsDashboardWiringTest`) والتحقق ناجح: `Unit` أخضر (`121/121`, `926 assertions`) و`EnterpriseApiFlowsTest` أخضر (`45/45`, `1033 assertions`).

### TASK H-020 (PENDING) - فصل منطق بيانات شاشة `settings` إلى خدمة قراءة/حوكمة
- الهدف: نقل استعلامات الإعدادات والتدقيق من `views/settings.php` إلى خدمة واضحة الحدود.
- الملفات المستهدفة:
  - `views/settings.php`
  - `app/Services/SettingsDashboardService.php` (جديد/توسعة)

### TASK H-030 (PENDING) - تقرير دوري لانحراف الصلاحيات وربطه ضمن CI
- الهدف: كشف drift في role-permission matrix وإخراج تقرير واضح قبل الدمج.
- الملفات المستهدفة:
  - `app/Scripts/permissions-drift-report.php`
  - `.github/workflows/ci.yml`
  - `Docs/` (artifact summary)

### TASK H-040 (PENDING) - توسيع `data-integrity-check` وإضافة artifact تفصيلي
- الهدف: توسيع invariants وفصل إخراج النتائج إلى JSON/Markdown قابل للتدقيق الإداري.
- الملفات المستهدفة:
  - `app/Scripts/data-integrity-check.php`
  - `.github/workflows/ci.yml`
  - `Docs/` (نتائج الفحص)

### TASK H-050 (PENDING) - إغلاق التوثيق التشغيلي للمرحلة v1.2
- الهدف: تحديث docs/state/log بالأدلة النهائية بعد إنهاء `H-010..H-040`.
- الملفات المستهدفة:
  - `Docs/WBGL-EXECUTION-STATE-AR.json`
  - `Docs/WBGL-EXECUTION-LOG-AR.md`
  - `Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md`

---

## 4) ممنوعات التنفيذ (Hard No)
1. نقل شامل مبكر للشجرة (`app/Domain`, `app/Application`...) قبل إنهاء مهام A وB.
2. حذف `api/*.php` القديمة قبل وجود بديل متوافق ومختبر.
3. استخدام أوامر غير متوافقة مع PostgreSQL (مثل `mysqldump`).
4. دمج إعادة هيكلة جذرية وميزة عميل كبيرة في نفس الدفعة.

## 5) آلية تحديث هذه الوثيقة
1. عند بدء أي مهمة: غيّر حالتها إلى `IN_PROGRESS`.
2. عند اكتمالها: غيّر حالتها إلى `DONE` وأضف ملخص 3 أسطر تحتها.
3. لا يجوز بدء مهمة جديدة قبل إغلاق المهمة السابقة في التسلسل.
