<?php
declare(strict_types=1);

namespace App\Services;

class ExcelColumnDetector
{
    /**
     * يحاول اكتشاف الأعمدة من الصف الأول بناءً على قائمة كلمات مفتاحية بالعربي والإنجليزي.
     *
     * @param string[] $headers
     * @return array<string,array> مفاتيح محتملة: supplier, bank, amount, guarantee, type, expiry, issue, po, contract, comment
     */
    public function detect(array $headers): array
    {
        $keywords = [
            'supplier' => [
                'supplier', 'vendor', 'supplier name', 'vendor name', 'party name', 'contractor name',
                'المورد', 'اسم المورد', 'اسم الموردين', 'الشركة', 'اسم الشركة', 'مقدم الخدمة',
            ],
            'guarantee' => [
                'guarantee no', 'guarantee number', 'reference', 'ref no',
                'bank guarantee number', 'bank gurantee number', 'bank guaranty number', // دعم الأخطاء الإملائية الشائعة
                'gurantee no', 'gurantee number', 'bank gurantee', 'guranttee number',
                'رقم الضمان', 'رقم المرجع', 'مرجع الضمان',
            ],
            'type' => [
                'type', 'guarantee type', 'category',
                'نوع الضمان', 'نوع', 'فئة الضمان',
            ],
            'amount' => [
                'amount', 'value', 'total amount', 'guarantee amount',
                'المبلغ', 'قيمة الضمان', 'قيمة', 'مبلغ الضمان',
            ],
            'expiry' => [
                'expiry date', 'exp date', 'validity', 'valid until', 'end date', 'validity date',
                'تاريخ الانتهاء', 'صلاحية', 'تاريخ الصلاحية', 'ينتهي في',
            ],
            'issue' => [
                'issue date', 'issuance date', 'issued on', 'release date',
                'تاريخ الاصدار', 'تاريخ الإصدار', 'تاريخ التحرير', 'تاريخ الاصدار/التحرير',
            ],
            'po' => [
                'po number', 'po no', 'po no.', 'po#', 'purchase order', 'order number', 'order no', 'order #', 'purchase order number',
                'رقم الطلب', 'رقم أمر الشراء', 'رقم po', 'رقم po.', 'رقم امر شراء', 'رقم أمر شراء',
            ],
            'contract' => [
                'contract number', 'contract no', 'contract #', 'contract reference', 'contract id',
                'agreement number', 'agreement no',
                'رقم العقد', 'رقم الاتفاقية', 'مرجع العقد',
            ],
            'comment' => [
                'comment', 'remarks', 'notes', 'note', 'description',
                'تعليق', 'ملاحظة', 'ملاحظات', 'وصف',
            ],
            'bank' => [
                'bank', 'bank name', 'issuing bank', 'beneficiary bank',
                'البنك', 'اسم البنك', 'البنك المصدر', 'بنك الاصدار', 'بنك الإصدار',
            ],
        ];

        $map = [];
        foreach ($headers as $idx => $header) {
            $h = $this->normalize($header);
            // حماية من التقاط أعمدة الضمان كـ Bank (مثل "BANK GUARANTEE NUMBER")
            $isGuaranteeish = str_contains($h, 'guarantee');
            foreach ($keywords as $field => $syns) {
                if ($field === 'bank' && $isGuaranteeish) {
                    continue;
                }
                foreach ($syns as $syn) {
                    if (str_contains($h, $this->normalize($syn))) {
                        $map[$field] = $map[$field] ?? [];
                        $map[$field][] = $idx;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    private function normalize(string $str): string
    {
        $str = mb_strtolower($str);
        // إزالة الرموز والفواصل والنقاط
        $str = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $str);
        $str = preg_replace('/\\s+/u', ' ', trim($str));
        return $str ?? '';
    }
}
