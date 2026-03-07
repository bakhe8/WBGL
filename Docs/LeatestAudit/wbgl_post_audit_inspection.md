# تقرير التدقيق العميق من المرحلة التالية

## Post-Audit Deep Inspection — WBGL

### المجالات غير المُغطَّاة في التقارير الخمسة السابقة

> **المهمة:** تحليل حصري للمجالات التي أغفلتها التقارير السابقة.
> **التاريخ:** 2026-03-07

---

## القسم الأول — تقييم تغطية المجالات

| المجال                   | مستوى التغطية السابقة          | هذا التقرير                     |
| ------------------------ | ------------------------------ | ------------------------------- |
| أمن البنية التحتية       | لم يُغطَّ                      | ✅ مُحلَّل هنا                  |
| أمن المتصفح              | ذُكر جزئياً (Security headers) | ✅ تعمّق جديد                   |
| إدارة الأسرار            | ذُكر settings.json فقط         | ✅ تحليل شامل                   |
| النسخ الاحتياطي والتعافي | ذُكر الغياب                    | ✅ تعمّق في الأثر               |
| سلامة الترحيلات          | ذُكر schema drift              | ✅ تحليل Migration system مباشر |
| دورة حياة البيانات       | لم يُغطَّ                      | ✅ مُحلَّل هنا                  |
| قابلية الاختبار          | لم يُغطَّ                      | ✅ مُحلَّل هنا                  |
| قابلية الصيانة           | ذُكر index.php                 | ✅ تعمّق هيكلي                  |
| تصميم تجربة المستخدم     | ذُكر في Human Behavior         | ✅ تعمّق نظام التصميم           |

---

## القسم الثاني — أمن البنية التحتية

---

### 🔴 INFRA-001 — النظام يعمل بـ PHP Built-in Server كبيئة "إنتاج"

**الدليل:** `wbgl_server.ps1` السطر 196

```powershell
$process = Start-Process -FilePath $phpPath `
    -ArgumentList "-S localhost:$TargetPort server.php" `
    -WorkingDirectory $projectPath ...
```

**المشكلة:**
`php -S` (PHP built-in development server) مُصمَّم **حصراً للتطوير**. وثائق PHP الرسمية تنص صراحةً: _"This web server is designed to aid application development. It may also be useful for testing purposes. As it is not designed to be a full-featured web server, it should not be used on a public network."_

**آثار استخدامه في بيئة إنتاج:**

- يعالج طلباً واحداً في كل مرة (single-threaded)
- لا دعم لـ TLS/SSL مباشر → HTTPS مستحيل بدون reverse proxy
- يتوقف عند أي PHP fatal error دون إعادة تشغيل
- `WBGL_8181.vbs` (`Start-Process wscript.exe`) لفتح المتصفح تلقائياً → يؤكد استخدام بيئة Windows محلية
- لا يدعم HTTP/2، لا gzip، لا static file caching headers صحيحة

**التساؤل الحرج:** هل هذا فعلاً بيئة إنتاج أم تطوير/اختبار؟ **الكود يُظهر عدم وجود أي خيار آخر** — لا nginx config، لا Apache config، لا Docker، لا CI/CD deployment.

---

### 🔴 INFRA-002 — لا حماية لمجلد `storage/` من الوصول المباشر

**الدليل:** `server.php` السطور 18-38

```php
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    // Serve static files directly
    readfile($file);
    exit;
}
```

**المشكلة الحرجة:**
`server.php` يُتحقق من وجود الملف ثم يُرسله مباشرةً. لا يوجد access control. طلب:

```
GET /storage/settings.json HTTP/1.1
```

→ يُرجع `settings.json` كاملاً بما فيه `DB_PASS`، `DB_USER` للمهاجم.

**الملفات المُعرَّضة المحتملة:**

- `/storage/settings.json` — credentials
- `/storage/logs/data-integrity-report.json` — معلومات النظام الداخلية
- `/storage/attachments/*` — مُرفقات تتجاوز صلاحيات المستخدم
- `/storage/uploads/*.xlsx` — ملفات Excel الخام

**ملاحظة:** `.gitignore` يستثني `storage/logs/*` ومحتويات `uploads/` و`attachments/*` — لكن `settings.json` **غير مستثنى** في `.gitignore`.

---

### 🔴 INFRA-003 — `settings.json` محتمل دخوله في Git history

**الدليل:** `.gitignore` السطور 1-36

```
/storage/settings.local.json  ← مُستثنى ✅
```

لكن `/storage/settings.json` **غير موجود** في `.gitignore`.

**المشكلة:**
`settings.json` هو مخزن `DB_PASS`, `DB_USER`, وكل الإعدادات الحساسة. إذا كان في Git history (حتى لو حُذف لاحقاً من الشجرة):

```bash
git log --all -- storage/settings.json
git show <commit-hash>:storage/settings.json
```

→ يُظهر credentials القديمة لأي شخص يمتلك نسخة من الـ repository.

---

### 🟡 INFRA-004 — `public/` يحتوي 56 ملفاً مُتاحاً مباشرة

**الدليل:** الدليل `public/` بـ 56 ملفاً

`server.php` يُرسل أي ملف في `public/` مباشرةً. بما في ذلك ملفات `.js` التي قد تحتوي:

- Debug information
- API endpoint URLs
- Version strings

لا content integrity (SRI hashes) على الـ JS files الخارجية.

---

### 🟡 INFRA-005 — لا حد لحجم رفع الملفات (File Upload Limit)

**البحث في الكود:** لا يوجد `MAX_FILE_SIZE` أو `upload_max_filesize` check في الكود.

PHP افتراضي `upload_max_filesize = 2M`. لكن Excel بـ 10,000 صف = 5-20MB. إذا لم يُعدَّل `php.ini` → رفع الملف يفشل بصمت (PHP يُرجع `UPLOAD_ERR_INI_SIZE`) والمستخدم يرى رسالة محيرة.

---

## القسم الثالث — أمن المتصفح

---

### 🔴 BROWSER-001 — CSP مع `unsafe-inline` يُلغي الحماية من XSS

**الملف:** `app/Support/SecurityHeaders.php` السطر 34

```php
"script-src 'self' 'unsafe-inline'",
"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
```

**المشكلة:**
`'unsafe-inline'` في `script-src` يعني: أي `<script>` مُضمَّن في HTML + أي `onclick=` + أي `javascript:` URL يُنفَّذ بدون قيود. وجود **Content-Security-Policy مع `unsafe-inline` يُساوي عدم وجوده من منظور XSS**.

**الأثر:** إذا كان أي حقل في الواجهة يُعرض بيانات المستخدم بدون escape (مثل `locked_reason`, `batch_name`, أسماء الموردين) → مهاجم يُدخل `<script>alert(1)</script>` → يُنفَّذ.

**ملاحظة:** `index.php` يعرض بيانات ضمان مباشرة (118KB HTML/PHP). دون مراجعة كل نقطة عرض — XSS risk موجود.

---

### 🟡 BROWSER-002 — HSTS مشروط بـ Secure Cookies فقط

**الملف:** `app/Support/SecurityHeaders.php` السطر 24-26

```php
if (SessionSecurity::shouldUseSecureCookies()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
```

`shouldUseSecureCookies()` يعتمد على اكتشاف HTTPS. في بيئة PHP built-in server (HTTP فقط) → HSTS لا يُرسَل أبداً. إذا كان المستخدم سبق وأكمل طلب HTTP ثم انتقل لـ HTTPS → المتصفح لا يُجبَر على HTTPS.

---

### 🟡 BROWSER-003 — لا `nonce` في CSP script-src

الحماية الحديثة من XSS تعتمد على `nonce` أو `hash` لكل `<script>`. النظام يستخدم `'unsafe-inline'` بدلاً منهما. حتى بدون XSS injection — أي مكتبة إعلانات أو tracking script يُحقنها وسيط (man-in-the-middle) ستُنفَّذ.

---

### 🟡 BROWSER-004 — `SecurityHeaders::apply()` — هل يُستدعى على كل الاستجابات؟

**البحث في الكود:** لم يظهر في `_bootstrap.php` استدعاء صريح لـ `SecurityHeaders::apply()` في الاستجابات العادية (HTTP API). يُحتمل أنه يُستدعى من `index.php` فقط للواجهة.

**الخطر:** API endpoints المكشوفة في `api/*.php` قد لا تُرسل Security Headers، مما يُعرّض API clients لـ content sniffing و framing attacks.

---

## القسم الرابع — إدارة الأسرار

---

### 🔴 SECRET-001 — لا آلية لتدوير الأسرار (Secrets Rotation)

**الوضع الحالي:**
`DB_PASS` مُخزَّن في `settings.json` كـ plaintext. لا يوجد:

- آلية لتغيير كلمة المرور وتحديثها تلقائياً
- TTL للـ credentials
- دعم لـ environment-based secrets injection (مثل AWS Secrets Manager, HashiCorp Vault)

**الأثر:** إذا تسرَّبت كلمة المرور (عبر Git history أو `/storage/settings.json` exposure) — لا إجراء تلقائي لتحديدها. يدوياً بالكامل.

---

### 🔴 SECRET-002 — API Tokens مُخزَّنة في DB بدون تشفير (محتمل)

**الملف:** `app/Services/ApiTokenService.php`

**الدليل (من التحليل المسبق):** System يدعم API tokens للمصادقة. لم يُراجَع كيفية تخزينها في DB.

**الخطر:** إذا كانت الـ tokens مُخزَّنة كـ plaintext في `api_tokens` table:

- SQL dump = تسريب كل tokens
- لا يمكن التمييز بين tokens صالحة ومُعاد استخدامها

**الممارسة الصحيحة:** تخزين hash(token) وليس الـ token نفسه.

---

### 🟡 SECRET-003 — `debug_exception.txt` و`debug_import_error.txt` خارج `.gitignore`

**.gitignore السطران 21-22:**

```
/debug_exception.txt
/debug_import_error.txt
```

هذان الملفان **مستثنيان** — لكنهما موجودان في الكود مُشاراً إليهما كمخرج للـ exceptions. هذا يؤكد أن ملفات debug تُكتب على الديسك. هل تحتوي على stack traces؟ هل تُعرض لأي HTTP client؟ — لم يُفحص.

---

### 🟡 SECRET-004 — `phpunit.xml` يُضبط `WBGL_DB_DRIVER` لكن لا `WBGL_DB_PASS`

```xml
<env name="APP_ENV" value="testing"/>
<env name="WBGL_DB_DRIVER" value="pgsql"/>
```

لا يوجد `WBGL_DB_PASS` أو `WBGL_DB_HOST` في phpunit.xml. هذا يعني:

- الاختبارات تقرأ من `settings.json` الحقيقي
- **الاختبارات تستخدم نفس DB الإنتاج** إذا لم يُعدَّل `settings.json` يدوياً
- لا test isolation

---

## القسم الخامس — النسخ الاحتياطي والتعافي من الكوارث

---

### 🔴 DR-001 — لا توجد استراتيجية backup على الإطلاق

**الدليل:** `.gitignore` السطر 12: `/storage/database/backups/*` مُستثنى (المجلد موجود لكن فارغ).

لا يوجد في كامل المشروع:

- سكريبت `pg_dump` — ولا واحد
- WAL archiving configuration
- Cron job للنسخ الاحتياطي
- وثيقة Recovery runbook
- حتى README section عن Backup

**الأثر المحسوب:** فقدان قاعدة البيانات = فقدان كل السجل الجنائي (`guarantee_history`) الذي يُعتبر أثمن ما في النظام.

---

### 🔴 DR-002 — `guarantee_history` الـ Hybrid Ledger غير قابل للاستعادة بدون DB كاملة

**التصميم:** كل حدث يحتوي `patch_data` (RFC 6902 JSON Patch) يُشير لـ state سابق. إعادة البناء تحتاج:

1. آخر `anchor_snapshot`
2. كل `patch_data` بعده بالترتيب

إذا فُقدت صفوف وسطى (partial corruption) → إعادة بناء غير ممكنة → السجل الجنائي **مُتلَف بشكل لا رجعة فيه**.

لا يوجد export tool لـ timeline events كـ flat format، لا أرشفة checkpoints خارج DB.

---

### 🟡 DR-003 — `server.pid` و`server.port` في `.gitignore` لكن موجودان في الـ repo الفعلي

`.gitignore` يستثني `server.pid` و`server.port`. لكنهما موجودان في الدليل. هذا يعني أنهما **تم commit-هما في وقت سابق** ثم أُضيفا لـ `.gitignore` لاحقاً — لكن Git لا يُزيل ما تم tracking-ه مسبقاً تلقائياً.

---

## القسم السادس — سلامة الترحيلات

---

### 🔴 MIGRATE-001 — لا يوجد نظام تراجع (Rollback) لأي migration

**الدليل:** مراجعة `database/migrations/` — جميع الـ 33 migration هي SQL scripts فقط. لا يوجد:

- `down()` migration
- Rollback script
- Reversibility annotation

**أخطر المهاجمات غير القابلة للتراجع:**

1. `20260304_000024_enforce_batch_isolation_guards.sql` — يُنشئ trigger يرفض بيانات قديمة. لا rollback.
2. `20260305_000026_enforce_workflow_transition_guards.sql` — يُضيف trigger يمنع transitions قديمة. لا rollback.
3. `20260228_000017_add_domain_constraints_and_stability_indexes.sql` — يُضيف CHECK constraints. إزالتها بعد run = migration جديدة.

**الأثر:** إذا كشفت migration خطأ بعد تطبيقها في الإنتاج — **لا طريقة للتراجع** سوى كتابة migration عكسية يدوياً واختبارها.

---

### 🔴 MIGRATE-002 — لا يوجد Migration Version Tracking

**الدليل:** لا يوجد جدول `migrations` أو `schema_versions` في DB. لا يوجد migration runner script.

**المشكلة:**
لا طريقة برمجية لمعرفة:

- أي migrations طُبِّقت على هذا DB؟
- هل migration #17 طُبِّقت قبل #26؟
- هل يمكن تطبيق migration #26 بأمان؟

**الإجراء الحالي:** تُطبَّق migrations يدوياً بالترتيب الأبجدي للاسم (بما أن الاسم يبدأ بـ timestamp). لكن:

- أي خطأ في الاسم → ترتيب خاطئ
- لا تحقق من أن migration طُبِّقت مرة واحدة فقط
- إعادة تشغيل migration = **أخطاء مكررة في الـ triggers والـ constraints**

---

### 🔴 MIGRATE-003 — بعض Migrations تغلق الجدول (Table Lock) أثناء التطبيق

**الملف:** `20260228_000017_add_domain_constraints_and_stability_indexes.sql`

```sql
CREATE INDEX CONCURRENTLY idx_guarantees_status_created ...
```

بعض indexes تستخدم `CONCURRENTLY` (لا lock) والبعض لا. كل `ADD CONSTRAINT CHECK` تأخذ **ACCESS SHARE lock** على الجدول أثناء التحقق من البيانات الحالية. في DB ضخمة = المستخدمون محجوبون أثناء التطبيق.

---

### 🟡 MIGRATE-004 — `ALTER COLUMN TYPE` يُعيد بناء الجدول بالكامل

**الملف:** `20260305_000026_enforce_workflow_transition_guards.sql`

```sql
IF active_action_set_at_udt NOT IN ('timestamp', 'timestamptz') THEN
    ALTER TABLE guarantee_decisions
        ALTER COLUMN active_action_set_at TYPE TIMESTAMP ...
```

`ALTER COLUMN TYPE` في PostgreSQL يُعيد كتابة كل صفوف الجدول. في `guarantee_decisions` مع 100,000 صمان = **دقائق من الـ table lock الكامل** في الإنتاج.

---

## القسم السابع — دورة حياة البيانات

---

### 🔴 LIFECYCLE-001 — لا توجد سياسة حذف أو أرشفة لأي بيانات

**الجداول التي تنمو إلى الأبد بدون أي آلية حذف:**

| الجدول                   | معدل النمو المتوقع | بعد 5 سنوات       |
| ------------------------ | ------------------ | ----------------- |
| `guarantee_history`      | 20-50 صف/ضمان      | 1M-5M صف          |
| `notifications`          | 5-20 إشعار/حدث     | 500K-2M صف        |
| `login_rate_limits`      | صف/محاولة          | يتراكم لا نهائياً |
| `scheduler_job_runs`     | صف/تشغيل           | 100K+ صف          |
| `scheduler_dead_letters` | صف/فشل             | 10K+ صف           |
| `break_glass_events`     | نادر               | محدود             |
| `settings_audit_logs`    | صف/تغيير           | مقبول             |

**الخطر:** `login_rate_limits` تنمو مع كل محاولة login (ناجحة أو فاشلة) بدون أي `DELETE ... WHERE created_at < NOW() - INTERVAL '1 day'` دوري.

---

### 🔴 LIFECYCLE-002 — `notifications` لا تُحذف بعد قراءتها

**الدليل:** جدول `notifications` بـ `dedupe_key` — يمنع إرسال نفس الإشعار مرتين. لكن لا يوجد:

- حقل `read_at`
- سياسة حذف بعد X أيام
- أرشفة للإشعارات القديمة

**الأثر:** الإشعارات القديمة (من سنوات) تبقى في الجدول. استعلامات `notifications WHERE user_id = X` تزداد بطئاً مع الوقت.

---

### 🟡 LIFECYCLE-003 — Uploaded Excel files في `storage/uploads/` لا تُحذف بعد المعالجة

**الدليل:** `.gitignore` السطر 18: `/storage/uploads/*.xlsx` — موجودة لكن لا مُستثناة من التراكم.

لا يوجد cleanup script يحذف Excel files بعد إتمام الاستيراد. مع الوقت: `storage/uploads/` ينمو خطياً مع كل استيراد.

---

## القسم الثامن — قابلية الاختبار

---

### 🔴 TEST-001 — معظم "Unit Tests" هي في الواقع Wiring Tests تحتاج DB حية

**الدليل:** `phpunit.xml` — `APP_ENV=testing` مع `WBGL_DB_DRIVER=pgsql` بدون isolated DB credentials.

أسماء الـ test files:

- `ApiContractGuardWiringTest` → تتحقق من السجل، تحتاج DB
- `SecurityBaselineWiringTest` → تتحقق من السجل، تحتاج DB
- `WorkflowTransitionGuardMigrationWiringTest` → تتحقق من migration، تحتاج DB حقيقية

**النتيجة:** لا يوجد **unit tests حقيقية** لـ:

- `ConfidenceCalculatorV2::calculate()` بحالات حدية
- `WorkflowService::advance()` بمدخلات خاطئة
- `SmartProcessingService::processGuarantee()` بدون DB

**الكود غير قابل للاختبار بمعزل عن قاعدة البيانات.**

---

### 🔴 TEST-002 — `SmokeTest` هو مجرد `assertTrue(true)`

**الملف:** `tests/Unit/SmokeTest.php`

```php
public function testFrameworkIsOperational(): void
{
    $this->assertTrue(true); // ← اختبار بلا معنى حرفياً
}
```

استدعاء `phpunit` — وThissTest يمر **دائماً بغض النظر عن حالة النظام**.

---

### 🔴 TEST-003 — لا يوجد Dependency Injection فعلي — اختبار الوحدة مستحيل

**الدليل:** من `AuthorityFactory.php`:

```php
$supplierRepo = new SupplierRepository(); // ← يُنشئ PDO مباشرة داخلياً
```

ولا يوجد interface يمكن mock-ها. `SupplierRepository` يستدعي `Database::connect()` في constructor. لا يمكن إنشاء `SupplierRepository` في test بدون اتصال DB حقيقي.

**الأثر:** كتابة unit test لـ `FuzzySignalFeeder` = مستحيلة بدون test DB.

---

### 🟡 TEST-004 — لا يوجد test coverage reporting

`phpunit.xml` لا يحتوي `<coverage>` section. لا يمكن معرفة:

- أي الكود مُغطَّى بالاختبارات
- أي الكود لم يُختبَر أبداً

---

## القسم التاسع — قابلية الصيانة

---

### 🔴 MAINT-001 — تشابك الـ services بدون حدود واضحة

**الدليل:** `BatchService.php` السطر 133:

```php
$guaranteeRepo = new \App\Repositories\GuaranteeRepository($this->db);
$decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);
```

**المشكلة:** Services تُنشئ repositories أخرى مباشرةً بـ `new` داخل methods وليس في constructor. هذا يعني:

- لا تصريح واضح بالتبعيات
- لا يمكن تتبع من يستخدم ماذا بدون قراءة كل method
- تغيير repository API = بحث يدوي في كل `new` في كل service

---

### 🔴 MAINT-002 — `index.php` (118KB) سيكون مستحيل الصيانة خلال سنة

**تحليل نمو التعقيد:**

- حجم حالي: 118KB → يضاف feature = +2-5KB → سنة واحدة: 130-150KB
- cyclomatic complexity: لا يمكن قياسها لأنه ملف واحد بلا وحدات
- أي regression: `git blame index.php` يُرجع آلاف السطور

**الخطر الحقيقي:** أي مطور جديد يحتاج **أسابيع** لفهم `index.php`. فريق من 3 مطورين = conflicts على نفس الملف يومياً.

---

### 🟡 MAINT-003 — أسماء ملفات Migrations ليست منهجية بالكامل

أسماء المهاجرات تبدأ بـ timestamp (`20260225_000000_`) — هذا جيد للترتيب. لكن:

- `20260226_000003` و`20260226_000004` في نفس اليوم — timestamp اليوم بدون وقت = ترتيب بالرقم الوسطي
- إذا أراد مطوران تطبيق migration في نفس اليوم: تعارض على نفس timestamp
- لا يوجد أداة لإنشاء migration ID فريد تلقائياً

---

### 🟡 MAINT-004 — كود PHP بدون type hints كاملة في Repositories

**الدليل:** `BatchService.php` السطر 83:

```php
static fn(array $row): bool => GuaranteeVisibilityService::canAccessGuarantee((int)($row['id'] ?? 0))
```

يستخدم cast `(int)` لأن `$row['id']` من DB قد يكون string. بدون type hints على Repository methods، كل مكان يستخدم البيانات يجب أن يتذكر عمل cast — مصدر أخطاء صامتة.

---

## القسم العاشر — تصميم تجربة المستخدم

---

### 🔴 UX-001 — رسائل الخطأ بين العربية والإنجليزية بدون منهجية

**الدليل:** `BatchService.php`:

```php
'reason' => 'لا يوجد قرار لهذا الضمان'  // ← عربي
'reason' => 'مقفل'                          // ← عربي
```

لكن من `_bootstrap.php`:

```php
'message' => 'Not Found'              // ← إنجليزي
'message' => 'Permission Denied'     // ← إنجليزي
'message' => 'Internal Server Error' // ← إنجليزي
```

**الأثر:** المستخدم يرى:

- رسائل batch بالعربية
- رسائل API error بالإنجليزية
- لا اتساق → cognitive confusion في حالات الخطأ

---

### 🔴 UX-002 — لا توجد حالة "Loading" أو progress indicator لعمليات الـ batch

**الدليل:** `BatchService.extendBatch()` تُنفِّذ N transactions متسلسلة. HTTP request يبقى معلقاً حتى الانتهاء. في batch بـ 500 ضمان = 30-120 ثانية انتظار.

المستخدم يرى: **صفحة متجمدة بدون أي مؤشر**.

السلوك الطبيعي: المستخدم يُعيد النقر → طلب جديد يبدأ → الأول لم ينتهِ → **تداخل transactions**.

---

### 🔴 UX-003 — حقول Workflow Stage لا تُوضِّح ما هو مطلوب بالضبط

Workflow stages: `draft → audited → analyzed → supervised → approved → signed`

لا يوجد في واجهة المستخدم (من خلال الكود) ما يُبيِّن:

- ماذا يجب أن يفعل المدقق في مرحلة `audited`؟
- ما الفرق بين `analyzed` و`supervised`؟
- أي حقول يجب أن تكون مملوءة قبل التقدم؟

كل هذا **تدريب شفهي** — لا guidance في الواجهة.

---

### 🟡 UX-004 — لا يوجد Accessibility (a11y) حقيقي

**الدليل:** `tests/Unit/UxA11yWiringTest.php` موجود — اسمه يُشير لـ accessibility test. لكن كـ "Wiring Test" فهو على الأرجح يتحقق من وجود عناصر في DOM لا من معاييرWCAG.

بيانات ضمان كثيرة، جداول معقدة — بدون aria-labels ومعايير الوصول، المستخدمون ذوو الإعاقة لا يمكنهم استخدام النظام.

---

## الملخص التنفيذي

### جدول الأولويات الشاملة

| #   | المجال       | المشكلة                                  | الخطورة   |
| --- | ------------ | ---------------------------------------- | --------- |
| 1   | بنية تحتية   | PHP built-in server في "الإنتاج"         | 🔴 حرجة   |
| 2   | أسرار        | `settings.json` غير مستثنى من Git        | 🔴 حرجة   |
| 3   | بنية تحتية   | `storage/settings.json` قابل للوصول HTTP | 🔴 حرجة   |
| 4   | متصفح        | CSP `unsafe-inline` يُلغي حماية XSS      | 🔴 حرجة   |
| 5   | ترحيل        | لا rollback لأي migration                | 🔴 حرجة   |
| 6   | ترحيل        | لا migration version tracking            | 🔴 حرجة   |
| 7   | اختبار       | Unit Tests تحتاج DB حية — لا mock        | 🔴 عالية  |
| 8   | دورة بيانات  | لا حذف لـ `login_rate_limits` → نمو أبدي | 🟡 عالية  |
| 9   | دورة بيانات  | Excel files لا تُحذف بعد معالجة          | 🟡 متوسطة |
| 10  | تجربة مستخدم | batch تجمد 30-120 ثانية بدون مؤشر        | 🔴 عالية  |

---

### الخلاصة الجوهرية

> التقارير الخمسة السابقة غطَّت: الكود، الأمن التطبيقي، الأداء، السلوك البشري، ونقاط البنية التحتية المرئية.
>
> ما بقي غير مُحلَّل: **طبقة العمليات الكاملة** — كيف يُنشَر، كيف يُصان، كيف يُختبَر، وكيف يتعامل مع نمو البيانات.
>
> **الإجابة:** النظام لا يُنشَر بـ Apache/nginx — يعمل بـ PHP built-in server. ليس لديه tests حقيقية. ليس لديه migration rollback. بياناته تنمو بلا حدود.
>
> هذه ليست مشاكل كود — هي **مشاكل نضج تشغيلي**. النظام مُصمَّم للعمل كتطبيق واحد على جهاز واحد في سياق محكوم.
