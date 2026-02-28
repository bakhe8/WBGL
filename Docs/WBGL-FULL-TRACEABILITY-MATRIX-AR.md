# مصفوفة التتبع الكاملة — WBGL (Mandatory Coverage Matrix)

تاريخ الإصدار: 2026-02-28  
الحالة: **Baseline Mapping Created**  
الهدف: إثبات أن الخطة الموحدة تغطي جميع مصادر `Docs` وكل البنود الإلزامية (`FR/R/G/C`) بدون عناصر غير مربوطة.

المراجع الأساسية:
- `Docs/WBGL-UNIFIED-OWNER-VISION-AR-2026-02-28.md`
- `Docs/WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md`
- `Docs/forWBGL_master_plan.md`
- `Docs/forWBGL.md`
- `Docs/WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md`

---

## 1) قواعد التغطية

- `Covered`: مغطى ببند صريح في الخطة.
- `Covered-with-Action`: مغطى لكن يتطلب تنفيذ بند مفتوح في الخطة.
- `Guardrail`: قدرة موجودة يجب حمايتها باختبارات عدم ارتداد (non-regression).
- `Historical`: مرجع سياقي ولا يولد بند تنفيذ جديد مستقل.

مهم:
- هذه المصفوفة تثبت **التغطية التخطيطية** وليست إغلاق التنفيذ.
- الإغلاق الفعلي يتطلب تحديث الحالة التنفيذية لكل بند إلى `Done` مع دليل تحقق.

---

## 2) تغطية كل ملف من قائمة Docs

| الملف | ما يغطيه | الربط في الخطة الموحدة | حالة التغطية |
|---|---|---|---|
| `critical-feature-discovery-report.md` | القدرات الصريحة/الضمنية/الناشئة + القيود الصامتة | المرحلة 1 (`P0-1`, `P0-6`) + المرحلة 2 (7-10,15) + المرحلة 3 (1,8) | Covered-with-Action |
| `forWBGL_master_plan.md` | المتطلبات الإلزامية `R01..R16` وWorkstreams | المرحلة 0/2/3 + KPI + الحوكمة | Covered-with-Action |
| `forWBGL.md` | عقود الأهداف `G01..G30` + Guardrails | المرحلة 0 (قواعد T1/WIP) + KPI + Gate شهري | Covered-with-Action |
| `INDEX-DOCS-AR.md` | طبقات المرجعية وربط الوثائق | قسم المراجع + قسم مصفوفة التتبع | Covered |
| `WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md` | Claims `C01..C25` + فجوات حرجة | المرحلة 1/2/3 + جدول C في هذه المصفوفة | Covered-with-Action |
| `wbgl-cognitive-system-audit-ar.md` | التحليل البنيوي/الزمني/التحول المرحلي | المرحلة 1 (`P0-6`) + المرحلة 2/3 (ضبط الاتساق) | Covered-with-Action |
| `wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md` | ملخص تضاربات قبل الحسم | مرجع تاريخي بعد resolved + Gate تتبع | Historical |
| `wbgl-feedback-meeting-baseline-ar-2026-02-28.md` | صوت المستخدم والأولويات التشغيلية | المرحلة 2 (رسائل/Role UI/Training) + KPI | Covered-with-Action |
| `WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md` | السجل الرسمي `FR-01..FR-25` | جدول FR في هذه المصفوفة + خطة المراحل | Covered-with-Action |
| `wbgl-human-risk-report-ar-2026-02-28.md` | المخاطر البشرية والحمل الذهني | المرحلة 2/3 + KPI الأداء والوضوح | Covered-with-Action |
| `WBGL-ROADMAP-INSTRUCTIONS-AR-2026-02-28.md` | خارطة الاستقرار والتنفيذ | الهيكل المرحلي المعتمد في الخطة | Covered |
| `wbgl-role-operational-analysis-ar-2026-02-28.md` | فجوات الأدوار والصلاحيات | المرحلة 1 (`FR-06`, `FR-22`) + المرحلة 2 (3,7,9,11,16) | Covered-with-Action |
| `wbgl-ui-first-operational-identity-audit-ar-2026-02-28.md` | واجهة-إلى-منطق + أسطح الهشاشة | المرحلة 2 (4,10,13,15) + KPI | Covered-with-Action |
| `WBGL-UNIFIED-OWNER-VISION-AR-2026-02-28.md` | خطة التنفيذ الموحدة | الوثيقة التنفيذية الأساسية | Covered |
| `WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md` | خط الأساس المحسوم للتعارضات | مرجع الحسم في الأولويات والـ FR | Covered |

---

## 3) تتبع سجل الادعاءات النهائي (`FR-01..FR-25`)

| ID | الحالة المرجعية | الربط بالخطة الموحدة | التغطية |
|---|---|---|---|
| FR-01 | Confirmed | المرحلة 3-1 (توحيد مسارات الإدخال مع الحفاظ على القنوات) | Covered-with-Action |
| FR-02 | Partially Confirmed | المرحلة 2-6 (توحيد معالجة التكرارات) | Covered-with-Action |
| FR-03 | Refuted | المرحلة 1 `P0-1` | Covered-with-Action |
| FR-04 | Confirmed | المرحلة 3-2 + المرحلة 1 `P0-6` | Guardrail |
| FR-05 | Confirmed | المرحلة 1 `P0-2` + `P0-6` | Guardrail |
| FR-06 | Refuted | المرحلة 1 `P0-2` | Covered-with-Action |
| FR-07 | Partially Confirmed | المرحلة 2-8 | Covered-with-Action |
| FR-08 | Confirmed | المرحلة 1 `P0-6` (حماية timeline hybrid) | Guardrail |
| FR-09 | Confirmed | المرحلة 1 `P0-6` (settings audit) | Guardrail |
| FR-10 | Confirmed | المرحلة 1 `P0-6` (print audit) | Guardrail |
| FR-11 | Confirmed | المرحلة 1 `P0-6` (scheduler/dead-letter) | Guardrail |
| FR-12 | Refuted | المرحلة 2-1 + KPI (envelope coverage) | Covered-with-Action |
| FR-13 | Refuted | المرحلة 2-7 | Covered-with-Action |
| FR-14 | Confirmed | المرحلة 2-10 + `P0-6` | Guardrail |
| FR-15 | Refuted | المرحلة 2-10 (تثبيت دلالة per-record للمستخدم) | Covered-with-Action |
| FR-16 | Confirmed | المرحلة الممتدة-1 + المرحلة 2-13 | Guardrail |
| FR-17 | Refuted | المرحلة 2-13 | Covered-with-Action |
| FR-18 | Refuted | المرحلة 3-1 (حسم المسارات المزدوجة) | Covered-with-Action |
| FR-19 | Refuted | المرحلة 3-1 (توحيد المسار وليس حذفه) | Covered-with-Action |
| FR-20 | Unproven | المرحلة 1 `P0-5` + KPI (`Manual Entry <1%`) | Covered-with-Action |
| FR-21 | Refuted | المرحلة 3-1 (`parse-paste` توحيد/دمج) | Covered-with-Action |
| FR-22 | Refuted | المرحلة 1 `P0-3` | Covered-with-Action |
| FR-23 | Refuted | المرحلة 2-14 | Covered-with-Action |
| FR-24 | Refuted | المرحلة 2-9 + المرحلة 1 `P0-2` (object scope) | Covered-with-Action |
| FR-25 | Confirmed | المرحلة 1 `P0-4` | Covered-with-Action |

---

## 4) تتبع المتطلبات الإلزامية (`R01..R16`)

| ID | المتطلب | الربط بالخطة الموحدة | التغطية |
|---|---|---|---|
| R01 | هيكل أوضح | المرحلة 3-5 | Covered-with-Action |
| R02 | صيانة أعلى | المرحلة 3-5/6 | Covered-with-Action |
| R03 | أثر تغيير متوقع | المرحلة 1 + حوكمة القسم 7 | Covered-with-Action |
| R04 | اتساق كود/تنفيذ | المرحلة 2-1 + المرحلة 3-5 | Covered-with-Action |
| R05 | فصل مسؤوليات | المرحلة 3-5 | Covered-with-Action |
| R06 | نموذج بيانات منظم | المرحلة 1 `P0-4` + المرحلة 3-6 | Covered-with-Action |
| R07 | توحيد API | المرحلة 2-1 | Covered-with-Action |
| R08 | بداية استخدام أسهل | المرحلة 2-3/4/16 | Covered-with-Action |
| R09 | تدريب أسرع | المرحلة 2-16 | Covered-with-Action |
| R10 | حمل ذهني أقل | المرحلة 2-3/4 + المرحلة 3-3 | Covered-with-Action |
| R11 | تنقل أوضح | المرحلة 2-3/4 | Covered-with-Action |
| R12 | تشغيل أسرع لغير التقنيين | المرحلة 2-3/11/13 | Covered-with-Action |
| R13 | `assigned_to` / `only_my_tasks` | المرحلة 2-11 | Covered-with-Action |
| R14 | Profile/Password clarity | المرحلة 2-12 | Covered-with-Action |
| R15 | تحقق/صلاحيات/مصادقة/أخطاء | المرحلة 1 + المرحلة 2-1/2/7/8/9 | Covered-with-Action |
| R16 | سرعة أحجام صغيرة/متوسطة | المرحلة 3-3 + KPI throughput | Covered-with-Action |

---

## 5) تتبع عقود الأهداف (`G01..G30`)

| ID | الربط بالخطة الموحدة | التغطية |
|---|---|---|
| G01 | المرحلة 3-5 (Architecture Boundaries) | Covered-with-Action |
| G02 | المرحلة 3-5 + KPI الصيانة | Covered-with-Action |
| G03 | القسم 7 (Gates/Rollback/Change Control) + `P0-6` | Covered-with-Action |
| G04 | المرحلة 3-5 + Policy/Contract discipline | Covered-with-Action |
| G05 | المرحلة 3-5 + Guardrail timeline | Covered-with-Action |
| G06 | المرحلة 3-6 (Typed Core Model) | Covered-with-Action |
| G07 | المرحلة 2-1 + KPI API envelope | Covered-with-Action |
| G08 | المرحلة 2-3/4/16 | Covered-with-Action |
| G09 | المرحلة 2-16 | Covered-with-Action |
| G10 | المرحلة 2-4 + KPI حمل ذهني/شكاوى | Covered-with-Action |
| G11 | المرحلة 2-3/4 | Covered-with-Action |
| G12 | المرحلة 2-3/11/13 | Covered-with-Action |
| G13 | المرحلة 2-11 | Covered-with-Action |
| G14 | المرحلة 2-12 | Covered-with-Action |
| G15 | المرحلة 3-3 + KPI throughput | Covered-with-Action |
| G16 | المرحلة 2-16 | Covered-with-Action |
| G17 | المرحلة 2-16 | Covered-with-Action |
| G18 | المرحلة 2-4/14 + تبسيط التدفقات | Covered-with-Action |
| G19 | المرحلة 2-3/4 | Covered-with-Action |
| G20 | المرحلة 2-11 + `P0-6` (audit safety) | Covered-with-Action |
| G21 | المرحلة 2-11 + المرحلة 3-3 | Covered-with-Action |
| G22 | المرحلة 2-12 + `P0-6` (security) | Covered-with-Action |
| G23 | المرحلة 3-6 + `P0-4` | Covered-with-Action |
| G24 | المرحلة 2-1 + المرحلة 3-1 (deprecation discipline) | Covered-with-Action |
| G25 | المرحلة 3-7 | Covered-with-Action |
| G26 | المرحلة 2-2 | Covered-with-Action |
| G27 | المرحلة 2-7 + `P0-2` | Covered-with-Action |
| G28 | المرحلة 1 `P0-6` | Covered-with-Action |
| G29 | المرحلة 3-5 + KPI جودة التنفيذ | Covered-with-Action |
| G30 | القسم 7-7 (`change record`) + Gates | Covered-with-Action |

---

## 6) تتبع Claim Validation (`C01..C25`)

| ID | الربط بالخطة الموحدة | التغطية |
|---|---|---|
| C01 | المرحلة 3-1 + Guardrails القنوات | Covered-with-Action |
| C02 | المرحلة 2-6 | Covered-with-Action |
| C03 | المرحلة 2-6 + `P0-6` timeline | Covered-with-Action |
| C04 | المرحلة 3-2 + `P0-6` | Guardrail |
| C05 | المرحلة 1 `P0-2` + `P0-6` | Guardrail |
| C06 | المرحلة 2-8 | Covered-with-Action |
| C07 | المرحلة 1 `P0-6` | Guardrail |
| C08 | المرحلة 1 `P0-6` | Guardrail |
| C09 | المرحلة 1 `P0-6` | Guardrail |
| C10 | المرحلة 1 `P0-6` | Guardrail |
| C11 | المرحلة 2-1 | Covered-with-Action |
| C12 | المرحلة 2-7 | Covered-with-Action |
| C13 | المرحلة 2-10 | Covered-with-Action |
| C14 | المرحلة 2-13 + المرحلة الممتدة-1 | Covered-with-Action |
| C15 | المرحلة 3-1 | Covered-with-Action |
| C16 | المرحلة 1 `P0-1` | Covered-with-Action |
| C17 | المرحلة 1 `P0-4` + المرحلة 3-6 | Covered-with-Action |
| C18 | المرحلة 1 `P0-4` + المرحلة 3-6 + تحديث README | Covered-with-Action |
| C19 | المرحلة 3-4 (README PostgreSQL) | Covered-with-Action |
| C20 | المرحلة 3-1 (`parse-paste` consolidation) | Covered-with-Action |
| C21 | المرحلة 1 `P0-5` (`convert-to-real`) | Covered-with-Action |
| C22 | المرحلة 1 `P0-5` (`commit-batch-draft`) | Covered-with-Action |
| C23 | المرحلة 2-15 (`active_action`) | Covered-with-Action |
| C24 | المرحلة 2-9 (object-level visibility) | Covered-with-Action |
| C25 | المرحلة 2-7 (centralized policy enforcement) | Covered-with-Action |

---

## 7) سجل الفجوات بعد الربط

- فجوات تتبع غير مربوطة (`Unmapped`) بعد هذا التحديث: **0**.
- عناصر تتطلب تنفيذ قبل الإغلاق: جميع البنود ذات حالة `Covered-with-Action`.
- عناصر حماية لا يجوز كسرها أثناء التنفيذ: كل البنود المصنفة `Guardrail`.

---

## 8) قرار الاعتماد

- هذه المصفوفة تعتمد كمرجع تدقيق إلزامي قبل الانتقال بين المراحل.
- أي بند جديد في `Docs` يضاف هنا قبل اعتباره ضمن نطاق التنفيذ.
- لا يعتبر البرنامج منتهيًا حتى تتحول كل عناصر `Covered-with-Action` إلى `Done` بأدلة تحقق.

