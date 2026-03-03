# قرار تجميد الميزات وربطها بالاستقرار — `P1-01`

تاريخ الإصدار: 2026-02-28  
الحالة: **Active / Mandatory**

الغرض:
- تنفيذ بند `P1-01` من التسلسل الإجباري.
- تفعيل Gate يمنع دمج أي تغيير وظيفي جديد غير مرتبط بخطة الاستقرار.
- ربط الإغلاق بالأدلة المرجعية: `R03`, `G03`, `FR-25`.

---

## 1) قرار التجميد

- طوال المرحلة الأولى `P1-*` يتم تجميد توسيع السطح الوظيفي (Feature Surface Expansion).
- أي تغيير في المسارات الحساسة يجب أن يكون مرتبطًا مباشرة بخطوات الاستقرار.
- الربط يتم إجباريًا عبر `CHANGE-TYPE` + `STABILITY-REFS` داخل وصف الـ PR.

---

## 2) Gate التنفيذي (Merge Enforcement)

تم اعتماد Gate تنفيذي داخل CI:
- السكربت: `app/Scripts/stability-freeze-gate.php`
- المنطق: `app/Support/StabilityFreezeGate.php`
- الربط في GitHub Actions: `.github/workflows/change-gate.yml`

سلوك Gate:
1. يقرأ الخطوة النشطة من `Docs/WBGL-EXECUTION-STATE-AR.json`.
2. إذا كانت الخطوة النشطة من نوع `P1-*` يتم فرض التجميد تلقائيًا.
3. لأي تغيير حساس (`api/`, `app/`, `views/`, `templates/`, `partials/`, `public/js/`, `public/css/`, `database/migrations/`) يجب وجود `CHANGE-TYPE`.
4. أثناء `P1-*` يمنع `CHANGE-TYPE: feature` أو `CHANGE-TYPE: enhancement`.
5. لأي تغيير حساس يجب وجود `STABILITY-REFS`.
6. `STABILITY-REFS` يجب أن تحتوي:
   - معرف خطوة من `P1-xx`
   - ومعرّف تغطية `FR/R/G/C` موجود ضمن تغطيات المرحلة الأولى.
7. يمنع إضافة ملفات جديدة في أسطح الميزات المباشرة أثناء التجميد:
   - `api/`, `views/`, `templates/`, `partials/`, `public/js/`, `public/css/`

---

## 3) صيغة إلزامية في وصف PR

```text
CHANGE-TYPE: bugfix
STABILITY-REFS: P1-02, FR-03, R15
```

أي تغييرات حساسة بدون هذين الحقلين (أو بقيمة `feature`) تعتبر غير قابلة للدمج أثناء `P1-*`.

---

## 4) ملاحظات الحوكمة

- Scope الاستقرار المسموح يُستخرج من `Docs/WBGL-EXECUTION-SEQUENCE-AR.json` تلقائيًا.
- عند الانتقال إلى `P2-*` يتوقف Gate عن فرض التجميد تلقائيًا.
- هذا القرار لا يلغي أي Gate آخر، بل يضيف طبقة منع مبكرة لزحف الميزات.
