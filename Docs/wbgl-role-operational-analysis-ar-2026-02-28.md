# تحليل WBGL التشغيلي حسب الأدوار الفعلية (Role-Based Operational Analysis)

تاريخ التحليل: 2026-02-28  
منهجية: UI-first ثم تأكيد بالـ RBAC/Policy/API.  
نطاق الأدلة: `maint/create_rbac_tables.php`, `maint/seed_permissions.php`, `app/Support/Guard.php`, `app/Support/UiPolicy.php`, `app/Support/ViewPolicy.php`, `app/Services/WorkflowService.php`, `app/Services/GuaranteeVisibilityService.php`, `api/*.php`, `index.php`, `views/*.php`, `public/js/*.js`.

---

## PHASE 0 — استخراج الأدوار الفعلية

الأدوار المعرّفة فعليًا:  
`developer`, `data_entry`, `data_auditor`, `analyst`, `supervisor`, `approver`, `signatory`  
(دليل: `maint/create_rbac_tables.php:65-73`, `maint/seed_permissions.php:33-64`)

ملاحظات عامة قبل التفصيل:
- كل صفحة غير `users.php/settings.php/maintenance.php` تتطلب Login فقط غالبًا، وليس Permission محدد.  
  (دليل: `app/Support/ViewPolicy.php:15-19`, واستدعاءات `ViewPolicy::guardView` في `views/*.php`)
- إخفاء عناصر UI يعتمد على `UiPolicy` و`policy.js`، لكنه لا يغطي كل الأزرار.  
  (دليل: `app/Support/UiPolicy.php:13-25`, `public/js/policy.js:92-126`)
- الوصول للبيانات نفسها مفلتر حسب الدور عبر `GuaranteeVisibilityService`.  
  (دليل: `app/Services/GuaranteeVisibilityService.php:33-48`)

### 1) دور `data_entry`
- الشاشات التي يصل لها: الصفحة الرئيسية، الدفعات، تفاصيل الدفعة، الطباعة، الإحصائيات، `confidence-demo`؛ ولا يصل إلى `settings/users/maintenance`.  
  (دليل: `ViewPolicy` + `UiPolicy` + `nav-manifest`)
- الأفعال المسموحة: إدخال يدوي، استيراد Excel، إدارة بيانات تشغيلية (`extend/reduce/release/update/batches/reopen/undo`) بحكم `manage_data`.  
  (دليل: `seed_permissions.php` + `api/create-guarantee.php:18`, `api/import.php:15`, `api/extend.php:17`, `api/release.php:17`, `api/batches.php:15`, `api/reopen.php:17`, `api/undo-requests.php:21`)
- الأفعال المحجوبة: إدارة المستخدمين والإعدادات والصيانة.  
  (دليل: `manage_users` مطلوب في `views/settings.php`, `views/users.php`, `views/maintenance.php`)
- انتقالات الحالة التي يتحكم بها: حالات القرار التشغيلية `pending/ready/released` عبر save/release/reopen، لكن ليس Workflow الرسمي `draft→audited→...`.  
  (دليل: `api/save-and-next.php`, `api/release.php`, `api/reopen.php`, مقابل `WorkflowService TRANSITION_PERMISSIONS`)
- صلاحيات الحوكمة: يستطيع فتح Undo Request والموافقة/الرفض/التنفيذ لأنه يملك `manage_data`.  
  (دليل: `api/undo-requests.php:21,69-87`)

### 2) دور `data_auditor`
- الشاشات: نفس صفحات Login-only الأساسية، بدون صفحات الإدارة.
- الأفعال المسموحة: التقدم من `draft` إلى `audited` فقط عبر Workflow button.
  (دليل: `WorkflowService.php:35-41`, `partials/record-form.php:139-149`)
- الأفعال المحجوبة: الإدخال اليدوي، الاستيراد، كل mutate endpoints التي تحتاج `manage_data`.
- انتقالات الحالة: `draft -> audited` فقط.
- صلاحيات الحوكمة: لا يوجد Undo/Reopen/Break-glass.

### 3) دور `analyst`
- الشاشات: صفحات Login-only الأساسية.
- الأفعال المسموحة: التقدم من `audited` إلى `analyzed`.
- الأفعال المحجوبة: create/import/manage_data/manage_users.
- انتقالات الحالة: `audited -> analyzed`.
- صلاحيات الحوكمة: لا يوجد.

### 4) دور `supervisor`
- الشاشات: صفحات Login-only الأساسية.
- الأفعال المسموحة: `analyzed -> supervised`.
- الأفعال المحجوبة فعليًا: رغم امتلاكه `reopen_batch` و`reopen_guarantee` في RBAC، لا يستطيع التنفيذ مباشرة لأن endpoints نفسها تبدأ بـ `manage_data`.
  (دليل: `seed_permissions.php:45-49` مقابل `api/reopen.php:17`, `api/batches.php:15`)
- انتقالات الحالة: `analyzed -> supervised`.
- صلاحيات الحوكمة: نظريًا reopen، عمليًا متعثر بدون `manage_data`.

### 5) دور `approver`
- الشاشات: صفحات Login-only الأساسية.
- الأفعال المسموحة: `supervised -> approved`.
- الأفعال المحجوبة فعليًا: نفس تعارض supervisor في reopen endpoints بسبب gate `manage_data`.
- انتقالات الحالة: `supervised -> approved`.
- صلاحيات الحوكمة: يملك `break_glass_override` نظريًا، لكن استخدامه في reopen/batch reopen محجوب أيضًا عند بداية endpoint بـ `manage_data`.
  (دليل: `seed_permissions.php:50-55` مقابل `api/reopen.php:17`, `api/batches.php:15`)

### 6) دور `signatory`
- الشاشات: صفحات Login-only الأساسية.
- الأفعال المسموحة: `approved -> signed` (مع منطق تواقيع متعددة قبل الإغلاق النهائي).
  (دليل: `api/workflow-advance.php:61-76`)
- الأفعال المحجوبة: كل create/import/manage_data/manage_users.
- انتقالات الحالة: `approved -> signed`.
- صلاحيات الحوكمة: لا يوجد.

### 7) دور `developer`
- الشاشات: كل الشاشات.
- الأفعال المسموحة: كل الأفعال (صلاحية `*`).
  (دليل: `Guard.php:52-55`, `seed_permissions.php:89-96`)
- الأفعال المحجوبة: غير واضح (إلا قيود منطق العمل نفسها).
- انتقالات الحالة: كل انتقالات workflow + كل عمليات mutate.
- صلاحيات الحوكمة: كامل الصلاحيات (reopen, break-glass, manage_users, إلخ).

---

## PHASE 1 — محاكاة يوم عمل لكل دور

## دور `data_entry`
- المهام اليومية: فتح الدفعات، إدخال يدوي/Excel/Smart Paste، تثبيت المورد/البنك، حفظ سريع، تنفيذ `extend/reduce/release`.  
  (دليل UI: `index.php:870-896`, `input-modals.controller.js`, `records.controller.js:521-631`)
- القرارات المتكررة: هل أقبل المطابقة المقترحة أم أصحح؟ هل الحالة جاهزة للإفراج؟ هل أفتح Undo عند الخطأ؟
- أخطاء متوقعة: ملف Excel فيه صفوف ناقصة/مكررة؛ النظام يتجاوز صفوفًا ويعيد تفاصيل أخطاء/تخطي.  
  (دليل: `ImportService.php:142-154`, `191-205`, `218-226`)
- موقف ضغط: دفعة كبيرة قرب موعد انتهاء؛ يحتاج قرارات سريعة مع خطر ضغط أزرار ظاهرة لكن غير مصرح بها حسب الدور في بعض الصفحات.
- احتكاك صلاحيات: أقل احتكاك هنا لأنه يملك `manage_data`، لكنه يحمل صلاحيات حوكمة ثقيلة (Undo approve/reject/execute) قد تكون أكبر من دوره التشغيلي.
- ما يبطئه: تباين واجهات الرد (بعض APIs تعيد HTML error وأخرى JSON).  
  (دليل: `api/extend.php` text/html مقابل `api/reopen.php` json)
- ما يربكه: وجود مسارين للإدخال اليدوي (`/api/create-guarantee.php` و`/api/manual-entry.php`) وسلوك متقارب.
- ما يبدو غير آمن: نفس الدور التشغيلي يستطيع تنفيذ مراحل Undo الحساسة.
- ما يبدو مقيدًا بلا داعٍ: غير واضح.
- ما يبدو قويًا جدًا بلا شرح: صلاحية Undo workflow كاملة.

## دور `data_auditor`
- المهام اليومية: مراجعة السجلات عند `draft` ثم ضغط workflow advance.
- القرارات: هل البيانات كافية للمرور إلى `audited` أم لا.
- أخطاء متوقعة: يشاهد أزرار تشغيلية (`save/release/extend/reduce`) لكن التنفيذ يفشل 403/Permission Denied.
  (دليل: أزرار بدون policy في `record-form.php:78-99` + endpoints تحتاج `manage_data`)
- موقف ضغط: تكدس مهام مرحلة واحدة فقط مع عدم وجود واجهة واضحة تقول "أنت مقيّد بهذه المرحلة فقط".
- احتكاك صلاحيات: مرتفع في UI لأن المنع يحدث بعد الضغط كثيرًا.
- ما يبطئه: تجارب فاشلة نتيجة ظهور أفعال ليست ضمن الدور.
- ما يربكه: لماذا بعض الأزرار تظهر ثم ترفض.
- ما يبدو غير آمن: فتح سجل قد يُحدث Auto-Match وتغييرات بدون فعل صريح منه.
  (دليل: `api/get-record.php:208-255`, `285-329`)
- ما يبدو مقيدًا بلا داعٍ: عدم وجود مسار تصعيد سريع داخل الواجهة عند رفض صلاحية.
- ما يبدو قويًا جدًا بلا شرح: ليس واضحًا لهذا الدور.

## دور `analyst`
- المهام اليومية: استلام حالات `audited`، تحليل، ثم advance إلى `analyzed`.
- القرارات: قبول المعطيات أم تعليق التحليل.
- أخطاء متوقعة: نفس مشكلة أزرار mutate الظاهرة.
- موقف ضغط: ضغط زمني مع ضرورة المحافظة على اتساق workflow.
- احتكاك صلاحيات: مشابه لـ auditor.
- ما يبطئه: إعادة تحميل الصفحة المتكررة بعد transitions.
- ما يربكه: الفصل غير الواضح بين status التشغيلي (`ready/released`) وworkflow_step (`audited/analyzed...`).
- ما يبدو غير آمن: read endpoint قد يعدّل القرار تلقائيًا.
- ما يبدو مقيدًا بلا داعٍ: لا أدوات مدمجة لطلب إعادة فتح من نفس الشاشة.
- ما يبدو قويًا جدًا بلا شرح: غير واضح.

## دور `supervisor`
- المهام اليومية: مراجعة ما بعد التحليل والتقدم إلى `supervised`.
- القرارات: اعتماد جودة التحليل أو إعادته لمسار التصحيح.
- أخطاء متوقعة: يتوقع تنفيذ reopen لأنه يملكها في الدور، لكن endpoint يرفضه بسبب `manage_data` gate.
  (دليل تعارض: `seed_permissions.php` مقابل `api/reopen.php:17`, `api/batches.php:15`)
- موقف ضغط: خطأ جوهري يحتاج reopen عاجل؛ الدور لا يستطيع التنفيذ مباشرة رغم الوعد الظاهر في مصفوفة الصلاحيات.
- احتكاك صلاحيات: عالي جدًا.
- ما يبطئه: تحويل الطلب لشخص آخر فقط لوجود gate تقني غير ظاهر في الواجهة.
- ما يربكه: الفرق بين "عندي reopen permission" و"غير قادر فعليًا".
- ما يبدو غير آمن: الاعتماد على شخص تشغيل لتنفيذ reopen governance.
- ما يبدو مقيدًا بلا داعٍ: reopen.
- ما يبدو قويًا جدًا بلا شرح: غير واضح.

## دور `approver`
- المهام اليومية: اعتماد نهائي من `supervised` إلى `approved`.
- القرارات: قبول نهائي أو طلب تصحيح.
- أخطاء متوقعة: نفس reopen contradiction، مع إضافة أن `break_glass_override` لا يفيده عمليًا في هذه endpoints بدون `manage_data`.
- موقف ضغط: حالة حرجة تتطلب break-glass سريع؛ الرفض يأتي مبكرًا من gate مختلف.
- احتكاك صلاحيات: شديد.
- ما يبطئه: مسار طوارئ غير متاح تشغيليًا رغم تعريفه في الدور.
- ما يربكه: "صلاحية الطوارئ" موجودة نظريًا لكنها غير قابلة للاستخدام في السيناريو المتوقع.
- ما يبدو غير آمن: الاعتماد على دور آخر أقل رتبة حوكميًا لتنفيذ إجراء حساس.
- ما يبدو مقيدًا بلا داعٍ: reopen/break-glass workflow.
- ما يبدو قويًا جدًا بلا شرح: غير واضح.

## دور `signatory`
- المهام اليومية: تسجيل التوقيع النهائي.
- القرارات: هل أوقع الآن أم أنتظر اكتمال الشروط.
- أخطاء متوقعة: يرى أجزاء تشغيلية ليست ضمن الدور، أو يصل لطباعة/سجل بلا تفسير واضح للمرحلة الحالية.
- موقف ضغط: ضغط توقيع دفعات كبيرة مع محدودية أدوات الفرز الخاصة بدوره.
- احتكاك صلاحيات: متوسط.
- ما يبطئه: عدم تخصيص واجهة “طابور توقيع” صريح.
- ما يربكه: تعدد المفاهيم (status/action/workflow) في نفس البطاقة.
- ما يبدو غير آمن: read-driven auto updates قد تغير حالة خلف الكواليس قبل توقيعه.
- ما يبدو مقيدًا بلا داعٍ: لا أدوات batch signature واضحة.
- ما يبدو قويًا جدًا بلا شرح: غير واضح.

## دور `developer` (دور فعلي لكنه غير تشغيلي)
- المهام اليومية المتوقعة داخل النظام: إدارة كاملة، إنقاذ طوارئ، دعم مستخدمين.
- ما يبطئه: لا توجد حدود تشغيلية داخل نفس الحساب (قد يزيد مخاطر الخطأ البشري عند العمل اليومي).
- ما يربكه: غير واضح.
- ما يبدو غير آمن: قوة مفرطة في بيئة إنتاج بدون guardrails تشغيلية واضحة داخل UI.
- ما يبدو مقيدًا بلا داعٍ: غير واضح.
- ما يبدو قويًا جدًا بلا شرح: كل شيء تقريبًا.

---

## PHASE 2 — العناصر الناقصة لكل دور (تشغيليًا)

## `data_entry`
- Faster: شاشة pre-check للملف قبل الاستيراد تعرض الصفوف المرفوضة قبل الحفظ.
- Clearer: سبب الرفض في نفس مكان الزر مع ربط مباشر بالصلاحية المطلوبة.
- Safer: فصل صلاحيات Undo approval/execution عن إدخال البيانات اليومي.
- Less mental load: توحيد مسار الإدخال اليدوي (مسار واحد واضح).
- Predictable: توحيد نمط ردود الأخطاء (JSON موحد بدل خليط HTML/JSON).

## `data_auditor`
- Faster: إخفاء أزرار mutate غير المصرح بها بدل رفضها لاحقًا.
- Clearer: بطاقة دور ثابتة توضح: “مسموح لك فقط draft->audited”.
- Safer: إظهار أن فتح السجل قد يُشغل auto-matching (أو تعطيل ذلك لهذا الدور).
- Less mental load: قائمة مهام stage-specific أكثر بروزًا من الأزرار العامة.
- Predictable: رسائل رفض صلاحية موحدة مع “من أطلب منه التنفيذ”.

## `analyst`
- Faster: فلتر افتراضي مباشر على `audited` عند الدخول.
- Clearer: فصل بصري واضح بين workflow_step وstatus التشغيلي.
- Safer: شرح صريح عند أي تحديث تلقائي على السجل.
- Less mental load: إخفاء الأفعال غير المرتبطة بدوره.
- Predictable: منع أي حفظ غير مقصود من save-and-next إذا لا يملك صلاحية mutate.

## `supervisor`
- Faster: تمكين reopen فعليًا وفق الصلاحية المعرفة، أو إزالته من الدور المعروض.
- Clearer: رسالة UI تشرح سبب gate الفعلي عند تعارض الصلاحيات.
- Safer: مسار reopen مع سبب إلزامي + سجل موافقة واضح من نفس الشاشة.
- Less mental load: صفحة “طلبات إعادة فتح” مخصصة لهذا الدور.
- Predictable: اتساق بين RBAC seed وبين requirePermission الفعلي في endpoint.

## `approver`
- Faster: break-glass action قابل للتنفيذ فعليًا من واجهته.
- Clearer: عرض حالة “Break-glass enabled/disabled” قبل بدء الإجراء.
- Safer: فصل من يعتمد undo ومن ينفذه داخل UI مع منع التضارب.
- Less mental load: dashboard طوارئ بسيط بدل التنقل بين صفحات متعددة.
- Predictable: توحيد شرط reopen بين ضمان مفرد ودفعة.

## `signatory`
- Faster: قائمة “بانتظار توقيعي” مستقلة.
- Clearer: توضيح عدد التواقيع المطلوبة والمتبقية قبل الإغلاق النهائي.
- Safer: منع أي أزرار تشغيلية غير توقيعية من واجهته.
- Less mental load: تقليل العناصر غير المتعلقة بالتوقيع في شاشة السجل.
- Predictable: نفس نمط الرسائل عند نجاح/رفض التوقيع في كل الحالات.

## `developer`
- Faster: غير واضح.
- Clearer: وسم واضح داخل UI عند العمل بحساب شامل الصلاحيات.
- Safer: تحذير سياقي قبل إجراءات عالية الأثر في الإنتاج.
- Less mental load: غير واضح.
- Predictable: غير واضح.

---

## PHASE 3 — تحليل تعارضات بين الأدوار

### 1) ما هو ناقص للأدوار junior وموجود للأدوار senior
- صلاحيات إدارة المستخدمين والإعدادات والصيانة غير متاحة إلا مع `manage_users`، وهذا متوقع.
- غير المتوازن: junior ترى أزرارًا تشغيلية لا تستطيعها، بينما senior/ops تنفذها؛ الواجهة لا تفصل بوضوح.
- missing عنصر مشترك: “تفسير فوري للصلاحية” داخل نفس السياق.

### 2) أين مرونة senior تربك junior
- وجود break-glass في النظام يجعل بعض المسارات تتصرف بشكل استثنائي، لكن junior لا ترى سياق القرار ولا لماذا تم تجاوز القاعدة.
- reopen/undo workflow عندما ينفذه دور أعلى لا يظهر للجونيور كقصة قرار متسلسلة سهلة.

### 3) أين الحوكمة تصنع احتكاكًا بلا شرح
- reopen permissions في `supervisor/approver` لكن `api/reopen.php` و`api/batches.php` يطلبان `manage_data` أولاً؛ الاحتكاك كبير وغير مفسر في UI.
- break-glass يتطلب إعدادات (`BREAK_GLASS_ENABLED`, ticket, reason min length) بدون عرض سياقي كافٍ قبل التنفيذ.
  (دليل: `BreakGlassService.php:79-93`)

### 4) أين الأدوار تتصرف بشكل غير متسق لنفس الفعل
- فعل “إعادة فتح”:
  - في سجل مفرد: frontend يرسل `guarantee_id` فقط غالبًا.
  - backend يتطلب `reason` إذا ليس break-glass.
  - النتيجة: نفس الفعل ينجح/يفشل بحسب مسار النداء وليس توقع المستخدم.
  (دليل: `records.controller.js:346-350` مقابل `api/reopen.php:46-52`)
- فعل “التعديل/الحفظ”:
  - بعض المسارات require `manage_data`.
  - `save-and-next.php` login-only ويكتب فعليًا في القرار.
  - النتيجة: حدود الدور غير متوقعة.

### فجوات مشتركة لكل الأدوار
- غياب اتساق عرض الصلاحيات على الأزرار.
- تباين تنسيقات الأخطاء.
- اختلاط مفاهيم الحالة (workflow/status/action) داخل شاشة واحدة.

### فجوات خاصة بكل مجموعة
- تشغيلية (`data_entry/auditor/analyst/signatory`): عبء ذهني عالي بسبب واجهة غير role-focused.
- إشرافية (`supervisor/approver`): تعارض صلاحيات reopen/break-glass.
- إدارية (`developer/manage_users`): قوة مفرطة دون guardrails تشغيلية كافية.

### فجوات تولد سوء فهم بين الأدوار
- “من يحق له reopen فعليًا؟” غير واضح للجميع.
- “لماذا زر موجود لكنه يرفض؟” متكرر بين الفرق.
- “هل فتح السجل قراءة فقط؟” غير صحيح دائمًا بسبب auto-mutation.

---

## PHASE 4 — القوائم المرتبة

## 1) أعلى 10 فجوات تؤثر على جميع الأدوار

1. عنوان: تعارض صلاحيات `reopen` بين RBAC والتنفيذ؛ لماذا مهم: يضرب الثقة في النظام؛ سيناريو: مشرف يحاول إعادة فتح دفعة عاجلة فيُرفض؛ الأدوار المتأثرة: supervisor, approver, وكل من يعتمد عليهم؛ الشدة: Critical.  
2. عنوان: أزرار ظاهرة بدون سماحية فعلية؛ لماذا مهم: وقت ضائع وخطأ تشغيلي؛ سيناريو: مدقق يضغط `release` ثم يرى Permission Denied؛ الأدوار: كل الأدوار غير `manage_data`; الشدة: Important.  
3. عنوان: read endpoint يسبب write تلقائي (`get-record` auto-match)؛ لماذا مهم: تغييرات غير مقصودة؛ سيناريو: مجرد فتح سجل يغيّر supplier/bank/status؛ الأدوار: جميع Login roles؛ الشدة: Critical.  
4. عنوان: تباين ردود الأخطاء (HTML مقابل JSON)؛ لماذا مهم: رسائل غير ثابتة وتجربة دعم أصعب؛ سيناريو: مستخدم يتلقى toast مختلف لنفس نوع الرفض؛ الأدوار: الجميع؛ الشدة: Important.  
5. عنوان: خلط `workflow_step` مع `status` و`active_action`; لماذا مهم: قرارات خاطئة تحت الضغط؛ سيناريو: محلل يخلط بين ready وaudited؛ الأدوار: الجميع؛ الشدة: Important.  
6. عنوان: صلاحيات حوكمة Undo ضمن `manage_data` التشغيلي؛ لماذا مهم: تركيز سلطة حساس في دور تشغيلي؛ سيناريو: منفذ إدخال يعتمد/ينفذ reopen request؛ الأدوار: data_entry أساسًا وتأثير على كل السلسلة؛ الشدة: Critical.  
7. عنوان: مسارات إدخال متعددة لنفس الهدف (`create-guarantee` و`manual-entry`); لماذا مهم: سلوك غير متوقع ودعم أعقد؛ سيناريو: فريقان يستخدمان مسارين بنتائج تدقيق مختلفة؛ الأدوار: data_entry + دعم؛ الشدة: Important.  
8. عنوان: عدم ظهور شرح صلاحية لحظي قبل التنفيذ؛ لماذا مهم: تكرار محاولات فاشلة؛ سيناريو: مستخدم يعيد المحاولة معتقدًا أنه خطأ شبكة؛ الأدوار: الجميع؛ الشدة: Important.  
9. عنوان: صفحات كثيرة Login-only مع أفعال داخلها تحتاج Permission أدق؛ لماذا مهم: الواجهة لا تمثل حدود الدور بدقة؛ سيناريو: المستخدم يدخل صفحة كاملة ثم يُمنع عند كل فعل؛ الأدوار: الجميع؛ الشدة: Important.  
10. عنوان: قوائم العمل غير متخصصة بالكامل لكل دور؛ لماذا مهم: عبء ذهني وإرهاق أسرع؛ سيناريو: موقع يواجه عناصر ليست ضمن مسؤوليته يوميًا؛ الأدوار: auditor/analyst/signatory خصوصًا؛ الشدة: Comfort.

## 2) أعلى 5 فجوات خاصة بالطاقم التشغيلي

1. عنوان: غياب preflight واضح لاستيراد Excel؛ لماذا مهم: يقلل سرعة التعديل بعد الرفع؛ سيناريو: ملف 10 صفوف فيه تكرار وأخطاء، المستخدم يكتشف بعد التنفيذ؛ الأدوار: data_entry؛ الشدة: Important.  
2. عنوان: أزرار mutate غير مرتبطة بالدور في بطاقة السجل؛ لماذا مهم: تشتيت وقرارات خاطئة؛ سيناريو: مدقق/محلل يضغط إجراء خارج دوره؛ الأدوار: data_auditor, analyst, signatory؛ الشدة: Important.  
3. عنوان: عدم وجود “سبب رفض + ماذا أفعل الآن” في نفس الرسالة؛ لماذا مهم: انتظار دعم أعلى؛ سيناريو: رفض reopen بلا مسار بديل واضح؛ الأدوار: تشغيلية كلها؛ الشدة: Important.  
4. عنوان: save-and-next متاح Login-only ويكتب فعليًا؛ لماذا مهم: قابلية تعديل من أدوار غير mutate؛ سيناريو: مستخدم مرحلة workflow يعدل supplier decision بلا إدراك حدوده؛ الأدوار: غير `manage_data` خصوصًا؛ الشدة: Critical.  
5. عنوان: عبء مفاهيم متعدد في شاشة واحدة؛ لماذا مهم: يرفع الأخطاء تحت الضغط؛ سيناريو: توقيع متأخر بسبب سوء فهم حالة السجل؛ الأدوار: analyst, signatory, auditor؛ الشدة: Comfort.

## 3) أعلى 5 فجوات خاصة بالأدوار الإشرافية/الإدارية

1. عنوان: `reopen_*` permission غير كافٍ للتنفيذ؛ لماذا مهم: يعطل التدخل الإشرافي؛ سيناريو: approver لا يستطيع تنفيذ reopen إلا عبر دور تشغيلي آخر؛ الأدوار: supervisor, approver؛ الشدة: Critical.  
2. عنوان: break-glass غير قابل للاستخدام في المسار المتوقع؛ لماذا مهم: يفشل مسار الطوارئ؛ سيناريو: مدير يعتمد الطوارئ لكن endpoint يرفضه قبل فحص break-glass؛ الأدوار: approver؛ الشدة: Critical.  
3. عنوان: غياب لوحة حوكمة Undo مخصصة للأدوار الإشرافية؛ لماذا مهم: قرارات حساسة خارج سياق إشرافي واضح؛ سيناريو: طلبات undo تُدار تشغيليًا بدل رقابيًا؛ الأدوار: supervisor, approver, data_entry؛ الشدة: Important.  
4. عنوان: صلاحية `developer` المطلقة بلا سياق تشغيلي داخل UI؛ لماذا مهم: خطأ بشري عالي الأثر في الإنتاج؛ سيناريو: حساب شامل ينفذ إجراء حرج بدون تحذير سياقي؛ الأدوار: developer + المؤسسة؛ الشدة: Important.  
5. عنوان: اختلافات endpoint-level غير مرئية في الواجهة؛ لماذا مهم: قرارات إدارية مبنية على توقعات خاطئة؛ سيناريو: الإدارة تفترض أن “الصلاحية موجودة = العملية تعمل”؛ الأدوار: supervisor, approver, manage_users؛ الشدة: Important.

---

## ملاحظات إثباتية مختصرة (Evidence Anchors)

- تعريف الأدوار والصلاحيات: `maint/create_rbac_tables.php`, `maint/seed_permissions.php`.  
- حوكمة الوصول للصفحات: `app/Support/ViewPolicy.php`, `views/*.php`.  
- حوكمة إظهار عناصر UI: `app/Support/UiPolicy.php`, `public/js/policy.js`, `public/js/nav-manifest.js`, `index.php`.  
- انتقالات workflow حسب الدور: `app/Services/WorkflowService.php`, `api/workflow-advance.php`, `partials/record-form.php`.  
- تعارض reopen: `api/reopen.php`, `api/batches.php` مقابل صلاحيات `supervisor/approver` في seed.  
- Undo workflow gate: `api/undo-requests.php`, `app/Services/UndoRequestService.php`.  
- read-mutation: `api/get-record.php:208-255, 285-329`.  
- رؤية البيانات حسب الدور: `app/Services/GuaranteeVisibilityService.php`.  

