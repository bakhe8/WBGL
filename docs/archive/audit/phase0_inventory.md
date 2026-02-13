# المرحلة صفر - جرد شامل لنظام WBGL

## التاريخ: 2026-02-11

---

## 🔍 ملخص تنفيذي

**WBGL** (Bank Guarantee Letters v3.0) هو نظام إدارة ضمانات بنكية مطوّر بـ PHP 8.3+ بدون إطار عمل (Vanilla PHP)، يستخدم SQLite كقاعدة بيانات، ويقدم ميزات ذكاء اصطناعي للمطابقة التلقائية.

---

## 📋 جدول شامل: مكونات النظام

| الفئة | العدد | الملفات/المكونات | الملاحظات |
|------|------|------------------|-----------|
| **📝 الملفات الرئيسية** | 3 | `index.php`, `server.php`, `README.md` | Entry Points |
| **🔌 نقاط API** | 36 | `/api/*.php` | REST-like endpoints |
| **📦 Models** | 9 | `/app/Models/*.php` | Entities (Guarantee, Bank, Supplier, etc.) |
| **💾 Repositories** | 13 | `/app/Repositories/*.php` | Data access layer |
| **⚙️ Services** | 26+ | `/app/Services/*.php` | Business logic |
| **🛠️ Support/Helpers** | 15 | `/app/Support/*.php` | Utilities (Database, Config, Normalizers) |
| **🖼️ Views** | 8 | `/views/*.php` | Full page templates |
| **🧩 Partials** | 12 | `/partials/*.php` | Reusable components |
| **🎨 CSS Files** | 6 | `/public/css/*.css` | Design system components |
| **⚡ JavaScript** | 8 | `/public/js/*.js` | Vanilla JS controllers |
| **🗄️ Database** | 1 | `storage/database/app.sqlite` | SQLite 3 |
| **🧪 Tests** | مُعرّف | `phpunit.xml` | PHPUnit Framework |

---

## 🏗️ البيئة التقنية

### متطلبات التشغيل

| المكون | الإصدار المُكتشف | المتطلب الأدنى |
|--------|------------------|----------------|
| **PHP** | 8.3.26 | >= 8.0 |
| **Composer** | 2.8.12 | غير محدد |
| **SQLite** | مُدمج مع PHP | 3.x |
| **Web Server** | PHP Built-in Server | Any |

### الاعتماديات (Composer)

```json
"require": {
    "php": ">=8.0",
    "phpoffice/phpspreadsheet": "^1.29"
},
"require-dev": {
    "phpunit/phpunit": "^12.5"
}
```

**PSR-4 Autoloading:**

```json
"autoload": {
    "psr-4": {
        "App\\": "app/"
    }
}
```

---

## 🚀 نقاط الدخول (Entry Points)

### ① الواجهة الرئيسية (Web)

- **الملف:** `index.php` (49.5 KB - 1059 lines)
- **الوظيفة:** النقطة الرئيسية لتشغيل التطبيق عبر المتصفح
- **النمط:** Server-Side Rendering مع Partials
- **Session Management:** نعم (مُدمج مع PHP)

### ② موجّه السيرفر (Router)

- **الملف:** `server.php` (34 lines)
- **الوظيفة:** توجيه الطلبات للـ PHP Built-in Server
- **Static Files:** Serves CSS, JS, PNG, JPG, GIF, SVG, Maps

### ③ نقاط API (36 endpoint)

- **المسار:** `/api/`

**أبرز نقاط API:**

- `save-and-next.php` (19.7 KB) - حفظ وانتقال تلقائي
- `get-record.php` (16.4 KB) - جلب سجل ضمان
- `import.php` (6.1 KB) - استيراد Excel
- `release.php`, `extend.php`, `reduce.php` - إصدار خطابات
- `get-letter-preview.php` - معاينة الخطابات
- `batches.php` - إدارة الدفعات
- `create-guarantee.php`, `create-supplier.php`, `create-bank.php` - إنشاء
- `update_supplier.php`, `update_bank.php` - تعديل
- `delete_supplier.php`, `delete_bank.php` - حذف
- `export_suppliers.php`, `export_banks.php` - تصدير
- `import_suppliers.php`, `import_banks.php` - استيراد
- `get_suppliers.php`, `get_banks.php` - قوائم
- `suggestions-learning.php` - اقتراحات AI
- `settings.php` - الإعدادات
- `history.php` - السجل التاريخي
- `save-note.php` - الملاحظات
- `upload-attachment.php` - المرفقات

---

## 📂 الهيكل التنظيمي للمشروع

```
WBGL/
├── index.php                 # Entry Point (Web)
├── server.php                # PHP Dev Server Router
├── composer.json             # Dependencies
├── phpunit.xml               # Test Configuration
├── VERSION                   # Version tracking
│
├── app/                      # Core Application Logic
│   ├── Contracts/           # Interfaces (1 file)
│   ├── DTO/                 # Data Transfer Objects (2 files)
│   ├── Models/              # Domain Models (9 files)
│   │   ├── Guarantee.php
│   │   ├── GuaranteeDecision.php
│   │   ├── Supplier.php
│   │   ├── Bank.php
│   │   ├── ImportedRecord.php
│   │   ├── ImportSession.php
│   │   ├── LearningLog.php
│   │   ├── SupplierAlternativeName.php
│   │   └── TrustDecision.php
│   │
│   ├── Repositories/        # Data Access Layer (13 files)
│   │   ├── GuaranteeRepository.php
│   │   ├── GuaranteeDecisionRepository.php
│   │   ├── SupplierRepository.php
│   │   ├── BankRepository.php
│   │   ├── SupplierLearningRepository.php
│   │   ├── SupplierOverrideRepository.php
│   │   ├── SupplierAlternativeNameRepository.php
│   │   ├── LearningRepository.php
│   │   ├── NoteRepository.php
│   │   ├── AttachmentRepository.php
│   │   ├── GuaranteeHistoryRepository.php
│   │   ├── BatchMetadataRepository.php
│   │   └── ImportedRecordRepository.php
│   │
│   ├── Services/            # Business Logic (26+ files)
│   │   ├── Learning/       # AI Matching Subsystem (9 files)
│   │   ├── SmartPaste/     # Smart Paste Features (1 file)
│   │   ├── Suggestions/    # Suggestion Engine (5 files)
│   │   ├── AutoAcceptService.php
│   │   ├── BankManagementService.php
│   │   ├── BatchService.php (30 KB)
│   │   ├── ConflictDetector.php
│   │   ├── DecisionService.php
│   │   ├── ExcelColumnDetector.php
│   │   ├── FieldExtractionService.php (14 KB)
│   │   ├── GuaranteeDataService.php
│   │   ├── ImportService.php (23 KB)
│   │   ├── LetterBuilder.php
│   │   ├── NavigationService.php
│   │   ├── ParseCoordinatorService.php (24 KB)
│   │   ├── PreviewFormatter.php
│   │   ├── RecordHydratorService.php
│   │   ├── SmartProcessingService.php (25 KB)
│   │   ├── StatsService.php
│   │   ├── StatusEvaluator.php
│   │   ├── SupplierCandidateService.php
│   │   ├── SupplierManagementService.php
│   │   ├── TableDetectionService.php (14 KB)
│   │   ├── TimelineDisplayService.php
│   │   ├── TimelineRecorder.php (27 KB)
│   │   └── ValidationService.php
│   │
│   └── Support/             # Utilities & Helpers (15 files)
│       ├── autoload.php     # PSR-4 Autoloader
│       ├── Database.php     # PDO SQLite Wrapper
│       ├── Config.php       # Configuration Constants
│       ├── Settings.php     # Dynamic Settings
│       ├── DateTime.php     # Date/Time utilities
│       ├── Normalizer.php   # Text normalization
│       ├── ArabicNormalizer.php
│       ├── BankNormalizer.php
│       ├── TypeNormalizer.php
│       ├── SimilarityCalculator.php
│       ├── ScoringConfig.php
│       ├── Input.php        # Request handling
│       ├── Logger.php       # Logging utility
│       ├── mb_levenshtein.php
│       └── SimpleXlsxReader.php
│
├── api/                     # API Endpoints (36 files)
│
├── views/                   # Page Templates (8 files)
│   ├── index.php            # Main view (handled by index.php root)
│   ├── batches.php
│   ├── batch-detail.php
│   ├── batch-print.php
│   ├── statistics.php
│   ├── settings.php
│   ├── maintenance.php
│   └── confidence-demo.php
│
├── partials/                # Reusable UI Components (12 files)
│   ├── unified-header.php
│   ├── record-form.php
│   ├── timeline-section.php
│   ├── letter-renderer.php
│   ├── confirm-modal.php
│   ├── historical-banner.php
│   ├── preview-placeholder.php
│   ├── excel-import-modal.php
│   ├── manual-entry-modal.php
│   ├── paste-modal.php
│   ├── suggestions.php
│   └── supplier-suggestions.php
│
├── public/                  # Static Assets
│   ├── css/                # Stylesheets (6 files)
│   │   ├── design-system.css
│   │   ├── layout.css
│   │   ├── components.css
│   │   ├── index-main.css (42 KB)
│   │   ├── batch-detail.css
│   │   └── confidence-indicators.css
│   │
│   ├── js/                 # JavaScript (8 files)
│   │   ├── records.controller.js (41 KB)
│   │   ├── input-modals.controller.js (21 KB)
│   │   ├── timeline.controller.js (19 KB)
│   │   ├── preview-formatter.js
│   │   ├── confidence-ui.js
│   │   ├── pilot-auto-load.js
│   │   ├── convert-to-real.js
│   │   └── main.js
│   │
│   └── uploads/            # User uploads (Excel files)
│
├── storage/                # Storage & Data
│   ├── database/
│   │   └── app.sqlite      # Main SQLite Database
│   ├── migrations/         # (Empty - no migration files)
│   └── logs/              # Application logs
│
├── templates/              # Document Templates
│   └── letter-template.php # Letter generation template
│
├── assets/                 # Additional assets
│   └── css/
│       └── letter.css      # Letter styling
│
├── scripts/                # Utility Scripts
│   └── (مجلد فارغ - للنصوص البرمجية المساعدة)
│
├── docs/                   # Documentation (فارغ)
│
├── vendor/                 # Composer Dependencies
│   ├── phpoffice/phpspreadsheet/
│   ├── phpunit/phpunit/
│   ├── autoload.php
│   └── ...
│
├── .git/                   # Git Repository
├── .gitignore
├── .vscode/                # VS Code Settings
│
├── toggle.ps1              # PowerShell: Start/Stop Server
├── toggle.bat              # Batch: Server Toggle
└── composer.bat            # Composer Wrapper
```

---

## 🗄️ قاعدة البيانات

### الموقع والنوع

- **النوع:** SQLite 3
- **المسار:** `storage/database/app.sqlite`
- **الإنشاء:** تلقائي عند أول تشغيل (Auto-create if not exists)
- **Foreign Keys:** مُفعّلة (`PRAGMA foreign_keys = ON`)

### الجداول المُكتشفة (من التحليل)

| الجدول | الوظيفة | ملاحظات |
|--------|---------|---------|
| `guarantees` | الضمانات البنكية | الجدول الرئيسي |
| `guarantee_decisions` | قرارات المطابقة | العلاقات والقرارات AI |
| `suppliers` | الموردين | قاعدة بيانات الموردين |
| `banks` | البنوك | قاعدة بيانات البنوك |
| `supplier_alternative_names` | الأسماء البديلة للموردين | نظام التعلم |
| `bank_alternative_names` | الأسماء البديلة للبنوك | نظام المطابقة |
| `supplier_learning` | سجل التعلم | AI Learning logs |
| `supplier_overrides` | التجاوزات اليدوية | Manual overrides |
| `guarantee_timeline` | السجل التاريخي | Audit trail |
| `guarantee_history` | تاريخ الضمانات | Historical snapshots |
| `notes` | الملاحظات | User notes |
| `attachments` | المرفقات | File attachments |
| `batch_metadata` | معلومات الدفعات | Batch tracking |
| `import_sessions` | جلسات الاستيراد | Import tracking |

**ملاحظة:** لم يتم العثور على ملفات migration - الجداول تُنشأ ديناميكياً من الـ Repositories أو عبر initialization script غير مُوثّق.

---

## ⚙️ أنماط التشغيل (Execution Modes)

### ✅ وضع الويب (Web Mode) - **المُكتشف والنشط**

- **Entry Point:** `index.php` + `server.php`
- **التشغيل:**

  ```bash
  php -S localhost:8089 server.php
  # أو
  .\toggle.ps1  # PowerShell Script
  ```

- **الوظيفة:** الواجهة الرئيسية للتطبيق

### ❌ لا يوجد CLI Mode

- لم يتم العثور على سكريبتات CLI مخصصة
- لم يتم العثور على Console Commands

### ❌ لا يوجد Cron Jobs

- لم يتم العثور على ملفات crontab أو scheduled tasks

### ❌ لا يوجد Background Workers / Queue

- لا توجد أنظمة queue مُكتشفة
- لا توجد background processors

### ❌ لا يوجد Daemon Mode

- التطبيق يعمل فقط كـ web application

**الخلاصة:** WBGL هو تطبيق ويب محض (Pure Web Application) بدون أنماط تشغيل خلفية.

---

## 🔐 ملفات الإعدادات (Configuration Files)

| الملف | الوصف | الحالة |
|------|-------|--------|
| `composer.json` | Composer dependencies | ✅ موجود |
| `phpunit.xml` | PHPUnit configuration | ✅ موجود |
| `app/Support/Config.php` | Static configuration constants | ✅ موجود |
| `app/Support/Settings.php` | Dynamic settings (DB-driven) | ✅ موجود |
| `.env` | Environment variables | ❌ غير موجود |
| `.gitignore` | Git ignore rules | ✅ موجود |

**ملاحظة هامة:** لا توجد ملفات `.env` - الإعدادات يتم تحميلها من قاعدة البيانات عبر `Settings` class.

---

## 🔌 الخدمات الخارجية (External Services)

### ✅ المُكتشفة

- **Google Fonts API:** `https://fonts.googleapis.com/css2?family=Tajawal`
  - Purpose: Arabic font (Tajawal)
  - Usage: In `index.php` and views

### ❌ غير مُكتشفة

- لا توجد اتصالات بـ APIs خارجية
- لا توجد اتصالات بـ payment gateways
- لا توجد اتصالات بـ email services (SMTP)
- لا توجد اتصالات بـ cloud storage
- لا توجد اتصالات بـ third-party AI services

**النمط العام:** التطبيق مُستقل ذاتياً (Self-contained) مع اعتمادية وحيدة على Google Fonts.

---

## 🧪 منظومة الاختبار (Test Suite)

### الإطار المُستخدم

- **PHPUnit:** ^12.5

### تكوين PHPUnit (`phpunit.xml`)

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>tests/Integration</directory>
    </testsuite>
    <testsuite name="Learning Authority">
        <directory>tests/Unit/Services/Learning</directory>
        <directory>tests/Integration/Services/Learning</directory>
    </testsuite>
</testsuites>
```

### حالة الاختبارات

- **الحالة:** غير مؤكد (ملفات الاختبار غير مُكتشفة في المسح الأولي)
- **Test Database:** `:memory:` SQLite (from phpunit.xml)
- **Environment:** `testing` mode

**توصية للتحقق:** يجب فحص مجلد `tests/` للتأكد من وجود ملفات الاختبار الفعلية.

---

## 📚 التوثيق (Documentation)

### المُكتشف

- **README.md** (190 lines, 5.7 KB)
  - نظرة عامة شاملة
  - تعليمات التثبيت والتشغيل
  - سياسة المساهمة (GitHub workflow)
  - معلومات الإصدارات

### غير المُكتشف

- مجلد `docs/` موجود ولكنه **فارغ**
- لا توجد Wiki محلية
- لا توجد API documentation
- لا توجد Architecture diagrams

**ملاحظة:** README يُشير إلى GitHub Wiki غير موجودة محلياً:

- Architecture Overview
- AI Matching System
- Design System
- API Reference
- Decisions Log

---

## 🛠️ سكريبتات التشغيل (Operational Scripts)

| الملف | النوع | الوظيفة |
|------|------|---------|
| `toggle.ps1` | PowerShell | تشغيل/إيقاف السيرفر |
| `toggle.bat` | Batch | Wrapper للـ PowerShell |
| `composer.bat` | Batch | Composer wrapper |
| `server.php` | PHP | PHP Built-in Server Router |

### وظيفة `toggle.ps1`

- تشغيل السيرفر على `localhost:8089`
- حفظ PID في `server.pid`
- فتح المتصفح تلقائياً
- إيقاف السيرفر عند التشغيل مرة أخرى

---

## 📊 إحصائيات الكود (Lines of Code)

| الفئة | أكبر ملف | حجمه |
|------|----------|------|
| Entry Point | `index.php` | 49.5 KB (1059 lines) |
| Service | `BatchService.php` | 30 KB |
| Service | `TimelineRecorder.php` | 27 KB |
| Service | `SmartProcessingService.php` | 25 KB |
| Service | `ParseCoordinatorService.php` | 24 KB |
| Service | `ImportService.php` | 23 KB |
| API | `save-and-next.php` | 19.7 KB |
| API | `get-record.php` | 16.4 KB |
| Service | `FieldExtractionService.php` | 14 KB |
| Service | `TableDetectionService.php` | 14 KB |
| CSS | `index-main.css` | 42 KB |
| JS | `records.controller.js` | 41 KB |
| JS | `input-modals.controller.js` | 21 KB |
| JS | `timeline.controller.js` | 19 KB |

**ملاحظة:** وجود ملفات كبيرة جداً (>1000 lines, >40KB) قد يُشير إلى مشاكل بنيوية (God Classes/Files).

---

## 🏃 كيف يبدأ WBGL؟ كيف يُشغَّل؟

### التشغيل الأساسي

```bash
# Method 1: Manual
cd WBGL
php -S localhost:8089 server.php

# Method 2: PowerShell Script (Windows)
.\toggle.ps1

# Method 3: Batch wrapper
.\toggle.bat
```

### التدفق

1. **PowerShell/Batch** → يُشغّل `php -S localhost:8089 server.php`
2. **server.php** → يوجه الطلبات:
   - Static files (CSS/JS/Images) → يُرجعها مباشرة
   - All other requests → يُوجهها لـ `index.php`
3. **index.php** →
   - يُحمّل `app/Support/autoload.php`
   - يُنشئ اتصال قاعدة البيانات
   - يُحمّل الـ Repositories والـ Services
   - يُرسم الصفحة بواسطة Partials

### Database Initialization

- من `Database.php`:
  - يتحقق من وجود `storage/database/app.sqlite`
  - إذا لم يكن موجوداً → يُنشئ المجلد والملف
  - يُفعّل `foreign_keys`

---

## 🎯 الخلاصة: نقاط القوة والضعف

### نقاط القوة ✅

1. **No Framework Overhead:** Vanilla PHP = سرعة وتحكم كامل
2. **Self-Contained:** SQLite + No external dependencies = سهولة النشر
3. **PSR-4 Autoloading:** بنية منظمة
4. **Repository Pattern:** فصل واضح بين الـ Data Access والـ Business Logic
5. **Service Layer:** منطق عمل مُنظّم
6. **Vanilla UI:** لا اعتماديات frontend ثقيلة

### نقاط ضعف محتملة ⚠️

1. **ملفات كبيرة جداً:** `index.php` (1059 lines) - مُرشح لـ God Class
2. **لا توجد Migrations:** صعوبة تتبع تطور قاعدة البيانات
3. **لا توجد `.env`:** الإعدادات في قاعدة البيانات قد تُعقّد الـ deployment
4. **لا يوجد Error Handling موحد:** يجب التحقق
5. **لا يوجد Logging موحد:** قد يكون Logger.php غير مُستخدم بشكل كافٍ
6. **لا توجد API Documentation:** صعوبة فهم الـ endpoints بدون توثيق
7. **Tests غير مؤكدة:** phpunit.xml موجود لكن ملفات الاختبار غير واضحة

---

## 📝 فجوات الفهم (Knowledge Gaps)

### المتطلب تحقق أعمق

1. ❓ **Database Schema Complete:** لم نحصل على schema كامل بسبب عدم توفر `sqlite3` CLI
2. ❓ **Test Coverage:** هل توجد ملفات اختبار فعلية في `tests/`?
3. ❓ **Security Measures:** ما هي آليات الحماية المُطبقة؟
4. ❓ **Error Handling Strategy:** هل يوجد نظام موحد؟
5. ❓ **Logging Implementation:** كيف يتم الـ logging فعلياً؟
6. ❓ **Deployment Process:** كيف يتم النشر للـ production؟
7. ❓ **Backup Strategy:** هل يوجد نظام backup لقاعدة البيانات؟
8. ❓ **Multi-user Support:** هل النظام يدعم المستخدمين المتعددين؟

---

## 🔜 الخطوة التالية

**الآن يجب الانتقال إلى PHASE 1 - فهم تشغيلي عميق للنظام**

قبل أي مراجعة أو نقد للكود، يجب فهم:

- ما المشكلة التي يحلها WBGL؟
- ما هي التدفقات العملية الرئيسية؟
- كيف تتدفق البيانات من الإدخال → المعالجة → الإخراج؟
- أين تحدث المنطقية التجارية والتحقق والصلاحيات؟

---

**تم إنجاز PHASE 0 بنجاح ✅**
