# WBGL Full-Scope Transformation Master Plan

## 1) Charter
هذا المستند هو خطة تنفيذ شاملة لتحويل WBGL بحيث يحقق كل الأهداف المكتوبة في [forWBGL.md](./forWBGL.md) بشكل كامل.

مبدأ الخطة:
- لا يوجد `Out of Scope`.
- كل المتطلبات `Mandatory`.
- التنفيذ يكون على مراحل ترتيبية فقط، وليس استبعادية.

الهدف التنفيذي:
- تحقيق سهولة استخدام أعلى.
- رفع قابلية الصيانة والتطوير.
- تقليل الحمل الذهني للمستخدم.
- زيادة اتساق السلوك الوظيفي والواجهات.
- الحفاظ على قوة الضبط التشغيلي الحالية في WBGL وعدم إضعافها.

## 2) Non-Negotiable Rules
- جميع البنود في هذه الخطة إلزامية 100%.
- لا يُسمح بإغلاق أي موجة تنفيذ قبل استيفاء معايير القبول الخاصة بها.
- لا يُسمح بتحسين واجهة فقط بدون تحسين منطق التشغيل المقابل.
- أي تغيير معماري يجب أن يحافظ على استمرارية العمل الفعلي للعمليات اليومية.

## 3) Unified Requirement Catalog (Mandatory)
هذه نسخة موحدة بدون تكرار للنقاط المذكورة في الملف الحالي.

| ID | Requirement | Mandatory |
|---|---|---|
| R01 | هيكل النظام أوضح وأبسط للفهم | Yes |
| R02 | سهولة الصيانة أعلى على المدى الطويل | Yes |
| R03 | أثر التغيير أكثر قابلية للتنبؤ وأقل مخاطرة | Yes |
| R04 | اتساق أعلى في أنماط الكود والتنفيذ | Yes |
| R05 | فصل مسؤوليات أفضل داخل المنظومة | Yes |
| R06 | نموذج بيانات أساسي منظم بحقوق واضحة (مع تقليل الاعتماد على JSON كمصدر وحيد) | Yes |
| R07 | توحيد واجهات API في الشكل والسلوك والأخطاء | Yes |
| R08 | تجربة بداية استخدام أسهل للمستخدم الجديد | Yes |
| R09 | تدريب الموظفين الجدد أسرع | Yes |
| R10 | حمل ذهني أقل في الاستخدام اليومي | Yes |
| R11 | تنقل أبسط ووضوح أعلى في تدفق الشاشات | Yes |
| R12 | ملاءمة أفضل للتشغيل السريع للفرق غير التقنية | Yes |
| R13 | إدارة مهام وإسناد واضحة (`assigned_to`, `only_my_tasks`) | Yes |
| R14 | إدارة الملف الشخصي وكلمة المرور بشكل مباشر وواضح | Yes |
| R15 | تحقق مدخلات، صلاحيات، مصادقة، ورسائل خطأ أكثر قابلية للتوقع | Yes |
| R16 | إحساس أسرع وأبسط عند الأحجام الصغيرة والمتوسطة | Yes |

## 4) Execution Model (Full Scope, Sequenced Waves)
لا يوجد حذف نطاق. يوجد فقط ترتيب تنفيذ.

| Wave | Focus | Requirements Covered | Exit Condition |
|---|---|---|---|
| Wave 0 | Baseline + Program Governance | R01-R16 (قياس أساسي) | Baseline Metrics Approved |
| Wave 1 | UX Simplification + Task-Centric Flow | R08,R09,R10,R11,R12,R13,R14,R16 | New UX Flow Live for Core Journey |
| Wave 2 | API Standardization + Validation/Errors/Auth Consistency | R07,R15,R03,R04 | Unified API Contract Enforced |
| Wave 3 | Architecture Refactor + Separation + Code Consistency | R01,R02,R04,R05,R03 | New Module Boundaries Stable |
| Wave 4 | Data Model Modernization (Typed Core Model) | R06,R03,R02 | Core Typed Model in Production |
| Wave 5 | Adoption, Training, and Performance Hardening | R09,R10,R11,R12,R16 + final closure | KPI Targets Met and Signed Off |

## 5) Workstreams and Deliverables

### WS-A: Architecture and Folder Structure
Deliverables:
- هيكل مجلدات موحد Backend / Domain / UI / Shared.
- دليل Dependency Rules واضح (من يستدعي من).
- نقل الكود حسب الوحدات بدون كسر التشغيل.

Definition of Done:
- لا توجد استدعاءات عشوائية بين الوحدات خارج القواعد.
- كل وحدة لها نقطة دخول واضحة.
- تقارير الفحص الداخلي تؤكد انخفاض التداخل بين الوحدات.

### WS-B: API Contract Unification
Deliverables:
- معيار موحد للاستجابة (`success`, `data`, `error`, `code`, `request_id`).
- معيار موحد للتحقق من المدخلات.
- معيار موحد للأخطاء الوظيفية وأخطاء الصلاحيات.

Definition of Done:
- كل endpoint يلتزم بنفس envelope.
- كل أخطاء التحقق تستخدم نفس النمط.
- توثيق API الفعلي متطابق مع السلوك الحقيقي.

### WS-C: Core Data Model Modernization
Deliverables:
- إضافة Typed Fields أساسية للضمانات.
- إبقاء `raw_data` كـ `compatibility layer` مرحليًا.
- خطة قراءة/كتابة انتقالية آمنة (Dual-write ثم cutover).

Definition of Done:
- كل الشاشات الأساسية تقرأ من النموذج المنظم.
- التوافق الخلفي لا ينكسر أثناء الانتقال.
- اكتمال تدقيق البيانات بعد الترحيل بنسبة 100%.

### WS-D: UX and Cognitive Load Reduction
Deliverables:
- `Simple Mode` افتراضي للموظف اليومي.
- مسارات `Wizard` للإنشاء/الاستيراد/التصحيح.
- تبسيط الشاشات وتقليل التشتت في الإجراءات.

Definition of Done:
- انخفاض عدد النقرات في الرحلة الأساسية.
- انخفاض نقاط التردد حسب جلسات اختبار المستخدم.
- ارتفاع معدل الإنجاز بدون مساعدة.

### WS-E: Work Queue and Assignment Clarity
Deliverables:
- `assigned_to` واضح في كل الرحلات.
- `only_my_tasks` موحد في كل شاشات العمل.
- صندوق عمل يومي واضح بالأولوية والحالة.

Definition of Done:
- كل مستخدم يرى مهامه بوضوح.
- تقليل وقت العثور على المهمة التالية.
- تقليل فقدان السياق عند العودة للعمل غير المكتمل.

### WS-F: User Profile and Account Clarity
Deliverables:
- صفحة ملف شخصي موحدة.
- تغيير كلمة مرور واضح وسريع.
- إعدادات شخصية عملية (لغة، اتجاه، واجهة، تفضيلات عملية).

Definition of Done:
- إنهاء مهام الحساب الشخصي في خطوات أقل.
- عدم الحاجة لمساعدة تقنية لإدارة الحساب.

### WS-G: Validation, Permission, Auth Predictability
Deliverables:
- مصفوفة صلاحيات موحدة وقابلة للتتبع.
- رسائل خطأ عملية: ماذا حدث + ماذا أفعل الآن.
- توحيد مصادقة الجلسة/الـ token حسب المسار.

Definition of Done:
- انخفاض أخطاء الاستخدام الناتجة عن غموض الرسائل.
- عدم وجود تعارض بين ما يظهر في الواجهة وما يُنفذ في المنطق.

### WS-H: Runtime Speed for Small/Medium Workload
Deliverables:
- تحسين استعلامات القوائم والبحث والفلترة.
- تحسين خطوات الرحلة الأساسية (إنشاء، تحديث، اعتماد، إصدار).
- تحسين استرجاع الحالة عند العودة للمهام غير المكتملة.

Definition of Done:
- تحسن زمن الاستجابة في سيناريو العمل اليومي.
- تحسن زمن إكمال المعاملة من بداية إلى نهاية.

## 6) KPI Framework (Mandatory Measurement)
لا يوجد إغلاق مشروع بدون تحقيق الأهداف الرقمية.

| KPI | Baseline (Wave 0) | Target | Verification |
|---|---|---|---|
| زمن إنجاز معاملة واحدة (P50) | قياس فعلي | -30% | Journey timing logs |
| زمن إنجاز معاملة واحدة (P90) | قياس فعلي | -40% | Journey timing logs |
| عدد النقرات للرحلة الأساسية | قياس فعلي | -35% | UX instrumentation |
| معدل أخطاء المستخدم في أول أسبوع تدريب | قياس فعلي | -50% | Support + audit labels |
| زمن تدريب موظف جديد حتى الإنتاجية | قياس فعلي | -40% | Training program tracking |
| نسبة تذاكر الدعم المتعلقة بالغموض | قياس فعلي | -50% | Helpdesk categories |
| نسبة فشل API الوظيفي بسبب سوء مدخلات | قياس فعلي | -30% | API error analytics |
| رضا المستخدم التشغيلي (Internal survey) | قياس فعلي | +25 نقاط | Monthly survey |

## 7) Rollout Strategy (No Functional Drop)
- Feature flags لكل مسار جديد.
- تشغيل تدريجي على فرق مختارة.
- Dual-run بين المسار الحالي والجديد عند النقاط الحساسة.
- Cutover رسمي فقط بعد تحقيق KPI المرحلة.
- خطة رجوع سريعة (`rollback`) لكل موجة.

## 8) Program Governance
- Weekly Execution Review (تقدم موجات + مخاطر).
- Biweekly User Validation Session (اختبار عملي مع موظفين فعليين).
- Monthly KPI Gate (لا انتقال لموجة لاحقة بدون نتائج القياس).
- Architecture Gate قبل أي refactor كبير.
- Operations Gate قبل أي تغيير يؤثر على تدفق العمل اليومي.

## 9) Risks and Mitigations
| Risk | Impact | Mitigation |
|---|---|---|
| تضخم التغيير بسبب Full Scope | High | Wave sequencing + strict gates |
| مقاومة المستخدم للتغيير | Medium | تدريج + تدريب عملي + Simple Mode |
| عدم اتساق الواجهة والمنطق أثناء الانتقال | High | Contract tests + UI-API parity checks |
| مخاطر ترحيل البيانات | High | Dual-write + data audit checkpoints |
| بطء التنفيذ بسبب كثرة البنود | Medium | Parallel workstreams with clear ownership |

## 10) Final Completion Gate
لا يعتبر هذا البرنامج منتهيًا إلا عند تحقق الشروط التالية كلها:
- R01 إلى R16 جميعها محققة فعليًا.
- KPI targets محققة ومثبتة رقميًا.
- اعتماد رسمي من التشغيل، الجودة، والدعم.
- إغلاق جميع البنود الحرجة بدون استثناء.

---

## Ownership Template (to be filled)
| Area | Owner | Backup | Start | Target Finish | Status |
|---|---|---|---|---|---|
| WS-A | TBD | TBD | TBD | TBD | Planned |
| WS-B | TBD | TBD | TBD | TBD | Planned |
| WS-C | TBD | TBD | TBD | TBD | Planned |
| WS-D | TBD | TBD | TBD | TBD | Planned |
| WS-E | TBD | TBD | TBD | TBD | Planned |
| WS-F | TBD | TBD | TBD | TBD | Planned |
| WS-G | TBD | TBD | TBD | TBD | Planned |
| WS-H | TBD | TBD | TBD | TBD | Planned |
