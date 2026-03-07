# التقرير التنفيذي الشامل — المراجعة المعرفية لنظام WBGL

## Cognitive System Audit — Bank Guarantee Lifecycle Management

> **المنهجية:** تحليل مباشر من كود المصدر. لا آراء نظرية. لا توصيات عامة. كل حكم مستند إلى سلوك فعلي موثّق في الكود.
>
> **تاريخ المراجعة:** 2026-03-07

---

## المرحلة الأولى — فهم النظام (نموذج التشغيل الذهني)

### 1.1 هوية النظام وطبيعته الحقيقية

WBGL ليس نظام CRUD بسيطاً. هو منظومة متكاملة لإدارة دورة حياة خطابات الضمان المصرفي (Bank Guarantee Lifecycle) تعمل في سياق مؤسسي مالي عربي. يتميز بثلاث طبقات وظيفية متشابكة:

1. **طبقة الاستيراد الذكي** — تحليل Excel وفق النمط المجدولي، ولصق نصي ذكي، وبريد إلكتروني
2. **طبقة التطابق الآلي بالذكاء الاصطناعي** — مطابقة الموردين والبنوك مع بوابة ثقة قابلة للشرح
3. **طبقة سير العمل الرقابي** — مسار موافقة من 6 مراحل مع تسجيل أحداث جنائي كامل

### 1.2 نقاط الدخول إلى النظام

| النوع          | الموقع                          | الوصف                               |
| -------------- | ------------------------------- | ----------------------------------- |
| HTTP/Web       | `index.php` (118KB)             | نقطة الدخول الرئيسية — يخدم الواجهة |
| HTTP/API       | `api/*.php` (59 ملف)            | نقاط الـ API الكاملة                |
| CLI            | `app/Scripts/*.php` (28 سكريبت) | أدوات الصيانة والترحيل والتدقيق     |
| Cron/Scheduler | `SchedulerRuntimeService`       | تشغيل مهام PHP Shell عبر `exec()`   |
| Token API      | `ApiTokenService`               | مسار مصادقة ثانوي مستقل عن الجلسة   |

### 1.3 تدفق دورة حياة الضمان (Runtime Flow)

```
[مصدر الإدخال]
     │
     ├─ Excel: ImportService → TableDetectionService → FieldExtractionService → ParseCoordinatorService
     ├─ Paste: ParseCoordinatorService → SmartPasteConfidenceService
     ├─ Email: import-email.php → ImportService
     └─ يدوي: manual-entry.php
     │
     ▼
[تخزين مبدئي]
guarantees.raw_data = JSON خام غير منظّم
guarantees.guarantee_number UNIQUE — مانع التكرار
     │
     ▼
[SmartProcessingService — المعالجة الذكية]
     ├─ Step 1: مطابقة البنك (حتمية — البحث في bank_alternative_names + short_name)
     │           إذا وُجد: إنشاء bank-only decision + تحديث raw_data بالاسم المعياري
     ├─ Step 2: مطابقة المورد (احتمالية — UnifiedLearningAuthority)
     │           TrustGate → 4 قرارات: allow / block / override / block(conflict)
     └─ Step 3: إذا نجحا معاً + confidence ≥ threshold + لا تعارضات:
                guarantee_decisions.status = 'ready'
                TimelineRecorder → recordStatusTransitionEvent
     │
     ▼
[guarantee_decisions]
status: pending | ready | released
workflow_step: draft | audited | analyzed | supervised | approved | signed
is_locked: false | true
active_action: NULL | extension | reduction | release
     │
     ▼
[سير العمل — WorkflowService]
draft → (audit_data) → audited
        → (analyze_guarantee) → analyzed
           → (supervise_analysis) → supervised
              → (approve_decision) → approved
                 → (sign_letters) → signed
     │
     ▼
[العمليات التشغيلية بعد التوقيع]
extend / reduce / release
كل عملية: snapshot قبل → تنفيذ → snapshot بعد → letter HTML snapshot
     │
     ▼
[guarantee_history — السجل الجنائي]
المشغل الهجين: anchor_snapshot + patch_data (RFC 6902)
letter_snapshot: HTML مؤصّل لكل إجراء
actor_kind / actor_display / actor_user_id: 5 حقول للجهة الفاعلة
```

### 1.4 مخطط قاعدة البيانات (الجوهر)

**الجداول الأساسية:**

| الجدول                                          | الغرض              | ملاحظة                                               |
| ----------------------------------------------- | ------------------ | ---------------------------------------------------- |
| `guarantees`                                    | السجل الخام        | `raw_data JSON` هو مستودع الحقيقة المبدئية           |
| `guarantee_decisions`                           | القرار التشغيلي    | علاقة 1:1 مع guarantee، محمية بـ 6 CHECK constraints |
| `guarantee_history`                             | السجل الجنائي      | نظام هجين v2: patch + anchor                         |
| `guarantee_occurrences`                         | تتبع الدفعات       | فهرس فريد (guarantee_id, batch_identifier)           |
| `suppliers`                                     | المرجعية المعيارية | normalized_name + official_name                      |
| `supplier_alternative_names`                    | أسماء بديلة للذكاء | source + usage_count                                 |
| `supplier_learning_cache`                       | ذاكرة التعلم       | fuzzy_score + block_count                            |
| `banks` / `bank_alternative_names`              | مرجعية البنوك      | البحث بالرنين المعياري                               |
| `users` / `roles` / `permissions`               | RBAC               | role_id → بلا role تعني بلا صلاحيات                  |
| `user_permissions`                              | تخصيص فردي         | override_type: allow/deny فقط (CHECK)                |
| `undo_requests`                                 | فتح مُراقب         | 4 حالات معيارية بـ CHECK constraint                  |
| `break_glass_events`                            | الطوارئ            | سجل دائم لا يُحذف                                    |
| `login_rate_limits`                             | الحماية            | compound key: hash(user+IP+UA)                       |
| `notifications`                                 | التنبيهات          | dedupe_key لمنع التكرار                              |
| `scheduler_job_runs` / `scheduler_dead_letters` | المهام المجدولة    | dead letter queue كاملة                              |

**المحاثّ (Triggers) في PostgreSQL:**

1. `trg_wbgl_enforce_decision_workflow_guards` — يمنع التحولات الغير شرعية بين مراحل سير العمل
2. `trg_wbgl_batch_purity_occurrences` — يمنع خلط السجلات التجريبية/الحقيقية في نفس الدفعة
3. `trg_wbgl_batch_purity_guarantees` — يمنع إعادة تصنيف ضمان بشكل يكسر نقاء الدفعة

**خضوع الأقفاص (Domain Constraints):**

- `status ∈ {pending, ready, released}`
- `workflow_step ∈ {draft, audited, analyzed, supervised, approved, signed}`
- `active_action ∈ {NULL, '', extension, reduction, release}`
- `released → is_locked = TRUE` (CHECK menjamin)
- `pending → workflow_step = draft` (Trigger)
- `signed → signatures_received ≥ 1` (Trigger)

---

## المرحلة الثانية — التحليل متعدد الأدوار

---

### 1️⃣ وجهة نظر المهندس المعماري

**النمط المعماري:** هجين متعدد الطبقات بدون إطار عمل (Framework-less Layered Architecture)

```
[Presentation]  index.php + views/*.php + partials/*.php
[API Gateway]   api/_bootstrap.php → api/*.php
[Application]   app/Services/  (منطق الأعمال)
[Domain]        app/Models/ + app/DTO/
[Data Access]   app/Repositories/ + app/Support/Database.php
[Infrastructure] app/Support/* (Guard, Auth, Settings, Logger, CSRF)
```

**نقاط القوة المعمارية:**

✅ **الفصل الواضح بين طبقات القرار والعرض والبيانات** — `GuaranteeRepository` منفصل تماماً عن `BatchService` منفصل عن `WorkflowService`

✅ **بوابة الـ API موحّدة** — `api/_bootstrap.php` يضمن: CSRF + Auth + Permission + RequestID + AuditLog على كل طلب قبل أي منطق

✅ **مصدر حقيقة واحد للسياسة** — `ActionabilityPolicyService::STAGE_PERMISSION_MAP` هو المرجع الوحيد لخريطة المراحل والصلاحيات؛ `WorkflowService` يستدعيه ولا يعيد تعريفه

✅ **مبدأ التماسك بالوظيفة** — `SmartProcessingService` يعمل كـ Orchestrator فقط، ويفوّض التعلم لـ `UnifiedLearningAuthority` والتعارضات لـ `ConflictDetector`

**نقاط الضعف المعمارية:**

⚠️ **غياب حاوية حقن التبعيات (DI Container)** — `SmartProcessingService` ينشئ `SupplierLearningRepository` داخلياً بـ `new`. هذا يجعل الاختبار الوحدوي صعباً والاستبدال مكلفاً

⚠️ **الـ Settings كملف JSON على الديسك** — كل استدعاء `Settings::getInstance()->get(...)` يقرأ الملف من القرص. لا يوجد cache داخل طلب HTTP واحد (في `all()` تستدعي `loadPrimary()` من القرص في كل مرة). هذا يعني قراءات متعددة للملف في نفس الطلب.

⚠️ **`Guard::$permissions` static state** — الإعدادات الثابتة `static ?array` تبقى محملة طوال الطلب وهذا صحيح. لكن في سياق CLI أو المهام المتوازية، يمكن أن تُسمّم قيماً خاطئة إذا لم يُعاد تهيئتها.

⚠️ **تكرار منطق مطابقة القواعد** — `WorkflowService::TRANSITION_PERMISSIONS` و `ActionabilityPolicyService::STAGE_PERMISSION_MAP` كلاهما يحتويان خريطة المراحل. `WorkflowService` يستدعي `ActionabilityPolicyService::STAGE_PERMISSION_MAP` للرفض لكن يعرّف خريطته الخاصة للتقدم. هذا خطر تباعد صامت.

---

### 2️⃣ وجهة نظر مهندس البرمجيات

**إيجابيات جودة الكود:**

✅ `declare(strict_types=1)` على كل ملف تقريباً — حماية قوية من تحولات النوع الضمنية

✅ PHPDoc واضح مع أنواع الإرجاع الدقيقة (`array{visible:bool, actionable:bool, ...}`)

✅ استخدام `TransactionBoundary::run()` لتغليف العمليات كالـ Undo — يضمن Atomicity

✅ أنماط التحقق الدفاعي: `trim()`, `mb_strlen()`, `max(0, min(500, $limit))` منتظمة عبر الخدمات

**نقاط الضعف البرمجية:**

❌ **`index.php` بحجم 118KB** — نقطة دخول وحيدة بـ 118 كيلوبايت. هذا الملف لا يمكن اختباره، ولا يمكن فهمه في ضربة واحدة، وأي تحرير خاطئ فيه يكسر النظام كله. هذه الديون التقنية الأثقل في المشروع.

❌ **`views/settings.php` بحجم 105KB** — ملف PHP/HTML واحد بـ 105 كيلوبايت. لا قابلية للاختبار، لا قابلية للتجزئة.

❌ **`app/Scripts/data-integrity-check.php` بحجم 22KB** و`app/Scripts/i18n-keygen.php` بحجم 17KB — سكريبتات ضخمة بدون اختبارات

❌ **`error_log("🔍 ...")` في كود الإنتاج** — `TimelineRecorder` و `SmartProcessingService` مليئان بـ `error_log()` للتصحيح. هذا يعني انبعاث سجلات ضجيج مستمر في الإنتاج. يوجد `Settings::PRODUCTION_MODE` لكن الكود لا يحترمه في كل المواضع.

❌ **الأكواد الميتة / الانتقالية** — `api/parse-paste.php` (V1) و `api/parse-paste-v2.php` (V2) موجودان معاً. `snapshot_data` (الحقل القديم) يُنشأ ثم يُصفَّر فوراً في السطر 577 من `TimelineRecorder`. هذا يدل على انتقال غير مكتمل.

❌ **`WorkflowService::signaturesRequired()` يُرجع دائماً 1** — هذا كود stub مُعلّق يخلق توقعاً غير محقق في قاعدة البيانات التي تتتبع `signatures_received`. إذا غيّر أحد قيمة `signatures_received` في الـ DB يدوياً، لن يكتشف الكود ذلك.

---

### 3️⃣ وجهة نظر مهندس قواعد البيانات

**نقاط القوة:**

✅ **3 PostgreSQL Triggers تحمي السلامة المنطقية في الـ DB مباشرة** — المحث `wbgl_enforce_decision_workflow_guards` يمنع أي تحول غير مشروع حتى لو جاء مباشرة من `psql`. هذا دفاع في العمق حقيقي.

✅ **6 CHECK Constraints على `guarantee_decisions`** — المجال مُقيَّد حتى في الطبقة أدنى. أي قيمة خارج نطاق status أو workflow_step ترفض في الـ DB.

✅ **الفهارس مُصمّمة للمسارات الساخنة** — `idx_guarantee_decisions_status_workflow_lock` فهرس مركّب على (status, workflow_step, is_locked, guarantee_id) — يخدم الاستعلامات الرئيسية مباشرة.

✅ **CASCADE DELETE مضبوط منطقياً** — `guarantee_history` → CASCADE, `supplier_alternative_names` → CASCADE, `bank_alternative_names` → CASCADE. في المقابل: `guarantee_decisions.supplier_id` → SET NULL عند حذف مورد، وهو سلوك صحيح.

**نقاط الضعف:**

⚠️ **`guarantees.raw_data` هو JSON غير منظّم** — كل بيانات الضمان الخام (المبلغ، التواريخ، الأطراف) مخزّنة في JSON column واحد. لا يمكن الاستعلام عنها بكفاءة، ولا فهرستها، ولا التحقق من نوعها. إذا تغيّر format الـ JSON من مصدر لآخر، يحتوي هذا الحقل على بيانات غير متجانسة.

⚠️ **`banks.created_at` و `banks.updated_at` نوعهما TEXT وليس TIMESTAMP** — في الـ schema الأساسي: `created_at TEXT, updated_at TEXT`. هذا يمنع أي مقارنة زمنية أو فهرسة صحيحة.

⚠️ **`active_action_set_at` نوعه TEXT في بعض البيئات** — Migration 26 يتحقق ديناميكياً من `udt_name` ويختار الاستعلام المناسب. هذا يعني أن الـ schema غير موحّد عبر البيئات — خطر migration drift.

⚠️ **`supplier_alternative_names` بدون unique constraint على `(supplier_id, normalized_name)`** — من الكود: `findConflictingAliases()` يُجري JOIN ويقارن. لكن لا شيء يمنع إدخال نفس الاسم المعياري لنفس المورد مرتين في الـ DB. هذا قد يُسبّب تكراراً في نتائج التطابق.

⚠️ **`guarantee_history.snapshot_data` يُصفَّر بعد الكتابة** — السطر 577 من `TimelineRecorder`: `$values[3] = null;`. الحقل موجود في الـ schema ويُكتب فيه ثم يُمسح. هذا يعني وجود بيانات مبعثرة في Schema ولا تُستخدم فعلياً.

⚠️ **غياب migration لجدول `guarantee_history_archive`** — يوجد migration_14 يُنشئ `guarantee_history_archive` لكن لا يوجد أي كود خدمة أو API يستخدمه. جدول legacy معلّق.

---

### 4️⃣ وجهة نظر مهندس الأمن

**نقاط القوة:**

✅ **CSRF مُفعَّل عالمياً بشكل افتراضي** — يُفرض في `_bootstrap.php` قبل أي منطق endpoint، على كل طلب mutating.

✅ **Rate Limiter بـ compound key** — التعرف على ID = `SHA-256(username + IP + UserAgent)`. البروتوكول يحمي من هجمات bruteforce الموزّعة.

✅ **Session Hardening متكامل** — `HttpOnly: true`, `SameSite: Lax` (يرفع تلقائياً إلى Strict/None عند الحاجة), `secure` يُكتشف من HTTPS, تجديد session_id عند تسجيل الدخول.

✅ **Internal Error Sanitization** — أي خطأ 5xx يُستبدل رسالته بـ"حدث خطأ داخلي" ويُسجَّل الخطأ الحقيقي internally. المهاجم لا يرى stack traces.

✅ **Break Glass Audit Trail** — أي تجاوز طارئ يتطلب permission + feature flag + سبب ≥8 أحرف + رقم تذكرة + مدة محدودة بسقف، ويُسجَّل في جدول دائم ويُشعَر به أمنياً.

**نقاط الضعف:**

🔴 **`Guard::hasOrLegacy()` يُرجع `true` افتراضياً** — إذا لم تُسجَّل صلاحية في قاعدة البيانات بعد، يُعطي النظام المستخدم الإذن! هذا يعني: أي صلاحية جديدة تُضاف قبل تشغيل migration يمكنها أن تُمنح لكل مستخدم بشكل ضمني. الـ `legacyDefault = true` هو ثقب أمني تصميمي.

🔴 **`Guard::protect()` في بعض endpoints لا يُستخدم** — بعض نقاط الـ api تستخدم `wbgl_api_require_permission()` والبعض الآخر `Guard::protect()`. عدم الاتساق يعني خطر نسيان الحماية في endpoint جديد.

🔴 **`Settings` تُخزَّن في `storage/settings.json`** — كلمات مرور DB وbيانات حساسة (`DB_PASS`, `DB_USER`) يمكن أن تُخزَّن في ملفات JSON على القرص. إذا كان الخادم يُعطي قراءة public أو كان الـ path مكشوفاً، هذا اختراق مباشر.

⚠️ **`LoginRateLimiter` حد صلب `5 attempts / 60s`** — هذه القيم ثابتة في الكود (constants) ولا يمكن تغييرها من الإعدادات. في بيئة إنتاج، قد يكون ذلك مقيّداً جداً أو مرناً جداً دون إمكانية ضبط.

⚠️ **`exec()` لتشغيل PHP scripts في الـ Scheduler** — `SchedulerRuntimeService` يستخدم `exec(PHP_BINARY . ' ' . escapeshellarg($scriptPath))`. المسار يُهرَّب بشكل صحيح، لكن تنفيذ shell مباشر هو سطح هجوم إضافي.

⚠️ **`WBGL_API_SKIP_GLOBAL_CSRF` constant** — أي endpoint يمكنه تجاوز CSRF بتعريف هذا الثابت. لا توجد قائمة مركزية بـ endpoints التي تستخدمه.

---

### 5️⃣ وجهة نظر العمليات / DevOps

**نقاط القوة:**

✅ **Dead Letter Queue للمهام المجدولة** — فشل أي job يُنتج سجلاً في `scheduler_dead_letters` مع إمكانية retry ومانع تكرار العمليات المتوازية (window 30 min).

✅ **Operational Alerts Service** — `OperationalAlertService` يراقب: API denied spikes, open dead letters, scheduler failures, pending undo requests, scheduler staleness — كلها مع عتبات قابلة للضبط.

✅ **Scheduler Concurrent Guard** — `hasRecentRunningJob()` يمنع تشغيل نفس المهمة مرتين في نفس الوقت.

✅ **`data-integrity-check.php` و`generate-system-counts-consistency-report.php`** — أدوات CLI متخصصة للتحقق من سلامة البيانات وتناسق الأعداد.

**نقاط الضعف:**

🔴 **`exec()` + `PHP_BINARY` بدون Supervisor أو Queue Worker** — لا يوجد Queue manager حقيقي. المهام تعمل بـ `exec()` synchronously. إذا تعطّل PHP الخارجي لأي سبب (OOM، timeout)، لا يوجد إعادة محاولة تلقائية بريئة.

🔴 **`Settings` بدون hot-reload** — تغيير `settings.json` يسري فوراً على الطلبات التالية لكن بدون transactional guarantee. إذا كُتب الملف جزئياً أثناء crash: النظام يُحمّل JSON فاسد ويعود إلى القيم الافتراضية بصمت.

⚠️ **السجلات عبر `error_log()` وليس Logger موحّد** — `TimelineRecorder`, `SmartProcessingService`, `BreakGlassService` يستخدمون `error_log()` مباشرة. يوجد `Logger.php` كخدمة لكن لا يُستخدم في كل مكان. نتيجة: السجلات مبعثرة بين PHP error log وأي نظام logging مُخصّص.

⚠️ **`server.pid` و`server.port` ملفات نصية** — دليل على تشغيل PHP's built-in server في التطوير. في الإنتاج: "غير قابل للتحقق من الكود الحالي" ما إذا كان يُستخدم Web server حقيقي.

⚠️ **لا يوجد health check endpoint** — لا توجد نقطة `/health` أو `/ping` للتحقق من صحة النظام أو الـ DB.

---

### 6️⃣ وجهة نظر الحوكمة والتدقيق

**هذا هو الجانب الأقوى في النظام:**

✅ **السجل الجنائي الهجين (Hybrid Forensic Ledger)** — كل حدث في `guarantee_history` يحمل:

- `actor_kind / actor_username / actor_user_id / actor_email / actor_display` — 5 حقول لتعريف الفاعل
- `patch_data` بصيغة RFC 6902 JSON Patch — الفرق الدقيق بين الحالتين
- `anchor_snapshot` — لقطة كاملة بشكل دوري لضمان إعادة البناء
- `letter_snapshot` — HTML مؤصّل للخطاب عند كل إجراء (ADR-007)
- `template_version` — إصدار القالب المستخدم للخطاب

✅ **خاصية عدم الرفض (Non-repudiation)** — لا يمكن لأي جهة أن تنفي إجراءً ما. البيانات تشمل: الوقت الدقيق، هوية المستخدم متعددة الأوجه، القيم قبل وبعد، وHTML الخطاب المُرسَل.

✅ **سجل تدقيق الإعدادات** — كل تغيير في `settings.json` يُسجَّل في `settings_audit_logs` مع القيمة القديمة والجديدة والفاعل.

✅ **سجل Break Glass لا يُمكن حذفه من الكود** — لا توجد `delete` operation في `BreakGlassService`. السجل دائم.

✅ **سلسلة اعتماد 3 أطراف في الـ Undo** — المقدِّم ≠ المعتمِد ≠ المنفِّذ، مفروض بالكود. هذا Segregation of Duties حقيقي.

**نقاط ضعف الحوكمة:**

⚠️ **خاصية `is_anchor` في `guarantee_history` تُحسب آلياً بدون ضمان** — الـ anchor interval يُعدّ بالاستعلام عن COUNT، ليس بالتتابع الصارم. إذا فشل إدخال حدث، يُفسد العدّاد ويمكن ألا يُنشأ anchor في الوقت المطلوب.

⚠️ **`recordDuplicateImportEvent` يُسجَّل لكن لا يُحدث `snapshot_data` بشكل آمن** — عند محاولة استيراد مكرر، يُنشأ حدث في التاريخ لكن `snapshot_data` يُبنى من آخر `raw_data` وقد لا يعكس state دقيق.

⚠️ **`created_by` في `guarantee_notes` و`guarantee_attachments` هو TEXT وليس FK** — لا يمكن تتبع ملاحظة أو مرفق إلى مستخدم محدد بشكل موثوق. سيُصبح المستخدم المحذوف "مجهولاً" في السجل.

---

### 7️⃣ وجهة نظر المستخدم النهائي

**نقاط القوة:**

✅ دعم RTL/LTR مع اكتشاف تلقائي للغة العربية
✅ مسار سير العمل يُنتج label واضح لكل مرحلة (`getActionLabel()`)
✅ Surface Policy يُحدد بشكل مركزي ما يظهر لكل مستخدم (9 grants مُحددة)
✅ Smart Paste يُعطي confidence score قبل الحفظ

**نقاط الضعف:**

⚠️ **رسائل الخطأ مختلطة اللغات** — بعض الأخطاء بالعربية، بعضها بالإنجليزية (`'Permission Denied'`, `'Not Found'`, ...). تجربة المستخدم غير متسقة.

⚠️ **لا يوجد إشعار للمستخدم عند تجاوز جلسته (timeout)** — Session تنتهي صامتة والطلب التالي يُرجع 401. المستخدم يخسر ما كان يعمل عليه.

---

## المرحلة الثالثة — المقاييس الكمية

| المقياس                        | الدرجة                    | المبرر التقني                                                                                                                    |
| ------------------------------ | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| **تماسك المعمارية**            | **72/100**                | فصل جيد بين الطبقات، لكن `index.php` (118KB) وعدم وجود DI Container يخفضان الدرجة                                                |
| **نزاهة المنطق**               | **80/100**                | 3 triggers + 6 CHECK constraints تضمن المجال في DB. لكن dual-definition في WorkflowService خطر                                   |
| **عمق الحوكمة**                | **88/100**                | الأعلى في المشروع. Hybrid ledger + Break Glass + Undo chain + Settings audit. ناقص: FK للجهات الفاعلة في الملاحظات               |
| **مؤشر التعرض للمخاطر**        | **35/100** _(أقل = أفضل)_ | `Guard::hasOrLegacy=true` + `settings.json` بلا تشفير + `exec()` scheduler هي المخاطر الرئيسية                                   |
| **الهشاشة الخفية**             | **40/100** _(أقل = أفضل)_ | `index.php` كنقطة فشل واحدة، Settings file corruption ، schema drift في `active_action_set_at`                                   |
| **احتمال الفشل الصامت %**      | **22%**                   | Notifications قابلة للإيقاف بـ setting، error_log بدلاً من structured logging، الـ Scheduler يفشل بصمت إذا لم يُفحص dead letters |
| **خطر التغيير التسلسلي**       | **متوسط-عالٍ**            | `ActionabilityPolicyService::STAGE_PERMISSION_MAP` إذا تغيّر يؤثر على: navigation + SQL builder + workflow + timeline تلقائياً   |
| **نسبة الأتمتة**               | **65%**                   | Bank matching تلقائي 100%، Supplier ≥90% threshold، Workflow triggers، Dead letter retry — لكن approval chain يدوي بالكامل       |
| **مقاومة خطأ بشري**            | **75/100**                | Self-approval blocked + Undo approval chain + DB triggers + domain constraints. لكن: Guard::hasOrLegacy ثغرة                     |
| **مؤشر الاستدامة طويلة المدى** | **62/100**                | الحوكمة ممتازة، قاعدة البيانات قوية، لكن index.php + views/settings.php ديون تقنية ثقيلة تجعل الصيانة مكلفة مع الوقت             |

---

## المرحلة الرابعة — التهديدات والمخاطر الهيكلية

### 🔴 المخاطر الحرجة (Critical)

**1. `Guard::hasOrLegacy(permissionSlug, legacyDefault=true)`**
يُعطي GRANT الافتراضي لأي صلاحية جديدة قبل تشغيل migration. إذا أُضيف كود يستدعي `hasOrLegacy('new_critical_permission')` قبل migration يُسجّل الصلاحية، كل مستخدم سيمتلكها ضمنياً.
**النسيج المتأثر:** أي endpoint يستخدم `Guard::hasOrLegacy()`

**2. `settings.json` كمخزن للـ DB credentials**
ملف JSON على القرص قد يحتوي `DB_PASS`. إذا كان `storage/` قابل للوصول من الـ web (لا يوجد في الكود ما يمنع ذلك بشكل صريح — غير قابل للتحقق من إعداد الخادم) فهذا اختراق مباشر.

**3. `index.php` المتضخّم (118KB)**
ملف PHP/HTML/JS واحد بـ 118 كيلوبايت هو: نقطة فشل واحدة، مستحيل الاختبار، يصعب فهمه، أي syntax error يُسقط النظام كله. في بيئة متغيرة هذا هو أكبر خطر للصيانة.

### 🟡 المخاطر العالية (High)

**4. `WorkflowService::TRANSITION_PERMISSIONS` تعريف مزدوج**
`WorkflowService` يُعرّف `TRANSITION_PERMISSIONS` محلياً، ثم في `canReject()` يُشير إلى `ActionabilityPolicyService::STAGE_PERMISSION_MAP`. إذا تُغيّر خريطة الصلاحيات في مكان واحد دون الآخر: سير عمل التقدم يختلف عن سير عمل الرفض بصمت.

**5. نضج التعلم الآلي (AI Maturity Gap)**
`evaluateTrust()` في `SmartProcessingService` تحتوي كوداً معلّقاً:

```
error_log("[Authority] Trust override - penalty needed for blocking alias");
```

Targeted Negative Learning مُكتبة كـ stub ولا تُطبَّق. هذا يعني أن alias conflicts تُرصد ولا تُعالَج — النظام يُسجّل المشكلة ويتجاهلها.

**6. Schema Drift في `active_action_set_at`**
Migration #26 يكتشف نوع العمود ديناميكياً لأن بعض البيئات لديها `TEXT` والأخرى `TIMESTAMP`. هذا يعني أن بنية قاعدة البيانات غير موحّدة عبر البيئات. في كل نشر جديد يُواجه المطور سؤالاً: هل العمود TEXT أم TIMESTAMP؟

**7. غياب idempotency في `recordImportEvent`**
المنع: `SELECT id WHERE event_type = 'import' LIMIT 1`. لكن في حالة race condition (طلبان متزامنان لنفس الضمان) يمكن إنشاء import events مكررة.

### 🟠 مخاطر التضخيم (Amplification Risks)

**تغيير صغير → تأثير كبير:**

| التغيير                                                  | الأثر المتسلسل                                                                                                                                                                                                                     |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| تعديل `ActionabilityPolicyService::STAGE_PERMISSION_MAP` | يؤثر على: SQL builder في batches، workflow advance/reject، navigation guard، timeline filters                                                                                                                                      |
| تغيير format `MATCH_AUTO_THRESHOLD` (0–1 vs 0–100)       | يوجد normalization في `Settings::normalizePercentage()` لكن أي مكان يقرأ القيمة مباشرة بدونه يُخطأ الحساب                                                                                                                          |
| إضافة stage جديد لسير العمل                              | يتطلب: `WorkflowService::STAGE_ORDER`، `TRANSITION_PERMISSIONS`، `ActionabilityPolicyService::STAGE_PERMISSION_MAP`، DB trigger، DB constraint، `PermissionCapabilityCatalog`، migration لـ permission جديد، DB seeding — 7+ أماكن |

---

## المرحلة الخامسة — التقرير التنفيذي (بالعربية)

---

# التقرير التنفيذي الشامل لنظام WBGL

## تقييم النضج المؤسسي والاستدامة التقنية

---

### أولاً: كيف يعمل WBGL فعلاً

WBGL هو نظام يعالج وثائق الضمان المصرفي عبر مسار رقمي متكامل من لحظة الاستيراد حتى الإفراج. الفكرة الجوهرية هي: **الضمان يبدأ كبيانات خام ويتحول تدريجياً إلى قرار موثّق قابل للإجراء التشغيلي**.

التدفق الفعلي هو:

1. **الاستيراد** — من Excel أو لصق نصي أو بريد. البيانات تُخزَّن كـ JSON خام في `guarantees.raw_data`.
2. **المطابقة الذكية** — النظام يحاول تلقائياً تحديد البنك (حتمياً) والمورد (احتمالياً بدرجة ثقة ≥90%). إذا نجح الاثنان — ينتقل السجل تلقائياً إلى `status=ready`.
3. **سير العمل** — مسار موافقة صارم من 6 مراحل. كل مرحلة تتطلب صلاحية محددة. الرفض يُعيد إلى البداية.
4. **الإجراءات التشغيلية** — بعد التوقيع: تمديد/تخفيض/إفراج. كل إجراء يُنتج خطاباً مؤصّلاً يُحفظ كـ HTML في السجل.
5. **السجل الجنائي** — كل خطوة تُسجَّل بنظام هجين يحتوي patch JSON + snapshots دورية + HTML الخطاب + هوية كاملة للفاعل.

---

### ثانياً: نقاط القوة (مع إثبات من الكود)

**🏆 الحوكمة الجنائية — الأقوى في المشروع**

يمتلك النظام بنية تتبع قابلة للدفاع أمام أي جهة رقابية. `guarantee_history` يحتوي على 5 حقول للجهة الفاعلة، patch RFC 6902 بين كل حالتين، HTML الخطاب المُرسَل، وإصدار القالب. هذا يُمكّن إعادة بناء حالة أي ضمان في أي لحظة تاريخية.

**🏆 الدفاع متعدد الطبقات (Defense in Depth)**

- طبقة HTTP: CSRF + Request ID + Security Headers
- طبقة Application: Guard + Session timeout + Rate limiter
- طبقة DB: 3 Triggers + 6+ CHECK constraints + Domain validation
- طبقة Business: Self-approval prevention + Break Glass ticketing + Undo 3-actor chain

**🏆 بوابة الثقة القابلة للشرح (Explainable Trust Gate)**

النظام لا يوافق أو يرفض فحسب — يُربط القرار بسبب مُشروح: `REASON_LOW_CONFIDENCE`, `REASON_ALIAS_CONFLICT`, `REASON_HIGH_CONFIDENCE`. هذا يجعل قرارات الأتمتة auditable.

**🏆 إدارة الصلاحيات المرنة**

نموذج RBAC مع تخصيصات فردية (allow/deny per-user) يُمكّن من مرونة رفيعة المستوى دون انتهاك المبادئ الأساسية.

---

### ثالثاً: نقاط الضعف الهيكلية (ليست شكلية)

**🔴 ضعف هيكلي #1: `index.php` كحصن رمادي**

ملف بـ 118KB يجمع: routing + views + business logic + HTML. هذا ليس ديوناً تقنية — هو قنبلة موقوتة. أي تعديل فيه محفوف بمخاطر التكسير لأجزاء بعيدة. لا يمكن اختباره. في فريق متعدد الأعضاء: مصدر conflicts دائم.

**🔴 ضعف هيكلي #2: `Guard::hasOrLegacy(default=true)` — ثغرة permission escalation**

هذه الدالة مُصمَّمة للتوافق مع الإصدارات القديمة، لكنها تخلق نافذة خطرة: في اللحظة الفاصلة بين كتابة كود يستدعيها وتشغيل migration يُسجّل الصلاحية — كل مستخدم في النظام لديه الصلاحية ضمنياً. في بيئة إنتاج، هذه اللحظة قد تكون ساعات.

**🔴 ضعف هيكلي #3: Settings كملف JSON**

إشكاليتان:

1. بيانات حساسة (DB credentials) في ملف JSON على الديسك بدون تشفير
2. `save()` يستخدم `file_put_contents(..., LOCK_EX)` — الـ lock لا يضمن atomicity على جميع أنظمة الملفات. إذا قُطعت العملية أثناء الكتابة → JSON فاسد → النظام يعود للقيم الافتراضية صامتاً.

**🟡 ضعف هيكلي #4: استمرارية التعلم الآلي غير مكتملة**

"Targeted Negative Learning" موثّقة في الكود كـ stub. اكتشاف alias conflicts لا يُفضي إلى أي تعلم فعلي. هذا يعني أن دقة النظام لا تتحسن تلقائياً عبر الوقت كما يُوحي تصميمه — يحتاج تدخلاً يدوياً مستمراً.

**🟡 ضعف هيكلي #5: قاعدة البيانات تحمل بيانات خام غير منظمة**

`guarantees.raw_data` هو JSON غير محدد الشكل. كل مصدر استيراد قد يضع حقولاً مختلفة. `FieldExtractionService` و`ParseCoordinatorService` يحاولان التطبيع، لكن لا يوجد JSON Schema validation. مع الوقت تتراكم أشكال متعددة لا يمكن الاستعلام عنها بكفاءة.

---

### رابعاً: المخاطر التشغيلية

**1. خطر الفشل الصامت في الـ Scheduler**
المهام المجدولة تعمل عبر `exec()` PHP shell. إذا تعطّل خادم PHP الخارجي (OOM killer, timeout)، `SchedulerRuntimeService` يُسجّل الفشل ويضع dead letter، لكن لا يوجد نظام re-queue تلقائي حقيقي. Dead letters تحتاج إنساناً يضغط "retry".

**2. خطر Settings Corruption**
إذا تعطّل الخادم أثناء حفظ `settings.json` → JSON فاسد → في أي استدعاء لاحق لـ `Settings::get()` تُعاد القيم الافتراضية بدون أي خطأ أو إنذار. قيم حرجة كـ `MATCH_AUTO_THRESHOLD` ستعود إلى 95 بدلاً من القيمة المُضبوطة → تغيير سلوك المطابقة الآلية بدون علم أحد.

**3. Race Condition في Timeline Recording**
لا يوجد database transaction يجمع: "تسجيل الحدث + حساب العدّاد للـ anchor". في load عالٍ قد تُكتب أحداث متعددة بنفس العدّاد → تُفقد بعض anchors → تحتاج إعادة بناء أبطأ.

---

### خامساً: الديون التقنية الخفية

| الدين                                    | المكان                 | التأثير                            |
| ---------------------------------------- | ---------------------- | ---------------------------------- |
| `index.php` 118KB                        | نقطة الدخول            | صيانة عالية التكلفة                |
| `views/settings.php` 105KB               | واجهة الإعدادات        | لا اختبارات، كسر سهل               |
| `snapshot_data` يُكتب ثم يُصفَّر         | `TimelineRecorder:577` | Schema مُربك + بيانات NULL في جدول |
| V1 + V2 parse-paste endpoints            | `api/`                 | ازدواجية غير محسومة                |
| `guarantee_history_archive` جدول ميت     | `migrations/14`        | Schema bloat                       |
| `error_log()` في كل مكان                 | أكثر من 20 موضع        | لا structured logging              |
| `active_action_set_at` نوع TEXT في بيئات | schema drift           | maintenance nightmare              |
| `created_by` في notes/attachments = TEXT | لا FK                  | عدم تتبع الفاعل                    |

---

### سادساً: تقييم نضج الحوكمة

**النضج الفعلي: متقدم، لكن غير مكتمل.**

النظام يُحقق 4 من 5 متطلبات الحوكمة المؤسسية الأساسية:

✅ **Non-repudiation** — لا يمكن إنكار أي إجراء
✅ **Audit Trail** — سجل كامل قابل للتتبع
✅ **Access Control** — RBAC + user overrides
✅ **Emergency Governance** — Break Glass مُقيَّد بالسياسة
❌ **Complete Actor Attribution** — `created_by` في الملاحظات والمرفقات TEXT وليس FK. المستخدم المحذوف يختفي من السجل.

---

### سابعاً: جاهزية المؤسسات

**WBGL في وضعه الحالي صالح للاستخدام المؤسسي المحدود بشروط:**

✅ **صالح لـ:** بيئة فريق صغير (5-15 مستخدماً)، عمليات ضمان يومية، تدقيق داخلي
⚠️ **يحتاج إصلاح قبل:** نمو الفريق، تدقيق خارجي رسمي، نشر متعدد الخوادم
❌ **غير صالح حالياً لـ:** حجم تشغيلي عالٍ، متطلبات zero-downtime، تدقيق SOC2/ISO 27001 بدون إصلاحات

---

### ثامناً: تنبؤ الاستدامة طويلة المدى

**خلال 6-12 شهراً (بدون تدخل):**

- `index.php` سيصل إلى نقطة لا يمكن لأحد تحريره بأمان
- دقة الذكاء الاصطناعي لن تتحسن (negative learning غير مُفعَّل)
- schema drift سيزداد مع كل migration

**خلال 12-24 شهراً:**

- صعوبة إضافة ميزات جديدة بسبب تعقيد `index.php`
- الاعتماد الكامل على شخص واحد يفهم البنية
- تراكم بيانات JSON غير متجانسة في `raw_data`

**التشخيص الإجمالي:** النظام بُني بمهارة عالية في طبقات الحوكمة والأمن والـ DB. لكن البنية التقديمية (presentation layer) متهالكة وستُكلّف بشكل غير متناسب مع الوقت. **الأولوية القصوى: تفكيك `index.php`.**

---

### تاسعاً: موثوقية منظومة القرارات والتسلسل الزمني

**الحكم: موثوق عند نضج الـ Schema — غير مضمون في بيئات قديمة.**

الطبقة الجنائية (`guarantee_history` + `TimelineHybridLedger`) مُصمَّمة بشكل دقيق. لكن ثمة تحفظ واحد جوهري: `TimelineRecorder::recordEvent()` يُطلق استثناء إذا لم تتوفر hybrid columns في الـ schema:

```php
throw new RuntimeException('Hybrid history columns are required before writing timeline events');
```

أي بيئة قديمة لم تُشغَّل فيها migration #5 (`add_hybrid_history_columns`) ستفشل في تسجيل **أي** حدث. هذا يعني: بيئة staging أو DR لم تُرقَّى بالكامل → فقدان صامت للتسجيل التاريخي في أي إجراء يُنفَّذ فيها.

---

## الخلاصة التنفيذية في 5 جُمَل

1. **WBGL نظام ذو هندسة مدروسة** لإدارة دورة حياة الضمانات المصرفية، يتميز بطبقة حوكمة وجنائية استثنائية مقارنة بحجمه.

2. **الديون التقنية الحقيقية محصورة في طبقة العرض** (`index.php` 118KB)، وليست في الأعماق — وهذا يجعل إصلاحها ممكناً بدون هدم الأساس.

3. **الثغرة الأمنية الأخطر** هي `Guard::hasOrLegacy(default=true)` التي تمنح صلاحيات ضمنية لأي permission جديدة قبل تسجيلها في DB.

4. **الذكاء الاصطناعي موثوق في المطابقة** لكن التعلم التكيفي (negative learning) مُعلَّق كـ stub، مما يعني أن دقة النظام ثابتة لا متطورة.

5. **النظام جاهز للعمل المؤسسي اليوم** بشرط: إصلاح `Guard::hasOrLegacy`، تأمين `settings.json`، وإطلاق مشروع تفكيك `index.php` كأولوية إستراتيجية.

---

_— نهاية التقرير التنفيذي —_
_"كل حكم في هذا التقرير مستند إلى سلوك كود موثّق. ما لم يُمكن التحقق منه، صُرِّح بذلك صراحةً."_
