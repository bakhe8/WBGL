<?php
declare(strict_types=1);

namespace App\Models;

class ImportedRecord
{
    public function __construct(
        public ?int $id,
        public int $sessionId,
        public string $rawSupplierName,
        public string $rawBankName,
        public ?string $amount = null,
        public ?string $guaranteeNumber = null,
        public ?string $contractNumber = null,
        public ?string $relatedTo = null,  // Document type: 'contract' or 'purchase_order'
        public ?string $issueDate = null,
        public ?string $expiryDate = null,
        public ?string $type = null,
        public ?string $comment = null,
        public ?string $normalizedSupplier = null,
        public ?string $normalizedBank = null,
        public ?string $matchStatus = null,
        public ?int $supplierId = null,
        public ?int $bankId = null,
        public ?string $bankDisplay = null,
        public ?string $supplierDisplayName = null,
        public ?string $createdAt = null,
        // Record type determines letter content: 'import'=extension, 'release_action'=release, 'modification'=amendment
        public ?string $recordType = 'import',  // Record type: 'import', 'modification', 'release_action'
        public ?int $importBatchId = null,  // Batch ID for import grouping
    ) {
    }
}
