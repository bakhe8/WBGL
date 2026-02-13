<?php
require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Support/BankNormalizer.php'; 

use App\Support\Database;
use App\Support\BankNormalizer;

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
    $updates = 0;
    $inserts = 0;
    $aliasInserts = 0;

    // Prepare alias insertion statement
    $insertAlias = $db->prepare('INSERT OR IGNORE INTO bank_alternative_names (bank_id, alternative_name, normalized_name) VALUES (?, ?, ?)');

    foreach ($data as $item) {
        $bankId = null;

        // Safe Update Logic
        if (isset($item['id'])) {
            // Check if exists
            $stmt = $db->prepare('SELECT * FROM banks WHERE id = ?');
            $stmt->execute([$item['id']]);
            $current = $stmt->fetch();

            if ($current) {
                // Update only non-empty fields
                $arabic = !empty($item['arabic_name']) ? $item['arabic_name'] : $current['arabic_name'];
                $english = !empty($item['english_name']) ? $item['english_name'] : $current['english_name'];
                $short = !empty($item['short_name']) ? $item['short_name'] : $current['short_name'];
                $dept = !empty($item['department']) ? $item['department'] : $current['department'];
                $addr = !empty($item['address_line1']) ? $item['address_line1'] : $current['address_line1'];
                $email = !empty($item['contact_email']) ? $item['contact_email'] : $current['contact_email'];

                $updateHelp = $db->prepare('UPDATE banks SET arabic_name=?, english_name=?, short_name=?, department=?, address_line1=?, contact_email=? WHERE id=?');
                $updateHelp->execute([$arabic, $english, $short, $dept, $addr, $email, $item['id']]);
                
                $bankId = $item['id'];
                $updates++;
            } else {
                // Insert with ID
                $insert = $db->prepare('INSERT INTO banks (id, arabic_name, english_name, short_name, department, address_line1, contact_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([
                    $item['id'],
                    $item['arabic_name'] ?? '',
                    $item['english_name'] ?? '',
                    $item['short_name'] ?? '',
                    $item['department'] ?? '',
                    $item['address_line1'] ?? '',
                    $item['contact_email'] ?? ''
                ]);
                $bankId = $item['id'];
                $inserts++;
            }
        } else {
            // New record without ID
            $insert = $db->prepare('INSERT INTO banks (arabic_name, english_name, short_name, department, address_line1, contact_email) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $item['arabic_name'] ?? '',
                $item['english_name'] ?? '',
                $item['short_name'] ?? '',
                $item['department'] ?? '',
                $item['address_line1'] ?? '',
                $item['contact_email'] ?? ''
            ]);
            $bankId = $db->lastInsertId();
            $inserts++;
        }

        // Handle Aliases Import (if bank was created/updated successfully)
        if ($bankId && isset($item['aliases']) && is_array($item['aliases'])) {
            foreach ($item['aliases'] as $alias) {
                if (empty(trim($alias))) continue;
                
                $normalized = BankNormalizer::normalize($alias);
                $insertAlias->execute([$bankId, trim($alias), $normalized]);
                if ($insertAlias->rowCount() > 0) {
                    $aliasInserts++;
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => "تم الاستيراد: $inserts إضافة، $updates تحديث، $aliasInserts صيغة بديلة."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
