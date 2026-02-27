<?php
declare(strict_types=1);

namespace App\Support;

class ApiPolicyMatrix
{
    /**
     * Endpoint policy matrix for privileged operations.
     *
     * @return array<string, array{auth:string,permission:?string}>
     */
    public static function all(): array
    {
        return [
            'api/alerts.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/batches.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/commit-batch-draft.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/convert-to-real.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/create-bank.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/create-guarantee.php' => ['auth' => 'permission', 'permission' => 'manual_entry'],
            'api/create-supplier.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/delete_bank.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/delete_supplier.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/export_banks.php' => ['auth' => 'login', 'permission' => null],
            'api/export_matching_overrides.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/export_suppliers.php' => ['auth' => 'login', 'permission' => null],
            'api/extend.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/get-current-state.php' => ['auth' => 'login', 'permission' => null],
            'api/get-history-snapshot.php' => ['auth' => 'login', 'permission' => null],
            'api/get-letter-preview.php' => ['auth' => 'login', 'permission' => null],
            'api/get-record.php' => ['auth' => 'login', 'permission' => null],
            'api/get-timeline.php' => ['auth' => 'login', 'permission' => null],
            'api/get_banks.php' => ['auth' => 'login', 'permission' => null],
            'api/get_suppliers.php' => ['auth' => 'login', 'permission' => null],
            'api/history.php' => ['auth' => 'login', 'permission' => null],
            'api/import-email.php' => ['auth' => 'permission', 'permission' => 'import_excel'],
            'api/import.php' => ['auth' => 'permission', 'permission' => 'import_excel'],
            'api/import_banks.php' => ['auth' => 'permission', 'permission' => 'import_excel'],
            'api/import_matching_overrides.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/import_suppliers.php' => ['auth' => 'permission', 'permission' => 'import_excel'],
            'api/learning-action.php' => ['auth' => 'login', 'permission' => null],
            'api/learning-data.php' => ['auth' => 'login', 'permission' => null],
            'api/login.php' => ['auth' => 'public', 'permission' => null],
            'api/logout.php' => ['auth' => 'public', 'permission' => null],
            'api/manual-entry.php' => ['auth' => 'permission', 'permission' => 'manual_entry'],
            'api/matching-overrides.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/me.php' => ['auth' => 'login', 'permission' => null],
            'api/merge-suppliers.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/metrics.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/notifications.php' => ['auth' => 'login', 'permission' => null],
            'api/parse-paste-v2.php' => ['auth' => 'login', 'permission' => null],
            'api/parse-paste.php' => ['auth' => 'login', 'permission' => null],
            'api/print-events.php' => ['auth' => 'login', 'permission' => null],
            'api/reduce.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/release.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/reopen.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/save-and-next.php' => ['auth' => 'login', 'permission' => null],
            'api/save-import.php' => ['auth' => 'permission', 'permission' => 'import_excel'],
            'api/save-note.php' => ['auth' => 'login', 'permission' => null],
            'api/scheduler-dead-letters.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/settings-audit.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/settings.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/smart-paste-confidence.php' => ['auth' => 'login', 'permission' => null],
            'api/suggestions-learning.php' => ['auth' => 'login', 'permission' => null],
            'api/undo-requests.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/update-guarantee.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/update_bank.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/update_supplier.php' => ['auth' => 'permission', 'permission' => 'manage_data'],
            'api/upload-attachment.php' => ['auth' => 'login', 'permission' => null],
            'api/user-preferences.php' => ['auth' => 'login', 'permission' => null],
            'api/users/create.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/users/delete.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/users/list.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/users/update.php' => ['auth' => 'permission', 'permission' => 'manage_users'],
            'api/workflow-advance.php' => ['auth' => 'login', 'permission' => null],
        ];
    }
}
