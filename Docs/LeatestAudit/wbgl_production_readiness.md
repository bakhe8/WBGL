# تقرير جاهزية الإنتاج المؤسسي

## Enterprise Production Readiness Audit — WBGL

### لجنة مراجعة البنية التحتية — تقييم الجاهزية للتشغيل طويل الأمد

---

> **الجهة المُقيِّمة:** مجلس مراجعة البنية التحتية الأعلى للمؤسسة
> **تاريخ التقييم:** 2026-03-07
> **المراحل:** 10 مراحل تقييم شاملة
> **الحكم المطلوب:** هل يمكن لـ WBGL أن يعمل بأمان لسنوات في بيئة إنتاج حقيقية؟

---

## المرحلة الأولى — افتراضات بيئة التشغيل

### 1.1 متطلبات PHP الخفية

**الدليل:** `composer.json` السطر 9: `"php": ">=8.0"`

المتطلبات الفعلية **غير الموثَّقة** التي يكتشفها المشغّل من الكود فقط:

| المتطلب                                         | المصدر في الكود                 | موثَّق في README؟ |
| ----------------------------------------------- | ------------------------------- | ----------------- |
| PHP ≥ 8.1 (بسبب str_starts_with, enum-like use) | `FuzzySignalFeeder.php:219`     | ❌                |
| ext-pdo_pgsql                                   | `Database::connectPgsql()`      | ❌                |
| ext-mbstring                                    | `mb_strlen()` في كل مكان        | ❌                |
| ext-json                                        | `json_encode/decode` في كل مكان | ❌                |
| ext-zip (phpspreadsheet)                        | `phpoffice/phpspreadsheet`      | ❌                |
| ext-gd أو ext-imagick (phpspreadsheet)          | xlsx thumbnails                 | ❌                |
| memory_limit ≥ 256M                             | Excel import OOM                | ❌                |
| max_execution_time ≥ 120s                       | batch operations                | ❌                |
| upload_max_filesize ≥ 20M                       | Excel files                     | ❌                |
| post_max_size ≥ 20M                             | Excel upload                    | ❌                |
| `storage/` مكتوب (writable)                     | settings.json, logs             | ❌                |
| `public/uploads/` مكتوب                         | Excel uploads                   | ❌                |

**13 متطلب غير موثَّق** — مشغّل جديد سيكتشفها بالتجربة والفشل.

### 1.2 متطلبات Cron مخفية

`SchedulerRuntimeService` يُطلق jobs عبر `exec(PHP_BINARY . ' ' . $scriptPath)`. هذا يعني:

- يجب تشغيل endpoint الـ Scheduler يدوياً أو عبر cron خارجي
- لا يوجد في الكود توضيح للتكرار المطلوب (كل دقيقة؟ كل 5 دقائق؟)
- لا يوجد Supervisor أو Systemd unit file

### 1.3 افتراضات الشبكة

النظام يفترض:

- قاعدة البيانات على نفس الجهاز أو شبكة محلية (TCP latency < 1ms)
- لا CDN، لا load balancer، لا reverse proxy
- `sslmode = 'prefer'` → SSL اختياري

---

## المرحلة الثانية — مرونة البنية التحتية

### 2.1 إعادة تشغيل قاعدة البيانات أثناء تنفيذ طلب

**السيناريو:** مستخدم يُنفِّذ batch extend على 200 ضمان. بعد معالجة 50 ضمان، PostgreSQL تُعاد تشغيلها.

**المسار الفعلي:**

```
خطوة 1: $this->db->beginTransaction() ← يعمل (PDO singleton حي)
خطوة 2: DB restart يكسر TCP connection
خطوة 3: $this->db->prepare() ← PDOException: "server closed the connection"
خطوة 4: catch Throwable → $this->db->rollBack() ← PDOException ثانية
خطوة 5: Exception في catch block → PHP Fatal
خطوة 6: PHP-FPM worker يموت
```

**النتيجة:**

- الـ 149 ضمان الباقية: لم تُعالَج
- الـ 50 التي commit-ها: موجودة في DB
- PHP-FPM worker: ميت حتى يُعاد تشغيل FPM
- `self::$instance !== null`: PDO مكسور للـ requests اللاحقة من نفس worker

**آلية التعافي؟ لا توجد.** يجب إعادة تشغيل PHP-FPM يدوياً.

### 2.2 Filesystem يصبح للقراءة فقط

`Settings::save()` تُنفِّذ `file_put_contents($path, $encoded, LOCK_EX)`.
إذا كان القرص للقراءة فقط:

- `file_put_contents` يُرجع `false`
- الكود **لا يتحقق** من نتيجة `file_put_contents`:

```php
// Settings.php - لا يوجد:
$result = file_put_contents(...);
if ($result === false) throw new RuntimeException(...);
```

**النتيجة:** حفظ الإعدادات "ينجح" في الواجهة لكن التغييرات لم تُحفَظ. صامت تماماً.

### 2.3 استنزاف Connection Pool

مع PHP-FPM بـ 50 worker وكل worker يحتفظ بـ PDO connection دائم:

- 50 connection دائمة في PostgreSQL
- Battery of concurrent requests = 50 × N queries زمنياً
- `max_connections` PostgreSQL = 100 افتراضياً
- مع traffic بسيط + admin tools + monitoring = يُمكن بلوغ 100 بسرعة

إذا استُنزف pool: كل `new PDO(...)` يُلقي Exception → النظام يموت بالكامل.

### 2.4 انقطاع شبكة مؤقت لـ DB

PDO singleton مُجمَّد على connection قديمة. عند عودة الشبكة:

- لا يوجد `reconnect()` في الكود
- لا يوجد ping/keepalive
- `$this->db->query(...)` يُلقي Exception
- النظام يظل معطوباً حتى PHP-FPM restart

---

## المرحلة الثالثة — المراقبة والرصد

### 3.1 جرد نظام التسجيل

| المصدر                    | الأداة            | المستوى   |
| ------------------------- | ----------------- | --------- |
| `TimelineRecorder`        | `error_log()`     | غير هيكلي |
| `SmartProcessingService`  | `error_log()`     | غير هيكلي |
| `BreakGlassService`       | `error_log()`     | غير هيكلي |
| `BatchService`            | `Logger::info()`  | هيكلي ✅  |
| `SchedulerRuntimeService` | `Logger::error()` | هيكلي ✅  |
| `_bootstrap.php`          | `error_log()`     | غير هيكلي |

**50%+ من السجلات**: `error_log()` مباشر → يذهب لـ PHP error log بدون:

- severity level قابل للتصفية
- request correlation ID
- structured JSON format

### 3.2 قدرة الإجابة على الأسئلة التشغيلية

| السؤال                | هل يمكن الإجابة؟ | الأداة                                           |
| --------------------- | ---------------- | ------------------------------------------------ |
| ما الطلبات التي تفشل؟ | جزئياً           | `X-Request-Id` موجود لكن سجلات غير هيكلية        |
| أي مستخدم سبّب الخطأ؟ | جزئياً           | `actor_user_id` في timeline لكن لا في error logs |
| كم تستغرق العمليات؟   | ❌               | لا timing metrics                                |
| أي الاستعلامات بطيئة؟ | ❌               | لا query timing، لا slow query log من التطبيق    |
| هل Scheduler يعمل؟    | جزئياً           | dead_letters موجودة لكن لا dashboard             |
| كم استيراد نجح اليوم؟ | ❌               | لا operational metrics aggregated                |

### 3.3 غياب Distributed Tracing

لا يوجد trace ID يربط:

- HTTP request → DB queries → Timeline events → Notifications

كل حدث مسجَّل بشكل مستقل. إعادة بناء "ماذا حدث في هذا الطلب" = impossible.

---

## المرحلة الرابعة — السلامة التشغيلية

### 4.1 حماية من العمليات الجماعية الخاطئة

| العملية                    | Confirmation؟        | Reversible؟       | Audit Log؟ |
| -------------------------- | -------------------- | ----------------- | ---------- |
| batch extend 5000 ضمان     | ❌ لا                | ✅ (undo request) | ✅         |
| batch release كامل         | ❌ لا                | ❌ لا             | ✅         |
| حذف مورد (cascade)         | ❌ لا                | ❌ لا             | ❌         |
| تغيير MATCH_AUTO_THRESHOLD | ❌ لا                | ✅ (يدوياً)       | ✅         |
| break glass override       | ✅ (reason + ticket) | N/A               | ✅         |
| تطبيق migration            | ❌ لا                | ❌ لا             | ❌         |

**الأخطر:** `batch release` جماعي بدون confirmation، وغير قابل للتراجع، لضمانات مالية حقيقية.

### 4.2 حماية من الإجراءات غير القابلة للتراجع

`UndoRequestService` لا يُغطي:

- `workflow_rejection` (رفض مرحلة workflow)
- حذف مورد أو بنك
- تغيير الإعدادات الجوهرية
- تطبيق migration

**الفجوة الأكبر:** workflow rejection لا يمكن التراجع عنه إلا بإعادة تمرير الضمان بكامل مراحله.

### 4.3 غياب Dry-Run Mode

لا توجد طريقة لمستخدم أن يُشغِّل `batch extend` في "محاكاة فقط" لرؤية ماذا سيتأثر قبل التنفيذ الفعلي.

---

## المرحلة الخامسة — سلامة النشر

### 5.1 هل يمكن نشر تحديث بدون إيقاف النظام؟ لا.

**المشاكل:**

1. **لا migration version tracking** — لا يمكن معرفة أي migrations طُبِّقت
2. **لا idempotency في migrations** — تطبيق migration مرتين = أخطاء مكررة
3. **كل migrations بدون rollback** — فشل migration في منتصفه = يجب تطبيق migration إصلاحية يدوياً
4. **migrations تأخذ Table Lock** — في الإنتاج، `ALTER COLUMN TYPE` = جدول مغلق لدقائق

**الإجراء الواقعي للنشر:**

```
1. إيقاف النظام كاملاً
2. أخذ snapshot يدوي
3. تطبيق migrations يدوياً بالترتيب
4. التحقق من النتائج يدوياً
5. إعادة تشغيل النظام
```

هذا يعني **downtime مُخطَّط** في كل تحديث. لا zero-downtime deployment ممكن.

### 5.2 تعارض schema بين النسخ

إذا نُشِرت نسخة جديدة من الكود قبل تطبيق migration:

- مثال: نسخة V2 تتوقع عمود `new_column` لم يُضَف بعد
- `PDOStatement::execute()` يُلقي `Column "new_column" does not exist`
- **النظام يموت فوراً** لكل طلب يصل لهذا الكود

لا feature flags للتحكم في تفعيل كود جديد بعد migration، لا backward-compatible writes.

---

## المرحلة السادسة — نمو البيانات طويل الأمد

### 6.1 تقدير حجم البيانات

**افتراض:** 10,000 ضمان جديد سنوياً، كل ضمان به 15 حدث timeline، 20 إشعار.

| الجدول                      | بعد سنة | بعد 5 سنوات | بعد 10 سنوات          |
| --------------------------- | ------- | ----------- | --------------------- |
| `guarantees`                | 10K     | 50K         | 100K                  |
| `guarantee_decisions`       | 10K     | 50K         | 100K                  |
| `guarantee_history`         | 150K    | 750K        | 1.5M                  |
| `notifications`             | 200K    | 1M          | 2M                    |
| `login_rate_limits`         | ~50K    | ~250K       | ~500K حتى إزالة قديمة |
| `scheduler_job_runs`        | ~8K     | ~40K        | ~80K                  |
| `guarantee_history_archive` | 0       | 0           | 0 (جدول ميت)          |

**النقطة الحرجة:** بدون partitioning في `guarantee_history`:

- بعد 5 سنوات: `FULL SCAN` على 750K صف لكل استعلام timeline بدون index مناسب
- `guarantee_history` يستخدم `JSON` لـ `patch_data` و`anchor_snapshot` — لا يمكن فهرسة محتواها
- كل `guarantee_history` صف يحتوي HTML خطاب كامل في `letter_snapshot` — قد تكون 10-50KB/صف

**تقدير الحجم:** 750K صف × 20KB/صف = **~15GB** في عمود واحد بعد 5 سنوات.

### 6.2 نقاط الانكسار المعمارية

| الحجم          | المشكلة                                               | الأثر                           |
| -------------- | ----------------------------------------------------- | ------------------------------- |
| **10K ضمان**   | مقبول مع تحسينات الـ index                            | أداء جيد                        |
| **50K ضمان**   | `guarantee_history` بدون partitioning                 | استعلامات timeline بطيئة (2-5s) |
| **100K ضمان**  | `raw_data JSON TEXT` بدون GIN index                   | batch queries بطيئة جداً        |
| **500K+ ضمان** | `FuzzySignalFeeder` يجلب 600 candidates من قاعدة ضخمة | fuzzy search يستنزف             |
| **1M ضمان**    | البنية الحالية لا تستحمل                              | انهيار كامل بدون rearchitecting |

---

## المرحلة السابعة — القدرة على التعافي

### 7.1 تعافي بعد تلف جزئي في البيانات

**سيناريو:** بعض صفوف `guarantee_history` تُحذف بالخطأ (human error في `DELETE` query مباشرة).

**إمكانية التعافي؟**

- إذا حُذفت `anchor_snapshot` rows: إعادة بناء الحالة التاريخية مستحيلة
- إذا حُذفت `patch_data` rows: سلسلة الـ patches مكسورة → بعض الحالات التاريخية لا يمكن إعادة بنائها
- لا أداة في النظام لاكتشاف corruption في chain الـ patches أو إصلاحها

### 7.2 إعادة بناء البيانات المشتقة

البيانات المشتقة التي يمكن إعادة حسابها:

- `supplier_learning_cache` → إعادة البناء من `guarantee_decisions` ممكنة نظرياً
- `supplier_alternative_names.usage_count` → إعادة الحساب ممكنة

البيانات التي **لا يمكن** إعادة بنائها:

- `guarantee_history.letter_snapshot` (HTML خطاب وقت التنفيذ) → ضائع نهائياً إذا حُذف
- `break_glass_events` → ضائع إذا حُذف (لكن لا delete في الكود)

### 7.3 إعادة تشغيل الأحداث (Event Replay)

لا يوجد في النظام آلية لـ "إعادة تشغيل" أحداث من نقطة معينة. `guarantee_history` للقراءة فقط من منظور الأداء. لا يمكن "Replay from event 500 for guarantee X".

---

## المرحلة الثامنة — التعقيد التشغيلي

### 8.1 حالات النظام القابلة للتواجد في نفس الوقت

مشغّل عملي يحتاج فهم 47+ حالة متزامنة:

```
Guarantee: status × workflow_step × is_locked × active_action
= 3 × 6 × 2 × 4 = 144 حالة نظرية
(منها ~20 حالة شرعية بعد constraints)
```

إضافةً لـ:

- `undo_requests` لها 4 حالات مستقلة
- `scheduler_job_runs` لها 5 حالات
- `break_glass_events` نشطة أو منتهية
- `batch_metadata` مفتوحة أو مغلقة

**عبء معرفي ثقيل** للمشغّل الجديد الذي يُشخِّص مشكلة.

### 8.2 صعوبة تفسير رسائل الخطأ

| رسالة الخطأ             | معناها الحقيقي                     | يفهمها المشغّل؟ |
| ----------------------- | ---------------------------------- | --------------- |
| `"غير جاهز"`            | status ≠ ready أو supplier_id NULL | ❌ غامض         |
| `"Permission Denied"`   | أي permission من 43                | ❌ لا يحدد أيها |
| `"حدث خطأ داخلي"`       | أي exception 5xx                   | ❌ لا معلومة    |
| `"تعذر إنشاء Snapshot"` | TimelineRecorder فشل               | ❌ لا سبب       |

### 8.3 متطلبات التدريب

لتشغيل النظام بكفاءة، مشغّل يحتاج فهم:

- 6 مراحل workflow ومتطلبات كل منها
- الفرق بين batch operations وIndividual operations
- متى يستخدم Break Glass وكيف
- كيفية تفسير confidence scores
- متى يلجأ لـ undo وحدوده
- كيفية قراءة timeline لتشخيص مشكلة

**تقدير زمن التدريب الفعلي:** 2-4 أسابيع للوصول لمستوى كفاءة تشغيلية.

---

## المرحلة التاسعة — المخاطر التنظيمية

### 9.1 مخاطر تمركز المعرفة

**الدليل:** `index.php` (118KB)، `views/settings.php` (105KB)، 28 سكريبت CLI متخصص، نظام Hybrid Timeline مُصمَّم بدقة عالية.

**التقدير:**

- عدد الأشخاص القادرين على صيانة النظام بأمان اليوم: **1-2 شخص**
- زمن تأهيل مطور جديد ليفهم النظام: **3-6 أشهر**
- القدرة على التشغيل إذا رحل المطور الرئيسي: **خطر عالٍ جداً**

### 9.2 عوامل تفاقم مخاطر الاعتماد

| العامل                                    | الوصف                                  |
| ----------------------------------------- | -------------------------------------- |
| `index.php` 118KB                         | لا يمكن قراءته بدون خبرة سابقة بالنظام |
| ADR (Architecture Decision Records) غائبة | لا توجد وثائق لماذا اتُّخِذ أي قرار    |
| `AuthorityFactory` و wiring معقد          | يتطلب فهم deep للـ Learning subsystem  |
| DB triggers بـ PL/pgSQL                   | يتطلب خبرة PostgreSQL متخصصة           |
| Hybrid Timeline Ledger                    | تصميم مخصص — لا توثيق خارج التعليقات   |

### 9.3 مخاطر المطوّر الواحد

إذا رحل المطور الرئيسي:

- أي تغيير في `index.php` = **خطر عالٍ** بدون فهم كامل
- أي migration جديدة = خطر بدون معرفة trigger interactions
- أي تحديث لـ Learning system = خطر بدون فهم Authority Charter
- debug أي مشكلة إنتاج = ساعات من القراءة قبل التشخيص

---

## المرحلة العاشرة — حكم الجاهزية للإنتاج

---

### 10.1 درجة الجاهزية الكلية

```
┌─────────────────────────────────────────────────────────────┐
│         تقييم جاهزية WBGL للإنتاج المؤسسي                   │
├─────────────────────┬───────────────────────────────────────┤
│ منظور               │ الدرجة (من 100)                       │
├─────────────────────┼───────────────────────────────────────┤
│ منطق الأعمال        │ 82 / 100  ✅ قوي                      │
│ الحوكمة والتدقيق    │ 88 / 100  ✅ استثنائي                 │
│ أمن التطبيق         │ 71 / 100  ⚠️ مقبول مع ثغرات           │
│ أمن البنية التحتية  │ 28 / 100  🔴 ضعيف جداً               │
│ المرونة التشغيلية   │ 22 / 100  🔴 حرج                     │
│ المراقبة والرصد     │ 35 / 100  🔴 غير كافية               │
│ سلامة النشر         │ 18 / 100  🔴 خطير                    │
│ دورة حياة البيانات  │ 20 / 100  🔴 غائبة                   │
│ قابلية الاختبار     │ 15 / 100  🔴 شبه صفر                 │
│ قابلية الصيانة      │ 45 / 100  ⚠️ متدهورة                 │
├─────────────────────┼───────────────────────────────────────┤
│ الدرجة الكلية       │ 42 / 100  🔴 غير جاهز                │
└─────────────────────┴───────────────────────────────────────┘
```

---

### 10.2 أعلى 10 مخاطر إنتاجية (Top 10 Production Risks)

---

#### 🔴 المخاطرة #1 — خادم الإنتاج = PHP Built-in Development Server

**الخطورة:** حرجة — كارثية

**الوصف:**
النظام يُشغَّل بـ `php -S localhost:8181` عبر `wbgl_server.ps1`. PHP built-in server:

- Single-threaded (طلب واحد في كل مرة)
- لا TLS/HTTPS
- لا static file caching
- لا gzip compression
- يوقف معالجة الطلبات عند أي PHP fatal error
- وثائق PHP: "يجب عدم استخدامه على شبكة عامة"

**الأثر:** انتهاك كامل لمتطلبات الإنتاج المؤسسي.

**الإصلاح:** نشر على nginx + PHP-FPM أو Apache مع `.htaccess`.

---

#### 🔴 المخاطرة #2 — DB credentials في `settings.json` قابلة للوصول عبر HTTP

**الخطورة:** حرجة — اختراق فوري

**الدليل:** `server.php` يُرسل أي ملف في المشروع مباشرةً. `GET /storage/settings.json` يُرجع credentials كاملة.

**الأثر:** أي مستخدم أو bot يمكنه قراءة DB password.

**الإصلاح الفوري:**

1. نقل `storage/` خارج document root
2. إضافة `deny all` لمجلد storage في nginx/Apache
3. تشفير credentials أو استخدام environment variables

---

#### 🔴 المخاطرة #3 — DB restart = PHP-FPM crash دائم بدون تعافي

**الخطورة:** حرجة — outage كامل

**الدليل:** `Database::$instance` (static PDO singleton) يبقى مكسوراً بعد DB restart. لا reconnection logic.

**الأثر:** كل طلب يفشل بـ PDOException حتى PHP-FPM يُعاد تشغيله يدوياً.

**الإصلاح:** إضافة connection retry + reset عند اكتشاف stale connection، أو استخدام connection pooler مثل pg_bouncer.

---

#### 🔴 المخاطرة #4 — لا نسخ احتياطي لأي بيانات

**الخطورة:** حرجة — فقدان كامل للبيانات

**الدليل:** لا سكريبت `pg_dump` في المشروع. `/storage/database/backups/` فارغ.

**الأثر:** فشل القرص = فقدان جميع الضمانات والسجل الجنائي والتاريخ المالي — بشكل لا رجعة فيه.

**الإصلاح الفوري:** إعداد `pg_dump` يومي مع periodic verification + WAL archiving.

---

#### 🔴 المخاطرة #5 — `settings.json` قد يحوي credentials في Git history

**الخطورة:** حرجة — تسريب بيانات دائم

**الدليل:** `.gitignore` لا يستثني `storage/settings.json`. أي `git push` سابق تضمَّن credentials = مُتاح للأبد في Git history.

**الأثر:** حتى لو تم حذف الملف من الشجرة الحالية، `git show <hash>:storage/settings.json` يُرجع credentials.

**الإصلاح:**

1. إضافة `/storage/settings.json` لـ `.gitignore` فوراً
2. تشغيل `git filter-branch` أو BFG Repo-Cleaner لمسح التاريخ
3. تغيير جميع credentials مباشرة

---

#### 🔴 المخاطرة #6 — لا migration version tracking + لا rollback

**الخطورة:** عالية — outage أثناء deployment

**الدليل:** 33 migration بدون `down()` script. لا جدول `migrations` في DB.

**الأثر:** فشل migration في منتصفها أثناء النشر = Schema في حالة وسطية غير متسقة. لا طريقة للتراجع.

**الإصلاح:** اعتماد migration framework مثل Phinx أو Flyway، أو على الأقل إضافة جدول `schema_migrations`.

---

#### 🔴 المخاطرة #7 — CSP `unsafe-inline` يُلغي حماية XSS

**الخطورة:** عالية — XSS attack vector مفتوح

**الدليل:** `SecurityHeaders.php:34`: `"script-src 'self' 'unsafe-inline'"`.

**الأثر:** أي حقل يعرض بيانات مستخدم (supplier name, locked_reason, batch_name) هو نقطة XSS محتملة.

**الإصلاح:** استبدال `unsafe-inline` بـ nonce-based CSP.

---

#### 🟡 المخاطرة #8 — Batch operations يُنفِّذ N transactions بدون معالجة الفشل الجزئي

**الخطورة:** عالية — حالات تشغيلية ناقصة

**الدليل:** `BatchService.extendBatch()` — حلقة N × `beginTransaction/commit`. فشل ضمان واحد يكسر الحلقة.

**الأثر:** دفعة ممتدة جزئياً بدون علم المستخدم. ضمانات تُفوَّت بصمت.

**الإصلاح:** استخدام Savepoint-based approach أو report كامل بدلاً من throw + break.

---

#### 🟡 المخاطرة #9 — `Guard::hasOrLegacy(default=true)` — صلاحيات ضمنية

**الخطورة:** عالية — privilege escalation

**الدليل:** `Guard.php:36`: أي permission جديدة غير مسجَّلة في DB تُعطى لكل المستخدمين ضمنياً.

**الأثر:** نافذة بين كتابة كود جديد وتشغيل migration = privilege escalation لكل المستخدمين.

**الإصلاح:** تغيير `$legacyDefault = false` وتطبيق migrations قبل نشر الكود.

---

#### 🟡 المخاطرة #10 — نمو `guarantee_history` غير محدود + لا archiving

**الخطورة:** متوسطة-عالية — degradation تدريجي

**الدليل:** لا `DELETE` policy، لا partitioning، `letter_snapshot` قد تكون 10-50KB/صف.

**الأثر:** بعد 5+ سنوات: استعلامات timeline تبطؤ، storage يُستنزف، performance يتدهور بشكل مستمر.

**الإصلاح:** تطبيق Table Partitioning بتاريخ + أرشفة `letter_snapshot` لتخزين خارجي.

---

### 10.3 المشاكل الحاجبة (Blocking Issues)

قبل أي نشر إنتاجي **يجب** معالجة:

| #   | المشكلة                                               | الأثر إذا لم تُعالَج       |
| --- | ----------------------------------------------------- | -------------------------- |
| 1   | إعداد web server حقيقي (nginx/Apache)                 | النظام لا يصمد تحت الضغط   |
| 2   | حماية `storage/` من HTTP access                       | تسريب credentials فوري     |
| 3   | إضافة `settings.json` لـ `.gitignore` + تنظيف history | credentials في Git للأبد   |
| 4   | إعداد backup يومي بـ `pg_dump`                        | فقدان كارثي لا رجعة فيه    |
| 5   | إصلاح PDO reconnection بعد DB restart                 | outage كامل بعد أي restart |

### 10.4 المخاطر التشغيلية المتوسطة

تُعالَج خلال أول 3 أشهر:

| #   | المشكلة                                   | الأثر                   |
| --- | ----------------------------------------- | ----------------------- |
| 6   | نظام Migration مع version tracking        | deployments خطرة        |
| 7   | إصلاح `Guard::hasOrLegacy(default=false)` | privilege escalation    |
| 8   | Rate limiting على Token API               | abuse vector            |
| 9   | sslmode → require                         | plaintext DB traffic    |
| 10  | data lifecycle policy للجداول المتنامية   | performance degradation |

### 10.5 التحسينات الموصى بها (Non-blocking)

| المجال  | التحسين                                   |
| ------- | ----------------------------------------- |
| CSP     | استبدال unsafe-inline بـ nonce            |
| Logging | توحيد جميع logs عبر `Logger::*`           |
| Testing | إضافة unit tests حقيقية بـ mocking        |
| Batch   | إضافة dry-run mode                        |
| UX      | إضافة loading indicators للعمليات الطويلة |

---

### 10.6 الحكم النهائي

```
┌───────────────────────────────────────────────────────────────┐
│                   حكم لجنة المراجعة                           │
├───────────────────────────────────────────────────────────────┤
│                                                               │
│   WBGL في وضعه الحالي                                         │
│                                                               │
│   🔴 غير مؤهَّل للتشغيل الإنتاجي المؤسسي مستحيل              │
│                                                               │
│   السبب الرئيسي:                                             │
│   البنية التحتية التشغيلية (نشر، backup، monitoring،         │
│   resilience) غير موجودة، وليست ضعيفة — غير موجودة.         │
│                                                               │
│   ما هو ممتاز في WBGL:                                       │
│   • منطق الأعمال ومسار الضمان                                │
│   • الحوكمة والسجل الجنائي                                   │
│   • حوادث Break Glass والـ Undo chain                        │
│   • DB-level Guards والـ Triggers                            │
│                                                               │
│   ما يمنع الجاهزية الإنتاجية:                               │
│   • خادم تطوير بدلاً من إنتاج                               │
│   • credentials مكشوفة عبر HTTP و Git                        │
│   • صفر backup                                               │
│   • لا recovery بعد DB restart                               │
│   • لا migration governance                                  │
│   • لا data lifecycle                                        │
│                                                               │
│   التوصية:                                                   │
│   خطة إصلاح من 3 مراحل خلال 90 يوماً قبل الإنتاج            │
│                                                               │
└───────────────────────────────────────────────────────────────┘
```

### 10.7 خطة الإصلاح الموصى بها (90 يوماً)

**المرحلة الأولى — فورية (أسبوعان):**

1. تثبيت nginx + PHP-FPM
2. حماية `storage/` من HTTP access
3. إعداد `pg_dump` backup cron
4. إضافة `settings.json` لـ `.gitignore` + تغيير credentials
5. إضافة reconnection logic في `Database::connect()`

**المرحلة الثانية — شهر واحد:** 6. اعتماد migration framework مع version tracking 7. إصلاح `Guard::hasOrLegacy(default=false)` 8. إضافة Token Rate Limiting 9. تغيير sslmode إلى require 10. إضافة `/api/health` endpoint

**المرحلة الثالثة — ثلاثة أشهر:** 11. data lifecycle policy (DELETE/archive للبيانات القديمة) 12. توحيد Logging عبر structured logger 13. إضافة monitoring dashboard أساسي 14. كتابة runbook تشغيلي (backup restore, rollback procedures) 15. تفكيك `index.php` تدريجياً

---

_نهاية تقرير جاهزية الإنتاج المؤسسي_
_"النظام مُصمَّم بمهارة — لكنه لم يُجهَّز للتشغيل. الفجوة بين الاثنين هي مسافة 90 يوماً من العمل الهندسي المُركَّز."_
