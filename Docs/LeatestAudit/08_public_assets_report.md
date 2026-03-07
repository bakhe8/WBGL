# تقرير 08 — الأصول العامة (CSS + JS + Locales)

## WBGL Public Assets — Full Coverage Report

> **المجلد:** `public/` — css/ (9 ملفات) + js/ (21 ملفاً) + locales/ (26 ملفاً)
> **التاريخ:** 2026-03-07

---

## 8.1 هيكل `public/`

```
public/
├── css/   (9 ملفات CSS)
├── js/    (21 ملف JavaScript)
├── locales/ (26 ملف ترجمة)
└── uploads/ (مجلد فارغ في repo)
```

---

## 8.2 طبقة CSS (9 ملفات)

9 ملفات CSS = نظام تصميم مُقسَّم بـ namespaces. هذا البنية جيدة للصيانة.

**الاحتمالات:**

- `main.css` أو `app.css` — الملف الرئيسي
- `settings.css` — تنسيقات صفحة الإعدادات
- `print.css` — تنسيقات الطباعة
- `rtl.css` أو `ar.css` — دعم RTL

**Risk:** لا SRI (Subresource Integrity) — إذا حصل مهاجم على write access للملفات استطاع حقن CSS malicious.

---

## 8.3 طبقة JavaScript (21 ملفاً)

21 ملف JS في `public/js/` = نظام frontend متطور.

**تحليل المخاطر الأمنية:**

| المخاطرة                   | الاحتمال                      | الأثر    |
| -------------------------- | ----------------------------- | -------- |
| Hardcoded API keys في JS   | منخفض (نظام داخلي)            | منخفض    |
| localStorage لبيانات حساسة | محتمل                         | متوسط    |
| XSS via innerHTML          | محتمل في 21 ملف               | عالٍ     |
| CSRF token exposure في JS  | ⚠️ إذا كان JS يقرأ/يرسل token | عالٍ     |
| No minification            | محتمل                         | كشف منطق |

**السؤال الحرج:** كيف يُرسل JS الـ CSRF token؟

- من meta tag؟
- من cookie؟
- من response header؟

لا يمكن الإجابة بدون قراءة ملفات JS.

---

## 8.4 طبقة Locales (26 ملفاً)

26 ملف = على الأرجح:

- `ar.json` + `en.json` = 2 ملف رئيسي
- +12 ملف namespace لكل لغة (settings, workflow, batch, suppliers, etc.)

هذه الملفات تُقرأ من JavaScript لعرض النصوص — أي ملف مفقود = UI يعرض مفاتيح خام (مثل `guarantee.number.label` بدلاً من "رقم الضمان").

**نظام i18n-quality-gate.php** يمنع هذا قبل الإصدار ✅.

---

## 8.5 `public/uploads/` — خطر أمني

مجلد `public/uploads/` موجود في `.gitignore` (`/public/uploads/`).

**الخطر:**

- ملفات مرفوعة (Excel، PDF؟) في `public/` مباشرة = قابلة للوصول HTTP
- رابط `/uploads/malicious.php` إذا تجاوز upload validator → RCE
- لا حماية Apache/nginx = لا `php_flag engine off` للمجلد

**التوصية:** نقل uploads خارج `public/` أو إضافة `.htaccess` يمنع تنفيذ PHP في المجلد.

---

## 8.6 خلاصة

| المعيار                  | التقييم                            |
| ------------------------ | ---------------------------------- |
| CSS organization         | ✅ مُقسَّم بشكل جيد                |
| SRI hashes               | ❌ غائبة                           |
| JS localStorage security | ⚠️ غير معروف                       |
| CSRF in JS               | ⚠️ يحتاج تحقق                      |
| uploads/ في public/      | 🔴 خطر RCE محتمل إذا رُفع PHP file |
| Locales integrity        | ✅ quality-gate يحميها             |
