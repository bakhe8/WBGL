<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Guarantee;
use App\Models\GuaranteeDecision;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

/**
 * RecordHydratorService
 * 
 * Converts raw guarantee data into a complete, display-ready record
 * with resolved supplier/bank names and current state values.
 * 
 * This eliminates duplicate name resolution code across API endpoints.
 */
class RecordHydratorService
{
    private SupplierRepository $suppliers;
    private BankRepository $banks;
    private \PDO $db;
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->suppliers = new SupplierRepository();
        $this->banks = new BankRepository();
    }
    
    /**
     * Hydrate a Guarantee into a complete array with resolved names
     * 
     * @param Guarantee $guarantee The guarantee to hydrate
     * @param GuaranteeDecision|null $decision Optional decision with supplier/bank IDs
     * @return array Complete record ready for display
     */
    public function hydrate(Guarantee $guarantee, ?GuaranteeDecision $decision = null): array
    {
        $raw = $guarantee->rawData;
        
        // Build base record from raw data (source of truth)
        $record = [
            'id' => $guarantee->id,
            'guarantee_number' => $guarantee->guaranteeNumber,
            'amount' => $raw['amount'] ?? 0,
            'expiry_date' => $raw['expiry_date'] ?? '',
            'issue_date' => $raw['issue_date'] ?? '',
            'contract_number' => $raw['contract_number'] ?? '',
            'type' => $raw['type'] ?? null,
            'status' => 'pending'
        ];
        
        // Resolve Supplier Name
        if ($decision?->supplierId) {
            $supplier = $this->suppliers->find($decision->supplierId);
            $record['supplier_id'] = $supplier->id;
            $record['supplier_name'] = $supplier->officialName;
        } else {
            $record['supplier_id'] = null;
            $record['supplier_name'] = $raw['supplier'] ?? '';
        }
        
        // Resolve Bank Name
        if ($decision?->bankId) {
            $bank = $this->banks->find($decision->bankId);
            $record['bank_id'] = $bank->id;
            $record['bank_name'] = $bank->officialName;
        } else {
            $record['bank_id'] = null;
            $record['bank_name'] = $raw['bank'] ?? '';
        }
        
        // Update status if decision exists
        if ($decision) {
            $record['status'] = $decision->status;
        }
        
        return $record;
    }
    
    /**
     * Resolve supplier name using ID or fall back to raw data
     * 
     * @param int|null $supplierId
     * @param array $rawData
     * @return string Resolved supplier name
     */
    public function resolveSupplierName(?int $supplierId, array $rawData): string
    {
        if ($supplierId) {
            $stmt = $this->db->prepare("SELECT official_name FROM suppliers WHERE id = ?");
            $stmt->execute([$supplierId]);
            $name = $stmt->fetchColumn();
            return $name ?: ($rawData['supplier'] ?? '');
        }
        
        return $rawData['supplier'] ?? '';
    }
    
    /**
     * Resolve bank name using ID or fall back to raw data
     * 
     * @param int|null $bankId
     * @param array $rawData
     * @return string Resolved bank name
     */
    public function resolveBankName(?int $bankId, array $rawData): string
    {
        if ($bankId) {
            $stmt = $this->db->prepare("SELECT arabic_name FROM banks WHERE id = ?");
            $stmt->execute([$bankId]);
            $name = $stmt->fetchColumn();
            return $name ?: ($rawData['bank'] ?? '');
        }
        
        return $rawData['bank'] ?? '';
    }
}
