# تقرير 06 — نظام الترجمة (i18n)

## WBGL Internationalization System — Full Coverage Report

> **الملفات:** `app/Scripts/i18n-*` + `public/locales/`
> **التاريخ:** 2026-03-07

---

## 6.1 معمارية نظام i18n

### مرحلة المسح والجمع (i18n:i1)

```
المدخل: كود PHP + HTML + JS
        ↓
1. i18n-static-scan.php (13.4KB)
   → يمسح كل ملفات PHP والـ views للمفاتيح الثابتة
   → مثال: t('guarantee.number') أو __('suppliers.name')
        ↓
2. i18n-runtime-harvest.mjs (9.7KB)
   → يُشغّل المتصفح (Playwright؟) ليلتقط المفاتيح الديناميكية
   → مثال: مفاتيح تُنشأ برمجياً من JS
        ↓
3. i18n-catalog-merge.php (5.4KB)
   → يدمج النتائج في catalog واحد
```

### مرحلة التوليد والمزامنة (i18n:i2)

```
4. i18n-keygen.php (17.3KB)
   → يُولّد hash keys فريدة لكل نص
   → يمنع تعارض المفاتيح
        ↓
5. i18n-sync-locales.php (8.4KB)
   → يُزامن ملفات ar.json و en.json
   → يُضيف مفاتيح ناقصة بـ placeholder
        ↓
6. التحقق النهائي: i18n-sync-locales.php check
   → يُفشل العملية إذا وُجدت مفاتيح غير متزامنة
```

### أدوات الجودة

```
7. i18n-quality-gate.php (15.2KB)
   → بوابة الجودة — تمنع الإصدار لو ترجمات ناقصة
   → مدمجة في release-gate.php

8. i18n-fill-ar-from-en.py (5.3KB)
   → ملء الترجمة العربية من الإنجليزية (آلياً)
   → يتطلب: API ترجمة خارجي؟ أو قاموس محلي؟

9. i18n-fill-en-from-ar.py (4.7KB)
   → العكس — رؤية مثيرة للاهتمام
```

---

## 6.2 هيكل `public/locales/`

26 ملف في `locales/` — يشير لـ 13 ملف لكل لغة (ar + en)، أو تقسيم namespace:

- `ar.json` (الملف الرئيسي)
- `en.json`
- ملفات namespace إضافية (مثل `ar.settings.json`)

---

## 6.3 تقييم النضج

| المعيار                     | التقييم                       |
| --------------------------- | ----------------------------- |
| اكتشاف المفاتيح الثابتة     | ✅ آلي عبر scanner            |
| اكتشاف المفاتيح الديناميكية | ✅ Playwright runtime harvest |
| منع الإصدار بترجمة ناقصة    | ✅ quality-gate مدمج          |
| ترجمة آلية                  | ✅ Python scripts موجودة      |
| تزامن ar/en                 | ✅ آلي                        |
| دعم RTL                     | ✅ ضمني (لغة عربية = RTL)     |
| اللغات المدعومة             | ⚠️ 2 فقط (AR + EN)            |

---

## 6.4 مشاهدات

**الميزة الغائبة:** نظام i18n لا يدعم تعدد اللهجات (مثل `ar-SA` vs `ar-EG`). للنطاق السعودي المحلي: كافٍ.

**Python dependency:** `i18n-fill-ar-from-en.py` يتطلب Python مثبتاً. هذا غير موثَّق في README كمتطلب.

**i18n-runtime-harvest.mjs:** يحتاج browser engine (Playwright؟). لا يمكن تشغيله على server بدون headless browser.

---

## 6.5 خلاصة

نظام i18n في WBGL من أكثر الأجزاء نضجاً ـ يفوق كثيراً من المشاريع المؤسسية. الفجوة الوحيدة: توثيق المتطلبات (Python + Node.js + browser).
