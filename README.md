# WBGL — نظام إدارة دورة حياة خطابات الضمان البنكية

[![PHP Version](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-blue.svg)](https://postgresql.org)
[![License](https://img.shields.io/badge/license-Private-red.svg)]()
[![Version](https://img.shields.io/badge/version-1.3.0-green.svg)]()
[![Status](https://img.shields.io/badge/status-Active-success.svg)]()

---

## 📋 نظرة عامة

**WBGL** (Bank Guarantee Lifecycle Management) نظام مؤسسي متكامل لإدارة **دورة حياة خطابات الضمان البنكية** — من استيراد البيانات من Excel حتى الإصدار والإفراج والأرشفة.

**المكوّنات الجوهرية:**

- **Backend:** PHP 8.3+ Vanilla — بدون framework
- **Database:** PostgreSQL مع migration system كامل + SHA-256 checksums
- **Frontend:** Vanilla JS + CSS Design System — بدون React/Vue
- **i18n:** عربية + إنجليزية مع quality gate يمنع الإصدار عند وجود ترجمات ناقصة

---

## ✨ الميزات الكاملة

### دورة حياة الضمانات

- **استيراد من Excel** — دفعات كاملة مع كشف الحقول تلقائياً
- **مسار العمل (Workflow):** `draft → approved → signed → released`
- **إفراج (Release)** — مع تسجيل السبب والصلاحية المقيَّدة
- **تمديد (Extension)** — تعديل تاريخ الانتهاء مع إنشاء خطاب
- **تخفيض (Reduction)** — تعديل المبلغ مع إنشاء خطاب
- **إعادة الفتح (Reopen)** — للأدوار المصرح لها فقط

### التعلم والمطابقة الذكية

- **UnifiedLearningAuthority** — المرجع الوحيد لاقتراحات الموردين
- **FuzzySignalFeeder** — مطابقة نصية مرجحة
- **AnchorSignalFeeder** — تعلم من القرارات المؤكدة السابقة
- **HistoricalSignalFeeder** — تعلم من سجلات الموردين
- **ConfidenceCalculatorV2** — حساب درجة الثقة الكلية
- **BankNormalizer** — تطبيع أسماء البنوك عبر `bank_alternative_names`

### الحوكمة والتدقيق

- **Timeline Ledger هجين** — أحداث في DB + SHA-256 anchors (غير قابل للتزوير)
- **UndoRequest** — نظام التراجع بـ 4 أطراف: submit → approve → reject → execute
- **Break Glass** — تجاوز طارئ يُسجَّل فوراً في Audit Log
- **Audit Trail** — كل 401/403 مُسجَّل مع Request-ID فريد

### المستخدمون والصلاحيات

- **3 طبقات:** Role → User Override (allow/deny/auto) → `Guard::has()`
- **7 أدوار:** developer, data_entry, data_auditor, analyst, supervisor, approver, signatory
- **PolicySurface** — تحدد ما يظهر في الواجهة لكل سجل بناءً على حالته ودور المستخدم
- **granular overrides** لكل مستخدم على كل صلاحية منفردة

### الإشعارات والواجهة

- **Notification Stack** — عرض الإشعارات بتأثير stack متراكب
- **Polling** كل 90 ثانية + auto-dismiss
- **Preview مباشر** للخطاب يتحدث في DOM بدون reload
- **وضع الإنتاج (Production Mode)** — يخفي بيانات الاختبار بزر واحد

---

## 🏗️ الهيكل التقني

```
WBGL/
├── api/                          # 63 API endpoint
│   ├── _bootstrap.php            # Auth + CSRF + PolicySurface + AuditTrail
│   ├── upload-attachment.php     # MIME+extension validation + random filename
│   ├── save-and-next.php
│   ├── extend.php / release.php / reduce.php / reopen.php
│   └── users/ roles/ ...
│
├── app/
│   ├── Repositories/             # 14 Repository (PDO + prepared statements)
│   ├── Services/
│   │   ├── Learning/             # UnifiedLearningAuthority, FuzzySignalFeeder ...
│   │   ├── WorkflowService.php
│   │   ├── UndoRequestService.php
│   │   ├── NotificationService.php
│   │   ├── LetterBuilderService.php
│   │   └── GuaranteeMutationPolicyService.php
│   └── Support/
│       ├── Guard.php             # نظام الصلاحيات
│       ├── AuthService.php       # Session + API Token
│       ├── Database.php          # PDO Singleton
│       ├── CsrfGuard.php
│       └── SecurityHeaders.php
│
├── database/
│   └── migrations/*.sql          # Versioned schema migrations
│
├── public/
│   ├── css/                      # Design system
│   ├── js/
│   │   ├── security.js           # Auto-injects CSRF on every fetch()
│   │   ├── i18n.js               # Lazy namespace loading + Intl
│   │   ├── records.controller.js # Main UI controller (1214 lines)
│   │   ├── users-management.js   # Users & Roles manager (994 lines)
│   │   └── timeline.controller.js
│   └── locales/ar/ en/           # Translation JSON files
│
├── scripts/                      # 29 admin scripts
│   ├── migrate.php               # Migration runner (schema_migrations + SHA-256)
│   ├── release-gate.php          # CI gate
│   ├── stability-freeze-gate.php
│   ├── i18n-harvest.php          # Extract translation keys
│   └── fill-missing-translations.py
│
├── storage/
│   ├── settings.json             # System settings
│   ├── settings.local.json       # Local secrets (.gitignored)
│   └── attachments/              # Uploaded files (outside public/ — secure)
│
├── tests/
│   ├── Unit/                     # Wiring + Security + Schema tests
│   └── Integration/
│       └── EnterpriseApiFlowsTest.php
│
├── views/                        # 10 PHP pages
│   ├── login.php
│   ├── settings.php              # System settings (114KB)
│   └── statistics.php
│
├── partials/                     # 14 reusable components
│   ├── record-form.php
│   ├── timeline-section.php
│   └── letter-template.php       # Single template for all letter types
│
├── Docs/                         # Internal documentation
│   ├── WBGL-OPERATIONS-RUNBOOK-AR.md
│   ├── WBGL-DR-DRILL-PROCEDURE-AR.md
│   └── LeatestAudit/             # 19 comprehensive audit reports
│
├── index.php                     # Main entry point (2749 lines)
├── server.php                    # PHP built-in server router
├── wbgl_server.ps1               # Server manager
└── toggle.bat                    # One-click start/stop
```

---

## 🔐 نموذج الأمان

| الطبقة           | التفاصيل                                                                        |
| ---------------- | ------------------------------------------------------------------------------- |
| Authentication   | PHP Session + API Token (header)                                                |
| Authorization    | Role → User Override → `Guard::has()`                                           |
| CSRF             | Server: `CsrfGuard` + Client: `security.js` يُعيد تعريف `window.fetch` تلقائياً |
| XSS              | `htmlspecialchars()` شامل في PHP + `escapeHtml()` في JS                         |
| File Upload      | Extension + MIME dual validation + Random filename + `storage/` خارج public     |
| Passwords        | `password_hash()` bcrypt                                                        |
| Security Headers | CSP, HSTS, X-Frame-Options, X-Content-Type, CORP, COOP                          |
| DB SSL           | `sslmode=require` افتراضياً + حارس إنتاجي يمنع أوضاع TLS الضعيفة على المضيفات غير المحلية |

---

## 🚀 التشغيل

### المتطلبات

| الأداة     | الإصدار                       |
| ---------- | ----------------------------- |
| PHP        | 8.3+                          |
| PostgreSQL | 15+                           |
| Node.js    | (لأدوات i18n)                 |
| Python 3   | (للترجمة التلقائية — اختياري) |

### التشغيل السريع

```powershell
# تشغيل/إيقاف بزر واحد
./toggle.bat

# أو عبر PowerShell
./wbgl_server.ps1 -Action start -Port 8181
./wbgl_server.ps1 -Action stop
./wbgl_server.ps1 -Action restart -OpenBrowser
./wbgl_server.ps1 -Action toggle

# مباشرة
php -S localhost:8181 server.php
```

ثم: `http://localhost:8181`

### إعداد قاعدة البيانات

```bash
# تطبيق جميع migrations
php scripts/migrate.php

# معاينة فقط (بدون تطبيق)
php scripts/migrate.php --dry-run

# حالة migrations المطبَّقة
php scripts/migrate.php --status
```

> Migration يحتفظ بجدول `schema_migrations` مع SHA-256 checksum — يمنع التطبيق المزدوج.

### إعداد الإعدادات

```json
// storage/settings.json
{
    "DB_HOST": "localhost",
    "DB_PORT": "5432",
    "DB_NAME": "wbgl",
    "DB_USER": "postgres",
    "PRODUCTION_MODE": false
}
```

```json
// storage/settings.local.json  ← في .gitignore
{
    "DB_PASSWORD": "your_secret_password"
}
```

---

## 🧪 الاختبارات

```bash
# Wiring + Security + Schema tests
vendor/bin/phpunit --testsuite Unit

# Integration API flows
vendor/bin/phpunit --testsuite Integration

# E2E Playwright (يتطلب server شغّال)
npm install
npx playwright install chromium
npm run test:e2e
```

**أنواع الاختبارات المضمَّنة:**

| الاختبار                                     | ما يتحقق منه                   |
| -------------------------------------------- | ------------------------------ |
| `SecurityBaselineWiringTest`                 | Security headers مُطبَّقة      |
| `WorkflowTransitionGuardMigrationWiringTest` | DB triggers موجودة             |
| `SchemaDriftCriticalEndpointsWiringTest`     | Schema لم ينحرف                |
| `PermissionCriticalEndpointContractTest`     | Permissions على endpoints حرجة |
| `ReleaseGateWiringTest`                      | Release gate يعمل              |
| `EnterpriseApiFlowsTest`                     | تدفقات API متكاملة             |

---

## 🔧 CI Gates

```bash
# يمنع الإصدار إذا وجدت مشاكل حرجة
php scripts/release-gate.php

# Stability Freeze
php scripts/stability-freeze-gate.php

# i18n Quality Gate — يمنع الإصدار عند ترجمات ناقصة
php scripts/i18n-harvest.php --check
```

---

## 🌐 نظام الترجمة (i18n)

```bash
# استخراج مفاتيح جديدة من PHP + JS
php scripts/i18n-harvest.php

# ملء المفاتيح الناقصة بالعربية تلقائياً
python scripts/fill-missing-translations.py

# مزامنة اللغة الإنجليزية
php scripts/sync-locale-en.php
```

مفاتيح غير مترجمة تُعلَّم بـ `__TODO_AR__` — يمنع CI الإصدار حتى تكتمل.

---

## ⚠️ ملاحظات تشغيلية حرجة

1. **`sslmode`** في `app/Support/Database.php` يجب تغييره من `prefer` إلى `require` في الإنتاج
2. **Backup** — لا يوجد `pg_dump` job تلقائي — يجب إضافته قبل النشر
3. **`Guard::hasOrLegacy(default=true)`** في `Guard.php` — راجعه قبل إضافة صلاحيات جديدة
4. **`public/uploads/`** — يجب منع تنفيذ PHP فيه (مرفقات المستخدمين تُحفَظ في `storage/attachments/`)
5. **`index.php`** (2749 سطر) + **`settings.php`** (114KB) — ملفات ضخمة تحتاج مراقبة

---

## 📁 الوثائق الداخلية

| الملف                                                  | الوصف                                      |
| ------------------------------------------------------ | ------------------------------------------ |
| `Docs/WBGL-OPERATIONS-RUNBOOK-AR.md`                   | دليل العمليات اليومية                      |
| `Docs/WBGL-DR-DRILL-PROCEDURE-AR.md`                   | إجراءات الاسترداد بعد الكوارث              |
| `Docs/WBGL-MASTER-REMEDIATION-ROADMAP-AR.md`           | خارطة طريق الإصلاح (52KB)                  |
| `Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md` | خطة إعادة هيكلة سير العمل (107KB)          |
| `Docs/LeatestAudit/README.md`                          | فهرس وخلاصة تنفيذية لـ 19 تقرير تدقيق شامل |

---

## 📝 الترخيص

هذا المشروع خاص ومملوك. جميع الحقوق محفوظة.

---

**Made with ❤️ in Saudi Arabia**
