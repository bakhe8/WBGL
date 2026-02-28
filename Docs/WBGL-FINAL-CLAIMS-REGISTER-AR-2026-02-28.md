# السجل النهائي للـ Claims — WBGL (Final Register)

تاريخ الإصدار: 2026-02-28  
الحالة: **Final**  
المرجع: هذا السجل هو المصدر الرسمي للحكم النهائي على الادعاءات المتداولة في وثائق `Docs`.

---

## حالات التصنيف

- `Confirmed`: مثبت end-to-end بالأدلة.
- `Partially Confirmed`: مثبت جزئيًا مع فجوة/شرط/استثناء.
- `Refuted`: الادعاء بصيغته العامة غير صحيح.
- `Unproven`: لا يوجد دليل كافٍ للحسم النهائي.

---

## سجل الادعاءات المحسوم

| ID | الادعاء | الحالة النهائية | الأدلة التنفيذية (Code/UI) | أين ظهر التضارب | الحسم النهائي |
|---|---|---|---|---|---|
| FR-01 | WBGL يدعم 4 قنوات إدخال (`Excel/SmartPaste/Manual/Email`) | Confirmed | `api/import.php`, `api/parse-paste.php`, `api/create-guarantee.php`, `api/import-email.php` | `critical-feature-discovery-report.md` vs تقارير تشغيلية | الادعاء صحيح تقنيًا. |
| FR-02 | معالجة التكرارات موحدة بالكامل عبر كل القنوات | Partially Confirmed | `ImportService::recordOccurrence` في مسار Excel، وفروقات في مسارات أخرى | `WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md` vs تقارير عامة | المعالجة قوية لكن ليست موحدة بنفس العمق في كل قناة. |
| FR-03 | فتح السجل قراءة فقط | Refuted | `api/get-record.php` يحتوي upsert/update + timeline write | UI-first + Claim Validation + Human Risk | القراءة قد تُحدث كتابة فعلية. |
| FR-04 | `Undo` lifecycle محكوم بفصل صلاحيات ومنع self-approval | Confirmed | `app/Services/UndoRequestService.php` | لا تضارب جوهري | الادعاء صحيح. |
| FR-05 | `Break-glass` موجود كآلية حوكمة قابلة للتدقيق | Confirmed | `app/Services/BreakGlassService.php` + `break_glass_events` | لا تضارب جوهري | الآلية موجودة ومكتملة بنيويًا. |
| FR-06 | الأدوار الإشرافية (`supervisor/approver`) تستطيع `reopen` عمليًا كما في RBAC | Refuted | `api/reopen.php` و`api/batches.php` يفرضان `manage_data` أولًا | Role Analysis + Feedback | RBAC وحده لا يكفي بسبب gate order. |
| FR-07 | `released` read-only مطبق على كل مسارات الكتابة | Partially Confirmed | `GuaranteeMutationPolicyService` مطبق في عدة endpoints لا كلها | Claim Validation + Cognitive Audit | الحماية موجودة لكن ليست شاملة 100%. |
| FR-08 | سجل timeline الهجين يسمح بإعادة بناء الحالة | Confirmed | `TimelineRecorder.php`, `TimelineHybridLedger.php`, `api/get-history-snapshot.php` | لا تضارب جوهري | الادعاء صحيح. |
| FR-09 | تغييرات الإعدادات مدققة قبل/بعد | Confirmed | `api/settings.php`, `SettingsAuditService.php` | لا تضارب جوهري | صحيح. |
| FR-10 | الطباعة/المعاينة مدققة كأحداث مستقلة | Confirmed | `api/print-events.php`, `PrintAuditService.php`, `public/js/print-audit.js` | لا تضارب جوهري | صحيح مع اعتماد JS للإرسال. |
| FR-11 | Scheduler + dead-letter + retry/resolve تعمل فعليًا | Confirmed | `maint/schedule.php`, `SchedulerRuntimeService.php`, `SchedulerDeadLetterService.php`, `api/scheduler-dead-letters.php` | لا تضارب جوهري | صحيح. |
| FR-12 | API contract موحد (`success/data/error/request_id`) | Refuted | تفاوت واضح بين JSON/HTML وحقول مختلفة (`api/release.php`, `api/extend.php`, `api/save-import.php`) | Claim Validation + UX reports | التوحيد غير قائم فعليًا. |
| FR-13 | `ApiPolicyMatrix` هي enforcement مركزي runtime | Refuted | `ApiPolicyMatrix.php` موجودة لكن غير مربوطة مركزيًا في `api/_bootstrap.php` | Claim Validation vs تقارير أعلى مستوى | موجودة كمرجعية/اختبار أكثر من runtime gate مركزي. |
| FR-14 | Batch actions تُرجع partition واضح (`processed/blocked/errors`) | Confirmed | `api/batches.php`, `BatchService.php` | لا تضارب جوهري | صحيح. |
| FR-15 | Batch actions ذرية على مستوى الدفعة كاملة | Refuted | `BatchService.php` يستخدم معاملات `beginTransaction/commit` لكل سجل | Human Risk vs UI-first | الذرية per-record وليست per-batch. |
| FR-16 | Metrics/Alerts موجودة ومفعلة | Confirmed | `api/metrics.php`, `api/alerts.php`, `OperationalMetricsService.php`, `OperationalAlertService.php` | Feature Census vs تقارير UX | موجودة تقنيًا بشكل مؤكد. |
| FR-17 | Metrics/Alerts ظاهرة كلوحة تشغيل يومية لجميع الأدوار | Refuted | تظهر فعليًا في `views/maintenance.php` وتحتاج `manage_users` | UI-first + validation checks | مرئية لفئة محدودة وليست تجربة عامة. |
| FR-18 | المسارات المكررة/legacy قليلة التأثير | Refuted | `parse-paste.php` و`parse-paste-v2.php`, `create-guarantee.php` و`manual-entry.php`, `history.php` retired | Claim Validation + Feature Discovery | خطر drift قائم ومؤثر. |
| FR-19 | Manual Entry غير موجود | Refuted | `public/js/input-modals.controller.js` -> `/api/create-guarantee.php` | Human Risk vs Feature docs | المسار موجود ومربوط. |
| FR-20 | Manual Entry مستقر ميدانيًا بلا أعطال | Unproven | تباين حقول occurrences (`import_source` vs `batch_type`) + اختلاف بيئات schema | Human Risk + UI-first + Claim Validation | لا يمكن الحسم بالاستقرار التشغيلي من الكود فقط. |
| FR-21 | Smart Paste V2 هو المسار الرئيسي في UI | Refuted | UI يستدعي `/api/parse-paste.php` (v1) | Claim Validation + UI-first | v2 موجود لكنه غير موصول بالمسار الرئيسي. |
| FR-22 | Save-and-Next مقيد بنفس صرامة mutate endpoints الأخرى | Refuted | `api/save-and-next.php` يتطلب login فقط ويكتب عدة كيانات | Role Analysis + Cognitive Audit | حدود الإذن أقل صرامة من endpoints `manage_data`. |
| FR-23 | workflow خطوة إلزامية قبل `extend/reduce/release` | Refuted | `extend/reduce/release` تتحقق من `status/lock` لا `workflow_step` | UI-first + code endpoints | يمكن تنفيذ إجراءات تشغيلية دون فرض تسلسل workflow الكامل. |
| FR-24 | scoping الوصول متسق بين كل مسارات القراءة المباشرة | Refuted | مسارات list/navigation تستخدم visibility service، ومسارات direct-id ليست دائمًا بنفس الصرامة | Claim Validation | يوجد gap في الاتساق. |
| FR-25 | Drift حقول الـschema فرضية ضعيفة | Confirmed | أمثلة مباشرة: `guarantee_occurrences import_source vs batch_type`, `status_flags` في بعض المسارات | Cognitive + Claim Validation | drift مؤكد ويؤثر على الاعتمادية. |

---

## الادعاءات المغلقة نهائيًا (Top Closure Set)

- مغلق وحاسم: `FR-03`, `FR-06`, `FR-13`, `FR-15`, `FR-22`, `FR-25`.
- يحتاج متابعة تشغيلية ميدانية لاختبار استقرار: `FR-20`.

---

## استخدام السجل في أي دراسة تطوير قادمة

- أي قرار تطويري جديد يجب أن يبدأ من هذا السجل.
- يمنع إعادة فتح claim مغلق إلا بدليل runtime جديد قابل لإعادة التحقق.
- في حال ظهور تعارض جديد: يضاف claim جديد ولا يُعدّل claim مغلق إلا بإصدار سجل جديد.

