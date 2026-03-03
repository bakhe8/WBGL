<?php
declare(strict_types=1);

namespace App\Support;

/**
 * PermissionCapabilityCatalog
 *
 * Human-facing metadata for permission controls to support
 * richer administration UX (what is shown, what is actionable,
 * and where each permission applies).
 */
class PermissionCapabilityCatalog
{
    /**
     * @return array<string, array{
     *     domain:string,
     *     control_scope:string,
     *     surface:string,
     *     behavior:string
     * }>
     */
    public static function all(): array
    {
        return [
            'import_excel' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'أزرار ملف/لصق + APIs الاستيراد',
                'behavior' => 'استيراد بيانات من Excel والبريد والقنوات المشابهة',
            ],
            'manual_entry' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر إدراج يدوي + APIs الإدخال اليدوي',
                'behavior' => 'إنشاء سجل ضمان يدويًا',
            ],
            'manage_data' => [
                'domain' => 'التحكم التشغيلي',
                'control_scope' => 'تنفيذ',
                'surface' => 'إدارة التطابقات/الاستثناءات وعمليات البيانات التشغيلية العامة',
                'behavior' => 'تنفيذ العمليات التشغيلية العامة غير المفصولة بصلاحية مستقلة',
            ],
            'guarantee_save' => [
                'domain' => 'التحكم التشغيلي',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر حفظ + API save-and-next/update-guarantee',
                'behavior' => 'حفظ تعديلات قرار الضمان وتحديث بياناته التشغيلية',
            ],
            'guarantee_extend' => [
                'domain' => 'التحكم التشغيلي',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر تمديد + API extend',
                'behavior' => 'تمديد تاريخ انتهاء الضمان',
            ],
            'guarantee_reduce' => [
                'domain' => 'التحكم التشغيلي',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر تخفيض + API reduce',
                'behavior' => 'تخفيض مبلغ الضمان ضمن ضوابط الحالة',
            ],
            'guarantee_release' => [
                'domain' => 'التحكم التشغيلي',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر إفراج + API release',
                'behavior' => 'إفراج الضمان وقفل حالته',
            ],
            'supplier_manage' => [
                'domain' => 'المرجعيات',
                'control_scope' => 'تنفيذ',
                'surface' => 'شاشة الموردين + APIs create/update/delete/merge supplier',
                'behavior' => 'إدارة كيان المورد المرجعي وعمليات الدمج',
            ],
            'bank_manage' => [
                'domain' => 'المرجعيات',
                'control_scope' => 'تنفيذ',
                'surface' => 'شاشة البنوك + APIs create/update/delete bank',
                'behavior' => 'إدارة كيان البنك المرجعي',
            ],
            'audit_data' => [
                'domain' => 'سير الاعتماد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر التقدم من مرحلة draft',
                'behavior' => 'تنفيذ التدقيق كمرحلة أولى في سير العمل',
            ],
            'analyze_guarantee' => [
                'domain' => 'سير الاعتماد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر التقدم من مرحلة audited',
                'behavior' => 'تحليل الضمانات قبل الإشراف',
            ],
            'supervise_analysis' => [
                'domain' => 'سير الاعتماد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر التقدم من مرحلة analyzed',
                'behavior' => 'مراجعة وإشراف على نتائج التحليل',
            ],
            'approve_decision' => [
                'domain' => 'سير الاعتماد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر التقدم من مرحلة supervised',
                'behavior' => 'الاعتماد المالي قبل التوقيع',
            ],
            'sign_letters' => [
                'domain' => 'سير الاعتماد',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر التقدم من مرحلة approved',
                'behavior' => 'توقيع الخطابات والإنهاء',
            ],
            'manage_users' => [
                'domain' => 'الحوكمة',
                'control_scope' => 'رؤية + تنفيذ',
                'surface' => 'شاشات users/settings/maintenance + APIs الإدارة',
                'behavior' => 'إدارة المستخدمين والإعدادات والعمليات الإدارية',
            ],
            'manage_roles' => [
                'domain' => 'الحوكمة',
                'control_scope' => 'رؤية + تنفيذ',
                'surface' => 'قسم إدارة الأدوار + APIs roles/*',
                'behavior' => 'إضافة/تعديل/حذف الأدوار وتحديد صلاحيات كل دور بالكامل',
            ],
            'reopen_batch' => [
                'domain' => 'الحوكمة',
                'control_scope' => 'تنفيذ',
                'surface' => 'إجراءات إعادة فتح الدفعات',
                'behavior' => 'إعادة فتح دفعة مغلقة ضمن سياسات الحوكمة',
            ],
            'reopen_guarantee' => [
                'domain' => 'الحوكمة',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر إعادة الفتح (القلم) + API reopen + إجراءات إعادة فتح الضمان',
                'behavior' => 'السماح بإعادة فتح ضمان مُفرج عنه ضمن الحوكمة',
            ],
            'break_glass_override' => [
                'domain' => 'الحوكمة',
                'control_scope' => 'تنفيذ طارئ',
                'surface' => 'عمليات Break-glass',
                'behavior' => 'تجاوز طارئ مقيّد بسبب/تذكرة/مدة',
            ],
            'ui_change_language' => [
                'domain' => 'تفضيلات الواجهة',
                'control_scope' => 'سلوك واجهة',
                'surface' => 'زر اللغة + API تفضيلات المستخدم',
                'behavior' => 'السماح بتغيير لغة الواجهة',
            ],
            'ui_change_direction' => [
                'domain' => 'تفضيلات الواجهة',
                'control_scope' => 'سلوك واجهة',
                'surface' => 'زر الاتجاه + API تفضيلات المستخدم',
                'behavior' => 'السماح بتغيير اتجاه RTL/LTR',
            ],
            'ui_change_theme' => [
                'domain' => 'تفضيلات الواجهة',
                'control_scope' => 'سلوك واجهة',
                'surface' => 'زر المظهر + API تفضيلات المستخدم',
                'behavior' => 'السماح بتغيير ثيم الواجهة',
            ],
            'timeline_view' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'رؤية',
                'surface' => 'لوحة Timeline + APIs التاريخ',
                'behavior' => 'عرض أحداث السجل واللقطات التاريخية',
            ],
            'notes_view' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'رؤية',
                'surface' => 'قسم الملاحظات',
                'behavior' => 'عرض ملاحظات الضمان',
            ],
            'notes_create' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'تنفيذ',
                'surface' => 'إضافة/حفظ الملاحظات + API save-note',
                'behavior' => 'إضافة ملاحظات جديدة على السجل',
            ],
            'attachments_view' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'رؤية',
                'surface' => 'قسم المرفقات',
                'behavior' => 'عرض مرفقات الضمان',
            ],
            'attachments_upload' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'تنفيذ',
                'surface' => 'زر رفع المرفق + API upload-attachment',
                'behavior' => 'رفع ملفات مرفقات جديدة',
            ],
            'navigation_view_batches' => [
                'domain' => 'الملاحة',
                'control_scope' => 'رؤية',
                'surface' => 'قائمة التنقل: الدفعات',
                'behavior' => 'إظهار/إخفاء صفحة وعناصر الدفعات في التنقل',
            ],
            'navigation_view_statistics' => [
                'domain' => 'الملاحة',
                'control_scope' => 'رؤية',
                'surface' => 'قائمة التنقل: الإحصائيات',
                'behavior' => 'إظهار/إخفاء صفحة وعناصر الإحصائيات في التنقل',
            ],
            'navigation_view_settings' => [
                'domain' => 'الملاحة',
                'control_scope' => 'رؤية',
                'surface' => 'قائمة التنقل: الإعدادات',
                'behavior' => 'إظهار/إخفاء صفحة وعناصر الإعدادات',
            ],
            'navigation_view_users' => [
                'domain' => 'الملاحة',
                'control_scope' => 'رؤية',
                'surface' => 'قائمة التنقل: المستخدمون',
                'behavior' => 'إظهار/إخفاء صفحة إدارة المستخدمين',
            ],
            'navigation_view_maintenance' => [
                'domain' => 'الملاحة',
                'control_scope' => 'رؤية',
                'surface' => 'قائمة التنقل: الصيانة',
                'behavior' => 'إظهار/إخفاء صفحة أدوات الصيانة',
            ],
            'metrics_view' => [
                'domain' => 'المؤشرات والرقابة',
                'control_scope' => 'رؤية',
                'surface' => 'لوحات المؤشرات + API metrics',
                'behavior' => 'قراءة مؤشرات الأداء التشغيلي للنظام',
            ],
            'alerts_view' => [
                'domain' => 'المؤشرات والرقابة',
                'control_scope' => 'رؤية',
                'surface' => 'لوحات التنبيهات + API alerts',
                'behavior' => 'عرض تنبيهات النظام والإجراءات المطلوبة',
            ],
            'settings_audit_view' => [
                'domain' => 'المؤشرات والرقابة',
                'control_scope' => 'رؤية',
                'surface' => 'سجل تدقيق الإعدادات + API settings-audit',
                'behavior' => 'عرض أثر تغييرات الإعدادات لأغراض الحوكمة',
            ],
            'users_create' => [
                'domain' => 'إدارة المستخدمين',
                'control_scope' => 'تنفيذ',
                'surface' => 'API users/create + زر إضافة مستخدم',
                'behavior' => 'إنشاء حسابات مستخدمين جديدة',
            ],
            'users_update' => [
                'domain' => 'إدارة المستخدمين',
                'control_scope' => 'تنفيذ',
                'surface' => 'API users/update + شاشة تعديل المستخدم',
                'behavior' => 'تعديل بيانات المستخدم وتفضيلاته',
            ],
            'users_delete' => [
                'domain' => 'إدارة المستخدمين',
                'control_scope' => 'تنفيذ',
                'surface' => 'API users/delete + زر حذف المستخدم',
                'behavior' => 'حذف المستخدمين وفق ضوابط الحوكمة',
            ],
            'users_manage_overrides' => [
                'domain' => 'إدارة المستخدمين',
                'control_scope' => 'تنفيذ',
                'surface' => 'خيارات override (تلقائي/سماح/منع) للمستخدم',
                'behavior' => 'إدارة تخصيص الصلاحيات على مستوى المستخدم الفردي',
            ],
            'roles_create' => [
                'domain' => 'إدارة الأدوار',
                'control_scope' => 'تنفيذ',
                'surface' => 'API roles/create + زر إضافة دور',
                'behavior' => 'إنشاء دور جديد وربط صلاحياته',
            ],
            'roles_update' => [
                'domain' => 'إدارة الأدوار',
                'control_scope' => 'تنفيذ',
                'surface' => 'API roles/update + شاشة تعديل الدور',
                'behavior' => 'تعديل بيانات الدور وصلاحياته',
            ],
            'roles_delete' => [
                'domain' => 'إدارة الأدوار',
                'control_scope' => 'تنفيذ',
                'surface' => 'API roles/delete + زر حذف الدور',
                'behavior' => 'حذف دور غير مرتبط بمستخدمين',
            ],
            'import_paste' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'واجهات اللصق الذكي + APIs parse-paste',
                'behavior' => 'استيراد السجلات عبر النص الملصوق',
            ],
            'import_email' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'قناة استيراد البريد + API import-email',
                'behavior' => 'استيراد الضمانات من قناة البريد الإلكتروني',
            ],
            'import_suppliers' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'API import_suppliers + شاشة الاستيراد',
                'behavior' => 'تحميل موردين من ملفات خارجية',
            ],
            'import_banks' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'API import_banks + شاشة الاستيراد',
                'behavior' => 'تحميل بنوك من ملفات خارجية',
            ],
            'import_matching_overrides' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'API import_matching_overrides + إعدادات المطابقة',
                'behavior' => 'استيراد قواعد تخصيص التطابق',
            ],
            'import_commit_batch' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'API commit-batch-draft + عمليات الدفعات',
                'behavior' => 'اعتماد دفعة مستوردة من وضع المسودة',
            ],
            'import_convert_to_real' => [
                'domain' => 'الإدخال والاستيراد',
                'control_scope' => 'تنفيذ',
                'surface' => 'API convert-to-real + أدوات تحويل الدفعات',
                'behavior' => 'تحويل دفعة تجريبية إلى دفعة تشغيلية حقيقية',
            ],
            'timeline_export' => [
                'domain' => 'العرض والتتبع',
                'control_scope' => 'تنفيذ',
                'surface' => 'أدوات تصدير/طباعة timeline',
                'behavior' => 'تصدير سجل الأحداث للتدقيق أو الأرشفة',
            ],
        ];
    }

    /**
     * @return array{
     *     domain:string,
     *     control_scope:string,
     *     surface:string,
     *     behavior:string
     * }|null
     */
    public static function forSlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $all = self::all();
        return $all[$slug] ?? null;
    }
}
