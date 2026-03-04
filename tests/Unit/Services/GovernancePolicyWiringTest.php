<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernancePolicyWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testBatchReopenGovernanceWiringIsPresent(): void
    {
        $batchApi = $this->readFile('api/batches.php');
        $batchService = $this->readFile('app/Services/BatchService.php');

        $this->assertStringContainsString('wbgl_api_require_login();', $batchApi);
        $this->assertStringContainsString("if (\$action !== 'reopen')", $batchApi);
        $this->assertStringContainsString("wbgl_api_require_permission('manage_data');", $batchApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $batchApi);
        $this->assertStringContainsString("Guard::has('reopen_batch')", $batchApi);
        $this->assertStringContainsString('سبب إعادة فتح الدفعة مطلوب', $batchApi);
        $this->assertStringContainsString('BreakGlassService::authorizeAndRecord', $batchApi);

        $this->assertStringContainsString('BatchAuditService::record(', $batchService);
        $this->assertStringContainsString('batch_reopened', $batchService);
    }

    public function testGuaranteeReopenGovernanceWiringIsPresent(): void
    {
        $reopenApi = $this->readFile('api/reopen.php');

        $this->assertStringContainsString('wbgl_api_require_login();', $reopenApi);
        $this->assertStringNotContainsString("wbgl_api_require_permission('manage_data')", $reopenApi);
        $this->assertStringContainsString("Guard::has('reopen_guarantee')", $reopenApi);
        $this->assertStringContainsString('BreakGlassService::authorizeAndRecord', $reopenApi);
        $this->assertStringNotContainsString('ENFORCE_UNDO_REQUEST_WORKFLOW', $reopenApi);
        $this->assertStringContainsString('UndoRequestService::submit', $reopenApi);
    }

    public function testReleasedReadOnlyPolicyWiringIsPresent(): void
    {
        $extendApi = $this->readFile('api/extend.php');
        $reduceApi = $this->readFile('api/reduce.php');
        $releaseApi = $this->readFile('api/release.php');
        $updateGuaranteeApi = $this->readFile('api/update-guarantee.php');
        $saveAndNextApi = $this->readFile('api/save-and-next.php');
        $saveAndNextService = $this->readFile('app/Services/SaveAndNextApplicationService.php');
        $uploadAttachmentApi = $this->readFile('api/upload-attachment.php');

        $this->assertStringContainsString("wbgl_api_require_permission('guarantee_extend');", $extendApi);
        $this->assertStringContainsString("wbgl_api_require_permission('guarantee_reduce');", $reduceApi);
        $this->assertStringContainsString("wbgl_api_require_permission('guarantee_release');", $releaseApi);
        $this->assertStringContainsString("wbgl_api_require_permission('guarantee_save');", $updateGuaranteeApi);
        $this->assertStringContainsString("wbgl_api_require_permission('guarantee_save');", $saveAndNextApi);

        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $extendApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $reduceApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $updateGuaranteeApi);
        $this->assertTrue(
            str_contains($saveAndNextApi, 'GuaranteeMutationPolicyService::evaluate')
                || str_contains($saveAndNextService, 'GuaranteeMutationPolicyService::evaluate'),
            'save-and-next policy evaluation wiring is missing'
        );
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $uploadAttachmentApi);
    }

    public function testObjectLevelVisibilityWiringIsPresentForLoginOnlyMutations(): void
    {
        $saveAndNextApi = $this->readFile('api/save-and-next.php');
        $saveNoteApi = $this->readFile('api/save-note.php');
        $uploadAttachmentApi = $this->readFile('api/upload-attachment.php');
        $workflowAdvanceApi = $this->readFile('api/workflow-advance.php');
        $extendApi = $this->readFile('api/extend.php');
        $reduceApi = $this->readFile('api/reduce.php');
        $releaseApi = $this->readFile('api/release.php');
        $updateGuaranteeApi = $this->readFile('api/update-guarantee.php');
        $reopenApi = $this->readFile('api/reopen.php');
        $convertToRealApi = $this->readFile('api/convert-to-real.php');
        $getRecordApi = $this->readFile('api/get-record.php');
        $undoRequestsApi = $this->readFile('api/undo-requests.php');
        $commitBatchDraftApi = $this->readFile('api/commit-batch-draft.php');

        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $saveAndNextApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $saveNoteApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $uploadAttachmentApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $workflowAdvanceApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $extendApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $reduceApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $releaseApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $updateGuaranteeApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $reopenApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $convertToRealApi);
        $this->assertTrue(
            str_contains($getRecordApi, 'wbgl_api_require_guarantee_visibility')
                || str_contains($getRecordApi, 'wbgl_api_policy_for_guarantee'),
            'get-record visibility guard is missing'
        );
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $undoRequestsApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $commitBatchDraftApi);
    }

    public function testReferenceEntityPermissionsAreSplitFromManageData(): void
    {
        $createBankApi = $this->readFile('api/create-bank.php');
        $updateBankApi = $this->readFile('api/update_bank.php');
        $deleteBankApi = $this->readFile('api/delete_bank.php');

        $createSupplierApi = $this->readFile('api/create-supplier.php');
        $updateSupplierApi = $this->readFile('api/update_supplier.php');
        $deleteSupplierApi = $this->readFile('api/delete_supplier.php');
        $mergeSupplierApi = $this->readFile('api/merge-suppliers.php');

        $this->assertStringContainsString("wbgl_api_require_permission('bank_manage');", $createBankApi);
        $this->assertStringContainsString("wbgl_api_require_permission('bank_manage');", $updateBankApi);
        $this->assertStringContainsString("wbgl_api_require_permission('bank_manage');", $deleteBankApi);

        $this->assertStringContainsString("wbgl_api_require_permission('supplier_manage');", $createSupplierApi);
        $this->assertStringContainsString("wbgl_api_require_permission('supplier_manage');", $updateSupplierApi);
        $this->assertStringContainsString("wbgl_api_require_permission('supplier_manage');", $deleteSupplierApi);
        $this->assertStringContainsString("wbgl_api_require_permission('supplier_manage');", $mergeSupplierApi);
    }

    public function testBatchServiceEnforcesObjectVisibilityBeforeMutations(): void
    {
        $batchService = $this->readFile('app/Services/BatchService.php');
        $this->assertStringContainsString('GuaranteeVisibilityService::canAccessGuarantee', $batchService);
        $this->assertStringContainsString("Guard::has('manage_data')", $batchService);
    }

    public function testImportAndParsePathsEnforceVisibilityOnExistingGuarantees(): void
    {
        $saveImportApi = $this->readFile('api/save-import.php');
        $parsePasteV2Api = $this->readFile('api/parse-paste-v2.php');
        $parseCoordinator = $this->readFile('app/Services/ParseCoordinatorService.php');

        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $saveImportApi);
        $this->assertStringContainsString('wbgl_api_require_guarantee_visibility', $parsePasteV2Api);
        $this->assertStringContainsString('GuaranteeVisibilityService::canAccessGuarantee', $parseCoordinator);
        $this->assertStringContainsString('Permission Denied', $parseCoordinator);
    }

    public function testIndexForcedIdUsesScopeFirstBeforeFullRecordLoad(): void
    {
        $indexPage = $this->readFile('index.php');

        $this->assertStringContainsString(
            '$idMatchesScope = \\App\\Services\\NavigationService::isIdInFilter(',
            $indexPage
        );
        $this->assertStringContainsString(
            'if ($idMatchesScope) {',
            $indexPage
        );
        $this->assertStringContainsString(
            '$currentRecord = $guaranteeRepo->find($requestedId);',
            $indexPage
        );
    }

    public function testIndexDefaultSelectionUsesNavigationScopedSourceOfTruth(): void
    {
        $indexPage = $this->readFile('index.php');

        $this->assertStringContainsString(
            '$firstId = \\App\\Services\\NavigationService::getIdByIndex(',
            $indexPage
        );
        $this->assertStringNotContainsString(
            "SELECT g.id FROM guarantees g\n        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id",
            $indexPage,
            'index default record query must not bypass NavigationService scoped predicate'
        );
    }

    public function testIndexClearsStaleRecordWhenScopedCountIsZero(): void
    {
        $indexPage = $this->readFile('index.php');

        $this->assertStringContainsString('if ($totalRecords === 0) {', $indexPage);
        $this->assertStringContainsString('$currentRecord = null;', $indexPage);
        $this->assertStringContainsString(
            '// Anti-leak: never keep stale record payload when current scoped list is empty.',
            $indexPage
        );
    }

    public function testRecordAndTimelineApisUseUnifiedPolicyAndStageFilter(): void
    {
        $recordApi = $this->readFile('api/get-record.php');
        $timelineApi = $this->readFile('api/get-timeline.php');
        $bootstrap = $this->readFile('api/_bootstrap.php');

        $this->assertStringContainsString('wbgl_api_policy_for_guarantee', $bootstrap);

        $this->assertStringContainsString('$stageFilter = isset($_GET[\'stage\'])', $recordApi);
        $this->assertStringContainsString('$stageFilter = isset($_GET[\'stage\'])', $timelineApi);

        $this->assertStringContainsString('wbgl_api_policy_for_guarantee($db, (int)$guaranteeId)', $recordApi);
        $this->assertStringContainsString('wbgl_api_policy_for_guarantee($db, (int)$guaranteeId)', $timelineApi);

        $this->assertStringContainsString('$stageFilter', $recordApi);
        $this->assertStringContainsString('$stageFilter', $timelineApi);
    }

    public function testStatsServiceUsesNavigationSinglePredicateCounts(): void
    {
        $statsService = $this->readFile('app/Services/StatsService.php');
        $navigationService = $this->readFile('app/Services/NavigationService.php');

        $this->assertStringContainsString(
            "NavigationService::countByFilter(\$db, 'all')",
            $statsService
        );
        $this->assertStringContainsString(
            "NavigationService::countByFilter(\$db, 'ready')",
            $statsService
        );
        $this->assertStringContainsString(
            "NavigationService::countByFilter(\$db, 'actionable')",
            $statsService
        );
        $this->assertStringContainsString(
            "NavigationService::countByFilter(\$db, 'pending')",
            $statsService
        );
        $this->assertStringContainsString(
            "NavigationService::countByFilter(\$db, 'released')",
            $statsService
        );

        $this->assertStringContainsString('public static function countByFilter', $navigationService);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
