<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\GuaranteeRepository;
use App\Support\BankNormalizer;
use App\Support\Guard;
use PDO;
use Throwable;

/**
 * Centralizes get-record presentation assembly.
 *
 * This service keeps endpoint logic thin by handling record data hydration,
 * matching projections, and final HTML rendering for the record form section.
 */
class GetRecordPresentationService
{
    private PDO $db;
    private GuaranteeRepository $guaranteeRepository;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->guaranteeRepository = new GuaranteeRepository($db);
    }

    public function renderOutOfScopeEmptyState(): string
    {
        return $this->renderEmptyState([
            'visible' => false,
            'actionable' => false,
            'executable' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $policy
     */
    public function renderRecordSection(int $guaranteeId, int $index, array $policy): string
    {
        $lastDecision = $this->loadLastDecision($guaranteeId);
        $surfaceStatus = (string)($lastDecision['status'] ?? 'pending');

        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            $surfaceStatus
        );

        if (!($surface['can_view_record'] ?? false)) {
            return $this->renderEmptyState($policy);
        }

        $guarantee = $this->guaranteeRepository->find($guaranteeId);
        if ($guarantee === null) {
            throw new \RuntimeException('Record not found');
        }

        $record = $this->buildRecord($guarantee, $lastDecision);
        $record['status_reasons'] = StatusEvaluator::getReasons(
            $record['supplier_id'] ?? null,
            $record['bank_id'] ?? null,
            []
        );

        $latestEventSubtype = null;
        $banks = $this->loadBanks();
        $supplierMatch = $this->resolveSupplierMatch((string)($record['supplier_name'] ?? ''));
        $bankMatch = $this->resolveBankMatch((string)($record['bank_name'] ?? ''));

        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            (string)($record['status'] ?? 'pending')
        );
        $recordCanExecuteActions = (bool)($surface['can_execute_actions'] ?? false);

        $partialHtml = $this->renderRecordPartial(
            $index,
            $record,
            $banks,
            $guarantee,
            $supplierMatch,
            $bankMatch,
            $latestEventSubtype,
            $recordCanExecuteActions
        );

        return $this->wrapRecordSection($partialHtml, $policy, $surface);
    }

    public static function renderError(string $message): string
    {
        return '<div id="record-form-section" class="card" data-current-event-type="current">'
            . '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadLastDecision(int $guaranteeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT status, supplier_id, bank_id, active_action FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed>|null $lastDecision
     * @return array<string,mixed>
     */
    private function buildRecord(object $guarantee, ?array $lastDecision): array
    {
        $raw = is_array($guarantee->rawData ?? null) ? $guarantee->rawData : [];

        $record = [
            'id' => $guarantee->id,
            'guarantee_number' => $guarantee->guaranteeNumber,
            'supplier_name' => $raw['supplier'] ?? '',
            'bank_name' => $raw['bank'] ?? '',
            'bank_id' => null,
            'amount' => $raw['amount'] ?? 0,
            'expiry_date' => $raw['expiry_date'] ?? '',
            'issue_date' => $raw['issue_date'] ?? '',
            'contract_number' => $raw['contract_number'] ?? '',
            'type' => $raw['type'] ?? 'Initial',
            'related_to' => $raw['related_to'] ?? 'contract',
            'active_action' => null,
            'status' => 'pending',
        ];

        if ($lastDecision === null) {
            return $record;
        }

        $record['status'] = $lastDecision['status'];
        $record['bank_id'] = $lastDecision['bank_id'];
        $record['supplier_id'] = $lastDecision['supplier_id'];
        $record['active_action'] = $lastDecision['active_action'];

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
                'SELECT arabic_name AS bank_name, department, address_line1 AS po_box, contact_email AS email
                 FROM banks
                 WHERE id = ?'
            );
            $bankStmt->execute([$record['bank_id']]);
            $bankDetails = $bankStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($bankDetails)) {
                $record['bank_name'] = $bankDetails['bank_name'] ?? $record['bank_name'];
                $record['bank_center'] = $bankDetails['department'] ?? null;
                $record['bank_po_box'] = $bankDetails['po_box'] ?? null;
                $record['bank_email'] = $bankDetails['email'] ?? null;
            }
        }

        return $record;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadBanks(): array
    {
        $stmt = $this->db->query(
            'SELECT id, arabic_name AS official_name, department, address_line1 AS po_box, contact_email AS email
             FROM banks
             ORDER BY arabic_name'
        );

        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{score:int,id:int|null,name:string,suggestions:list<array<string,mixed>>}
     */
    private function resolveSupplierMatch(string $supplierName): array
    {
        $match = [
            'score' => 0,
            'id' => null,
            'name' => '',
            'suggestions' => [],
        ];

        if ($supplierName === '') {
            return $match;
        }

        try {
            $authority = Learning\AuthorityFactory::create();
            $suggestionDTOs = $authority->getSuggestions($supplierName);
            $suggestions = array_map(static fn($dto): array => $dto->toArray(), $suggestionDTOs);
            if ($suggestions === []) {
                return $match;
            }

            $top = $suggestions[0];
            $match['score'] = (int)($top['confidence'] ?? 0);
            $match['id'] = (int)($top['supplier_id'] ?? 0);
            $match['name'] = (string)($top['official_name'] ?? '');
            $match['suggestions'] = $suggestions;
        } catch (Throwable) {
            // Read path must stay resilient; learning failures are ignored.
        }

        return $match;
    }

    /**
     * @return array{score:int,id:int|null,name:string}
     */
    private function resolveBankMatch(string $bankName): array
    {
        $match = ['score' => 0, 'id' => null, 'name' => ''];
        if ($bankName === '') {
            return $match;
        }

        try {
            $normalized = BankNormalizer::normalize($bankName);
            $stmt = $this->db->prepare(
                'SELECT b.id, b.arabic_name AS bank_name
                 FROM banks b
                 JOIN bank_alternative_names a ON b.id = a.bank_id
                 WHERE a.normalized_name = ?
                 LIMIT 1'
            );
            $stmt->execute([$normalized]);
            $bank = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($bank)) {
                $match['score'] = 100;
                $match['id'] = isset($bank['id']) ? (int)$bank['id'] : null;
                $match['name'] = (string)($bank['bank_name'] ?? '');
            }
        } catch (Throwable) {
            // Read path must stay resilient; matching failures are ignored.
        }

        return $match;
    }

    /**
     * @param array<string,mixed> $record
     * @param list<array<string,mixed>> $banks
     * @param array{score:int,id:int|null,name:string,suggestions:list<array<string,mixed>>} $supplierMatch
     * @param array{score:int,id:int|null,name:string} $bankMatch
     */
    private function renderRecordPartial(
        int $index,
        array $record,
        array $banks,
        object $guarantee,
        array $supplierMatch,
        array $bankMatch,
        ?string $latestEventSubtype,
        bool $recordCanExecuteActions
    ): string {
        ob_start();
        include __DIR__ . '/../../partials/record-form.php';
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $surface
     */
    private function wrapRecordSection(string $html, array $policy, array $surface): string
    {
        return '<div id="record-form-section" class="decision-card" data-current-event-type="current"'
            . ' data-policy-visible="' . (($policy['visible'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-actionable="' . (($policy['actionable'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-executable="' . (($policy['executable'] ?? false) ? '1' : '0') . '"'
            . ' data-surface-can-view-record="' . (($surface['can_view_record'] ?? false) ? '1' : '0') . '"'
            . ' data-surface-can-view-preview="' . (($surface['can_view_preview'] ?? false) ? '1' : '0') . '"'
            . ' data-surface-can-execute-actions="' . (($surface['can_execute_actions'] ?? false) ? '1' : '0') . '">'
            . $html
            . '</div>';
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function renderEmptyState(array $policy): string
    {
        return '<div id="record-form-section" class="decision-card decision-card-empty-state"'
            . ' data-policy-visible="' . (($policy['visible'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-actionable="' . (($policy['actionable'] ?? false) ? '1' : '0') . '"'
            . ' data-policy-executable="' . (($policy['executable'] ?? false) ? '1' : '0') . '"'
            . ' data-surface-can-view-record="0"'
            . ' data-surface-can-view-preview="0"'
            . ' data-surface-can-execute-actions="0">'
            . '<div class="card-body"><div class="empty-state-message" data-i18n="index.empty.no_record_in_scope">لا توجد سجلات ضمن نطاق العرض الحالي</div></div>'
            . '</div>';
    }
}

