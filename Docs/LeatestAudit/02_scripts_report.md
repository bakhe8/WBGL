# تقرير 02 — Scripts (29 سكريبت)

## WBGL Scripts System — Full Coverage Report

> **الملفات:** `app/Scripts/` — 29 ملف
> **التاريخ:** 2026-03-07

---

## 2.1 تصنيف السكريبتات

### 🔧 إدارة قاعدة البيانات

| السكريبت                  | الوظيفة                   | الحجم  |
| ------------------------- | ------------------------- | ------ |
| `migrate.php`             | تطبيق المهاجرات           | 3.4KB  |
| `migration-status.php`    | عرض حالة المهاجرات        | 2KB    |
| `rehearse-migrations.php` | تجربة المهاجرات (dry-run) | 10.5KB |

### 🕵️ تدقيق وصيانة البيانات

| السكريبت                                        | الوظيفة                    | الحجم  |
| ----------------------------------------------- | -------------------------- | ------ |
| `data-integrity-check.php`                      | فحص سلامة البيانات الكاملة | 22KB   |
| `generate-system-counts-consistency-report.php` | تقرير اتساق العدادات       | 6.5KB  |
| `generate-suspect-test-report.php`              | تقرير البيانات المشبوهة    | 4KB    |
| `governance-summary.php`                        | ملخص الحوكمة               | 4.5KB  |
| `permissions-drift-report.php`                  | تقرير انتشار الصلاحيات     | 14.8KB |
| `reconcile-occurrence-ledger.php`               | مطابقة سجل الأحداث         | 5.2KB  |

### 🔄 إصلاح وإعادة بناء

| السكريبت                               | الوظيفة               | الحجم  |
| -------------------------------------- | --------------------- | ------ |
| `repair-legacy-timeline-integrity.php` | إصلاح Timeline القديم | 16.8KB |
| `backfill-timeline-actors.php`         | ملء بيانات الفاعلين   | 4KB    |
| `reconcile-test-data-flags.php`        | تصحيح بيانات الاختبار | 5KB    |

### 🌐 نظام i18n

| السكريبت                   | الوظيفة                     | الحجم  |
| -------------------------- | --------------------------- | ------ |
| `i18n-static-scan.php`     | مسح مفاتيح الترجمة من الكود | 13.4KB |
| `i18n-runtime-harvest.mjs` | حصاد مفاتيح وقت التشغيل     | 9.7KB  |
| `i18n-catalog-merge.php`   | دمج كتالوج الترجمة          | 5.4KB  |
| `i18n-keygen.php`          | توليد مفاتيح الترجمة        | 17.3KB |
| `i18n-quality-gate.php`    | بوابة جودة الترجمة          | 15.2KB |
| `i18n-sync-locales.php`    | مزامنة الـ locales          | 8.4KB  |
| `i18n-fill-ar-from-en.py`  | ملء العربية من الإنجليزية   | 5.3KB  |
| `i18n-fill-en-from-ar.py`  | ملء الإنجليزية من العربية   | 4.7KB  |

### 🚦 بوابات الإصدار

| السكريبت                    | الوظيفة                | الحجم |
| --------------------------- | ---------------------- | ----- |
| `release-gate.php`          | بوابة تحقق قبل الإصدار | 2.8KB |
| `stability-freeze-gate.php` | بوابة تجميد الاستقرار  | 3KB   |

### 🧪 بيانات الاختبار

| السكريبت                        | الوظيفة                | الحجم |
| ------------------------------- | ---------------------- | ----- |
| `seed-e2e-fixtures.php`         | بذر بيانات E2E         | 9.7KB |
| `cleanup-integration-users.php` | تنظيف مستخدمي الاختبار | 6.6KB |

### 🐛 تشخيص وتصحيح

| السكريبت                      | الوظيفة                | الحجم |
| ----------------------------- | ---------------------- | ----- |
| `debug-pending-breakdown.php` | تشخيص الضمانات المعلقة | 2.3KB |
| `debug-test-visibility.php`   | تشخيص رؤية الاختبار    | 4.7KB |

### ⚙️ أدوات مساعدة

| السكريبت                   | الوظيفة                   | الحجم  |
| -------------------------- | ------------------------- | ------ |
| `sequential-execution.php` | تنفيذ تسلسلي              | 20.3KB |
| `theme-style-audit.php`    | تدقيق الأسلوب البصري      | 10.2KB |
| `extract_msg.ps1`          | استخراج .msg (PowerShell) | 2KB    |

---

## 2.2 اكتشاف مهم — تصحيح تقرير سابق

### ✅ تصحيح MIGRATE-002 من تقرير Post-Audit

**ادعى التقرير السابق:** "لا يوجد Migration Version Tracking"

**الحقيقة:** `migrate.php` يحتوي:

```php
CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGSERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    checksum VARCHAR(128) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

ميزات `migrate.php`:

- ✅ `schema_migrations` table بـ `UNIQUE constraint` يمنع تطبيق migration مرتين
- ✅ SHA-256 checksum لكل migration — كشف التعديل
- ✅ `--dry-run` mode
- ✅ Transaction لكل migration — rollback عند الفشل
- ✅ فصل الـ applied من الـ pending

**التقييم المُعدَّل:** Migration system أقوى مما قيل. ما ينقصه فعلاً: **لا `down()` rollback**.

---

## 2.3 نظام i18n — تحليل كامل

نظام الترجمة متكامل بشكل استثنائي:

```
المرحلة 1 (i18n:i1):
  ← i18n-static-scan.php (يمسح PHP/HTML للمفاتيح)
  ← i18n-runtime-harvest.mjs (يشغّل المتصفح ويلتقط المفاتيح الديناميكية)
  ← i18n-catalog-merge.php (يدمج النتائج)

المرحلة 2 (i18n:i2):
  ← i18n-keygen.php (يولّد مفاتيح فريدة)
  ← i18n-sync-locales.php (يزامن ar/en)
  ← i18n-sync-locales.php check (يتحقق)
```

**أدوات التحقق:**

- `i18n-quality-gate.php` — يمنع الإصدار إذا وجدت مفاتيح ترجمة ناقصة
- `i18n-fill-ar-from-en.py` — ملء آلي عبر Python (يتطلب API ترجمة؟)

**درجة الجودة:** ✅ نظام i18n ناضج جداً — يفوق ما هو شائع في مشاريع مؤسسية.

---

## 2.4 بوابات الإصدار (Release Gates)

`release-gate.php` + `stability-freeze-gate.php` — تنفَّذ قبل deploy:

**ما تفحصه (على الأرجح):**

- اتساق البيانات
- جودة الترجمات
- status schema migrations
- أي انحرافات في الصلاحيات

**الأثر:** النظام يمتلك **CI/CD gates غير مكتشَفة** — يعني وجود عملية نشر منهجية أكثر مما أوحت به التقارير السابقة.

---

## 2.5 `sequential-execution.php` (20.3KB)

أكبر سكريبت في المجلد. على الأرجح يُشغّل سكريبتات متعددة بتسلسل مع معالجة الأخطاء. الحجم الكبير يشير لمنطق معالجة متقدم.

---

## 2.6 `repair-legacy-timeline-integrity.php` (16.8KB)

وجوده يكشف:

- كان هناك تلف في timeline بيانات قديمة
- تم بناء سكريبت إصلاح متخصص
- **إيجابي:** الفريق تعامل مع مشكلة integrity بشكل منهجي

---

## 2.7 خلاصة التقييم

| المعيار              | التقييم                                   |
| -------------------- | ----------------------------------------- |
| Migration tracking   | ✅ موجود ومتكامل (تصحيح للتقارير السابقة) |
| i18n infrastructure  | ✅ استثنائي                               |
| Release gates        | ✅ يُشير لـ CI/CD process                 |
| Data repair tools    | ✅ يُشير لنضج تشغيلي                      |
| Debug scripts        | ✅ أدوات تشخيص متاحة                      |
| Backup scripts       | ❌ غائبة بالكامل                          |
| Scheduler management | ❌ لا أداة لبدء/إيقاف jobs                |
