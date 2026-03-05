# WBGL — حزم التغييرات المعتمدة (Change Packages)

**النسخة:** 1.0  
**التاريخ:** 2026-03-05  
**الغرض:** تنظيم مجموعة التغييرات الكبيرة إلى حزم تنفيذ/مراجعة واضحة وقابلة للتسليم.

---

## PKG-01 — مزامنة قاعدة البيانات والصلاحيات

**الهدف:** إغلاق أي `pending migrations` وضبط مصفوفة الصلاحيات قبل الإغلاق التشغيلي.  
**النطاق:**

1. تطبيق جميع المهاجرات المتبقية.
2. التحقق من `migration-status` = صفر Pending.
3. التحقق من عدم وجود انجراف صلاحيات.

**الملفات الأساسية:**

1. `database/migrations/20260305_000025_add_batch_full_operations_override_permission.sql`
2. `app/Scripts/migrate.php`
3. `app/Scripts/migration-status.php`
4. `app/Scripts/permissions-drift-report.php`

**دليل الإغلاق:**

1. `Pending: 0`
2. `permissions-drift-report: PASS`

---

## PKG-02 — تحقق تكاملي لسلسلة الأدوار

**الهدف:** تثبيت سلوك السلسلة التشغيلية end-to-end من `data_entry` حتى `signed` عبر اختبار تكامل واحد موحد.  
**النطاق:**

1. إنشاء سيناريو تكاملي يغطي انتقالات كل الأدوار.
2. التحقق من ثبات `status=ready` و`workflow_step=signed` بعد اكتمال السلسلة.
3. ضمان تنظيف المستخدمين/البيانات التي ينشئها الاختبار.

**الملفات الأساسية:**

1. `tests/Integration/EnterpriseApiFlowsTest.php`

**دليل الإغلاق:**

1. نجاح `--filter testRoleBasedWorkflowChainFromDataEntryToSigned`
2. نجاح ملف الاختبار التكاملـي كاملًا.

---

## PKG-03 — تنظيم الشحنة الكبيرة إلى باقات مراجعة

**الهدف:** تحويل التغييرات الكبيرة إلى مجموعات مراجعة قابلة للفهم والتنفيذ التدريجي.  
**الباقات المقترحة للمراجعة/التسليم:**

1. **باقة الحوكمة والسياسات:**
   1. `api/_bootstrap.php`
   2. `app/Support/ApiPolicyMatrix.php`
   3. `app/Services/UiSurfacePolicyService.php`
   4. `app/Services/ActionabilityPolicyService.php`
   5. `app/Support/ViewPolicy.php`
2. **باقة سير العمل والانتقالات:**
   1. `api/workflow-advance.php`
   2. `api/workflow-reject.php`
   3. `app/Services/WorkflowService.php`
   4. `app/Services/TimelineRecorder.php`
3. **باقة العزل بين الاختباري/الحقيقي:**
   1. `app/Support/TestDataVisibility.php`
   2. `app/Services/BatchAccessPolicyService.php`
   3. `database/migrations/20260304_000024_enforce_batch_isolation_guards.sql`
   4. `database/migrations/20260304_000025_auto_isolate_reclassified_batches.sql`
4. **باقة الواجهة وتجربة الاستخدام:**
   1. `index.php`
   2. `public/js/main.js`
   3. `public/js/records.controller.js`
   4. `views/batches.php`
   5. `views/maintenance.php`
   6. `public/locales/ar/index.json`
   7. `public/locales/en/index.json`
5. **باقة الاختبارات والتقارير:**
   1. `tests/Integration/EnterpriseApiFlowsTest.php`
   2. `tests/Unit/Services/ActionabilityPolicyServiceTest.php`
   3. `app/Scripts/data-integrity-check.php`
   4. `app/Scripts/generate-system-counts-consistency-report.php`
   5. `app/Scripts/generate-suspect-test-report.php`

**مبدأ التنفيذ:** لا يعتمد التسليم على وقت ثابت، بل على اكتمال كل باقة مع أدلة تحققها.

---

## PKG-04 — تحديث حالة التنفيذ الرسمية

**الهدف:** مزامنة وثائق المتابعة الرسمية مع المنجز الفعلي للكود والاختبارات.  
**النطاق:**

1. تحديث `WBGL-EXECUTION-SEQUENCE-AR.json` بإضافة دورة إغلاق جديدة.
2. تحديث `WBGL-EXECUTION-STATE-AR.json` بأدلة التنفيذ الجديدة.
3. تحديث `WBGL-EXECUTION-LOG-AR.md` بسجل زمني واضح.

**الملفات الأساسية:**

1. `Docs/WBGL-EXECUTION-SEQUENCE-AR.json`
2. `Docs/WBGL-EXECUTION-STATE-AR.json`
3. `Docs/WBGL-EXECUTION-LOG-AR.md`

---

## PKG-05 — فحص التناسق النهائي (Closure Checks)

**الهدف:** تأكيد موثوقية الأرقام والسياسات قبل اعتبار الدورة مغلقة.  
**النطاق:**

1. فحص انجراف الصلاحيات.
2. فحص نزاهة البيانات.
3. فحص اتساق العدادات.
4. فحص عدم وجود عينات اختبار غير موسومة.

**الملفات/التقارير الناتجة:**

1. `storage/logs/permissions-drift-report.md`
2. `storage/logs/data-integrity-report.md`
3. `storage/logs/system-counts-consistency-report.md`
4. `storage/logs/suspect-test-data-unflagged-report.md`

**معيار الإغلاق:**

1. PASS في drift + integrity + counts.
2. `suspect report = 0`.

---

## قرار التنفيذ

الحزم الخمس أعلاه مكتملة وظيفيًا ويمكن اعتمادها كمرجع تسليم/مراجعة نهائي لهذه الدورة.
