# تقرير 05 — Views وPartials (واجهات الصفحات)

## WBGL Views & Partials — Full Coverage Report

> **الملفات:** `views/` (10 ملفات) + `partials/` (14 ملفاً)
> **التاريخ:** 2026-03-07

---

## 5.1 جرد Views

| الملف                 | الحجم     | الوظيفة                |
| --------------------- | --------- | ---------------------- |
| `settings.php`        | 114.5KB   | صفحة الإعدادات الكاملة |
| `statistics.php`      | 65.4KB    | لوحة الإحصاءات         |
| `maintenance.php`     | 26.5KB    | صفحة الصيانة           |
| `users.php`           | 32.6KB    | إدارة المستخدمين       |
| `batch-detail.php`    | 36.5KB    | تفاصيل الدفعة          |
| `batches.php`         | 18.9KB    | قائمة الدفعات          |
| `batch-print.php`     | 15.9KB    | طباعة الدفعات          |
| `login.php`           | 11.4KB    | صفحة الدخول            |
| `confidence-demo.php` | 9.8KB     | عرض نظام الثقة         |
| `edit_guarantee.php`  | 190 bytes | redirect بسيط          |

---

## 5.2 ملاحظات على الحجم

- **`settings.php` = 114.5KB** — ثاني أكبر ملف بعد `index.php` (118KB). صفحة واحدة بهذا الحجم = كمية هائلة من HTML+PHP+JavaScript مدمجة.
- **`edit_guarantee.php` = 190 bytes** — على الأرجح redirect فقط. احتمال أن يكون stub أو قديماً.
- **`confidence-demo.php`** — وجود صفحة "عرض تجريبي" في الإنتاج يُشير لإمكانية وصول لها.

---

## 5.3 مشاهدات هيكلية

**مشكلة الضخامة:**
ثلاثة ملفات (settings.php + statistics.php + index.php) تجاوزت 60KB. أي bug أو regression:

- صعوبة تتبعه بدون معرفة عميقة بالملف
- Git diff = آلاف الأسطور

**تقييم XSS:**
بدون قراءة كاملة لكل ملف، لا يمكن التأكيد. لكن:

- `settings.php` يعرض بيانات المستخدمين وقيم الإعدادات
- `statistics.php` يعرض أسماء الموردين والبنوك
- `maintenance.php` يعرض نتائج فحوصات البيانات

إذا كانت أي من هذه البيانات تُعرَض بـ `echo $var` بدون `htmlspecialchars()` → XSS محتمل.

---

## 5.4 Partials — 14 ملفاً

`partials/` يحتوي مكوّنات UI مكررة (header, footer, nav, modals, etc).

الحجم الكلي (14 ملف) منطقي لـ shared components. وجود partials يعني النظام يُقسّم الـ UI إلى مكونات — جيد للصيانة.

---

## 5.5 `confidence-demo.php` — تقييم الخطر

وجود صفحة "demo" في view layer تعني:

- قد تكون متاحة للمستخدمين العاديين
- تكشف خوارزمية الثقة وطريقة عملها
- قد تُستخدَم لـ fingerprinting النظام

**التوصية:** التأكد من حمايتها بـ permission مناسبة أو إزالتها من الإنتاج.

---

## 5.6 خلاصة

| المعيار                   | التقييم                           |
| ------------------------- | --------------------------------- |
| ملف views ضخمة (>60KB)    | ⚠️ مشكلة صيانة                    |
| `edit_guarantee.php` stub | ⚠️ يحتاج تحقق — قد يكون dead code |
| XSS في templates          | ⚠️ غير مؤكد بدون فحص شامل         |
| Partial components        | ✅ هيكل جيد                       |
| Demo page في الإنتاج      | ⚠️ يحتاج permission guard         |
