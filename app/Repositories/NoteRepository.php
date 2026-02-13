<?php
namespace App\Repositories;

use App\Support\Database;
use PDO;

class NoteRepository
{
    private PDO $db;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO guarantee_notes 
            (guarantee_id, content, created_by) 
            VALUES (:gid, :content, :user)
        ');
        
        $stmt->execute([
            'gid' => $data['guarantee_id'],
            'content' => $data['content'],
            'user' => $data['created_by'] ?? 'system'
        ]);
        
        return (int) $this->db->lastInsertId();
    }

    public function getByGuaranteeId(int $guaranteeId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
        $stmt->execute([$guaranteeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
