<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Guarantee Model (V3)
 * 
 * Represents a guarantee with raw data in JSON format
 */
class Guarantee
{
    public function __construct(
        public ?int $id,
        public string $guaranteeNumber,
        public array $rawData,
        public string $importSource,
        public ?string $importedAt = null,
        public ?string $importedBy = null,
    ) {}
    
    /**
     * Get supplier name from raw data
     */
    public function getSupplierName(): ?string
    {
        return $this->rawData['supplier'] ?? null;
    }
    
    /**
     * Get bank name from raw data
     */
    public function getBankName(): ?string
    {
        return $this->rawData['bank'] ?? null;
    }
    
    /**
     * Get amount from raw data
     */
    public function getAmount(): string|int|float|null
    {
        return $this->rawData['amount'] ?? null;
    }
    
    /**
     * Get expiry date from raw data
     */
    public function getExpiryDate(): ?string
    {
        return $this->rawData['expiry_date'] ?? null;
    }
    
    /**
     * Get document reference from raw data
     */
    public function getDocumentReference(): ?string
    {
        return $this->rawData['document_reference'] ?? null;
    }
    
    /**
     * Get related_to (contract or purchase_order)
     */
    public function getRelatedTo(): ?string
    {
        return $this->rawData['related_to'] ?? null;
    }
    
    /**
     * To array for API responses
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'guarantee_number' => $this->guaranteeNumber,
            'supplier' => $this->getSupplierName(),
            'bank' => $this->getBankName(),
            'amount' => $this->getAmount(),
            'expiry_date' => $this->getExpiryDate(),
            'document_reference' => $this->getDocumentReference(),
            'related_to' => $this->getRelatedTo(),
            'import_source' => $this->importSource,
            'imported_at' => $this->importedAt,
            'raw_data' => $this->rawData,
        ];
    }
}
