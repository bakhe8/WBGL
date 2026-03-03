# خارطة طريق التعريب الشامل الآلي — WBGL

تاريخ الإصدار: 2026-03-02  
الحالة: **Active / Mandatory**  
نمط التنفيذ: **متتابع إلزامي (Sequential Gate-Driven)**  

---

## 1) الهدف التنفيذي

تحويل كل نص ظاهر للمستخدم في النظام إلى نص ديناميكي معتمد على ملفات الترجمة، بحيث يمكن تشغيل النظام بالكامل بالعربية أو الإنجليزية بدون نصوص صلبة متبقية.

النتيجة النهائية المطلوبة:
1. لا يوجد أي نص UI صلب خارج نظام i18n.
2. كل الصفحات والمودالات والحالات التفاعلية تتغير حسب اللغة.
3. الاختبارات الآلية تمنع رجوع المشكلة مستقبلًا.

---

## 2) خط الأساس الحالي (Baseline)

تم رصد أولي للنصوص العربية الصلبة في المسارات التشغيلية:
1. إجمالي مواضع مرصودة: **1183**.
2. عدد الملفات المتأثرة: **69**.
3. أعلى الملفات كثافة: `views/settings.php`, `views/statistics.php`, `views/maintenance.php`, `views/users.php`, `index.php`, `public/js/records.controller.js`.

ملاحظة: هذه الأرقام هي baseline التنفيذ، ويجب أن تنخفض بشكل متتابع حتى الصفر.

---

## 3) النطاق الكامل

يشمل التعريب الإلزامي:
1. كل صفحات `views/*`.
2. `index.php` + كل `partials/*` + `templates/*`.
3. كل نصوص JS في `public/js/*` (toasts/alerts/dialogs/tooltips/button labels).
4. رسائل API الظاهرة للمستخدم (بصيغة message_key بدل نص صلب).
5. نصوص runtime الناتجة بعد التفاعل (مودال، dropdown، timeline states، dynamic cards).

غير مشمول:
1. بيانات الأعمال نفسها (أسماء موردين/بنوك/أرقام/محتوى خطابات مصدره المستخدم).

---

## 4) معمارية التعريب المستهدفة

1. **Source of Truth**: `public/locales/{ar,en}/*.json`.
2. **Namespaces**: `common`, `auth`, `settings`, `users`, ثم إضافة `index`, `batches`, `batch_detail`, `statistics`, `maintenance`, `timeline`, `modals`, `messages`.
3. **Frontend API**: `WBGLI18n.t`, `tPlural`, `data-i18n`, `data-i18n-title`, `data-i18n-placeholder`, `data-i18n-content`.
4. **API Contract**: الاستجابات ترجع `message_key` و`message_params` بدل نص صلب عند الحاجة للعرض.

---

## 5) خطة التنفيذ المتتابع الآلي

## المرحلة I0 — Freeze + Governance Bootstrap

1. تجميد إضافة ميزات UI جديدة خلال المشروع.
2. إنشاء ملف حالة تنفيذ: `Docs/WBGL-I18N-EXECUTION-STATE-AR.json`.
3. تعريف بوابات الانتقال: لا انتقال لمرحلة لاحقة قبل PASS.

مخرج الإغلاق:
1. حالة تنفيذ مبدئية + baseline مثبت.

## المرحلة I1 — Catalog Automation

1. إنشاء ماسح ثابت: `app/Scripts/i18n-static-scan.php`.
2. إنشاء جامع runtime عبر Playwright: `app/Scripts/i18n-runtime-harvest.mjs`.
3. إنشاء مجمّع catalog موحّد: `app/Scripts/i18n-catalog-merge.php`.

ملفات الإخراج:
1. `storage/i18n/static-findings.json`
2. `storage/i18n/runtime-findings.json`
3. `storage/i18n/i18n-catalog.json` (المرجع الموحد)

مخرج الإغلاق:
1. Catalog شامل لكل النصوص (Static + Runtime) مع مصدر كل نص.

## المرحلة I2 — Key Strategy + Locale Sync

1. إنشاء مولّد مفاتيح: `app/Scripts/i18n-keygen.php`.
2. فرض naming convention ثابت للمفاتيح (`page.section.component.action`).
3. إنشاء مزامن ملفات اللغة: `app/Scripts/i18n-sync-locales.php`.
4. إضافة حالات المفتاح: `new`, `mapped`, `translated`, `reviewed`.

مخرج الإغلاق:
1. كل عناصر catalog مرتبطة بمفاتيح.
2. ملفات `ar/en` متطابقة في المفاتيح 100%.

## المرحلة I3 — Bulk Migration (UI Markup)

1. تحويل النصوص الصلبة في PHP/HTML إلى `data-i18n*`.
2. تحويل attributes (`title`, `placeholder`, `content`) إلى مفاتيح.
3. التخلص من النصوص الثابتة داخل الأزرار والكروت والعناوين.

ترتيب التنفيذ:
1. `index.php` + `partials/*` الأساسية.
2. `views/users.php`, `views/settings.php`.
3. `views/batches.php`, `views/batch-detail.php`, `views/statistics.php`, `views/maintenance.php`.

مخرج الإغلاق:
1. عدم وجود نص عربي/إنجليزي صلب في markup التشغيلي.

## المرحلة I4 — Bulk Migration (JavaScript Runtime)

1. استبدال كل الرسائل الصلبة في JS بـ `WBGLI18n.t(...)`.
2. تحويل النصوص داخل `innerHTML`/template strings إلى مفاتيح.
3. إضافة helper قياسي: `t(key,fallback,params)` في كل controller.

أولوية الملفات:
1. `public/js/records.controller.js`
2. `public/js/input-modals.controller.js`
3. `public/js/timeline.controller.js`
4. بقية الملفات ذات التفاعل العالي.

مخرج الإغلاق:
1. كل toasts/dialogs/tooltips/runtime labels ديناميكية.

## المرحلة I5 — API Message Key Refactor

1. توحيد envelope: `{ success, data, error, message_key, message_params, request_id }`.
2. منع الرسائل الصلبة في API responses.
3. ربط الواجهة بمفاتيح الرسائل بدل النص الخام.

مخرج الإغلاق:
1. كل الرسائل المعروضة للمستخدم قابلة للترجمة.

## المرحلة I6 — Automated Testing

## 6.1 اختبارات ثابتة (Static)
1. `i18n-static-scan` يجب أن يعطي `hardcoded_ui_strings = 0`.
2. `i18n-missing-keys` يجب أن يعطي `0`.
3. `i18n-quality-gate` يمنع `__TODO__`, `????`, وقيم المفاتيح غير المترجمة في الكتالوج.
4. `i18n-unused-keys` ينتج تقرير فقط (لا يفشل مبدئيًا).

## 6.2 اختبارات وحدة/تكامل
1. اختبار loader: تحميل namespace fallback بشكل صحيح.
2. اختبار interpolation/plural.
3. اختبار language toggle + persistence.
4. اختبار API message_key contract.

## 6.3 اختبارات E2E (Playwright)
1. تشغيل AR وEN على الصفحات الأساسية.
2. فتح المودالات التفاعلية والتحقق أن النصوص مترجمة.
3. سيناريو timeline/history/modal/toast.
4. لقطة Snapshot لكل صفحة لكل لغة.

مخرج الإغلاق:
1. `npm run test:e2e` PASS.
2. تقرير تغطية i18n runtime PASS.

## المرحلة I7 — CI Gates

إضافة Workflow: `.github/workflows/i18n-gate.yml`

البوابات الإلزامية:
1. `i18n-static-scan` PASS.
2. `i18n-sync-check` PASS.
3. `i18n-missing-keys=0`.
4. `playwright-smoke-i18n` PASS.

سياسة الفشل:
1. أي PR يضيف نصًا صلبًا جديدًا في UI => فشل.
2. أي مفتاح مفقود في لغة => فشل.

## المرحلة I8 — Cutover + Lock

1. تفعيل وضع صارم: `I18N_STRICT_MODE=true`.
2. منع fallback غير المصرح به في الإنتاج (باستثناء مفاتيح allowlist مؤقتة).
3. تحديث `INDEX-DOCS-AR` وربط الأدلة.

مخرج الإغلاق:
1. النظام ثنائي اللغة كامل مع بوابات منع الرجوع.

---

## 6) تعريف الإنجاز (Definition of Done)

لا تعتبر المهمة منتهية إلا إذا تحقق:
1. `hardcoded_ui_strings = 0` في المسارات التشغيلية.
2. `missing_locale_keys = 0` بين `ar/en`.
3. `runtime_untranslated_nodes = 0` في E2E scenarios المعتمدة.
4. جميع بوابات CI الخاصة بـ i18n PASS.

---

## 7) أوامر التشغيل القياسية

1. `php app/Scripts/i18n-static-scan.php scan`
2. `node app/Scripts/i18n-runtime-harvest.mjs`
3. `php app/Scripts/i18n-catalog-merge.php`
4. `php app/Scripts/i18n-keygen.php`
5. `php app/Scripts/i18n-sync-locales.php`
6. `php app/Scripts/i18n-quality-gate.php scan`
7. `php app/Scripts/i18n-quality-gate.php gate`
8. `php app/Scripts/i18n-static-scan.php gate`
9. `npm run test:e2e`

---

## 8) مصفوفة المخاطر والضوابط

1. خطر: كسر واجهة أثناء استبدال نصوص template strings.  
الضابط: التحويل دفعات صغيرة + E2E smoke بعد كل دفعة.

2. خطر: عدم التقاط نصوص تظهر بعد التفاعل فقط.  
الضابط: runtime harvester عبر Playwright مع سيناريوهات إلزامية.

3. خطر: تضخم مفاتيح غير منضبط.  
الضابط: key convention + lint يمنع مفاتيح خارج النمط.

4. خطر: رجوع النصوص الصلبة لاحقًا.  
الضابط: CI gate إلزامي يمنع الدمج.

---

## 9) طريقة التنفيذ اليومية (إلزامية)

1. تنفيذ دفعة واحدة فقط `in_progress`.
2. تشغيل scan -> migrate -> sync -> tests.
3. تحديث حالة التنفيذ بالأرقام (قبل/بعد).
4. إغلاق الدفعة فقط بعد PASS كامل.

---

## 10) الخلاصة

هذه الخارطة تحول التعريب من مهمة يدوية إلى **منظومة آلية قابلة للقياس**.  
النجاح هنا ليس “ترجمة صفحات” فقط، بل **ضمان هندسي مستمر** أن أي نص جديد يدخل النظام يكون ديناميكيًا منذ أول يوم.
