# WBGL Enterprise-Grade Execution Plan

آخر تحديث: 2026-02-27  
الحالة: Enterprise closure completed (PostgreSQL active mode)

## 1) الهدف

هذه الوثيقة تحول WBGL من "جاهزية إنتاج قوية" إلى "Enterprise-Grade" عبر إغلاق 6 فجوات تشغيلية ومؤسسية:

1. قاعدة بيانات مؤسسية + Backup/Restore/DR
2. Session + HTTP Security Hardening
3. Integration/E2E Testing
4. CI/CD مؤسسي
5. Observability (Logs + Metrics + Alerts)
6. SoD / Governance / Compliance

## 2) خط الأساس الحالي (Evidence)

من `WBGL_EXECUTION_LOOP_STATUS.json` (latest run_at):

- `guarded=59/59`
- `sensitive_unguarded=0`
- `db_cutover.active_driver=pgsql`
- `db_cutover.portability_high_blockers=0`
- `stage_gates: A/B/C/D-rehearsal/D-pg-report/D-pg-activation/E = pass`
- `pending_migrations=0`
- `next_batch=[]`
- `tests.files_total=33` (`unit=32`, `integration=1`)
- `playwright.tests_count=3` (`playwright.ready=true`)

الاستنتاج: WBGL أغلق المسار التنفيذي بالكامل مع تشغيل فعلي على PostgreSQL.

## 3) مبادئ التنفيذ

1. WBGL هو الأساس النهائي (BG مرجع فروقات فقط).
2. لا إعادة بناء من الصفر.
3. كل دفعة تمر عبر Change Gate + أدلة اختبار.
4. لا دمج لتعديلات حساسة بدون تحديث `WBGL_EXECUTION_LOOP_STATUS.json`.
5. أي قرار يخص الامتثال يوثق قبل التنفيذ.

## 4) موجات التنفيذ

## Wave-1: Security & Governance Baseline

### Scope

- البند 2 (Security Hardening)
- جزء حاكم من البند 4 (CI Gate enforcement)

### التنفيذ

1. تقوية إعدادات الجلسة:
   - `httponly`, `secure`, `samesite`
   - idle timeout + absolute timeout
2. منع Session Fixation:
   - `session_regenerate_id(true)` بعد login
3. Logout hard destroy:
   - `session_unset`, `session_destroy`, cookie invalidation
4. CSRF مركزي لكل POST/PUT/DELETE
5. Security headers مركزية:
   - CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
6. توسيع rate-limit (username + IP + user-agent)

### المخرجات

- `WBGL/docs/SECURITY_BASELINE.md`
- اختبارات security integration (session/CSRF)

### معيار الإغلاق

- لا endpoint mutating بدون CSRF
- نجاح اختبارات session fixation
- headers الأمنية مفعلة على المسارات الأساسية

---

## Wave-2: Integration & E2E

### Scope

- البند 3 كاملًا

### التنفيذ

1. إنشاء `tests/Integration` فعليًا (ليس تعريفًا فقط)
2. Integration API flows:
   - auth/rbac
   - print events
   - history snapshot/time-machine
   - undo governance lifecycle
   - scheduler dead-letter lifecycle
3. E2E UI (Playwright أو بديل معتمد):
   - طباعة خطاب
   - التنقل عبر timeline
   - مراجعة logs/audit في settings
4. fixtures/seeding ثابت
5. coverage report + minimum threshold

### المخرجات

- `WBGL/tests/Integration/*`
- `WBGL/tests/E2E/*`
- تقرير تغطية آلي في CI

### معيار الإغلاق

- flow failures تمنع merge تلقائيًا
- مرور اختبارات التكامل على CI

---

## Wave-3: Observability

### Scope

- البند 5 كاملًا

### التنفيذ

1. structured JSON logs
2. correlation/request id من bootstrap
3. metrics endpoint داخلي:
   - latency
   - error rate
   - scheduler failures
   - dead-letter count
4. dashboards تشغيلية
5. alert rules:
   - error spike
   - dead-letter growth
   - slow API
   - backup failure

### المخرجات

- `WBGL/docs/OBSERVABILITY_RUNBOOK.md`
- لوحات مراقبة + سياسات تنبيه موثقة

### معيار الإغلاق

- اكتشاف الأعطال الحرجة آليًا خلال دقائق
- traceability كاملة للطلبات

---

## Wave-4: Enterprise DB Cutover

### Scope

- البند 1 كاملًا

### التنفيذ

1. اعتماد PostgreSQL للإنتاج
2. دعم multi-driver في طبقة DB
3. migrations متوافقة مع PostgreSQL
4. نقل بيانات SQLite -> PostgreSQL مع تحقق row-count/checksum
5. Backup strategy:
   - daily dumps
   - PITR/WAL
   - retention policy
6. Restore drills دورية
7. cutover runbook واضح

### المخرجات

- `WBGL/docs/DB_CUTOVER_RUNBOOK.md`
- `WBGL/docs/BACKUP_RESTORE_RUNBOOK.md`
- سكربتات backup/restore موثقة

### معيار الإغلاق

- restore اختباري ناجح
- RPO/RTO معرفان ومختبران
- صفر فقد بيانات في التحقق

---

## Wave-5: SoD & Compliance

### Scope

- البند 6 كاملًا

### التنفيذ

1. تعريف مصفوفة أدوار مؤسسية
2. dual-control للعمليات الحساسة
3. منع self-approval شامل
4. break-glass procedure مع audit إلزامي
5. تقارير تدقيق دورية
6. ربط الامتثال ببوابة CI

### المخرجات

- `WBGL/docs/GOVERNANCE_POLICY.md`
- `WBGL/docs/SOD_MATRIX.md`
- `WBGL/docs/BREAK_GLASS_RUNBOOK.md`

### معيار الإغلاق

- لا عملية حساسة تنفذ بطلب/اعتماد نفس الشخص
- أدلة تدقيق كاملة وقابلة للمراجعة

## 5) البوابات الانتقالية (Stage Gates)

1. Gate-A: Security baseline pass + CI gate enforcement
2. Gate-B: Integration/E2E pass على flows الحرجة
3. Gate-C: Observability dashboards + alerts active
4. Gate-D: DB cutover rehearsal pass
5. Gate-E: SoD compliance pass

## 5.1) تحديث الحالة التنفيذية (2026-02-27)

- Wave-1: مكتمل تشغيليًا (Security + Governance baseline) وفق حالة اللوب.
- Wave-2: مكتمل تشغيليًا:
  - Suite تكامل HTTP موجود (`tests/Integration/EnterpriseApiFlowsTest.php`).
  - Playwright smoke baseline مفعل ويعمل (`3 passed`).
- Wave-3: مكتمل تشغيليًا:
  - `GET /api/metrics.php` + `GET /api/alerts.php` (محميان بـ `manage_users`).
  - `X-Request-Id` correlation على الاستجابات والأخطاء.
  - قواعد تنبيه threshold-based مع عرض تشغيلي داخل `views/maintenance.php`.
  - توثيق التشغيل في `docs/OBSERVABILITY_RUNBOOK.md`.
- Wave-4: مكتمل تشغيليًا (Full PG activation):
  - سكربتات `db-driver-status`, `db-cutover-check`, `backup-db` (PG-native).
  - سكربتات `check-migration-portability`, `db-cutover-fingerprint`.
  - إضافة سكربت rehearsal مخصص: `maint/pgsql-activation-rehearsal.php`.
  - ربط rehearsal في CI: `.github/workflows/ci.yml` و`.github/workflows/release-readiness.yml`.
  - تحويل SQL تلقائي إلى PG عبر `app/Support/MigrationSqlAdapter.php`.
  - نتيجة portability: `high_blockers=0`.
  - توثيق تشغيل التفعيل في `docs/PGSQL_ACTIVATION_RUNBOOK.md`.
  - توثيق `docs/DB_CUTOVER_RUNBOOK.md` و`docs/BACKUP_RESTORE_RUNBOOK.md`.
  - تثبيت migration tooling على مسار PostgreSQL التشغيلي.
- Wave-5: مكتمل baseline الامتثال:
  - `docs/GOVERNANCE_POLICY.md`
  - `docs/SOD_MATRIX.md`
  - `docs/BREAK_GLASS_RUNBOOK.md`
  - إضافة قياس readiness ضمن execution loop.
- Stage Gates:
  - Gate-B: Pass
  - Gate-C: Pass
  - Gate-D Rehearsal: Pass
  - Gate-D PG Rehearsal Report: Pass
  - Gate-D PG Activation: Pass
  - Gate-E: Pass

## 6) تعريف "Enterprise-Grade" (Definition of Done)

يُعلن WBGL Enterprise-Grade فقط إذا تحققت جميع النقاط:

1. DB enterprise stack + tested restore
2. Session/HTTP hardening + CSRF complete
3. Integration/E2E mandatory in CI
4. Multi-workflow CI/CD (gate + ci + security + release)
5. Central observability with actionable alerts
6. SoD policy enforced and audited

## 7) Checklist تنفيذية (Master Checklist)

- [x] Wave-1 Security & Governance Baseline closed
- [x] Wave-2 Integration/E2E closed
- [x] Wave-3 Observability closed
- [x] Wave-4 Enterprise DB Cutover closed (rehearsal baseline + portability=0)
- [x] Wave-5 SoD & Compliance closed
- [x] Gate-A passed
- [x] Gate-B passed
- [x] Gate-C passed
- [x] Gate-D passed (rehearsal baseline)
- [x] Gate-E passed
- [x] Enterprise declaration approved
- [x] Full PG activation declaration approved

## 8) ملاحظة قرار الأعمال

إذا استمر نموذج "حساب واحد" دون فصل أدوار، فالنتيجة تكون:

- **Enterprise-Lite Operationally**
- وليست **Enterprise Compliance-Grade** كاملة.
