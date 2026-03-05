<?php
declare(strict_types=1);

namespace App\Services;

/**
 * LetterBuilder - Single Source of Truth for Letter Construction
 *
 * Centralized service for building guarantee letters.
 * - No code duplication
 * - Easily extensible for future actions
 * - Uses PreviewFormatter for helper functions
 *
 * @author WBGL System
 * @version 1.0.0
 */
class LetterBuilder
{
    /**
     * Build complete letter data structure from guarantee.
     *
     * @param array $guaranteeData Full guarantee data including relations
     * @param string $action 'extension', 'reduction', 'release', etc.
     * @param array{show_print_button?:bool} $options
     * @return array Structured data ready for template rendering
     */
    public static function prepare(array $guaranteeData, string $action, array $options = []): array
    {
        // Extract related_to (contract or purchase_order)
        $relatedTo = $guaranteeData['related_to'] ?? 'contract';

        return [
            'guarantee_id' => isset($guaranteeData['id']) ? (int)$guaranteeData['id'] : 0,
            'header' => self::buildHeader($guaranteeData),
            'subject_parts' => self::buildSubjectParts($guaranteeData, $action, $relatedTo),
            'content' => self::buildContent($guaranteeData, $action, $relatedTo),
            'signature' => self::getSignature($action),
            'cc' => self::buildCC($guaranteeData, $action, $relatedTo),
            'action' => $action,
            'relatedTo' => $relatedTo,
            'show_print_button' => (bool)($options['show_print_button'] ?? false),
        ];
    }

    /**
     * Build header: Bank name + Bank details
     */
    private static function buildHeader(array $data): array
    {
        return [
            'bank_name' => $data['bank_name'] ?? '',
            'bank_center' => $data['bank_center'] ?? '',
            // Clean duplicate "ص.ب" prefixes if present in data
            'bank_po_box' => isset($data['bank_po_box'])
                ? str_replace(['ص.ب.', 'ص.ب', 'P.O. Box', 'PO Box'], '', PreviewFormatter::toArabicNumerals($data['bank_po_box']))
                : '',
            'bank_email' => $data['bank_email'] ?? '',
        ];
    }

    /**
     * Build subject line as structured parts (for template with lang attributes)
     */
    private static function buildSubjectParts(array $data, string $action, string $relatedTo): array
    {
        $actionTexts = [
            'extension' => 'طلب تمديد',
            'reduction' => 'طلب تخفيض',
            'release' => 'الإفراج عن',
        ];

        return [
            'text' => $actionTexts[$action] ?? '',
            'guarantee_number' => htmlspecialchars($data['guarantee_number'] ?? ''),
            'related_label' => $relatedTo === 'purchase_order' ? 'لأمر الشراء رقم' : 'للعقد رقم',
            'contract_number' => $relatedTo === 'purchase_order'
                ? PreviewFormatter::toArabicNumerals(htmlspecialchars($data['contract_number'] ?? ''))
                : htmlspecialchars($data['contract_number'] ?? ''),
        ];
    }

    /**
     * Build content: Paragraphs, address box, etc.
     *
     * ✨ Extensible: Add new action cases to the switch statement
     */
    private static function buildContent(array $data, string $action, string $relatedTo): array
    {
        // Strategy Pattern: Map actions to specific content builders
        switch ($action) {
            case 'release':
                return self::buildReleaseContent($data);

            case 'extension':
            case 'reduction':
                return self::buildExtensionContent($data);

            // 🔮 Future actions can be added here:
            // case 'amendment':
            //     return self::buildAmendmentContent($data);

            default:
                // Fallback: Use extension format for unknown actions
                return self::buildExtensionContent($data);
        }
    }

    /**
     * Content for Release letters
     */
    private static function buildReleaseContent(array $data): array
    {
        $supplierName = htmlspecialchars($data['supplier_name'] ?? '');

        return [
            'paragraphs' => [
                "بهذا نعيد إليكم أصل الضمان البنكي المذكور أعلاه والصادر منكم لصالحنا على حساب <span>{$supplierName}</span>، وذلك لانتهاء الغرض منه."
            ],
            'has_address_box' => false,
        ];
    }

    /**
     * Content for Extension / Reduction letters
     *
     * Uses PreviewFormatter::getIntroPhrase() to build the opening sentence
     * based on guarantee type (Final, Advance Payment, Preliminary, etc.)
     */
    private static function buildExtensionContent(array $data): array
    {
        // ✅ Use existing logic from PreviewFormatter
        // This builds the complete intro phrase based on guarantee type:
        // - Final → "إشارة إلى الضمان البنكي النهائي الموضح أعلاه"
        // - Advance → "إشارة إلى ضمان الدفعة المقدمة البنكي الموضح أعلاه"
        // - Default → "إشارة إلى الضمان البنكي الموضح أعلاه"
        $rawType = trim($data['type'] ?? '');
        $introPhrase = PreviewFormatter::getIntroPhrase($rawType);

        $supplierName = htmlspecialchars($data['supplier_name'] ?? '');
        $arabicAmount = PreviewFormatter::toArabicNumerals(
            number_format((float) ($data['amount'] ?? 0), 2)
        );
        $formattedExpiry = PreviewFormatter::formatArabicDate($data['expiry_date'] ?? '');

        // First paragraph
        $paragraph1 = sprintf(
            '<span>%s</span>، والصادر منكم لصالحنا على حساب <span>%s</span> بمبلغ قدره (<span>%s</span>)، نأمل منكم <span class="signature-style">تمديد فترة سريان الضمان حتى تاريخ <span>%s</span>م</span> مع بقاء الشروط الأخرى دون تغيير، وإفادتنا بذلك من خلال البريد الإلكتروني المخصص للضمانات البنكية لدى مستشفى الملك فيصل التخصصي ومركز الأبحاث بالرياض (<span lang="en">bgfinance@kfshrc.edu.sa</span>)، كما نأمل منكم إرسال أصل تمديد الضمان إلى العنوان التالي:',
            $introPhrase,
            $supplierName,
            $arabicAmount,
            $formattedExpiry
        );

        // Second paragraph
        $paragraph2 = 'علمًا بأنه في حال عدم تمكن البنك من تمديد الضمان المذكور قبل انتهاء مدة سريانه فيجب على البنك دفع قيمة الضمان إلينا حسب النظام.';

        return [
            'paragraphs' => [$paragraph1, $paragraph2],
            'has_address_box' => true,
        ];
    }

    /**
     * Get signature based on action type
     *
     * ✨ Extensible: Add custom signatures for new actions
     */
    private static function getSignature(string $action): array
    {
        // Different signatures for different actions
        if ($action === 'release') {
            return [
                'title' => 'مُساعِد الرّئيس التّنفيذي',
                'name' => 'الدّكتور<span class="symbol">/</span> صَالح بن محمد المفدّى',
                'margin_top' => '3em',
            ];
        }

        // Default signature (for extension, reduction, etc.)
        return [
            'title' => 'نائب المدير العام للشؤون المالية',
            'name' => 'دلال بنت صالح العجروش',
            'margin_top' => '3em',
        ];
    }

    /**
     * Build CC section (only for release action)
     *
     * @return array|null CC data or null if not applicable
     */
    private static function buildCC(array $data, string $action, string $relatedTo): ?array
    {
        // CC section only for release letters
        if ($action !== 'release') {
            return null;
        }

        // Department label based on related_to
        $departmentLabel = $relatedTo === 'purchase_order' ? 'إدارة المشتريات' : 'إدارة العقود';
        $supplierName = htmlspecialchars($data['supplier_name'] ?? '');

        return [
            'recipients' => [
                $departmentLabel,
                $supplierName,
            ]
        ];
    }

    /**
     * Render letter HTML using template
     *
     * @param array $letterData Data from prepare() method
     * @return string Rendered HTML
     */
    public static function render(array $letterData): string
    {
        ob_start();
        extract($letterData);
        include __DIR__ . '/../../templates/letter-template.php';
        return ob_get_clean();
    }
}
