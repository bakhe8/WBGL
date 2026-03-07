<?php
require_once __DIR__ . '/_bootstrap.php';
use App\Support\Database;
use App\Support\AuthService;

wbgl_api_require_login();

try {
    $db = Database::connect();
    $currentUser = AuthService::getCurrentUser();
    $requestLocale = strtolower(trim((string)($_GET['lang'] ?? '')));
    $requestLocale = in_array(substr($requestLocale, 0, 2), ['ar', 'en'], true) ? substr($requestLocale, 0, 2) : '';
    $locale = $requestLocale !== '' ? $requestLocale : strtolower((string)($currentUser?->preferredLanguage ?? 'ar'));
    $isEn = $locale === 'en';
    $t = static function (string $key) use ($isEn): string {
        $ar = [
            'pagination_prev' => 'السابق',
            'pagination_next' => 'التالي',
            'pagination_info' => 'صفحة %d من %d (إجمالي %d)',
            'empty' => 'لا يوجد موردين.',
            'error_prefix' => 'خطأ: ',
            'id' => 'المعرف',
            'official_name' => 'الاسم الرسمي',
            'english_name' => 'الاسم الإنجليزي',
            'status' => 'الحالة',
            'actions' => 'الإجراءات',
            'confirmed' => 'مؤكد',
            'unconfirmed' => 'غير مؤكد',
            'update' => '✏️ تحديث',
            'merge' => '🔗 دمج',
            'delete' => '🗑️ حذف',
            'select' => 'تحديد',
            'select_all' => 'تحديد الكل',
            'selected_count' => 'المحدد: %d',
            'delete_selected' => '🗑️ حذف المحدد',
        ];
        $en = [
            'pagination_prev' => 'Previous',
            'pagination_next' => 'Next',
            'pagination_info' => 'Page %d of %d (Total %d)',
            'empty' => 'No suppliers found.',
            'error_prefix' => 'Error: ',
            'id' => 'ID',
            'official_name' => 'Official Name',
            'english_name' => 'English Name',
            'status' => 'Status',
            'actions' => 'Actions',
            'confirmed' => 'Confirmed',
            'unconfirmed' => 'Unconfirmed',
            'update' => '✏️ Update',
            'merge' => '🔗 Merge',
            'delete' => '🗑️ Delete',
            'select' => 'Select',
            'select_all' => 'Select All',
            'selected_count' => 'Selected: %d',
            'delete_selected' => '🗑️ Delete Selected',
        ];
        $dict = $isEn ? $en : $ar;
        return $dict[$key] ?? $key;
    };
    
    // Pagination Logic
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $limit = 100;
    $offset = ($page - 1) * $limit;
    
    // Count total
    $countStmt = $db->query('SELECT COUNT(*) FROM suppliers');
    $totalRows = $countStmt->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    // Fetch Data
    $stmt = $db->prepare('SELECT * FROM suppliers ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    $suppliers = $stmt->fetchAll();
    
    // Pagination Controls Function
    function renderPagination($page, $totalPages, $totalRows, $jsFunction, $t) {
        if ($totalPages <= 1) return '';
        $html = '<div class="pagination">';
        
        if ($page > 1) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page - 1) . ')">' . htmlspecialchars($t('pagination_prev')) . '</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled>' . htmlspecialchars($t('pagination_prev')) . '</button>';
        }
        
        $html .= '<span class="pagination-info">' .
            sprintf(htmlspecialchars($t('pagination_info')), (int)$page, (int)$totalPages, (int)$totalRows) .
            '</span>';
        
        if ($page < $totalPages) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page + 1) . ')">' . htmlspecialchars($t('pagination_next')) . '</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled>' . htmlspecialchars($t('pagination_next')) . '</button>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    if (empty($suppliers)) {
        echo '<div id="suppliersTableContainer"><div class="alert">' . htmlspecialchars($t('empty')) . '</div></div>';
    } else {
        echo '<div id="suppliersTableContainer">';
        // Top Pagination
        echo renderPagination($page, $totalPages, (int)$totalRows, 'loadSuppliers', $t);

        echo '<div class="bulk-selection-toolbar" data-bulk-entity="suppliers">';
        echo '  <label class="bulk-selection-label">';
        echo '      <input type="checkbox" data-bulk-select-all="suppliers" onchange="toggleSelectAll(\'suppliers\', this)">';
        echo '      <span>' . htmlspecialchars($t('select_all')) . '</span>';
        echo '  </label>';
        echo '  <span class="bulk-selection-count" data-bulk-count="suppliers">'
            . sprintf(htmlspecialchars($t('selected_count')), 0)
            . '</span>';
        echo '  <button class="btn btn-sm btn-danger" data-bulk-delete-btn="suppliers"'
            . ' data-authorize-resource="supplier" data-authorize-action="manage" data-authorize-mode="disable"'
            . ' onclick="deleteSelectedSuppliers()" disabled>'
            . htmlspecialchars($t('delete_selected'))
            . '</button>';
        echo '</div>';
        
        echo '<table class="data-table">
            <thead>
                <tr>
                    <th class="bulk-select-cell">' . htmlspecialchars($t('select')) . '</th>
                    <th>' . htmlspecialchars($t('id')) . '</th>
                    <th>' . htmlspecialchars($t('official_name')) . '</th>
                    <th>' . htmlspecialchars($t('english_name')) . '</th>
                    <th>' . htmlspecialchars($t('status')) . '</th>
                    <th>' . htmlspecialchars($t('actions')) . '</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($suppliers as $s) {
            $supplierId = (int)($s['id'] ?? 0);
            $selectedConfirmed = $s['is_confirmed'] ? 'selected' : '';
            $selectedUnconfirmed = !$s['is_confirmed'] ? 'selected' : '';
            
            echo '<tr data-id="' . $supplierId . '">
                <td class="bulk-select-cell"><input type="checkbox" class="bulk-row-checkbox" data-bulk-entity="suppliers" value="' . $supplierId . '" onchange="updateBulkSelectionUI(\'suppliers\')"></td>
                <td>' . htmlspecialchars((string)$supplierId) . '</td>
                <td><input type="text" class="row-input" name="official_name" value="' . htmlspecialchars($s['official_name']) . '"></td>
                <td><input type="text" class="row-input" name="english_name" value="' . htmlspecialchars($s['english_name'] ?? '') . '"></td>
                <td>
                    <select class="row-input" name="is_confirmed">
                        <option value="1" ' . $selectedConfirmed . '>' . htmlspecialchars($t('confirmed')) . '</option>
                        <option value="0" ' . $selectedUnconfirmed . '>' . htmlspecialchars($t('unconfirmed')) . '</option>
                    </select>
                </td>
                <td>
                    <button class="btn btn-sm table-action-btn table-action-btn-spaced"
                        data-authorize-resource="supplier"
                        data-authorize-action="manage"
                        data-authorize-mode="disable"
                        onclick="updateSupplier(' . $supplierId . ', this)">' . htmlspecialchars($t('update')) . '</button>
                    <button class="btn btn-sm btn-merge table-action-btn table-action-btn-spaced"
                        data-authorize-resource="supplier"
                        data-authorize-action="manage"
                        data-authorize-mode="disable"
                        onclick="openMergeModal(' . $supplierId . ', \'' . addslashes($s['official_name']) . '\')">' . htmlspecialchars($t('merge')) . '</button>
                    <button class="btn btn-sm btn-danger table-action-btn"
                        data-authorize-resource="supplier"
                        data-authorize-action="manage"
                        data-authorize-mode="disable"
                        onclick="deleteSupplier(' . $supplierId . ')">' . htmlspecialchars($t('delete')) . '</button>
                </td>
            </tr>';
        }
        
        echo '</tbody></table>';
        
        // Bottom Pagination
        echo renderPagination($page, $totalPages, (int)$totalRows, 'loadSuppliers', $t);
        echo '</div>';
    }
} catch (Exception $e) {
    $prefix = isset($t) && is_callable($t) ? $t('error_prefix') : 'خطأ: ';
    echo '<div class="alert alert-error">' . htmlspecialchars($prefix) . htmlspecialchars($e->getMessage()) . '</div>';
}
