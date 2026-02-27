<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Timeline Recorder
 * Core functions for tracking guarantee history events
 * Formerly TimelineHelper
 */
class TimelineRecorder
{

    /**
     * Create snapshot from Database (Server = Source Of Truth)
     *
     * If $decisionData provided, use it. Else, fetch from DB.
     * This snapshot represents the "current state" at the time of calling.
     */
    public static function createSnapshot($guaranteeId, $decisionData = null)
    {
        $db = \App\Support\Database::connection();

        if (!$decisionData) {
            // Fetch latest decision + raw data
            $stmt = $db->prepare("
                SELECT
                    g.raw_data,
                    d.supplier_id,
                    d.bank_id,
                    d.status,
                    s.official_name as supplier_name,
                    b.arabic_name as bank_name
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                LEFT JOIN banks b ON d.bank_id = b.id
                WHERE g.id = ?
            ");
            $stmt->execute([$guaranteeId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                return null;
            }
        } else {
            $data = $decisionData;
        }

        $rawData = json_decode($data['raw_data'], true);

        // 🔥 FIX: Fallback to raw_data if decision fields are null
        // This ensures snapshots ALWAYS have bank/supplier names
        $supplierName = $data['supplier_name'] ?? $rawData['supplier'] ?? '';
        $bankName = $data['bank_name'] ?? $rawData['bank'] ?? '';

        return [
            'guarantee_number' => $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => $data['supplier_id'],
            'supplier_name' => $supplierName,  // ← Always filled
            'raw_supplier_name' => $rawData['supplier'] ?? '', // 🟢 explicit raw fallback
            'bank_id' => $data['bank_id'],
            'bank_name' => $bankName,          // ← Always filled
            'raw_bank_name' => $rawData['bank'] ?? '',  // 🟢 explicit raw fallback
            'status' => $data['status'] ?? 'pending'
        ];
    }

    /**
     * ADR-007: Generate Immutable Letter HTML Snapshot
     * Renders the complete formatted letter HTML for historical accuracy
     *
     * ✅ UPDATED: Now uses unified LetterBuilder system instead of preview-section.php
     *
     * @param int $guaranteeId
     * @param string $actionType 'extension', 'reduction', or 'release'
     * @param array $actionData Additional action-specific data (e.g., new_expiry, new_amount)
     * @return string|null Complete letter HTML or null if guarantee not found
     */
    public static function generateLetterSnapshot($guaranteeId, $actionType, $actionData = [])
    {
        $db = \App\Support\Database::connection();

        error_log("🔍 generateLetterSnapshot (LetterBuilder): GID=$guaranteeId Type=$actionType");

        // Fetch current guarantee state
        $stmt = $db->prepare("
            SELECT
                g.raw_data,
                g.guarantee_number,
                d.supplier_id,
                d.bank_id,
                s.official_name as supplier_name,
                b.arabic_name as bank_name
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            LEFT JOIN banks b ON d.bank_id = b.id
            WHERE g.id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            error_log("❌ generateLetterSnapshot: Guarantee not found! GID=$guaranteeId");
            return null;
        }

        $rawData = json_decode($data['raw_data'], true);

        // ✅ Fetch dynamic bank details if bank_id exists
        $bankCenter = '';
        $bankPoBox = '';
        $bankEmail = '';

        if (!empty($data['bank_id'])) {
            // Use BankRepository to get bank details
            $bankRepo = new \App\Repositories\BankRepository();
            $bankDetails = $bankRepo->getBankDetails((int)$data['bank_id']);

            if ($bankDetails) {
                $bankCenter = $bankDetails['department'] ?? '';
                $bankPoBox = $bankDetails['po_box'] ?? '';
                $bankEmail = $bankDetails['email'] ?? '';
            }
        }

        // Build guarantee data array for LetterBuilder
        $guaranteeData = [
            'guarantee_number' => $data['guarantee_number'] ?? $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
            'amount' => $actionData['new_amount'] ?? ($rawData['amount'] ?? 0),
            'expiry_date' => $actionData['new_expiry'] ?? ($rawData['expiry_date'] ?? ''),
            'type' => $rawData['type'] ?? '',
            'related_to' => $rawData['related_to'] ?? 'contract',
            'supplier_name' => $data['supplier_name'] ?? $rawData['supplier'] ?? '',
            'bank_name' => $data['bank_name'] ?? $rawData['bank'] ?? '',
            'bank_center' => $bankCenter,
            'bank_po_box' => $bankPoBox,
            'bank_email' => $bankEmail
        ];

        // ✅ Use unified LetterBuilder system
        try {
            $letterData = \App\Services\LetterBuilder::prepare($guaranteeData, $actionType);
            $letterHtml = \App\Services\LetterBuilder::render($letterData);

            error_log("✅ generateLetterSnapshot: HTML generated via LetterBuilder (" . strlen($letterHtml) . " bytes)");
            return $letterHtml;
        } catch (\Exception $e) {
            error_log("❌ generateLetterSnapshot: LetterBuilder error - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect changes between old and new data
     * Returns array of changes with field, old_value, new_value, trigger
     */
    /**
     * Record Extension Event (UE-02)
     * Strictly monitors expiry_date change
     *
     * @param int $guaranteeId
     * @param array $oldSnapshot
     * @param string $newExpiry
     * @param int|null $actionId
     * @param array|null $letterSnapshot Optional letter snapshot (generated if not provided)
     */
    public static function recordExtensionEvent($guaranteeId, $oldSnapshot, $newExpiry, $actionId = null, $letterSnapshot = null)
    {
        // Validate change
        $oldExpiry = $oldSnapshot['expiry_date'] ?? null;
        if ($oldExpiry === $newExpiry) {
            return false; // No actual change
        }

        // ADR-007: Generate letter snapshot if not provided
        if (!$letterSnapshot) {
            // ✨ FIX: Pass actionData as array with proper key
            $letterSnapshot = self::generateLetterSnapshot($guaranteeId, 'extension', [
                'new_expiry' => $newExpiry
            ]);
        }

        $changes = [[
            'field' => 'expiry_date',
            'old_value' => $oldExpiry,
            'new_value' => $newExpiry,
            'trigger' => 'extension_action',
            'action_id' => $actionId
        ]];

        $currentUser = \App\Support\AuthService::getCurrentUser();
        $creatorName = $currentUser ? $currentUser->fullName : 'المستخدم';

        $afterSnapshot = self::createSnapshot($guaranteeId);
        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, $creatorName, [], 'extension', $letterSnapshot, $afterSnapshot);
    }

    /**
     * Record Reduction Event (UE-03)
     * Strictly monitors amount change
     *
     * @param int $guaranteeId
     * @param array $oldSnapshot
     * @param float $newAmount
     * @param float|null $previousAmount
     * @param array|null $letterSnapshot Optional letter snapshot
     */
    public static function recordReductionEvent($guaranteeId, $oldSnapshot, $newAmount, $previousAmount = null, $letterSnapshot = null)
    {
        // Use previousAmount if explicitly passed (for restore hacks), otherwise from snapshot
        $oldAmount = $previousAmount ?? ($oldSnapshot['amount'] ?? 0);

        if ((float)$oldAmount === (float)$newAmount) {
            return false;
        }

        // ADR-007: Generate letter snapshot if not provided
        if (!$letterSnapshot) {
            $letterSnapshot = self::generateLetterSnapshot($guaranteeId, 'reduction', ['new_amount' => $newAmount]);
        }

        $changes = [[
            'field' => 'amount',
            'old_value' => $oldAmount,
            'new_value' => $newAmount,
            'trigger' => 'reduction_action'
        ]];

        $currentUser = \App\Support\AuthService::getCurrentUser();
        $creatorName = $currentUser ? $currentUser->fullName : 'المستخدم';

        $afterSnapshot = self::createSnapshot($guaranteeId);
        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, $creatorName, [], 'reduction', $letterSnapshot, $afterSnapshot);
    }

    /**
     * Record Release Event (UE-04)
     * Strictly monitors status change to released
     *
     * @param int $guaranteeId
     * @param array $oldSnapshot
     * @param string|null $reason
     * @param array|null $letterSnapshot Optional letter snapshot
     */
    public static function recordReleaseEvent($guaranteeId, $oldSnapshot, $reason = null, $letterSnapshot = null)
    {
        // ADR-007: Generate letter snapshot if not provided
        if (!$letterSnapshot) {
            $letterSnapshot = self::generateLetterSnapshot($guaranteeId, 'release', []);
        }

        $changes = [[
            'field' => 'status',
            'old_value' => $oldSnapshot['status'] ?? 'pending',
            'new_value' => 'released',
            'trigger' => 'release_action'
        ]];

        // Add reason to event details if present
        $extraDetails = $reason ? ['reason_text' => $reason] : [];

        $currentUser = \App\Support\AuthService::getCurrentUser();
        $creatorName = $currentUser ? $currentUser->fullName : 'المستخدم';

        $afterSnapshot = self::createSnapshot($guaranteeId);
        return self::recordEvent($guaranteeId, 'release', $oldSnapshot, $changes, $creatorName, $extraDetails, 'release', $letterSnapshot, $afterSnapshot);
    }

    /**
     * Record Decision Event (UE-01 or SY-03)
     * Monitors Supplier/Bank changes
     */
    public static function recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, $isAuto = false, $confidence = null, $subtype = null)
    {
        $changes = [];

        // Check Supplier
        if (isset($newData['supplier_id'])) {
            $old = $oldSnapshot['supplier_id'] ?? null;
            $new = $newData['supplier_id'];
            if ($old != $new) {
                $changes[] = [
                    'field' => 'supplier_id',
                    'old_value' => ['id' => $old, 'name' => $oldSnapshot['supplier_name'] ?? ''],
                    'new_value' => ['id' => $new, 'name' => $newData['supplier_name'] ?? ''],
                    'trigger' => $isAuto ? 'ai_match' : 'manual'
                ];
            }
        }

        // Check Bank
        if (isset($newData['bank_id'])) {
            $old = $oldSnapshot['bank_id'] ?? null;
            $new = $newData['bank_id'];
            if ($old != $new) {
                $changes[] = [
                    'field' => 'bank_id',
                    'old_value' => ['id' => $old, 'name' => $oldSnapshot['bank_name'] ?? ''],
                    'new_value' => ['id' => $new, 'name' => $newData['bank_name'] ?? ''],
                    'trigger' => $isAuto ? 'ai_match' : 'manual'
                ];
            }
        }

        if (empty($changes)) {
            return false;
        }

        $creator = 'System';
        if (!$isAuto) {
            $currentUser = \App\Support\AuthService::getCurrentUser();
            $creator = $currentUser ? $currentUser->fullName : 'المستخدم';
        }

        $extra = $confidence ? ['confidence' => $confidence] : [];
        $afterSnapshot = self::createSnapshot($guaranteeId);

        $subtype = $subtype ?? ($isAuto ? 'ai_match' : 'manual_edit');
        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, $creator, $extra, $subtype, null, $afterSnapshot);
    }

    /**
     * Record Manual Admin Edit (Audit Milestone)
     * Stores a FULL snapshot as a safety anchor
     */
    public static function recordManualEditEvent($guaranteeId, array $newRawData, ?array $oldSnapshot = null)
    {
        $creatorName = self::getCurrentUser();
        $beforeSnapshot = is_array($oldSnapshot) && !empty($oldSnapshot)
            ? $oldSnapshot
            : self::createSnapshot($guaranteeId);
        $afterSnapshot = self::createSnapshot($guaranteeId, ['raw_data' => json_encode($newRawData, JSON_UNESCAPED_UNICODE)]);
        $changes = self::buildChangesFromSnapshots(
            is_array($beforeSnapshot) ? $beforeSnapshot : [],
            is_array($afterSnapshot) ? $afterSnapshot : [],
            'manual_edit'
        );

        $eventDetails = [
            'action' => 'Manual Administrative Edit',
            'reason' => 'Admin override via maintenance dashboard',
            'snapshot_contract' => 'before_change'
        ];

        return self::recordEvent(
            $guaranteeId,
            'modified',
            $beforeSnapshot,
            $changes,
            $creatorName,
            $eventDetails,
            'manual_edit',
            null,
            $afterSnapshot
        );
    }

    /**
     * Create JSON Patch (RFC 6902-ish) for audit
     */
    public static function createPatch($old, $new): array
    {
        $patch = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $old);
            $newExists = array_key_exists($key, $new);

            if (!$oldExists && $newExists) {
                $patch[] = ['op' => 'add', 'path' => '/' . $key, 'value' => $new[$key]];
            } elseif ($oldExists && !$newExists) {
                $patch[] = ['op' => 'remove', 'path' => '/' . $key];
            } elseif ($oldExists && $newExists && $old[$key] != $new[$key]) {
                $patch[] = ['op' => 'replace', 'path' => '/' . $key, 'value' => $new[$key]];
            }
        }
        return $patch;
    }

    /**
     * Build timeline-style changes array from two snapshots.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,array<string,mixed>>
     */
    private static function buildChangesFromSnapshots(array $before, array $after, string $trigger = 'manual_edit'): array
    {
        $patch = self::createPatch($before, $after);
        $changes = [];

        foreach ($patch as $op) {
            $path = (string)($op['path'] ?? '');
            if (!str_starts_with($path, '/')) {
                continue;
            }

            $field = substr($path, 1);
            if ($field === '') {
                continue;
            }

            $oldValue = $before[$field] ?? null;
            $newValue = array_key_exists('value', $op) ? $op['value'] : ($after[$field] ?? null);

            if (($op['op'] ?? '') === 'remove') {
                $newValue = null;
            }

            $changes[] = [
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'trigger' => $trigger,
            ];
        }

        return $changes;
    }

    /**
     * Core Private Recording Method
     * Enforces Closed Event Contract
     */
    private static function recordEvent(
        $guaranteeId,
        $type,
        $snapshot,
        $changes,
        $creator,
        $extraDetails = [],
        $subtype = null,  // 🆕 event_subtype
        $letterSnapshot = null,  // ADR-007: letter snapshot
        $postSnapshot = null     // Current state after change (for hybrid patch generation)
    ) {
        $db = \App\Support\Database::connection();
        $guaranteeId = (int)$guaranteeId;

        // Note: We do NOT calculate status change here anymore.
        // Status transitions (SE-01/02) must be recorded via recordStatusTransitionEvent separately.

        // 🛡️ LEDGER LOGIC: Periodic Auto-Anchors
        // To ensure high-speed reconstruction and 100% audit integrity,
        // we force a full snapshot every 10 events if one wasn't provided (Anchor).
        $anchorInterval = \App\Services\TimelineHybridLedger::anchorInterval();
        if ($snapshot === null) {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM guarantee_history WHERE guarantee_id = ?");
            $countStmt->execute([$guaranteeId]);
            $historyCount = (int)$countStmt->fetchColumn();

            if ($historyCount > 0 && ($historyCount + 1) % $anchorInterval === 0) {
                $snapshot = self::createSnapshot($guaranteeId);
                $extraDetails['ledger_auto_anchor'] = true;
                $extraDetails['checkpoint_reason'] = 'periodic_maintenance_anchor';
            }
        }

        $details = is_array($extraDetails) ? $extraDetails : [];
        $eventTimestamp = self::resolveEventTimestamp($details['event_time'] ?? null);
        if (array_key_exists('event_time', $details)) {
            unset($details['event_time']);
        }

        $eventDetails = array_merge([
            'changes' => $changes
        ], $details);

        // Map Creator to Display Text (If it's a known generic/internal ID, map it, otherwise use it)
        $creatorText = match ($creator) {
            'User', 'user', 'web_user' => 'بواسطة المستخدم',
            'System', 'system', 'System AI' => 'بواسطة النظام',
            default => 'بواسطة ' . $creator
        };

        error_log("🔍 recording event: Type=$type Subtype=$subtype GID=$guaranteeId");

        $snapshotJson = is_array($snapshot) && !empty($snapshot)
            ? json_encode($snapshot, JSON_UNESCAPED_UNICODE)
            : null;
        $eventDetailsJson = json_encode($eventDetails, JSON_UNESCAPED_UNICODE);

        $columns = [
            'guarantee_id',
            'event_type',
            'event_subtype',
            'snapshot_data',
            'event_details',
            'letter_snapshot',
            'created_at',
            'created_by',
        ];
        $values = [
            $guaranteeId,
            $type,
            $subtype,
            $snapshotJson,
            $eventDetailsJson,
            $letterSnapshot,
            $eventTimestamp,
            $creatorText,
        ];

        if (!\App\Services\TimelineHybridLedger::supportsHybridColumns($db)) {
            throw new \RuntimeException('Hybrid history columns are required before writing timeline events');
        }

        $currentSnapshotForHybrid = is_array($postSnapshot) && !empty($postSnapshot)
            ? $postSnapshot
            : self::createSnapshot($guaranteeId);

        $hybrid = \App\Services\TimelineHybridLedger::buildHybridPayload(
            $db,
            $guaranteeId,
            (string)$type,
            $subtype ? (string)$subtype : null,
            is_array($snapshot) ? $snapshot : null,
            is_array($currentSnapshotForHybrid) ? $currentSnapshotForHybrid : null,
            $details,
            is_string($letterSnapshot) ? $letterSnapshot : null
        );

        $columns = array_merge($columns, [
            'history_version',
            'patch_data',
            'anchor_snapshot',
            'is_anchor',
            'anchor_reason',
            'letter_context',
            'template_version',
        ]);

        $values = array_merge($values, [
            $hybrid['history_version'] ?? 'v2',
            !empty($hybrid['patch_data']) ? json_encode($hybrid['patch_data'], JSON_UNESCAPED_UNICODE) : null,
            !empty($hybrid['anchor_snapshot']) ? json_encode($hybrid['anchor_snapshot'], JSON_UNESCAPED_UNICODE) : null,
            (int)($hybrid['is_anchor'] ?? 0),
            $hybrid['anchor_reason'] ?? null,
            !empty($hybrid['letter_context']) ? json_encode($hybrid['letter_context'], JSON_UNESCAPED_UNICODE) : null,
            $hybrid['template_version'] ?? \App\Services\TimelineHybridLedger::templateVersion(),
        ]);

        // Hybrid mode is patch-first by default to avoid storing full unchanged payloads.
        $values[3] = null;

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $db->prepare(
            'INSERT INTO guarantee_history (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        );

        try {
            $stmt->execute($values);
            $id = $db->lastInsertId();
            error_log("✅ Event recorded successfully. ID=$id");
            return $id;
        } catch (\PDOException $e) {
            error_log("❌ DB Error recording event: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Public structured recorder for callers that need custom event_type/subtype
     * while still going through hybrid ledger and snapshot contract enforcement.
     *
     * @param array<string,mixed> $beforeSnapshot
     * @param array<int,array<string,mixed>> $changes
     * @param array<string,mixed> $extraDetails
     * @param array<string,mixed>|null $afterSnapshot
     */
    public static function recordStructuredEvent(
        int $guaranteeId,
        string $eventType,
        ?string $eventSubtype,
        array $beforeSnapshot,
        array $changes,
        string $creator,
        array $extraDetails = [],
        ?string $letterSnapshot = null,
        ?array $afterSnapshot = null
    ) {
        return self::recordEvent(
            $guaranteeId,
            $eventType,
            $beforeSnapshot,
            $changes,
            $creator,
            $extraDetails,
            $eventSubtype,
            $letterSnapshot,
            $afterSnapshot
        );
    }

    /**
     * saveImportEvent / saveReimportEvent kept as is (LE-00)
     */
    /**
     * Record Import Event (LE-00)
     * The ONLY entry point.
     */
    public static function recordImportEvent($guaranteeId, $source = 'excel', $explicitRawData = null)
    {
        $db = \App\Support\Database::connection();
        // Check if import event already exists (prevent duplicates)
        $stmt = $db->prepare("SELECT id FROM guarantee_history WHERE guarantee_id = ? AND event_type = 'import' LIMIT 1");
        $stmt->execute([$guaranteeId]);
        if ($stmt->fetch()) {
            return false; // Already has import event
        }

        $rawData = [];

        if ($explicitRawData) {
            $rawData = $explicitRawData;
        } else {
            // Fallback: Fetch raw_data from guarantees
            $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
            $stmt->execute([$guaranteeId]);
            $rawDataJson = $stmt->fetchColumn();

            if (!$rawDataJson) {
                // Keep error log for fallback cases
                error_log("❌ TimelineRecorder: No raw_data found for guarantee ID $guaranteeId during import event creation.");
                $snapshot = [];
            } else {
                $rawData = json_decode($rawDataJson, true) ?? [];
            }
        }

        // Create snapshot from RAW data (whether explicit or from DB)
        $snapshot = [
            'supplier_name' => $rawData['supplier'] ?? '',
            'bank_name' => $rawData['bank'] ?? '',
            'supplier_id' => null,
            'bank_id' => null,
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
            'guarantee_number' => $rawData['guarantee_number'] ?? $rawData['bg_number'] ?? '',
            'type' => $rawData['type'] ?? '',
            'status' => 'pending'
        ];

        $eventDetails = ['source' => $source];

        // 🛡️ Anchor Point: Import ALWAYS stores a full snapshot
        return self::recordEvent(
            $guaranteeId,
            'import',
            $snapshot,
            [],
            'النظام',
            $eventDetails,
            $source
        );
    }

    /**
     * Record Duplicate Import Event (RE-00)
     * Called when user attempts to import/paste a guarantee that already exists
     * Creates a timeline event for transparency without modifying guarantee data
     */
    public static function recordDuplicateImportEvent($guaranteeId, $source = 'excel')
    {
        $db = \App\Support\Database::connection();

        // Fetch current raw_data for snapshot
        $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawDataJson = $stmt->fetchColumn();

        if (!$rawDataJson) {
            return false;
        }

        $rawData = json_decode($rawDataJson, true) ?? [];

        // Create snapshot from current data
        $snapshot = [
            'supplier_name' => $rawData['supplier'] ?? '',
            'bank_name' => $rawData['bank'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'guarantee_number' => $rawData['guarantee_number'] ?? '',
        ];

        $eventDetails = [
            'source' => $source,
            'message' => 'محاولة استيراد مكرر - الضمان موجود بالفعل',
            'action' => 'duplicate_detected'
        ];

        // Use 'import' type with 'duplicate' subtype
        return self::recordEvent(
            $guaranteeId,
            'import',
            $snapshot,
            [],  // no changes
            'النظام',
            $eventDetails,
            'duplicate_' . $source  // event_subtype (duplicate_excel/duplicate_smart_paste)
        );
    }

    public static function recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, $reason = 'auto_logic')
    {
        $oldStatus = $oldSnapshot['status'] ?? 'pending';

        if ($oldStatus === $newStatus) {
            return false;
        }
        $currentSnapshot = self::createSnapshot($guaranteeId);

        $changes = [[
            'field' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'trigger' => $reason
        ]];

        // Metadata (Conflict is Reason, NOT Event)
        $extra = ['reason' => $reason];

        return self::recordEvent($guaranteeId, 'status_change', $oldSnapshot, $changes, 'System', $extra, 'status_change', null, $currentSnapshot);
    }

    /**
     * saveReimportEvent (LE-00 Equivalent for duplicates, but strictly separate type)
     */
    public static function recordReimportEvent($guaranteeId, $source = 'excel')
    {
        $snapshot = self::createSnapshot($guaranteeId);
        $eventDetails = ['source' => $source, 'reason' => 'duplicate_guarantee_number'];
        return self::recordEvent(
            $guaranteeId,
            'reimport',
            $snapshot,
            [],
            'النظام',
            $eventDetails,
            (string)$source,
            null,
            $snapshot
        );
    }

    // ... [Keep getEventDisplayLabel as per previous fix, but ensure it handles new structure] ...

    public static function getTimeline($guaranteeId)
    {
        $db = \App\Support\Database::connection();
        $stmt = $db->prepare("SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC, id DESC");
        $stmt->execute([$guaranteeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $resolvedSnapshot = \App\Services\TimelineHybridLedger::resolveEventSnapshot($db, $row);
            $snapshotRaw = json_encode($resolvedSnapshot, JSON_UNESCAPED_UNICODE);
            $row['snapshot_data'] = (is_string($snapshotRaw) && trim($snapshotRaw) !== '') ? $snapshotRaw : '{}';
        }

        return $rows;
    }

    public static function getEventDisplayLabel(array $event): string
    {
        return \App\Services\TimelineEventCatalog::getEventDisplayLabel($event);
    }

    public static function getEventIcon(array $event): string
    {
        return \App\Services\TimelineEventCatalog::getEventIcon($event);
    }

    /**
     * Record Workflow Stage Transition Event (Phase 3)
     */
    public static function recordWorkflowEvent($guaranteeId, $oldStep, $newStep, $userName, $oldSnapshot = null)
    {
        $beforeSnapshot = is_array($oldSnapshot) && !empty($oldSnapshot)
            ? $oldSnapshot
            : self::createSnapshot($guaranteeId);
        $currentSnapshot = self::createSnapshot($guaranteeId);

        $changes = [[
            'field' => 'workflow_step',
            'old_value' => $oldStep,
            'new_value' => $newStep,
            'trigger' => 'workflow_advance'
        ]];

        return self::recordEvent(
            $guaranteeId,
            'status_change',
            $beforeSnapshot,
            $changes,
            $userName,
            [],
            'workflow_advance',
            null,
            $currentSnapshot
        );
    }

    /**
     * Record Reopen Event (manual_override)
     */
    public static function recordReopenEvent($guaranteeId, $oldSnapshot)
    {
        $creatorName = self::getCurrentUser();

        $changes = [[
            'field' => 'status',
            'old_value' => $oldSnapshot['status'] ?? 'ready',
            'new_value' => 'pending',
            'trigger' => 'manual_correction'
        ]];

        $currentSnapshot = self::createSnapshot($guaranteeId);
        return self::recordEvent($guaranteeId, 'manual_override', $oldSnapshot, $changes, $creatorName, [
            'action' => 'Re-opened for correction',
            'reason' => 'User requested manual correction of record data'
        ], 'reopened', null, $currentSnapshot);
    }

    private static function getCurrentUser(): string
    {
        $user = \App\Support\AuthService::getCurrentUser();
        return $user ? $user->fullName : 'المستخدم';
    }

    private static function resolveEventTimestamp($value): string
    {
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return date('Y-m-d H:i:s');
    }
}
