<?php

/**
 * User Management Dashboard (v2.0)
 * Locked to users with 'manage_users' permission
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\ViewPolicy;

ViewPolicy::guardView('users.php');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - WBGL</title>
    <?php include __DIR__ . '/../partials/ui-bootstrap.php'; ?>
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
            background: #2563eb;
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 2px solid transparent;
        }

        .btn-add:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
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
            max-width: 800px;
            /* Wider for permissions */
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

        /* 🛡️ Permission Toggle Styles */
        .perm-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .perm-info b {
            display: block;
            font-size: 14px;
        }

        .perm-info small {
            color: #64748b;
            font-size: 12px;
        }

        .toggle-group {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 8px;
            gap: 4px;
        }

        .toggle-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            background: transparent;
            color: #64748b;
            transition: all 0.2s;
        }

        .toggle-btn.active[data-type="auto"] {
            background: white;
            color: #475569;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .toggle-btn.active[data-type="allow"] {
            background: #dcfce7;
            color: #166534;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .toggle-btn.active[data-type="deny"] {
            background: #fee2e2;
            color: #991b1b;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body data-i18n-namespaces="common,users">

    <div id="loadingOverlay" class="loading-overlay">... جاري التنفيذ</div>

    <!-- Unified User & Permissions Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content text-right">
            <div class="modal-header">
                <h2 id="modalTitle">إضافة مستخدم جديد</h2>
            </div>
            <form id="userForm">
                <input type="hidden" id="userIdField">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                    <!-- Basic Info -->
                    <div>
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
                            <label>لغة الواجهة</label>
                            <select id="preferredLanguageField" class="form-control">
                                <option value="ar">العربية (RTL)</option>
                                <option value="en">English (LTR)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>المظهر</label>
                            <select id="preferredThemeField" class="form-control">
                                <option value="system">System</option>
                                <option value="light">Light</option>
                                <option value="dark">Dark</option>
                                <option value="desert">Desert</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>اتجاه الواجهة</label>
                            <select id="preferredDirectionField" class="form-control">
                                <option value="auto">Auto</option>
                                <option value="rtl">RTL</option>
                                <option value="ltr">LTR</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label id="passwordLabel">كلمة المرور</label>
                            <input type="password" id="passwordField" class="form-control" placeholder="كلمة المرور">
                        </div>
                    </div>

                    <!-- Permissions Overrides -->
                    <div style="border-right: 1px solid #f1f5f9; padding-right: 30px;">
                        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 15px; color: #1e293b;">تخصيص الصلاحيات (تحكم متقدم)</h3>
                        <div id="permissionsList" style="max-height: 400px; overflow-y: auto; border: 1px solid #f1f5f9; border-radius: 12px; background: #fafafa;">
                            <!-- Loaded via JS -->
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn-save">حفظ البيانات والصلاحيات</button>
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
                        <th>اللغة</th>
                        <th>المظهر</th>
                        <th>الاتجاه</th>
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

    <script src="../public/js/security.js?v=<?= time() ?>"></script>
    <script src="../public/js/i18n.js?v=<?= time() ?>"></script>
    <script src="../public/js/direction.js?v=<?= time() ?>"></script>
    <script src="../public/js/theme.js?v=<?= time() ?>"></script>
    <script src="../public/js/policy.js?v=<?= time() ?>"></script>
    <script src="../public/js/nav-manifest.js?v=<?= time() ?>"></script>
    <script src="../public/js/ui-runtime.js?v=<?= time() ?>"></script>
    <script src="../public/js/global-shortcuts.js?v=<?= time() ?>"></script>
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
                    <td>${(user.preferred_language || 'ar').toUpperCase()}</td>
                    <td>${(user.preferred_theme || 'system').toUpperCase()}</td>
                    <td>${(user.preferred_direction || 'auto').toUpperCase()}</td>
                    <td style="color:#666">${user.last_login || 'لم يدخل بعد'}</td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn-action btn-edit" title="تعديل البيانات والصلاحيات" onclick="openEditModal(${user.id})">✏️ إدارة</button>
                            <button class="btn-action btn-delete" title="حذف المستخدم" onclick="deleteUser(${user.id})">🗑️</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'إضافة مستخدم جديد';
            document.getElementById('userIdField').value = '';
            document.getElementById('userForm').reset();
            document.getElementById('preferredLanguageField').value = 'ar';
            document.getElementById('preferredThemeField').value = 'system';
            document.getElementById('preferredDirectionField').value = 'auto';
            document.getElementById('passwordField').required = true;
            document.getElementById('passwordLabel').innerText = 'كلمة المرور';
            renderPermissionsList([]); // Empty overrides
            document.getElementById('userModal').style.display = 'flex';
        }

        function openEditModal(userId) {
            const user = allUsers.find(u => u.id == userId);
            if (!user) return;

            document.getElementById('modalTitle').innerText = 'تعديل بيانات المستخدم وصلاحياته';
            document.getElementById('userIdField').value = user.id;
            document.getElementById('fullNameField').value = user.full_name;
            document.getElementById('usernameField').value = user.username;
            document.getElementById('emailField').value = user.email || '';
            document.getElementById('roleField').value = user.role_id;
            document.getElementById('preferredLanguageField').value = user.preferred_language || 'ar';
            document.getElementById('preferredThemeField').value = user.preferred_theme || 'system';
            document.getElementById('preferredDirectionField').value = user.preferred_direction || 'auto';
            document.getElementById('passwordField').required = false;
            document.getElementById('passwordField').value = '';
            document.getElementById('passwordLabel').innerText = 'كلمة المرور (اتركها فارغة لعدم التغيير)';

            const userOverrides = allOverrides[userId] || [];
            renderPermissionsList(userOverrides);

            document.getElementById('userModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        document.getElementById('userForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const userId = document.getElementById('userIdField').value;
            const isEdit = userId !== '';

            // Collect overrides
            const overrides = [];
            document.querySelectorAll('.perm-row').forEach(row => {
                const permId = row.dataset.permId;
                const type = row.querySelector('.toggle-btn.active').dataset.type;
                if (type !== 'auto') {
                    overrides.push({
                        permission_id: permId,
                        type: type
                    });
                }
            });

            const payload = {
                user_id: userId,
                full_name: document.getElementById('fullNameField').value,
                username: document.getElementById('usernameField').value,
                email: document.getElementById('emailField').value,
                role_id: document.getElementById('roleField').value,
                preferred_language: document.getElementById('preferredLanguageField').value,
                preferred_theme: document.getElementById('preferredThemeField').value,
                preferred_direction: document.getElementById('preferredDirectionField').value,
                password: document.getElementById('passwordField').value,
                permissions_overrides: overrides
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

        // 🛡️ GRANULAR PERMISSIONS LOGIC
        let allPermissions = [];
        let allOverrides = {};

        async function loadContext() {
            try {
                const response = await fetch('../api/users/list.php');
                const data = await response.json();

                if (!data.success) throw new Error(data.error);

                rolesData = data.roles;
                allUsers = data.users;
                allPermissions = data.permissions;
                allOverrides = data.overrides;
            } catch (err) {
                console.error('Fetch context error:', err);
                alert('فشل تحميل بيانات الصلاحيات والأدوار');
            }
        }

        function renderPermissionsList(userOverrides) {
            const listEl = document.getElementById('permissionsList');

            listEl.innerHTML = allPermissions.map(p => {
                const override = userOverrides.find(o => o.permission_id == p.id);
                const type = override ? override.type : 'auto';

                return `
                    <div class="perm-row" data-perm-id="${p.id}">
                        <div class="perm-info">
                            <b>${p.name}</b>
                            <small>${p.slug}</small>
                        </div>
                        <div class="toggle-group">
                            <button type="button" class="toggle-btn ${type=='auto'?'active':''}" data-type="auto" onclick="setOverride(${p.id}, 'auto')">تلقائي</button>
                            <button type="button" class="toggle-btn ${type=='allow'?'active':''}" data-type="allow" onclick="setOverride(${p.id}, 'allow')">سماح</button>
                            <button type="button" class="toggle-btn ${type=='deny'?'active':''}" data-type="deny" onclick="setOverride(${p.id}, 'deny')">منع</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function setOverride(permId, type) {
            const row = document.querySelector(`.perm-row[data-perm-id="${permId}"]`);
            row.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
            row.querySelector(`.toggle-btn[data-type="${type}"]`).classList.add('active');
        }

        async function init() {
            showLoading(true);
            await loadContext();
            loadUsers();
            showLoading(false);
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>

</html>
