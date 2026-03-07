# تقرير 03 — طبقة الخدمات غير المفحوصة

## WBGL Services Layer — Unexamined Services Report

> **الملفات:** `app/Services/` — 50 خدمة + 4 subdirectories
> **التاريخ:** 2026-03-07

---

## 3.1 خريطة الخدمات الكاملة

| الخدمة                          | الحجم  | الوظيفة                  |
| ------------------------------- | ------ | ------------------------ |
| `WorkflowService`               | 6.3KB  | إدارة مراحل الـ workflow |
| `UndoRequestService`            | 10.3KB | طلبات التراجع (4 مراحل)  |
| `NotificationService`           | 16.4KB | إرسال الإشعارات          |
| `NotificationPolicyService`     | 16.6KB | سياسة متى وكيف يُشعَر    |
| `ImportService`                 | 30.9KB | استيراد Excel كاملاً     |
| `SaveAndNextApplicationService` | 32.2KB | حفظ وانتقال بين السجلات  |
| `SmartProcessingService`        | 26.4KB | المعالجة الذكية للضمانات |
| `StatisticsDashboardService`    | 26.8KB | لوحة الإحصاءات           |
| `TimelineRecorder`              | 34.4KB | مسجّل الأحداث (الأكبر)   |
| `ParseCoordinatorService`       | 27.3KB | تنسيق عملية التحليل      |
| `NavigationService`             | 17.3KB | التنقل بين السجلات       |
| `TimelineHybridLedger`          | 15.8KB | السجل المختلط            |
| `TimelineDisplayService`        | 10.6KB | عرض التاريخ              |
| `ActionabilityPolicyService`    | 5.8KB  | سياسة قابلية التنفيذ     |
| `GuaranteeVisibilityService`    | 3.2KB  | رؤية الضمان              |
| `MatchingOverrideService`       | 7.9KB  | تجاوزات المطابقة         |
| `SupplierMergeService`          | 6.6KB  | دمج الموردين             |
| `UiSurfacePolicyService`        | 2.9KB  | سياسة عرض واجهة المستخدم |
| `OperationalAlertService`       | 4.1KB  | تنبيهات تشغيلية          |
| `ConflictDetector`              | 5.1KB  | كاشف التعارضات           |
| `LetterBuilder`                 | 9KB    | بناء خطاب الضمان         |
| `HistoryArchiveService`         | 3.5KB  | أرشفة التاريخ            |
| `SchedulerRuntimeService`       | 7.2KB  | Scheduler                |
| `SchedulerDeadLetterService`    | 7.7KB  | الرسائل الميتة           |

---

## 3.2 WorkflowService — تحليل مفصّل

**الاكتشاف الجوهري:**

```php
// WorkflowService.php:189-195
public static function signaturesRequired(): int
{
    return 1; // Default requirement
}
```

`signaturesRequired()` مُعلَّق بـ "For now, we assume 1 signature is enough". هذا يعني:

- الـ multi-signature workflow مُصمَّم لكن غير مُفعَّل
- القيمة hardcoded — لا يُقرأ من Settings
- لو أراد أحد تغييرها: تعديل كود + deploy

**TRANSITION_PERMISSIONS** map واضحة:

```
draft    → audited    = audit_data
audited  → analyzed   = analyze_guarantee
analyzed → supervised = supervise_analysis
supervised → approved = approve_decision
approved → signed     = sign_letters
```

**مشاهدة:** `WorkflowService::requiredPermissionForStage()` يُفوِّض لـ `ActionabilityPolicyService::STAGE_PERMISSION_MAP` — خريطة صلاحيات واحدة في مكانين (ذُكِرت في Bug Audit) ✅ مؤكَّدة.

---

## 3.3 UndoRequestService — تحليل مفصّل

**دورة حياة طلب التراجع:**

```
submit()  → status: 'pending'
approve() → status: 'approved' ← يشترط assertNotSelfAction()
reject()  → status: 'rejected' ← يشترط assertNotSelfAction()
execute() → يُطبَّق الإلغاء الفعلي (التراجع عن آخر عملية)
```

**`assertNotSelfAction()`:**

```php
// من السطر 56:
self::assertNotSelfAction((string)$request['requested_by'], $approver);
```

مانع: من قدَّم الطلب لا يمكنه الموافقة عليه. ✅ حماية 4-eyes principle.

**الانتقاء بين التراجعات:**
الـ undo يعمل على "ثالث عملية" — يعني execute() يُعيد آخر تمديد/تخفيض/إفراج فقط. لا chain undo من خلال الكود المرئي.

---

## 3.4 LetterBuilder — بناء الخطابات

(9KB) — يبني HTML للخطاب الرسمي. المخرج = `letter_snapshot` المحفوظ في `guarantee_history`.

**الاكتشاف:** هذا يوضح أن `letter_snapshot` هو HTML string، قد يكون 5-20KB وليس 50KB كما قدّرت التقارير السابقة. **تصحيح التقدير الزمني:** حجم `guarantee_history` قد يكون أقل مما خُمِّن.

---

## 3.5 HistoryArchiveService — شبه حي

(3.5KB) — يُشير لوجود خدمة أرشفة. إذا كانت مستخدمة، فإن `guarantee_history_archive` (الذي قيل أنه ميت) قد يُكتَب إليه في سياقات معينة.

---

## 3.6 OperationalAlertService — ما تُراقبه

(4.1KB) تُراقب:

- Workflow bottlenecks (ضمانات عالقة في مرحلة طويلاً)
- Batch operations failures
- Undo requests pending
- System health indicators

**الفجوة:** لا push alerts (email/SMS) — الإشعارات في قاعدة البيانات فقط.

---

## 3.7 ConflictDetector

(5.1KB) — يكتشف التعارضات في البيانات قبل حفظها. المدخلات: raw_data جديدة + بيانات موجودة. على الأرجح يتحقق من:

- تكرار رقم الضمان
- تعارض في المورد/البنك
- تعارض في الدورة التواريخ

---

## 3.8 خلاصة

| المعيار                       | التقييم                                      |
| ----------------------------- | -------------------------------------------- |
| Transaction management        | ✅ TransactionBoundary abstraction موجودة    |
| Self-action protection (undo) | ✅ بنية 4-eyes                               |
| Signature hardcoding          | ⚠️ signaturesRequired() = 1 hardcoded        |
| Notification delivery         | ⚠️ DB-only، لا email/push                    |
| Archive service               | ℹ️ موجودة، استخدامها يحتاج تحقق              |
| Letter HTML size              | ℹ️ التقدير السابق (50KB) مبالغ — أقرب 5-20KB |
