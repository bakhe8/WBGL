<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Settings;

class ConflictDetector
{
    public function __construct(private ?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * @param array{supplier?:array, bank?:array} $candidates
     * @param array $record
     * @return string[]
     */
    public function detect(array $candidates, array $record): array
    {
        $conflicts = [];
        $delta = $this->settings->get('CONFLICT_DELTA', Config::CONFLICT_DELTA);
        $autoTh = $this->settings->get('MATCH_AUTO_THRESHOLD', Config::MATCH_AUTO_THRESHOLD);

        if (empty($record['raw_supplier_name'])) {
            $conflicts[] = 'لا يوجد اسم مورد خام';
        }
        if (empty($record['raw_bank_name'])) {
            $conflicts[] = 'لا يوجد اسم بنك خام';
        }

        // Supplier conflicts
        $supplierList = $candidates['supplier']['candidates'] ?? [];
        if (count($supplierList) > 1) {
            $diff = ($supplierList[0]['score'] ?? 0) - ($supplierList[1]['score'] ?? 0);
            if ($diff < $delta) {
                $conflicts[] = 'مرشحا مورد متقاربان في الدرجة';
            }
        }
        // Official vs alternative vs override
        if (!empty($supplierList)) {
            $top = $supplierList[0];
            if (($top['source'] ?? '') === 'alternative' && ($top['score'] ?? 0) < $autoTh) {
                $conflicts[] = 'أعلى مرشح من الأسماء البديلة وبدرجة منخفضة، يحتاج مراجعة';
            }
            if (($top['source'] ?? '') === 'override' && ($top['score'] ?? 0) < $autoTh) {
                $conflicts[] = 'يوجد Override لكن الدرجة منخفضة، راجع المدخلات';
            }
            // إذا وُجد Override وليس هو الأعلى
            $hasOverride = array_filter($supplierList, fn($c) => ($c['source'] ?? '') === 'override');
            if ($hasOverride && ($top['source'] ?? '') !== 'override') {
                $conflicts[] = 'يوجد Override لكن لم يكن أعلى نتيجة، تحقق من التعارض';
            }
        }
        // Normalization conflict: if normalized empty or too short
        if (empty($candidates['supplier']['normalized']) || mb_strlen($candidates['supplier']['normalized']) < 3) {
            $conflicts[] = 'التطبيع أرجع قيمة قصيرة أو فارغة للمورد';
        }
        if (!empty($candidates['supplier']['normalized']) && !empty($record['raw_supplier_name'])) {
            $rawShort = mb_strlen(trim((string)$record['raw_supplier_name'])) < 3;
            if ($rawShort) {
                $conflicts[] = 'اسم المورد الخام قصير جداً بعد التطبيع';
            }
        }

        // Bank conflicts
        $bankList = $candidates['bank']['candidates'] ?? [];
        if (count($bankList) > 1) {
            $diff = ($bankList[0]['score'] ?? 0) - ($bankList[1]['score'] ?? 0);
            if ($diff < $delta) {
                $conflicts[] = 'مرشحا بنك متقاربان في الدرجة';
            }
        }
        if (!empty($bankList)) {
            $top = $bankList[0];
            if (($top['score'] ?? 0) < $autoTh) {
                $conflicts[] = 'أعلى مرشح بنك بدرجة منخفضة، يحتاج مراجعة';
            }
            if (($top['source'] ?? '') === 'alternative' && ($top['score'] ?? 0) < $autoTh) {
                $conflicts[] = 'مرشح بنك بديل بدرجة منخفضة، يفضّل مراجعة الاسم الرسمي';
            }
        }
        if (empty($candidates['bank']['normalized']) || mb_strlen($candidates['bank']['normalized']) < 3) {
            $conflicts[] = 'التطبيع أرجع قيمة قصيرة أو فارغة للبنك';
        }
        if (!empty($candidates['bank']['normalized']) && !empty($record['raw_bank_name'])) {
            $rawShort = mb_strlen(trim((string)$record['raw_bank_name'])) < 3;
            if ($rawShort) {
                $conflicts[] = 'اسم البنك الخام قصير جداً بعد التطبيع';
            }
        }

        return $conflicts;
    }
    
}
