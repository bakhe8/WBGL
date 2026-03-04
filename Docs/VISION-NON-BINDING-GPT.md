WBGL Architecture Recovery & Refactor Program

المدة الواقعية: 12–16 أسبوع
الهدف النهائي: تحويل WBGL من نظام نما بالترقيعات إلى منصة قابلة للتطوير طويل الأمد.

1️⃣ الهدف الحقيقي للخطة

الخطة تهدف إلى تحقيق 6 أشياء:

تثبيت سلوك النظام قبل أي تغيير

إعادة تنظيم الكود ليعكس المعمارية

توحيد منطق الصلاحيات والسياسات

إزالة التداخل بين الطبقات

إنشاء بنية قاعدة بيانات قابلة لإعادة البناء

منع عودة الفوضى مستقبلاً

2️⃣ المعمارية النهائية المستهدفة

النظام سيصبح:

Modular Monolith + Clean Architecture

الهيكل النهائي:

wbgl
│
├── app
│
│   ├── Domain
│   │   ├── Guarantee
│   │   ├── Workflow
│   │   ├── Identity
│   │   ├── Matching
│   │   └── Shared
│   │
│   ├── Application
│   │   ├── UseCases
│   │   ├── DTO
│   │   └── Services
│   │
│   ├── Infrastructure
│   │   ├── Persistence
│   │   ├── Mail
│   │   ├── Queue
│   │   ├── Scheduler
│   │   ├── Notifications
│   │   └── Integrations
│   │
│   ├── Interfaces
│   │   ├── Http
│   │   │   ├── Controllers
│   │   │   ├── Middleware
│   │   │   └── Requests
│   │   │
│   │   └── CLI
│   │
│   └── Support
│
├── routes
│
├── resources
│
├── database
│
├── public
│
└── tests
3️⃣ القواعد المعمارية (Architecture Guardrails)

هذه القواعد تمنع عودة الفوضى.

القاعدة 1

Domain لا يعتمد على أي شيء خارجي

Domain → لا يستورد Infrastructure
القاعدة 2

Controllers لا تحتوي منطق أعمال

Controller → UseCase فقط
القاعدة 3

UseCases لا تحتوي SQL

القاعدة 4

Views لا تحتوي Queries

القاعدة 5

كل قرار صلاحية يمر عبر Policy Engine واحد

Domain/Policies
4️⃣ مرحلة التمهيد (Phase 0) — تثبيت السلوك

المدة: 2 أسبوع

الهدف: منع كسر النظام أثناء إعادة الهيكلة.

الخطوات

1️⃣ إنشاء Autoload عبر Composer

PSR-4

2️⃣ إنشاء Golden Path Tests

اختبارات للمسارات الحرجة:

create guarantee
extend guarantee
release guarantee
reopen guarantee
save-and-next

هذه الاختبارات يجب أن تمر طوال المشروع.

3️⃣ توثيق API الحالي

حتى لو غير متناسق.

نسجل:

endpoint
request
response
error

4️⃣ رسم خريطة النظام

Inventory:

Endpoints
Services
Repositories
Database Tables
Integrations
5️⃣ المرحلة الأولى — إنشاء الهيكل الجديد

المدة: 1 أسبوع

لا يتم نقل أي كود بعد.

إنشاء فقط:

app/Domain
app/Application
app/Infrastructure
app/Interfaces
app/Support
6️⃣ المرحلة الثانية — نقل Infrastructure

المدة: 3 أسابيع

نبدأ من الأسفل للأعلى.

النقل
Repositories
Models
Database access
Mail
Queue
Scheduler

إلى:

Infrastructure

مثال

app/Repositories/GuaranteeRepository
↓
app/Infrastructure/Persistence/Repositories
تعريف interfaces

الـ interface يبقى في:

Domain
7️⃣ المرحلة الثالثة — نقل Domain

المدة: 3 أسابيع

هنا يتم استخراج منطق الأعمال الحقيقي.

إنشاء:

Domain/Guarantee
Domain/Workflow
Domain/Identity
Domain/Matching
نقل
Policies
Lifecycle
Business rules

إلى Domain.

مثال
ReopenPolicy
GuaranteeStateMachine
8️⃣ المرحلة الرابعة — نقل Application Logic

المدة: 4 أسابيع

تحويل الخدمات إلى Use Cases.

مثال:

BatchService

↓

Application/UseCases/Workflow/BatchProcess
أهم ملف يجب تفكيكه
save-and-next

يتحول إلى:

Application/UseCases/Workflow/SaveAndNextHandler
9️⃣ المرحلة الخامسة — تنظيف طبقة HTTP

المدة: 3 أسابيع

نقل API من:

api/*.php

إلى:

routes/api.php
Controllers تصبح بسيطة
Request
↓
Controller
↓
UseCase
↓
Response
نقل SQL من Views

أي Query في views تنتقل إلى:

Query Service
🔟 المرحلة السادسة — توحيد قاعدة البيانات

المدة: 2 أسبوع

إنشاء:

baseline migration

يمثل قاعدة البيانات كاملة.

اختبار:

migrate fresh

يجب أن يبني النظام بالكامل.

1️⃣1️⃣ المرحلة السابعة — إزالة القديم

المدة: 1 أسبوع

حذف:

api/*.php القديمة
app/Services القديمة
code غير مستخدم
1️⃣2️⃣ المرحلة الثامنة — توحيد API

المدة: 1 أسبوع

شكل موحد للاستجابة:

success
{
  ok: true,
  data: {}
}

error
{
  ok: false,
  code:
  message:
}
1️⃣3️⃣ المرحلة التاسعة — أدوات الحماية

المدة: مستمرة

إضافة:

PHPStan
Deptrac
PhpMetrics

لفرض الحدود المعمارية.

1️⃣4️⃣ تعريف النجاح لكل مرحلة
نهاية المرحلة 2

كل Repository أصبح داخل

Infrastructure
نهاية المرحلة 3

كل Policy أصبح داخل

Domain
نهاية المرحلة 4

كل Use Case داخل

Application
نهاية المرحلة 5

كل Endpoint:

Controller → UseCase
1️⃣5️⃣ مؤشرات النجاح

بعد انتهاء البرنامج يجب أن يتحسن:

المعيار	قبل	بعد
وضوح المعمارية	4	8
قابلية الصيانة	4	8
الدين التقني	8	4
خطر التغيير	عالي	منخفض
1️⃣6️⃣ النتيجة النهائية

النظام سيتحول من:

Organic Patchwork System

إلى:

Structured Modular Monolith
1️⃣7️⃣ أهم نصيحة في التنفيذ

لا تجمع بين:

Refactor
Feature Development

في نفس الوقت.

نفذ:

Refactor Branch

مستقل.

1️⃣8️⃣ خطوة البداية العملية

ابدأ الآن فقط بـ:

1️⃣ إنشاء المجلدات الخمسة في app
2️⃣ إعداد Composer Autoload
3️⃣ كتابة Golden Path Tests

ولا تنقل أي ملف بعد.
