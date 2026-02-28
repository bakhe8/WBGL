# تقرير التدقيق المعرفي الشامل لنظام WBGL (AS-IS)

تاريخ التنفيذ: 2026-02-28  
نطاق التدقيق: كود WBGL + قاعدة تشغيل PostgreSQL الفعلية + مسارات API/Views/CLI/Scheduler  
قاعدة الأدلة: تحليل بنية المشروع + تتبع تدفق التنفيذ + استعلامات تشغيلية مباشرة من قاعدة البيانات.

---

## 1) مرحلة الفهم الإجباري الكامل (Phase 1)

### 1.1 خريطة البنية (Folder Structure)

الهيكل التشغيلي الأساسي الذي تم تدقيقه:

- `api/` (62 ملف): نقاط الدخول الوظيفية للنظام.
- `app/` (116 ملف): Services/Repositories/Support/Models.
- `views/` (11 ملف): واجهات HTML/PHP.
- `partials/` (14 ملف): أجزاء العرض المشتركة.
- `public/` (48 ملف): JS/CSS/Assets.
- `maint/` (29 ملف): أدوات تشغيل/هجرة/مراقبة/مهام دورية.
- `database/migrations/`: مخطط الهجرات.
- `storage/`: إعدادات التشغيل، قواعد بيانات محلية، سجلات.
- `index.php` + `server.php`: نقطة الويب الرئيسية + راوتر السيرفر المحلي.

ملاحظة: تم التركيز على المكونات التشغيلية المباشرة. مكونات الطرف الثالث (`vendor/`, `node_modules/`) خارج تدقيق السلوك الوظيفي الداخلي.

### 1.2 نقاط الدخول (Entry Points)

#### HTTP / Web

- الصفحة الرئيسية: `index.php`.
- صفحات العرض: `views/*.php`.
- راوتر التطوير: `server.php` (يمرر غير الأنواع الثابتة إلى خادم PHP المدمج).

#### API

- 57 endpoint فعلي (باستثناء `api/_bootstrap.php`) عبر:
  - دورة الضمان: `save-and-next`, `extend`, `reduce`, `release`, `reopen`, `workflow-advance`.
  - الاستيراد: `import`, `parse-paste`, `manual-entry`, `create-guarantee`, `import-email`, `save-import`.
  - الحوكمة/الأثر: `get-timeline`, `get-history-snapshot`, `get-current-state`, `print-events`, `undo-requests`, `settings-audit`, `metrics`, `alerts`, `scheduler-dead-letters`.

#### CLI / Cron / Background

- Scheduler runner: `maint/schedule.php`.
- الوظيفة المجدولة الحالية في الكتالوج: `notify-expiry` عبر `SchedulerJobCatalog`.
- حلقة تنفيذ تشخيصية: `maint/run-execution-loop.php`.
- أدوات هجرة/تحقق/إدارة تشغيل: `maint/*.php`.

### 1.3 دورة الطلب كاملة (Input -> Processing -> Persistence -> Timeline -> Output)

#### المدخلات

- Excel: `api/import.php` -> `ImportService`.
- Smart Paste: `api/parse-paste.php` -> `ParseCoordinatorService`.
- إدخال يدوي: `api/create-guarantee.php`, `api/manual-entry.php`.
- واجهة المستخدم: `index.php` + `save-and-next`.
- بريد إلكتروني (مسار موازي): `api/import-email.php` + `api/save-import.php`.

#### المعالجة

- مطابقة المورد/البنك:
  - `SmartProcessingService`.
  - منطق مطابقة مكرر داخل `api/get-record.php` و `api/save-and-next.php`.
- تقييم الحالة: `StatusEvaluator::evaluate(supplier_id, bank_id)` => `ready|pending`.
- مسار المراحل: `WorkflowService`.

#### حفظ القرار

- الحالة الحالية محفوظة في `guarantee_decisions` (سطر واحد لكل ضمان عبر قيد UNIQUE على `guarantee_id`).
- المادة الخام محفوظة في `guarantees.raw_data` (JSON).

#### تسجيل الخط الزمني

- التسجيل المركزي: `TimelineRecorder`.
- Hybrid ledger: `TimelineHybridLedger` (patch + anchors).
- العرض: `TimelineDisplayService`.

#### المخرجات

- تحديث الحالة والواجهة (جاهز/مفرج/قابل إجراء).
- توليد خطابات (Release/Batch print).
- تحديثات Timeline وحالة العرض التاريخي.

### 1.4 تحليل مخطط قاعدة البيانات (Deep Schema Audit)

#### حجم البيانات التشغيلي (Runtime PG)

- `guarantees=577`
- `guarantee_decisions=552`
- `guarantee_history=2908`
- `guarantee_occurrences=590`
- `suppliers=335`
- `banks=37`
- جداول حوكمة موجودة (undo/break_glass/print/settings_audit/dead_letters) مع استخدام فعلي متفاوت.

#### النموذج البياني

- 34 جدول تشغيل.
- علاقات FK أساسية موجودة بين الضمانات/القرارات/الموردين/البنوك/الخط الزمني.
- JSON/نصوص دلالية مستخدمة بكثافة في:
  - `guarantees.raw_data`
  - `guarantee_history.*`
  - `*_json` payload tables.

#### Raw vs Normalized

- Raw: `guarantees.raw_data`.
- Normalized decision state: `guarantee_decisions` (status, supplier_id, bank_id, workflow_step, lock flags).
- Occurrence ledger: `guarantee_occurrences` كسجل سياقي للدفعات.

#### سلامة القيود

- قوية نسبيًا في FK/PK/UNIQUE الأساسية.
- ضعيفة في قيود domain لبعض الأعمدة الحرجة (لا يوجد CHECK لحالات `guarantee_decisions.status` أو `workflow_step`).

#### الفهارس

- معظم الفهارس PK/UNIQUE.
- غياب فهارس تشغيلية متوقعة على استعلامات timeline/decision عالية التردد (مثل `guarantee_history(guarantee_id, created_at)` غير موجود فعليًا وقت التدقيق رغم وجود migration ملفي).

### 1.5 النمط المعماري الفعلي

- Monolith PHP hybrid:
  - Server-driven صفحات + API endpoints.
  - Service/Repository style داخل `app/`.
  - ليست Event-driven أصلًا، لكنها تنتج سلوكًا event-like عبر `guarantee_history` و `print_events` و `audit_trail_events`.
- Coupling ضمني مرتفع بين:
  - UI controllers,
  - API endpoint logic,
  - Timeline side-effects.

### 1.6 النموذج الذهني التشغيلي (Guarantee Lifecycle)

- كيان الضمان = بيانات خام (`guarantees`) + قرار تشغيلي (`guarantee_decisions`) + أثر زمني (`guarantee_history`).
- `status` (pending/ready/released) منفصل عن `workflow_step` (draft..signed)، لكنهما لا يتحركان بتناسق فعلي في البيانات.
- `active_action` يؤثر على قابلية الظهور في قوائم "actionable" أكثر من كونه metadata فقط.
- الأتمتة تعمل في أكثر من نقطة، أحيانًا بآثار جانبية كتابةً لا قراءةً.

---

## 2) استخراج القدرات الخفية (Mandatory Protocol)

## 2.1 Hidden Capability Extraction

### A) ميزات صريحة (Explicit Features)

- استيراد Excel وتسجيل import/duplicate occurrences.
- Smart paste parsing متعدد/أحادي.
- حفظ قرار المورد/البنك.
- Extend/Reduce/Release/Reopen.
- Workflow advance.
- Timeline عرض تاريخي واستعادة عرض الحالة الحالية.
- إدارة دفعات (close/reopen/release/reduce/extend).
- ملاحظات ومرفقات.
- RBAC + login + CSRF + rate limiting.
- Scheduler + dead letter management.
- Print audit + settings audit + operational metrics/alerts.

### B) ميزات ضمنية (Implicit Features)

- قراءة سجل عبر `api/get-record.php` قد تكتب تلقائيًا قرارات وتغير حالة وتضيف timeline events.
- `save-and-next` يصحح عدم تطابق اسم/ID المورد تلقائيًا (يثق بالاسم عند التعارض).
- تطبيع بنكي تلقائي داخل الحفظ حتى لو لم يُرسل bank في الطلب.
- Production mode يفرض إخفاء بيانات الاختبار حتى بدون UI toggle.
- `ViewPolicy::guardView` قد يكون فقط login-gate إذا لم تُعرف صلاحية الصفحة في map.

### C) ميزات ناشئة (Emergent Features)

- تراكب Timeline + Print Events + Audit Trail + Undo + Break Glass + Scheduler Dead Letters ينتج منظومة حوكمة تشغيلية كاملة رغم أنها موزعة.
- اقتران `active_action` مع فلتر navigation actionability يولد قيدًا عمليًا قويًا على الأعمال اليومية.
- اختبارات wiring تعطي إحساس تغطية حوكمة بينما object-level authorization ليس مضمونًا فعليًا.

## 2.2 Negative Space Analysis

### ما الذي يمنعه النظام ضمنيًا

- منع release/extend/reduce على الضمان غير الجاهز (بدون break-glass).
- منع تعديل الضمان released عبر `GuaranteeMutationPolicyService` إلا في حالات طوارئ.
- منع self-approval في undo workflow.
- منع أكثر من طلب undo pending لنفس الضمان.
- منع test data creation عند `PRODUCTION_MODE=1`.

### ما الذي يصححه النظام بصمت

- Auto supplier/bank matching في مسارات متعددة.
- Auto status recalculation to `ready|pending`.
- Auto clearing لبعض `active_action` عند تغيّر بيانات (مسار save-and-next فقط).

### ما الذي يفرضه النظام بلا UI صريحة

- CSRF على mutating API (غير Bearer).
- Read-only policy لـ released.
- قيود break-glass (صلاحية + سبب + ticket + TTL clamp).

## 2.3 Redundancy-based Feature Detection

تكرار المنطق موجود في:

- المطابقة والتحديث بين `get-record`, `save-and-next`, `SmartProcessingService`.
- مسارات الاستيراد بين `import.php` و `parse-paste.php` و `import-email.php/save-import.php`.
- منطق release/extend/reduce مفردًا ودفعات.

الاستنتاج:

- جزء منه Missing Abstraction (ديون تصميمية).
- وجزء آخر Hidden Feature accretion (قدرات غير معلنة نشأت عبر تكرار سلوكي).

---

## 3) تدقيق ذكاء الـ Timeline (Timeline Intelligence Audit)

التحقق الفعلي:

- `history_version=v2` لكل السجلات (2908/2908).
- anchors = 1815 (62.41%).
- `snapshot_data` فارغ/NULL = 100% (مطابق لسياسة hybrid v2).
- `letter_snapshot` موجود في 205 حدث.

القدرات الناتجة:

- Forensic capability: قوي.
  - يمكن إعادة بناء الحالة قبل/عند الحدث (`reconstructStateBeforeEvent` / `resolveEventSnapshot`).
- Behavioral reconstruction: متاح.
  - Patch+anchor يعيد التسلسل المنطقي للتغييرات.
- Governance leverage: مرتفع.
  - يمكن الإسناد بين من غيّر ماذا ومتى ولماذا عبر event_details + actor.
- Implicit rollback ability: جزئي.
  - يوجد reopen governance يعيد الحالة إلى pending، لكن لا يوجد rollback شامل تلقائي كامل للـ raw_data من timeline snapshot.

---

## 4) State Transition Heatmap (كل الانتقالات الممكنة من الكود)

## 4.1 Guarantee Decision Status

| من | إلى | المحفز | الإثبات |
|---|---|---|---|
| (لا قرار) | pending | إنشاء قرار افتراضي/أول حفظ | `StatusEvaluator`, insert مسارات save/import |
| pending | ready | توفر supplier+bank | `StatusEvaluator::evaluate` + save/get-record/smart-processing |
| ready | pending | حذف/فقد أحد الحقلين أو reopen | `save-and-next`, `UndoRequestService::applyReopen` |
| ready | released | release/releaseBatch | `api/release.php`, `BatchService::releaseBatch` |
| released | pending | reopen direct أو execute undo | `UndoRequestService::applyReopen` |
| released | released | تعديل مع break-glass (بدون تغيير status) | `extend/reduce/upload` + mutation policy |
| ready | ready | extend/reduce/manual edits | endpoints + timeline modified events |

## 4.2 Workflow Step

| من | إلى | المحفز |
|---|---|---|
| draft | audited | workflow-advance |
| audited | analyzed | workflow-advance |
| analyzed | supervised | workflow-advance |
| supervised | approved | workflow-advance |
| approved | signed | workflow-advance |
| approved | approved (مع signature_received_n) | branch التوقيعات المتعددة (مشروط) |

ملاحظة تشغيلية: branch التوقيعات المتعددة شبه معطل فعليًا لأن `signaturesRequired()` يعيد 1.

## 4.3 Batch Status

| من | إلى | المحفز |
|---|---|---|
| active | completed | `closeBatch` |
| completed | active | `reopenBatch` (سبب إلزامي) |
| غير موجود | completed | close ينشئ metadata جديد |

## 4.4 Undo Request Status

| من | إلى | المحفز |
|---|---|---|
| none | pending | submit |
| pending | approved | approve |
| pending | rejected | reject |
| approved | executed | execute |

## 4.5 Scheduler Dead Letter Status

| من | إلى | المحفز |
|---|---|---|
| none | open | failure بعد retries |
| open | resolved | resolve action |
| open | retried | retry action |
| retried/resolved | open | failure لاحق لنفس run token (update) |

---

## 5) Dead-Branch Mining (الفروع النادرة/المعطلة)

1. `api/convert-to-real.php` ينشئ `GuaranteeRepository` بدون PDO constructor -> مسار معطل عمليًا.
2. `api/commit-batch-draft.php` يحدث عمود `guarantees.status_flags` غير موجود في schema runtime.
3. `api/create-guarantee.php` يكتب في `guarantee_occurrences.import_source` بينما الجدول فعليًا يحتوي `batch_type`.
4. `api/parse-paste-v2.php` يكتب إلى `guarantee_metadata` غير موجودة؛ الخطأ يُلتقط ويُخفى.
5. `TimelineDisplayService` يقوم بعمليتي `usort` متتاليتين لنفس البيانات (تكرار بلا قيمة تشغيلية).
6. branch التوقيعات المتعددة في workflow شبه غير قابل للوصول لأن required signatures = 1.
7. endpoint `api/history.php` retired دائمًا (410) -> legacy branch متروك عمدًا.
8. تفعيل صلاحيات `reopen_batch/reopen_guarantee` لا يكفي وحده بسبب حارس `manage_data` المسبق في endpoint.

---

## 6) التحليل متعدد الأدوار (Phase 2)

## 6.1 منظور المعماري

نقاط قوة:

- فصل نسبي جيد بين `Support/Services/Repositories`.
- مركزية أمن API bootstrap.
- Hybrid timeline design واضح وقابل للتتبع.

نقاط ضعف بنيوية:

- توازي مسارات import/workflow بشكل غير موحد.
- اقتران خفي UI/API عبر side effects.
- drift بين migration state والـ effective schema (فهارس timeline غير موجودة فعليًا رغم migration سجلت مطبقة).

## 6.2 منظور المهندس البرمجي

- Duplication مرتفع في المطابقة وتحديث القرار.
- dead branches واضحة (أمثلة أعلاه).
- naming/schema drift بين الكود والجداول في عدة endpoints.
- اختبارات wiring قوية شكليًا لكن coverage سلوكي object-level محدود.

## 6.3 منظور مهندس قواعد البيانات

- النموذج الهجين (raw + normalized + ledger) فعّال وظيفيًا.
- قيود FK/UNIQUE جيدة للأساسيات.
- نقص فهارس تشغيلية على مسارات القراءة الثقيلة.
- اعتماد كثيف على JSON/JSON-text يرفع كلفة الصيانة والتحسين.
- عدم وجود CHECK domain لحقول status/workflow يزيد مخاطر القيم الشاذة.

## 6.4 منظور الأمن

مخاطر حرجة:

- Object-level authorization gaps:
  - `index.php` يحمل ID مباشرًا بدون visibility filter.
  - `save-note`, `upload-attachment`, `save-and-next`, `workflow-advance` تعتمد login فقط بدون تحقق وصول record-level.
- رفع ملفات بدون allowlist للامتدادات/الأنواع + مسار تقديم ملفات مباشر.
- صلاحيات views الحساسة (batches/statistics/batch detail/print) login-only فعليًا بسبب ViewPolicy map.

ملاحظة:

- قابلية تنفيذ ملفات مرفوعة تعتمد على إعداد web server الفعلي خارج الريبو: **غير قابل للتحقق من الكود الحالي**.

## 6.5 منظور العمليات (DevOps)

- يوجد observability stack (metrics/alerts/dead letters/logging).
- scheduler لديه retry + dead-letter.
- failure silencing موجود في بعض المسارات (catch-and-continue) ويزيد احتمالية silent degradation.
- لا يوجد دليل داخل الكود على جدولة cron النظامية خارج `maint/schedule.php`: **غير قابل للتحقق من الكود الحالي**.

## 6.6 منظور الحوكمة والتدقيق

- traceability البنيوية قوية (timeline + print + audit trail + undo + break-glass).
- الاستخدام الفعلي لبعض مكونات الحوكمة منخفض جدًا حاليًا (undo/break-glass/settings audit/dead letters = 0 rows runtime).
- هذا يعني جاهزية آليات الحوكمة موجودة، لكن النضج التشغيلي الفعلي غير مكتمل.

## 6.7 منظور المستخدم النهائي

- سير العمل يحوي friction خفي بسبب `active_action` (جاهز لكن غير actionable).
- رسائل الأخطاء موجودة غالبًا لكن بعض المسارات تفشل بصمت.
- التباين بين status وworkflow step يزيد الحمل المعرفي (أغلب السجلات draft رغم ready/released).

---

## 7) البروتوكول الإلزامي: القوائم الكاملة

## 7.1 كل الميزات الصريحة

- Import (Excel/paste/manual/email).
- Matching (supplier/bank).
- Decision save.
- Action lifecycle (extend/reduce/release/reopen).
- Batch operations.
- Workflow advance.
- Timeline/history display.
- Notes/attachments.
- User/role/permission management.
- Settings & audits.
- Print events governance.
- Notifications.
- Scheduler + dead letters.

## 7.2 كل الميزات الضمنية

- auto-write during read (`get-record`).
- auto status recomputation.
- auto correction for supplier id/name mismatch.
- production-only filtering of test data.
- visibility bypass via direct ID on root page.

## 7.3 كل الميزات الناشئة

- governance mesh عبر دمج عدة جداول/خدمات أثر.
- effective "action suppression" عبر active_action + actionable filter.
- permission illusion: guardView موجود + test يمر، لكن map غير معرف => login-only.

## 7.4 كل السلوكيات الممكنة للحوكمة

- timeline patch/anchor reconstruction.
- print audit by guarantee/batch.
- API access denied trail.
- settings change-set logging.
- break-glass event recording.
- undo dual-step governance with approval/execute split.
- scheduler dead-letter lifecycle.

## 7.5 كل سلوكيات الأتمتة

- smart processing post-import.
- auto supplier/bank match (عدة مسارات).
- auto duplicate occurrence recording.
- scheduler retry ثم dead-letter.
- auto alerts from metrics thresholds.

## 7.6 كل آليات الحماية

- RBAC عبر Guard + role_permissions.
- login rate limiting.
- CSRF enforcement (session clients).
- read-only mutation policy on released.
- break-glass hard gates (reason/ticket/TTL/permission).
- self-approval prevention in undo.

## 7.7 كل القيود المخفية

- `reopen_*` permissions لا تكفي بسبب guard `manage_data` المسبق.
- views غير المعرّفة في ViewPolicy map = login-only تلقائيًا.
- actionable queue متأثرة جذريًا بـ active_action غير المصفر.
- fallback imports legacy قد يكتب إلى مسارات/نماذج مختلفة عن المسار الحديث.

## 7.8 كل الافتراضات الصامتة

- assumption أن المستخدم الموثق يمكنه mutation في بعض endpoints الحساسة.
- assumption أن migration-applied يعادل schema-consistent (غير صحيح دائمًا).
- assumption أن workflow permission يكفي بدون data-state guard.
- assumption أن بيئة الويب لن تنفذ ملفات مرفوعة.

---

## 8) القياسات الكمية (Phase 3)

| المؤشر | القيمة | التبرير الفني |
|---|---:|---|
| Architectural Coherence Score | 71/100 | هيكل services/repositories واضح، لكن توازي المسارات والتكرار عالي. |
| Logical Integrity Index | 57/100 | تناقضات صلاحيات + drift schema/code + فروع معطلة. |
| Governance Depth Index | 78/100 | منظومة أثر واسعة فعليًا (timeline/print/audit/undo/break-glass/dead-letter). |
| Risk Exposure Index | 73/100 | ثغرات object-level + upload hardening gaps + view authorization gaps. |
| Hidden Fragility Score | 81/100 | تغييرات صغيرة في status/active_action/visibility تؤثر على سلوك واسع. |
| Silent Failure Probability | 34% | catch-and-continue في مسارات متعددة + فروع legacy + mismatch schema محتمل. |
| Change Propagation Risk Level | High | coupling بين UI filters, decision state, timeline, batch operations. |
| Automation Ratio | 10.1% | (51 ai_match + 4 auto_create + 1 auto_match_bank) / 552 decisions. |
| Human Error Resistance Index | 62/100 | safeguards جيدة، لكن صلاحيات object-level ناقصة وسلوكيات implicit عالية. |
| Long-Term Survivability Index | 56/100 | قابل للبقاء مع refactoring حوكمي؛ الوضع الحالي يحمل debt بنيوي واضح. |

---

## 9) المخاطر البنيوية والتهديدات (Phase 4)

### 9.1 مخاطر نظامية

- صلاحيات record-level غير محكومة بشكل موحد عبر كل endpoints/views.
- تباين صلاحيات الدور (role design) مع guards الفعلية في endpoints.
- drift بين الهياكل المتوقعة والهياكل الفعلية للجداول في فروع متعددة.

### 9.2 مناطق fragility العالية

- `status`/`active_action`/`workflow_step` تفاعلها عالي الحساسية.
- مسارات import المتعددة (حديث/قديم) قد تنتج سلوكًا متباينًا.
- timeline stack حساس لأي تغيّر في contract الحدث.

### 9.3 Change Amplification

- تعديل بسيط في mapping الصلاحيات أو status logic قد يعيد تشكيل visibility/actionability عبر النظام كله.
- أي تغيير في schema occurrence/history ينعكس على import/timeline/batch في وقت واحد.

### 9.4 عدم اتساق بين النية والتنفيذ

- نية role-scoped visibility موجودة، لكن root access المباشر وبعض endpoints الالتقاطية تضعفها.
- نية reopen governance موجودة، لكن تركيب permission checks الحالي يحدها على أدوار غير متوقعة.

---

## 10) التقرير التنفيذي النهائي (Phase 5)

## 10.1 كيف يعمل WBGL فعليًا

WBGL يعمل كمنظومة Monolith هجينة: بيانات خام للضمان + قرار تشغيلي منفصل + سجل timeline هجين.  
المعالجة ليست فقط عند الحفظ؛ توجد نقاط قراءة تقوم بالكتابة تلقائيًا.  
منظومة الحوكمة موجودة وظيفيًا بعمق، لكنها موزعة على عدة وحدات وتتطلب توحيد إنفاذ الصلاحيات على مستوى الكيان.

## 10.2 نقاط القوة (مع دلائل)

- Hybrid timeline reconstruction مضبوط تقنيًا (`TimelineHybridLedger`, `TimelineRecorder`).
- Policy gates مهمة موجودة (CSRF, rate limit, mutation policy, break-glass hard checks).
- بنية RBAC أساسية منظمة (roles/permissions/guard).
- وجود observability طبقي (metrics + alerts + dead letters + logs).

## 10.3 نقاط الضعف البنيوية

- Object-level authorization غير موحد.
- View-level permission mapping ناقص يفتح صفحات حساسة لمجرد login.
- schema/code drift في endpoints حرجة.
- duplication في matching/import/action flows.
- غياب فهارس تشغيلية متوقعة على timeline hot paths.

## 10.4 المخاطر التشغيلية

- تعديل أو قراءة سجل قد يغير حالته ضمنيًا في سياقات معينة.
- احتمالية أخطاء صامتة في فروع legacy أو فروع catch-and-continue.
- قابلية اتساع أثر أي تعديل بسيط في status/action filters.

## 10.5 الدين التقني الخفي

- أكواد endpoint غير متوافقة مع schema الحالي.
- فروع workflow/reopen/legacy غير مستعملة أو متعارضة مع صلاحيات الدور.
- اختبارات wiring قوية شكليًا لكنها لا تغطي object-level semantics.

## 10.6 نضج الحوكمة

- نضج بنيوي: مرتفع.
- نضج تشغيلي فعلي: متوسط إلى منخفض (استعمال منخفض لآليات critical governance runtime).

## 10.7 جاهزية المؤسسة (Enterprise Readiness)

- جاهزية وظيفية: جيدة.
- جاهزية أمنية/حوكمية شاملة: متوسطة مع فجوات حرجة في الإنفاذ الموحد.

## 10.8 الاستدامة طويلة المدى

- قابلة للاستدامة فقط مع ضبط التوافق بين الصلاحيات، المخطط، ومسارات التنفيذ المتوازية.
- الوضع الحالي قابل للعمل لكنه حساس للتغييرات.

## 10.9 موثوقية القرار والـ Timeline

- موثوقية timeline reconstruction: عالية تقنيًا.
- موثوقية الحوكمة العملية للقرار: متوسطة بسبب فجوات authorization drift والتباين بين المسارات.

---

## 11) ملحق الأدلة (Code Evidence Pointers)

- Direct ID load بدون visibility filter: `index.php:73`, `index.php:113`.
- Visibility filter موجود لكن عبر Navigation فقط: `app/Services/NavigationService.php:69`.
- GuardView map محدود: `app/Support/ViewPolicy.php:14-18`.
- Views حساسة تستخدم guardView لكن غير mapped: `views/batches.php:12`, `views/batch-detail.php:13`, `views/batch-print.php:15`, `views/statistics.php:11`.
- `get-record` يكتب تلقائيًا: `api/get-record.php:208-254`, `api/get-record.php:284-328`.
- `save-and-next` login-only + mutation + auto-correct: `api/save-and-next.php:18`, `api/save-and-next.php:180-188`, `api/save-and-next.php:399`, `api/save-and-next.php:504`.
- `workflow-advance` login-only + canAdvance permission-only: `api/workflow-advance.php:16`, `app/Services/WorkflowService.php:58`.
- `signaturesRequired=1`: `app/Services/WorkflowService.php:100`.
- upload بلا allowlist: `api/upload-attachment.php:37-41`, `api/upload-attachment.php:61`.
- router يمرر unknown extensions للخادم: `server.php:29`.
- reopen guard mismatch: `api/reopen.php:16`, `api/reopen.php:42`.
- batch reopen guard mismatch: `api/batches.php:14`, `api/batches.php:135`.
- schema drift أمثلة:
  - `api/convert-to-real.php:27`
  - `api/commit-batch-draft.php:55`
  - `api/create-guarantee.php:118`
  - `api/parse-paste-v2.php:142`
- timeline display sort مكرر: `app/Services/TimelineDisplayService.php:89`, `:94`.
- current-state snapshot لا يعيد `active_action` بينما UI يتوقعها: `api/get-current-state.php:138`, `public/js/timeline.controller.js:446`.

---

## 12) خلاصة البروتوكول

No additional feature-level behavior is extractable from code structure.

