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
        $payload = $this->renderHistoryViewState($historyId, $index);
        if (!empty($payload['forbidden'])) {
            return ['forbidden' => true, 'html' => ''];
        }
        return ['forbidden' => false, 'html' => (string)($payload['record_html'] ?? '')];
    }

    /**
     * @return array{
     *   forbidden:bool,
     *   mode:string,
     *   is_historical:bool,
     *   guarantee_id:int,
     *   event_id:int,
     *   event_subtype:string,
     *   preview_action:string,
     *   record_html:string,
     *   preview_html:string
     * }
     */
    public function renderHistoryViewState(int $historyId, int $index): array
    {
        $eventStmt = $this->db->prepare('SELECT * FROM guarantee_history WHERE id = ?');
        $eventStmt->execute([$historyId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($event)) {
            throw new \RuntimeException('History event not found');
        }

        $guaranteeId = (int)($event['guarantee_id'] ?? 0);
        if ($guaranteeId <= 0) {
            throw new \RuntimeException('Invalid guarantee id on history event');
        }

        $policy = wbgl_api_policy_for_guarantee($this->db, $guaranteeId);
        if (!($policy['visible'] ?? false)) {
            return [
                'forbidden' => true,
                'mode' => 'history',
                'is_historical' => true,
                'guarantee_id' => $guaranteeId,
                'event_id' => $historyId,
                'event_subtype' => '',
                'preview_action' => '',
                'record_html' => '',
                'preview_html' => '',
            ];
        }

        $decisionRow = $this->loadDecisionRow($guaranteeId);
        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            (string)($decisionRow['status'] ?? 'pending')
        );

        if (!($surface['can_view_timeline'] ?? false)) {
            return [
                'forbidden' => false,
                'mode' => 'history',
                'is_historical' => true,
                'guarantee_id' => $guaranteeId,
                'event_id' => $historyId,
                'event_subtype' => '',
                'preview_action' => '',
                'record_html' => $this->renderHistoryOutOfScopeState($policy, $index),
                'preview_html' => $this->renderHiddenPreviewSection(),
            ];
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

        $snapshot = TimelineHybridLedger::resolveEventSnapshot($this->db, $event);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $record = $this->buildBaseRecordFromGuaranteeRow($guaranteeRow, $raw);
        foreach ($snapshot as $key => $value) {
            $record[$key] = $value ?? '';
        }
        $this->hydrateSupplierName($record, $snapshot);
        $this->hydrateBankNameAndDetails($record, $snapshot);

        $eventSubtype = trim((string)($event['event_subtype'] ?? ''));
        $previewAction = $this->resolvePreviewAction($guaranteeId, $eventSubtype, $decisionRow);
        $record['active_action'] = $previewAction;
        $record['workflow_step'] = (string)($decisionRow['workflow_step'] ?? 'draft');
        $record['signatures_received'] = (int)($decisionRow['signatures_received'] ?? 0);

        $isHistorical = true;
        $bannerData = [
            'timestamp' => $event['created_at'],
            'reason' => $event['change_reason'] ?? $event['event_type'] ?? 'unknown',
        ];

        $guarantee = new \stdClass();
        $guarantee->rawData = $raw;
        $supplierMatch = ['score' => 0, 'suggestions' => []];
        $bankMatch = ['id' => 0];
        $banks = [];
        $latestEventSubtype = $eventSubtype !== '' ? $eventSubtype : null;

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

        $previewHtml = $this->renderPreviewSectionFromRecord(
            $record,
            (bool)($surface['can_view_preview'] ?? false),
            false,
            is_string($event['letter_snapshot'] ?? null) ? (string)$event['letter_snapshot'] : null
        );

        return [
            'forbidden' => false,
            'mode' => 'history',
            'is_historical' => true,
            'guarantee_id' => $guaranteeId,
            'event_id' => $historyId,
            'event_subtype' => $eventSubtype,
            'preview_action' => $previewAction,
            'record_html' => $recordHtml,
            'preview_html' => $previewHtml,
        ];
    }

    /**
     * @return array{
     *   forbidden:bool,
     *   mode:string,
     *   is_historical:bool,
     *   guarantee_id:int,
     *   event_id:int,
     *   event_subtype:string,
     *   preview_action:string,
     *   record_html:string,
     *   preview_html:string
     * }
     */
    public function renderCurrentViewState(int $guaranteeId, int $index): array
    {
        $policy = wbgl_api_policy_for_guarantee($this->db, $guaranteeId);
        if (!($policy['visible'] ?? false)) {
            return [
                'forbidden' => true,
                'mode' => 'current',
                'is_historical' => false,
                'guarantee_id' => $guaranteeId,
                'event_id' => 0,
                'event_subtype' => '',
                'preview_action' => '',
                'record_html' => '',
                'preview_html' => '',
            ];
        }

        $decisionRow = $this->loadDecisionRow($guaranteeId);
        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            (string)($decisionRow['status'] ?? 'pending')
        );

        if (!($surface['can_view_record'] ?? false)) {
            return [
                'forbidden' => false,
                'mode' => 'current',
                'is_historical' => false,
                'guarantee_id' => $guaranteeId,
                'event_id' => 0,
                'event_subtype' => '',
                'preview_action' => '',
                'record_html' => $this->renderHistoryOutOfScopeState($policy, $index),
                'preview_html' => $this->renderHiddenPreviewSection(),
            ];
        }

        $recordService = new GetRecordPresentationService($this->db);
        $recordHtml = $recordService->renderRecordSection($guaranteeId, $index, $policy);
        $record = $this->buildCurrentRecordForPreview($guaranteeId, $decisionRow);

        $statusForPreview = strtolower(trim((string)($record['status'] ?? 'pending')));
        if ($statusForPreview === 'approved') {
            $statusForPreview = 'ready';
        }
        $showPreview = (bool)($surface['can_view_preview'] ?? false)
            && in_array($statusForPreview, ['ready', 'issued', 'released', 'signed'], true);

        $previewHtml = $this->renderPreviewSectionFromRecord($record, $showPreview, false, null);
        $previewAction = trim((string)($record['active_action'] ?? ''));

        return [
            'forbidden' => false,
            'mode' => 'current',
            'is_historical' => false,
            'guarantee_id' => $guaranteeId,
            'event_id' => 0,
            'event_subtype' => '',
            'preview_action' => $previewAction,
            'record_html' => $recordHtml,
            'preview_html' => $previewHtml,
        ];
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
        $showInlineHistoricalBanner = false;
        echo '<div id="record-form-section" class="decision-card" data-record-index="' . $index . '">';
        require __DIR__ . '/../../partials/record-form.php';
        echo '</div>';
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function loadDecisionRow(int $guaranteeId): array
    {
        $decisionStmt = $this->db->prepare(
            'SELECT status, supplier_id, bank_id, active_action, workflow_step, signatures_received
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1'
        );
        $decisionStmt->execute([$guaranteeId]);
        $row = $decisionStmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $guaranteeRow
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function buildBaseRecordFromGuaranteeRow(array $guaranteeRow, array $raw): array
    {
        return [
            'id' => $guaranteeRow['id'],
            'guarantee_number' => $guaranteeRow['guarantee_number'] ?? ($raw['guarantee_number'] ?? ''),
            'supplier_name' => $raw['supplier'] ?? '',
            'bank_name' => $raw['bank'] ?? '',
            'bank_id' => null,
            'supplier_id' => null,
            'amount' => $raw['amount'] ?? 0,
            'expiry_date' => $raw['expiry_date'] ?? '',
            'issue_date' => $raw['issue_date'] ?? '',
            'contract_number' => $raw['contract_number'] ?? ($raw['document_reference'] ?? ''),
            'type' => $raw['type'] ?? 'Initial',
            'status' => 'pending',
            'related_to' => $raw['related_to'] ?? 'contract',
            'workflow_step' => 'draft',
            'signatures_received' => 0,
            'active_action' => '',
            'bank_center' => '',
            'bank_po_box' => '',
            'bank_email' => '',
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $snapshot
     */
    private function hydrateSupplierName(array &$record, array $snapshot): void
    {
        if (!empty($record['supplier_id']) && empty($snapshot['supplier_name'])) {
            $supplierStmt = $this->db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $supplierStmt->execute([$record['supplier_id']]);
            $supplierName = $supplierStmt->fetchColumn();
            if (is_string($supplierName) && $supplierName !== '') {
                $record['supplier_name'] = $supplierName;
            }
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $snapshot
     */
    private function hydrateBankNameAndDetails(array &$record, array $snapshot): void
    {
        if (!empty($record['bank_id']) && empty($snapshot['bank_name'])) {
            $bankStmt = $this->db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
            $bankStmt->execute([$record['bank_id']]);
            $bankName = $bankStmt->fetchColumn();
            if (is_string($bankName) && $bankName !== '') {
                $record['bank_name'] = $bankName;
            }
        }

        if (!empty($record['bank_id'])) {
            $bankDetailsStmt = $this->db->prepare(
                'SELECT department, address_line1 AS po_box, contact_email AS email
                 FROM banks
                 WHERE id = ?'
            );
            $bankDetailsStmt->execute([$record['bank_id']]);
            $bankDetails = $bankDetailsStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($bankDetails)) {
                $record['bank_center'] = (string)($bankDetails['department'] ?? '');
                $record['bank_po_box'] = (string)($bankDetails['po_box'] ?? '');
                $record['bank_email'] = (string)($bankDetails['email'] ?? '');
            }
        }
    }

    /**
     * @param array<string,mixed> $decisionRow
     */
    private function resolvePreviewAction(int $guaranteeId, string $eventSubtype, array $decisionRow): string
    {
        $actionSubtypes = ['extension', 'reduction', 'release'];
        if (in_array($eventSubtype, $actionSubtypes, true)) {
            return $eventSubtype;
        }

        $decisionAction = trim((string)($decisionRow['active_action'] ?? ''));
        if (in_array($decisionAction, $actionSubtypes, true)) {
            return $decisionAction;
        }

        $lastActionStmt = $this->db->prepare(
            "SELECT event_subtype
             FROM guarantee_history
             WHERE guarantee_id = ?
               AND event_subtype IN ('extension', 'reduction', 'release')
             ORDER BY id DESC
             LIMIT 1"
        );
        $lastActionStmt->execute([$guaranteeId]);
        $lastSubtype = trim((string)$lastActionStmt->fetchColumn());
        if (in_array($lastSubtype, $actionSubtypes, true)) {
            return $lastSubtype;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $record
     */
    private function renderPreviewSectionFromRecord(
        array $record,
        bool $showPreview,
        bool $showPrintButton,
        ?string $letterSnapshotHtml
    ): string {
        if (!$showPreview) {
            return $this->renderHiddenPreviewSection();
        }

        $letterSnapshotHtml = is_string($letterSnapshotHtml) ? trim($letterSnapshotHtml) : '';
        if ($letterSnapshotHtml !== '' && $letterSnapshotHtml !== 'null' && !str_starts_with($letterSnapshotHtml, '{')) {
            return '<div id="preview-section">' . $letterSnapshotHtml . '</div>';
        }

        $previewRecord = $record;
        $printButtonEnabled = $showPrintButton;
        ob_start();
        echo '<div id="preview-section">';
        $record = $previewRecord;
        $showPlaceholder = true;
        $showPrintButton = $printButtonEnabled;
        require __DIR__ . '/../../partials/letter-renderer.php';
        echo '</div>';
        $html = ob_get_clean();
        return is_string($html) ? $html : $this->renderHiddenPreviewSection();
    }

    private function renderHiddenPreviewSection(): string
    {
        return '<div id="preview-section" hidden class="u-hidden"></div>';
    }

    /**
     * @param array<string,mixed> $decisionRow
     * @return array<string,mixed>
     */
    private function buildCurrentRecordForPreview(int $guaranteeId, array $decisionRow): array
    {
        $recordStmt = $this->db->prepare('SELECT * FROM guarantees WHERE id = ?');
        $recordStmt->execute([$guaranteeId]);
        $guaranteeRow = $recordStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($guaranteeRow)) {
            throw new \RuntimeException('Guarantee not found');
        }

        $raw = json_decode((string)($guaranteeRow['raw_data'] ?? '{}'), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $record = $this->buildBaseRecordFromGuaranteeRow($guaranteeRow, $raw);
        $record['status'] = (string)($decisionRow['status'] ?? 'pending');
        $record['bank_id'] = $decisionRow['bank_id'] ?? null;
        $record['supplier_id'] = $decisionRow['supplier_id'] ?? null;
        $record['active_action'] = (string)($decisionRow['active_action'] ?? '');
        $record['workflow_step'] = (string)($decisionRow['workflow_step'] ?? 'draft');
        $record['signatures_received'] = (int)($decisionRow['signatures_received'] ?? 0);

        if (!empty($record['supplier_id'])) {
            $supplierStmt = $this->db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $supplierStmt->execute([$record['supplier_id']]);
            $supplierName = $supplierStmt->fetchColumn();
            if (is_string($supplierName) && $supplierName !== '') {
                $record['supplier_name'] = $supplierName;
            }
        }

        if (!empty($record['bank_id'])) {
            $bankStmt = $this->db->prepare(
                'SELECT arabic_name, department, address_line1 AS po_box, contact_email AS email
                 FROM banks
                 WHERE id = ?'
            );
            $bankStmt->execute([$record['bank_id']]);
            $bankRow = $bankStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($bankRow)) {
                $record['bank_name'] = (string)($bankRow['arabic_name'] ?? $record['bank_name']);
                $record['bank_center'] = (string)($bankRow['department'] ?? '');
                $record['bank_po_box'] = (string)($bankRow['po_box'] ?? '');
                $record['bank_email'] = (string)($bankRow['email'] ?? '');
            }
        }

        return $record;
    }
}
