<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Timeline event label/icon resolver extracted from TimelineRecorder.
 */
class TimelineEventCatalog
{
    /**
     * @param array<string, mixed> $event
     */
    public static function getEventDisplayLabel(array $event): string
    {
        $subtype = (string) ($event['event_subtype'] ?? '');
        $type = (string) ($event['event_type'] ?? '');

        $details = json_decode((string)($event['event_details'] ?? '{}'), true);
        $changes = is_array($details) ? ($details['changes'] ?? []) : [];
        if (!is_array($changes)) {
            $changes = [];
        }

        $hasField = static function (string $field) use ($changes): bool {
            foreach ($changes as $change) {
                if (($change['field'] ?? '') === $field) {
                    return true;
                }
                if (($change['trigger'] ?? '') === $field) {
                    return true;
                }
            }
            return false;
        };

        $hasTrigger = static function (string $trigger) use ($changes): bool {
            foreach ($changes as $change) {
                if (($change['trigger'] ?? '') === $trigger) {
                    return true;
                }
            }
            return false;
        };

        $hasOnlyBank = false;
        $hasSupplier = false;
        foreach ($changes as $change) {
            if (($change['field'] ?? '') === 'bank_id') {
                $hasOnlyBank = true;
            }
            if (($change['field'] ?? '') === 'supplier_id') {
                $hasSupplier = true;
            }
        }

        if ($hasOnlyBank && !$hasSupplier) {
            return 'تطابق تلقائي';
        }

        if ($hasTrigger('workflow_advance')) {
            foreach ($changes as $change) {
                if (($change['field'] ?? '') === 'workflow_step') {
                    return match ($change['new_value'] ?? '') {
                        'audited' => 'تم التدقيق',
                        'analyzed' => 'تم التحليل',
                        'supervised' => 'تم الإشراف',
                        'approved' => 'تم الاعتماد',
                        'signed' => 'تم التوقيع',
                        default => 'تحديث المرحلة'
                    };
                }
            }
            return 'تحديث المرحلة';
        }

        if ($hasTrigger('workflow_reject')) {
            return 'رفض وإرجاع';
        }

        if ($subtype !== '') {
            return match ($subtype) {
                'excel', 'manual', 'smart_paste', 'smart_paste_multi' => 'استيراد',
                'extension' => 'تمديد',
                'reduction' => 'تخفيض',
                'release' => 'إفراج',
                'supplier_change' => 'تطابق يدوي',
                'bank_change' => 'تطابق تلقائي',
                'bank_match' => 'تطابق تلقائي',
                'auto_match' => 'تطابق تلقائي',
                'manual_edit' => 'تطابق يدوي',
                'ai_match' => 'تطابق تلقائي',
                'status_change' => 'تغيير حالة',
                'reopened' => 'إعادة فتح',
                'correction' => 'تصحيح بيانات',
                'workflow_advance' => 'تحديث مسار العمل',
                'workflow_reject' => 'رفض وإرجاع',
                default => 'تحديث'
            };
        }

        if ($type === 'import') {
            return 'استيراد';
        }
        if ($type === 'reimport') {
            return 'استيراد مكرر';
        }
        if ($type === 'auto_matched') {
            return 'تطابق تلقائي';
        }
        if ($type === 'approved') {
            return 'اعتماد';
        }

        if ($type === 'modified') {
            if ($hasField('expiry_date') || $hasTrigger('extension_action')) {
                return 'تمديد';
            }
            if ($hasField('amount') || $hasTrigger('reduction_action')) {
                return 'تخفيض';
            }
            if ($hasField('supplier_id') || $hasField('bank_id')) {
                return 'اختيار القرار';
            }
            return 'تحديث';
        }

        if ($type === 'released' || $type === 'release') {
            return 'إفراج';
        }

        if ($type === 'status_change') {
            return 'تغيير حالة';
        }

        return 'تحديث';
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function getEventIcon(array $event): string
    {
        $label = self::getEventDisplayLabel($event);
        return match ($label) {
            'استيراد' => '📥',
            'استيراد مكرر' => '🔁',
            'تطابق تلقائي' => '🤖',
            'تطابق يدوي' => '✍️',
            'اعتماد' => '✔️',
            'تمديد' => '⏱️',
            'تخفيض' => '💰',
            'إفراج' => '🔓',
            'تغيير حالة' => '🔄',
            'إعادة فتح' => '🔓',
            'تصحيح بيانات' => '🛠️',
            'تحديث المرحلة' => '⚡',
            'تم التدقيق' => '🔍',
            'تم التحليل' => '📝',
            'تم الإشراف' => '🛂',
            'تم الاعتماد' => '✅',
            'تم التوقيع' => '✒️',
            'رفض وإرجاع' => '⛔',
            default => '📝'
        };
    }
}
