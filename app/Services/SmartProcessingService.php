<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Support\BankNormalizer;
use App\Support\Logger;
use App\Models\TrustDecision;
use App\Services\ConflictDetector;
use PDO;

/**
 * Smart Processing Service
 * 
 * Responsible for applying AI intelligence to new guarantees
 * regardless of their source (Excel, Manual, Paste).
 * 
 * Core functions:
 * 1. Auto-matching suppliers and banks
 * 2. Creating decisions for high-confidence matches (>90% Supplier, direct match for Banks)
 * 3. Logging auto-match events
 */
class SmartProcessingService
{
    private PDO $db;
    private \App\Services\Learning\UnifiedLearningAuthority $authority;
    private ConflictDetector $conflictDetector;
    private \App\Support\Settings $settings;
    private string $decisionSource;
    private string $decidedBy;

    public function __construct(?string $decisionSource = null, ?string $decidedBy = null)
    {
        $this->db = Database::connect();
        $this->settings = new \App\Support\Settings();
        $this->decisionSource = $decisionSource ?? 'auto';
        $this->decidedBy = $decidedBy ?? 'system';
        
        // ✅ PHASE 4 COMPLETE: Using UnifiedLearningAuthority (100% cutover)
        // Legacy LearningService removed
        $this->authority = \App\Services\Learning\AuthorityFactory::create();
        $this->conflictDetector = new ConflictDetector();
    }


    /**
     * Process any pending guarantees automatically
     * Can be called after Excel import, Manual Entry, or Paste
     * 
     * @param int $limit Max records to process at once
     * @return array statistics ['processed' => int, 'auto_matched' => int]
     */
    public function processNewGuarantees(int $limit = 500): array
    {
        // 1. Find pending guarantees (those without decisions)
        $sql = "
            SELECT g.* 
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
            WHERE d.id IS NULL
            ORDER BY g.id DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        $guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['processed' => 0, 'auto_matched' => 0, 'banks_matched' => 0];

        foreach ($guarantees as $row) {
            $stats['processed']++;
            $rawData = json_decode($row['raw_data'], true);
            $guaranteeId = $row['id'];
            
            $supplierName = $rawData['supplier'] ?? '';
            $bankName = $rawData['bank'] ?? '';

            if (empty($supplierName) || empty($bankName)) {
                continue;
            }

            // =====================================================================
            // STEP 1: BANK MATCHING (ALWAYS FIRST, INDEPENDENT)
            // =====================================================================
            $bankId = null;
            $finalBankName = '';
            
            if (!empty($bankName)) {
                $normalized = BankNormalizer::normalize($bankName);
                // ✅ ENHANCED: Search in both alternative_names AND short_name
                $stmt2 = $this->db->prepare("
                    SELECT DISTINCT b.id, b.arabic_name
                    FROM banks b
                    LEFT JOIN bank_alternative_names a ON b.id = a.bank_id
                    WHERE a.normalized_name = ? OR LOWER(b.short_name) = LOWER(?)
                    LIMIT 1
                ");
                $stmt2->execute([$normalized, $bankName]);
                $bank = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if ($bank) {
                    $bankId = $bank['id'];
                    $finalBankName = $bank['arabic_name'];
                    
                    // ✅ ALWAYS log bank match event (independent of supplier)
                    $this->logBankAutoMatchEvent($guaranteeId, $rawData['bank'], $finalBankName);
                    $stats['banks_matched']++;
                    
                    // Update raw_data with matched bank name
                    $this->updateBankNameInRawData($guaranteeId, $finalBankName);
                }
            }

            // =====================================================================
            // STEP 2: SUPPLIER MATCHING (Using Authority)
            // =====================================================================
            $supplierId = null;
            
            // ✅ Get suggestions from UnifiedLearningAuthority
            $suggestionDTOs = $this->authority->getSuggestions($supplierName);
            
            // Convert DTOs to array format for compatibility with existing code
            $supplierSuggestions = array_map(function($dto) {
                return [
                    'id' => $dto->supplier_id,
                    'official_name' => $dto->official_name,
                    'english_name' => $dto->english_name,
                    'score' => $dto->confidence,
                    'level' => $dto->level,
                    'reason_ar' => $dto->reason_ar,
                    'source' => $dto->primary_source ?? 'authority',
                    'confirmation_count' => $dto->confirmation_count,
                    'rejection_count' => $dto->rejection_count
                ];
            }, $suggestionDTOs);
            
            $supplierConfidence = 0;
            $finalSupplierName = '';
            $trustDecision = null;
            
            if (!empty($supplierSuggestions)) {
                $top = $supplierSuggestions[0];
                
                // Evaluate trust using new Explainable Trust Gate
                $trustDecision = $this->evaluateTrust(
                    $top['id'],
                    $top['source'] ?? null,
                    $top['score'],
                    $supplierName
                );
                
                if ($trustDecision->allowed) {
                    $supplierId = $top['id'];
                    $finalSupplierName = $top['official_name'];
                    $supplierConfidence = $top['score'];
                    
                    // PHASE 2: Apply Targeted Negative Learning if Override occurred
                    if ($trustDecision->shouldApplyTargetedPenalty()) {
                        // Note: Targeted penalty would be applied via Authority
                        // For now, log it
                        error_log("[Authority] Trust override - penalty needed for blocking alias");
                    }
                }
            }

            // =====================================================================
            // STEP 3: DECISION CREATION (ONLY if BOTH succeeded)
            // =====================================================================
            
            // Conflict Detection
            $candidates = [
                'supplier' => [
                    'candidates' => $supplierSuggestions,
                    'normalized' => mb_strtolower(trim($supplierName)) 
                ],
                'bank' => [
                    'candidates' => [],  // Banks use direct matching
                    'normalized' => mb_strtolower(trim($bankName))
                ]
            ];
            
            $recordContext = [
                'raw_supplier_name' => $supplierName,
                'raw_bank_name' => $bankName
            ];
            
            $conflicts = $this->conflictDetector->detect($candidates, $recordContext);

            // Auto-approve ONLY if BOTH supplier AND bank matched + No conflicts
            if ($supplierId && $bankId && empty($conflicts)) {
                $threshold = $this->settings->get('MATCH_AUTO_THRESHOLD', 90);
                Logger::info('auto_match_decision', [
                    'guarantee_id' => $guaranteeId,
                    'supplier_id' => $supplierId,
                    'bank_id' => $bankId,
                    'confidence' => $supplierConfidence,
                    'threshold' => $threshold,
                    'source' => 'smart_processing'
                ]);
                $this->createAutoDecision($guaranteeId, $supplierId, $bankId, $supplierConfidence);
                $this->logAutoMatchEvents($guaranteeId, $rawData, $finalSupplierName, $supplierConfidence);
                
                // Record status transition event for timeline visibility
                $oldSnapshot = [
                    'status' => 'pending',
                    'supplier_id' => null,
                    'bank_id' => null
                ];
                \App\Services\TimelineRecorder::recordStatusTransitionEvent(
                    $guaranteeId,
                    $oldSnapshot,
                    'ready',
                    'auto_match_completion'
                );
                
                $stats['auto_matched']++;
            } else {
                if ($trustDecision && !$trustDecision->allowed) {
                    // EXPLAINABLE TRUST GATE: Log why auto-approval was blocked
                    error_log(sprintf(
                        "[TRUST_GATE] Auto-approval blocked for guarantee #%d - Reason: %s, Confidence: %d",
                        $guaranteeId,
                        $trustDecision->reason,
                        $trustDecision->confidence
                    ));
                    
                    if ($trustDecision->blockingAlias) {
                        error_log(sprintf(
                            "[TRUST_GATE] Blocking alias: '%s' (source: %s, usage: %d)",
                            $trustDecision->blockingAlias['alternative_name'],
                            $trustDecision->blockingAlias['source'],
                            $trustDecision->blockingAlias['usage_count']
                        ));
                    }
                }

                if ($bankId) {
                    // Bank is deterministic and should be persisted even if supplier is pending.
                    $this->createBankOnlyDecision($guaranteeId, $bankId);
                }
            }
        }

        return $stats;
    }

    /**
     * Create 'Approved' decision ensuring status becomes 'ready'
     * ✅ TYPE SAFETY: Ensure IDs are integers (not strings from database)
     */
    private function createAutoDecision(int $guaranteeId, int $supplierId, int $bankId, float $confidence = 100.0): void
    {
        // Explicit type safety - ensure integers
        $supplierIdSafe = (int)$supplierId;
        $bankIdSafe = (int)$bankId;
        $now = date('Y-m-d H:i:s');
        $existing = $this->fetchDecisionRow($guaranteeId);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE guarantee_decisions
                SET supplier_id = ?,
                    bank_id = ?,
                    status = 'ready',
                    decision_source = ?,
                    decided_by = ?,
                    confidence_score = ?,
                    decided_at = ?,
                    last_modified_at = CURRENT_TIMESTAMP,
                    last_modified_by = ?
                WHERE guarantee_id = ?
            ");
            $stmt->execute([
                $supplierIdSafe,
                $bankIdSafe,
                $this->decisionSource,
                $this->decidedBy,
                $confidence,
                $now,
                $this->decidedBy,
                $guaranteeId
            ]);
            return;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decision_source, decided_by, confidence_score, created_at)
            VALUES (?, ?, ?, 'ready', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $guaranteeId,
            $supplierIdSafe,
            $bankIdSafe,
            $this->decisionSource,
            $this->decidedBy,
            $confidence,
            $now
        ]);
    }

    /**
     * Persist bank-only decision (supplier pending).
     */
    private function createBankOnlyDecision(int $guaranteeId, int $bankId): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->fetchDecisionRow($guaranteeId);

        if ($existing) {
            $supplierId = $existing['supplier_id'] ? (int)$existing['supplier_id'] : null;
            $status = $supplierId ? 'ready' : 'pending';
            $stmt = $this->db->prepare("
                UPDATE guarantee_decisions
                SET bank_id = ?,
                    status = ?,
                    decision_source = ?,
                    decided_by = ?,
                    decided_at = ?,
                    last_modified_at = CURRENT_TIMESTAMP,
                    last_modified_by = ?
                WHERE guarantee_id = ?
            ");
            $stmt->execute([
                (int)$bankId,
                $status,
                $this->decisionSource,
                $this->decidedBy,
                $now,
                $this->decidedBy,
                $guaranteeId
            ]);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO guarantee_decisions (guarantee_id, bank_id, status, decision_source, decided_by, decided_at, created_at)
            VALUES (?, ?, 'pending', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $guaranteeId,
            (int)$bankId,
            $this->decisionSource,
            $this->decidedBy,
            $now,
            $now
        ]);
    }

    private function fetchDecisionRow(int $guaranteeId): ?array
    {
        $stmt = $this->db->prepare("SELECT supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1");
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Log timeline events for transparency
     * Note: Bank matching is now automatic and deterministic, so we only log supplier events
     */
    private function logAutoMatchEvents(int $guaranteeId, array $raw, string $supName, int $supScore): void
    {
        // CRITICAL FIX: Re-fetch raw_data to get updated bank name
        // Bank matching happens BEFORE this, and updates raw_data in DB
        // We need fresh data to ensure snapshot has the matched bank name
        $stmt = $this->db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $freshRaw = json_decode($stmt->fetchColumn(), true);
        
        // Use fresh data if available, fallback to passed data
        $dataForSnapshot = $freshRaw ?: $raw;
        
        // 1. Fetch current data for Snapshot (State BEFORE approval)
        // Since this runs immediately after creation, status was pending, supplier was null.
        $snapshot = [
            'guarantee_number' => $dataForSnapshot['bg_number'] ?? $dataForSnapshot['guarantee_number'] ?? '',
            'contract_number' => $dataForSnapshot['contract_number'] ?? '',
            'amount' => $dataForSnapshot['amount'] ?? 0,
            'expiry_date' => $dataForSnapshot['expiry_date'] ?? '',
            'issue_date' => $dataForSnapshot['issue_date'] ?? '',
            'type' => $dataForSnapshot['type'] ?? '',
            'supplier_id' => null,   // Before match
            'supplier_name' => $raw['supplier'] ?? '',  // Use original for "before" state
            'raw_supplier_name' => $raw['supplier'] ?? '',
            'bank_id' => null,
            'bank_name' => $dataForSnapshot['bank'] ?? '',  // ✅ FIXED: Now gets matched bank name!
            'raw_bank_name' => $dataForSnapshot['bank'] ?? '',
            'status' => 'pending'
        ];

        // 2. Prepare Event Details with 'changes'
        $eventDetails = [
            'action' => 'Auto-matched and approved',
            'changes' => [[
                'field' => 'supplier_id',
                'old_value' => ['id' => null, 'name' => $raw['supplier']],
                'new_value' => ['id' => 'matched', 'name' => $supName], // ID is technically the new ID, but name is what matters for display
                'trigger' => 'ai_match'
            ]],
            'supplier' => ['raw' => $raw['supplier'], 'matched' => $supName, 'score' => $supScore],
            'result' => 'Automatically approved based on high confidence match'
        ];

        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            'ai_match',
            json_encode($snapshot),
            json_encode($eventDetails),
            date('Y-m-d H:i:s'),
            'System AI'
        ]);
    }
    
    /**
     * Update Bank Name in raw_data
     * Updates the guarantee's raw_data to use the matched bank name
     */
    private function updateBankNameInRawData(int $guaranteeId, string $matchedBankName): void
    {
        $stmt = $this->db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawData = json_decode($stmt->fetchColumn(), true);
        
        if ($rawData) {
            // Update bank name with matched name
            $rawData['bank'] = $matchedBankName;
            
            $updateStmt = $this->db->prepare("UPDATE guarantees SET raw_data = ? WHERE id = ?");
            $updateStmt->execute([json_encode($rawData), $guaranteeId]);
        }
    }
    
    /**
     * Log Bank Auto-Match Event
     * Records bank matching as a separate timeline event
     * 
     * CRITICAL: snapshot_data must contain the state BEFORE the change
     */
    private function logBankAutoMatchEvent(int $guaranteeId, string $rawBankName, string $matchedBankName): void
    {
        // Get current guarantee data to build snapshot
        $stmt = $this->db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawDataJson = $stmt->fetchColumn();
        $rawData = json_decode($rawDataJson, true);
        
        // Create snapshot BEFORE bank update (state before this event)
        $snapshot = [
            'guarantee_number' => $rawData['bg_number'] ?? $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => null,  // Not matched yet
            'supplier_name' => $rawData['supplier'] ?? '',  // Keep original supplier name
            'raw_supplier_name' => $rawData['supplier'] ?? '', // 🟢 explicit raw
            'bank_id' => null,      // Not matched yet (before this event)
            'bank_name' => $rawBankName,  // BEFORE matching (original user input)
            'raw_bank_name' => $rawBankName, // 🟢 explicit raw
            'status' => 'pending'
        ];
        
        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            'bank_match',
            json_encode($snapshot),  // Full state BEFORE change
            json_encode([
                'action' => 'Bank auto-matched',
                'changes' => [[
                    'field' => 'bank_name',
                    'old_value' => $rawBankName,
                    'new_value' => $matchedBankName,
                    'trigger' => 'auto'
                ]],
                'result' => 'Automatically matched during import'
            ]),
            date('Y-m-d H:i:s'),
            'System AI'
        ]);
    }

    /**
     * Evaluate trust for a supplier match (Explainable Trust Gate)
     * 
     * This method implements the core Trust Gate logic, transforming it from
     * a simple Boolean check to an explainable decision with context.
     * 
     * @param int $supplierId The matched supplier ID
     * @param string|null $source The source of the match ('alias', 'search', etc.)
     * @param int $score The confidence score
     * @param string $rawName The raw input name
     * @return TrustDecision The trust decision with reasoning
     */
    private function evaluateTrust(
        int $supplierId,
        ?string $source,
        int $score,
        string $rawName
    ): TrustDecision {
        // Rule 1: Low confidence - block
        // ✅ DYNAMIC: Use threshold from Settings
        $threshold = $this->settings->get('MATCH_AUTO_THRESHOLD', 90);

        if ($score < $threshold) {
            return TrustDecision::block(
                TrustDecision::REASON_LOW_CONFIDENCE,
                $score
            );
        }

        // Rule 2: Alias source - check for conflicts AND check alias trust
        if ($source === 'alias') {
            // For alias matches, we need to check:
            // 1. The actual source of THIS alias (is it trusted?)
            // 2. Are there OTHER conflicting aliases?
            
            $learningRepo = new SupplierLearningRepository($this->db);
            $normalized = \App\Support\ArabicNormalizer::normalize($rawName);
            
            // Get the ACTUAL source of the current alias match
            $currentAliasStmt = $learningRepo->db->prepare("
                SELECT source, usage_count
                FROM supplier_alternative_names
                WHERE supplier_id = ? AND normalized_name = ?
                LIMIT 1
            ");
            $currentAliasStmt->execute([$supplierId, $normalized]);
            $currentAlias = $currentAliasStmt->fetch(PDO::FETCH_ASSOC);
            
            $aliasSource = $currentAlias['source'] ?? 'unknown';
            
            // Check for OTHER conflicting aliases
            $conflicts = $learningRepo->findConflictingAliases($supplierId, $normalized);
            
            // If THIS alias is from a trusted source (import_official, manual, seed)
            // AND there are conflicts, we OVERRIDE (trust this, penalize others)
            $trustedSources = ['import_official', 'manual', 'seed'];
            
            if (in_array($aliasSource, $trustedSources) && !empty($conflicts)) {
                // Trust Override scenario
                $culprit = $conflicts[0];
                return TrustDecision::override($score, $culprit);
            }
            
            // If THIS alias is from learning AND there are conflicts, BLOCK
            if (!empty($conflicts)) {
                $culprit = $conflicts[0];
                return TrustDecision::block(
                    TrustDecision::REASON_ALIAS_CONFLICT,
                    $score,
                    $culprit
                );
            }
            
            // Alias match with no conflicts - allow
            return TrustDecision::allow(TrustDecision::REASON_HIGH_CONFIDENCE, $score);
        }

        // Rule 3: High confidence search/import - check for conflicts
        // This is the critical case: we have a good match, but need to check
        // if there are conflicting aliases that would have blocked trust
        $learningRepo = new SupplierLearningRepository($this->db);
        $normalized = \App\Support\ArabicNormalizer::normalize($rawName);
        $conflicts = $learningRepo->findConflictingAliases($supplierId, $normalized);
        
        if (!empty($conflicts)) {
            // We have a high-confidence match BUT there are conflicting aliases
            // This is a Trust Override scenario - we trust the current match
            // but identify the culprit for targeted negative learning
            $culprit = $conflicts[0]; // Highest priority conflict
            return TrustDecision::override($score, $culprit);
        }

        // Clean high-confidence match with no conflicts
        return TrustDecision::allow(TrustDecision::REASON_HIGH_CONFIDENCE, $score);
    }
}

