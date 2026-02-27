# WBGL SoD Matrix (Wave-5 Baseline)

## الهدف

تحديد فصل المهام بين الأدوار لمنع تضارب المصالح، وتقليل مخاطر التلاعب، وتثبيت الامتثال التشغيلي.

## الأدوار الأساسية

1. `data_entry`
2. `data_auditor`
3. `analyst`
4. `supervisor`
5. `approver`
6. `signatory`
7. `developer`

## الصلاحيات المرجعية

1. `import_excel`
2. `manual_entry`
3. `manage_data`
4. `audit_data`
5. `analyze_guarantee`
6. `supervise_analysis`
7. `approve_decision`
8. `sign_letters`
9. `manage_users`
10. `reopen_batch`
11. `reopen_guarantee`
12. `break_glass_override`

## مصفوفة الفصل (Baseline)

| الدور | إدخال/تعديل بيانات | مراجعة/تدقيق | اعتماد مالي | توقيع | Reopen | Break-Glass |
|---|---|---|---|---|---|---|
| data_entry | نعم | لا | لا | لا | لا | لا |
| data_auditor | لا | نعم | لا | لا | لا | لا |
| analyst | لا | تحليل | لا | لا | لا | لا |
| supervisor | لا | إشراف | لا | لا | نعم | لا |
| approver | لا | لا | نعم | لا | نعم | نعم |
| signatory | لا | لا | لا | نعم | لا | لا |
| developer | حسب السياسة | حسب السياسة | لا | لا | نعم | نعم |

## قواعد منع التضارب

1. من يطلب Undo لا يجوز أن يعتمده.
2. من يعتمد الطلب لا ينفذه إذا كان هو نفس طالب الطلب.
3. صلاحية `break_glass_override` لا تُمنح إلا مع مبرر تشغيلي معتمد.
4. أي استخدام Break-Glass يتطلب تذكرة حادث وتوثيق TTL.

## تشغيل تدريجي (من حساب واحد إلى فصل كامل)

1. المرحلة 1: تفعيل قيود SoD بالكود (منجز).
2. المرحلة 2: إنشاء مستخدمين فعليين لكل دور.
3. المرحلة 3: نقل العمليات الحساسة من حساب واحد إلى ثنائي اعتماد.
4. المرحلة 4: إيقاف الاستخدام اليومي لأي حساب بصلاحية `break_glass_override`.
5. المرحلة 5: اعتماد تقارير مراجعة دورية رسمية.

## مرجعية التنفيذ

1. `maint/create_rbac_tables.php`
2. `maint/seed_permissions.php`
3. `app/Services/UndoRequestService.php`
4. `app/Services/BreakGlassService.php`

