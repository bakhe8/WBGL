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
            'empty' => 'لا توجد بنوك مضافة.',
            'error_prefix' => 'خطأ: ',
            'id' => 'المعرف',
            'arabic_name' => 'الاسم العربي',
            'english_name' => 'الاسم الإنجليزي',
            'short_name' => 'الاسم المختصر',
            'department' => 'إدارة الضمانات',
            'address' => 'صندوق البريد',
            'email' => 'البريد الإلكتروني',
            'actions' => 'الإجراءات',
            'update' => '✏️ تحديث',
            'delete' => '🗑️ حذف',
        ];
        $en = [
            'pagination_prev' => 'Previous',
            'pagination_next' => 'Next',
            'pagination_info' => 'Page %d of %d (Total %d)',
            'empty' => 'No banks found.',
            'error_prefix' => 'Error: ',
            'id' => 'ID',
            'arabic_name' => 'Arabic Name',
            'english_name' => 'English Name',
            'short_name' => 'Short Name',
            'department' => 'Department',
            'address' => 'Mailbox',
            'email' => 'Email',
            'actions' => 'Actions',
            'update' => '✏️ Update',
            'delete' => '🗑️ Delete',
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
    $countStmt = $db->query('SELECT COUNT(*) FROM banks');
    $totalRows = $countStmt->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    // Fetch Data
    $stmt = $db->prepare('SELECT * FROM banks ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    $banks = $stmt->fetchAll();
    
    // Pagination Controls Function
    function renderPagination($page, $totalPages, $totalRows, $jsFunction, $t) {
        if ($totalPages <= 1) return '';
        $html = '<div class="pagination">';
        
        // Previous
        if ($page > 1) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page - 1) . ')">' . htmlspecialchars($t('pagination_prev')) . '</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled>' . htmlspecialchars($t('pagination_prev')) . '</button>';
        }
        
        // Page Info
        $html .= '<span class="pagination-info">' .
            sprintf(htmlspecialchars($t('pagination_info')), (int)$page, (int)$totalPages, (int)$totalRows) .
            '</span>';
        
        // Next
        if ($page < $totalPages) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page + 1) . ')">' . htmlspecialchars($t('pagination_next')) . '</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled>' . htmlspecialchars($t('pagination_next')) . '</button>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    if (empty($banks)) {
        echo '<div id="banksTableContainer"><div class="alert">' . htmlspecialchars($t('empty')) . '</div></div>';
    } else {
        echo '<div id="banksTableContainer">';
        // Top Pagination
        echo renderPagination($page, $totalPages, (int)$totalRows, 'loadBanks', $t);
        
        echo '<table class="data-table">
            <thead>
                <tr>
                    <th>' . htmlspecialchars($t('id')) . '</th>
                    <th>' . htmlspecialchars($t('arabic_name')) . '</th>
                    <th>' . htmlspecialchars($t('english_name')) . '</th>
                    <th>' . htmlspecialchars($t('short_name')) . '</th>
                    <th>' . htmlspecialchars($t('department')) . '</th>
                    <th>' . htmlspecialchars($t('address')) . '</th>
                    <th>' . htmlspecialchars($t('email')) . '</th>
                    <th>' . htmlspecialchars($t('actions')) . '</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($banks as $bank) {
            echo '<tr data-id="' . $bank['id'] . '">
                <td>' . $bank['id'] . '</td>
                <td><input type="text" class="row-input" name="arabic_name" value="' . htmlspecialchars($bank['arabic_name'] ?? '') . '"></td>
                <td><input type="text" class="row-input" name="english_name" value="' . htmlspecialchars($bank['english_name'] ?? '') . '"></td>
                <td><input type="text" class="row-input" name="short_name" value="' . htmlspecialchars($bank['short_name'] ?? '') . '"></td>
                <td><input type="text" class="row-input" name="department" value="' . htmlspecialchars($bank['department'] ?? '') . '"></td>
                <td><input type="text" class="row-input" name="address_line1" value="' . htmlspecialchars($bank['address_line1'] ?? '') . '"></td>
                <td><input type="text" class="row-input" name="contact_email" value="' . htmlspecialchars($bank['contact_email'] ?? '') . '"></td>
                <td>
                    <button class="btn btn-sm table-action-btn table-action-btn-spaced"
                        data-authorize-resource="bank"
                        data-authorize-action="manage"
                        data-authorize-mode="disable"
                        onclick="updateBank(' . $bank['id'] . ', this)">' . htmlspecialchars($t('update')) . '</button>
                    <button class="btn btn-sm btn-danger table-action-btn"
                        data-authorize-resource="bank"
                        data-authorize-action="manage"
                        data-authorize-mode="disable"
                        onclick="deleteBank(' . $bank['id'] . ')">' . htmlspecialchars($t('delete')) . '</button>
                </td>
            </tr>';
        }
        
        echo '</tbody></table>';
        
        // Bottom Pagination
        echo renderPagination($page, $totalPages, (int)$totalRows, 'loadBanks', $t);
        echo '</div>'; // Close container
    }
} catch (Exception $e) {
    $prefix = isset($t) && is_callable($t) ? $t('error_prefix') : 'خطأ: ';
    echo '<div class="alert alert-error">' . htmlspecialchars($prefix) . htmlspecialchars($e->getMessage()) . '</div>';
}
