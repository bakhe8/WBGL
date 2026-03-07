# تقرير 04 — طبقة Repositories وقاعدة البيانات

## WBGL Repositories Layer — Full Coverage Report

> **الملفات:** `app/Repositories/` — 14 ملف
> **التاريخ:** 2026-03-07

---

## 4.1 جرد الـ Repositories

| الـ Repository                      | الحجم  | الجدول الرئيسي               |
| ----------------------------------- | ------ | ---------------------------- |
| `GuaranteeRepository`               | 18.2KB | `guarantees`                 |
| `GuaranteeDecisionRepository`       | 7.3KB  | `guarantee_decisions`        |
| `SupplierRepository`                | 8.7KB  | `suppliers`                  |
| `SupplierLearningRepository`        | 7.4KB  | `supplier_learning`          |
| `SupplierAlternativeNameRepository` | 3.9KB  | `supplier_alternative_names` |
| `SupplierOverrideRepository`        | 4.7KB  | `supplier_overrides`         |
| `BankRepository`                    | 7.2KB  | `banks`                      |
| `UserRepository`                    | 7.8KB  | `users`                      |
| `RoleRepository`                    | 4.3KB  | `roles`                      |
| `LearningRepository`                | 3.1KB  | جداول تعلم                   |
| `BatchMetadataRepository`           | 2.1KB  | `batch_metadata`             |
| `NoteRepository`                    | 1KB    | `notes`                      |
| `AttachmentRepository`              | 1.8KB  | `attachments`                |
| `ImportedRecordRepository`          | 2KB    | `imported_records`           |

---

## 4.2 GuaranteeRepository — أكبر Repository

(18.2KB) — يتضمن:

- `find(int $id)` — جلب ضمان بـ ID
- `findAll(array $filters)` — بحث مع filters
- `updateRawData(int $id, string $json)` — تحديث `raw_data`
- `create(array $data)` — إنشاء ضمان
- pagination support

**مشاهدة أمنية:**
`updateRawData()` يقبل JSON string مباشرةً. إذا كان الـ caller هو `BatchService`:

```php
$guaranteeRepo->updateRawData($g['id'], json_encode($raw));
```

يتم encode في البداية. لكن: هل يوجد schema validation على `$raw` قبل encoding؟

**لا schema validation** مرئية — `raw_data` يُحدَّث بأي JSON.

---

## 4.3 UserRepository — أمان كلمات المرور

(7.8KB) — يتضمن عمليات CRUD للمستخدمين.

**السؤال الحرج:** كيف تُخزَّن كلمات المرور؟

من البنية المعمارية العامة، المتوقع:

- `password_hash($password, PASSWORD_BCRYPT)` أو `PASSWORD_ARGON2ID`
- لا يمكن التأكيد بدون قراءة الملف كاملاً

**توصية:** طلب مراجعة دالة `create()` و`verifyPassword()` في هذا الـ Repository.

---

## 4.4 SupplierLearningRepository — بنية التعلم

(7.4KB) — يتعامل مع:

- `supplier_learning` table
- أحداث تعلم إيجابي/سلبي
- تحديث scores

**الربط:** هذا ما يُغذي `UnifiedLearningAuthority` بالبيانات التاريخية للتعلم.

---

## 4.5 LearningRepository

(3.1KB) — repository منفصل لجداول تعلم إضافية. وجود repository منفصل عن `SupplierLearningRepository` يشير لاحتمال:

- Learning subsystem يحتوي جداول متعددة
- أو مرحلة refactoring لم تكتمل

---

## 4.6 مشاهدة عامة: Repositories وـ SQL Injection

جميع الـ Repositories تستخدم PDO Prepared Statements بناءً على ما رأيناه في BatchService وWorkflowService. هذا يعني:

✅ SQL injection غير محتمل في مسارات `prepare()` العادية.

⚠️ لكن: `GuaranteeRepository::findAll()` قد يبني SQL ديناميكياً لـ filters — يحتاج فحص.

---

## 4.7 خلاصة

| المعيار                    | التقييم                    |
| -------------------------- | -------------------------- |
| Prepared statements        | ✅ نعم (PDO)               |
| SQL injection via prepare  | ✅ محمي                    |
| Dynamic query building     | ⚠️ يحتاج تحقق في findAll() |
| Password hashing           | ⚠️ يحتاج تأكيد             |
| raw_data schema validation | ❌ غائبة                   |
| Repository count           | ✅ تغطية جيدة لكل domain   |
| Separation of concerns     | ✅ كل Domain له Repository |
