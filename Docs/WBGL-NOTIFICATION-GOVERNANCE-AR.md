# حوكمة الإشعارات في WBGL

## الهدف
توحيد منطق الإشعارات على مستوى النظام بحيث:

1. تصل الإشعارات للمستخدم/الدور الصحيح فقط.
2. تكون قابلة للتصنيف (نوع/شدة/فئة).
3. يمكن للمستخدم التعامل معها مباشرة (`مقروء` / `إخفاء`).
4. لا تؤثر قراءة مستخدم على صندوق مستخدم آخر.

## المكونات المنفذة

1. **تخزين الإشعار**:
   - جدول `notifications` (رسالة أساسية + الاستهداف).
   - جدول `notification_user_states` (حالة كل مستخدم: مقروء/مخفي).

2. **خدمة الإشعارات الأساسية**:
   - `app/Services/NotificationService.php`
   - مسؤولة عن:
     - الإنشاء (عام/مستخدم/دور)
     - جلب الإشعارات للمستخدم الحالي
     - تعليم مقروء
     - إخفاء
     - عدّ غير المقروء

3. **سياسة التوجيه الموحدة**:
   - `app/Services/NotificationPolicyService.php`
   - مسؤولة عن:
     - تصنيف كل نوع إشعار (`category`, `severity`)
     - توجيه تلقائي حسب الدور/المستخدم
     - إرفاق metadata موحدة داخل `data.notification_meta`
     - fallback آمن عند غياب التوجيه

4. **واجهة المستخدم (Sidebar Cards)**:
   - `index.php`
   - تعرض الإشعارات على شكل كروت مكدسة من الأقدم إلى الأحدث.
   - كل كرت يحتوي:
     - نوع الشدة (معلومة/تنبيه/خطأ/نجاح)
     - فئة الإشعار (سير العمل/الحوكمة/التشغيل/...)
     - العنوان والمحتوى والوقت
     - أزرار `مقروء` و`إخفاء`

## سياسة التوجيه الحالية (افتراضي)

### `scheduler_failure`
- الفئة: `operations`
- الشدة: `error`
- المستهدف: دور `developer`

### `undo_request_submitted`
- الفئة: `governance`
- الشدة: `warning`
- المستهدف: `supervisor`, `approver`, `developer`

### `undo_request_approved`
- الفئة: `workflow`
- الشدة: `info`
- المستهدف: `developer` + المستخدم صاحب الطلب

### `undo_request_rejected`
- الفئة: `workflow`
- الشدة: `warning`
- المستهدف: `developer` + المستخدم صاحب الطلب

### `undo_request_executed`
- الفئة: `workflow`
- الشدة: `success`
- المستهدف: `developer` + المستخدم صاحب الطلب

## إعدادات قابلة للتخصيص

داخل `storage/settings.json` أو `storage/settings.local.json`:

1. `NOTIFICATIONS_ENABLED` (افتراضي: `true`)
2. `NOTIFICATION_UI_MAX_ITEMS` (افتراضي: `40`)
3. `NOTIFICATION_POLICY_OVERRIDES` (افتراضي: `{}` أو `[]`)

### مثال override

```json
{
  "NOTIFICATION_POLICY_OVERRIDES": {
    "undo_request_submitted": {
      "roles": ["approver"],
      "severity": "error",
      "category": "governance",
      "fallback_global": false
    }
  }
}
```

## قواعد الاستخدام الصحيحة

1. أي خدمة تولّد إشعارًا يجب أن تستخدم:
   - `NotificationPolicyService::emit(...)`
   - وليس الإنشاء اليدوي المباشر إلا لحالة استثنائية واضحة.

2. لا تستخدم إشعار عام (`global`) إلا إذا كان الحدث موجهًا فعليًا للجميع.

3. عند إنشاء إشعار مرتبط بطلب مستخدم:
   - مرّر `directUsername` ليصل إشعار شخصي لصاحب الطلب.

## خطة الإكمال القادمة

1. إضافة شاشة إدارة سياسات الإشعار من الإعدادات (UI).
2. ربط بقية أحداث النظام (رفض مراحل، فشل استيراد، Break-glass، إلخ) بنفس المحرك.
3. إضافة اختبارات تكامل API للإشعارات (read/hide/targeting).
4. إضافة أرشفة/TTL للإشعارات القديمة.

