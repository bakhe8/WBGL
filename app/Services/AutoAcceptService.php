<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ImportedRecordRepository;
use App\Support\Config;
use App\Support\Settings;
use App\Repositories\LearningLogRepository;
use App\Support\Normalizer;
use App\Models\ImportedRecord;

class AutoAcceptService
{
    public function __construct(
        private ImportedRecordRepository $records,
        private Settings $settings = new Settings(),
        private LearningLogRepository $learningLog = new LearningLogRepository(),
        private Normalizer $normalizer = new Normalizer(),
    )
    {
    }

    /**
     * يحاول اعتماد السجل تلقائيًا بناءً على أعلى مرشح وعتبة AUTO.
     */
    public function tryAutoAccept(ImportedRecord $record, array $supplierCandidates, array $conflicts = []): void
    {
        if (empty($supplierCandidates)) {
            return;
        }
        $best = $supplierCandidates[0];
        $second = $supplierCandidates[1] ?? null;
        $delta = $second ? (($best['score'] ?? 0) - ($second['score'] ?? 0)) : 1;

        // إيقاف الاعتماد التلقائي إذا وُجد أي تعارض مسجّل
        if (!empty($conflicts)) {
            return;
        }

        // اعتماد تلقائي فقط إذا المصدر رسمي أو Override أو بديل مؤكد (ليس fuzzy) وبفارق كافٍ
        $allowedSources = ['official', 'override', 'alternative'];
        $autoTh = $this->settings->get('MATCH_AUTO_THRESHOLD', Config::MATCH_AUTO_THRESHOLD);
        $confDelta = $this->settings->get('CONFLICT_DELTA', Config::CONFLICT_DELTA);
        if (
            in_array($best['source'] ?? '', $allowedSources, true) &&
            ($best['score'] ?? 0) >= $autoTh &&
            $delta >= $confDelta &&
            !empty($best['supplier_id'])
        ) {
            // تسجيل في learning_log أولاً
            $this->learningLog->create([
                'raw_input' => $record->rawSupplierName,
                'normalized_input' => $this->normalizer->normalizeName($record->rawSupplierName),
                'suggested_supplier_id' => $best['supplier_id'] ?? null,
                'decision_result' => 'auto',
                'candidate_source' => $best['source'] ?? '',
                'score' => $best['score'] ?? null,
                'score_raw' => $best['score_raw'] ?? null,
            ]);

            // تحديث واحد شامل بدلاً من تحديثين
            $this->records->updateDecision($record->id ?? 0, [
                'supplier_id' => $best['supplier_id'] ?? null,
                'match_status' => 'ready',
                'decision_result' => 'auto',
            ]);
        }
    }

    /**
     * اعتماد تلقائي للبنك عند وجود مرشح واحد قوي وغياب تعارضات.
     *
     * @param array<int, array{bank_id:int,name:string,source:string,score:float,score_raw:float}> $bankCandidates
     */
    public function tryAutoAcceptBank(ImportedRecord $record, array $bankCandidates, array $conflicts = []): void
    {
        if (empty($bankCandidates)) {
            return;
        }
        $best = $bankCandidates[0];
        $second = $bankCandidates[1] ?? null;
        $delta = $second ? (($best['score'] ?? 0) - ($second['score'] ?? 0)) : 1;

        if (!empty($conflicts)) {
            return;
        }

        $allowedSources = ['official', 'override', 'alternative'];
        $autoTh = $this->settings->get('MATCH_AUTO_THRESHOLD', Config::MATCH_AUTO_THRESHOLD);
        $confDelta = $this->settings->get('CONFLICT_DELTA', Config::CONFLICT_DELTA);
        if (
            in_array($best['source'] ?? '', $allowedSources, true) &&
            ($best['score'] ?? 0) >= $autoTh &&
            $delta >= $confDelta &&
            !empty($best['bank_id'])
        ) {
            // تسجيل في learning_log أولاً
            $this->learningLog->createBank([
                'raw_input' => $record->rawBankName,
                'normalized_input' => $this->normalizer->normalizeName($record->rawBankName),
                'suggested_bank_id' => $best['bank_id'] ?? null,
                'decision_result' => 'auto',
                'candidate_source' => $best['source'] ?? '',
                'score' => $best['score'] ?? null,
                'score_raw' => $best['score_raw'] ?? null,
            ]);

            // تحديث واحد شامل بدلاً من تحديثين
            $this->records->updateDecision($record->id ?? 0, [
                'bank_id' => $best['bank_id'] ?? null,
                'match_status' => 'ready',
                'decision_result' => 'auto',
            ]);
        }
    }
}
