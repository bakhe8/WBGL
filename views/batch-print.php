<?php
/**
 * V3 Batch Print View - Unified System
 * Uses LetterBuilder for consistent rendering
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\ViewPolicy;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Repositories\GuaranteeRepository;
use App\Repositories\BankRepository;
use App\Repositories\SupplierRepository;

ViewPolicy::guardView('batch-print.php');

function batchPrintAbort(string $message): never
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    die(
        '<style>.batch-print-error{padding:20px;font-family:sans-serif;text-align:center;}</style>' .
        '<div class="batch-print-error">' . $safeMessage . '</div>'
    );
}

// 1. Inputs
$idsParam = $_GET['ids'] ?? '';
$batchIdentifier = isset($_GET['batch_identifier']) ? trim((string)$_GET['batch_identifier']) : '';
$batchIdentifier = $batchIdentifier !== '' ? $batchIdentifier : null;
$settings = Settings::getInstance();
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$batchPrintLocaleCode = strtolower((string)$settings->get('DEFAULT_LOCALE', 'ar'));
if (!in_array($batchPrintLocaleCode, ['ar', 'en'], true)) {
    $batchPrintLocaleCode = 'ar';
}
$batchPrintLocalePrimary = [];
$batchPrintLocaleFallback = [];
$batchPrintPrimaryPath = __DIR__ . '/../public/locales/' . $batchPrintLocaleCode . '/batch_print.json';
$batchPrintFallbackPath = __DIR__ . '/../public/locales/ar/batch_print.json';
if (is_file($batchPrintPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchPrintPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $batchPrintLocalePrimary = $decodedLocale;
    }
}
if (is_file($batchPrintFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchPrintFallbackPath), true);
    if (is_array($decodedLocale)) {
        $batchPrintLocaleFallback = $decodedLocale;
    }
}
$batchPrintTodoArPrefix = '__' . 'TODO_AR__';
$batchPrintTodoEnPrefix = '__' . 'TODO_EN__';
$batchPrintIsPlaceholder = static function ($value) use ($batchPrintTodoArPrefix, $batchPrintTodoEnPrefix): bool {
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return str_starts_with($trimmed, $batchPrintTodoArPrefix) || str_starts_with($trimmed, $batchPrintTodoEnPrefix);
};
$batchPrintT = static function (string $key, array $params = [], ?string $fallback = null) use ($batchPrintLocalePrimary, $batchPrintLocaleFallback, $batchPrintIsPlaceholder): string {
    $value = $batchPrintLocalePrimary[$key] ?? null;
    if (!is_string($value) || $batchPrintIsPlaceholder($value)) {
        $value = $batchPrintLocaleFallback[$key] ?? null;
    }
    if (!is_string($value) || $batchPrintIsPlaceholder($value)) {
        $value = $fallback ?? $key;
    }
    foreach ($params as $token => $replacement) {
        $value = str_replace('{{' . (string)$token . '}}', (string)$replacement, $value);
    }
    return $value;
};
$batchPrintUnknownLabel = $batchPrintT('batch_print.ui.unknown', [], 'batch_print.ui.unknown');

if (!$idsParam) {
    batchPrintAbort($batchPrintT('batch_print.error.missing_ids'));
}

$guaranteeIds = explode(',', $idsParam);
$guaranteeIds = array_filter(array_map('intval', $guaranteeIds));

if (empty($guaranteeIds)) {
    batchPrintAbort($batchPrintT('batch_print.error.no_valid_records'));
}

$db = Database::connect();

// Default mode: filter out test guarantees unless explicitly requested.
if (!$includeTestData && !empty($guaranteeIds)) {
    $placeholders = implode(',', array_fill(0, count($guaranteeIds), '?'));
    $stmt = $db->prepare("
        SELECT id FROM guarantees 
        WHERE id IN ($placeholders) 
        AND is_test_data = 0
    ");
    $stmt->execute($guaranteeIds);
    $guaranteeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($guaranteeIds)) {
        batchPrintAbort($batchPrintT('batch_print.error.no_valid_records_production'));
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
    <?php $batchPrintTitle = $batchPrintT('batch_print.meta.title', ['count' => count($guaranteeIds)]); ?>
    <title><?= htmlspecialchars($batchPrintTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/../partials/ui-bootstrap.php'; ?>

    <!-- Link to external CSS instead of copying -->
    <link rel="stylesheet" href="/public/css/a11y.css">
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

<body data-i18n-namespaces="common,batch_print">

    <div class="floating-actions no-print">
        <button onclick="handleBatchPrint()" class="action-btn">
            <span data-i18n="batch_print.actions.print_all">🖨️ طباعة الكل</span> (<?= count($guaranteeIds) ?>)
        </button>
        <button onclick="handleCloseWindow()" class="action-btn close">
            <span data-i18n="batch_print.actions.close">✕ إغلاق</span>
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
            'id' => (int)$guaranteeId,
            'guarantee_number' => $guarantee->guaranteeNumber,
            'contract_number' => $guarantee->rawData['contract_number'] ?? '',
            'amount' => $guarantee->rawData['amount'] ?? 0,
            'expiry_date' => $guarantee->rawData['expiry_date'] ?? '',
            'type' => $guarantee->rawData['type'] ?? '',
            'related_to' => $guarantee->rawData['related_to'] ?? 'contract',
            'supplier_name' => $guarantee->rawData['supplier'] ?? $batchPrintUnknownLabel,
            'bank_name' => $guarantee->rawData['bank'] ?? $batchPrintUnknownLabel,
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
            $bankNameRaw = str_replace(["\r", "\n", "\t"], ' ', (string)($record['bank_name'] ?? ''));
            $bankName = trim($bankNameRaw);
            while (str_contains($bankName, '  ')) {
                $bankName = str_replace('  ', ' ', $bankName);
            }

            if ($bankName !== '' && $bankName !== $batchPrintUnknownLabel) {
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
                            $stmt = $db->prepare('SELECT id FROM banks WHERE (arabic_name LIKE ?) LIMIT 1');
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

<script src="/public/js/security.js?v=<?= time() ?>"></script>
<script src="/public/js/i18n.js?v=<?= time() ?>"></script>
<script src="/public/js/direction.js?v=<?= time() ?>"></script>
<script src="/public/js/theme.js?v=<?= time() ?>"></script>
<script src="/public/js/policy.js?v=<?= time() ?>"></script>
<script src="/public/js/nav-manifest.js?v=<?= time() ?>"></script>
<script src="/public/js/ui-runtime.js?v=<?= time() ?>"></script>
<script src="/public/js/global-shortcuts.js?v=<?= time() ?>"></script>
<script src="/public/js/print-audit.js?v=<?= time() ?>"></script>
    <script>
        const wbglBatchPrintIds = <?= json_encode(array_values(array_map('intval', $guaranteeIds)), JSON_UNESCAPED_UNICODE) ?>;
        const wbglBatchIdentifier = <?= json_encode($batchIdentifier, JSON_UNESCAPED_UNICODE) ?>;

        function handleBatchPrint() {
            const audit = window.WBGLPrintAudit;
            if (audit && typeof audit.recordBatchPrint === 'function') {
                audit.recordBatchPrint(wbglBatchPrintIds, wbglBatchIdentifier, {
                    trigger: 'floating_print_button'
                }).finally(() => window.print());
                return;
            }
            window.print();
        }

        function handleCloseWindow() {
            const closeFn = window['close'];
            if (typeof closeFn === 'function') {
                closeFn.call(window);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const audit = window.WBGLPrintAudit;
            if (audit && typeof audit.recordBatchOpen === 'function') {
                audit.recordBatchOpen(wbglBatchPrintIds, wbglBatchIdentifier, {
                    source: 'batch_print_view'
                }).catch(() => {});
            }
        });
    </script>
</body>

</html>
