# خطة إعادة ضبط شاملة لمنطق الوصول/العرض/الخصوصية/التنفيذ في دورة حياة الضمان

## 1) الهدف (نسخة موسعة بعد المراجعة)
المشكلة ليست فلتر `Actionable` فقط، بل انحراف في تعريف الوصول والعرض والتنفيذ عبر النظام كاملًا.
الهدف هو توحيد القرار في ستة مستويات:
1. `Record Access Scope`: أي السجلات التي يجوز الوصول إليها.
2. `Data Visibility Scope`: أي الحقول التي يجوز عرضها.
3. `Surface Visibility Scope`: أي مكونات واجهة يجب أن تظهر/تختفي.
4. `Actionability Scope`: ما الذي يجب أن يظهر كـ "مطلوب منك".
5. `Executability Scope`: ما الذي يمكن تنفيذه الآن فعليًا.
6. `Navigation Scope`: ما يدخل في الفلاتر والعدادات والتنقل.

---

## 2) توحيد المصطلحات إلزاميًا: Visible vs Actionable vs Executable
تعريفات النظام الرسمية:
1. `Visible`
   - يحق للمستخدم معرفة/رؤية السجل (كليًا أو جزئيًا حسب policy).
2. `Actionable`
   - السجل يجب أن يظهر ضمن "مهامي الآن".
   - أقوى من Visible.
3. `Executable`
   - يمكن تنفيذ الإجراء الآن (بعد فحوصات إضافية: lock, active_action, holds, SLA...).

قاعدة رياضية إلزامية:
`Executable ⊆ Actionable ⊆ Visible`

قرار تشغيلي للواجهة الرئيسية:
`Actionable` في صفحة المهام يجب أن يكون قريبًا جدًا من `Executable` لتجنب "مهمة ظاهرة لكن غير قابلة للعمل".

---

## 3) الحالة المؤكدة من الصور (Root Symptoms)
المشاهدات تؤكد:
1. عرض سجل/بيانات/Timeline رغم أن عداد المهام `0/0`.
2. عرض تفاصيل مراحل لا ترتبط بحاجة تنفيذية حالية للمستخدم.
3. عرض مكونات تشغيلية حساسة رغم عدم وجود علاقة عمل نشطة.
4. تناقض بين "لا توجد مهام" وبين عرض سياق تنفيذي كامل.

تصنيف رسمي: `Privacy Surface Exposure + Workflow Scope Drift`.

---

## 4) ما هو مطبق حاليًا (Baseline)
1. إصلاحات object-level visibility في endpoints حرجة.
2. إصلاح SQL placeholder في فلتر `Actionable`.
3. منع forced id خارج scope في حالات سابقة.
4. تمرير `stageFilter` في مسارات التنقل.
5. اختبارات Unit أساسية ناجحة.

الحكم: الأساس مفيد لكنه غير كافٍ بدون طبقة سياسة موحدة.

---

## 5) السبب الجذري
1. تشتت شروط القرار بين ملفات وخدمات متعددة.
2. غياب نموذج قرار موحد يميز بين `Visible/Actionable/Executable`.
3. تحميل بيانات حساسة مبكرًا ثم محاولة إخفائها واجهيًا.
4. اختلاف شروط count/list/navigation/stats.
5. الاعتماد الجزئي على role-centric بدل effective permissions (Role + User overrides + need-to-know).

---

## 6) النهج المعتمد: Policy Stack موحد + مخرجات قياسية

## 6.1 الخدمات الأساسية
1. `ActionabilityPolicyService` (Policy + Query Builder)
2. `RecordScopePolicyService`
3. `UiSurfacePolicyService`
4. `WorkflowExecutionPolicyService`

## 6.2 مخرجات موحدة إلزامية
الخدمة يجب أن تُخرج ثلاثة أشكال قياسية:
1. `SqlPredicate`
   - `whereSql`
   - `bindings`
   - `debug` (اختياري للشادو مود)
2. `PolicyResult` (لكل سجل)
   - `visible`
   - `actionable`
   - `executable`
   - `reasonCodes[]`
3. `SurfaceGrants`
   - مثل `canViewIdentity`, `canViewFinancials`, `canViewTimeline`, `canViewActionButtons`...

مبدأ: ممنوع تكرار نفس where بطرق مختلفة في services مختلفة.

---

## 7) Minimal Load (إغلاق التسريب من الجذر)
قاعدة إلزامية:
عند فتح سجل (`id` مباشر أو forced id) يتم تحميل `Minimal Fields` فقط أولًا:
- `id`, `status`, `workflow_step`, `is_locked`, `active_action`, `assignee/meta` الضروري.

ثم:
1. إذا `Executable/Actionable`: حمّل التفاصيل الكاملة.
2. إذا `Visible فقط`: اعرض `Read-Limited View` بدون حقول حساسة.
3. إذا `Not Visible`: `404/403` حسب policy.

هدف هذه الخطوة: حتى لو حصل bug بالواجهة، لا تكون البيانات الحساسة أصلًا موجودة في DOM/response.

---

## 8) Surface Privacy Policy (DOM-Safe Rendering)
الإظهار لا يعتمد على CSS hide، بل على grants ناتجة من policy:
1. `canViewIdentity = visible + need_to_know + perm.view_identity`
2. `canViewFinancials = perm.view_financials + (actionable OR explicit_override)`
3. `canViewTimeline = visible + perm.view_timeline`
4. `canViewTimelineDetails = executable OR perm.timeline_detailed`
5. `canViewWorkflowBanner = actionable`
6. `canViewActionButtons = executable فقط`

`need_to_know` شرط مستقل، وليس مرادفًا للدور.

---

## 9) API Contracts موحدة
كل endpoint للسجل يرجع:
1. `policy: { visible, actionable, executable, reasons[] }`
2. `surface: { ...grants }`

وعند الرفض:
1. `403`
2. `required_permission`
3. `current_step`
4. `reason_code`

---

## 10) توحيد count/list/navigation/stats (Single Predicate Guarantee)
1. مصدر predicate واحد فقط: `ActionabilityPolicyService::buildActionableWhere(...)`.
2. `NavigationService` و`StatsService` ممنوع يبنون where مستقل.
3. يضاف فقط:
   - `limit/offset/order`
   - aggregations (`COUNT`, `SUM`...) فوق نفس predicate.

اختبار اتساق إلزامي:
1. `count == list.length` لنفس user + filter + dataset.
2. `next/prev` ضمن نفس predicate.
3. `forced id` خارج predicate لا يعيد تفاصيل تنفيذية.

---

## 11) Shadow Mode المطور (Difference with reasons)
لا يكفي تخزين اختلاف IDs.
يجب تسجيل:
1. `guarantee_id`
2. `v1: { actionable, reasonCodes[] }`
3. `v2: { actionable, reasonCodes[] }`
4. `stageFilter`
5. `effectivePermissionsHash`

بهذا يصبح تحليل الفرق سببيًا وليس شكليًا.

---

## 12) Edge Cases الإلزامية
1. `forced id` عبر URL.
2. stageFilter خارج المسموح.
3. timeline endpoint المنفصل.
4. search داخل actionable scope.
5. caching/SSR keys مرتبطة بـ effective permissions.

---

## 13) مراحل التنفيذ (محدثة)
### المرحلة A: AccessDecision + PolicyResult
1. توحيد vocabulary: `visible/actionable/executable`.
2. تعريف reason codes مركزية.

### المرحلة B: Minimal Load Gate
1. أي صفحة/endpoint سجل يبدأ بتحميل minimal.
2. منع تحميل الحقول الحساسة قبل القرار.

### المرحلة C: Single Predicate
1. نقل where الموحد لـ policy service.
2. ربط count/list/navigation/stats بالمصدر الموحد.

### المرحلة D: Surface Grants
1. بناء `UiSurfacePolicyService`.
2. منع render للمكونات غير المصرح بها.

### المرحلة E: API Contract + Errors
1. إرجاع `policy + surface`.
2. توحيد payload الرفض.

### المرحلة F: Shadow + Tests + Rollout
1. `ACCESS_SURFACE_POLICY_V2` feature flag.
2. Shadow mode مع reason codes.
3. اختبارات anti-leak قبل إزالة V1.

---

## 14) معايير القبول (Acceptance + Anti-Leak)
1. لا يوجد مكون/حقل حساس يظهر خارج scope.
2. لا يوجد تناقض بين filter/count/list/navigation.
3. عند `Actionable=0`:
   - لا action buttons
   - لا سياق تنفيذي مضلل
   - لا timeline تفصيلي خارج grant
4. API لا تنفذ أي إجراء خارج `Executable`.
5. `No Sensitive Fields On Minimal Load`.
6. `DOM Leak Test`: DOM/HTML لا يحتوي بيانات حساسة بلا grant.
7. `Timeline Partial Redaction` عند السماح الجزئي.
8. `Single Predicate Guarantee`: ممنوع where actionable خارج policy service (lint/grep CI rule).

---

## 15) قياس النجاح
1. صفر حالات `0/0` مع عرض سياق تنفيذي حساس.
2. صفر شكاوى خصوصية مرتبطة بالـ surfaces.
3. استقرار الأداء ضمن baseline.
4. Shadow diff = صفر قبل القطع النهائي.

---

## 16) القرار التنفيذي
التقرير المراجع صحيح الاتجاه، وتم اعتماده كتحسين رسمي للخطة:
المطلوب ليس "إصلاح Actionable" فقط، بل توحيد قرار النظام كاملًا:
`Visible -> Actionable -> Executable`

---

## 17) Gap Matrix من الكود الحالي (قبل أي تنفيذ جديد)
هذا القسم إلزامي لمنع "التنفيذ الأعمى". التصنيف التالي مبني على قراءة مباشرة للكود الحالي:

### 17.1 موجود ومطبق فعليًا (لا يحتاج بناء من الصفر)
1. حارس Object-Level Visibility مركزي موجود في:
   - `app/Services/GuaranteeVisibilityService.php` (`buildSqlFilter`, `canAccessGuarantee`)
   - `api/_bootstrap.php` (`wbgl_api_require_guarantee_visibility`)
2. تطبيق visibility guard في endpoints حرجة موجود (مثال: `api/get-record.php`).
3. طبقة صلاحيات واجهة أساسية موجودة:
   - Backend: `app/Support/UiPolicy.php`, `app/Support/ViewPolicy.php`
   - Frontend: `public/js/policy.js`, `public/js/ui-runtime.js`, `partials/ui-bootstrap.php`
4. اختبارات wiring لفرض visibility في APIs موجودة:
   - `tests/Unit/Services/GovernancePolicyWiringTest.php`

### 17.2 موجود لكن يحتاج تحسين (Refactor + توحيد)
1. شروط `Actionable` ما زالت موزعة في أكثر من مكان:
   - `index.php`
   - `app/Services/NavigationService.php`
   - `app/Services/StatsService.php`
2. تحميل سجل كامل مبكرًا في `index.php` (عند `requestedId`) قبل فصل Minimal/Full loading.
3. الـ APIs لا ترجع عقدًا موحدًا من نوع:
   - `policy {visible, actionable, executable, reasons}`
   - `surface grants`
4. إظهار الواجهة يعتمد بدرجة كبيرة على permission-only بدل policy-result + need-to-know.
5. العدادات/القوائم/التنقل مرتبطة بمنطق متقارب لكنه ليس "Single Predicate" إلزاميًا بعد.

### 17.3 غير موجود ويحتاج بناء من الصفر
1. `PolicyResult` موحد (Tri-state + reason codes) ككائن قرار رسمي عبر النظام.
2. `ActionabilityPolicyService` واحد كمصدر predicate وحيد (Policy + Query Builder).
3. `UiSurfacePolicyService` مبني على:
   - effective permissions
   - need-to-know
   - policy result
4. `Minimal Load Gate` الشامل (load-minimal ثم hydrate مشروط).
5. Shadow Mode سببي يسجل فروقات القرار مع `reasonCodes[]` وليس IDs فقط.
6. مجموعة اختبارات Anti-Leak المتخصصة:
   - DOM leak
   - Timeline partial redaction
   - Single predicate CI guard

### 17.4 نتيجة تقييم الجاهزية
1. الأساس الحالي جيد وموجود جزئيًا.
2. الخطة المطلوبة ليست إعادة بناء النظام من الصفر.
3. الجزء الأكبر هو "توحيد قرار + تقليل التكرار + منع التسريب بالتصميم".
4. أي تنفيذ لاحق يجب أن يمر بهذا التسلسل:
   - Consolidate (بدون تغيير سلوك)
   - Introduce unified policy contracts
   - Activate minimal-load + surface grants
   - Enforce via anti-leak tests
مع سياسة تحميل، عرض، API، وتنقل مبنية على مصدر حقيقة واحد.

---

## 18) حالة التنفيذ الحالية (2026-03-03)
تحديث تنفيذي بعد الاعتماد، لتفادي أي انحراف بين الخطة والكود:

### 18.1 تم تنفيذه فعليًا
1. `index.php`:
   - تفعيل `scope-first` عند `forced id` عبر:
     - `NavigationService::isIdInFilter(...)`
   - منع تحميل السجل الكامل إلا بعد نجاح فحص النطاق.
2. `index.php`:
   - إزالة اختيار السجل الافتراضي عبر SQL يدوي.
   - اعتماد `NavigationService::getIdByIndex(..., 1, ...)` كمصدر موحد لأول سجل ضمن نفس predicate.
   - النتيجة: إغلاق حالة `0/0` مع ظهور سجل خارج نطاق الفلتر/الرؤية بسبب مسار fallback.
3. `api/get-record.php` و `api/get-timeline.php`:
   - تمرير `stage` إلى `getIdByIndex`.
   - إضافة فحص policy موحد قبل إرجاع البيانات:
     - `wbgl_api_policy_for_guarantee(...)`
4. `api/_bootstrap.php`:
   - إضافة helper موحد يعيد:
     - `visible/actionable/executable/reasons`
   - مبني على:
     - `GuaranteeVisibilityService` + `ActionabilityPolicyService::evaluate(...)`
5. التحقق:
   - `GovernancePolicyWiringTest` محدث وناجح.
   - `ActionabilityPolicyServiceTest` ناجح.
   - `ApiBootstrapTest` ناجح.
6. توحيد العدادات:
   - `StatsService::getImportStats` أصبح يعتمد `NavigationService::countByFilter(...)` مباشرة
     بدل شروط SQL مستقلة.
   - النتيجة: `count/list/navigation` تشترك فعليًا في نفس predicate المصدر.
7. توافق تشغيلي:
   - `ActionabilityPolicyService::allowedStages` يدعم `manage_data` كـ full-stage override
     للحفاظ على التوافق مع السلوك الإداري السابق.
8. تقدم المرحلة `E` (API Contract + Errors):
   - توحيد عقد الاستجابة في endpoints حرجة:
     - `api/save-note.php`
     - `api/upload-attachment.php`
     - `api/workflow-advance.php`
     - `api/save-and-next.php`
     - `api/release.php`
     - `api/reduce.php`
     - `api/extend.php`
   - إضافة payload معياري عند الرفض يتضمن:
     - `required_permission`
     - `current_step`
     - `reason_code`
     - `policy`
     - `surface`
     - `reasons`
     - `request_id`
9. توافق الاختبارات بعد التعديل:
   - `ApiBootstrapTest` ناجح.
   - `EnterpriseApiFlowsTest::testSaveAndNextRejectsInvisibleGuaranteeAndPreventsWrites` ناجح.
   - `EnterpriseApiFlowsTest::testLifecycleMutationFlowWritesTimelineHybridEvents` ناجح.
10. إغلاق تسريب واجهي عند التنقل الجزئي (SPA fragment refresh):
   - `public/js/records.controller.js`:
     - إضافة تحليل `policy/surface` من fragment الراجع من `api/get-record.php`.
     - تطبيق حارس واجهي صريح لإخفاء الهوية/المعاينة عند `can_view_record=0`.
     - منع إطلاق `guarantee:updated` عندما لا يوجد سجل ضمن النطاق.
   - الهدف: منع بقاء معلومات حساسة قديمة في DOM بعد انتقال الفلتر إلى نطاق فارغ.
11. توسيع Anti-Leak Integration Test:
   - إضافة اختبار:
     - `EnterpriseApiFlowsTest::testGetRecordOutOfScopeActionableFilterReturnsEmptyStateWithoutSensitiveFields`
   - التحقق يشمل:
     - عودة empty-state ضمن `record-form-section`.
     - `surface` flags = 0.
     - عدم تسرب `guarantee_number` أو `supplier/contract` في الاستجابة.

### 18.2 قيد التنفيذ (المرحلة التالية)
1. توحيد أعمق لمسارات الإحصاءات/العدادات لتستخدم نفس predicate الحتمي بالكامل.
2. إدخال `SurfaceGrants` بشكل صريح في واجهة الصفحة الرئيسية (وليس APIs فقط).
3. منع تحميل/عرض أي سياق تنفيذي تفصيلي عند `Actionable=0` ما لم يوجد grant واضح.
4. اختبارات Anti-Leak مخصصة:
   - DOM leak
   - timeline redaction
   - single-predicate CI guard

### 18.3 ملاحظة تشغيلية
أي سلوك قديم في المتصفح بعد هذا التعديل يحتاج:
1. Hard refresh (`Ctrl+F5`)
2. إعادة فتح الرابط بدون `id` ثابت عند اختبار `Actionable=0`.
