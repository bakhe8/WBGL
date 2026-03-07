# تقرير التدقيق المعرفي من الدرجة الثانية — Meta-Audit

## WBGL — ما فاتته التقارير الثلاثة السابقة

> **المهمة:** الكشف عن الثغرات التي لم تُغطِّها التقارير السابقة.
> كل ما تَم اكتشافه سابقاً مُستبعَد. هذا حصراً ما فات.
>
> **التاريخ:** 2026-03-07

---

## القسم الأول — تحليل تغطية التقارير السابقة

| المجال                          | مستوى التغطية | ملاحظة                                               |
| ------------------------------- | ------------- | ---------------------------------------------------- |
| سلوك وقت التشغيل                | جزئي          | الـ lifecycle نُقِّح لكن تأثيرات البيئة لم تُحلَّل   |
| أداء قاعدة البيانات             | جزئي          | الفهارس مذكورة لكن query plans غائبة                 |
| خطط تنفيذ الاستعلامات           | **لا تغطية**  | قابل للتحقق                                          |
| السلوك المتزامن                 | **لا تغطية**  | Critical — لم يُذكر أبداً                            |
| استهلاك الذاكرة                 | **لا تغطية**  | phpspreadsheet + JSON + batch                        |
| حدود النظام                     | **لا تغطية**  | PHP memory_limit, max_execution_time                 |
| تحمّل الأعطال                   | جزئي          | dead letter ذُكر لكن DB restart لم يُحلَّل           |
| سيناريوهات تلف البيانات         | **لا تغطية**  | partial write, torn write                            |
| الترميز والتوطين                | جزئي          | levenshtein ذُكر لكن encoding propagation لم يُحلَّل |
| التكاملات الخارجية              | **لا تغطية**  | phpspreadsheet, email, PowerShell                    |
| افتراضات البنية التحتية         | **لا تغطية**  | web server, PHP-FPM, sslmode                         |
| مخاطر النشر                     | **لا تغطية**  | zero-downtime, rollback                              |
| المراقبة والرصد                 | جزئي          | Operational Alerts ذُكر لكن gaps كثيرة               |
| التعافي التشغيلي                | **لا تغطية**  | لا procedure واضح                                    |
| التعافي من الكوارث              | **لا تغطية**  | لا backup، لا DR                                     |
| استراتيجية النسخ الاحتياطي      | **لا تغطية**  | غائبة كلياً من الكود                                 |
| مخاطر ترحيل البيانات            | جزئي          | schema drift ذُكر لكن data migration scenarios لا    |
| إدارة الاعتمادات                | جزئي          | settings.json ذُكر لكن dependency risks لا           |
| مخاطر التبعيات                  | **لا تغطية**  | phpspreadsheet CVEs، version pinning                 |
| مخاطر Frontend-Backend          | **لا تغطية**  | token handling في JS، race في forms                  |
| مخاطر سلوك المستخدم             | **لا تغطية**  | misuse، double-submit، tab duplication               |
| سير عمل الحالات الحدية          | جزئي          | بعض ذُكر لكن كثير فات                                |
| الفروع الميتة الخفية            | جزئي          | بعض ذُكر                                             |
| مخاطر تطور البيانات طويلة المدى | جزئي          | JSON drift ذُكر لكن quantification فات               |
| حدود قابلية التوسع              | **لا تغطية**  | أبداً لم تُحلَّل                                     |

---

## القسم الثاني — النقاط العمياء المكتشفة

---

### 🔵 BLIND-001 — لا يوجد Connection Pooling — PDO Singleton خطير

**الملف:** `app/Support/Database.php` السطر 12-46

**الدليل:**

```php
private static ?PDO $instance = null;

public static function connect(): PDO
{
    if (self::$instance === null) {
        self::$instance = self::connectPgsql($config);
    }
    return self::$instance;
}
```

**المشكلة غير المُكتشَفة:**
PHP-FPM مع عدة workers = كل worker يحتفظ بـ **اتصال PDO مفتوح باستمرار** مع PostgreSQL. هذا يعني:

- مع 20 worker: 20 اتصال دائم في PostgreSQL
- PostgreSQL `max_connections` الافتراضي = 100
- في وقت الذروة: FPM pool يمكن أن **يستنفد** كل connections المتاحة
- لا يوجد `pg_bouncer` أو أي connection pooler
- لا يوجد retry عند فقدان الاتصال (DB restart = كل workers يتعطلون معاً)

**الأثر:** عند إعادة تشغيل PostgreSQL — كل الـ workers الحالية تحتفظ بـ PDO stale instance. الاتصال القديم مكسور لكن `self::$instance !== null` لذا لا تتم إعادة الاتصال. كل الطلبات تفشل بـ **PDOException** حتى يُعاد تشغيل PHP-FPM يدوياً.

---

### 🔵 BLIND-002 — `date_default_timezone_set()` داخل Database::connect()

**الملف:** `app/Support/Database.php` السطر 19

```php
public static function connect(): PDO
{
    date_default_timezone_set('Asia/Riyadh'); // ← يُستدعى مع كل connect()
```

**المشكلة:**
`date_default_timezone_set` تغير الـ timezone لكامل عملية PHP. المشكلة:

1. يُستدعى هذا في أي مكان تُستخدم `Database::connect()` — بما في ذلك Scripts وCLI
2. في CLI scripts مُشغَّلة من cron بـ timezone مختلفة → قد يُسبب تعارضاً
3. أي حساب زمني يحدث **قبل** أول استدعاء لـ `Database::connect()` سيستخدم timezone مختلفة
4. `date_default_timezone_set` ليست thread-safe — مشكلة إذا استُخدمت PHP async extensions مستقبلاً

**الأثر الجنائي:** حسابات التواريخ في `Guards` أو `SessionSecurity` (تُنفَّذ قبل connect) ستستخدم UTC أو timezone الخادم، بينما حسابات `BatchService` ستستخدم Asia/Riyadh. **تناقض في حسابات الوقت داخل نفس الطلب.**

---

### 🔵 BLIND-003 — `BatchService` يحتوي N تحويلات قاعدة بيانات منفصلة في حلقة

**الملف:** `app/Services/BatchService.php` السطر 197-248

**الدليل:**

```php
foreach ($guarantees as $g) {
    ...
    $this->db->beginTransaction(); // ← transaction مستقلة لكل ضمان
    try {
        // update...
        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e; // ← يكسر الحلقة!
    }
}
```

**المشكلة:**
لو دفعة تحتوي 500 ضمان، وفشل الضمان رقم 400:

- الضمانات 1-399 تُوافَق وتُنفَّذ
- الضمان 400 يفشل → rollback لهذا الضمان فقط
- `throw $e` يكسر الحلقة → الضمانات 401-500 لا تُعالَج

**النتيجة:** دفعة ممتدة جزئياً. المستخدم لا يعلم أن 100 ضمان لم تُعالَج. السجل يُظهر:

```json
"extended_count": 399,
"errors": [{"guarantee_id": 400, "error": "..."}]
```

لكن المستخدم قد يعتقد أن الـ 399 فعط المطلوبة كانت مختارة. **حالة بيانات غير متسقة** للدفعة.

---

### 🔵 BLIND-004 — `isBatchClosed()` يُستعلَم 3 مرات لكل عملية batch

**الملف:** `app/Services/BatchService.php`

في كل من `extendBatch()`, `releaseBatch()`, `reduceBatch()`:

```php
if ($this->isBatchClosed($importSource)) { ... } // استعلام DB
```

ثم داخل كل loop iteration:

```php
$guarantees = $this->getBatchGuarantees(...)
```

**بين الاستعلام والتنفيذ لا يوجد lock.** إذا أغلق مستخدم آخر الدفعة أثناء معالجة الحلقة — الحالة الـ "closed" لا تُكتشَف حتى العملية التالية. **Race condition صامتة**: يمكن تنفيذ extend على دفعة مغلقة إذا جاء الإغلاق بعد check وقبل commit.

---

### 🔵 BLIND-005 — phpspreadsheet تقرأ ملفات Excel كاملة في الذاكرة

**الاعتماد:** `composer.json` — `phpoffice/phpspreadsheet: ^1.29`

**المشكلة:**
phpspreadsheet تُحمّل ملف Excel **كاملاً في memory** عند المعالجة. ملف Excel بـ 10,000 ضمان:

- كل صف يحتوي ~10 أعمدة
- phpspreadsheet ينشئ Cell objects لكل خلية
- ذاكرة مطلوبة: ~50-200MB لملف متوسط

مع `memory_limit = 128M` (افتراضي PHP) → **Fatal: Allowed memory size exhausted** صامت من منظور المستخدم.

**الأسوأ:** phpspreadsheet المُلفَّقة (malformed Excel) يمكن أن تُسبّب:

- infinite loop في XML parser
- OOM في ZIP extraction
- مشاركة نفس الـ worker لعملية تلتهم كل ذاكرة PHP-FPM

لا يوجد في الكود أي:

- `use PhpOffice\PhpSpreadsheet\...ReadFilter` (لقراءة جزئية)
- `$spreadsheet->disconnectWorksheets()` (لتحرير الذاكرة)
- حد لعدد صفوف Excel المقبولة

---

### 🔵 BLIND-006 — `phpspreadsheet ^1.29` — نطاق إصدار واسع بدون lock

**الملف:** `composer.json` السطر 10

```json
"phpoffice/phpspreadsheet": "^1.29"
```

`^1.29` يعني: من 1.29.0 **حتى أقل من 2.0.0**. هذا يشمل أي إصدار `1.x` مُستقبلي.

**المخاطر:**

1. `composer update` التالية ستُحدّث phpspreadsheet لأحدث 1.x تلقائياً
2. phpspreadsheet لها سجل CVEs (XML External Entity Injection، Path Traversal في Excel files)
3. إصدار جديد قد يكسر API الحالية بشكل صامت

**لا يوجد:**

- `composer.lock` في `.gitignore` بشكل صحيح
- Security audit cron للتحقق من CVEs

---

### 🔵 BLIND-007 — Token API بدون Rate Limiting

**الملفات:** `api/_bootstrap.php` + `app/Services/ApiTokenService.php`

**الدليل:**
`LoginRateLimiter` يعمل **فقط على login endpoint**. مسار الـ API Token لا يخضع لأي rate limiting:

```php
// في _bootstrap.php:
// الـ token auth يستدعي: ApiTokenService::authenticateRequest()
// لا يوجد RateLimiter::check() في هذا المسار
```

**المشكلة:**
مهاجم يمتلك token صالح يمكنه إرسال **آلاف الطلبات بالثانية** بدون أي throttle. Token لا يُعاد تجديده تلقائياً (لا expiry ذُكر في الكود). Token API هو **سطح هجوم مفتوح للـ abuse**.

---

### 🔵 BLIND-008 — `_bootstrap.php` لا يتحقق من `Content-Type: application/json`

**الملف:** `api/_bootstrap.php`

**المشكلة:**
مُتغيرات الطلب تُقرأ عبر `$_POST` (لبيانات form) أو `json_decode(file_get_contents('php://input'))`. لكن لا يوجد تحقق من أي `Content-Type`. إذا أرسل عميل Request بـ `Content-Type: application/x-www-form-urlencoded` لكن body بـ JSON structure — `$_POST` سيكون فارغاً و `file_get_contents('php://input')` سيُرجع JSON.

**العكس أيضاً:** إذا أُرسل request بـ `Content-Type: application/json` لكن بـ formdata body — json_decode يفشل ويُرجع null بشكل صامت. لا يوجد validation على هذا المستوى.

---

### 🔵 BLIND-009 — `Database::connect()` يستدعي `Settings::getInstance()` الذي يقرأ الملف

**التفاعل:** `Database::connect()` → `resolveConfiguration()` → `Settings::getInstance()->get()`

**المشكلة:**
`Settings::getInstance()` يُنشئ `Settings` object الذي يُحاول قراءة `storage/settings.json`. إذا كان الملف مفقوداً أو JSON فاسداً → يُعاد `[]` بصمت → جميع قيم الـ DB (`DB_HOST`, `DB_USER`, `DB_PASS`) ستُرجع القيم الافتراضية (فارغة/localhost) → فشل الاتصال بـ DB.

**لكن الأخطر:** الخطأ يُسجَّل فقط في `error_log('[WBGL_DB_CONNECT_ERROR]')` ويخرج بـ 500 بدون أي:

- Alerting
- Fallback للـ environment variables (يحدث، لكن بعد settings)
- إنذار مسبق لفريق العمليات

---

## القسم الثالث — فئات المخاطر المفقودة

---

### ⚠️ RISK-001 — غياب كامل لاستراتيجية النسخ الاحتياطي

**الدرجة:** حرجة | **الأثر:** كارثي | **نسبة الاحتمال:** عالية

**الدليل في الكود:** لا توجد أي ملفات backup scripts، لا cron job، لا migration_backup، لا `pg_dump`, لا `pg_basebackup` في أي سكريبت. `app/Scripts/` يحتوي 28 سكريبت — لا واحد منها يتعلق بالنسخ الاحتياطي.

**المخاطر الفعلية:**

- فقدان البيانات عند فشل القرص
- لا إمكانية للتعافي من التلف الكامل
- `guarantee_history` (السجل الجنائي المُثنى بالکود) لا يُصمَّم للبقاء خارج الـ DB

**التخفيف المقترح:** إعداد `pg_dump` يومي + WAL archiving للنقطة الزمنية.

---

### ⚠️ RISK-002 — Transaction Isolation مُعيَّن على Read Committed (الافتراضي)

**الدليل:** لا يوجد في الكود `SET TRANSACTION ISOLATION LEVEL SERIALIZABLE` أو أي isolation level صريح.

**المشكلة:**
PostgreSQL الافتراضي = **Read Committed**. في `BatchService.extendBatch()`:

```
Thread A: beginTransaction() → read guarantee → snapshot →
Thread B: beginTransaction() → read guarantee → snapshot → update → commit
Thread A: → update (بناءً على snapshot قبل تحديث B) → commit
```

النتيجة: **Lost Update** — تحديث B يُفقد عند تحديث A الأحدث. في الـ Timeline: يوجد حدثان لكل snapshots تُشير لنفس الحالة القديمة.

**التصحيح:** استخدام `SELECT ... FOR UPDATE` أو isolation level `SERIALIZABLE`.

---

### ⚠️ RISK-003 — `guarantees.raw_data` نمو غير محدود

**الدليل:** `guarantees.raw_data JSON NOT NULL`

**حساب الحجم:**

- ضمان واحد: raw_data ≈ 2-5KB
- 100,000 ضمان: 200-500MB في عمود JSON واحد
- بعد 5 سنوات بمعدل 50,000 ضمان/سنة + `guarantee_history` (patch + anchor) = **تهديد حجم حقيقي**

لا يوجد:

- Table partitioning
- Archiving policy
- index على حقول داخل JSON (PostgreSQL يدعم ذلك بـ GIN indexes)

---

### ⚠️ RISK-004 — Scheduler يعمل بـ `PHP_BINARY` — اعتماد ضمني على PATH

**الملف:** `app/Services/SchedulerRuntimeService.php` السطر 51

```php
$command = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
```

**المشكلة:**
`PHP_BINARY` تُرجع المسار الكامل للـ PHP executable النشط حالياً. هذا يعني:

- في بيئة Docker: مسار PHP داخل container قد يختلف بين builds
- في cron: `PHP_BINARY` قد يختلف عن PHP المُهيَّأ للـ web server
- إذا استُخدم PHP 8.2 للـ web و PHP 8.0 لـ cron → سلوك مختلف

---

### ⚠️ RISK-005 — غياب health check endpoint

**الدليل:** لا يوجد في `api/` ملف `health.php` أو `ping.php`.

**الأثر:** لا يمكن:

- load balancer التحقق من صحة الـ server
- Kubernetes أو Docker Swarm إعادة تشغيل الـ pod تلقائياً عند الفشل
- مراقبة uptime بدون external check

---

### ⚠️ RISK-006 — `DB_SSLMODE = 'prefer'` — SSL اختياري وقابل للتدهور

**الملف:** `app/Support/Settings.php` السطر 109

```php
'DB_SSLMODE' => 'prefer',
```

`prefer` يعني: حاول SSL، وإذا فشل → انتقل لـ plaintext بصمت. في شبكة غير آمنة أو أثناء certificate problem → الاتصال يحدث بدون SSL بشكل **تلقائي وصامت**.

**المطلوب:** `sslmode = require` أو `verify-full` في الإنتاج.

---

## القسم الرابع — تحليل التعقيد الخفي

---

### 🌀 COMP-001 — إعادة بناء السجل الزمني تتدهور بشكل تربيعي

**الآلية الفعلية:**

```
تعريف إعادة البناء:
1. ابحث عن أقرب anchor سابق للنقطة الزمنية المطلوبة
2. قرأ كل patch_data من الـ anchor حتى النقطة المطلوبة
3. طبّق كل patch بالترتيب
```

**المشكلة:**
إذا كان `HISTORY_ANCHOR_INTERVAL = 10` (كل 10 أحداث anchor)، وبعد 5 سنوات يوجد guarantee بـ 1000 حدث:

- أقرب anchor قد يكون قبل 10 أحداث فقط

لكن: إذا حدث فشل في إنشاء anchor (race condition في BUG من التقرير الثاني)، قد يوجد 100+ حدث بين anchors. إعادة بناء الحالة = تطبيق 100 JSON Patch بالتسلسل.

**عدم التوثيق:** لا يوجد في الكود capacity estimate أو maximum-patches-without-anchor limit. هذا يعني: **تكلفة إعادة البناء = O(events_without_anchor)** وهي unbounded.

---

### 🌀 COMP-002 — مصفوفة تفاعل الصلاحيات قنبلة تأخيرية

**الحجم الفعلي:**

- 7 أدوار × 43 صلاحية = 301 مدخل محتمل
- يُضاف: per-user overrides (allow/deny) لكل مستخدم
- يُضاف: Guard::hasOrLegacy() يُطبّق legacy default لصلاحيات غير مسجلة

**التفاعل غير المُوثَّق:**
Role + user_override + legacy_default = ثلاثة مصادر تتفاعل. لا يوجد:

- أداة لعرض الصلاحيات الفعلية لمستخدم معين ("ما الصلاحيات الفعلية لـ User X؟")
- Audit لمتى تغيّرت الصلاحيات
- تحذير عند تعارض بين role permission وuser override

**النمو المتوقع:** مع إضافة ميزات جديدة = صلاحيات جديدة. مع 100 مستخدم و50 صلاحية = 5000 مدخل محتمل في `user_permissions`. لا توجد migration strategy لهذا التضخم.

---

### 🌀 COMP-003 — بيانات `raw_data` تتباعد بين المصادر مع الوقت (JSON Schema Entropy)

**الوضع الحالي:**
كل مصدر استيراد ينتج `raw_data` بصيغة مختلفة:

- Excel: `{'expiry_date': 'Y-m-d', 'amount': float, ...}`
- Paste: `{'expiry_date': 'dd/mm/yyyy', 'amount': '1,500,000', ...}`
- Email: `{'expiry_date': 'التاسع عشر من...', 'amount': '...', ...}`
- Manual: `{'expiry_date': 'Y-m-d', ...}` (standardized)

لا يوجد JSON schema validation. بعد سنوات:

- `raw_data['expiry_date']` سيحتوي 5+ formats مختلفة
- كود يفترض `'Y-m-d'` سيفشل عند قراءة سجل قديم
- FieldExtractionService يُعالج التباين لكنه لا يُوحِّد التخزين

**المقياس المتوقع:** ضمانات استُورِدت قبل 3 سنوات بصيغة قديمة → **تلف بيانات هادئ** عند أي migration.

---

### 🌀 COMP-004 — Workflow Trigger يُطلَق حتى عند تحديث حقل غير ذي صلة

**الملف:** `database/migrations/20260305_000026_enforce_workflow_transition_guards.sql`

```sql
CREATE TRIGGER trg_wbgl_enforce_decision_workflow_guards
BEFORE INSERT OR UPDATE ON guarantee_decisions
FOR EACH ROW
EXECUTE FUNCTION wbgl_enforce_decision_workflow_guards();
```

**المشكلة:**
الـ trigger يُطلَق على **كل UPDATE** على `guarantee_decisions` — بما في ذلك:

- تحديث `last_modified_at` فقط
- تحديث `confidence_score` فقط
- تحديث `decided_by` فقط

لكل من هذه التحديثات الصغيرة، DB trigger يُنفِّذ منطق كامل للتحقق من transitions. هذا overhead لا ضرورة له يمكن تخفيضه بجعل الـ trigger مشروطاً بـ `WHEN (OLD.workflow_step IS DISTINCT FROM NEW.workflow_step OR OLD.status IS DISTINCT FROM NEW.status)`.

---

## القسم الخامس — محاكاة سيناريوهات الفشل

---

### 💥 FAIL-001 — إعادة تشغيل PostgreSQL أثناء workflow transition

**السيناريو:** المستخدم يُقدِّم `workflow-advance.php`، بدأت transaction، DB تُعاد تشغيلها في منتصف `beginTransaction → update → commit`.

**المسار الفعلي:**

1. `beginTransaction()` ← يُفتَح اتصال PDO من singleton
2. `$this->db->prepare(...)` ← DB restart يكسر TCP connection
3. PDO يُلقي `PDOException: SQLSTATE[08006]: Server has gone away`
4. `catch (\Throwable $e)` يُجري `rollBack()` ← **سيفشل أيضاً** لأن الاتصال مكسور
5. `rollBack()` يُلقي exception ثانية → **double exception**

**الأهم:**

```php
if ($this->db->inTransaction()) {
    $this->db->rollBack(); // ← سيفشل بـ exception على اتصال مكسور
}
```

PDO exception في rollBack داخل catch = **unhandled runtime crash**. PHP يُغلق الـ worker. الـ DB singleton يبقى مكسوراً. الطلبات التالية من نفس worker = كل واحدة تفشل بنفس الطريقة حتى يُعاد تشغيل PHP-FPM.

---

### 💥 FAIL-002 — استيراد Excel ضخم + انقطاع مفاجئ

**السيناريو:** مستخدم يستورد Excel بـ 5000 ضمان. PHP memory_limit = 128M. بعد معالجة 2000 ضمان، phpspreadsheet يستهلك الذاكرة الكاملة.

**المسار:**

1. `phpspreadsheet` يُحمِّل Excel كاملاً في الذاكرة
2. PHP يُطلق `Fatal error: Allowed memory size`
3. PHP-FPM يُسجِّل الخطأ، يُرجع 500 للعميل
4. **Transaction مفتوحة** للضمانات المعالجة قبل الـ OOM = تُلغى تلقائياً (PostgreSQL يُغلق connection → rollback تلقائي)
5. **لكن:** الـ 2000 ضمان المعالجة بتحويلات `COMMIT` مسبقة = **موجودة في DB**
6. الـ 3000 الباقية = غائبة
7. المستخدم يرى 500 error، لا يعلم بأي شيء نجح

**الأثر:** دفعة جزئية في DB بدون أي علامة تدل على عدم اكتمالها.

---

### 💥 FAIL-003 — محاولتان لـ workflow transition متزامنتان

**السيناريو:** نفس الضمان مفتوح في tabين في المتصفح. المستخدم يضغط "تقدم" في كلا التابين بشكل شبه متزامن.

**المسار:**

```
Request A: beginTransaction()
Request B: beginTransaction()
Request A: SELECT workflow_step = 'audited' ← شرط التحقق
Request B: SELECT workflow_step = 'audited' ← نفس الحالة
Request A: UPDATE workflow_step = 'analyzed' → COMMIT
Request B: UPDATE workflow_step = 'analyzed' ← DB trigger يمنع: 'audited→analyzed' صحيح لكن الحالة الآن 'analyzed'
```

**هل يمنع الـ trigger ذلك؟** نعم — `wbgl_enforce_decision_workflow_guards` يتحقق من `OLD.workflow_step`. لكن:

- Request B يرى OLD = 'analyzed' و NEW = 'analyzed' → لا transition → لا خطأ من الـ trigger
- REQUEST B ينجح: workflow_step يُبقى 'analyzed'، decision_source تُحدَّث، last_modified تُحدَّث

**النتيجة:** request B "ينجح" (HTTP 200) لكن لم يُحدث شيئاً فعلياً. المستخدم الثاني يعتقد أنه قدَّم workflow بينما في الواقع حدث race. **التلف الجنائي:** سجل timeline يحتوي حدثَين لنفس الانتقال.

---

### 💥 FAIL-004 — Settings JSON يُتلَف عند التحديث المتزامن

**السيناريو:** إدارييان يحفظان الإعدادات في نفس الوقت.

**المسار:**

```php
// كلاهما يستدعي:
$result = file_put_contents($this->path, $encoded, LOCK_EX);
```

`LOCK_EX` = exclusive lock — لكن على Unix:

1. Admin A يفتح الملف، يكتسب lock
2. Admin B ينتظر
3. Admin A يكتب JSON الكامل ويُغلق
4. Admin B يكتب JSON الكامل ويُغلق

**لكن المشكلة:** كل منهما قرأ الإعدادات **الحالية** قبل الـ lock:

```php
$current = $this->loadPrimary(); // ← قراءة بدون lock
$merged = array_merge($current, $data);
file_put_contents($this->path, $encoded, LOCK_EX); // ← كتابة بـ lock
```

**فقدان التحديث (Lost Update):** Admin A غيّر MATCH_AUTO_THRESHOLD. Admin B غيّر TIMEZONE. كلاهما قرأ القيم الأصلية → كلاهما بنى merged مختلف → آخر من يكتب يمحو تغيير الأول.

هذا **TOCTOU bug** (Time-of-check to time-of-use) في Settings.

---

## القسم السادس — تنبؤ الأداء عند التوسع

---

### 📊 SCALE-001 — 10,000 ضمان

| المؤشر                                                         | التقدير             | التأثير               |
| -------------------------------------------------------------- | ------------------- | --------------------- |
| حجم guarantee_history                                          | ~100,000-500,000 صف | مقبول                 |
| البحث الفازي (600 candidates)                                  | ~600ms/طلب          | مقبول                 |
| بناء SQL predicate للـ batch view                              | مليون صف scan       | بطيء بدون index مناسب |
| `getBatchGuarantees()` مع `GuaranteeVisibilityService` per-row | O(n) in PHP         | ثقيل                  |
| `Settings` fileقراءة (100 طلب)                                 | 200 قراءة ملف/ثانية | ضجيج ملحوظ            |

**الحكم:** مقبول مع تحسينات طفيفة.

---

### 📊 SCALE-002 — 100,000 ضمان

| المؤشر                             | التقدير                                | التأثير            |
| ---------------------------------- | -------------------------------------- | ------------------ |
| حجم guarantee_history              | 1-5 مليون صف                           | يحتاج partitioning |
| `raw_data` JSON في guarantees      | 200-500MB في عمود واحد                 | Seq scan كارثي     |
| إعادة بناء Timeline لضمان قديم     | تطبيق 100+ patches                     | 500ms-2s/ضمان      |
| FuzzySignalFeeder يجلب 600 من 100k | WITH indexes مقبول, لكن ranking في PHP | تدهور              |
| BatchService على دفعة 10k          | ~10,000 transactions منفصلة            | ~60-120 ثانية      |

**الحكم:** يحتاج إعادة هيكلة JavaBatch والفهرسة.

---

### 📊 SCALE-003 — 1,000,000 ضمان

**هذا النظام غير قابل للعمل بهذا الحجم بدون إعادة معمارية كاملة:**

| المشكلة                                             | السبب                            |
| --------------------------------------------------- | -------------------------------- |
| `guarantee_history` بـ 10M-50M صف بدون partitioning | Seq scan = دقائق                 |
| `raw_data` JSON في عمود TEXT                        | لا GIN index، لا partial queries |
| PHP-FPM singleton connection بدون pooler            | max_connections مستنزف           |
| `FuzzySignalFeeder` يجلب 600 مورد من 1M supplier    | index fragmentation              |
| BatchService N transactions                         | يحتاج أيام                       |
| `guarantee_history_archive` لا يُستخدم              | archive strategy مفقودة          |

---

## القسم السابع — نقاط الهشاشة المعمارية المتأخرة

---

### 🏗️ ARCH-001 — اعتماد ضمني على sapi CLI vs Web

**الملف:** `app/Support/Database.php` السطر 31

```php
if (php_sapi_name() === 'cli-server' || isset($_SERVER['HTTP_ACCEPT'])) {
    // JSON error response
} else {
    die('Database Connection Error');
}
```

**المشكلة:**

- `php_sapi_name() === 'cli-server'`: هذا الـ built-in server اختبار فقط
- في CLI (cron): `$_SERVER['HTTP_ACCEPT']` غير موجود → يُنفَّذ `die()` في الـ CLI
- أي سكريبت cron يستدعي Database::connect() عند فشل الـ DB → `die()` بدون أي output مفيد

**الأثر:** Cron jobs تفشل بصمت بدون error message مناسب.

---

### 🏗️ ARCH-002 — settings.json قبل متغيرات البيئة في التسلسل

**الملف:** `app/Support/Database.php` السطر 154-170

```php
// Project policy: settings.json is the primary runtime source.
// Environment values are treated as a temporary override/fallback only.
if ($settings instanceof Settings) {
    $value = $settings->get($settingsKey, null); // ← أولاً
    if ($value !== null && trim($value) !== '') { return $value; }
}
$envValue = getenv($envKey); // ← ثانياً
```

**التعارض مع ممارسات العمل:**
12-factor app principle: **Environment variables should be the primary configuration source** for production. لكن WBGL يُعكِس هذا: settings.json أولاً، env أخيراً.

**المشكلة العملية:**
في بيئة Kubernetes/Docker: `WBGL_DB_HOST` موضوع كـ secret في environment. لكن إذا كان `settings.json` موجود (ولو فارغاً إلا من مرحلة سابقة)، قد يُتجاهَل الـ environment variable. مشغّل البنية التحتية لن يتوقع هذا السلوك.

---

### 🏗️ ARCH-003 — غياب وسيط معالجة الطوابير (Message Queue)

**الوضع الحالي:**
كل العمليات المكلفة تُنفَّذ **synchronously** داخل طلب HTTP:

- استيراد Excel
- المطابقة الذكية (6 feeders × N signals)
- batch operations (N transactions)
- إرسال notifications

**الأثر الآن:** طلب HTTP واحد قد يستغرق 30-120 ثانية. PHP `max_execution_time` الافتراضي = 30 أو 60 ثانية. **استيراد ملف Excel كبير = PHP timeout صامت**.

---

## القسم الثامن — التقرير المفقود: الثغرات والمخاطر و التوصيات

---

### 1. النقاط العمياء في التقارير السابقة

| النقطة العمياء                                       | الخطورة | السبب                        |
| ---------------------------------------------------- | ------- | ---------------------------- |
| غياب Connection Pooling                              | حرجة    | DB restart = FPM crash كامل  |
| `date_default_timezone_set()` في Database::connect() | عالية   | تناقض زمني داخل الطلب        |
| Read Committed Isolation = Lost Update               | حرجة    | تحديثات متزامنة تفقد بعضها   |
| phpspreadsheet OOM للملفات الكبيرة                   | حرجة    | PHP timeout/crash صامت       |
| Token API بدون Rate Limiting                         | عالية   | abuse vector مفتوح           |
| TOCTOU في Settings::save()                           | عالية   | lost update عند تحديث متزامن |
| sslmode=prefer → plaintext fallback                  | عالية   | بيانات DB مكشوفة بدون تحذير  |
| Workflow trigger يعمل على كل UPDATE                  | متوسطة  | overhead غير ضروري           |
| غياب كامل لـ Backup strategy                         | كارثية  | لا استعادة عند الفشل         |
| غياب Health Check endpoint                           | عالية   | لا monitoring تلقائي         |

---

### 2. المخاطر الجديدة المكتشفة

| الخطر                                      | الخطورة | التخفيف                                            |
| ------------------------------------------ | ------- | -------------------------------------------------- |
| BatchService N transactions → حالة جزئية   | عالية   | Transactional batch wrapper أو Saga pattern        |
| isBatchClosed() race condition             | متوسطة  | Advisory Lock أو SELECT FOR UPDATE                 |
| phpspreadsheet ^1.29 بدون lock             | عالية   | تثبيت patch version + `composer.lock` في VCS       |
| PHP_BINARY في Scheduler يختلف بالبيئات     | متوسطة  | تكوين PHP path صريح                                |
| JSON Schema Entropy عبر المصادر            | عالية   | JSON Schema validation + normalization post-import |
| Timeline reconstruction غير محدودة التكلفة | متوسطة  | تحديد max_patches_without_anchor                   |

---

### 3. توصيات فورية (أسبوع واحد)

1. **إضافة `LOCK IN SHARE MODE` أو `FOR UPDATE`** في جميع workflow transitions
2. **تغيير `sslmode` إلى `require`** في إعدادات الإنتاج
3. **تعطيل `Guard::hasOrLegacy(default=true)`** — تغيير الـ default إلى `false`
4. **إضافة `api/health.php`** endpoint بسيط يرجع 200 OK
5. **تسجيل `DB_PASS`** عبر متغيرات البيئة فقط وليس `settings.json`

---

### 4. توصيات التوسع المتوسط المدى (شهر)

1. **تثبيت PHP-FPM connection pool** عبر pg_bouncer
2. **تطبيق ReadFilter في phpspreadsheet** للقراءة الجزئية وتجنب OOM
3. **إعداد pg_dump cron** — نسخ احتياطية يومية
4. **إضافة Token Rate Limiting** موازٍ لـ Login Rate Limiter
5. **تحويل BatchService** من N transactions إلى Savepoint-based batch

---

### الخلاصة الشاملة

> التقارير الثلاثة السابقة كانت ممتازة في: أمن الطبقة التطبيقية، الحوكمة، السجل الجنائي، وجودة الكود.
>
> لكنها أغفلت كلياً: **طبقة البنية التحتية** — الاتصال بـ DB، التزامن، الذاكرة، النسخ الاحتياطية، والتوسعة.
>
> **الخطر الأول غير المُكتشَف:** إعادة تشغيل PostgreSQL تعني تعطل كامل لـ PHP-FPM workers بدون تعافي تلقائي.
> **الخطر الثاني:** البيانات المالية الحرجة في `raw_data` غير مُنظَّمة ومتباعدة الصيغ — كارثة ترحيل مؤجلة.
