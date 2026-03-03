# الخطة الشاملة لتوحيد الثيمات والستايلات والواجهات — WBGL

تاريخ الإصدار: 2026-03-01  
الحالة: **Active / Mandatory**  
نمط التنفيذ: **متتابع بالاعتماد (Dependency-Driven)** بدون ربط بزمن تقويمي.

---

## 1) الهدف التنفيذي

هذه الخطة تضمن أن كل واجهات النظام تعمل بنفس منطق العرض عبر جميع الثيمات (`light`, `dark`, `desert`, `system/auto`) بدون تناقضات بصرية أو سلوكية.

الناتج المطلوب:
- ثيمات متسقة 100% على كل الصفحات.
- منع أي `style` أو لون صلب خارج نظام التوكنز.
- إغلاق drift بين CSS وJS وHTML snapshots.
- اعتماد حوكمة تمنع رجوع التناقضات مستقبلًا.

---

## 2) القواعد الحاكمة للخطة

1. التنفيذ **بالتوالي**: لا يتم الانتقال لخطوة إلا بعد إغلاق السابقة بأدلة.
2. لا ميزات جديدة قبل إغلاق التناقضات البصرية الحرجة.
3. أي لون/حد/ظل يجب أن يمر عبر Tokens وليس قيمًا صلبة.
4. أي واجهة مخفية بصلاحية يجب أن تكون محمية Backend كذلك.
5. أي إصلاح UI لا يعتبر مكتملًا بدون تحقق على الثيمات الثلاثة + RTL/LTR + Desktop/Mobile.

---

## 3) النطاق الكامل المغطى

### 3.1 الثيمات
- `light`
- `dark`
- `desert`
- `system` (يحيل إلى ثيم فعلي حسب التفضيل)

### 3.2 الواجهات الرسمية
- `index.php` (الواجهة الرئيسية)
- `views/batches.php`
- `views/batch-detail.php`
- `views/batch-print.php`
- `views/statistics.php`
- `views/settings.php`
- `views/users.php`
- `views/login.php`
- `views/maintenance.php` (إذا بقيت ضمن النطاق التشغيلي)

### 3.3 الواجهات المقيدة
- `views/confidence-demo.php` (Developer-only)

### 3.4 طبقات الستايل
- `public/css/design-system.css`
- `public/css/themes.css`
- `public/css/layout.css`
- `public/css/components.css`
- `public/css/index-main.css`
- `public/css/mobile.css`
- `public/css/batch-detail.css`
- `public/css/a11y.css`
- `assets/css/letter.css`
- `public/css/confidence-indicators.css`

### 3.5 طبقات JS المؤثرة بصريًا
- `public/js/main.js`
- `public/js/records.controller.js`
- `public/js/timeline.controller.js`
- `public/js/input-modals.controller.js`
- `public/js/global-shortcuts.js`
- `public/js/confidence-ui.js`

### 3.6 القوالب والمكونات
- `partials/unified-header.php`
- `partials/letter-renderer.php`
- `partials/preview-placeholder.php`
- `partials/record-form.php`
- `partials/timeline-section.php`
- `templates/letter-template.php`

---

## 4) المشكلات الجذرية المعروفة

1. وجود `inline styles` في PHP/JS يلتف على الثيم.
2. وجود ألوان صلبة كثيرة داخل ملفات CSS تشغيلية.
3. ازدواجية تعريف Tokens بين `design-system.css` و`index-main.css`.
4. تحميل snapshots تاريخية بMarkup قديم ينتج تعارضات شكلية.
5. عدم وجود اختبارات Theme Parity آلية تمنع regression.

---

## 5) تعريف الإنجاز (Definition of Done)

يعتبر المشروع منتهيًا لهذه الخطة فقط إذا تحقق كل ما يلي:

1. لا يوجد `inline style` بصري تشغيلي في الواجهات الأساسية.
2. لا يوجد لون/ظل/حد صلب داخل CSS التشغيلي إلا في حالات موثقة كاستثناء.
3. نفس الصفحة تعطي نفس بنية العرض تحت `light/dark/desert` مع اختلاف palette فقط.
4. لا اختلاف شكلي بين الحالة الحالية والتاريخية لنفس المكون.
5. وجود اختبارات آلية تمنع رجوع المخالفات.
6. تحديث `Docs` ومصفوفة التتبع بالأدلة النهائية.

---

## 6) خطة التنفيذ المتتابع (بدون زمن)

## المرحلة S0 — Baseline Freeze

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S0-01 | تجميد التعديلات غير المرتبطة بالثيمات | Branch نظيف لنطاق UI | عدم وجود تعديلات خارج Scope |
| S0-02 | أخذ Baseline Screenshots لكل الصفحات والثيمات | حزمة صور مرجعية | توفر صور قبل/بعد للمقارنة |
| S0-03 | إنشاء قائمة مخالفة أولية | ملف Audit أولي | حصر كل `inline/hardcoded` |

## المرحلة S1 — Token Canonicalization

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S1-01 | اعتماد `design-system.css` كمصدر Tokens وحيد | وثيقة قرار + تعديل CSS | إزالة تكرار tokens من مصادر أخرى |
| S1-02 | ربط كل themes في `themes.css` بالتوكنز فقط | خريطة mapping واضحة | عدم وجود قيم تعارض خارج map |
| S1-03 | تعريف توكنات للـ overlays/modals/alerts/chips/print button | Tokens جديدة موثقة | استبدال القيم الصلبة في الطبقات الحرجة |

## المرحلة S2 — CSS Sanitization

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S2-01 | إزالة الألوان الصلبة من `index-main.css` | CSS معتمد على tokens | lint pass + visual pass |
| S2-02 | تنظيف `mobile.css` من القيم الصلبة | Mobile parity | عدم اختلاف الهوية بين Desktop/Mobile |
| S2-03 | تنظيف `components.css` و`batch-detail.css` | Components موحدة | اختفاء التباينات في cards/modals |
| S2-04 | عزل استثناءات `letter.css` المبررة فقط | قائمة استثناءات print/official letter | كل استثناء موثق بسبب |

## المرحلة S3 — Inline Style Elimination

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S3-01 | استخراج inline styles من `templates/letter-template.php` إلى CSS | قالب نظيف | zero inline styling داخل template |
| S3-02 | استخراج inline styles من `partials/*` إلى classes | partials قابلة للثيم | zero inline styling التشغيلي |
| S3-03 | استبدال style strings في `main.js` و`records.controller.js` | modals/toasts تعتمد classes | اختبارات تفاعل ناجحة |
| S3-04 | استبدال style strings في `input-modals.controller.js` | مخرجات الاستيراد متوافقة مع الثيم | لا ألوان ثابتة في HTML المولد |

## المرحلة S4 — Theme Parity by Surface

| المعرف | السطح | التحقق الإلزامي |
|---|---|---|
| S4-01 | Header + Global Nav + User Menu | ثبات spacing/icon/badge بكل الثيمات |
| S4-02 | Sidebar + Notes + Attachments | لا قص/تداخل/ألوان غير متباينة |
| S4-03 | Data Card + Action Buttons | نفس الأحجام/الحدود مع اختلاف palette فقط |
| S4-04 | Timeline Cards + Active State | Active/hover/readability متسقة |
| S4-05 | Preview Canvas + A4 + Print Button | نفس شكل الزر في current/historical |
| S4-06 | Batches/Statistics/Settings/Users | لا شذوذ في الجداول/البطاقات/tabs |
| S4-07 | Login + صفحات مقيدة | نفس الهوية البصرية + صلاحيات صحيحة |

## المرحلة S5 — Historical Snapshot Rendering Contract

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S5-01 | فرض normalize بعد أي حقن snapshot | وحدة تطبيع مركزية | لا اختلافات بعد تنقل timeline |
| S5-02 | منع حقن styles داخل snapshots المخزنة | contract واضح للسيرفر | snapshot HTML بدون inline style |
| S5-03 | fallback آمن لنسخ legacy | adapter واضح | لا كسر على بيانات تاريخية قديمة |

## المرحلة S6 — Accessibility + Direction

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S6-01 | تدقيق contrast لكل الثيمات | تقرير contrast | اجتياز AA للمكونات الأساسية |
| S6-02 | تدقيق focus/keyboard لكل الحوارات | a11y fixes | تنقل كامل بلوحة المفاتيح |
| S6-03 | تدقيق RTL/LTR ديناميكي | parity تقرير اتجاه | لا انكسار layout عند تبديل الاتجاه |

## المرحلة S7 — Automation and Governance

| المعرف | العمل | المخرج | شرط الإغلاق |
|---|---|---|---|
| S7-01 | إضافة فحص يمنع inline style الجديد | CI gate | فشل تلقائي عند أي inline جديد |
| S7-02 | إضافة فحص يمنع hardcoded colors غير المصرح بها | CI gate | allowlist واضحة |
| S7-03 | إضافة visual snapshot tests للصفحات الأساسية | baseline tests | مقارنة قبل/بعد ثابتة |
| S7-04 | تحديث `INDEX-DOCS-AR` و`TRACEABILITY` | توثيق نهائي | كل خطوة مربوطة بأدلتها |

---

## 7) آلية تنفيذ كل خطوة (نموذج ثابت)

لكل معرف خطوة من `S0..S7` ينفذ نفس التسلسل:

1. فتح خطوة واحدة فقط كـ `in_progress`.
2. تنفيذ تعديل كودي محدود النطاق.
3. تشغيل فحوص syntax + unit/integration ذات الصلة.
4. تشغيل فحص بصري على `light/dark/desert` مع لقطات.
5. توثيق الدليل في سجل التنفيذ.
6. إغلاق الخطوة `completed` ثم الانتقال لما بعدها.

---

## 8) المخرجات الإلزامية لكل صفحة

لكل صفحة رسمية يجب إنتاج Evidence Pack يحتوي:

1. لقطة `light`.
2. لقطة `dark`.
3. لقطة `desert`.
4. لقطة `RTL`.
5. لقطة `LTR`.
6. لقطة حالة تاريخية (إن وجد timeline).
7. لقطة حالة modal مفتوح (إن وجد).

---

## 9) مصفوفة المخاطر والضوابط

| الخطر | الأثر | الضابط |
|---|---|---|
| تعديل CSS يكسر مكونًا آخر | عالي | تنفيذ على خطوات صغيرة + visual regression |
| إزالة inline styles تغير سلوك JS | متوسط | استبدالها بـ classes مع tests event flow |
| snapshots legacy لا تتوافق مع القواعد الجديدة | عالي | adapter normalization قبل render |
| اختلاف RTL/LTR بعد refactor | متوسط | suite تحقق اتجاه لكل صفحة |
| عودة hardcoded colors بعد الدمج | عالي | CI gate + lint rule |

---

## 10) قواعد الاستثناءات

يسمح باستثناءات محدودة فقط في حالتين:

1. تنسيق مستند رسمي مطبوع (A4) يتطلب ثوابت قانونية.
2. متطلبات وصول بصري (Accessibility) تحتاج لونًا مباشرًا مؤقتًا.

شرط قبول أي استثناء:

1. توثيق صريح في الملف + سبب.
2. ربط الاستثناء بمعرف خطوة.
3. مراجعة واعتماد قبل الدمج.

---

## 11) أوامر التدقيق القياسية

```powershell
rg --line-number --glob '*.php' 'style="' index.php views partials templates
rg --line-number --glob '*.js' 'style\\.|style=' public/js
rg --line-number --glob '*.css' '#[0-9A-Fa-f]{3,8}|rgba?\\(|hsla?\\(' public/css assets/css
php app/Scripts/theme-style-audit.php scan
php app/Scripts/theme-style-audit.php gate
php vendor/bin/phpunit
```

---

## 12) قرار التشغيل النهائي

هذه الخطة هي المرجع التنفيذي الوحيد لتنظيف الثيمات والستايلات والواجهات في WBGL.  
لا يتم إعلان اكتمال العمل إلا بعد إغلاق جميع معرفات `S0..S7` مع أدلة تحقق بصرية وكودية.
