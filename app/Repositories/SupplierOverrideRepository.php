<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * SupplierOverrideRepository
 * 
 * Manages manual overrides for supplier matching (table: supplier_overrides)
 */
class SupplierOverrideRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Get all overrides with normalized names
     */
    public function allNormalized(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM supplier_overrides");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Table might not exist yet
            return [];
        }
    }
}
