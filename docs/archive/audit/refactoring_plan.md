# خطة إعادة هيكلة WBGL الشاملة

خطة مرحلية لتحويل النظام الحالي إلى بنية نظيفة وآمنة وقابلة للصيانة، مع الحفاظ على كل الوظائف الحالية بدون كسر.

> [!IMPORTANT]
> كل مرحلة مستقلة وقابلة للتنفيذ بشكل منفصل. المراحل مرتبة بالأولوية — الأمان أولاً.

---

## Phase 1: Security Foundation (الأولوية القصوى)

تأمين النظام ضد الثغرات المكتشفة في التدقيق.

### 1.1 حماية مجلد Storage

#### [MODIFY] [server.php](file:///c:/Users/Bakheet/Documents/WBGL/server.php)

إضافة حماية لمنع الوصول المباشر لملفات `storage/` (قاعدة البيانات، السجلات، المرفقات):

```diff
 $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
 $file = __DIR__ . $uri;

+// Block access to sensitive directories
+$blocked = ['/storage/', '/app/', '/.env', '/.git'];
+foreach ($blocked as $path) {
+    if (str_starts_with($uri, $path)) {
+        http_response_code(403);
+        echo 'Forbidden';
+        exit;
+    }
+}
+
 // Serve static files directly
```

### 1.2 إضافة حماية CSRF

#### [NEW] [CsrfMiddleware.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Middleware/CsrfMiddleware.php)

```php
class CsrfMiddleware {
    public static function generateToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(): bool {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    public static function field(): string {
        return '<input type="hidden" name="_token" value="' . self::generateToken() . '">';
    }
}
```

#### [MODIFY] جميع ملفات API التي تقبل POST (36 ملف في `api/`)

إضافة التحقق في بداية كل ملف:

```diff
+session_start();
+if ($_SERVER['REQUEST_METHOD'] === 'POST') {
+    if (!\App\Middleware\CsrfMiddleware::verify()) {
+        http_response_code(403);
+        echo json_encode(['error' => 'CSRF token invalid']);
+        exit;
+    }
+}
```

#### [MODIFY] جميع الـ Forms في `partials/` و `index.php`

إضافة حقل CSRF المخفي:

```diff
 <form method="POST" ...>
+    <?= \App\Middleware\CsrfMiddleware::field() ?>
```

### 1.3 مصادقة بسيطة (كلمة مرور واحدة)

#### [NEW] [AuthMiddleware.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Middleware/AuthMiddleware.php)

```php
class AuthMiddleware {
    public static function check(): void {
        session_start();
        if ($_SESSION['authenticated'] ?? false) return;
        
        // Check if password is set in settings
        $settings = \App\Support\Settings::getInstance();
        $password = $settings->get('APP_PASSWORD', '');
        
        if (empty($password)) return; // No password = open access (backward compatible)
        
        header('Location: /views/login.php');
        exit;
    }
}
```

#### [NEW] [login.php](file:///c:/Users/Bakheet/Documents/WBGL/views/login.php)

صفحة تسجيل دخول بسيطة بنفس Design System.

### 1.4 استبدال die() بـ Exceptions

#### [MODIFY] [Database.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Support/Database.php)

```diff
-    die('Database Connection Error');
+    throw new \RuntimeException('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage());
```

#### [MODIFY] [batch-print.php](file:///c:/Users/Bakheet/Documents/WBGL/views/batch-print.php)

استبدال 3 مواضع `die()` بـ redirect لصفحة خطأ.

---

## Phase 2: Backend Architecture

إعادة تنظيم الكود الخلفي لفصل المسؤوليات.

### 2.1 إنشاء Router بسيط

#### [NEW] [Router.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Core/Router.php)

```php
class Router {
    private array $routes = [];

    public function get(string $path, callable $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void {
        $handler = $this->routes[$method][$uri] ?? null;
        if ($handler) {
            $handler();
        } else {
            http_response_code(404);
            require __DIR__ . '/../../views/error.php';
        }
    }
}
```

### 2.2 استخراج Controllers من index.php

تقسيم `index.php` (1058 سطر) إلى:

#### [NEW] [GuaranteeController.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Controllers/GuaranteeController.php)

- `show()` — عرض ضمان واحد (الأسطر 57-374 من index.php الحالي)
- `navigate()` — التنقل بين السجلات (الأسطر 78-96)

#### [NEW] [ImportController.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Controllers/ImportController.php)

- `upload()` — يستقبل ملف Excel ويستدعي ImportService

#### [NEW] [LetterController.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Controllers/LetterController.php)

- `preview()` — معاينة الخطاب
- `generate()` — إنتاج الخطاب

#### [MODIFY] [index.php](file:///c:/Users/Bakheet/Documents/WBGL/index.php)

يتقلص من 1058 سطر إلى ~50 سطر:

```php
<?php
require_once __DIR__ . '/app/Support/autoload.php';

$router = new \App\Core\Router();

$router->get('/', [GuaranteeController::class, 'show']);
$router->get('/batches', fn() => require 'views/batches.php');
$router->get('/statistics', fn() => require 'views/statistics.php');
$router->get('/settings', fn() => require 'views/settings.php');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$router->dispatch($method, $uri);
```

### 2.3 إزالة global $db

#### [MODIFY] [StatusEvaluator.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/StatusEvaluator.php)

```diff
  public static function evaluateFromDatabase(int $guaranteeId): array
  {
-     global $db;
+     $db = \App\Support\Database::connect();
```

### 2.4 طبقة Validation مركزية

#### [NEW] [GuaranteeValidator.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Validators/GuaranteeValidator.php)

```php
class GuaranteeValidator {
    public static function forSave(array $data): array {
        $errors = [];
        if (empty($data['guarantee_id'])) $errors[] = 'رقم الضمان مطلوب';
        if (!empty($data['amount']) && !is_numeric($data['amount']))
            $errors[] = 'المبلغ يجب أن يكون رقمياً';
        if (!empty($data['expiry_date']) && !strtotime($data['expiry_date']))
            $errors[] = 'تاريخ الصلاحية غير صالح';
        return $errors;
    }

    public static function forImport(array $row): array {
        $errors = [];
        if (empty($row['guarantee_number'])) $errors[] = 'رقم الضمان مفقود';
        if (empty($row['supplier'])) $errors[] = 'اسم المورد مفقود';
        return $errors;
    }
}
```

### 2.5 إضافة Transactions للعمليات الحرجة

#### [MODIFY] [save-and-next.php](file:///c:/Users/Bakheet/Documents/WBGL/api/save-and-next.php)

```diff
+$db->beginTransaction();
+try {
     $decisionRepo->save($data);
     $timelineRecorder->record($event);
     $learningRepo->logConfirm($data);
+    $db->commit();
+} catch (\Throwable $e) {
+    $db->rollBack();
+    http_response_code(500);
+    echo json_encode(['error' => 'فشل الحفظ: ' . $e->getMessage()]);
+    exit;
+}
```

### 2.6 نظام Migrations

#### [NEW] [MigrationRunner.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Support/MigrationRunner.php)

```php
class MigrationRunner {
    public function run(): void {
        $db = Database::connect();
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY,
            filename TEXT UNIQUE,
            ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $files = glob(storage_path('migrations/*.sql'));
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $exists = $db->prepare('SELECT 1 FROM migrations WHERE filename = ?');
            $exists->execute([$name]);
            if (!$exists->fetch()) {
                $db->exec(file_get_contents($file));
                $db->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
            }
        }
    }
}
```

#### [NEW] ملفات Migration في `storage/migrations/`

```
storage/migrations/
├── 001_create_guarantees.sql
├── 002_create_suppliers.sql
├── 003_create_banks.sql
├── 004_create_decisions.sql
├── 005_create_timeline.sql
├── 006_create_learning.sql
└── 007_create_notes_attachments.sql
```

> [!NOTE]
> سيتم استخراج CREATE TABLE من الكود الحالي في Repositories إلى ملفات SQL مستقلة.

### 2.7 حذف الكود الميت

#### [DELETE] [ImportSession.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Models/ImportSession.php)

#### [DELETE] [AutoAcceptService.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/AutoAcceptService.php)

#### [DELETE] [RecordHydratorService.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/RecordHydratorService.php)

وأيضاً حذف الدوال الميتة من `ImportService.php`:

- `previewExcel()` (سطر 527)
- `validateImportData()` (سطر 501)

---

## Phase 3: Error Handling & Reliability

### 3.1 نظام Exceptions

#### [NEW] [AppException.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Exceptions/AppException.php)

```php
class AppException extends \RuntimeException {
    public static function notFound(string $entity): self {
        return new self("لم يتم العثور على {$entity}", 404);
    }
    public static function validation(string $message): self {
        return new self($message, 422);
    }
    public static function database(string $message): self {
        return new self("خطأ في قاعدة البيانات: {$message}", 500);
    }
}
```

#### [NEW] [error.php](file:///c:/Users/Bakheet/Documents/WBGL/views/error.php)

صفحة خطأ عربية بنفس الـ Design System بدل شاشة بيضاء.

### 3.2 إصلاح ابتلاع الأخطاء

#### [MODIFY] [import.php](file:///c:/Users/Bakheet/Documents/WBGL/api/import.php)

```diff
-catch (\Throwable $e) {
-    /* Ignore automation errors, keep import success */
-}
+catch (\Throwable $e) {
+    error_log('Automation error during import: ' . $e->getMessage());
+    $warnings[] = 'تنبيه: فشلت المعالجة التلقائية — يمكنك المطابقة يدوياً';
+}
```

### 3.3 حماية نظام التعلم

#### [NEW] [LearningGuard.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/Learning/LearningGuard.php)

```php
class LearningGuard {
    // لا تسجل التعلم إذا الثقة أقل من 50%
    public static function shouldLearn(int $confidence, string $source): bool {
        if ($confidence < 50 && $source === 'auto') return false;
        return true;
    }

    // تقليل وزن القرارات القديمة (أقدم من 6 أشهر)
    public static function decayFactor(string $date): float {
        $months = (time() - strtotime($date)) / (30 * 86400);
        if ($months < 6) return 1.0;
        if ($months < 12) return 0.7;
        return 0.4;
    }
}
```

---

## Phase 4: Frontend Refactoring

### 4.1 تقسيم CSS

#### [MODIFY] [index-main.css](file:///c:/Users/Bakheet/Documents/WBGL/public/css/index-main.css) → تقسيم إلى

| الملف الجديد | المحتوى | الحجم التقريبي |
|-------------|---------|---------------|
| [NEW] `sidebar.css` | أنماط الشريط الجانبي | ~8 KB |
| [NEW] `record-form.css` | نموذج بيانات الضمان | ~10 KB |
| [NEW] `timeline.css` | لوحة التاريخ الزمني | ~8 KB |
| [NEW] `preview.css` | معاينة الخطاب | ~5 KB |
| [NEW] `modals.css` | النوافذ المنبثقة | ~6 KB |
| [MODIFY] `index-main.css` | الهيكل العام فقط | ~5 KB |

#### [MODIFY] [index.php](file:///c:/Users/Bakheet/Documents/WBGL/index.php) — تعديل الـ `<head>`

```diff
  <link rel="stylesheet" href="public/css/index-main.css">
+ <link rel="stylesheet" href="public/css/sidebar.css">
+ <link rel="stylesheet" href="public/css/record-form.css">
+ <link rel="stylesheet" href="public/css/timeline.css">
+ <link rel="stylesheet" href="public/css/preview.css">
+ <link rel="stylesheet" href="public/css/modals.css">
```

### 4.2 نقل Inline Styles إلى CSS

#### [MODIFY] `index.php` + `partials/*.php`

مسح جميع `style="..."` واستبدالها بـ CSS classes. مثال:

```diff
-<div style="display: flex; align-items: center; gap: 12px; background: #fef3c7; 
-    border: 2px solid #f59e0b; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
+<div class="test-data-banner">
```

```css
/* في record-form.css */
.test-data-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--accent-warning-light);
    border: 2px solid var(--accent-warning);
    border-radius: var(--radius-md);
    padding: var(--space-md) var(--space-lg);
    margin-bottom: var(--space-md);
}
```

### 4.3 إزالة بقايا Alpine.js الميتة

#### [MODIFY] [index.php](file:///c:/Users/Bakheet/Documents/WBGL/index.php)

```diff
-<div class="progress-fill" :style="`width: ${progress}%`"></div>
+<div class="progress-fill" id="progress-fill"></div>

-<span x-text="currentIndex"></span>
+<span id="currentIndex"><?= $currentIndex ?></span>

-<span class="progress-percent" x-text="`${progress}%`"></span>
+<span class="progress-percent" id="progress-percent">
+    <?= $totalRecords > 0 ? round(($currentIndex / $totalRecords) * 100) : 0 ?>%
+</span>
```

### 4.4 تقسيم JavaScript

#### [MODIFY] [records.controller.js](file:///c:/Users/Bakheet/Documents/WBGL/public/js/records.controller.js) → تقسيم إلى

| الملف الجديد | المحتوى | الدوال |
|-------------|---------|--------|
| [NEW] `navigation.js` | التنقل بين السجلات | `previousRecord`, `nextRecord`, `loadRecord` |
| [NEW] `supplier.js` | منطق المورد | `selectSupplier`, `processSupplierInput`, `createSupplier` |
| [NEW] `actions.js` | الإجراءات | `extend`, `release`, `reduce`, `saveAndNext` |
| [NEW] `preview.js` | معاينة الخطاب | `updatePreviewFromDOM`, `togglePreview`, `print` |
| [NEW] `dialogs.js` | حوارات مخصصة | `customConfirm`, `customPrompt` |
| [MODIFY] `records.controller.js` | التهيئة + الربط فقط | `init`, `bindEvents`, `constructor` |

### 4.5 استبدال Inline Event Handlers

```diff
-<a onmouseover="if('...' !== 'all') this.style.background='#f1f5f9'"
-   onmouseout="if('...' !== 'all') this.style.background='transparent'">
+<a class="filter-link <?= $statusFilter === 'all' ? 'active' : '' ?>">
```

```css
.filter-link:not(.active):hover {
    background: var(--bg-hover);
}
.filter-link.active {
    background: var(--accent-primary-light);
    font-weight: var(--font-weight-semibold);
}
```

### 4.6 Loading States

#### [NEW] [loading.css](file:///c:/Users/Bakheet/Documents/WBGL/public/css/loading.css)

```css
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
    border-radius: var(--radius-sm);
}

@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
```

### 4.7 Dark Mode

#### [MODIFY] [design-system.css](file:///c:/Users/Bakheet/Documents/WBGL/public/css/design-system.css)

```css
@media (prefers-color-scheme: dark) {
    :root {
        --bg-body: #0f172a;
        --bg-card: #1e293b;
        --bg-secondary: #1e293b;
        --text-primary: #f1f5f9;
        --text-secondary: #cbd5e1;
        --text-muted: #94a3b8;
        --border-primary: #334155;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
}
```

---

## Phase 5: Testing

### 5.1 هيكل الاختبارات

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── ImportServiceTest.php
│   │   ├── LetterBuilderTest.php
│   │   └── Learning/
│   │       └── ConfidenceCalculatorV2Test.php
│   ├── Support/
│   │   ├── ArabicNormalizerTest.php
│   │   └── BankNormalizerTest.php
│   └── Validators/
│       └── GuaranteeValidatorTest.php
└── Integration/
    └── Api/
        └── ImportApiTest.php
```

### 5.2 أمثلة اختبارات

#### [NEW] [ConfidenceCalculatorV2Test.php](file:///c:/Users/Bakheet/Documents/WBGL/tests/Unit/Services/Learning/ConfidenceCalculatorV2Test.php)

```php
class ConfidenceCalculatorV2Test extends TestCase {
    public function test_empty_signals_returns_zero(): void {
        $calc = new ConfidenceCalculatorV2();
        $this->assertEquals(0, $calc->calculate([]));
    }

    public function test_alias_exact_gives_100(): void {
        $signal = new SignalDTO();
        $signal->signal_type = 'alias_exact';
        $signal->raw_strength = 1.0;
        $calc = new ConfidenceCalculatorV2();
        $this->assertEquals(100, $calc->calculate([$signal]));
    }

    public function test_rejection_reduces_confidence(): void {
        $signal = new SignalDTO();
        $signal->signal_type = 'alias_exact';
        $signal->raw_strength = 1.0;
        $calc = new ConfidenceCalculatorV2();
        $withRejection = $calc->calculate([$signal], 0, 2);
        $this->assertLessThan(100, $withRejection);
    }
}
```

---

## Phase 6: Storage Optimization

### 6.1 تغيير letter_snapshot من HTML إلى JSON

#### [MODIFY] [TimelineRecorder.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/TimelineRecorder.php)

```diff
-$letterHtml = LetterBuilder::render($data);
-// Store full HTML (5-10 KB per event)
+$letterJson = json_encode([
+    'action' => $action,
+    'supplier_id' => $data['supplier_id'],
+    'bank_id' => $data['bank_id'],
+    'amount' => $data['amount'],
+    'expiry_date' => $data['expiry_date'],
+]);
+// Store JSON only (200 bytes per event)
```

#### [MODIFY] [TimelineDisplayService.php](file:///c:/Users/Bakheet/Documents/WBGL/app/Services/TimelineDisplayService.php)

إضافة on-demand rendering: عند طلب عرض الخطاب، يُولّد HTML من JSON.

---

## Verification Plan

### Automated Tests

```powershell
# تشغيل جميع الاختبارات (بعد Phase 5)
cd c:\Users\Bakheet\Documents\WBGL
.\vendor\bin\phpunit

# تشغيل اختبار محدد
.\vendor\bin\phpunit tests/Unit/Services/Learning/ConfidenceCalculatorV2Test.php

# تشغيل مجموعة اختبارات
.\vendor\bin\phpunit --testsuite Unit
```

### Manual Verification (بعد كل Phase)

#### Phase 1 — Security

1. شغّل `php -S localhost:8089 server.php`
2. جرب الوصول لـ `http://localhost:8089/storage/database/app.sqlite` ← يجب أن يعطي **403 Forbidden**
3. جرب إرسال POST بدون CSRF token ← يجب أن يُرفض
4. أضف `APP_PASSWORD` في الإعدادات ← يجب أن تظهر صفحة Login

#### Phase 2 — Backend

1. تحقق أن الصفحة الرئيسية تعمل كالسابق (`http://localhost:8089/`)
2. جرب استيراد ملف Excel ← يجب أن يعمل بنفس الطريقة
3. جرب حفظ قرار (Save & Next) ← يجب أن ينجح
4. جرب إصدار خطاب ← يجب أن ينتج نفس النتيجة

#### Phase 4 — Frontend

1. تحقق أن الواجهة تبدو مطابقة للحالي (لا تغيير بصري)
2. جرب التنقل بين الضمانات
3. جرب التصفية (جاهز / معلق / مُفرج)
4. if Dark Mode OS enabled → الواجهة تتحول تلقائياً

> [!WARNING]
> **قبل بداية أي Phase**: يجب أخذ نسخة احتياطية من `storage/database/app.sqlite` وملفات المشروع.

---

## ملخص الجهد المتوقع

| المرحلة | الملفات الجديدة | الملفات المعدلة | الجهد التقديري |
|---------|----------------|----------------|---------------|
| Phase 1: Security | 3 | ~40 | يوم واحد |
| Phase 2: Backend | 10 | 5 | 3-4 أيام |
| Phase 3: Error Handling | 3 | 3 | يوم واحد |
| Phase 4: Frontend | 8 | 15 | 3-4 أيام |
| Phase 5: Testing | 6 | 0 | 2-3 أيام |
| Phase 6: Storage | 0 | 2 | نصف يوم |
| **الإجمالي** | **30** | **~65** | **~2 أسبوع** |
