<?php

/**
 * User Management Dashboard (v2.0)
 * Locked to users with 'manage_users' permission
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Guard;
use App\Support\Database;

// Security Check
if (!AuthService::isLoggedIn() || !Guard::has('manage_users')) {
    header('Location: /views/login.php');
    exit;
}

$currentUser = AuthService::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - WBGL</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/index-main.css">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
        }

        .users-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0;
        }

        .header-title p {
            color: var(--text-light);
            margin-top: 5px;
        }

        .users-card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: right;
        }

        th {
            background: #f8fafc;
            padding: 16px;
            font-weight: 700;
            color: var(--text-light);
            font-size: 14px;
            border-bottom: 1px solid var(--border-primary);
        }

        td {
            padding: 16px;
            border-bottom: 1px solid var(--border-primary);
            font-size: 15px;
            vertical-align: middle;
        }

        tr:hover {
            background: #fdfdfd;
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .role-developer {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-signatory {
            background: #dcfce7;
            color: #166534;
        }

        .role-analyst {
            background: #e0e7ff;
            color: #3730a3;
        }

        .role-default {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-add {
            background: var(--color-primary);
            color: white;
        }

        .btn-add:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-edit {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-edit:hover {
            background: #e2e8f0;
        }

        .btn-delete {
            background: #fff1f2;
            color: #e11d48;
        }

        .btn-delete:hover {
            background: #ffe4e6;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 500px;
            padding: 32px;
            border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            font-family: inherit;
            font-size: 15px;
            outline: none;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }

        .modal-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: inherit;
        }

        .btn-save {
            background: var(--color-primary);
            color: white;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #475569;
        }

        .back-link {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: var(--color-primary);
            font-weight: 600;
            margin-bottom: 20px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            font-weight: 800;
        }
    </style>
</head>

<body>

    <div id="loadingOverlay" class="loading-overlay">... جاري التنفيذ</div>

    <!-- User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content text-right">
            <div class="modal-header">
                <h2 id="modalTitle">إضافة مستخدم جديد</h2>
            </div>
            <form id="userForm">
                <input type="hidden" id="userIdField">
                <div class="form-group">
                    <label>الاسم الكامل</label>
                    <input type="text" id="fullNameField" class="form-control" placeholder="مثل: أحمد محمد" required>
                </div>
                <div class="form-group">
                    <label>اسم المستخدم</label>
                    <input type="text" id="usernameField" class="form-control" placeholder="username" required>
                </div>
                <div class="form-group">
                    <label>البريد الإلكتروني (اختياري)</label>
                    <input type="email" id="emailField" class="form-control" placeholder="user@example.com">
                </div>
                <div class="form-group">
                    <label>الدور الوظيفي</label>
                    <select id="roleField" class="form-control" required>
                        <!-- Loaded via JS -->
                    </select>
                </div>
                <div class="form-group">
                    <label id="passwordLabel">كلمة المرور</label>
                    <input type="password" id="passwordField" class="form-control" placeholder="اتركها فارغة لعدم التغيير في حال التعديل">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-save">حفظ</button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <div class="users-container">
        <a href="../index.php" class="back-link">
            <span>→</span> العودة للرئيسية
        </a>

        <div class="header-section">
            <div class="header-title">
                <h1>إدارة المستخدمين</h1>
                <p>إدارة الحسابات، كلمات المرور، والصلاحيات للموظفين</p>
            </div>
            <button class="btn-action btn-add" onclick="openAddModal()">
                <span>+</span> إضافة مستخدم جديد
            </button>
        </div>

        <div class="users-card">
            <table>
                <thead>
                    <tr>
                        <th>الاسم الكامل</th>
                        <th>اسم المستخدم</th>
                        <th>الدور</th>
                        <th>آخر دخول</th>
                        <th style="width: 180px;">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <!-- Loaded via JS -->
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let rolesData = [];
        let allUsers = [];

        async function loadUsers() {
            try {
                const response = await fetch('../api/users/list.php');
                const data = await response.json();

                if (!data.success) throw new Error(data.error);

                rolesData = data.roles;
                allUsers = data.users;

                // Populate roles select
                const roleSelect = document.getElementById('roleField');
                roleSelect.innerHTML = rolesData.map(r => `<option value="${r.id}">${r.name}</option>`).join('');

                renderUsers(data.users);
            } catch (err) {
                console.error('Fetch error:', err);
                alert('فشل تحميل قائمة المستخدمين');
            }
        }

        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');
            tbody.innerHTML = users.map(user => `
                <tr data-user-id="${user.id}">
                    <td><strong>${user.full_name}</strong><br><small style="color:#888">${user.email || ''}</small></td>
                    <td><code>${user.username}</code></td>
                    <td>
                        <span class="role-badge role-${user.role_slug || 'default'}">
                            ${user.role_name || 'بدون دور'}
                        </span>
                    </td>
                    <td style="color:#666">${user.last_login || 'لم يدخل بعد'}</td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn-action btn-edit" onclick="openEditModal(${user.id})">✏️</button>
                            <button class="btn-action btn-delete" onclick="deleteUser(${user.id})">🗑️</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'إضافة مستخدم جديد';
            document.getElementById('userIdField').value = '';
            document.getElementById('userForm').reset();
            document.getElementById('passwordField').required = true;
            document.getElementById('passwordLabel').innerText = 'كلمة المرور';
            document.getElementById('userModal').style.display = 'flex';
        }

        function openEditModal(userId) {
            const user = allUsers.find(u => u.id == userId);
            if (!user) return;

            document.getElementById('modalTitle').innerText = 'تعديل بيانات المستخدم';
            document.getElementById('userIdField').value = user.id;
            document.getElementById('fullNameField').value = user.full_name;
            document.getElementById('usernameField').value = user.username;
            document.getElementById('emailField').value = user.email || '';
            document.getElementById('roleField').value = user.role_id;
            document.getElementById('passwordField').required = false;
            document.getElementById('passwordField').value = '';
            document.getElementById('passwordLabel').innerText = 'كلمة المرور (اتركها فارغة لعدم التغيير)';

            document.getElementById('userModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        document.getElementById('userForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const userId = document.getElementById('userIdField').value;
            const isEdit = userId !== '';

            const payload = {
                user_id: userId,
                full_name: document.getElementById('fullNameField').value,
                username: document.getElementById('usernameField').value,
                email: document.getElementById('emailField').value,
                role_id: document.getElementById('roleField').value,
                password: document.getElementById('passwordField').value
            };

            const url = isEdit ? '../api/users/update.php' : '../api/users/create.php';

            showLoading(true);
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    loadUsers();
                } else {
                    alert('خطأ: ' + data.error);
                }
            } catch (err) {
                alert('حدث خطأ في الشبكة');
            } finally {
                showLoading(false);
            }
        });

        async function deleteUser(userId) {
            if (!confirm('هل أنت متأكد من حذف هذا المستخدم نهائياً؟')) return;

            showLoading(true);
            try {
                const response = await fetch('../api/users/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: userId
                    })
                });
                const data = await response.json();

                if (data.success) {
                    loadUsers();
                } else {
                    alert('خطأ: ' + data.error);
                }
            } catch (err) {
                alert('حدث خطأ في الشبكة');
            } finally {
                showLoading(false);
            }
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        document.addEventListener('DOMContentLoaded', loadUsers);
    </script>
</body>

</html>
