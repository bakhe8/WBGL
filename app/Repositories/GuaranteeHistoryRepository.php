<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

class GuaranteeHistoryRepository
{
    public function log(int $guaranteeId, string $action, array $snapshot, ?string $reason = null, string $by = 'system'): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            INSERT INTO guarantee_history 
            (guarantee_id, action, snapshot_data, change_reason, created_by) 
            VALUES (:gid, :act, :snap, :reason, :by)
        ');
        
        $stmt->execute([
            'gid' => $guaranteeId,
            'act' => $action,
            'snap' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'by' => $by
        ]);
        
        return (int) $pdo->lastInsertId();
    }

    public function getHistory(int $guaranteeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = :gid ORDER BY created_at DESC');
        $stmt->execute(['gid' => $guaranteeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM guarantee_history WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $row['snapshot_data'] = json_decode($row['snapshot_data'], true);
        }
        
        return $row ?: null;
    }
}
