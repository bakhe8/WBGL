الخطة الشاملة لإعادة هيكلة نظام WBGL
(بناءً على كل ما تمت مناقشته – نسخة موحدة قابلة للتنفيذ)

هدف الخطة
تحويل نظام WBGL من كونه تراكم ترقيعات إلى منصة مهنية قابلة للتطوير مع الحفاظ على التشغيل المستمر وعدم إيقاف الخدمة. تعتمد الخطة على مبادئ Clean Architecture + Modular Monolith مع تجنب التعقيد المفرط، ويتم تنفيذها عبر مراحل آمنة ومتدرجة.

الهيكل النهائي المستهدف (التنظيم الجديد للملفات والمجلدات)
text
wbgl/
├── app/
│   ├── Domain/                      # نواة الأعمال (لا تعتمد على أي شيء خارجي)
│   │   ├── Guarantee/
│   │   │   ├── Entities/             (Guarantee.php, Batch.php)
│   │   │   ├── ValueObjects/         (GuaranteeId.php, Money.php)
│   │   │   ├── Policies/             (ReopenPolicy.php, ExtendPolicy.php)
│   │   │   ├── StateMachine/         (GuaranteeState.php)
│   │   │   └── Events/               (GuaranteeReopened.php)
│   │   ├── Workflow/
│   │   │   ├── Entities/             (Task.php, WorkflowStep.php)
│   │   │   └── Policies/             (AssignmentPolicy.php)
│   │   ├── Matching/
│   │   │   ├── Services/             (SuggestionEngine.php)
│   │   │   └── Events/               (MatchFound.php)
│   │   ├── Identity/                  (المستخدمون والأدوار)
│   │   │   ├── Entities/             (User.php, Role.php)
│   │   │   └── Policies/             (PermissionPolicy.php)
│   │   └── Shared/                    (مشترك بين الدومينات)
│   │       ├── Kernel/                (الواجهات المشتركة، الأحداث العامة)
│   │       └── Traits/                (Timestampable, SoftDelete)
│   │
│   ├── Application/                   # حالات الاستخدام وتنسيق العمل
│   │   ├── UseCases/
│   │   │   ├── Guarantee/
│   │   │   │   ├── CreateGuarantee/
│   │   │   │   │   ├── CreateGuaranteeCommand.php
│   │   │   │   │   ├── CreateGuaranteeHandler.php
│   │   │   │   │   └── CreateGuaranteeDTO.php
│   │   │   │   ├── ExtendGuarantee/
│   │   │   │   ├── ReduceGuarantee/
│   │   │   │   ├── ReleaseGuarantee/
│   │   │   │   └── ReopenGuarantee/
│   │   │   └── Workflow/
│   │   │       ├── SaveAndNext/
│   │   │       │   ├── SaveAndNextCommand.php
│   │   │       │   ├── SaveAndNextHandler.php
│   │   │       │   └── SaveAndNextDTO.php
│   │   │       └── BatchProcess/
│   │   ├── DTO/                       # كائنات نقل البيانات العامة
│   │   └── Services/                   # خدمات تطبيقية (مثل ImportService)
│   │
│   ├── Infrastructure/                 # تفاصيل تقنية (DB, Mail, Queue, ...)
│   │   ├── Persistence/
│   │   │   ├── Repositories/           (GuaranteeRepository.php, UserRepository.php)
│   │   │   └── Models/                 (GuaranteeModel.php, UserModel.php)
│   │   ├── Mail/                       (Mailer.php)
│   │   ├── Queue/                       (JobDispatcher.php)
│   │   ├── Scheduler/                   (CronManager.php)
│   │   ├── Notifications/               (Notifier.php)
│   │   ├── Integrations/                 (BankIntegration.php, EmailParser.php)
│   │   └── Logging/                      (Logger.php)
│   │
│   ├── Interfaces/                      # طبقة الواجهات (HTTP, CLI)
│   │   ├── Http/
│   │   │   ├── Controllers/             (GuaranteeController.php, WorkflowController.php)
│   │   │   ├── Middleware/               (AuthMiddleware.php, PermissionMiddleware.php)
│   │   │   ├── Requests/                 (CreateGuaranteeRequest.php, ReopenRequest.php)
│   │   │   └── Responses/                 (JsonResponseFormatter.php)
│   │   └── CLI/                          (Commands/ImportCommand.php, CheckDeadLettersCommand.php)
│   │
│   └── Support/                          # أدوات مساعدة مشتركة
│       ├── Helpers/                       (ArrayHelper.php, DateHelper.php)
│       ├── Exceptions/                     (DomainException.php, InfrastructureException.php)
│       └── Utils/                           (Validator.php, Encryptor.php)
│
├── bootstrap/                            # تهيئة التطبيق
│   └── app.php                            (حاوية الخدمات، تسجيل المقدمين)
│
├── routes/
│   ├── api.php                             (تعريف نقاط النهاية API)
│   └── web.php                              (تعريف صفحات الويب)
│
├── resources/
│   ├── views/                               (قوالب العرض – Blade أو غيره)
│   └── lang/                                 (ملفات الترجمة)
│
├── database/
│   ├── migrations/                           (هجرات قاعدة البيانات)
│   └── seeds/                                 (بيانات تجريبية)
│
├── public/
│   └── index.php                              (نقطة الدخول الوحيدة)
│
├── storage/                                    (ملفات التخزين المؤقت، السجلات)
│   ├── logs/
│   └── framework/
│
├── tests/                                      (الاختبارات)
│   ├── Unit/
│   ├── Integration/
│   └── Feature/
│
└── vendor/                                     (تبعيات Composer)
مبادئ إعادة الهيكلة (قواعد ذهبية)
لا تغير المنطق أثناء النقل – غيّر المكان والتنظيم فقط (Structural Refactor).

تحرك خطوة بخطوة – لا تحاول نقل كل شيء دفعة واحدة.

احتفظ بشبكة أمان – أضف اختبارات للمسارات الحرجة قبل البدء.

لا تخلط بين إعادة الهيكلة وإضافة ميزات جديدة – افصلهما تماماً.

اجعل النظام قابلاً للتشغيل بعد كل خطوة – اختبر واستمر.

المراحل التفصيلية (مع الجداول الزمنية التقديرية)
المرحلة 0: التمهيد والتثبيت (أسبوعان)
الهدف: بناء شبكة أمان وفهم الوضع الحالي بدقة.

المهام:

إعداد الاختبارات التكاملية للمسارات الحرجة:
save-and-next.php, reopen.php, extend.php, import.php.
استخدام أدوات مثل PHPUnit أو Codeception.
توثيق العقود الحالية للـ API:
تسجيل شكل الطلبات والاستجابات لكل نقطة نهاية (حتى لو كانت غير موحدة).
إعداد Composer PSR-4:
تعديل composer.json ليشمل النطاقات الجديدة (مثل WBGL\Domain\, WBGL\Application\...).
تشغيل composer dump-autoload.
إنشاء المجلدات الرئيسية (فارغة):
app/Domain, app/Application, app/Infrastructure, app/Interfaces, app/Support.
إنشاء نقطة دخول موحدة:
نقل محتوى index.php الحالي إلى public/index.php جديد (مع الاحتفاظ بالقديم كنسخة احتياطية).
إنشاء bootstrap/app.php لتهيئة الحاوية الأساسية (مثلاً باستخدام Simple DI Container أو Laravel Container).
إنشاء ملفات routes:
routes/api.php, routes/web.php (فارغة حالياً).
المرحلة 1: نقل البنية التحتية (Infrastructure) – 3 أسابيع
الهدف: نقل كل ما يتعلق بالوصول إلى البيانات والتكاملات الخارجية إلى مكانها الجديد، مع الحفاظ على العقود.

المهام:

نقل Repositories:
من app/Repositories/ إلى app/Infrastructure/Persistence/Repositories/.
تعديل use statements في الملفات التي تستدعيها.
نقل Models (إذا كانت موجودة):
إلى app/Infrastructure/Persistence/Models/.
نقل ملفات التكامل الخارجي:
مثل BankIntegration.php, EmailParser.php إلى app/Infrastructure/Integrations/.
نقل خدمات Mail, Queue, Notifications إلى مجلداتها الجديدة.
إنشاء واجهات Repositories في Domain:
لكل Repository منقول، أنشئ واجهة في app/Domain/Guarantee/Repositories/GuaranteeRepositoryInterface.php (مثلاً).
عدّل الـ Repository في Infrastructure لتنفيذ الواجهة.
تحديث الـ autoloading والتأكد من أن كل شيء لا يزال يعمل.
المرحلة 2: نقل منطق الأعمال (Domain & Application) – 4 أسابيع
الهدف: عزل منطق الأعمال في Domain، وتحويل الخدمات الحالية إلى Use Cases في Application.

المهام:

نقل الكيانات (Entities) والقيم (Value Objects):
من app/Entities/ أو الملفات المبعثرة إلى app/Domain/Guarantee/Entities/ وغيرها.
نقل السياسات (Policies):
مثل ReopenPolicy.php, ApprovalPolicy.php إلى app/Domain/Guarantee/Policies/.
نقل آلة الحالة (State Machine):
GuaranteeState.php إلى app/Domain/Guarantee/StateMachine/.
نقل الأحداث (Events):
GuaranteeReopened.php إلى app/Domain/Guarantee/Events/.
تحويل الخدمات الحالية إلى Use Cases:
مثال: app/Services/BatchService.php ← app/Application/UseCases/Workflow/BatchProcess/BatchProcessHandler.php.
مثال: save-and-next.php ← app/Application/UseCases/Workflow/SaveAndNext/SaveAndNextHandler.php.
فصل الـ Commands و DTOs:
لكل Use Case، أنشئ Command و DTO خاص به.
تحديث أي استدعاءات للمنطق المنقول (من Controllers أو API) لاستخدام الـ Use Cases الجديدة.
المرحلة 3: إعادة هيكلة واجهات HTTP و API – 3 أسابيع
الهدف: جعل طبقة HTTP رقيقة (Thin) وتوحيد استجابات API.

المهام:

نقل Controllers الحالية:
من api/*.php و views/*.php إلى app/Interfaces/Http/Controllers/.
اجعل كل Controller يستدعي Use Case واحد فقط.
إنشاء Middleware للصلاحيات والتحقق:
AuthMiddleware, PermissionMiddleware في app/Interfaces/Http/Middleware/.
توحيد استجابات API:
إنشاء JsonResponseFormatter يقوم بتغليف الاستجابة في envelope موحد.
عدّل كل Controller لاستخدامه.
نقل الـ Requests:
إنشاء كلاسات طلب (Request) تحتوي قواعد التحقق (Validation) في app/Interfaces/Http/Requests/.
إعادة تعريف المسارات:
بدلاً من الملفات المنفصلة في api/، عرّف نقاط النهاية في routes/api.php باستخدام Router (مثل FastRoute أو Laravel Router).
مثال: $router->post('/guarantees/{id}/reopen', [GuaranteeController::class, 'reopen']).
نقل ملفات العرض (Views):
انقل views/ إلى resources/views/.
تأكد من أن الـ Controllers تعيد العرض باستخدام مسار جديد.
المرحلة 4: توحيد قاعدة البيانات والهجرات – أسبوعان
الهدف: ضمان إمكانية إعادة بناء قاعدة البيانات من الصفر بثقة.

المهام:

إنشاء baseline migration:
استخدم أداة مثل mysqldump --no-data لتصدير هيكل قاعدة البيانات الحالي، ثم حوّله إلى ملف هجرة جديد (مثل 2024_01_01_000001_create_initial_schema.php).
اختبار baseline:
شغّل الهجرة على قاعدة بيانات فارغة وتحقق من تطابقها مع البيئة الحالية.
إعادة تنظيم ملفات الهجرات القديمة:
احتفظ بها للتاريخ، ولكن تأكد أن الجديد يعتمد على baseline فقط.
إضافة seeders للبيانات الأساسية (مثل أنواع الضمانات، الصلاحيات الافتراضية).
المرحلة 5: التحسين والمراقبة (مستمرة بعد 3 أشهر)
الهدف: ضمان استمرارية الالتزام بالهيكل الجديد وتحسين الجودة.

المهام:

إضافة أدوات تحليل ثابتة:
PHPStan (مستوى 8 أو 9) مع قواعد تمنع انتهاك الطبقات.
Deptrac لفرض الحدود بين المجلدات.
تحسين الاختبارات:
كتابة Unit Tests للـ Domain و Use Cases.
زيادة تغطية Integration Tests للمسارات الحرجة.
مراقبة مؤشرات الجودة:
استخدام PhpMetrics أو مماثل لقياس التعقيد والدين التقني شهرياً.
توثيق المعمارية:
تحديث README.md بخريطة清晰ة للهيكل وكيفية إضافة ميزة جديدة.
جدول زمني تقديري إجمالي
المرحلة	المدة	النشاط الرئيسي
0	أسبوعان	التمهيد، اختبارات، autoloader، مجلدات
1	3 أسابيع	نقل Infrastructure (Repositories, Models, Integrations)
2	4 أسابيع	نقل Domain وتحويل Use Cases
3	3 أسابيع	إعادة هيكلة HTTP/API وتوحيد الاستجابات
4	أسبوعان	توحيد قاعدة البيانات (baseline migration)
5	مستمر	تحسين الجودة، أدوات، مراقبة
المجموع التقديري: 14 أسبوعاً (حوالي 3.5 أشهر) للوصول إلى الهيكل المستهدف مع استقرار كامل.

نصائح حرجة للتنفيذ
لا تبدأ المرحلة التالية قبل التأكد من استقرار المرحلة الحالية.

احتفظ بنسخة احتياطية كاملة قبل كل خطوة نقل كبيرة.

استخدم التحكم بالنسخ (Git) بشكل مكثف، وأنشئ فروعاً منفصلة لكل مرحلة.

عند نقل الملفات، استخدم git mv للحفاظ على التاريخ.

اجعل فريقك (أو نفسك) يوثق كل تغيير في سجل التحديثات (CHANGELOG).
