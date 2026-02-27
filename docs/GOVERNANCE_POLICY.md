# WBGL Governance Policy (Wave-5 Baseline)

## الهدف

هذه السياسة تضبط العمليات الحساسة في WBGL بحيث لا تُنفذ إلا ضمن ضوابط فصل المهام (SoD) ومسار تدقيق كامل.

## النطاق

تسري هذه السياسة على:

1. إعادة فتح الضمانات (`reopen_guarantee`).
2. إعادة فتح الدفعات (`reopen_batch`).
3. تجاوز الطوارئ (`break_glass_override`).
4. اعتماد/تنفيذ طلبات Undo.
5. أي تغيير إعدادات أو عملية ذات أثر تشغيلي/مالي.

## ضوابط إلزامية

1. **منع Self-Approval**  
   لا يسمح أن يطلب المستخدم العملية ويعتمدها بنفسه.

2. **Dual-Control للعمليات الحساسة**  
   عمليات Undo/Reopen تخضع لفصل أدوار عند تفعيل سياسة الـ workflow.

3. **Break-Glass مضبوط**  
   لا يعمل إلا بصلاحية صريحة + سبب إلزامي + تذكرة حادث + TTL.

4. **Audit Trail إلزامي**  
   كل عملية حساسة يجب أن تترك أثرًا في جداول التدقيق.

5. **Policy-First Enforcement**  
   الضبط في الخادم (API/Service) هو مصدر الحقيقة، وليس إخفاء عناصر الواجهة.

## ربط الضوابط بالكود

1. منع self-approval:
   - `app/Services/UndoRequestService.php` (`Self-approval is not allowed`)
2. صلاحية break-glass:
   - `app/Services/BreakGlassService.php` (`Guard::has('break_glass_override')`)
3. إلزام سبب/تذكرة الطوارئ:
   - `app/Services/BreakGlassService.php`
   - `app/Support/Settings.php` (`BREAK_GLASS_REQUIRE_TICKET`)
4. تدقيق batch/break-glass:
   - `database/migrations/20260226_000012_break_glass_and_batch_governance.sql`
   - `app/Services/BatchAuditService.php`

## متطلبات الامتثال التشغيلي

1. لا تُمنح صلاحيات `reopen_*` و `break_glass_override` إلا لأدوار محددة في مصفوفة SoD.
2. كل استخدام Break-Glass يجب أن يحتوي:
   - سبب واضح
   - رقم تذكرة
   - مدة صلاحية محددة
3. مراجعة أسبوعية لسجلات:
   - `break_glass_events`
   - `batch_audit_events`
   - `undo_requests`

## خط الأساس الحالي

السياسة مفعلة جزئيًا على مستوى الكود، وتحتاج استمرار فصل الأدوار فعليًا على المستخدمين الحقيقيين لإغلاق Wave-5 بالكامل.

