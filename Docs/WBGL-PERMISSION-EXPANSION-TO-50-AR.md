# توسيع مصفوفة الصلاحيات إلى 50 صلاحية (WBGL)

آخر تحديث: 2026-03-01  
الغرض: توسيع نموذج التحكم من 21 صلاحية نشطة إلى 50 صلاحية قابلة للضبط على مستوى الدور والمستخدم، مع الحفاظ على الاستقرار.

---

## 1) خط الأساس الحالي

- الصلاحيات النشطة فعليًا في قاعدة البيانات: `50`
- قدرات الواجهة المربوطة في `UiPolicy`: `20`
- الهدف التنفيذي القادم: `50` صلاحية (متحقق)
- الحد العملي الآمن بعد اكتمال الحوكمة والاختبارات: `120-150`

---

## 2) قواعد التوسعة الإلزامية

1. لا تُضاف أي صلاحية جديدة بدون ربطها بـ:
   - سطح واجهة واضح (إظهار/إخفاء/تعطيل)
   - Guard في API/Backend
   - إدخال في `PermissionCapabilityCatalog`
   - اختبار يثبت رفض الوصول المباشر عند غياب الصلاحية
2. لا نسمح بصلاحية جديدة بصيغة "اسم عام" غير قابل للقياس.
3. أي صلاحية جديدة تكون `deny-by-default` حتى تُمنح صراحة.
4. أي تقسيم لصلاحية عامة (مثل `manage_data`) يتم على مراحل مع fallback واضح.

---

## 3) المصفوفة المستهدفة (50 صلاحية)

| # | slug | الحالة | المجال | نوع التحكم | السطح المستهدف | fallback حالي | الأولوية |
|---|---|---|---|---|---|---|---|
| 1 | `import_excel` | Active | الإدخال | تنفيذ | أزرار ملف/استيراد + APIs الاستيراد | - | P1 |
| 2 | `manual_entry` | Active | الإدخال | تنفيذ | زر إدراج يدوي + APIs الإدراج | - | P1 |
| 3 | `manage_data` | Active | التشغيل | تنفيذ | إدارة البيانات المرجعية فقط (البنوك/الموردين/الدمج) | - | P1 |
| 4 | `audit_data` | Active | workflow | تنفيذ | تقدم المرحلة draft -> audited | - | P1 |
| 5 | `analyze_guarantee` | Active | workflow | تنفيذ | تقدم المرحلة audited -> analyzed | - | P1 |
| 6 | `supervise_analysis` | Active | workflow | تنفيذ | تقدم المرحلة analyzed -> supervised | - | P1 |
| 7 | `approve_decision` | Active | workflow | تنفيذ | تقدم المرحلة supervised -> approved | - | P1 |
| 8 | `sign_letters` | Active | workflow | تنفيذ | تقدم المرحلة approved -> signed | - | P1 |
| 9 | `manage_users` | Active | الحوكمة | رؤية + تنفيذ | users/settings/maintenance + user APIs | - | P1 |
| 10 | `manage_roles` | Active | الحوكمة | رؤية + تنفيذ | قسم إدارة الأدوار + role APIs | - | P1 |
| 11 | `reopen_batch` | Active | الحوكمة | تنفيذ | إعادة فتح الدفعات | - | P1 |
| 12 | `reopen_guarantee` | Active | الحوكمة | تنفيذ | إعادة فتح الضمانات | - | P1 |
| 13 | `break_glass_override` | Active | الحوكمة الطارئة | تنفيذ | مسارات break-glass | - | P1 |
| 14 | `ui_change_language` | Active | تفضيلات UI | سلوك | زر اللغة | - | P2 |
| 15 | `ui_change_direction` | Active | تفضيلات UI | سلوك | زر الاتجاه | - | P2 |
| 16 | `ui_change_theme` | Active | تفضيلات UI | سلوك | زر الثيم | - | P2 |
| 17 | `timeline_view` | Active | العرض والتتبع | رؤية | لوحة Timeline + history APIs | - | P1 |
| 18 | `notes_view` | Active | العرض والتتبع | رؤية | قسم الملاحظات | - | P2 |
| 19 | `notes_create` | Active | العرض والتتبع | تنفيذ | إضافة ملاحظة + `save-note` | - | P2 |
| 20 | `attachments_view` | Active | العرض والتتبع | رؤية | قسم المرفقات | - | P2 |
| 21 | `attachments_upload` | Active | العرض والتتبع | تنفيذ | رفع مرفقات + `upload-attachment` | - | P2 |
| 22 | `navigation_view_batches` | Proposed | الملاحة | رؤية | عنصر "الدفعات" في التنقل | login عام | P2 |
| 23 | `navigation_view_statistics` | Proposed | الملاحة | رؤية | عنصر "الإحصائيات" في التنقل | login عام | P2 |
| 24 | `navigation_view_settings` | Proposed | الملاحة | رؤية | عنصر/صفحة الإعدادات | `manage_users` | P2 |
| 25 | `navigation_view_users` | Proposed | الملاحة | رؤية | عنصر/صفحة المستخدمين | `manage_users` | P2 |
| 26 | `navigation_view_maintenance` | Proposed | الملاحة | رؤية | عنصر/صفحة الصيانة | `manage_users` | P2 |
| 27 | `metrics_view` | Proposed | الحوكمة | رؤية | `api/metrics.php` + لوحات المؤشرات | `manage_users` | P1 |
| 28 | `alerts_view` | Proposed | الحوكمة | رؤية | `api/alerts.php` + تنبيهات النظام | `manage_users` | P1 |
| 29 | `settings_audit_view` | Proposed | الحوكمة | رؤية | `api/settings-audit.php` | `manage_users` | P1 |
| 30 | `users_create` | Proposed | إدارة المستخدمين | تنفيذ | `api/users/create.php` | `manage_users` | P1 |
| 31 | `users_update` | Proposed | إدارة المستخدمين | تنفيذ | `api/users/update.php` | `manage_users` | P1 |
| 32 | `users_delete` | Proposed | إدارة المستخدمين | تنفيذ | `api/users/delete.php` | `manage_users` | P1 |
| 33 | `users_manage_overrides` | Proposed | إدارة المستخدمين | تنفيذ | تعديل صلاحيات المستخدم (allow/deny/auto) | `manage_users` | P1 |
| 34 | `roles_create` | Proposed | إدارة الأدوار | تنفيذ | `api/roles/create.php` | `manage_roles` | P1 |
| 35 | `roles_update` | Proposed | إدارة الأدوار | تنفيذ | `api/roles/update.php` | `manage_roles` | P1 |
| 36 | `roles_delete` | Proposed | إدارة الأدوار | تنفيذ | `api/roles/delete.php` | `manage_roles` | P1 |
| 37 | `import_paste` | Proposed | الإدخال | تنفيذ | `api/parse-paste.php` + `parse-paste-v2.php` | `import_excel` | P1 |
| 38 | `import_email` | Proposed | الإدخال | تنفيذ | `api/import-email.php` | `import_excel` | P1 |
| 39 | `import_suppliers` | Proposed | الإدخال | تنفيذ | `api/import_suppliers.php` | `import_excel` | P1 |
| 40 | `import_banks` | Proposed | الإدخال | تنفيذ | `api/import_banks.php` | `import_excel` | P1 |
| 41 | `import_matching_overrides` | Proposed | الإدخال | تنفيذ | `api/import_matching_overrides.php` | `manage_data` | P1 |
| 42 | `import_commit_batch` | Proposed | الإدخال | تنفيذ | `api/commit-batch-draft.php` | `manage_data` | P1 |
| 43 | `import_convert_to_real` | Proposed | الإدخال | تنفيذ | `api/convert-to-real.php` | `manage_data` | P1 |
| 44 | `guarantee_save` | Active | التشغيل | تنفيذ | `api/save-and-next.php`, `api/update-guarantee.php` | - | P1 |
| 45 | `guarantee_extend` | Active | التشغيل | تنفيذ | `api/extend.php` | - | P1 |
| 46 | `guarantee_reduce` | Active | التشغيل | تنفيذ | `api/reduce.php` | - | P1 |
| 47 | `guarantee_release` | Active | التشغيل | تنفيذ | `api/release.php` | - | P1 |
| 48 | `supplier_manage` | Active | المرجعيات | تنفيذ | create/update/delete/merge supplier APIs | - | P1 |
| 49 | `bank_manage` | Active | المرجعيات | تنفيذ | create/update/delete bank APIs | - | P1 |
| 50 | `timeline_export` | Proposed | العرض والتتبع | تنفيذ | تصدير/طباعة أحداث timeline | `timeline_view` | P3 |

---

## 4) طريقة التنفيذ (متتابعة وبدون تقيد بزمن)

1. إضافة الـ 23 صلاحية المقترحة في migration واحدة (تم).
2. إدراج كل الصلاحيات الجديدة داخل `PermissionCapabilityCatalog`.
3. ربط الصلاحيات الجديدة تدريجيًا في `UiPolicy` و`ViewPolicy` (حسب السطح).
4. تفكيك Guards العامة في APIs:
   - `manage_users` -> (`users_*`, `metrics_view`, `settings_audit_view`, ...)
   - `manage_roles` -> (`roles_create/update/delete`)
   - `import_excel` -> (`import_paste`, `import_email`, `import_suppliers`, ...)
   - `manage_data` -> (`guarantee_save`, `guarantee_extend`, `guarantee_reduce`, `guarantee_release`, `supplier_manage`, `bank_manage`, ...)
5. إبقاء fallback مؤقت لمدة انتقالية لكل مجموعة حتى اكتمال الربط.
6. إضافة اختبارات إلزامية:
   - API direct access returns `403` عند غياب الصلاحية.
   - UI guards: hide/disable behavior مطابق للصلاحية.
   - مصفوفة API policy لا تحتوي endpoints غير مصنفة.
7. تحديث شاشة إدارة المستخدمين/الأدوار لتعرض الصلاحيات الجديدة حسب المجال.
8. إغلاق كل fallback قديم بعد اكتمال التغطية والاختبارات.

---

## 5) معايير الإغلاق (Definition of Done)

- كل صلاحية في المصفوفة لها:
  - موضع واجهة أو API واضح
  - Guard فعلي من السيرفر
  - ظهور في شاشة التحكم `users.php`
  - تغطية اختبار
- عدم وجود endpoint قابل للاستدعاء المباشر يعتمد فقط على "إخفاء الواجهة".
- لا توجد صلاحيات عامة متبقية تتحكم في أسطح متعددة بشكل غير قابل للفصل.
