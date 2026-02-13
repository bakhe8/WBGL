# خطة إعادة هيكلة WBGL — النسخة الموسعة للمطورين

> [!IMPORTANT]
> هذه الخطة مبنية على [التدقيق السابق](file:///c:/Users/Bakheet/.gemini/antigravity/brain/6a0e4419-8541-4295-92a8-e028e6d13ed3/audit_investigation.md) الذي أكد 18 مشكلة حقيقية في النظام. كل خطوة مكتوبة بتفاصيل كافية ليقوم بها أي مطور PHP.

## المتطلبات الأساسية

| المتطلب | التفاصيل |
|---------|---------|
| PHP | 8.1+ مع إضافات `pdo_sqlite`, `mbstring` |
| Composer | مثبت عالمياً أو عبر `composer.bat` الموجود |
| المشروع | `c:\Users\Bakheet\Documents\WBGL` |
| قاعدة البيانات | SQLite في `storage/database/app.sqlite` |
| التشغيل | `php -S localhost:8089 server.php` |

---

## Phase 1: Security Foundation (أولوية قصوى)

### 1.1 حماية مجلد Storage

**المشكلة**: [server.php](file:///c:/Users/Bakheet/Documents/WBGL/server.php) (34 سطر) لا يمنع الوصول لـ `storage/` — أي شخص يمكنه تحميل `app.sqlite` مباشرة.

**الملف**: `server.php` — السطر 6 (بعد تعريف `$uri`)

**التعديل**: أضف الكود التالي **بين السطر 7 والسطر 9** (بعد `$file = __DIR__ . $uri;` وقبل `// Serve static files directly`):

```php
// === SECURITY: Block access to sensitive directories ===
$blockedPrefixes = ['/storage/', '/app/', '/.env', '/.git', '/.vscode'];
foreach ($blockedPrefixes as $blocked) {
    if (str_starts_with($uri, $blocked)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}
```

**اختبار**: شغّل السيرفر ثم افتح `http://localhost:8089/storage/database/app.sqlite` — يجب أن يظهر **403 Forbidden** بدل تحميل الملف.

---

### 1.2 حماية CSRF

**المشكلة**: لا توجد حماية CSRF في أي من 36 ملف API — أي موقع خارجي يمكنه إرسال POST requests.

#### الخطوة أ: إنشاء ملف Middleware جديد

**أنشئ** `app/Middleware/CsrfMiddleware.php` (ملف جديد):

```php
<?php
declare(strict_types=1);
namespace App\Middleware;

class CsrfMiddleware
{
    /**
     * يولّد Token عشوائي ويخزنه في الجلسة.
     * إذا كان موجوداً مسبقاً لا يُولَّد من جديد.
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * يتحقق من صحة الـ Token المرسل مع الطلب.
     * يبحث في: 1) حقل POST "_token"  2) Header "X-CSRF-TOKEN"
     */
    public static function verify(): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $sent = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($stored)) return false;
        return hash_equals($stored, $sent);
    }

    /** يُنتج حقل HTML مخفي لإضافته داخل الفورم */
    public static function field(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}
```

#### الخطوة ب: تعديل ملفات API (36 ملف)

كل ملف في مجلد `api/` يجب أن يبدأ بفحص CSRF. **أضف هذا الكود في أول كل ملف بعد `<?php`**:

```php
session_start();
require_once __DIR__ . '/../app/Support/autoload.php';
// CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!\App\Middleware\CsrfMiddleware::verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
        exit;
    }
}
```

**القائمة الكاملة لملفات API** (في `api/`):

| # | الملف | نوع الطلب | ملاحظة |
|---|-------|-----------|--------|
| 1 | `save-and-next.php` | POST | **أهم ملف** — 19KB |
| 2 | `import.php` | POST | رفع Excel |
| 3 | `extend.php` | POST | تمديد ضمان |
| 4 | `release.php` | POST | إفراج |
| 5 | `reduce.php` | POST | تخفيض |
| 6-36 | باقي 30 ملف | POST/GET | تطبيق نفس النمط |

> [!TIP]
> ملفات GET فقط (مثل `get-record.php`, `get-timeline.php`) لا تحتاج CSRF لكن يُفضل إضافة الفحص للتوحيد.

#### الخطوة ج: تعديل الواجهات (Forms)

كل فورم في `index.php` و `partials/*.php` يحتاج حقل CSRF. **أضف بعد كل `<form`**:

```php
<?= \App\Middleware\CsrfMiddleware::field() ?>
```

#### الخطوة د: تعديل JavaScript لإرسال Token

في [records.controller.js](file:///c:/Users/Bakheet/Documents/WBGL/public/js/records.controller.js) (41KB) — كل `fetch()` يرسل POST يحتاج Header:

```javascript
// أضف في أعلى الملف
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

// في كل fetch POST، أضف في headers:
headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': getCsrfToken()
}
```

وفي `<head>` في `index.php` أضف:

```html
<meta name="csrf-token" content="<?= \App\Middleware\CsrfMiddleware::generateToken() ?>">
```

**اختبار**: افتح DevTools > Network > أرسل POST بدون token → يجب 403.

---

### 1.3 مصادقة بسيطة (اختياري)

**أنشئ** `app/Middleware/AuthMiddleware.php`:

```php
<?php
declare(strict_types=1);
namespace App\Middleware;

class AuthMiddleware
{
    public static function check(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if ($_SESSION['authenticated'] ?? false) return;

        $settings = \App\Support\Settings::getInstance();
        $password = $settings->get('APP_PASSWORD', '');

        // لا يوجد كلمة مرور = وصول مفتوح (توافقية عكسية)
        if (empty($password)) return;

        // إعادة التوجيه لصفحة الدخول
        header('Location: /views/login.php');
        exit;
    }
}
```

**أنشئ** `views/login.php` — صفحة تسجيل دخول بسيطة بنفس Design System.

**التفعيل**: أضف `APP_PASSWORD` في صفحة الإعدادات → النظام يطلب كلمة مرور تلقائياً.

---

### 1.4 استبدال die() بـ Exceptions

| الملف | السطر | الكود الحالي | البديل |
|-------|-------|-------------|--------|
| [Database.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Support/Database.php) | 43 | `die('Database Connection Error')` | `throw new \RuntimeException(...)` |
| [batch-print.php](file:///c:/Users/Bakheet/Documents/WBGL/views/batch-print.php) | 3 مواضع | `die('...')` | `header('Location: /?error=...')` |

---

## Phase 2: Backend Architecture

### 2.1 Router بسيط

**أنشئ** `app/Core/Router.php` — يستقبل المسار ويوجه للـ handler المناسب. انظر الكود في [الخطة الأصلية](file:///c:/Users/Bakheet/.gemini/antigravity/brain/6a0e4419-8541-4295-92a8-e028e6d13ed3/implementation_plan.md#L132-L151).

### 2.2 استخراج Controllers من index.php

**المشكلة**: [index.php](file:///c:/Users/Bakheet/Documents/WBGL/index.php) = **1059 سطر** (49KB). يحتوي كل شيء: PHP logic + HTML + inline CSS.

**التقسيم**:

| Controller جديد | المسؤولية | الأسطر المنقولة من index.php |
|----------------|-----------|---------------------------|
| `app/Controllers/GuaranteeController.php` | عرض ضمان + تنقل | أسطر 1-374 (PHP logic) |
| `app/Controllers/ImportController.php` | استيراد Excel | يستدعي `ImportService` |
| `app/Controllers/LetterController.php` | معاينة/إنتاج الخطاب | يستدعي `LetterBuilder` |

**بعد التقسيم**: `index.php` يتقلص لـ ~50 سطر (router + HTML template فقط).

### 2.3 إزالة `global $db`

**الملف**: [StatusEvaluator.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/StatusEvaluator.php) السطر 50

```diff
- global $db;
+ $db = \App\Support\Database::connect();
```

### 2.4 Validation مركزية

**أنشئ** `app/Validators/GuaranteeValidator.php` — انظر الكود في [الخطة الأصلية](file:///c:/Users/Bakheet/.gemini/antigravity/brain/6a0e4419-8541-4295-92a8-e028e6d13ed3/implementation_plan.md#L205-L226).

### 2.5 Database Transactions

**الملف**: [save-and-next.php](file:///c:/Users/Bakheet/Documents/WBGL/api/save-and-next.php) (19KB) — أكبر ملف API، يحفظ قرار + timeline + learning بدون transaction.

**التعديل**: لف العمليات الثلاث في `beginTransaction()` / `commit()` / `rollBack()`.

### 2.6 نظام Migrations

**أنشئ** `app/Support/MigrationRunner.php` + ملفات SQL في `storage/migrations/`. يتم استخراج `CREATE TABLE` من الكود الحالي في Repositories.

### 2.7 حذف الكود الميت

> [!CAUTION]
> **لا تحذف `FieldExtractionService.php`** — التدقيق صنفه يتيماً بالخطأ، لكنه مستخدم في 9 مواقع من `ParseCoordinatorService.php`.

| ملف للحذف | السبب |
|----------|-------|
| `app/Models/ImportSession.php` | 6 أسطر، لا يُستدعى من أي مكان |
| `app/Services/AutoAcceptService.php` | 4.7KB، مهجور بالكامل |
| `app/Services/RecordHydratorService.php` | 3.9KB، لا يوجد استدعاء خارجي |
| `ImportService::previewExcel()` | دالة ميتة داخل الملف |
| `ImportService::validateImportData()` | دالة ميتة داخل الملف |

---

## Phase 3: Error Handling

### 3.1 نظام Exceptions

**أنشئ** `app/Exceptions/AppException.php` + **أنشئ** `views/error.php` (صفحة خطأ عربية بالـ Design System).

### 3.2 إصلاح ابتلاع الأخطاء

**الملف**: [import.php](file:///c:/Users/Bakheet/Documents/WBGL/api/import.php) السطر 116. الـ `catch` فارغ ويبتلع أخطاء الأتمتة.

```diff
- catch (\Throwable $e) { /* Ignore automation errors */ }
+ catch (\Throwable $e) {
+     error_log('Automation error: ' . $e->getMessage());
+     $warnings[] = 'تنبيه: فشلت المعالجة التلقائية';
+ }
```

### 3.3 حماية نظام التعلم

**أنشئ** `app/Services/Learning/LearningGuard.php` — فلتر يمنع التسجيل إذا الثقة < 50%، ويُنقص وزن القرارات القديمة.

---

## Phase 4: Frontend Refactoring

### 4.1 تقسيم CSS

**المشكلة**: [index-main.css](file:///c:/Users/Bakheet/Documents/WBGL/public/css/index-main.css) = **42KB** ملف واحد ضخم.

**التقسيم إلى ملفات في `public/css/`**:

| الملف الجديد | المحتوى |
|-------------|---------|
| `sidebar.css` | أنماط `.sidebar`, `.input-toolbar`, `.progress-*` |
| `record-form.css` | أنماط `.decision-card`, `.record-header` |
| `timeline.css` | أنماط `.timeline-*` |
| `preview.css` | أنماط المعاينة |
| `modals.css` | أنماط النوافذ المنبثقة |
| `index-main.css` | يبقى: الهيكل العام + المتغيرات |

**بعد التقسيم**: أضف `<link>` tags في `<head>` في `index.php`.

### 4.2 نقل Inline Styles → CSS Classes

**المشكلة**: `index.php` يحتوي ~40 موضع `style="..."`. مثال (سطر 563):

```diff
- <div style="display: flex; align-items: center; gap: 12px; background: #fef3c7;
-     border: 2px solid #f59e0b; border-radius: 8px; padding: 14px 18px; ...">
+ <div class="test-data-banner">
```

### 4.3 إزالة بقايا Alpine.js

**المشكلة**: `index.php` أسطر 753-757 تستخدم `:style`, `x-text` بدون تحميل Alpine.js.

```diff
- <div class="progress-fill" :style="`width: ${progress}%`"></div>
+ <div class="progress-fill" id="progress-fill"
+      style="width: <?= $totalRecords > 0 ? round(($currentIndex/$totalRecords)*100) : 0 ?>%">
+ </div>
```

### 4.4 تقسيم JavaScript

**المشكلة**: [records.controller.js](file:///c:/Users/Bakheet/Documents/WBGL/public/js/records.controller.js) = **41KB**.

| الملف الجديد (في `public/js/`) | الدوال |
|-------------------------------|--------|
| `navigation.js` | `previousRecord`, `nextRecord`, `loadRecord` |
| `supplier.js` | `selectSupplier`, `processSupplierInput` |
| `actions.js` | `extend`, `release`, `reduce`, `saveAndNext` |
| `preview.js` | `updatePreviewFromDOM`, `togglePreview` |
| `dialogs.js` | `customConfirm`, `customPrompt` |

### 4.5 Inline Event Handlers → CSS + addEventListener

**المشكلة**: أسطر 676-698 في `index.php` — فلاتر بـ `onmouseover`/`onmouseout`.

```diff
- <a onmouseover="..." onmouseout="...">
+ <a class="filter-link <?= $statusFilter === 'all' ? 'active' : '' ?>">
```

### 4.6 Loading States + Dark Mode

أنشئ `public/css/loading.css` (skeleton animations) + أضف `@media (prefers-color-scheme: dark)` في [design-system.css](file:///c:/Users/Bakheet/Documents/WBGL/public/css/design-system.css).

---

## Phase 5: Testing

**الوضع الحالي**: لا توجد اختبارات للمشروع — `phpunit.xml` موجود ومُعد لـ `tests/Unit` و `tests/Integration` لكن المجلدات فارغة.

| ملف الاختبار | ما يختبره |
|-------------|----------|
| `tests/Unit/Services/Learning/ConfidenceCalculatorV2Test.php` | حساب الثقة |
| `tests/Unit/Support/ArabicNormalizerTest.php` | توحيد النصوص العربية |
| `tests/Unit/Support/BankNormalizerTest.php` | تطبيع أسماء البنوك |
| `tests/Unit/Validators/GuaranteeValidatorTest.php` | التحقق من بيانات الضمان |
| `tests/Integration/Api/ImportApiTest.php` | استيراد Excel كاملاً |

**التشغيل**: `.\vendor\bin\phpunit` من جذر المشروع.

---

## Phase 6: Storage Optimization

تغيير `letter_snapshot` في `TimelineRecorder.php` من HTML كامل (5-10KB/حدث) إلى JSON (200 bytes/حدث). التعديل في `TimelineDisplayService.php` لتوليد HTML عند الطلب.

---

## Verification Plan

### اختبارات آلية (بعد Phase 5)

```powershell
cd c:\Users\Bakheet\Documents\WBGL
.\vendor\bin\phpunit                    # كل الاختبارات
.\vendor\bin\phpunit --testsuite Unit   # الوحدات فقط
```

### اختبارات يدوية بعد كل Phase

#### Phase 1 — Security

1. شغّل: `php -S localhost:8089 server.php`
2. افتح: `http://localhost:8089/storage/database/app.sqlite` → **يجب 403**
3. في DevTools Console: `fetch('/api/save-and-next.php', {method:'POST'})` → **يجب 403 + CSRF error**
4. أضف `APP_PASSWORD=test123` في الإعدادات → **يجب ظهور صفحة Login**

#### Phase 2 — Backend

1. الصفحة الرئيسية `http://localhost:8089/` تعمل كالسابق
2. استيراد Excel → نفس النتيجة
3. حفظ قرار (Save & Next) → ينجح
4. إصدار خطاب → نفس النتيجة

#### Phase 4 — Frontend

1. الواجهة مطابقة بصرياً للحالية
2. التنقل بين الضمانات يعمل
3. التصفية (جاهز/معلق/مُفرج) تعمل
4. Dark Mode → الواجهة تتحول تلقائياً

> [!WARNING]
> **قبل كل Phase**: انسخ `storage/database/app.sqlite` احتياطياً.

---

## ملخص الجهد

| المرحلة | ملفات جديدة | ملفات معدلة | الجهد |
|---------|------------|------------|-------|
| Phase 1: Security | 3 | ~40 | يوم |
| Phase 2: Backend | 10 | 5 | 3-4 أيام |
| Phase 3: Errors | 3 | 3 | يوم |
| Phase 4: Frontend | 8 | 15 | 3-4 أيام |
| Phase 5: Testing | 6 | 0 | 2-3 أيام |
| Phase 6: Storage | 0 | 2 | نصف يوم |
| **الإجمالي** | **30** | **~65** | **~2 أسبوع** |
