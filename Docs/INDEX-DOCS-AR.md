# فهرس وثائق WBGL وربطها (Master Index)

آخر تحديث: 2026-02-28  
الغرض: فهرسة جميع ملفات `Docs` وربطها ببعض بشكل مرجعي واضح.

---

## 1) الفهرس الكامل للملفات

| الملف | النوع | الغرض |
|---|---|---|
| [WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md](./WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md) | Resolved Unified Report | النسخة الموحدة النهائية المعتمدة بعد حسم التعارضات |
| [WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md](./WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md) | Final Claims Register | السجل الرسمي النهائي للادعاءات وحالاتها |
| [critical-feature-discovery-report.md](./critical-feature-discovery-report.md) | Technical Extraction | استخراج شامل للميزات الصريحة/الضمنية/الناشئة والقيود المخفية |
| [wbgl-cognitive-system-audit-ar.md](./wbgl-cognitive-system-audit-ar.md) | Deep Cognitive Audit | تدقيق معمق لسلوك النظام الفعلي ومخاطره البنيوية |
| [WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md](./WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md) | Claim Validation | إثبات/نفي الادعاءات (Confirmed / Partial / Refuted) بالأدلة |
| [wbgl-ui-first-operational-identity-audit-ar-2026-02-28.md](./wbgl-ui-first-operational-identity-audit-ar-2026-02-28.md) | UI-First Operational Audit | ربط الواجهة بالمنطق والتنفيذ وقسم الهوية التشغيلية |
| [wbgl-human-risk-report-ar-2026-02-28.md](./wbgl-human-risk-report-ar-2026-02-28.md) | Human Risk | تقرير الحمل الذهني والمخاطر البشرية وتجربة الاستخدام |
| [wbgl-role-operational-analysis-ar-2026-02-28.md](./wbgl-role-operational-analysis-ar-2026-02-28.md) | Role-Based Operations | تحليل تشغيلي مفصل لكل دور فعلي (RBAC + UI + API) |
| [wbgl-feedback-meeting-baseline-ar-2026-02-28.md](./wbgl-feedback-meeting-baseline-ar-2026-02-28.md) | Baseline | خط أساس معتمد لدراسة التطوير القادمة (صوت المستخدمين) |
| [wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md](./wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md) | Consistency Summary | خلاصة الاتساق والتضارب بين التقارير (تحتاج تدقيق) |
| [WBGL-FULL-TRACEABILITY-MATRIX-AR.md](./WBGL-FULL-TRACEABILITY-MATRIX-AR.md) | Full Traceability Matrix | مصفوفة تتبع إلزامية تربط كل FR/R/G/C بالخطة التنفيذية |

---

## 2) الربط بين الملفات (Dependency / Cross-Reference Map)

## 0) الطبقة المرجعية المعتمدة (Authoritative Layer)
- [WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md](./WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md)  
  المرجع التنفيذي الموحد النهائي (Resolved Narrative).
- [WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md](./WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md)  
  المرجع الرسمي لحسم كل claim بحالة نهائية.

## أ) طبقة الدليل الفني (Evidence Layer)
- [critical-feature-discovery-report.md](./critical-feature-discovery-report.md)  
  يعتمد عليه: جميع ملفات التحليل اللاحقة كمرجع ميزات وسلوك.
- [wbgl-cognitive-system-audit-ar.md](./wbgl-cognitive-system-audit-ar.md)  
  يتقاطع مع: Claim Validation + UI-First + Human Risk.
- [WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md](./WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md)  
  هو مرجع الحسم عند التعارض لأنه يميز بين: Confirmed/Partial/Refuted.

## ب) طبقة التشغيل وتجربة المستخدم (Operational Layer)
- [wbgl-ui-first-operational-identity-audit-ar-2026-02-28.md](./wbgl-ui-first-operational-identity-audit-ar-2026-02-28.md)  
  يتقاطع مع: Role Analysis + Human Risk.
- [wbgl-human-risk-report-ar-2026-02-28.md](./wbgl-human-risk-report-ar-2026-02-28.md)  
  يغذي مباشرة: Feedback Baseline.
- [wbgl-role-operational-analysis-ar-2026-02-28.md](./wbgl-role-operational-analysis-ar-2026-02-28.md)  
  يغذي مباشرة: Feedback Baseline + Prioritization.

## ج) طبقة القرار والتخطيط (Decision Layer)
- [wbgl-feedback-meeting-baseline-ar-2026-02-28.md](./wbgl-feedback-meeting-baseline-ar-2026-02-28.md)  
  المرجع التشغيلي الأساسي لأي دراسة تطوير قادمة.
- [wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md](./wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md)  
  مرجع توحيد الرواية بين الملفات قبل اتخاذ قرارات عالية الأثر.
- [WBGL-FULL-TRACEABILITY-MATRIX-AR.md](./WBGL-FULL-TRACEABILITY-MATRIX-AR.md)  
  مرجع التغطية الإلزامية (Coverage Gate) قبل الانتقال بين مراحل التنفيذ.

---

## 3) مصفوفة الاتفاق/التضارب المختصرة

| الموضوع | ملفات متفقة | ملفات فيها تباين/تعارض |
|---|---|---|
| Read causes mutation | Cognitive Audit + Claim Validation + Role Analysis + Human Risk + Feedback | لا يوجد تعارض جوهري |
| فجوة صلاحيات UI vs Backend | UI-First + Role Analysis + Human Risk + Feedback + Cognitive | لا يوجد تعارض جوهري |
| عمق الحوكمة (Undo/Break-glass/Timeline) | Technical + Cognitive + UI-First + Role + Human | لا يوجد تعارض جوهري |
| شمول معالجة التكرارات عبر كل القنوات | Feature Discovery | Claim Validation (جزئية) |
| إنفاذ read-only للـ released | Feature Discovery (وصف عام) | Claim Validation/Cognitive (إنفاذ غير موحد) |
| Reopen permissions vs التنفيذ الفعلي | Role Analysis + Feedback + UI-First | توصيفات أعلى مستوى في تقارير عامة قد تبدو أقل حسمًا |
| فشل الإدخال اليدوي كحالة ثابتة | Human Risk (مطروح بقوة) | غير مثبت بنفس القوة في الملفات التقنية |

---

## 4) مسار القراءة المقترح (لأي فريق تطوير جديد)

1. ابدأ بـ [WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md](./WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md) لحسم حقائق التنفيذ.  
2. ثم [wbgl-role-operational-analysis-ar-2026-02-28.md](./wbgl-role-operational-analysis-ar-2026-02-28.md) لفهم أثرها على الأدوار.  
3. ثم [wbgl-feedback-meeting-baseline-ar-2026-02-28.md](./wbgl-feedback-meeting-baseline-ar-2026-02-28.md) لتحديد الأولويات التشغيلية.  
4. راجع [wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md](./wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md) قبل أي قرار نهائي لتسوية نقاط التعارض المفتوحة.
5. اعتمد [WBGL-FULL-TRACEABILITY-MATRIX-AR.md](./WBGL-FULL-TRACEABILITY-MATRIX-AR.md) قبل إغلاق أي مرحلة تنفيذ للتأكد من عدم وجود فجوات غير مربوطة.

---

## 5) سياسة الاعتماد المرجعي

- **المصدر النهائي المعتمد (Resolved):**  
  [WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md](./WBGL-UNIFIED-RESOLVED-REPORT-AR-2026-02-28.md) +  
  [WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md](./WBGL-FINAL-CLAIMS-REGISTER-AR-2026-02-28.md)
- **مصادر الأدلة الفنية:**  
  `WBGL_COGNITIVE_AUDIT_CLAIM_VALIDATION_AR.md` + `wbgl-cognitive-system-audit-ar.md` + `critical-feature-discovery-report.md`
- **مصادر الأدلة التشغيلية:**  
  `wbgl-role-operational-analysis-ar-2026-02-28.md` + `wbgl-feedback-meeting-baseline-ar-2026-02-28.md` + `wbgl-human-risk-report-ar-2026-02-28.md`
- **الحالة الحالية لملف الاتساق السابق:**  
  `wbgl-docs-consistency-summary-needs-audit-ar-2026-02-28.md` أصبح وثيقة تاريخية مرجعية بعد إصدار النسخة `Resolved`.
