# تقرير التدقيق المعرفي الشامل + Claim Validation لنظام WBGL (AS-IS)

تاريخ التنفيذ: 2026-02-28  
النطاق: كامل المستودع `WBGL` (بدون افتراضات خارج الكود)  
منهجية الإثبات: `runtime code > docs` مع إسناد كل نقطة إلى `file:path + line range`.

---

## Phase 0 — المسح الكامل للمستودع (Mandatory Traversal)

### 0.1 إثبات المرور الكامل
- تم تنفيذ مسح شامل recursive على كل المجلدات والملفات.
- إجمالي الملفات: `8375`
- إجمالي المجلدات: `1326`
- تم بناء فهرس داخلي كامل، ثم تصنيف الملفات وظيفيًا.

### 0.2 بنية المستوى الأعلى (Top-level Inventory)

| المسار | ملفات | مجلدات فرعية |
|---|---:|---:|
| `.git` | 984 | 265 |
| `.github` | 6 | 1 |
| `.vscode` | 3 | 0 |
| `api` | 62 | 1 |
| `app` | 116 | 12 |
| `assets` | 4 | 2 |
| `database` | 17 | 1 |
| `Docs` | 2 | 0 |
| `Emails` | 2 | 1 |
| `maint` | 29 | 1 |
| `node_modules` | 563 | 66 |
| `partials` | 14 | 0 |
| `php` | 75 | 6 |
| `public` | 48 | 14 |
| `storage` | 24 | 7 |
| `templates` | 1 | 0 |
| `tests` | 36 | 8 |
| `vendor` | 6356 | 920 |
| `views` | 11 | 0 |

### 0.3 تصنيف الملفات الوظيفي (Non-exclusive, code-facing)

> المجموع الأساسي بعد استبعاد مجلدات الطرف الثالث: `CORE_FILES=470`

| الفئة | العدد |
|---|---:|
| `UI` | 54 |
| `API endpoints` | 62 |
| `Services` | 58 |
| `Repositories` | 14 |
| `DB/migrations` | 17 |
| `Auth/permissions` | 26 |
| `Scheduler/cron/maint` | 37 |
| `Reports/letters/print` | 18 |
| `Audit/timeline` | 32 |
| `Settings/config` | 17 |
| `Tests` | 36 |

---

## Phase 1 — إعادة بناء نموذج التشغيل الفعلي (AS-IS, بدون حكم)

### 1.1 نقاط الدخول الفعلية
- HTTP Router:
  - `server.php:6-33`
- Web Entry:
  - `index.php:6-10`, `index.php:67-75`
- API bootstrap/auth/csrf:
  - `api/_bootstrap.php:45-74`, `api/_bootstrap.php:77-101`, `api/_bootstrap.php:124-127`
- CLI / Scheduler:
  - `maint/schedule.php:17-63`
  - `maint/schedule-status.php:27-52`
  - `maint/schedule-dead-letters.php:35-81`

### 1.2 مخطط تشغيلي مختصر (Call-Graph Style)
- إدخال `Excel`:
  - `POST /api/import.php` -> `ImportService::importFromExcel` -> `guarantees` + `guarantee_occurrences` + timeline import -> `SmartProcessingService::processNewGuarantees`
  - دليل: `api/import.php:71-74,90-113` + `app/Services/ImportService.php:54-106,181-205,567-580`
- إدخال `Smart Paste`:
  - `POST /api/parse-paste.php` -> `ParseCoordinatorService::parseText` -> create/find guarantee -> optional auto-matching
  - دليل: `api/parse-paste.php:55-66` + `app/Services/ParseCoordinatorService.php:45-59,493-507,543-567,573-588`
- إدخال `Manual`:
  - UI calls `/api/create-guarantee.php` مباشرة
  - دليل: `public/js/input-modals.controller.js:75-79` + `api/create-guarantee.php:83-121`
  - endpoint بديل موجود `/api/manual-entry.php` عبر `ImportService::createManually`
  - دليل: `api/manual-entry.php:27-31` + `app/Services/ImportService.php:232-299`
- إدخال `Email/MSG`:
  - `POST /api/import-email.php` -> `EmailImportService::processMsgFile` (Excel strategy أو fallback) -> `POST /api/save-import.php` للحفظ النهائي
  - دليل: `api/import-email.php:26-37` + `app/Services/Import/EmailImportService.php:57-63,65-217` + `api/save-import.php:31-82,118-168`
- القرار/المطابقة:
  - مطابقة تلقائية async/service-level: `SmartProcessingService`
  - مطابقة عند القراءة (side-effect): `api/get-record.php:209-255,284-329`
- حفظ القرار اليدوي:
  - `POST /api/save-and-next.php` -> upsert `guarantee_decisions` + status eval + timeline
  - دليل: `api/save-and-next.php:398-443,493-500`
- تغيير الحالة التشغيلية:
  - تمديد: `api/extend.php`
  - تخفيض: `api/reduce.php`
  - إفراج + lock: `api/release.php`
  - إعادة فتح governed: `api/reopen.php` + `UndoRequestService`
- إخراج:
  - توليد/معاينة خطابات: `api/get-letter-preview.php`, `LetterBuilder`
  - طباعة/معاينة مطبوعة مع audit: `api/print-events.php`, `PrintAuditService`

### 1.3 Data Authority Map (مصدر الحقيقة لكل مفهوم)

| المفهوم | مصدر الحقيقة | دليل |
|---|---|---|
| أصل بيانات الضمان | `guarantees.raw_data (json)` | `storage/database/backups/wbgl_pg_20260227_195846.sql:566-577` |
| رقم الضمان الفريد | `guarantees.guarantee_number UNIQUE` | `.../wbgl_pg_20260227_195846.sql:7944-7949` |
| قرار الضمان الحالي | `guarantee_decisions` صف واحد لكل ضمان | `.../wbgl_pg_20260227_195846.sql:362-383,7891-7892` |
| حالة `pending/ready/released` | `guarantee_decisions.status` + `is_locked` | `.../wbgl_pg_20260227_195846.sql:365-367` |
| workflow stage | `guarantee_decisions.workflow_step` | `.../wbgl_pg_20260227_195846.sql:381-382` |
| سياق الدفعات/التكرارات | `guarantee_occurrences` | `.../wbgl_pg_20260227_195846.sql:533-540` |
| timeline/history | `guarantee_history` (hybrid v2) | `.../wbgl_pg_20260227_195846.sql:409-426` |
| archive للتاريخ | `guarantee_history_archive` | `.../wbgl_pg_20260227_195846.sql:433-446` + `app/Services/HistoryArchiveService.php:39-94` |
| audit إعدادات | `settings_audit_logs` عبر `SettingsAuditService` | `app/Services/SettingsAuditService.php:30-61` |
| audit طباعة | `print_events` عبر `PrintAuditService` | `app/Services/PrintAuditService.php:57-85` |
| denied-access audit | `audit_trail_events` عبر `AuditTrailService` | `api/_bootstrap.php:50-63` + `app/Services/AuditTrailService.php:34-51` |

### 1.4 State & Stage Enforcement Map

| القاعدة | نقاط الإنفاذ |
|---|---|
| `ready` فقط عند وجود `supplier_id` و `bank_id` | `app/Services/StatusEvaluator.php:30-38` |
| `released` lock عند الإفراج | `api/release.php:75-86` + `app/Services/BatchService.php:353-364` |
| منع mutation على released إلا بـ break-glass | `app/Services/GuaranteeMutationPolicyService.php:52-91`; مستخدمة في `api/update-guarantee.php:54-62`, `api/extend.php:37-50`, `api/reduce.php:47-60`, `api/save-and-next.php:43-57`, `api/upload-attachment.php:45-59` |
| Workflow transitions | `app/Services/WorkflowService.php:46-71`; endpoint `api/workflow-advance.php:44-55,78-99` |
| Undo governance قبل reopen الطبيعي | `api/reopen.php:46-62` + `app/Services/UndoRequestService.php:13-31,50-99,114-156` |
| Break-glass constraints | `app/Services/BreakGlassService.php:75-100,106-121` |

### 1.5 Hidden Capability Extraction / Negative Space / Emergent Behaviors

#### Explicit Features
- Excel import + batch metadata + timeline import events.  
  دليل: `api/import.php:71-74,90-100`; `ImportService.php:100-121`.
- Smart paste extraction + confidence (endpointين).  
  دليل: `api/parse-paste.php:55-66`; `api/parse-paste-v2.php:60-116`.
- Manual creation.  
  دليل: `api/create-guarantee.php:83-121`; `api/manual-entry.php:27-31`.
- Undo workflow + dead-letter + metrics/alerts + print audit.

#### Implicit Features (غير مسماة كـ feature صريح)
- read endpoint يكتب تلقائيًا (auto supplier/bank matching + timeline) عند `GET record`.
  - `api/get-record.php:209-255,284-329`
- auto-status transition logging عند اكتمال auto-match.
  - `SmartProcessingService.php:204-216`
- archive-before-delete لضمان عدم فقد التاريخ.
  - `HistoryArchiveService.php:39-94`

#### Negative Space (ما الذي يمنعه النظام ضمنيًا)
- رفض mutation methods بدون CSRF (لجلسات browser) + استثناء Bearer token.
  - `api/_bootstrap.php:124-127`; `CsrfGuard.php:75-97`
- منع test-data creation في production mode في عدة قنوات.
  - `api/import.php:38-47`, `api/parse-paste.php:37-46`, `api/create-guarantee.php:26-35`
- منع self-approval في undo.
  - `UndoRequestService.php:249-253`

#### Emergent Behaviors (نتيجة تفاعل مكونات)
- تفاعل `GuaranteeVisibilityService + NavigationService/StatsService` ينتج scoping ديناميكي حسب role/stage/ownership.
  - `GuaranteeVisibilityService.php:42-91`
  - `NavigationService.php:69-71,121-124`
  - `StatsService.php:39-42,81-83`
- تفاعل `TimelineRecorder + TimelineHybridLedger` يعطي time-machine reconstruction (anchor + patch + fallback).
  - `TimelineRecorder.php:523-552`
  - `TimelineHybridLedger.php:224-303`

### 1.6 State Transition Heatmap (المستخرج من الشروط الفعلية)

#### Guarantee Status
- `no decision -> pending`:
  - `SmartProcessingService::createBankOnlyDecision` insert `'pending'`
  - دليل: `SmartProcessingService.php:336-339`
- `pending -> ready`:
  - auto path: `createAutoDecision` / bank+supplier complete
  - manual path: `save-and-next` عبر `StatusEvaluator::evaluate`
  - دليل: `SmartProcessingService.php:265-290`; `save-and-next.php:399-443`; `StatusEvaluator.php:30-38`
- `ready -> released`:
  - `api/release.php:47-53,75-86`; `BatchService.php:353-364`
- `released -> pending`:
  - governed undo execute/reopen direct
  - `UndoRequestService.php:196-217`
- `ready|pending -> pending`:
  - manual save with missing completeness after updates
  - `StatusEvaluator.php:37`; `save-and-next.php:399-417`

#### Workflow Stage
- `draft -> audited -> analyzed -> supervised -> approved -> signed`
  - `WorkflowService.php:25-32,46-53`
- branch نادر للتواقيع المتعددة (`approved -> approved` مع increment signatures):
  - `workflow-advance.php:62-75`
  - عمليًا dead غالبًا لأن `signaturesRequired() = 1` (`WorkflowService.php:100-103`)

#### Undo / Scheduler / Batch
- Undo: `pending -> approved|rejected -> executed`  
  دليل: `UndoRequestService.php:57-67,89-99,127-136`
- Scheduler runs: `running -> success|failed`  
  دليل: `SchedulerRuntimeService.php:159-205`
- Dead letters: `open -> resolved|retried`  
  دليل: `SchedulerDeadLetterService.php:143-152,183-191`
- Batch governance: `active <-> completed`  
  دليل: `BatchService.php:750-753,785-813`

### 1.7 Dead-Branch Mining (Branches نادرة/حواف حرجة)
- `api/parse-paste-v2.php` يكتب إلى `guarantee_metadata` (قابلية تحقق الجدول غير مؤكدة من المسار الفعلي الحالي).  
  دليل: `api/parse-paste-v2.php:141-149`  
  الحالة: `Uncertain` (وجود الجدول غير مثبت عبر schema الملتقطة).
- `api/convert-to-real.php` ينشئ `new GuaranteeRepository()` بدون `PDO` مطلوب.
  - `api/convert-to-real.php:27`
  - تعريف constructor يتطلب `PDO`: `GuaranteeRepository.php:19-22`
- `api/history.php` endpoint موجود لكنه retired دائمًا `410`.
  - `api/history.php:19-35`
- فرع signatures المتعددة غير مفعّل عمليًا لأن requirement ثابت = 1.
  - `WorkflowService.php:100-103`; `workflow-advance.php:62-75`

---

## Phase 2 — Claim Validation (C01..C18)

### C01) Multiple ingestion channels (Excel + smart paste + manual + email/msg)
- **Status:** `Confirmed`
- **Evidence:**  
  - Excel: `api/import.php:53-55,71-74` + `ImportService.php:54`  
  - Smart Paste: `api/parse-paste.php:24,65`  
  - Manual: `public/js/input-modals.controller.js:75-79` -> `api/create-guarantee.php:18-21`  
  - Email/MSG: `api/import-email.php:26-37` + `EmailImportService.php:19-63`
- **Reachability:** Excel/smart/manual/email جميعها wired.
- **Risk of mismatch:** `Low`

### C02) Duplicate guarantees handled as occurrences across batches (not blind overwrite)
- **Status:** `Partially Confirmed`
- **Evidence:**  
  - Excel duplicate -> `recordOccurrence` + duplicate timeline: `ImportService.php:197-204`  
  - Occurrence ledger insert idempotent: `ImportService.php:567-580`
  - Smart paste duplicate يسجل timeline فقط بدون `recordOccurrence`: `ParseCoordinatorService.php:493-507` (missing occurrence call in duplicate branch)
- **Reachability:** Excel path wired. Smart-paste duplicate branch wired لكنه غير مكتمل سياقيًا.
- **Contradiction:** claim عام على كل القنوات، بينما التنفيذ كامل في Excel فقط.
- **Risk:** `Medium`

### C03) Re-import preserves contextual history (batch context / occurrence ledger)
- **Status:** `Partially Confirmed`
- **Evidence:**  
  - Batches تُشتق من occurrences: `views/batches.php:20-35`  
  - Excel يعيد تسجيل الظهور: `ImportService.php:197-204`  
  - Drift: manual endpoint يكتب عمود `import_source` داخل `guarantee_occurrences` بينما schema الفعلي يستخدم `batch_type`: `api/create-guarantee.php:118-120` vs schema `...wbgl_pg...:533-540`
- **Reachability:** Wired لكن مع خطر runtime drift في `create-guarantee`.
- **Risk:** `High`

### C04) Governed Undo lifecycle (submit/approve/reject/execute) with anti-self-approval
- **Status:** `Confirmed`
- **Evidence:**  
  - API actions: `api/undo-requests.php:58-87`  
  - anti-self-action: `UndoRequestService.php:55,87,121,249-253`  
  - execution requires approved + transaction: `UndoRequestService.php:118-137`
- **Reachability:** Wired via API + `reopen.php` submit path.
- **Risk:** `Low`

### C05) Break-Glass override with reason/ticket/TTL + durable logging
- **Status:** `Confirmed`
- **Evidence:**  
  - permission + feature toggle + reason + ticket + ttl clamp: `BreakGlassService.php:75-100`  
  - durable insert into `break_glass_events`: `BreakGlassService.php:106-121`  
  - wired in reopen/batch reopen/mutation policy: `api/reopen.php:31-40`, `api/batches.php:124-133`, `GuaranteeMutationPolicyService.php:63-76`
- **Reachability:** Wired.
- **Risk:** `Low`

### C06) Released guarantees read-only by default unless governed override
- **Status:** `Partially Confirmed`
- **Evidence:**  
  - policy تمنع mutation على released: `GuaranteeMutationPolicyService.php:52-91`
  - مستخدمة في endpoints حرجة: `api/update-guarantee.php:54-62`, `api/extend.php:37-50`, `api/reduce.php:47-60`, `api/save-and-next.php:43-57`
  - bypass محتمل: `api/workflow-advance.php` لا يستخدم mutation policy (`16-55`), `api/save-note.php` يسمح كتابة ملاحظة login-only (`11,25-41`)
- **Reachability:** policy wired جزئيًا، ليس شاملًا لكل write paths.
- **Risk:** `High`

### C07) Hybrid timeline ledger (patch + anchor) for time-machine reconstruction
- **Status:** `Confirmed`
- **Evidence:**  
  - hybrid payload write: `TimelineRecorder.php:523-552`  
  - patch-first default (`snapshot_data = null`): `TimelineRecorder.php:554-555`  
  - reconstruct state via anchor+patch with legacy fallback: `TimelineHybridLedger.php:224-303`  
  - endpoint snapshot reconstruction: `api/get-history-snapshot.php:42-44`
- **Reachability:** Wired.
- **Risk:** `Low`

### C08) Settings changes audited with before/after + actor context
- **Status:** `Confirmed`
- **Evidence:**  
  - capture before/after + actor + IP/UA: `SettingsAuditService.php:11-61`
  - hook on settings save: `api/settings.php:84-95`
  - retrieval endpoint: `api/settings-audit.php:32-36`
- **Reachability:** Wired.
- **Risk:** `Low`

### C09) Print/preview audited as first-class events
- **Status:** `Confirmed`
- **Evidence:**  
  - service event model: `PrintAuditService.php:12-31,57-85`
  - API write/list: `api/print-events.php:33-49,94-108`
  - frontend instrumentation: `public/js/print-audit.js:100-129`
- **Reachability:** Wired.
- **Risk:** `Low`

### C10) Scheduler runtime + job-run ledger + dead-letter + retry/resolve APIs
- **Status:** `Confirmed`
- **Evidence:**  
  - run ledger insert/update: `SchedulerRuntimeService.php:61-84,159-205`
  - dead-letter record/resolve/retry: `SchedulerDeadLetterService.php:12-105,134-198`
  - API retry/resolve/list: `api/scheduler-dead-letters.php:24-33,56-69`
  - scheduler runner: `maint/schedule.php:17-63`
- **Reachability:** Wired (CLI + API).
- **Risk:** `Low`

### C11) API responses use one consistent envelope (`success/data/error/request_id`)
- **Status:** `Refuted`
- **Evidence:**  
  - bootstrap failure includes `request_id`: `api/_bootstrap.php:68-72`
  - endpoints كثيرة لا تستخدم envelope موحد (`status/message`): `api/save-import.php:163,168,175`
  - endpoints HTML وليس JSON: `api/release.php:16`, `api/extend.php:16`, `api/reduce.php:15`
- **Reachability:** Live inconsistency.
- **Risk:** `Medium`

### C12) Centralized policy matrix (deny-by-default) + denied-access audited
- **Status:** `Partially Confirmed`
- **Evidence:**  
  - denied-access audited: `api/_bootstrap.php:50-63` + `AuditTrailService.php:34-51`
  - matrix موجودة: `ApiPolicyMatrix.php:13-77`
  - matrix غير مستخدمة runtime للتنفيذ المركزي: usage فقط في tests/maint (`tests/...ApiPolicyMatrixCoverageTest.php`, `maint/run-execution-loop.php`)؛ لا استدعاء في bootstrap.
- **Reachability:** audit wired، matrix enforcement غير wired مركزيًا.
- **Risk:** `Medium`

### C13) Bulk extend/reduce/release with eligibility partition (`processed/blocked/errors`)
- **Status:** `Confirmed`
- **Evidence:**  
  - endpoint actions: `api/batches.php:33-100`
  - per-record partitioning outputs:
    - extend: `BatchService.php:123-271`
    - release: `BatchService.php:309-418`
    - reduce: `BatchService.php:467-604`
- **Reachability:** Wired.
- **Risk:** `Low`

### C14) Operational metrics/alerts APIs tied to governance counters
- **Status:** `Confirmed`
- **Evidence:**  
  - metrics counters تشمل `pending_undo_requests`, `open_dead_letters`, `api_access_denied_24h`: `OperationalMetricsService.php:20-40`
  - alerts thresholds + health summary: `OperationalAlertService.php:20-49,101-109`
  - APIs: `api/metrics.php:27-33`, `api/alerts.php:29-38`
- **Reachability:** Wired.
- **Risk:** `Low`

### C15) Legacy/duplicate endpoint paths with drift risk
- **Status:** `Confirmed`
- **Evidence:**  
  - smart paste duplicate endpoints: `api/parse-paste.php` و `api/parse-paste-v2.php`
  - UI uses only v1: `public/js/input-modals.controller.js:115`
  - legacy retired endpoint still موجود: `api/history.php:19-35`
  - manual creation paths متعددة: `/api/create-guarantee.php` و `/api/manual-entry.php`
- **Reachability:** Drift risk فعلي.
- **Risk:** `Medium`

### C16) Read operations causing side effects (auto-write)
- **Status:** `Confirmed`
- **Evidence:**  
  - `api/get-record.php` يقوم بـ upsert/update `guarantee_decisions` + timeline while loading view: `35-82`, `209-255`, `284-329`
- **Reachability:** Wired في navigation flow.
- **Risk:** `High`

### C17) JSON-heavy model with potential drift; safeguards?
- **Status:** `Partially Confirmed`
- **Evidence (JSON-heavy):**  
  - `guarantees.raw_data json`: schema `...wbgl_pg...:569`
  - timeline payload fields نصية JSON (`event_details`, `patch_data`, `anchor_snapshot`): `...wbgl_pg...:414-425`
  - repository hydrates raw JSON مباشرة بدون schema contract صارم: `GuaranteeRepository.php:210`
- **Evidence (safeguards):**  
  - بعض integrity constraints قوية (`UNIQUE`, `FK`): `...wbgl_pg...:7892,8234,8242,8250`
  - patch-first enforce migration: `20260227_000016_enforce_zero_history_snapshot_data.sql:1-8`
- **Contradiction:** no global JSON schema validation layer.
- **Risk:** `Medium`

### C18) DB driver assumptions consistent with migrations/config (PostgreSQL vs SQLite artifacts)
- **Status:** `Partially Confirmed`
- **Evidence:**  
  - runtime PostgreSQL-only: `Database.php:99-105,130-136`
  - migrations تحتوي SQLite-style SQL: `20260226_000012_...sql:1-13,34-56`
  - adapter يعيد كتابة SQL لـ pgsql: `MigrationSqlAdapter.php:17-21,24-39,41-74`
  - docs still تقول SQLite: `README.md:27,57,85-89`
- **Risk:** `Medium`

---

## Claims إضافية مكتشفة (Implied-by-UI/Docs/Naming)

### C19) `README` يعلن SQLite stack بينما runtime فعليًا PostgreSQL-only
- **Status:** `Refuted (doc/runtime mismatch)`
- **Evidence:** `README.md:27,57,85-89` vs `app/Support/Database.php:99-105`
- **Reachability:** mismatch توثيقي مباشر.
- **Risk:** `High` (تشغيل/نشر خاطئ)

### C20) `parse-paste-v2` “Implemented but Unreachable/Not Wired” من الـprimary UI
- **Status:** `Confirmed`
- **Evidence:** endpoint موجود `api/parse-paste-v2.php:1-158`، UI يستدعي v1 فقط `public/js/input-modals.controller.js:115`
- **Risk:** `Medium`

### C21) `convert-to-real` wired UI but backend path breaks constructor contract
- **Status:** `Confirmed`
- **Evidence:** `public/js/convert-to-real.js:10-16` -> `api/convert-to-real.php:27` vs `GuaranteeRepository.php:19-22`
- **Reachability:** Wired from UI، مرجح فشل runtime.
- **Risk:** `High`

### C22) `commit-batch-draft` يكتب عمود `status_flags` غير ظاهر في schema الفعلي
- **Status:** `Partially Confirmed`
- **Evidence:** write query `api/commit-batch-draft.php:55`; schema `guarantees` لا يظهر `status_flags` في dump `...wbgl_pg...:566-577`
- **Reachability:** Wired عبر `smart-workstation.controller.js:202`
- **Risk:** `High`

### C23) `get-current-state` لا يعيد `active_action` بينما frontend يتوقعه
- **Status:** `Confirmed`
- **Evidence:** snapshot response `api/get-current-state.php:138-154` (no `active_action`)؛ frontend usage `public/js/timeline.controller.js:444-447`
- **Risk:** `Medium`

### C24) Visibility scoping غير متسق بين list/navigation وdirect-id access
- **Status:** `Confirmed`
- **Evidence:** list/filters تستخدم visibility `NavigationService.php:69-71`; direct ID load in index bypasses visibility `index.php:71-74`
- **Risk:** `High`

### C25) Policy matrix موجودة لكن enforcement ليس مركزيًا
- **Status:** `Confirmed`
- **Evidence:** `ApiPolicyMatrix.php:13-77`; no runtime bootstrap use (`api/_bootstrap.php` لا يستدعيها)
- **Risk:** `Medium`

---

## Phase 3 — Contradictions & Gaps (Top 20)

1. `Advertised/used path broken`: `api/convert-to-real.php` يستدعي constructor خاطئ (`:27` vs `GuaranteeRepository.php:19-22`).  
2. `Schema drift`: `api/create-guarantee.php` inserts `guarantee_occurrences.import_source` (`:118-120`) بينما schema يستخدم `batch_type` (`...:537`).  
3. `Schema drift`: `api/commit-batch-draft.php` uses `guarantees.status_flags` (`:55`) غير ظاهر في schema الحالي (`...:566-577`).  
4. `Authorization scope gap`: direct `id` read in `index.php:71-74` بدون `GuaranteeVisibilityService`.  
5. `Authorization scope gap`: `api/save-note.php` login-only مع write by `guarantee_id` (`:11,25-41`).  
6. `Authorization scope gap`: `api/workflow-advance.php` login-only mutation (`:16-26,78-85`).  
7. `Authorization scope gap`: `api/save-and-next.php` login-only decision mutation (`:18,398-443`).  
8. `Read with side-effects`: `api/get-record.php` performs auto writes (`:209-255,284-329`).  
9. `Envelope inconsistency`: JSON contracts مختلفة + endpoints HTML (`api/release.php:16`, `api/save-import.php:163-175`, `_bootstrap.php:68-72`).  
10. `Central policy not centralized`: `ApiPolicyMatrix` غير مفعلة runtime.  
11. `View policy narrow map`: فقط `users/settings/maintenance` محمية permission-level (`ViewPolicy.php:15-19`).  
12. `UI-doc mismatch`: README يصف `SQLite` خلاف runtime `pgsql-only`.  
13. `Duplicate path drift`: `parse-paste-v2` exists but UI calls v1 only.  
14. `Duplicate ingestion behavior drift`: manual عبر `create-guarantee` و`manual-entry` بعقود مختلفة.  
15. `Occurrence coverage gap`: smart-paste duplicates لا تسجل occurrence ledger (timeline-only).  
16. `Potential missing table dependency`: `parse-paste-v2` writes `guarantee_metadata` (`:141-149`) — **Uncertain** schema presence.  
17. `Frontend expectation mismatch`: `active_action` expected but not returned in current snapshot API.  
18. `Upload hardening gap`: filename sanitization only, no strict MIME/extension allowlist (`upload-attachment.php:39-41,61-71`).  
19. `Governance bypass surface`: released mutation lock غير مطبق uniformly (مثال workflow advance, notes).  
20. `Observability execution gap`: Integration suite runs with exit `0` but silent output in this environment (evidence operationally limited).

---

## Most Dangerous Mismatch Candidates

### 1) مسارات multi-write بلا transaction شامل قد تنتج partial state
- مثال: `api/create-guarantee.php` ينشئ guarantee ثم occurrence/timeline لاحقًا بدون transaction محكم (`:113-135`).  
- مع schema drift في occurrence insert قد ينتهي بطبقة بيانات جزئية.

### 2) قراءات تُغيّر البيانات بصمت
- `api/get-record.php` auto-matching writes on read (`:209-255,284-329`).
- خطر: تغيير سجل دون intent صريح من المستخدم.

### 3) تجاوز scoping على مستوى object
- direct-id paths بلا `GuaranteeVisibilityService` في عدة endpoints/pages.
- خطر: وصول/تعديل cross-scope.

### 4) عدم اتساق إنفاذ read-only لـ released
- policy موجودة لكن ليست شاملة لكل write endpoints.
- خطر: تجاوز lifecycle locks.

### 5) Drift schema/code عالي التأثير
- `status_flags`, `import_source` في occurrences، constructor mismatch.
- خطر: أعطال production + تدهور حوكمة التتبع.

---

## مؤشرات كمية (تقدير تقني مبني على الكود)

> هذه المؤشرات قياس معماري/تشغيلي من السلوك الفعلي المكتشف، وليست أرقام benchmark خارجية.

| المؤشر | القيمة | تبرير تقني مختصر |
|---|---:|---|
| `Architectural Coherence Score` | 74/100 | خدمة/Repository layering واضح، لكن endpoints drift وlegacy paths تقلل الاتساق. |
| `Logical Integrity Index` | 68/100 | core lifecycle مضبوط، لكن وجود schema drift + read-side writes يضعف التكامل المنطقي. |
| `Governance Depth Index` | 84/100 | undo governance + break-glass + print/settings/denied audits + hybrid timeline قوي. |
| `Risk Exposure Index` | 63/100 | مخاطر متوسطة-عالية في authorization scoping وunwired/broken endpoints. |
| `Hidden Fragility Score` | 72/100 | fragile points عالية: duplicated paths, drift columns, non-uniform policy enforcement. |
| `Silent Failure Probability` | 27% | وجود non-blocking catches + silent integration output + بعض fallback branches. |
| `Change Propagation Risk` | High-Medium | تغييرات صغيرة في schema/decision paths قد تكسر batch/manual/timeline flows. |
| `Automation Ratio` | 61% | auto-matching/status/timeline/scheduler/alerts موجودة لكن ليست uniformly governed. |
| `Human Error Resistance Index` | 71/100 | validations جيدة وundo governance قوية، لكن inconsistent envelopes + path drift يرفع العبء. |
| `Long-Term Survivability Index` | 69/100 | قاعدة جيدة مع debt قابل للتراكم إن لم يُعالج drift/duplication. |

---

## تقييم جاهزية المؤسسة والحوكمة (مختصر تنفيذي)

- **نقاط قوة مثبتة من الكود:**
  - حوكمة متقدمة (`UndoRequestService`, `BreakGlassService`, `SettingsAuditService`, `PrintAuditService`).
  - timeline قابل لإعادة بناء الحالة تاريخيًا (`TimelineHybridLedger`).
  - scheduler runtime observability + dead-letter operational controls.
- **نقاط ضعف بنيوية:**
  - عدم اتساق إنفاذ authorization/visibility على مستوى object.
  - drift بين schema الفعلي وبعض endpoints.
  - duplication في المسارات الوظيفية يخلق divergence risk.
- **ثقة القرارات وtimeline:**
  - عالية نسبيًا في المسار الأساسي بسبب ledger hybrid + audit events.
  - تنخفض عند مسارات read-side mutation وschema drift/unwired endpoints.

---

## Verification Appendix

### أوامر التنفيذ (Key Commands Executed)

```powershell
Get-ChildItem -Recurse -File -Force
Get-ChildItem -Recurse -Directory -Force
rg --files api | Sort-Object
rg -n "ApiPolicyMatrix" -g "*.php"
rg -n "GuaranteeVisibilityService|canAccessGuarantee" -g "*.php"
rg -n "status\\s*=\\s*'" app api index.php maint tests
rg -n "workflow_step\\s*=\\s*'|workflowStep\\s*=\\s*'" app api index.php maint tests
rg -n "status\\]\\s*=\\s*'|status'\\s*=>\\s*'" app api index.php maint tests
vendor\bin\phpunit --testsuite Unit
vendor\bin\phpunit --testsuite Integration --testdox
vendor\bin\phpunit --list-tests --testsuite Integration
```

### ملفات دخول/محاور تحقق رئيسية
- `server.php`
- `index.php`
- `api/_bootstrap.php`
- `app/Support/Database.php`
- `app/Services/ImportService.php`
- `app/Services/ParseCoordinatorService.php`
- `app/Services/SmartProcessingService.php`
- `app/Services/TimelineRecorder.php`
- `app/Services/TimelineHybridLedger.php`
- `app/Services/UndoRequestService.php`
- `app/Services/BreakGlassService.php`
- `app/Services/SchedulerRuntimeService.php`
- `app/Services/SchedulerDeadLetterService.php`
- `api/batches.php`
- `storage/database/backups/wbgl_pg_20260227_195846.sql`

### ما لم يمكن التحقق منه بالكامل
- وجود/استخدام جدول `guarantee_metadata` في runtime الحالي لمسار `parse-paste-v2`:
  - **الحالة:** `غير قابل للتحقق من الكود الحالي` بشكل قاطع من schema capture المعتمد في هذا التدقيق.
- Integration tests في هذه البيئة تعيد `exit=0` لكن بدون output execution report:
  - **الحالة:** تحقق جزئي execution-wise.

---

## خاتمة البروتوكول

No additional feature-level behavior is extractable from code structure.

