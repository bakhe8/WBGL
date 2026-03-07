# تقرير 01 — طبقة الـ API (63 Endpoint)

## WBGL API Layer — Full Coverage Report

> **الملفات:** `api/` — 63 endpoint + 1 bootstrap + 2 subdirectories
> **التاريخ:** 2026-03-07

---

## 1.1 معمارية الـ API Bootstrap

`_bootstrap.php` (418 سطر) هو العمود الفقري لكل الـ API. يوفر:

| الوظيفة                | التنفيذ                                               | الجودة   |
| ---------------------- | ----------------------------------------------------- | -------- |
| Request ID             | `wbgl_api_request_id()` — `X-Request-Id` header       | ✅ ممتاز |
| CSRF                   | `CsrfGuard::validateRequest()` على كل mutating method | ✅ ممتاز |
| Auth (Session)         | `AuthService::isLoggedIn()`                           | ✅       |
| Auth (Token)           | `ApiTokenService::authenticateRequest()`              | ✅       |
| Policy surface         | `wbgl_api_policy_surface_for_guarantee()`             | ✅ متقدم |
| Audit Trail كل 401/403 | `AuditTrailService::record()`                         | ✅ ممتاز |
| Error envelope         | `wbgl_api_envelope()` unified format                  | ✅       |
| Internal error masking | رسائل 5xx تُخفي التفاصيل، تُسجَّل بـ request_id       | ✅ ممتاز |

---

## 1.2 جرد الـ Endpoints الكاملة

### Authentication

| الـ Endpoint | الوظيفة                      | Auth مطلوب؟ |
| ------------ | ---------------------------- | ----------- |
| `login.php`  | تسجيل الدخول + Rate Limiting | ❌ (public) |
| `logout.php` | تسجيل الخروج                 | ✅          |
| `me.php`     | بيانات المستخدم الحالي       | ✅          |

### Guarantees — Core

| الـ Endpoint               | الوظيفة         | Permission           |
| -------------------------- | --------------- | -------------------- |
| `import.php`               | استيراد Excel   | `import_guarantees`  |
| `import-email.php`         | استيراد من بريد | `import_guarantees`  |
| `manual-entry.php`         | إدخال يدوي      | `manual_entry`       |
| `create-guarantee.php`     | إنشاء ضمان      | `create_guarantee`   |
| `update-guarantee.php`     | تحديث ضمان      | `edit_guarantee`     |
| `get-record.php`           | عرض ضمان        | visibility check     |
| `get-current-state.php`    | الحالة الكاملة  | visibility check     |
| `get-timeline.php`         | جدول زمني       | `view_timeline`      |
| `get-history-snapshot.php` | snapshot تاريخي | `view_timeline`      |
| `get-letter-preview.php`   | معاينة خطاب     | `view_letter`        |
| `history.php`              | تاريخ الضمان    | ✅                   |
| `print-events.php`         | طباعة الأحداث   | ✅                   |
| `save-import.php`          | حفظ استيراد     | ✅                   |
| `save-and-next.php`        | حفظ والتالي     | ✅                   |
| `save-note.php`            | إضافة ملاحظة    | `add_notes`          |
| `upload-attachment.php`    | رفع مرفق        | `upload_attachments` |
| `convert-to-real.php`      | تحويل لحقيقي    | ✅                   |

### Workflow

| الـ Endpoint           | الوظيفة    | Permission           |
| ---------------------- | ---------- | -------------------- |
| `workflow-advance.php` | تقدم مرحلة | per-stage permission |
| `workflow-reject.php`  | رفض مرحلة  | per-stage permission |

### Batch Operations

| الـ Endpoint             | الوظيفة                |
| ------------------------ | ---------------------- |
| `batches.php`            | قائمة الدفعات + تفاصيل |
| `commit-batch-draft.php` | تثبيت مسودة دفعة       |
| `extend.php`             | تمديد                  |
| `reduce.php`             | تخفيض                  |
| `release.php`            | إفراج                  |
| `reopen.php`             | إعادة فتح              |

### Suppliers & Banks

| الـ Endpoint                | الوظيفة        |
| --------------------------- | -------------- |
| `get_suppliers.php`         | قائمة الموردين |
| `create-supplier.php`       | إضافة مورد     |
| `update_supplier.php`       | تحديث مورد     |
| `delete_supplier.php`       | حذف مورد       |
| `delete_suppliers_bulk.php` | حذف جماعي      |
| `merge-suppliers.php`       | دمج موردين     |
| `import_suppliers.php`      | استيراد موردين |
| `export_suppliers.php`      | تصدير موردين   |
| `get_banks.php`             | قائمة البنوك   |
| `create-bank.php`           | إضافة بنك      |
| `update_bank.php`           | تحديث بنك      |
| `delete_bank.php`           | حذف بنك        |
| `delete_banks_bulk.php`     | حذف بنوك جماعي |
| `import_banks.php`          | استيراد بنوك   |
| `export_banks.php`          | تصدير بنوك     |

### Learning & Matching

| الـ Endpoint                    | الوظيفة           |
| ------------------------------- | ----------------- |
| `learning-action.php`           | إجراء تعلم        |
| `learning-data.php`             | بيانات تعلم       |
| `parse-paste.php`               | تحليل نص V1       |
| `parse-paste-v2.php`            | تحليل نص V2       |
| `smart-paste-confidence.php`    | تقييم ثقة اللصق   |
| `matching-overrides.php`        | تجاوزات المطابقة  |
| `import_matching_overrides.php` | استيراد التجاوزات |
| `export_matching_overrides.php` | تصدير التجاوزات   |
| `suggestions-learning.php`      | اقتراحات التعلم   |

### Administration

| الـ Endpoint                 | الوظيفة                    | Permission        |
| ---------------------------- | -------------------------- | ----------------- |
| `settings.php`               | قراءة/تحديث الإعدادات      | `manage_settings` |
| `settings-audit.php`         | سجل تغييرات الإعدادات      | `manage_settings` |
| `users/` (4 ملفات)           | إدارة المستخدمين           | `manage_users`    |
| `roles/` (3 ملفات)           | إدارة الأدوار              | `manage_roles`    |
| `undo-requests.php`          | إدارة طلبات التراجع        | ✅                |
| `notifications.php`          | الإشعارات                  | ✅                |
| `metrics.php`                | المقاييس                   | ✅                |
| `alerts.php`                 | التنبيهات التشغيلية        | ✅                |
| `scheduler-dead-letters.php` | رسائل الـ Scheduler الميتة | ✅                |
| `user-preferences.php`       | تفضيلات المستخدم           | ✅                |

---

## 1.3 مشاهدات وتحليلات

### ✅ نقاط قوة

- **CSRF** مفعّل عالمياً على كل متحوّلات (POST,PUT,DELETE,PATCH) عبر `$csrfEnforced`
- **Request ID** يُضاف لكل response — قابلية التتبع ممتازة
- **Audit Trail** لكل 401/403 — كل رفض موثَّق
- **Policy Surface** متكاملة: visibility + actionability + executability في response واحد
- **Internal error masking**: تفاصيل الـ 500 تُخفى عن العميل وتُسجَّل بـ request_id

### ⚠️ مشاهدات تستحق الانتباه

**1. `parse-paste.php` V1 وV2 موجودان معاً:**

- V1 (2737 bytes) وV2 (6607 bytes) — غير واضح من يستخدم أيهما
- V1 قد يكون dead code فعلياً

**2. `metrics.php` (631 bytes فقط):**

- حجمه يشير لـ stub أو endpoint بسيط جداً
- ما المقاييس المتاحة؟ هل مفيدة تشغيلياً؟

**3. `convert-to-real.php` (1164 bytes):**

- اسم غامض — ماذا يحوّل؟

**4. لا versioning للـ API:**

- لا `/api/v1/` prefix
- أي تغيير في response format يكسر الـ clients المتصلين

**5. `upload-attachment.php` (5494 bytes):**

- يقبل ملفات مرفوعة — هل يتحقق من نوع الملف ومحتواه؟
- هل MIME type validation موجودة أم فقط extension check؟

---

## 1.4 تدفق Workflow Advance — تحليل معمّق

من قراءة `workflow-advance.php`:

```
1. wbgl_api_require_login()
2. validate guarantee_id
3. wbgl_api_require_guarantee_visibility()
4. wbgl_api_policy_surface_for_guarantee() ← يجلب policy + surface
5. canAdvanceWithReasons() ← WorkflowService يتحقق من Permission
6. getNextStage() ← الخطوة التالية
7. إذا nextStep = SIGNED → TransactionBoundary مع signature counting
8. TransactionBoundary::run() → createSnapshot + update decision + recordWorkflowEvent
```

**ملاحظة إيجابية:** استخدام `TransactionBoundary::run()` بدلاً من `beginTransaction/commit` مباشرة — يعني وجود abstraction for transaction management. هذا **ليس** ما ذكرته التقارير السابقة.

---

## 1.5 خلاصة جودة طبقة الـ API

| المعيار                    | التقييم                                                 |
| -------------------------- | ------------------------------------------------------- |
| Authentication consistency | ✅ 100% — كل endpoint يستدعي `wbgl_api_require_login()` |
| Authorization granularity  | ✅ per-permission + per-guarantee visibility            |
| Error handling             | ✅ unified envelope مع masking                          |
| CSRF protection            | ✅ global بدون استثناء                                  |
| Input validation           | ✅ على أغلب الـ endpoints                               |
| API versioning             | ❌ غائب                                                 |
| Upload security            | ⚠️ يحتاج تحقق                                           |
| V1/V2 dead code            | ⚠️ parse-paste.php V1 مشبوه                             |
