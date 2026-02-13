<?php
require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Support/Normalizer.php';
use App\Support\Database;
use App\Support\Normalizer;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid method');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $json = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new Exception('Invalid JSON format');
    }

    $db = Database::connect();
    $norm = new Normalizer();
    $updates = 0;
    $inserts = 0;

    foreach ($data as $item) {
        // Safe Update Logic
        if (isset($item['id'])) {
            $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ?');
            $stmt->execute([$item['id']]);
            $current = $stmt->fetch();

            if ($current) {
                // Update only non-empty
                $official = !empty($item['official_name']) ? $item['official_name'] : $current['official_name'];
                $english = !empty($item['english_name']) ? $item['english_name'] : $current['english_name'];
                // Confirmed is boolean, carefully handle. If key exists in JSON, update? Or overwrite rule "empty"?
                // JSON bool false is empty? No. isset check?
                // User said: "Empty fields (empty string/null) in file -> Ignore".
                // For boolean, strict check.
                $confirmed = isset($item['is_confirmed']) ? $item['is_confirmed'] : $current['is_confirmed'];
                $normalized = $norm->normalizeSupplierName($official);

                $updateHelp = $db->prepare('UPDATE suppliers SET official_name=?, english_name=?, normalized_name=?, is_confirmed=? WHERE id=?');
                $updateHelp->execute([$official, $english, $normalized, $confirmed, $item['id']]);
                $updates++;
            } else {
                // Insert with ID
                $official = $item['official_name'] ?? '';
                $insert = $db->prepare('INSERT INTO suppliers (id, official_name, english_name, normalized_name, is_confirmed) VALUES (?, ?, ?, ?, ?)');
                $insert->execute([
                    $item['id'],
                    $official,
                    $item['english_name'] ?? '',
                    $norm->normalizeSupplierName($official),
                    isset($item['is_confirmed']) ? $item['is_confirmed'] : 0
                ]);
                $inserts++;
            }
        } else {
            // New record
            $official = $item['official_name'] ?? '';
            $insert = $db->prepare('INSERT INTO suppliers (official_name, english_name, normalized_name, is_confirmed) VALUES (?, ?, ?, ?)');
            $insert->execute([
                $official,
                $item['english_name'] ?? '',
                $norm->normalizeSupplierName($official),
                isset($item['is_confirmed']) ? $item['is_confirmed'] : 0
            ]);
            $inserts++;
        }
    }

    echo json_encode(['success' => true, 'message' => "تم الاستيراد: $inserts إضافة، $updates تحديث"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
