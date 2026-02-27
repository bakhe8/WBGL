<?php
declare(strict_types=1);

/**
 * Backfill guarantee_occurrences from guarantees.import_source.
 *
 * Usage:
 *   php maint/backfill-occurrences-from-import-source.php
 *   php maint/backfill-occurrences-from-import-source.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

function wbglOccBatchType(string $batchIdentifier): string
{
    $value = strtolower(trim($batchIdentifier));
    if (str_starts_with($value, 'test_excel_') || str_starts_with($value, 'excel_')) {
        return 'excel';
    }
    if (str_starts_with($value, 'smart_paste_') || str_starts_with($value, 'paste_')) {
        return 'smart_paste';
    }
    if (str_starts_with($value, 'manual_')) {
        return 'manual';
    }
    return 'manual';
}

$asJson = in_array('--json', $argv, true);

$result = [
    'generated_at' => date(DATE_ATOM),
    'driver' => 'unknown',
    'total_candidates' => 0,
    'inserted' => 0,
    'already_present' => 0,
    'skipped_empty_source' => 0,
    'errors' => [],
];

try {
    $db = Database::connect();
    $result['driver'] = Database::currentDriver();

    $rows = $db->query("
        SELECT id, import_source, imported_at
        FROM guarantees
        ORDER BY id ASC
    ");
    $guarantees = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    $result['total_candidates'] = count($guarantees);

    $colStmt = $db->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'guarantee_occurrences'
    ");
    $occurrenceColumns = $colStmt ? $colStmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $hasImportSourceColumn = in_array('import_source', $occurrenceColumns, true);

    $existsStmt = $db->prepare('
        SELECT id
        FROM guarantee_occurrences
        WHERE guarantee_id = ? AND batch_identifier = ?
        LIMIT 1
    ');

    if ($hasImportSourceColumn) {
        $insertStmt = $db->prepare('
            INSERT INTO guarantee_occurrences
            (guarantee_id, batch_identifier, batch_type, import_source, occurred_at)
            VALUES (?, ?, ?, ?, ?)
        ');
    } else {
        $insertStmt = $db->prepare('
            INSERT INTO guarantee_occurrences
            (guarantee_id, batch_identifier, batch_type, occurred_at)
            VALUES (?, ?, ?, ?)
        ');
    }

    foreach ($guarantees as $row) {
        $guaranteeId = (int)($row['id'] ?? 0);
        $batchIdentifier = trim((string)($row['import_source'] ?? ''));
        if ($guaranteeId <= 0) {
            continue;
        }
        if ($batchIdentifier === '') {
            $result['skipped_empty_source']++;
            continue;
        }

        $existsStmt->execute([$guaranteeId, $batchIdentifier]);
        $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            $result['already_present']++;
            continue;
        }

        $occurredAt = (string)($row['imported_at'] ?? '');
        if ($occurredAt === '') {
            $occurredAt = date('Y-m-d H:i:s');
        }

        $batchType = wbglOccBatchType($batchIdentifier);
        if ($hasImportSourceColumn) {
            $insertStmt->execute([$guaranteeId, $batchIdentifier, $batchType, $batchType, $occurredAt]);
        } else {
            $insertStmt->execute([$guaranteeId, $batchIdentifier, $batchType, $occurredAt]);
        }
        $result['inserted']++;
    }
} catch (Throwable $e) {
    $result['errors'][] = $e->getMessage();
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

echo "WBGL Occurrence Backfill\n";
echo "Driver: {$result['driver']}\n";
echo "Candidates: {$result['total_candidates']}\n";
echo "Inserted: {$result['inserted']}\n";
echo "Already present: {$result['already_present']}\n";
echo "Skipped empty source: {$result['skipped_empty_source']}\n";
if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach ($result['errors'] as $error) {
        echo "- {$error}\n";
    }
}
