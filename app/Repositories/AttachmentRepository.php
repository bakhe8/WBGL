<?php
namespace App\Repositories;

use App\Support\Database;
use PDO;

class AttachmentRepository
{
    private PDO $db;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO guarantee_attachments 
            (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by) 
            VALUES (:gid, :name, :path, :size, :type, :user)
        ');
        
        $stmt->execute([
            'gid' => $data['guarantee_id'],
            'name' => $data['file_name'],
            'path' => $data['file_path'],
            'size' => $data['file_size'] ?? 0,
            'type' => $data['file_type'] ?? 'unknown',
            'user' => $data['uploaded_by'] ?? 'system'
        ]);
        
        return (int) $this->db->lastInsertId();
    }

    public function getByGuaranteeId(int $guaranteeId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? ORDER BY created_at DESC');
        $stmt->execute([$guaranteeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool
    {
        // First get the file path to delete from disk (should be done in service, but repo can return info)
        $stmt = $this->db->prepare('DELETE FROM guarantee_attachments WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM guarantee_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }
}
