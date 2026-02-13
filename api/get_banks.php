<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
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
    function renderPagination($page, $totalPages, $jsFunction) {
        if ($totalPages <= 1) return '';
        $html = '<div class="pagination" style="margin: 20px 0; text-align: center; display: flex; justify-content: center; gap: 5px;">';
        
        // Previous
        if ($page > 1) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page - 1) . ')">السابق</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled style="opacity: 0.5;">السابق</button>';
        }
        
        // Page Info
        $html .= '<span style="padding: 5px 10px; line-height: 28px;">صفحة ' . $page . ' من ' . $totalPages . ' (إجمالي ' . $GLOBALS['totalRows'] . ')</span>';
        
        // Next
        if ($page < $totalPages) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page + 1) . ')">التالي</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled style="opacity: 0.5;">التالي</button>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    if (empty($banks)) {
        echo '<div id="banksTableContainer"><div class="alert">لا توجد بنوك مضافة.</div></div>';
    } else {
        echo '<div id="banksTableContainer">';
        // Top Pagination
        echo renderPagination($page, $totalPages, 'loadBanks');
        
        echo '<table class="data-table">
            <thead>
                <tr>
                    <th>المعرف</th>
                    <th>الاسم العربي</th>
                    <th>الاسم الإنجليزي</th>
                    <th>الاسم المختصر</th>
                    <th>إدارة الضمانات</th>
                    <th>صندوق البريد</th>
                    <th>البريد الإلكتروني</th>
                    <th>الإجراءات</th>
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
                    <button class="btn btn-sm" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;" onclick="updateBank(' . $bank['id'] . ', this)">✏️ تحديث</button>
                    <button class="btn btn-sm btn-danger" style="padding: 4px 8px; font-size: 12px;" onclick="deleteBank(' . $bank['id'] . ')">🗑️ حذف</button>
                </td>
            </tr>';
        }
        
        echo '</tbody></table>';
        
        // Bottom Pagination
        echo renderPagination($page, $totalPages, 'loadBanks');
        echo '</div>'; // Close container
    }
} catch (Exception $e) {
    echo '<div class="alert alert-error">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
