قناة أوامر الوكيل (Stage 3)

نظرة عامة
- هذه القناة اختيارية وقابلة للتفعيل عبر agent/config.yml.
- عند التفعيل، يقرأ الوكيل أوامر JSON من مجلد inbox ويكتب الردود في outbox.
- الأوامر المدعومة: pause, resume, set_ignored, add_ignored, clear_ignored, get_ignored, rotate_logs, ping.
- صُممت المجلدات والتسميات لتكون واضحة وقابلة للتوسع بدون لمس التطبيق الرئيسي.

التهيئة في agent/config.yml
أضف أو حدث القسم التالي (القيم الافتراضية معطلة):

commands:
  enabled: true           # تفعيل قناة الأوامر
  inbox: agent/commands/inbox
  outbox: agent/commands/outbox
  poll_interval_ms: 500   # فاصل الفحص بالمللي ثانية

ملاحظات بنيوية
- تم استثناء agent/commands تلقائياً من المراقبة لتجنب الضجيج.
- يتم نقل ملفات الأوامر المعالجة إلى agent/commands/inbox/processed مع اللاحقة .done
- إن لم تكن PyYAML مثبتة فلن تُقرأ config.yml، وستُستخدم الإعدادات الافتراضية (والقناة ستكون معطلة).

تنسيق ملف الأمر (JSON)
- اسم الملف حر (مثلاً pause.json)، ويُفضل تضمين id لتمايز الردود.
- الحقول العامة:
  op: اسم العملية (بالأحرف الصغيرة)
  id: معرف اختياري لل correlating
  ... حقول إضافية حسب العملية

أمثلة أوامر
1) إيقاف مؤقت
{
  "op": "pause",
  "id": "cmd-001"
}
الرد: outbox/cmd-001.response.json

2) استئناف
{
  "op": "resume",
  "id": "cmd-002"
}

3) Ping
{
  "op": "ping",
  "id": "cmd-003"
}
الرد يتضمن snapshot لحالة الوكيل.

4) تعيين التجاهلات (استبدال كامل للإضافات السابقة)
{
  "op": "set_ignored",
  "id": "cmd-004",
  "paths": ["storage/tmp", "C:/absolute/path/to/ignore"],
  "globs": ["node_modules/**", "**/*.tmp"]
}

4b) إضافة تجاهلات (دمج فوق الحالي)
{
  "op": "add_ignored",
  "id": "cmd-004b",
  "paths": ["more_stuff"],
  "globs": ["**/*.cache"]
}

4c) مسح الإضافات فقط
{
  "op": "clear_ignored",
  "id": "cmd-004c"
}

4d) استرجاع حالة التجاهلات
{
  "op": "get_ignored",
  "id": "cmd-004d"
}

5) تدوير السجلات
{
  "op": "rotate_logs",
  "id": "cmd-005"
}
سيُعاد فتح معالجات السجل بعد إعادة تسمية الملفات الحالية بإلحاق طابع زمني.

اعتبارات تشغيلية وأمان
- لا توجد أوامر تؤثر على التطبيق، كل التأثير داخل مجلد agent فقط.
- الردود تُكتب دائماً إلى outbox باسم <id>.response.json أو باسم ملف الأمر إذا لم يوجد id.
- عند فشل قراءة JSON (مثلاً الملف يُكتب حالياً)، سيحاول الوكيل حتى 3 مرات؛ عند تجاوز ذلك يُنقل الملف إلى agent/commands/inbox/invalid ويُكتب تقرير خطأ <stem>.error.json.

ترقية الإصدار
- تم تحديث نسخة الوكيل إلى 1.3.1-stage4.
