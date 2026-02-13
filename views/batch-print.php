<?php
/**
 * V3 Batch Print View - Unified System
 * Uses LetterBuilder for consistent rendering
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\BankRepository;
use App\Repositories\SupplierRepository;

// 1. Inputs
$idsParam = $_GET['ids'] ?? '';

if (!$idsParam) {
    die('<div style="padding: 20px; font-family: sans-serif; text-align: center;">معرفات السجلات مفقودة.</div>');
}

$guaranteeIds = explode(',', $idsParam);
$guaranteeIds = array_filter(array_map('intval', $guaranteeIds));

if (empty($guaranteeIds)) {
    die('<div style="padding: 20px; font-family: sans-serif; text-align: center;">لا توجد سجلات صالحة للطباعة.</div>');
}

// 2. Data Fetching
use App\Support\Settings;

$db = Database::connect();

// Production Mode: Filter out test guarantees
$settings = Settings::getInstance();
if ($settings->isProductionMode() && !empty($guaranteeIds)) {
    $placeholders = implode(',', array_fill(0, count($guaranteeIds), '?'));
    $stmt = $db->prepare("
        SELECT id FROM guarantees 
        WHERE id IN ($placeholders) 
        AND (is_test_data = 0 OR is_test_data IS NULL)
    ");
    $stmt->execute($guaranteeIds);
    $guaranteeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($guaranteeIds)) {
        die('<div style="padding: 20px; font-family: sans-serif; text-align: center;">لا توجد سجلات صالحة للطباعة في وضع الإنتاج.</div>');
    }
}

$guaranteeRepo = new GuaranteeRepository($db);
$bankRepo = new BankRepository();
$supplierRepo = new SupplierRepository();

$lastActions = [];
if (!empty($guaranteeIds)) {
    $placeholders = implode(',', array_fill(0, count($guaranteeIds), '?'));
    $stmt = $db->prepare("
        SELECT gh.guarantee_id,
               CASE
                   WHEN gh.event_subtype IN ('extension', 'reduction', 'release') THEN gh.event_subtype
                   WHEN gh.event_type = 'release' THEN 'release'
                   ELSE NULL
               END AS last_action
        FROM guarantee_history gh
        WHERE gh.guarantee_id IN ($placeholders)
          AND gh.id = (
            SELECT gh2.id
            FROM guarantee_history gh2
            WHERE gh2.guarantee_id = gh.guarantee_id
              AND (gh2.event_subtype IN ('extension', 'reduction', 'release') OR gh2.event_type = 'release')
            ORDER BY gh2.created_at DESC, gh2.id DESC
            LIMIT 1
          )
    ");
    $stmt->execute($guaranteeIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lastActions[(int) $row['guarantee_id']] = $row['last_action'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>طباعة مجمعة - <?= count($guaranteeIds) ?> خطابات</title>

    <!-- Link to external CSS instead of copying -->
    <link rel="stylesheet" href="/assets/css/letter.css">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap"
        rel=" stylesheet">

    <style>
        /* Only batch-specific overrides */
        body {
            background: #525659;
            margin: 0;
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .letter-preview {
            background: transparent !important;
            padding: 0 !important;
            width: auto !important;
            margin-bottom: 30px;
        }

        /* Hide the single-letter print button in batch mode */
        .btn-print-overlay {
            display: none !important;
        }

        /* Floating Print Button */
        .floating-actions {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .action-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-family: 'Tajawal', sans-serif;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .action-btn:hover {
            background: #1d4ed8;
        }

        .action-btn.close {
            background: #4b5563;
        }

        .action-btn.close:hover {
            background: #374151;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                display: block;
            }

            .floating-actions {
                display: none !important;
            }

            .letter-preview {
                margin: 0;
                page-break-after: always;
                width: 100% !important;
                background: white !important;

                /* FIX: Override absolute positioning from letter.css */
                position: relative !important;
                left: auto !important;
                top: auto !important;
            }

            .letter-preview:last-child {
                page-break-after: auto;
            }

            .letter-preview .letter-paper {
                box-shadow: none;
                margin: 0;
                width: 100% !important;
                height: 100% !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>

    <div class="floating-actions no-print">
        <button onclick="window.print()" class="action-btn">
            🖨️ طباعة الكل (<?= count($guaranteeIds) ?>)
        </button>
        <button onclick="window.close()" class="action-btn close">
            ✕ إغلاق
        </button>
    </div>

    <?php foreach ($guaranteeIds as $guaranteeId): ?>
        <?php
        // Fetch guarantee
        $guarantee = $guaranteeRepo->find((int) $guaranteeId);
        if (!$guarantee)
            continue;

        // Load decision data
        $decisionStmt = $db->prepare("SELECT * FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1");
        $decisionStmt->execute([$guaranteeId]);
        $decision = $decisionStmt->fetch(PDO::FETCH_ASSOC);

        $lastAction = $lastActions[(int) $guaranteeId] ?? null;
        if (!$lastAction) {
            continue;
        }

        // Prepare data array
        $record = [
            'guarantee_number' => $guarantee->guaranteeNumber,
            'contract_number' => $guarantee->rawData['contract_number'] ?? '',
            'amount' => $guarantee->rawData['amount'] ?? 0,
            'expiry_date' => $guarantee->rawData['expiry_date'] ?? '',
            'type' => $guarantee->rawData['type'] ?? '',
            'related_to' => $guarantee->rawData['related_to'] ?? 'contract',
            'supplier_name' => $guarantee->rawData['supplier'] ?? 'غير محدد',
            'bank_name' => $guarantee->rawData['bank'] ?? 'غير محدد',
            'active_action' => $lastAction ?? ($decision['active_action'] ?? null), // ✅ No default
        ];

        // Enrich with relations
        if ($decision && $decision['supplier_id']) {
            $supplier = $supplierRepo->find((int) $decision['supplier_id']);
            if ($supplier)
                $record['supplier_name'] = $supplier->officialName;
        }

        // Bank details from database (if decision has bank_id)
        if ($decision && $decision['bank_id']) {
            $bank = $bankRepo->getBankDetails((int) $decision['bank_id']);
            if ($bank) {
                $record['bank_name'] = $bank['official_name'];
                $record['bank_center'] = $bank['department'];
                $record['bank_po_box'] = $bank['po_box'];
                $record['bank_email'] = $bank['email'];
            }
        } else {
            // Fallback: Smart Bank Matching using Normalizer
            $bankName = trim(preg_replace('/\s+/u', ' ', $record['bank_name'] ?? ''));

            if ($bankName && $bankName !== 'غير محدد') {
                try {
                    $bankId = null;

                    // 1. Try exact match on 'arabic_name' (TRIMMED)
                    $stmt = $db->prepare("SELECT id FROM banks WHERE TRIM(arabic_name) = ? LIMIT 1");
                    $stmt->execute([$bankName]);
                    $bankId = $stmt->fetchColumn();

                    // 2. If not found, use Normalizer & Aliases
                    if (!$bankId && class_exists('\App\Support\BankNormalizer')) {
                        $normalized = \App\Support\BankNormalizer::normalize($bankName);

                        $stmt = $db->prepare("SELECT bank_id FROM bank_alternative_names WHERE normalized_name = ? LIMIT 1");
                        $stmt->execute([$normalized]);
                        $bankId = $stmt->fetchColumn();

                        // 3. Fallback: Fuzzy search with normalized name
                        if (!$bankId) {
                            $stmt = $db->prepare("SELECT id FROM banks WHERE arabic_name LIKE ? LIMIT 1");
                            $stmt->execute(["%$bankName%"]);
                            $bankId = $stmt->fetchColumn();
                        }
                    }

                    if ($bankId) {
                        $bank = $bankRepo->getBankDetails((int) $bankId);
                        if ($bank) {
                            $record['bank_name'] = $bank['official_name'];
                            $record['bank_center'] = $bank['department'];
                            // Force non-empty PO Box info if data exists
                            $record['bank_po_box'] = !empty($bank['po_box']) ? $bank['po_box'] : ($guarantee->rawData['bank_po_box'] ?? '');
                            $record['bank_email'] = !empty($bank['email']) ? $bank['email'] : ($guarantee->rawData['bank_email'] ?? '');
                        }
                    } else {
                        // Keep rawData
                        $record['bank_center'] = $guarantee->rawData['bank_center'] ?? '';
                        $record['bank_po_box'] = $guarantee->rawData['bank_po_box'] ?? '';
                        $record['bank_email'] = $guarantee->rawData['bank_email'] ?? '';
                    }
                } catch (\Exception $e) {
                    // Silent fail
                }
            }
        }

        // Use unified renderer (no placeholder in print context)
        $showPlaceholder = false;
        include __DIR__ . '/../partials/letter-renderer.php';
        ?>
    <?php endforeach; ?>

</body>

</html>
