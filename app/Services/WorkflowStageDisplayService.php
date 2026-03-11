<?php

declare(strict_types=1);

namespace App\Services;

final class WorkflowStageDisplayService
{
    /**
     * @var array<string,array{fallback_label:string,class:string}>
     */
    private const DESCRIPTORS = [
        'draft_without_action' => ['fallback_label' => 'بانتظار اختيار الإجراء', 'class' => 'badge-neutral'],
        'draft' => ['fallback_label' => 'بانتظار التدقيق', 'class' => 'badge-neutral'],
        'audited' => ['fallback_label' => 'تم التدقيق', 'class' => 'badge-info'],
        'analyzed' => ['fallback_label' => 'تم التحليل', 'class' => 'badge-info'],
        'supervised' => ['fallback_label' => 'تم الإشراف', 'class' => 'badge-info'],
        'approved' => ['fallback_label' => 'تم الاعتماد', 'class' => 'badge-warning'],
        'signed' => ['fallback_label' => 'تم التوقيع', 'class' => 'badge-success'],
    ];

    /**
     * @return array{
     *   step:string,
     *   code:string,
     *   key:string,
     *   label:string,
     *   fallback_label:string,
     *   class:string
     * }
     */
    public static function describe(
        string $workflowStep,
        ?string $activeAction = null,
        string $translationKeyPrefix = '',
        ?callable $translate = null
    ): array {
        $normalizedStep = self::normalize($workflowStep, 'draft');
        $normalizedAction = self::normalize($activeAction ?? '', '');
        $code = self::resolveCode($normalizedStep, $normalizedAction);
        $descriptor = self::DESCRIPTORS[$code] ?? null;

        if ($descriptor === null) {
            return [
                'step' => $normalizedStep,
                'code' => $code,
                'key' => '',
                'label' => strtoupper($normalizedStep !== '' ? $normalizedStep : '-'),
                'fallback_label' => strtoupper($normalizedStep !== '' ? $normalizedStep : '-'),
                'class' => 'badge-neutral',
            ];
        }

        $key = $translationKeyPrefix !== '' ? $translationKeyPrefix . '.' . $code : '';
        $label = $descriptor['fallback_label'];
        if ($key !== '' && $translate !== null) {
            $translated = $translate($key, $label);
            if (is_string($translated) && trim($translated) !== '') {
                $label = $translated;
            }
        }

        return [
            'step' => $normalizedStep,
            'code' => $code,
            'key' => $key,
            'label' => $label,
            'fallback_label' => $descriptor['fallback_label'],
            'class' => $descriptor['class'],
        ];
    }

    public static function resolveCode(string $workflowStep, ?string $activeAction = null): string
    {
        $normalizedStep = self::normalize($workflowStep, 'draft');
        $normalizedAction = self::normalize($activeAction ?? '', '');

        if ($normalizedStep === 'draft' && $normalizedAction === '') {
            return 'draft_without_action';
        }

        return $normalizedStep;
    }

    private static function normalize(string $value, string $fallback): string
    {
        $normalized = strtolower(trim($value));
        return $normalized !== '' ? $normalized : $fallback;
    }
}
