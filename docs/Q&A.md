Requires Business Confirmation
1) تعريف رسمي لـ State vs Stage vs Approval Milestones

الأسئلة المطلوبة:

ما التعريف الرسمي لكل من:

State (pending / ready / released …)

Stage (draft …)

مراحل Timeline (audited / analyzed / supervised / approved / signed)

هل هذه الثلاثة:

تمثل نفس المسار بأسماء مختلفة؟

أم تعمل بالتوازي (State تشغيلي، Stage إداري، Timeline موافقات)؟

ما هي التركيبات المسموحة وغير المسموحة (مثلاً: ready + draft هل هذا طبيعي؟).

المخرجات المطلوبة (قرار عمل):

جدول حالات (State machine) + جدول مراحل (Stage) + mapping واضح بينهما (إن وجد).

قواعد منع التناقضات (Validation rules).

2) سياسة صلاحيات “تأكيد التدقيق Audit” وسبب إتاحتها لـ Admin

الأسئلة المطلوبة:

هل زر Audit مسموح لـ Admin كـ “صلاحية سوبر” دائمًا؟

أم هذا خاص ببيئة اختبار/طور تطوير؟

في بيئة الإنتاج، هل يجب أن يكون Audit حصريًا على دور Data Auditor؟

المخرجات المطلوبة:

RBAC matrix رسمي يحدد من يملك:

Audit / Analyze / Supervise / Approve / Sign

Manual match (bank/supplier)

تعديل Master Data (banks/suppliers)

إعادة فتح Batch

قرار امتثال: هل Admin actions تعتبر “تدقيقًا صالحًا” أم “تجاوزًا إداريًا” يجب تمييزه؟

3) Enforcement Mechanism لحالة Released (قفل حقيقي أم تحذير)

الأسئلة المطلوبة:

عند تحويل الضمان إلى released:

هل يجب أن يصبح Read-only بالكامل (No edit/no actions)؟

أم يسمح بتعديلات محددة (مثلاً: إضافة ملاحظة فقط)؟

ما مصير الأفعال التشغيلية (extend/reduce/empty) بعد release:

ممنوعة دائمًا؟

أم مسموحة بقرار استثنائي؟

المخرجات المطلوبة:

قائمة “Allowed actions in released state” + تحقق على مستوى API (وليس UI فقط).

إن وُجد استثناء: من يفعّله وكيف يُسجّل سبب الاستثناء؟

4) مصير الضمانات في نطاق المراجعة 70–95 (Review Band)

الأسئلة المطلوبة:

ما الذي يحدث عندما تكون الثقة بين 0.7 و 0.95؟

هل تُنشأ مهمة تلقائية؟

لمن تُسند (Auditor؟ Analyst؟ Supervisor؟)؟

هل تمنع الانتقال إلى ready حتى يتم القرار؟

ما “القرار النهائي” للمطابقة:

من يملكه؟

وهل يتطلب موافقة ثانية؟

المخرجات المطلوبة:

تعريف واضح لمسار “Needs Review”:

Queue / SLA / owner role

نتائج ممكنة: confirm / override / reject

أثرها على Timeline والـ ML.

5) حوكمة التعلم الآلي: من يؤثر على Knowledge Base

الأسئلة المطلوبة:

من يملك صلاحية أن يكون “تأكيده” مؤثرًا على:

Confirmed Patterns (تعلم)

Penalties/Rejections (عقوبات)

هل كل Manual Match يرفع Count ويؤثر على تعلم النظام؟

هل يوجد مفهوم “Reviewer-confirmed learning” (تأكيد ثنائي)؟

المخرجات المطلوبة:

سياسة Learning Governance:

Roles allowed to teach

Minimum confirmations required

Audit/rollback of learned patterns

Separation بين “تصحيح تشغيلي” و“تعليم نموذج”.

6) Decision Loop لمؤشرات Dashboard (غير الانتهاءات)

الأسئلة المطلوبة:

هل توجد عتبات تنبيه/تصعيد لمؤشرات مثل:

AI Confidence

Manual intervention %

First Time Right

Avg processing time

ماذا يحدث عند التدهور؟

تنبيه؟

فتح Incident؟

تعديل إعدادات المطابقة تلقائيًا/يدويًا؟

تدريب/تنظيف بيانات؟

المخرجات المطلوبة:

KPI thresholds + actions playbook:

Trigger → Owner → Action → Expected outcome → Tracking.

7) إعادة فتح Batch: الصلاحيات + السبب + أثر إعادة الفتح

الأسئلة المطلوبة:

من يملك صلاحية “Re-open batch”؟

هل يتطلب إدخال سبب أو تعليق؟

عند إعادة الفتح:

هل تتغير ضمانات الدفعة؟

هل يعاد تشغيل المطابقة؟

هل يُسجل حدث على Timeline لكل ضمان؟

المخرجات المطلوبة:

Batch governance policy:

RBAC + Reason required

Event logging (BatchEvent + GuaranteeEvent)

تأثير على التقارير.

8) Smart Paste: هل ينشئ Batch؟ وهل يخضع لنفس الحوكمة؟

الأسئلة المطلوبة:

هل Smart Paste:

ينشئ Batch باسم manual_paste؟

أم ينشئ ضمان بدون Batch؟

هل يخضع لنفس:

Matching thresholds؟

Audit queue؟

Approval workflow؟

هل يوجد تحقق جودة إضافي (Mandatory fields validation / duplicate detection)؟

المخرجات المطلوبة:

تعريف قناة Smart Paste في نموذج البيانات:

source_channel = smart_paste

batch_id optional/required

قواعد ضبط الجودة.

9) أولوية الثقة بين مصادر الإدخال وحل التعارضات

الأسئلة المطلوبة:

عند تعارض القيم بين:

Excel import vs manual edit vs smart paste

ما مصدر “الحق”؟

هل المستخدم النهائي دائمًا أعلى ثقة؟

هل Excel يعتبر مرجعًا رسميًا؟

هل يسمح بتعديل رقم الضمان أو العقد بعد الإدخال؟

ما سياسة Versioning/History؟

المخرجات المطلوبة:

Data precedence rules + immutability rules:

Fields immutable (مثلاً guarantee_number)

Fields editable with logging

Conflict resolution policy.
