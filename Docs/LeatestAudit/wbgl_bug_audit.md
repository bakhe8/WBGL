# تقرير التدقيق المعرفي الشامل — الأخطاء والكود الميت والمخاطر

## نظام WBGL — تحليل دقيق من داخل الكود

> **المنهجية:** تحليل مباشر من الكود المصدري مع تتبع التنفيذ الذهني الكامل.
> كل نتيجة مرتبطة بملف وسطر محدد. لا افتراضات نظرية.
>
> **التاريخ:** 2026-03-07 | **المدقق:** Senior Architect AI

---

## الفئة الأولى: أخطاء فعلية نشطة (Actual Bugs)

---

### 🔴 BUG-001 — خطأ حرج: `levenshtein()` لا تدعم النصوص متعددة البايت (العربية)

**الملف:** `app/Services/Learning/Feeders/FuzzySignalFeeder.php` السطر 149

**الكود:**

```php
$distance = levenshtein($str1, $str2);
```

**المشكلة:**
دالة `levenshtein()` في PHP تعمل على **مستوى البايت (byte)** وليس على مستوى **الحرف (character)**. في اللغة العربية، كل حرف مُرمَّز بـ 2–4 بايت في UTF-8. هذا يعني:

- `levenshtein("شركة", "شركه")` تُحسب المسافة بين 8–12 بايت وليس بين 4 أحرف
- تنتج مسافة مُضخَّمة بمعامل 2–4x
- `$maxLength` تُحسب أيضاً بـ `mb_strlen()` (سطر 143) وهو الصحيح لعدد الأحرف

**النتيجة الفعلية:**
الصيغة `similarity = 1 - (distance / maxLength)` ستُنتج قيماً **سالبة** لكثير من أزواج الكلمات العربية لأن:

- `distance` (بالبايت) > `maxLength` (بالأحرف)
- مثل: `levenshtein("شركة", "شركة محدودة")` قد يُنتج 16 بينما `mb_strlen("شركة محدودة") = 11`
- الناتج: `1 - (16/11) = -0.45` → بعد `max(0, ...)`: القيمة تُصبح 0.0

**الأثر:** جميع المطابقات الفازية للنصوص العربية ستُرجع تشابهاً اقرب إلى الصفر → تفشل حتى المطابقات القوية → النظام يتجاهل موردين شبه متطابقين.

**الإصلاح:** استخدام `similar_text()` المبني للعمل مع UTF-8، أو تحويل النص إلى مصفوفة أحرف ثم حساب Levenshtein يدوياً، أو استخدام مكتبة متخصصة.

---

### 🔴 BUG-002 — تكرار منطق `identifyPrimarySignal()` مع نتائج مختلفة

**الملفان:**

- `app/Services/Learning/UnifiedLearningAuthority.php` السطر 222-227
- `app/Services/Learning/ConfidenceCalculatorV2.php` السطر 143-157

**المشكلة:**
كلا الملفان يحتويان على دالة `identifyPrimarySignal()` بمنطق **مختلف تماماً**:

في `UnifiedLearningAuthority`:

```php
private function identifyPrimarySignal(array $signals): SignalDTO
{
    // For now, return first (will be refined)
    return $signals[0]; // ← يُرجع أول إشارة بغض النظر عن قوتها
}
```

في `ConfidenceCalculatorV2`:

```php
private function identifyPrimarySignal(array $signals): SignalDTO
{
    foreach ($signals as $signal) {
        $baseScore = $this->baseScores[$signal->signal_type] ?? 0;
        if ($baseScore > $highestBaseScore) { // ← يُرجع الإشارة الأعلى درجةً
```

**الأثر:** `UnifiedLearningAuthority` يُسجّل `primary_source` من أول إشارة في المصفوفة (التي قد تكون الأضعف)، بينما `ConfidenceCalculatorV2` يحسب الثقة بناءً على الإشارة الأعلى. نتيجة: **حقل `primary_source` في استجابة API يُظهر مصدراً مختلفاً عمّا بُني عليه حساب الثقة** — هذا يُضلّل المراجعين البشريين.

التعليق "For now, return first (will be refined)" يؤكد أن هذا كود مؤقت **نُسي في الإنتاج**.

---

### 🔴 BUG-003 — خطأ في استعلام `OPERATIONAL_COUNT_ARITHMETIC`

**الملف:** `app/Scripts/data-integrity-check.php` السطر 328

**الكود الخاطئ:**

```sql
COUNT(*) FILTER (WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
    AND (d.id IS NULL OR d.status = 'pending')) AS pending_total
```

**المشكلة:**
الاستعلام يستخدم `d.id IS NULL OR d.status = 'pending'`. الجزء `d.id IS NULL` يُمثّل ضمانات **بدون قرار decision** (LEFT JOIN)، وهؤلاء يُدرجون ضمن `pending_total`. لكن فعلياً:

- ضمان بدون decision ليس `pending` — إنه **بيانات ناقصة**
- البنية التحتية الرياضية ستمر حتى لو كانت البيانات فاسدة

**الأثر:** الفحص `OPERATIONAL_COUNT_ARITHMETIC` يمرر (status = OK) حتى لو كانت هناك ضمانات بدون decisions، لأنها تُحتسب كـ pending، مما يُعطي وهماً **كاذباً** بسلامة البيانات.

**الإصلاح:**

```sql
-- الصحيح:
COUNT(*) FILTER (WHERE d.id IS NOT NULL
    AND (d.is_locked IS NULL OR d.is_locked = FALSE)
    AND d.status = 'pending') AS pending_total
```

---

### 🟡 BUG-004 — N+1 Query في `AnchorSignalFeeder`

**الملف:** `app/Services/Learning/Feeders/AnchorSignalFeeder.php` السطر 82-92

**الكود:**

```php
foreach ($anchors as $anchor) {
    $matchCount = $this->supplierRepo->countSuppliersWithAnchor($anchor); // ← N استعلام
    $frequencies[$anchor] = $matchCount;
}
// ثم...
foreach ($anchors as $anchor) {
    $matchingSuppliers = $this->supplierRepo->findByAnchor($anchor); // ← N استعلام ثانٍ
```

**المشكلة:**
لكل `anchor` في المصفوفة، يُنفَّذ استعلامان منفصلان إلى الـ DB. إذا أنتج `extractAnchors()` ثمانية مداخل نصية — يكون لديك **16 استعلام متسلسل** في طلب واحد.

**الأثر:** تدهور أداء مباشر في كل عملية تطابق مورد.

---

### 🟡 BUG-005 — `Settings::get()` تقرأ الملف من القرص في كل استدعاء

**الملف:** `app/Support/Settings.php` السطر 146-150

**الكود:**

```php
public function get(string $key, mixed $default = null): mixed
{
    $all = $this->all(); // ← يستدعي loadPrimary() → file_get_contents() في كل مرة
    return $all[$key] ?? $default;
}
```

**المشكلة:**
`all()` تستدعي `loadPrimary()` التي تُنفّذ `file_get_contents($this->path)` في كل طلب. `ConfidenceCalculatorV2` وحدها تستدعي `settings->get()` **9 مرات** في `loadBaseScores()` + مرات إضافية في `assignLevel()` و`meetsDisplayThreshold()`. في طلب يُعالج 50 مورداً:

- 50 × (9 + 2 + 1) = **600 قراءة ملف** من القرص لإعدادات لم تتغير

**الإصلاح:** إضافة static cache داخل `all()`.

---

### 🟡 BUG-006 — `ConfidenceCalculatorV2::$baseScores` تُعلَن بعد استخدامها

**الملف:** `app/Services/Learning/ConfidenceCalculatorV2.php`

**الترتيب في الملف:**

```php
// السطر 26: الـ constructor يستدعي loadBaseScores()
public function __construct(...) {
    ...
    $this->loadBaseScores(); // ← يُسند $this->baseScores
}

// السطر 53: إعلان الخاصية يأتي بعد ذلك
private array $baseScores = [];
```

**المشكلة:** في PHP، الخاصية `$baseScores` تُعلَن وتُهيَّأ في نهاية الملف، لكن `loadBaseScores()` تُسنَد إليها من الـ constructor. هذا يعمل في PHP لأن PHP تُحمّل الـ class كاملةً قبل إنشاء الكائن، لكن **الترتيب مُضلّل للمطورين** ويخالف الممارسات الجيدة. الأخطر: إذا نُسي سهواً استدعاء `loadBaseScores()`، ستكون `$baseScores = []` وستُرجع دالة `getBaseScore()` القيمة الافتراضية 40 لكل الأنواع.

---

### 🟡 BUG-007 — `AnchorSignalFeeder::determineSignalType()` vs `calculateAnchorStrength()` تناقض منطقي

**الملف:** `app/Services/Learning/Feeders/AnchorSignalFeeder.php` السطر 100-129

**المشكلة:**

```php
private function determineSignalType(int $frequency): string
{
    if ($frequency <= 2) return 'entity_anchor_unique'; // ← 1 أو 2 → نفس النوع
    else return 'entity_anchor_generic';
}

private function calculateAnchorStrength(int $frequency): float
{
    if ($frequency === 1) return 1.0;      // ← 1 → قوة 1.0
    elseif ($frequency === 2) return 0.9;  // ← 2 → قوة 0.9
    elseif ($frequency <= 5) return 0.7;   // ← 3,4,5 → قوة 0.7 (لكن النوع 'generic'!)
    else return 0.5;
}
```

**التناقض:** `frequency = 2` يُعطي نوع `entity_anchor_unique` (مميز)، لكن `frequency = 3` يُعطي `entity_anchor_generic` (شائع) مع قوة 0.7. في `ConfidenceCalculatorV2::baseScores`: `entity_anchor_unique = 90` vs `entity_anchor_generic = 75`.

**الأثر:** مورد يظهر في 2 ضمانات يحصل على 90 نقطة أساس، ومورد يظهر في 3 ضمانات يحصل على 75 فجأة — **قفزة عكسية غير منطقية**.

---

### 🟡 BUG-008 — `Guard::protect()` تخرج المستخدم بـ `exit` بدون تنظيف

**الملف:** `app/Support/Guard.php` السطر 124-136

**الكود:**

```php
public static function protect(string $permissionSlug): void
{
    if (!self::has($permissionSlug)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([...]);
        exit; // ← Hard exit
    }
}
```

**المشكلة:**
`exit` يقاطع تنفيذ PHP فوراً. أي cleanup code (إغلاق connections، تسجيل events، إنهاء transactions) لن يُنفَّذ. في بعض endpoints قد تكون transaction مفتوحة عند استدعاء `protect()`. المقارنة: `wbgl_api_fail()` في `_bootstrap.php` أيضاً تستخدم `exit`، لكن هي تُسجّل الـ audit log أولاً. `Guard::protect()` لا تُسجّل أي شيء.

**الأثر:** 403 من `Guard::protect()` لا يُسجَّل في `AuditTrailService` — ثغرة في التتبع الجنائي.

---

### 🟡 BUG-009 — `FuzzySignalFeeder::hasDistinctiveKeywordMatch()` تُرجع `true` عند عدم وجود كلمات مميزة

**الملف:** `app/Services/Learning/Feeders/FuzzySignalFeeder.php` السطر 199-202

**الكود:**

```php
if (empty($inputDistinctive) || empty($supplierDistinctive)) {
    return true; // ← اسماء بدون كلمات مميزة → تُعتبر مطابقة
}
```

**المشكلة:**
إذا كان اسم المورد المدخل مكوناً بالكامل من كلمات شائعة (مثل "شركة دولية محدودة")، ونفس الشيء للمورد في قاعدة البيانات — فالدالة تُرجع `true` حتى لو لم يكن هناك أي تطابق فعلي في الكلمات المميزة. هذا يُمكّن من ظهور مطابقات فازية **خاطئة تماماً** لأسماء شائعة.

---

### 🟠 BUG-010 — `active_action_set_at` نوعه `TEXT` في بعض البيئات — هشاشة Schema

**الملف:** `database/migrations/20260305_000026_enforce_workflow_transition_guards.sql`

**الكود في الـ migration:**

```sql
IF active_action_set_at_udt IN ('timestamp', 'timestamptz') THEN
    -- استعلام للـ TIMESTAMP
ELSE
    -- استعلام للـ TEXT
```

**المشكلة:**
نفس الـ migration يتصرف **بشكل مختلف** حسب بيئة التشغيل. هذا يعني:

- في بيئة A: `active_action_set_at` = TIMESTAMP → يُخزَّن كـ datetime صحيح
- في بيئة B: `active_action_set_at` = TEXT → يُخزَّن كـ string نصي

لا توجد migration تُوحّد النوع. أي محاولة للمقارنة الزمنية (`WHERE active_action_set_at > NOW()`) ستفشل في بيئة TEXT.

---

## الفئة الثانية: كود ميت وأجزاء غير مكتملة (Dead Code & Stubs)

---

### 💀 DEAD-001 — `identifyPrimarySignal()` في `UnifiedLearningAuthority` — كود مؤقت منسي

**الملف:** `app/Services/Learning/UnifiedLearningAuthority.php` السطر 225-226

```php
// For now, return first (will be refined)
return $signals[0];
```

**الحالة:** كود بـ تعليق "For now" في الإنتاج. الدالة موجودة في `ConfidenceCalculatorV2` بمنطق حقيقي ومختلف. هذه النسخة **لا قيمة لها وتُضلّل**.

---

### 💀 DEAD-002 — `detectAmbiguity()` لا يُستخدم نتيجتها

**الملف:** `app/Services/Learning/UnifiedLearningAuthority.php` السطر 209 و235-243

```php
'is_ambiguous' => $this->detectAmbiguity($signals), // ← يُحسب
```

لكن `is_ambiguous` لا تُستخدم في `SuggestionFormatter`, ولا في أي مكان آخر لاتخاذ قرار. البيانات تُحسب وتُنقل لكن **لا تُفعّل أي سلوك**.

---

### 💀 DEAD-003 — Targeted Negative Learning — كود Stub معلّق

**الملف:** `app/Services/SmartProcessingService.php`

```php
error_log("[Authority] Trust override - penalty needed for blocking alias");
// ← لا يحدث شيء. التعليق يصف ميزة غير مُنفَّذة.
```

**الحالة:** `TrustDecision::shouldApplyTargetedPenalty()` تُشير إلى حالة تحتاج معالجة، لكن الكود يسجّل error_log ويُهمل. **التعلم السلبي الموجَّه غير موجود فعلياً** رغم وجوده في التصميم.

---

### 💀 DEAD-004 — جدول `guarantee_history_archive` — جدول يتيم بلا كود

**الملف:** `database/migrations/20260227_000014_create_guarantee_history_archive.sql`

**الحالة:**
الـ migration يُنشئ جدول `guarantee_history_archive` لـ "أرشفة" أحداث التاريخ القديمة. لكن بعد البحث في كل الكود:

- لا يوجد `INSERT INTO guarantee_history_archive` في أي ملف PHP
- لا يوجد API endpoint يقرأ منه
- لا يوجد scheduler job يُنقل إليه البيانات

الجدول **موجود في الـ Schema ولا يُستخدم أبداً** — Schema bloat حقيقي.

---

### 💀 DEAD-005 — `api/parse-paste.php` (V1) — endpoint قديم مُبقى بجوار V2

**الملفات:**

- `api/parse-paste.php` (V1)
- `api/parse-paste-v2.php` (V2)

**الحالة:**
كلا الملفين موجودان. V2 يُشار إليه كـ "النسخة الحالية" في التعليقات. V1 مبقى لأسباب "التوافق مع الإصدارات القديمة" لكن لا يوجد توثيق لمن يستخدم V1 حالياً. في غياب هذا التوثيق، V1 هو **كود ميت محتمل** يضاعف مسطح الهجوم.

---

### 💀 DEAD-006 — `WorkflowService::signaturesRequired()` — stub يُرجع دائماً 1

**الملف:** `app/Services/WorkflowService.php`

```php
public static function signaturesRequired(): int
{
    return 1; // can be updated here
}
```

الكود يتتبع `signatures_received` في قاعدة البيانات بدقيق (DB trigger يمنع `signed` بدون >= 1)، لكن **المنطق الفعلي للتعدد غير موجود**. الميزة مكتمة في الـ DB ونصف مكتملة في الكود.

---

### 💀 DEAD-007 — `snapshot_data` يُكتب ثم يُصفَّر فوراً

**الملف:** `app/Services/TimelineRecorder.php` السطر 577 (تقريباً)

```php
$values[3] = null; // ← يُصفَّر snapshot_data بعد بنائه مباشرة
```

**الحالة:**
الكود يبني `snapshot_data` (الحقل القديم) ثم يُصفَّره ليستخدم نظام الـ hybrid (anchor + patch). هذا يعني أن الحقل `snapshot_data` في `guarantee_history` **دائماً NULL** في البيئات التي تعمل بـ hybrid mode، لكنه موجود في الـ schema. بيانات مُهدَرة في كل حدث.

---

### 💀 DEAD-008 — `getopt('strict-warn')` لا يُغيّر سلوك الخروج كما يُتوقع

**الملف:** `app/Scripts/data-integrity-check.php` السطر 119, 584

```php
$strictWarn = ...; // true إذا مُرِّر --strict-warn
...
if ($failViolations > 0 || ($strictWarn && $warnViolations > 0)) {
    exit(1);
}
```

**المشكلة:**
`--strict-warn` يُرجع `false` كقيمة من `getopt()` عندما يُمرَّر بدون قيمة. السطر:

```php
$strictWarn = is_array($options) && array_key_exists('strict-warn', $options);
```

هذا **صحيح** — `array_key_exists` تُرجع true لأن المفتاح موجود. لكن في واجهة PHPDocBlock المُعلَّقة أعلاه (السطر 11): `--strict-warn` مُعرَّف بدون القيمة الاختيارية `::`. إذن هذا يعمل بشكل صحيح. الملاحظة: **الـ markdown report** الذي يُولَّد يستخدم `$strictWarnMode` (int) لا `$strictWarn` (bool) ← خطأ في التوحيد.

---

## الفئة الثالثة: ازدواجية المنطق (Logic Duplication)

---

### 🔁 DUP-001 — ازدواجية تعريف خريطة صلاحيات المراحل

**الملفان:**

- `app/Services/WorkflowService.php` — `TRANSITION_PERMISSIONS`
- `app/Services/ActionabilityPolicyService.php` — `STAGE_PERMISSION_MAP`

**الحالة:**
كلا الملفين يُعرِّفان خريطة مراحل → صلاحيات. `WorkflowService` يستخدم خريطته في `canAdvance()`. `ActionabilityPolicyService` تستخدم خريطتها في `buildActionableSqlPredicate()` و`evaluate()`. عند الإضافة أو تعديل مرحلة، **يجب التحديث في مكانين** — مصدر خطأ class بالتعريف.

---

### 🔁 DUP-002 — إنشاء `SupplierRepository` مرتين في `AuthorityFactory`

**الملف:** `app/Services/Learning/AuthorityFactory.php` السطر 50 و104

```php
$supplierRepo = new SupplierRepository(); // ← M-01
...
private static function createFuzzyFeeder(): FuzzySignalFeeder {
    $supplierRepo = new SupplierRepository(); // ← M-02
```

**المشكلة:**
مثيلان منفصلان لـ `SupplierRepository` يحملان اتصالات DB خاصة بهما. لا sharing لأي cache. هذا يُضاعف الـ overhead.

---

### 🔁 DUP-003 — إنشاء `Normalizer` مرتين في `AuthorityFactory`

**الملف:** `app/Services/Learning/AuthorityFactory.php` السطر 45 و105

```php
$normalizer = new Normalizer(); // ← في create()
...
$normalizer = new Normalizer(); // ← في createFuzzyFeeder()
```

نفس المشكلة. `Normalizer` stateless تماماً — يكفي instance واحد للجميع.

---

### 🔁 DUP-004 — `Settings::new()` تُنشأ ثلاث مرات في `AuthorityFactory` + `ConfidenceCalculatorV2`

- `AuthorityFactory::create()`: `$settings = new Settings()`
- `ConfidenceCalculatorV2::__construct()`: `$settings ?? new Settings()`
- أي كود آخر يستدعي `new ConfidenceCalculatorV2()` بدون settings ينشئ instance ثالثاً

كل instance من `Settings` يقرأ الملف من القرص مستقلاً. الحل الصحيح: `Settings::getInstance()` موجود كـ singleton — استخدامه.

---

## الفئة الرابعة: تعارضات معمارية وتصميمية (Contradictions)

---

### ⚡ CONTR-001 — الوثيقة تقول "لا استعلام مباشر" لكن الكود يستعلم

**الملف:** `app/Services/Learning/UnifiedLearningAuthority.php` السطر 25-26

```php
// Does NOT:
// - Query database directly (uses feeders)
```

لكن `SuggestionFormatter` (يستخدمه مباشرة) يستعلم مباشرة من `SupplierRepository`. حق النظرية الصارمة منتهك.

---

### ⚡ CONTR-002 — `BREAK_GLASS_ENABLED` الافتراضي `true` في الكود، لكن التوثيق يقول إنه غير نشط

**الملف:** `app/Support/Settings.php` السطر 76

```php
'BREAK_GLASS_ENABLED' => true,
```

في `BreakGlassService.php` التعليق يقول "Emergency override stays available for governed exceptions" — لكن التوثيق الخارجي للمراجعة السابقة أشار إلى أن الافتراضي كان `false`. الكود الفعلي يقول `true`. **Break Glass مُفعَّل افتراضياً** — أي مستخدم بصلاحية `break_glass_override` يمكنه تجاوز الحوكمة فوراً دون تهيئة إضافية.

---

### ⚡ CONTR-003 — `Guard::$permissions` ووصف الـ wildcard يُعطي developer ALL permissions لكن `Guard::hasOrLegacy()` لا يُعالج الـ wildcard

**الملف:** `app/Support/Guard.php`

```php
public static function has(string $permissionSlug): bool
{
    return in_array($permissionSlug, $permissions, true) || in_array('*', $permissions, true);
    // ← يُعالج wildcard '*'
}

public static function hasOrLegacy(string $permissionSlug, bool $legacyDefault = true): bool
{
    if (!self::isRegistered($permissionSlug)) {
        return $legacyDefault; // ← يتجاوز has() كلياً
    }
    return self::has($permissionSlug); // ← يصل لـ has() فقط إذا مسجّلة
}
```

**التعارض:** Developer (wildcard `*`) يُعطى كل الصلاحيات عبر `has()`. لكن `hasOrLegacy()` تتحقق أولاً من الـ DB. إذا لم تُسجَّل الصلاحية → يُرجع `legacyDefault = true` **لكل المستخدمين بما فيهم non-developers**. `has()` للـ developer تُرجع true (wildcard). `hasOrLegacy()` للـ non-developer تُرجع true (legacy default) لنفس الصلاحية غير المسجلة. **كلاهما يصلان لـ true بطريقين مختلفين** → لا فرق وظيفي بين developer و non-developer للصلاحيات الجديدة.

---

### ⚡ CONTR-004 — معادلة similarity الخاطئة مع length check

**الملف:** `app/Services/Learning/Feeders/FuzzySignalFeeder.php` السطر 81-91

```php
$inputLen = mb_strlen($normalizedInput);    // ← بالأحرف
$supplierLen = mb_strlen((string)$supplierNormalized); // ← بالأحرف
$maxLen = max($inputLen, $supplierLen);
$lengthDiff = abs($inputLen - $supplierLen);
$maxPossibleSimilarity = 1 - ($lengthDiff / $maxLen);
```

ثم:

```php
$similarity = $this->calculateSimilarity($normalizedInput, $supplierNormalized);
// حيث calculateSimilarity تستخدم levenshtein() بالبايت
```

**التناقض:** الـ fast gate يحسب بالأحرف (`mb_strlen`) لكن الـ similarity الفعلي يحسب بالبايت (`levenshtein`). قد يتجاوز record الـ fast gate (بتشابه نظري بالأحرف) ثم يُرجع similarity مختلف تماماً بالبايت. الـ pre-filter غير متسق مع الحساب الفعلي.

---

## الفئة الخامسة: ملفات يتيمة وأصول غير مستخدمة (Orphaned Files)

---

### 📁 ORPHAN-001 — `guarantee_history_archive` — جدول DB بلا كود

كما شُرح في DEAD-004.

---

### 📁 ORPHAN-002 — `app/Scripts/debug-pending-breakdown.php` و`debug-test-visibility.php`

**الملفات:**

- `app/Scripts/debug-pending-breakdown.php` (2.3KB)
- `app/Scripts/debug-test-visibility.php` (4.7KB)

ملفات debug تبدأ بـ `debug-` — هذه **ليست scripts إنتاج**. وجودها في `app/Scripts/` دون فصلها في مجلد `dev/` يعني أنها قابلة للتشغيل عرضاً في بيئة إنتاج.

---

### 📁 ORPHAN-003 — `app/Scripts/i18n-fill-ar-from-en.py` و`i18n-fill-en-from-ar.py`

ملفان Python في مشروع PHP. يتطلبان Python مثبّتاً على الخادم. **غير موثّقة** كمتطلب في أي `README`. في بيئات Docker أو CI/CD التي لا تحتوي Python، ستفشل.

---

### 📁 ORPHAN-004 — ملف `extract_msg.ps1` PowerShell في مشروع PHP/Linux

**الملف:** `app/Scripts/extract_msg.ps1`

سكريبت PowerShell وسط مشروع PHP مُخصَّص (ظاهرياً) لبيئة Linux/Mac. هذا يُشير إلى أن استخراج emails `.msg` (Outlook) يعمل **فقط على Windows** من خلال هذا السكريبت، بنما بقية النظام يعمل بمستقل عن البيئة. تعارض بيئي محتمل.

---

### 📁 ORPHAN-005 — `server.pid` و`server.port` — آثار بيئة التطوير

ملفان يدلان على تشغيل PHP built-in server. **يجب عدم وجودهما في `.gitignore`** إذا كانا موجودَين في الـ repo — خطر كشف معلومات البيئة.

---

## الفئة السادسة: ثغرات الجودة والأداء

---

### ⚙️ PERF-001 — `FuzzySignalFeeder` يجلب 600 مورد ثم يُرشّح في PHP

**الملف:** `app/Services/Learning/Feeders/FuzzySignalFeeder.php` السطر 62

```php
$allSuppliers = $this->supplierRepo->getFuzzyCandidatesByTokens($inputTokens, 600);
```

الـ Fuzzy Feeder يجلب حتى 600 مورد من الـ DB ثم يُطبّق levenshtein في PHP على كل منهم. مع مشكلة BUG-001 (levenshtein بالبايت)، كل العملية تُنتج نتائج خاطئة بأداء ثقيل.

---

### ⚙️ PERF-002 — `Settings` تُقرأ من القرص مرتين في كل `all()` (primary + local)

**الملف:** `app/Support/Settings.php` السطر 121-128

```php
public function all(): array
{
    $primary = $this->loadPrimary(); // ← file_get_contents(settings.json)
    $local = $this->loadFileData($this->localPath); // ← file_get_contents(settings.local.json)
```

كل استدعاء `all()` = 2 قراءة ملف. في طلب HTTP واحد يمكن أن تُنفَّذ عشرات المرات.

---

### ⚙️ PERF-003 — `Guard::permissions()` يستعلم DB في كل request ولا يُطبَّق cache عبر requests

**الملف:** `app/Support/Guard.php`

`$permissions` static تُحتفظ بها داخل نفس request (PHP process). لكن في بيئة FPM، كل request = process جديد = استعلام DB جديد. لا APCu/Redis caching لصلاحيات الدور. في تطبيق مكثف الطلبات، كل طلب ينفذ SQL لجلب الصلاحيات.

---

## الفئة السابعة: ثغرات الأمان (Security Vulnerabilities)

---

### 🔐 SEC-001 — `Guard::hasOrLegacy(default=true)` — مُعاد تأكيده كـ Critical

**الخطورة:** حرجة. شُرح في الفئة الأولى. أي صلاحية جديدة = كل المستخدمين يمتلكونها حتى تشغيل migration.

---

### 🔐 SEC-002 — `settings.json` بدون أي تشفير أو حماية على مستوى التطبيق

**الملف:** `app/Support/Settings.php` السطر 117

```php
$this->path = $path ?: (__DIR__ . '/../../storage/settings.json');
```

`storage/` مجلد بجوار جذر التطبيق. إذا لم يكن مُحمياً بـ `.htaccess` أو nginx rules من الوصول المباشر، فملف الإعدادات (المحتوي على DB credentials) قابل للوصول من الـ web مباشرة.

---

### 🔐 SEC-003 — `exec()` بدون timeout أو resource limits

**الملف:** `app/Services/SchedulerRuntimeService.php` السطر 214

```php
exec($command . ' 2>&1', $lines, $code);
```

`exec()` بدون timeout يعني أن job معلّق يُجمّد thread PHP لأجل غير مسمى. في PHP-FPM مع `max_children` محدود، هذا يمكن أن يُسبّب **استنزاف كامل للـ workers**.

---

### 🔐 SEC-004 — حقل `locked_reason` في `guarantee_decisions` غير محمي من XSS

**الملف:** `database/migrations` + Views

`locked_reason` هو TEXT يُدخله المستخدم. إذا عُرض في الواجهة بدون `htmlspecialchars()` — ثغرة XSS محتملة. **غير قابلة للتحقق** بالكامل دون رؤية `views/` الكاملة.

---

## الفئة الثامنة: ديون تقنية (Technical Debt)

---

### 💸 DEBT-001 — `index.php` بـ 118,000 بايت كنقطة دخول وحيدة

**الخطورة:** عالية. ملف PHP/HTML/JS/CSS ضخم لا يمكن اختباره. أي مطور جديد يتطلب أسابيع لفهمه.

---

### 💸 DEBT-002 — `views/settings.php` بـ 105,000 بايت كصفحة واحدة

**نفس المشكلة.** صفحة الإعدادات كصف PHP HTML ضخم بدون components.

---

### 💸 DEBT-003 — `error_log()` منتشر في 20+ موضع كبديل للـ structured logging

المشروع يحتوي `app/Support/Logger.php` لكنه غير مستخدم في `TimelineRecorder`, `SmartProcessingService`, `BreakGlassService`. الناتج: سجلات في PHP error_log وليس في نظام logging مُنظَّم قابل للتصفية.

---

### 💸 DEBT-004 — غياب DI Container كلي

كل service ينشئ dependencies الخاصة به بـ `new`. لا تبادلية، لا اختبار وحدوي حقيقي. `AuthorityFactory` هو أحسن ما وُجد، لكنه يُنشئ مثيلات مضاعفة.

---

### 💸 DEBT-005 — Schema Drift بين البيئات

`active_action_set_at` قد يكون TEXT في بعض البيئات وTIMESTAMP في أخرى. `banks.created_at` = TEXT دائماً (لا يمكن الاستعلام الزمني). هاتان المشكلتان تتراكمان مع كل migration جديد.

---

## الملخص التنفيذي المصنَّف

### إحصائيات التدقيق

| الفئة            | العدد  | أعلى خطورة                    |
| ---------------- | ------ | ----------------------------- |
| أخطاء فعلية نشطة | 10     | 🔴 حرج (BUG-001: levenshtein) |
| كود ميت / stubs  | 8      | 💀 متوسط-عالٍ                 |
| ازدواجية منطقية  | 4      | 🔁 متوسط                      |
| تعارضات معمارية  | 4      | ⚡ متوسط-عالٍ                 |
| ملفات يتيمة      | 5      | 📁 منخفض-متوسط                |
| ثغرات أداء       | 3      | ⚙️ عالٍ                       |
| ثغرات أمنية      | 4      | 🔐 حرج (SEC-001)              |
| ديون تقنية       | 5      | 💸 عالٍ                       |
| **الإجمالي**     | **43** |                               |

---

### أولويات الإصلاح الفورية

| الأولوية | المشكلة                                           | الأثر                             | الجهد |
| -------- | ------------------------------------------------- | --------------------------------- | ----- |
| #1       | **BUG-001** — `levenshtein()` للعربية             | فشل كامل للمطابقة الفازية العربية | منخفض |
| #2       | **SEC-001** — `Guard::hasOrLegacy(default=true)`  | privilege escalation              | منخفض |
| #3       | **BUG-003** — خطأ في OPERATIONAL_COUNT_ARITHMETIC | نتائج سلامة بيانات كاذبة          | منخفض |
| #4       | **BUG-002** — تكرار `identifyPrimarySignal()`     | تضليل في audit trail              | متوسط |
| #5       | **SEC-003** — `exec()` بدون timeout               | DoS محتمل على workers             | منخفض |
| #6       | **PERF-001/002** — قراءة Settings من قرص          | أداء                              | متوسط |
| #7       | **DEAD-004** — جدول archive معلّق                 | schema bloat                      | منخفض |
| #8       | **DEBT-001/002** — تفكيك index.php و settings.php | استدامة طويلة المدى               | عالٍ  |

---

### الخلاصة الجوهرية

النظام يُظهر نضجاً حوكمياً استثنائياً — سجل جنائي، workflow guards، break glass، undo chain. لكن **الطبقة الإدراكية للنظام (الذكاء الاصطناعي)** تحتوي على خطأ أساسي: `levenshtein()` لا تعمل بشكل صحيح مع النصوص العربية. كل المطابقة الفازية مبنية على أساس معيب. هذه ليست ديناً تقنياً — هي خطأ وظيفي نشط يؤثر على نتائج كل تطابق مورد في النظام.

> "الحوكمة ممتازة. قاعدة البيانات محصّنة. الذكاء الاصطناعي الأساسي معطوب."
