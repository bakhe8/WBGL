# WBGL Break-Glass Runbook (Wave-5 Baseline)

## الهدف

تشغيل آمن ومضبوط لآلية تجاوز الطوارئ في الحالات الاستثنائية فقط، مع أثر تدقيقي كامل.

## شروط ما قبل التنفيذ

1. تفعيل `BREAK_GLASS_ENABLED=true`.
2. تفعيل `BREAK_GLASS_REQUIRE_TICKET=true`.
3. منح صلاحية `break_glass_override` للجهة المخولة فقط.
4. وجود سبب واضح للطوارئ (حد أدنى 8 أحرف).

## حقول الإدخال المتوقعة

يمكن تمرير الحقول بإحدى الصيغ:

1. صيغة مباشرة:
   - `break_glass_enabled`
   - `break_glass_reason`
   - `break_glass_ticket`
   - `break_glass_ttl_minutes`
2. أو كائن:
   - `break_glass: { enabled, reason, ticket_ref, ttl_minutes }`

## تدفق التنفيذ

1. النظام يتحقق من الصلاحية `break_glass_override`.
2. يتحقق من تفعيل السياسة في الإعدادات.
3. يتحقق من السبب/التذكرة/TTL.
4. يسجل الحدث في `break_glass_events`.
5. يعيد معلومات event (`id`, `expires_at`, `ticket_ref`).

## سجلات التتبع

1. جدول: `break_glass_events`
2. حقول مهمة للمراجعة:
   - `action_name`
   - `target_type`
   - `target_id`
   - `requested_by`
   - `reason`
   - `ticket_ref`
   - `ttl_minutes`
   - `expires_at`
   - `created_at`

## استعلامات مراجعة (PostgreSQL)

```sql
SELECT id, action_name, target_type, target_id, requested_by, ticket_ref, expires_at, created_at
FROM break_glass_events
ORDER BY id DESC
LIMIT 100;
```

```sql
SELECT action_name, COUNT(*) AS usage_count
FROM break_glass_events
WHERE created_at >= (CURRENT_TIMESTAMP - INTERVAL '30 days')
GROUP BY action_name
ORDER BY usage_count DESC;
```

## ضوابط ما بعد الحادث

1. إغلاق تذكرة الحادث مع ربط `break_glass_events.id`.
2. مراجعة السبب مع المالك التشغيلي.
3. تحديد إجراء يمنع تكرار الحاجة إلى Break-Glass.
4. توثيق الدرس المستفاد في سجل الحوادث.
