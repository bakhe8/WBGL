<?php
declare(strict_types=1);

/**
 * Scheduled job: notify guarantees expiring in next 30 days.
 *
 * Usage:
 *   php maint/notify-expiry.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\NotificationService;
use App\Support\Database;

try {
    $db = Database::connect();
    $expiryExpr = "(g.raw_data::jsonb ->> 'expiry_date')";
    $expiringWindow = "({$expiryExpr})::date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '30 days')";

    $sql = "
        SELECT g.id, g.guarantee_number, {$expiryExpr} AS expiry_date
        FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
          AND {$expiryExpr} IS NOT NULL
          AND {$expiringWindow}
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    foreach ($rows as $row) {
        $gid = (int)$row['id'];
        $number = (string)($row['guarantee_number'] ?? '');
        $expiry = (string)($row['expiry_date'] ?? '');
        $days = 0;
        if ($expiry !== '') {
            $days = (int)floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
            if ($days < 0) {
                $days = 0;
            }
        }

        $title = 'تنبيه انتهاء ضمان';
        $message = "الضمان رقم {$number} سينتهي خلال {$days} يوم (تاريخ الانتهاء: {$expiry})";
        $dedupe = "expiry:{$gid}:{$expiry}";

        NotificationService::create(
            'expiry_warning',
            $title,
            $message,
            null,
            [
                'guarantee_id' => $gid,
                'guarantee_number' => $number,
                'expiry_date' => $expiry,
                'days_remaining' => $days,
            ],
            $dedupe
        );
        $created++;
    }

    echo "Expiry notification job done. scanned=" . count($rows) . " processed={$created}" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'notify-expiry failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
