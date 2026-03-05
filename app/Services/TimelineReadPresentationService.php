<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Guard;
use PDO;

/**
 * Consolidates timeline/history read rendering used by API adapters.
 */
class TimelineReadPresentationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{forbidden:bool,html:string}
     */
    public function renderTimelineByIndex(
        int $index,
        string $statusFilter,
        ?string $searchTerm,
        ?string $stageFilter,
        bool $includeTestData = false
    ): array {
        $guaranteeId = NavigationService::getIdByIndex(
            $this->db,
            $index,
            $statusFilter,
            $searchTerm,
            $stageFilter,
            $includeTestData
        );

        $timeline = [];
        if ($guaranteeId) {
            $policy = wbgl_api_policy_for_guarantee($this->db, (int)$guaranteeId);
            if (!($policy['visible'] ?? false)) {
                return ['forbidden' => true, 'html' => ''];
            }

            $statusStmt = $this->db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
            $statusStmt->execute([(int)$guaranteeId]);
            $decisionRow = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $surface = UiSurfacePolicyService::forGuarantee(
                $policy,
                Guard::permissions(),
                (string)($decisionRow['status'] ?? 'pending')
            );

            if ($surface['can_view_timeline'] ?? false) {
                $timeline = TimelineDisplayService::getEventsForDisplay($this->db, (int)$guaranteeId);
            }
        }

        return [
            'forbidden' => false,
            'html' => $this->renderTimelinePartial($timeline),
        ];
    }

    /**
     * @return array{forbidden:bool,html:string}
     */
    public function renderHistorySnapshot(int $historyId, int $index): array
    {
        $eventStmt = $this->db->prepare('SELECT * FROM guarantee_history WHERE id = ?');
        $eventStmt->execute([$historyId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($event)) {
            throw new \RuntimeException('History event not found');
        }

        $guaranteeId = (int)($event['guarantee_id'] ?? 0);
        $policy = wbgl_api_policy_for_guarantee($this->db, $guaranteeId);
        if (!($policy['visible'] ?? false)) {
            return ['forbidden' => true, 'html' => ''];
        }

        $decisionStmt = $this->db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $decisionStmt->execute([$guaranteeId]);
        $decisionRow = $decisionStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            (string)($decisionRow['status'] ?? 'pending')
        );

        if (!($surface['can_view_timeline'] ?? false)) {
            return [
                'forbidden' => false,
                'html' => $this->renderHistoryOutOfScopeState($policy, $index),
            ];
        }

        $snapshot = TimelineHybridLedger::resolveEventSnapshot($this->db, $event);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $recordStmt = $this->db->prepare('SELECT * FROM guarantees WHERE id = ?');
        $recordStmt->execute([$guaranteeId]);
        $guaranteeRow = $recordStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($guaranteeRow)) {
            throw new \RuntimeException('Parent guarantee record not found');
        }

        $raw = json_decode((string)($guaranteeRow['raw_data'] ?? '{}'), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $record = [
            'id' => $guaranteeRow['id'],
            'guarantee_number' => $guaranteeRow['guarantee_number'] ?? ($raw['guarantee_number'] ?? ''),
            'supplier_name' => $raw['supplier'] ?? '',
            'bank_name' => $raw['bank'] ?? '',
            'bank_id' => null,
            'supplier_id' => null,
            'amount' => $raw['amount'] ?? 0,
            'expiry_date' => $raw['expiry_date'] ?? '',
            'issue_date' => $raw['issue_date'] ?? '',
            'contract_number' => $raw['contract_number'] ?? '',
            'type' => $raw['type'] ?? 'Initial',
            'status' => 'pending',
        ];

        foreach ($snapshot as $key => $value) {
            $record[$key] = $value ?? '';
        }

        if (!empty($record['supplier_id']) && empty($snapshot['supplier_name'])) {
            $supplierStmt = $this->db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $supplierStmt->execute([$record['supplier_id']]);
            $supplierName = $supplierStmt->fetchColumn();
            if (is_string($supplierName) && $supplierName !== '') {
                $record['supplier_name'] = $supplierName;
            }
        }

        if (!empty($record['bank_id']) && empty($snapshot['bank_name'])) {
            $bankStmt = $this->db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
            $bankStmt->execute([$record['bank_id']]);
            $bankName = $bankStmt->fetchColumn();
            if (is_string($bankName) && $bankName !== '') {
                $record['bank_name'] = $bankName;
            }
        }

        $isHistorical = true;
        $bannerData = [
            'timestamp' => $event['created_at'],
            'reason' => $event['change_reason'] ?? $event['event_type'] ?? 'unknown',
        ];
        $latestEventSubtype = null;

        $guarantee = new \stdClass();
        $guarantee->rawData = $raw;
        $supplierMatch = ['score' => 0, 'suggestions' => []];
        $bankMatch = ['id' => 0];
        $banks = [];

        $recordHtml = $this->renderHistoryRecordPartial(
            $index,
            $record,
            $isHistorical,
            $bannerData,
            $guarantee,
            $supplierMatch,
            $bankMatch,
            $banks,
            $latestEventSubtype
        );

        return ['forbidden' => false, 'html' => $recordHtml];
    }

    public static function renderHistoryError(string $message, int $index): string
    {
        return '<div class="alert alert-error">'
            . '<h4>فشل تحميل النسخة التاريخية</h4>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<button data-action="load-record" data-index="' . $index . '" class="btn btn-secondary">'
            . 'العودة للوضع الحالي'
            . '</button>'
            . '</div>';
    }

    private function renderTimelinePartial(array $timeline): string
    {
        ob_start();
        include __DIR__ . '/../../partials/timeline-section.php';
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function renderHistoryOutOfScopeState(array $policy, int $index): string
    {
        return '<div id="record-form-section" class="decision-card decision-card-empty-state"'
            . ' data-record-index="' . htmlspecialchars((string)$index, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-policy-visible="' . (($policy['visible'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-actionable="' . (($policy['actionable'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-executable="' . (($policy['executable'] ?? false) ? '1' : '0') . '"'
            . ' data-surface-can-view-record="0"'
            . ' data-surface-can-view-preview="0"'
            . ' data-surface-can-execute-actions="0">'
            . '<div class="card-body"><div class="empty-state-message" data-i18n="index.empty.no_record_in_scope">لا توجد سجلات ضمن نطاق العرض الحالي</div></div>'
            . '</div>';
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $bannerData
     * @param object $guarantee
     * @param array<string,mixed> $supplierMatch
     * @param array<string,mixed> $bankMatch
     * @param array<int,array<string,mixed>> $banks
     */
    private function renderHistoryRecordPartial(
        int $index,
        array $record,
        bool $isHistorical,
        array $bannerData,
        object $guarantee,
        array $supplierMatch,
        array $bankMatch,
        array $banks,
        ?string $latestEventSubtype
    ): string {
        ob_start();
        echo '<div id="record-form-section" class="decision-card" data-record-index="' . $index . '">';
        require __DIR__ . '/../../partials/record-form.php';
        echo '</div>';
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }
}
