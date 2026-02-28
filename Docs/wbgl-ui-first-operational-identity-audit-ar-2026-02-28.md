# تدقيق WBGL التشغيلي (UI-First) — 2026-02-28

نطاق هذا التقرير: مبني فقط على كود المستودع (UI templates + JS + APIs + services + migrations). لا يعتمد على نوايا المؤلف.

## المرحلة 0 — خريطة الواجهة أولًا

### الأسطح الرئيسية الظاهرة
- لوحة التشغيل الرئيسية `index.php` مع البحث، الفلاتر، العدادات، التنقل بين السجلات، وشريط إدخال العمليات (`index.php:564-623`, `index.php:800-900`, `index.php:805-835`).
- Workstation بطاقة الضمان وأزرار القرار (`partials/record-form.php:33-45`, `partials/record-form.php:78-99`, `partials/record-form.php:139-149`).
- Timeline sidebar وعرض الأحداث (`partials/timeline-section.php`, تحميل ديناميكي من `public/js/records.controller.js:928-946`).
- Manual Entry modal (`partials/manual-entry-modal.php:193`, `public/js/input-modals.controller.js:49-92`).
- Smart Paste modal (`partials/paste-modal.php:68`, `public/js/input-modals.controller.js:95-298`).
- Excel Import modal/hidden input (`partials/excel-import-modal.php`, `index.php:552`, `index.php:899`, `public/js/input-modals.controller.js:309-371`, `public/js/input-modals.controller.js:449-493`).
- قائمة الدفعات `views/batches.php` (`views/batches.php:20-35`).
- صفحة تفاصيل الدفعة `views/batch-detail.php` (إغلاق/إعادة فتح/Bulk/Metadata/Print) (`views/batch-detail.php:223-253`, `views/batch-detail.php:492-653`).
- الإعدادات `views/settings.php` (tabs + CRUD + save settings) (`views/settings.php:205-210`, `views/settings.php:754-762`, `views/settings.php:1096-1099`).
- إدارة المستخدمين `views/users.php` (`views/users.php:471`, `views/users.php:586`, `views/users.php:617`).
- التقارير `views/statistics.php` (صفحة تقارير SQL مباشرة) (`views/statistics.php:11`, `views/statistics.php:61-71`, `views/statistics.php:81-97`).
- التنقل العلوي وسياسات الإخفاء حسب الصلاحيات (`partials/unified-header.php:209-243`, `public/js/nav-manifest.js:4-52`, `public/js/policy.js:73-133`).

### أعلى 12 عملية يومية (UI → Action → Endpoint → Service/SQL → DB)

**عملية 1: تصفية لوحة العمل والتنقل إلى السجل**
- ما يراه المستخدم: روابط فلترة (`all/ready/actionable/pending/released`) + عدادات + بحث (`index.php:805-835`, `partials/unified-header.php:182-190`).
- ما يفعله: يضغط رابط فلتر أو يكتب بحث؛ الصفحة تعيد تحميل نفسها.
- الضمانات/البوابات: دخول إلزامي (`index.php:6-10`)، وفلتر رؤية حسب الدور عبر `GuaranteeVisibilityService` داخل `NavigationService` (`app/Services/NavigationService.php:69-124`).
- البيانات المتغيرة: لا يوجد mutation مثبت.
- Trace: `index.php` (request page) → `app/Services/NavigationService.php:getNavigationInfo/getIdByIndex` (`:27-62`, `:223-253`) + SQL على `guarantees`, `guarantee_decisions`, `suppliers` (`:133-138`, `:239-244`).
- مطابقة التوقع: نعم (قراءة/فلترة فقط).

**عملية 2: تحميل سجل من الـWorkstation (By Index)**
- ما يراه المستخدم: زر "العودة للوضع الحالي" و/أو تنقل داخلي (`partials/record-form.php:60`, `public/js/records.controller.js:890-911`).
- ما يفعله: trigger `data-action="load-record"` ثم `fetch('/api/get-record.php?...')` (`public/js/records.controller.js:904`).
- الضمانات/البوابات: endpoint يتطلب login فقط (`api/get-record.php:16` + `api/_bootstrap.php:77-90`).
- البيانات المتغيرة: endpoint يكتب داخل `guarantee_decisions` عبر upsert (`api/get-record.php:35-82`, `api/get-record.php:229-240`, `api/get-record.php:303-314`) ويسجل timeline على `guarantee_history` (`api/get-record.php:250-255`, `api/get-record.php:328-329` عبر `TimelineRecorder`).
- Trace: UI trigger `partials/record-form.php:60` → JS `public/js/records.controller.js:890-905` → endpoint `api/get-record.php` → service/event `app/Services/TimelineRecorder.php:284-331`, `:718-738` → tables `guarantee_decisions`, `guarantee_history`.
- مطابقة التوقع: لا؛ المستخدم يتوقع قراءة، لكن يحصل mutation (Read-with-write).

**عملية 3: حفظ القرار والانتقال التالي (Save-and-Next)**
- ما يراه المستخدم: زر "💾 حفظ" (`partials/record-form.php:78`).
- ما يفعله: `fetch('/api/save-and-next.php')` (`public/js/records.controller.js:441-473`).
- الضمانات/البوابات: login فقط (`api/save-and-next.php:18`) + سياسة read-only عند released عبر `GuaranteeMutationPolicyService::evaluate` (`api/save-and-next.php:43-57`, `app/Services/GuaranteeMutationPolicyService.php:16-91`).
- البيانات المتغيرة: تحديث/إدراج `guarantee_decisions` (`api/save-and-next.php:404-443`)، تحديث `guarantees.raw_data` للبنك (`api/save-and-next.php:99-118`, `:336-337`)، تسجيل timeline في `guarantee_history` (`api/save-and-next.php:493-505`, `app/Services/TimelineRecorder.php:559`).
- Trace: UI `data-action="saveAndNext"` → JS `saveAndNext` → endpoint `/api/save-and-next.php` → SQL مباشر + `TimelineRecorder` + `LearningRepository` (`api/save-and-next.php:518-555`) → tables `guarantee_decisions`, `guarantees`, `guarantee_history`, learning tables.
- مطابقة التوقع: جزئيًا؛ يحفظ وينتقل فعلًا، لكن endpoint أيضًا قد ينشئ مورد تلقائيًا (`api/save-and-next.php:214-243`) وهي منطق مخفي للمستخدم.

**عملية 4: تمديد الضمان الفردي**
- ما يراه المستخدم: زر "🔄 تمديد" مع تعطيل بصري إذا ليس `ready` (`partials/record-form.php:82-87`, `:70-73`).
- ما يفعله: `fetch('/api/extend.php')` (`public/js/records.controller.js:521-535`).
- الضمانات/البوابات: permission `manage_data` (`api/extend.php:17`) + gate الحالة `ready` و lock check (`api/extend.php:52-86`) + break-glass عبر policy (`api/extend.php:37-45`).
- البيانات المتغيرة: `guarantees.raw_data.expiry_date` (`api/extend.php:103-108`)، `guarantee_decisions.active_action/decision_source` (`api/extend.php:111-129`)، timeline event في `guarantee_history` (`api/extend.php:131-137`, `app/Services/TimelineRecorder.php:178-207`).
- Trace: UI `data-action="extend"` → JS `extend()` → `/api/extend.php` → repo + SQL + `TimelineRecorder::recordExtensionEvent` → tables `guarantees`, `guarantee_decisions`, `guarantee_history`.
- مطابقة التوقع: نعم.

**عملية 5: تخفيض الضمان الفردي**
- ما يراه المستخدم: زر "📉 تخفيض" + prompt للمبلغ (`partials/record-form.php:88-93`, `public/js/records.controller.js:598-615`).
- ما يفعله: إدخال مبلغ ثم `fetch('/api/reduce.php')`.
- الضمانات/البوابات: permission `manage_data` (`api/reduce.php:16`) + تحقق أن المبلغ الجديد أقل من الحالي (`api/reduce.php:62-74`) + gate الحالة/lock (`api/reduce.php:77-111`) + break-glass policy (`api/reduce.php:47-60`).
- البيانات المتغيرة: `guarantees.raw_data.amount` (`api/reduce.php:123-129`)، `guarantee_decisions` metadata (`api/reduce.php:136-145`)، timeline (`api/reduce.php:147-153`, `app/Services/TimelineRecorder.php:219-245`).
- Trace: UI `data-action="reduce"` → JS `reduce()` → `/api/reduce.php` → repo + SQL + timeline → tables `guarantees`, `guarantee_decisions`, `guarantee_history`.
- مطابقة التوقع: نعم.

**عملية 6: الإفراج عن الضمان الفردي**
- ما يراه المستخدم: زر "📤 إفراج" (`partials/record-form.php:94-99`).
- ما يفعله: `fetch('/api/release.php')` (`public/js/records.controller.js:559-573`).
- الضمانات/البوابات: permission `manage_data` (`api/release.php:17`) + gate `status=ready` (`api/release.php:38-53`) + تحقق supplier/bank قبل الإفراج (`api/release.php:64-68`).
- البيانات المتغيرة: lock + status على `guarantee_decisions` (`api/release.php:75-95`) + timeline release event (`api/release.php:97-99`, `app/Services/TimelineRecorder.php:256-278`).
- Trace: UI `release` → JS → endpoint `/api/release.php` → `GuaranteeDecisionRepository::lock` (`app/Repositories/GuaranteeDecisionRepository.php:131-140`) + timeline → tables `guarantee_decisions`, `guarantee_history`.
- مطابقة التوقع: نعم.

**عملية 7: إعادة فتح/Undo**
- ما يراه المستخدم: أيقونة ✏️ لإعادة الفتح عند `ready/released` (`index.php:582-590`).
- ما يفعله: JS يرسل فقط `guarantee_id` إلى `/api/reopen.php` (`public/js/records.controller.js:346-350`).
- الضمانات/البوابات: permission `manage_data` (`api/reopen.php:17`)، direct reopen يتطلب `reopen_guarantee` أو break-glass (`api/reopen.php:42-44`)، وبدون break-glass يجب `reason` لإنشاء undo request (`api/reopen.php:46-52`).
- البيانات المتغيرة: إما `undo_requests` (submit) (`app/Services/UndoRequestService.php:13-33`) أو direct reopen على `guarantee_decisions` + timeline (`app/Services/UndoRequestService.php:196-217`)، مع تسجيل break-glass في `break_glass_events` (`app/Services/BreakGlassService.php:107-121`).
- Trace: UI `data-action="reopenRecord"` → JS `reopenRecord` → `/api/reopen.php` → `UndoRequestService`/`BreakGlassService` → tables `undo_requests`, `guarantee_decisions`, `guarantee_history`, `break_glass_events`.
- مطابقة التوقع: لا؛ UI لا يطلب reason بينما backend يفرضه عند المسار غير الطارئ.

**عملية 8: التقدم في Workflow**
- ما يراه المستخدم: زر `data-action="workflow-advance"` يظهر عند `WorkflowService::canAdvance` (`partials/record-form.php:116-149`).
- ما يفعله: `fetch('/api/workflow-advance.php')` (`public/js/main.js:266-283`).
- الضمانات/البوابات: endpoint login-only (`api/workflow-advance.php:16`) لكن الانتقال الفعلي gated بـ `WorkflowService::canAdvance` (permission-per-stage) (`api/workflow-advance.php:43-52`, `app/Services/WorkflowService.php:58-71`).
- البيانات المتغيرة: تحديث `guarantee_decisions.workflow_step/signatures_received` عبر `createOrUpdate` (`api/workflow-advance.php:81-85`, `app/Repositories/GuaranteeDecisionRepository.php:95-125`) + timeline event (`api/workflow-advance.php:87-93`, `app/Services/TimelineRecorder.php:791-815`).
- Trace: UI workflow button → JS main handler → endpoint `/api/workflow-advance.php` → `WorkflowService` + repository + timeline → tables `guarantee_decisions`, `guarantee_history`.
- مطابقة التوقع: نعم للانتقال المرحلي، لكن ليس مرتبطًا إجباريًا قبل extend/reduce/release (Bypass محتمل).

**عملية 9: الإدخال اليدوي**
- ما يراه المستخدم: modal يدوي + زر `btnSaveManualEntry` (`partials/manual-entry-modal.php:193`, `public/js/input-modals.controller.js:388-390`).
- ما يفعله: POST `/api/create-guarantee.php` (`public/js/input-modals.controller.js:75-79`).
- الضمانات/البوابات: permission `manual_entry` (`api/create-guarantee.php:18`) + validation حقول (`api/create-guarantee.php:37-48`).
- البيانات المتغيرة: إنشاء `guarantees` (`api/create-guarantee.php:95-114`)، insertion في `guarantee_occurrences` (`api/create-guarantee.php:117-120`)، timeline import (`api/create-guarantee.php:132-135`) + auto-match (`api/create-guarantee.php:136-140`).
- Trace: UI manual modal → JS `submitManualEntry` → `/api/create-guarantee.php` → repo + timeline + smart processing → tables `guarantees`, `guarantee_occurrences`, `guarantee_history`.
- مطابقة التوقع: جزئيًا.
- Unproven: بنية `guarantee_occurrences` غير موجودة في migrations الأساسية داخل `database/migrations`; كما أن هذا endpoint يكتب عمود `import_source` بينما `ImportService` يكتب `batch_type` (`api/create-guarantee.php:118` vs `app/Services/ImportService.php:576-577`).

**عملية 10: Smart Paste**
- ما يراه المستخدم: modal لصق + زر `btnProcessPaste` (`partials/paste-modal.php:68`, `public/js/input-modals.controller.js:405-407`).
- ما يفعله: POST `/api/parse-paste.php` (`public/js/input-modals.controller.js:115-126`).
- الضمانات/البوابات: login (`api/parse-paste.php:24`) + منع test-data في production (`api/parse-paste.php:37-46`).
- البيانات المتغيرة: إنشاء ضمانات جديدة أو اكتشاف مكرر داخل `ParseCoordinatorService` (`app/Services/ParseCoordinatorService.php:388-482`, `:487-567`)، تسجيل occurrences (`:466`, `:555`)، timeline import/duplicate (`:470`, `:499`, `:559`), trigger auto-match (`:573-583`).
- Trace: UI paste modal → JS `parsePasteData` → `/api/parse-paste.php` → `ParseCoordinatorService::parseText` (`:45-60`) → tables `guarantees`, `guarantee_occurrences`, `guarantee_history`.
- مطابقة التوقع: نعم إجمالًا.

**عملية 11: استيراد Excel**
- ما يراه المستخدم: زر ملف (أو hidden file input) (`index.php:881`, `index.php:552`, `public/js/input-modals.controller.js:342-345`, `:468-471`).
- ما يفعله: POST `/api/import.php` بملف Excel.
- الضمانات/البوابات: permission `import_excel` (`api/import.php:15`) + التحقق من الامتداد (`api/import.php:53-55`).
- البيانات المتغيرة: create guarantees + occurrences + duplicate events (`app/Services/ImportService.php:172-204`, `:567-580`)، timeline import (`api/import.php:90-99`)، ثم smart processing (`api/import.php:106-113`).
- Trace: UI import → JS upload handler → `/api/import.php` → `ImportService::importFromExcel` (`app/Services/ImportService.php:54-227`) + `TimelineRecorder` + `SmartProcessingService` → tables `guarantees`, `guarantee_occurrences`, `guarantee_history`, `batch_metadata`.
- مطابقة التوقع: نعم.

**عملية 12: عمليات الدفعات + الطباعة**
- ما يراه المستخدم: في `batch-detail` أزرار close/reopen/extend/release/metadata/print (`views/batch-detail.php:223-253`, `:570-653`).
- ما يفعله: POST `/api/batches.php` مع `action` (`views/batch-detail.php:446-470`) + print window (`views/batch-detail.php:647-651`).
- الضمانات/البوابات: `manage_data` (`api/batches.php:15`)، reopen يتطلب reason دائمًا + صلاحية `reopen_batch` أو break-glass (`api/batches.php:119-137`)، policy enforcement داخل batch methods (ready/locked/closed) (`app/Services/BatchService.php:87-109`, `:150-157`, `:325-342`, `:494-501`).
- البيانات المتغيرة: `guarantees.raw_data` + `guarantee_decisions` + `guarantee_history` لكل عنصر (`app/Services/BatchService.php:199-228`, `:352-376`, `:541-562`)، حالة الدفعة `batch_metadata` (`:744-751`, `:786-789`)، تدقيق دفعات `batch_audit_events` (`:755-761`, `:798-806` + `app/Services/BatchAuditService.php:34-44`)، وتدقيق الطباعة `print_events` (`public/js/print-audit.js:100-129`, `api/print-events.php:94-103`, `app/Services/PrintAuditService.php:58-61`).
- Trace: UI batch actions → JS `API.post()` (`views/batch-detail.php:446-477`) → `/api/batches.php` → `BatchService` + `BatchAuditService`; UI print → `/api/print-events.php` → `PrintAuditService`.
- مطابقة التوقع: جزئيًا؛ التنفيذ batch-level ليس transaction واحد شامل للدفعة (partial success by design).

## المرحلة 1 — خط الأساس التشغيلي (Operational Fact Baseline)

### A) أسطح التحكم (Control Surfaces)
- المصادقة: Session login + Bearer API token fallback (`api/_bootstrap.php:77-90`, `app/Support/ApiTokenService.php:56-67`, `:121-149`).
- CSRF: enforced لكل mutating method عند تفعيل الإعداد (`api/_bootstrap.php:124-128`, `app/Support/CsrfGuard.php:69-97`).
- RBAC runtime: `Guard::has` + overrides per-user (`app/Support/Guard.php:25-29`, `:60-82`) + view gate (`app/Support/ViewPolicy.php:49-64`).
- API permission gates endpoint-by-endpoint (`api/*.php` تستخدم `wbgl_api_require_permission(...)` مثل `api/extend.php:17`, `api/import.php:15`, `api/users/create.php:17`).
- Undo governance: submit/approve/reject/execute مع منع self-approval (`app/Services/UndoRequestService.php:50-56`, `:82-88`, `:114-122`, `:249-253`).
- Break-glass policy: صلاحية منفصلة + سبب + ticket + TTL + ledger (`app/Services/BreakGlassService.php:75-99`, `:107-121`).
- Release read-only policy: منع تعديل released إلا break-glass (`app/Services/GuaranteeMutationPolicyService.php:50-90`).
- workflow stages + permission-per-transition (`app/Services/WorkflowService.php:17-41`, `:58-71`).
- Unproven: `ApiPolicyMatrix` موجودة (`app/Support/ApiPolicyMatrix.php:13-77`) لكن لم يثبت أنها enforced runtime مباشرة؛ الاستخدام الظاهر في أدوات صيانة (`maint/run-execution-loop.php:1270`, `:2394`).

### B) أسطح الإنتاجية (Throughput Surfaces)
- `save-and-next` يقلل الانتقال اليدوي ويعيد next id (`public/js/records.controller.js:441-495`, `api/save-and-next.php:561-573`, `:579-590`).
- Bulk batch operations على مجموعة IDs (`views/batch-detail.php:570-601`, `api/batches.php:33-54`, `app/Services/BatchService.php:84-271`, `:277-419`, `:424-605`).
- Smart Paste متعدد الصفوف (`app/Services/ParseCoordinatorService.php:48-53`, `:65-105`).
- Excel import مع dedupe/skip/error تفصيلي (`app/Services/ImportService.php:94-99`, `:191-204`, `api/import.php:123-128`).
- Automation بعد الإدخال/الاستيراد (`api/import.php:106-113`, `api/create-guarantee.php:136-140`, `app/Services/ParseCoordinatorService.php:573-583`).
- Scheduler runtime + retries + dead letters (`app/Services/SchedulerRuntimeService.php:58-118`, `maint/schedule.php:41-63`).

### C) أسطح التتبع (Traceability Surfaces)
- ledger زمني مركزي: insert into `guarantee_history` عبر `TimelineRecorder` (`app/Services/TimelineRecorder.php:559-567`).
- Hybrid patch + anchors + template version (`app/Services/TimelineRecorder.php:523-552`).
- Import/duplicate/status/workflow/reopen events واضحة (`app/Services/TimelineRecorder.php:613-669`, `:676-715`, `:718-738`, `:791-815`, `:821-837`).
- Print audit (`api/print-events.php:94-108`, `app/Services/PrintAuditService.php:58-76`).
- Settings audit (`api/settings.php:87-95`, `app/Services/SettingsAuditService.php:30-58`).
- Access denied audit (`api/_bootstrap.php:50-64`, `app/Services/AuditTrailService.php:35-51`).
- Batch governance audit (`app/Services/BatchService.php:755-761`, `:798-806`, `app/Services/BatchAuditService.php:34-44`).
- History archive service (`app/Services/HistoryArchiveService.php:40-94`).
- Occurrence ledger (`app/Services/ImportService.php:567-580`, `app/Services/BatchService.php:839-842`).

### D) أسطح الهشاشة (Fragility Surfaces)
- قراءة تولّد كتابة: `/api/get-record.php` يقوم upsert + status/timeline أثناء load (`api/get-record.php:35-82`, `:229-255`, `:303-329`).
- Endpoint حساس بدون permission gate صريح: `/api/save-and-next.php` login-only رغم تعديلات متعددة (`api/save-and-next.php:18`, `:404-443`, `:493-505`).
- Endpoint workflow login-only أيضًا (`api/workflow-advance.php:16`) مع أن القرار النهائي يعتمد على `WorkflowService::canAdvance` (`:44-52`).
- Multi-write بدون transaction موحد في save-and-next (عدة UPDATE/INSERT متتابعة بدون `beginTransaction`) (`api/save-and-next.php:99-118`, `:319-330`, `:404-443`, `:493-505`).
- مساران مختلفان للاستيراد من نفس الصفحة (`public/js/input-modals.controller.js:342-345` و `:468-471` + `public/js/main.js:232-241`).
- عقد جدول occurrences غير متسق في الكود (`api/create-guarantee.php:118` يستخدم `import_source`، بينما `ImportService` يستخدم `batch_type` `app/Services/ImportService.php:576-577`)، و migrations الأساسية لا تُظهر تعريفًا واضحًا لهذا الجدول. Unproven schema.

## المرحلة 2 — استخراج الهوية الاستراتيجية (Evidence-Only)

### القسم 1) الدور الاستراتيجي الأساسي
WBGL هو أساسًا **نظام تشغيل ضمانات مؤسسي محكوم (Governed Guarantee Operating Core)**.

- يغطي دورة العمل اليومية كاملة من الإدخال (manual/paste/excel) حتى القرار والإفراج/التمديد/التخفيض (`public/js/input-modals.controller.js:49-126`, `api/import.php:71-74`, `api/extend.php:97-137`, `api/release.php:75-99`, `api/reduce.php:121-153`).
- يمتلك طبقة حوكمة فعلية (undo workflow + break-glass + lock policy) (`app/Services/UndoRequestService.php:13-157`, `app/Services/BreakGlassService.php:63-129`, `app/Services/GuaranteeMutationPolicyService.php:50-90`).
- يحتفظ بسجل زمني قابل للتدقيق لكل التحولات (`app/Services/TimelineRecorder.php:559-567`, `:613-738`, `:791-837`).
- يدعم throughput عالي عبر save-and-next وbatch actions (`public/js/records.controller.js:441-495`, `app/Services/BatchService.php:84-605`).
- يطبق workflow stage-based مع صلاحيات انتقال (`app/Services/WorkflowService.php:35-71`, `api/workflow-advance.php:43-55`).
- يربط التشغيل بالمراقبة والتنبيهات التشغيلية (`api/metrics.php:15-32`, `app/Services/OperationalAlertService.php:20-110`).
- يدير تحذيرات الانتهاء عبر scheduler job (`app/Services/SchedulerJobCatalog.php:15-18`, `maint/notify-expiry.php:21-60`).

### القسم 2) الميزة التنافسية البنيوية
- **حوكمة إعادة الفتح ثنائية المسار (Undo + Break-glass)**: تمكّن الفصل بين طلب/اعتماد/تنفيذ مع مسار طوارئ مضبوط؛ الدليل `api/reopen.php:46-65`, `app/Services/UndoRequestService.php:50-157`, `app/Services/BreakGlassService.php:75-121`; الظهور: جزئي (UI يعرض زر reopen، تفاصيل الحوكمة غالبًا backend).
- **Ledger زمني هجين Patch+Anchor مع Letter Snapshot**: يمكّن إعادة بناء الحالة وتدقيق الأدلة النصية/الخطابات؛ الدليل `app/Services/TimelineRecorder.php:523-552`, `:559-567`, `:88-162`; الظهور: محدود (timeline مرئي، تفاصيل hybrid غير مرئية للمستخدم).
- **Batch-as-Context عبر occurrence ledger بدل الاعتماد على import_source فقط**: يمكّن تتبع re-occurrence والدفعات كسياق تشغيلي مستقل؛ الدليل `views/batches.php:20-35`, `app/Services/ImportService.php:567-580`, `app/Services/BatchService.php:49-79`, `:834-842`; الظهور: مرئي في UI الدفعات.
- **تدقيق الطباعة كنشاط حوكمة مستقل**: يمكّن ربط الطباعة بالمستخدم/السياق/الدفعة؛ الدليل `public/js/print-audit.js:100-129`, `api/print-events.php:94-108`, `app/Services/PrintAuditService.php:58-76`; الظهور: backend-only غالبًا.

### القسم 3) الملاءمة التنظيمية
- **Primary Fit: Mixed operations with governance layers**.
- سبب الاختيار: وجود مسارات حجمية (save-next/import/batch) مع طبقات ضبط (undo/break-glass/locks/audit) في نفس النواة (`api/save-and-next.php:561-590`, `app/Services/BatchService.php:84-605`, `app/Services/UndoRequestService.php:50-157`, `app/Services/BreakGlassService.php:63-129`, `app/Services/TimelineRecorder.php:559-567`).
- **Secondary Fit: Compliance-heavy / audit-first**.
- سبب الاختيار: كثافة دفاتر التدقيق (timeline, print_events, settings_audit_logs, audit_trail_events, batch_audit_events) (`app/Services/TimelineRecorder.php:559-567`, `app/Services/PrintAuditService.php:58-61`, `app/Services/SettingsAuditService.php:30-58`, `app/Services/AuditTrailService.php:35-51`, `app/Services/BatchAuditService.php:34-44`).

### القسم 4) النموذج طويل الأجل (Archetype)
- **الاختيار: C) Operating Core**.

- أدلة مؤيدة للاختيار:
- يغطي core workflows التشغيلية اليومية end-to-end (`index.php:800-900`, `public/js/records.controller.js:441-631`, `api/extend.php`, `api/reduce.php`, `api/release.php`).
- يدعم ingestion متعدد (manual/paste/excel) بنفس النظام (`public/js/input-modals.controller.js:49-126`, `api/create-guarantee.php`, `api/parse-paste.php`, `api/import.php`).
- يمتلك batch orchestration وإدارة حالة دفعة (`api/batches.php:33-145`, `app/Services/BatchService.php:732-813`).
- يعمل كسجل مرجعي للحالة الجارية + history (`guarantee_decisions` و`guarantee_history` عبر `app/Services/TimelineRecorder.php:559-567`).

- أدلة ضد A) Fortress System (حاليًا ليس Fortress كامل):
- وجود read endpoints تُحدث كتابة (`api/get-record.php:35-82`, `:229-255`).
- endpoints حاسمة login-only بدون permission صريح (`api/save-and-next.php:18`, `api/workflow-advance.php:16`).

- أدلة ضد B) Control Tower:
- يوجد metrics/alerts (`api/metrics.php:15-32`, `app/Services/OperationalAlertService.php:20-110`) لكن لا يظهر محرك assignment مركزي أو غرفة متابعة موحدة كمسار UI رئيسي. Unproven كـControl Tower كامل.

- أدلة ضد D) Commercial Product-ready:
- عقود استجابة غير موحدة (بعض endpoints ترجع HTML fragments وبعضها JSON) (`api/extend.php:16`, `api/release.php:16` مقابل `api/import.php:14`, `api/settings.php:2`).
- وجود تضارب عقدي محتمل في occurrences وغياب migrations أساسية لبعض الجداول التشغيلية. Unproven schema stability (`api/create-guarantee.php:118`, `app/Services/ImportService.php:576-577`, `database/migrations/*`).

## المرحلة 3 — فحص التناقضات (Contradiction Check)

### تعارضات القسم 1 (Core Strategic Role)
- [Identity-supporting] وجود throughput + governance معًا: save-next/batch من جهة وundo/break-glass/timeline من جهة أخرى (`api/save-and-next.php:561-590`, `app/Services/BatchService.php:84-605`, `app/Services/UndoRequestService.php:50-157`, `app/Services/BreakGlassService.php:63-129`).
- [Identity-threatening] مسار `reopen` في UI لا يرسل reason بينما backend يفرضه بدون break-glass (`public/js/records.controller.js:346-350` مقابل `api/reopen.php:46-52`).
- [Identity-threatening] read-mutation في get-record يربك الفصل بين "عرض" و"تعديل" (`api/get-record.php:35-82`, `:229-255`).

### تعارضات القسم 2 (Competitive Advantage)
- [Identity-supporting] workflow undo يفصل submit/approve/execute ويمنع self-approval (`app/Services/UndoRequestService.php:50-56`, `:114-135`, `:249-253`).
- [Identity-threatening] جزء من مزايا الحوكمة غير ظاهر للمستخدم (مثل break_glass_events/batch_audit/settings_audit) وقد يقلل قابلية الفهم التشغيلي في UI. الدليل على الوجود backend: `app/Services/BreakGlassService.php:107-121`, `app/Services/BatchAuditService.php:34-44`, `app/Services/SettingsAuditService.php:30-58`; الظهور UI المباشر لهذه السجلات: Unproven.
- [Identity-threatening] print audit يعتمد JS fire-and-forget؛ الطباعة قد تتم حتى لو فشل تسجيل audit (`public/js/print-audit.js:81-85`, `:100-106`).

### تعارضات القسم 3 (Organization Fit)
- [Identity-supporting] fit مزدوج (عمليات كثيفة + حوكمة) مدعوم عمليًا (`app/Services/BatchService.php:84-605`, `app/Services/TimelineRecorder.php:559-567`, `app/Services/UndoRequestService.php:50-157`).
- [Identity-threatening] بعض التغييرات الحرجة محمية بالـlogin فقط لا permission خاص (`api/save-and-next.php:18` + writes `:404-443`; `api/workflow-advance.php:16` + writes `:81-85`).
- [Identity-threatening] عدم اكتمال migration baseline للجداول الأساسية في `database/migrations` يضعف قابلية الضبط عبر البيئات. Unproven (`database/migrations` يحتوي جداول إضافية فقط مثل `20260226_000003...` و`20260226_000007...` و`20260227_000014...`).

### تعارضات القسم 4 (Archetype)
- [Identity-supporting] أدلة Operating Core قوية: ingestion متعدد + core actions + batch orchestration (`api/create-guarantee.php`, `api/parse-paste.php`, `api/import.php`, `api/batches.php`).
- [Identity-threatening] وجود عقود متباينة (HTML fragment vs JSON) وتكرار مسارات import يضعف اتساق ABI المتوقع لنمط Product-ready (`api/extend.php:16`, `api/release.php:16`, `api/import.php:14`, `public/js/input-modals.controller.js:342-345`, `:468-471`, `public/js/main.js:232-241`).
- [Identity-threatening] workflow يمكن تجاوزه تشغيليًا لأن extend/reduce/release لا تتحقق من `workflow_step` (تتحقق من `status/lock` فقط) (`api/extend.php:52-86`, `api/reduce.php:77-111`, `api/release.php:38-53`), بينما workflow gate مستقل في endpoint آخر (`api/workflow-advance.php:43-55`).

## ملاحظات الإثبات
- أي نقطة غير قابلة للإثبات من الشيفرة فقط وُسمت `Unproven`.
- هذا التقرير لا يقدم خطة تنفيذ، ويقتصر على التدقيق التشغيلي.
