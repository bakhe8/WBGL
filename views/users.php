<?php

/**
 * User Management Dashboard (v2.0)
 * Locked to users with 'manage_users' permission
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\ViewPolicy;

ViewPolicy::guardView('users.php');
$settings = Settings::getInstance();
$currentUser = AuthService::getCurrentUser();
$localeInfo = LocaleResolver::resolve(
    $currentUser,
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$pageLocale = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $pageLocale,
    $currentUser?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$pageDirection = (string)($directionInfo['direction'] ?? ($pageLocale === 'ar' ? 'rtl' : 'ltr'));
?>
<!DOCTYPE html>
<html class="users-page" lang="<?= htmlspecialchars($pageLocale, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($pageDirection, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="users.ui.txt_a59aab68">إدارة المستخدمين - WBGL</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/themes.css">
    <link rel="stylesheet" href="../public/css/index-main.css">
    <style>
        html.users-page,
        body.users-page {
            height: auto;
            min-height: 100%;
        }

        body.users-page {
            display: block;
            overflow-y: auto !important;
            overflow-x: hidden;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
        }

        .users-container {
            max-width: 1200px;
            margin: 24px auto 40px;
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
            background: var(--bg-secondary);
            padding: 16px;
            font-weight: 700;
            color: var(--text-secondary);
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
            background: var(--bg-hover);
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .role-developer {
            background: var(--accent-danger-light);
            color: var(--accent-danger);
        }

        .role-signatory {
            background: var(--accent-success-light);
            color: var(--accent-success);
        }

        .role-analyst {
            background: var(--accent-primary-light);
            color: var(--accent-primary);
        }

        .role-default {
            background: var(--bg-secondary);
            color: var(--text-secondary);
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
            background: var(--accent-primary);
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            box-shadow: var(--shadow-md);
            border: 2px solid transparent;
        }

        .btn-add:hover {
            background: var(--accent-primary-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-edit {
            background: var(--bg-secondary);
            color: var(--text-secondary);
        }

        .btn-edit:hover {
            background: var(--bg-hover);
        }

        .btn-delete {
            background: var(--accent-danger-light);
            color: var(--accent-danger);
        }

        .btn-delete:hover {
            background: var(--accent-danger-light);
            filter: brightness(0.95);
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

        .modal.is-open {
            display: flex;
        }

        .modal-content {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-primary);
            width: 100%;
            max-width: 800px;
            /* Wider for permissions */
            padding: 32px;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            max-height: calc(100vh - 40px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
        }

        .modal-form {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
        }

        .modal-grid {
            display: grid;
            gap: 24px;
            min-height: 0;
            flex: 1;
        }

        .form-pane {
            min-height: 0;
            overflow: auto;
            padding-inline-end: 6px;
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
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-control option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-control::placeholder {
            color: var(--text-light);
        }

        .form-control:focus {
            border-color: var(--color-primary);
            box-shadow: var(--shadow-focus);
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
            background: var(--bg-secondary);
            color: var(--text-secondary);
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: color-mix(in srgb, var(--bg-body) 75%, transparent);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            font-weight: 800;
        }

        .loading-overlay.is-visible {
            display: flex;
        }

        /* 🛡️ Permission Toggle Styles */
        .perm-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border-light);
            gap: 16px;
        }

        .perm-info b {
            display: block;
            font-size: 14px;
        }

        .perm-info small {
            color: var(--text-muted);
            font-size: 12px;
        }

        .perm-meta {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .perm-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .perm-badge.scope-view {
            background: var(--accent-primary-light);
            color: var(--accent-primary);
            border-color: var(--accent-primary);
        }

        .perm-badge.scope-action {
            background: var(--accent-success-light);
            color: var(--accent-success);
            border-color: var(--accent-success);
        }

        .perm-badge.scope-ui {
            background: var(--accent-warning-light);
            color: var(--accent-warning);
            border-color: var(--accent-warning);
        }

        .perm-badge.domain {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-color: var(--border-primary);
        }

        .perm-surface {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .perm-group-title {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--accent-primary-light);
            color: var(--accent-primary);
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 800;
            border-bottom: 1px solid var(--border-primary);
        }

        .toggle-group {
            display: flex;
            background: var(--bg-secondary);
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
            color: var(--text-muted);
            transition: all 0.2s;
        }

        .toggle-btn.active[data-type="auto"] {
            background: var(--bg-card);
            color: var(--text-secondary);
            box-shadow: var(--shadow-sm);
        }

        .toggle-btn.active[data-type="allow"] {
            background: var(--accent-success-light);
            color: var(--accent-success);
            box-shadow: var(--shadow-sm);
        }

        .toggle-btn.active[data-type="deny"] {
            background: var(--accent-danger-light);
            color: var(--accent-danger);
            box-shadow: var(--shadow-sm);
        }

        .section-card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-secondary);
        }

        .section-title {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
        }

        .section-subtitle {
            margin: 4px 0 0;
            font-size: 12px;
            color: var(--text-muted);
        }

        .section-content {
            padding: 0;
        }

        .roles-table th,
        .roles-table td {
            padding: 12px 14px;
            font-size: 13px;
        }

        .role-chip {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--accent-primary-light);
            color: var(--accent-primary);
            border: 1px solid var(--accent-primary);
            font-size: 11px;
            font-weight: 700;
        }

        .permission-preview {
            color: var(--text-muted);
            font-size: 11px;
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .users-header-actions {
            display: flex;
            gap: 10px;
        }

        .th-actions {
            width: 180px;
        }

        .user-email-muted,
        .user-last-login {
            color: var(--text-muted);
        }

        .users-actions-inline {
            display: flex;
            gap: 8px;
        }

        .role-modal-content {
            width: min(1280px, 96vw);
            max-width: min(1280px, 96vw);
        }

        .role-permissions-grid {
            min-height: 420px;
            overflow-y: auto;
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            background: var(--bg-secondary);
        }

        .role-perm-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-primary);
        }

        .role-perm-row:last-child {
            border-bottom: none;
        }

        .role-perm-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent-primary);
            flex-shrink: 0;
        }

        .role-perm-details b {
            display: block;
            font-size: 13px;
            color: var(--text-primary);
        }

        .role-perm-details small {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
        }

        .role-tools {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .empty-note {
            padding: 14px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .user-modal-content {
            width: min(1380px, 96vw);
            max-width: min(1380px, 96vw);
        }

        .user-form-grid {
            grid-template-columns: minmax(340px, 0.95fr) minmax(560px, 1.25fr);
        }

        .role-form-grid {
            grid-template-columns: minmax(300px, 0.8fr) minmax(640px, 1.2fr);
        }

        .permissions-panel {
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 10px;
        }

        .permissions-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .permissions-panel-title {
            font-size: 16px;
            font-weight: 800;
            margin: 0;
            color: var(--text-primary);
        }

        .permissions-panel-stats {
            font-size: 12px;
            color: var(--text-muted);
            background: var(--accent-primary-light);
            border: 1px solid var(--accent-primary);
            border-radius: 999px;
            padding: 3px 10px;
            white-space: nowrap;
        }

        .permissions-toolbar {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }

        .permissions-toolbar .form-control {
            padding: 10px 12px;
            font-size: 13px;
        }

        .user-permissions-list {
            min-height: 460px;
            overflow-y: auto;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            background: var(--bg-card);
        }

        .form-pane.user-info-pane {
            border-inline-start: 1px solid var(--border-primary);
            padding-inline-start: 18px;
        }

        .form-pane.user-permissions-pane {
            padding-inline-end: 0;
        }

        @media (max-width: 1200px) {
            .user-modal-content,
            .role-modal-content {
                width: min(1100px, 98vw);
                max-width: min(1100px, 98vw);
            }

            .user-form-grid,
            .role-form-grid {
                grid-template-columns: 1fr;
            }

            .form-pane.user-info-pane {
                border-inline-start: none;
                padding-inline-start: 0;
            }

            .permissions-toolbar {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="users-page" data-i18n-namespaces="common,users">

    <div id="loadingOverlay" class="loading-overlay" data-i18n="users.loading.executing">... جاري التنفيذ</div>
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>

    <!-- Role Management Modal -->
    <div id="roleModal" class="modal">
        <div class="modal-content role-modal-content text-right">
            <div class="modal-header">
                <h2 id="roleModalTitle" data-i18n="users.ui.txt_8239bd1f">إضافة دور جديد</h2>
            </div>
            <form id="roleForm" class="modal-form">
                <input type="hidden" id="roleIdField">
                <div class="modal-grid role-form-grid">
                    <div class="form-pane">
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_ffd52e52">اسم الدور</label>
                            <input type="text" id="roleNameField" class="form-control" placeholder="مثل: مسؤول التقارير" data-i18n-placeholder="users.ui.txt_3805066b" required>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_82f4c359">Slug (اختياري)</label>
                            <input type="text" id="roleSlugField" class="form-control" placeholder="report_manager">
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_ebe89f85">وصف الدور</label>
                            <textarea id="roleDescriptionField" class="form-control" rows="4" placeholder="وصف مختصر لمسؤوليات هذا الدور" data-i18n-placeholder="users.ui.txt_332524f6"></textarea>
                        </div>
                    </div>
                    <div class="form-pane">
                        <div class="permissions-panel">
                            <div class="permissions-panel-header">
                                <h3 class="permissions-panel-title" data-i18n="users.ui.txt_8fda9faf">صلاحيات الدور</h3>
                                <span id="rolePermissionsStats" class="permissions-panel-stats">0/0</span>
                            </div>
                            <div class="permissions-toolbar">
                                <input type="text" id="rolePermissionsSearch" class="form-control" placeholder="ابحث بالاسم أو slug أو الوصف..." data-i18n-placeholder="users.ui.txt_eb0cb699">
                                <select id="rolePermissionsDomainFilter" class="form-control">
                                    <option value="all" data-i18n="users.ui.txt_04f4a1b1">كل المجالات</option>
                                </select>
                            </div>
                            <div class="role-tools">
                                <button type="button" class="btn-action btn-edit" onclick="toggleVisibleRolePermissions(true)" data-i18n="users.ui.txt_3c0ff186">تحديد الظاهر</button>
                                <button type="button" class="btn-action btn-delete" onclick="toggleVisibleRolePermissions(false)" data-i18n="users.ui.txt_5dedbaf4">إلغاء الظاهر</button>
                                <button type="button" class="btn-action btn-edit" onclick="toggleAllRolePermissions(true)" data-i18n="users.actions.select_all">تحديد الكل</button>
                                <button type="button" class="btn-action btn-delete" onclick="toggleAllRolePermissions(false)" data-i18n="users.ui.txt_4755fb7e">إلغاء الكل</button>
                            </div>
                            <div id="rolePermissionsList" class="role-permissions-grid"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-save" data-i18n="users.ui.txt_e6fc7206">حفظ الدور وصلاحياته</button>
                    <button type="button" class="btn-cancel" onclick="closeRoleModal()" data-i18n="users.actions.cancel">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unified User & Permissions Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content user-modal-content text-right">
            <div class="modal-header">
                <h2 id="modalTitle" data-i18n="users.ui.txt_260caacc">إضافة مستخدم جديد</h2>
            </div>
            <form id="userForm" class="modal-form">
                <input type="hidden" id="userIdField">

                <div class="modal-grid user-form-grid">
                    <!-- Basic Info -->
                    <div class="form-pane user-info-pane">
                        <div class="form-group">
                            <label data-i18n="users.table.name">الاسم الكامل</label>
                            <input type="text" id="fullNameField" class="form-control" placeholder="مثل: أحمد محمد" data-i18n-placeholder="users.ui.txt_b52b3fd8" required>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.fields.username">اسم المستخدم</label>
                            <input type="text" id="usernameField" class="form-control" placeholder="username" required>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_73698845">البريد الإلكتروني (اختياري)</label>
                            <input type="email" id="emailField" class="form-control" placeholder="user@example.com" data-i18n-placeholder="users.ui.user_example_com">
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_22b7cdbc">الدور الوظيفي</label>
                            <select id="roleField" class="form-control" required>
                                <!-- Loaded via JS -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_7cdac228">لغة الواجهة</label>
                            <select id="preferredLanguageField" class="form-control">
                                <option value="ar" data-i18n="users.ui.txt_780b969b">العربية (RTL)</option>
                                <option value="en" data-i18n="users.ui.english_ltr">English (LTR)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.table.theme">المظهر</label>
                            <select id="preferredThemeField" class="form-control">
                                <option value="system">System</option>
                                <option value="light">Light</option>
                                <option value="dark">Dark</option>
                                <option value="desert">Desert</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label data-i18n="users.ui.txt_49d8df81">اتجاه الواجهة</label>
                            <select id="preferredDirectionField" class="form-control">
                                <option value="auto">Auto</option>
                                <option value="rtl">RTL</option>
                                <option value="ltr">LTR</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label id="passwordLabel" data-i18n="users.fields.password">كلمة المرور</label>
                            <input type="password" id="passwordField" class="form-control" placeholder="كلمة المرور" data-i18n-placeholder="users.fields.password">
                        </div>
                    </div>

                    <!-- Permissions Overrides -->
                    <div class="form-pane user-permissions-pane">
                        <div class="permissions-panel">
                            <div class="permissions-panel-header">
                                <h3 class="permissions-panel-title" data-i18n="users.ui.txt_32cee12d">تخصيص الصلاحيات (تحكم متقدم)</h3>
                                <span id="userPermissionsStats" class="permissions-panel-stats">0/0</span>
                            </div>
                            <div class="permissions-toolbar">
                                <input type="text" id="userPermissionsSearch" class="form-control" placeholder="ابحث بالاسم أو slug أو الوصف..." data-i18n-placeholder="users.ui.txt_eb0cb699">
                                <select id="userPermissionsDomainFilter" class="form-control">
                                    <option value="all" data-i18n="users.ui.txt_04f4a1b1">كل المجالات</option>
                                </select>
                            </div>
                            <div class="role-tools">
                                <button type="button" class="btn-action btn-edit" onclick="setVisibleUserOverrides('allow')" data-i18n="users.ui.txt_f143ade4">سماح للظاهر</button>
                                <button type="button" class="btn-action btn-delete" onclick="setVisibleUserOverrides('deny')" data-i18n="users.ui.txt_ef01e310">منع للظاهر</button>
                                <button type="button" class="btn-action btn-edit" onclick="setVisibleUserOverrides('auto')" data-i18n="users.ui.txt_16e14e58">تلقائي للظاهر</button>
                            </div>
                            <div id="permissionsList" class="user-permissions-list">
                                <!-- Loaded via JS -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn-save" data-i18n="users.ui.txt_b0c5b5a2">حفظ البيانات والصلاحيات</button>
                    <button type="button" class="btn-cancel" onclick="closeModal()" data-i18n="users.actions.cancel">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <div class="users-container">
        <div class="header-section">
            <div class="header-title">
                <h1 data-i18n="users.page.title">إدارة المستخدمين</h1>
                <p data-i18n="users.ui.txt_d0b485d9">إدارة الحسابات، كلمات المرور، والصلاحيات للموظفين</p>
            </div>
            <div class="users-header-actions">
                <button class="btn-action btn-add"
                    data-authorize-resource="roles"
                    data-authorize-action="manage"
                    data-authorize-mode="hide"
                    onclick="openAddRoleModal()">
                    <span>+</span> <span data-i18n="users.actions.add_role_short">إضافة دور</span>
                </button>
                <button class="btn-action btn-add"
                    data-authorize-resource="users"
                    data-authorize-action="manage"
                    data-authorize-mode="hide"
                    onclick="openAddModal()">
                    <span>+</span> <span data-i18n="users.ui.txt_260caacc">إضافة مستخدم جديد</span>
                </button>
            </div>
        </div>

        <div class="section-card"
            data-authorize-resource="roles"
            data-authorize-action="manage"
            data-authorize-mode="hide">
            <div class="section-header">
                <div>
                    <h2 class="section-title" data-i18n="users.ui.txt_c007681a">إدارة الأدوار</h2>
                    <p class="section-subtitle" data-i18n="users.ui.txt_253a8555">تحديد صلاحيات الرول بالكامل (عرض/تنفيذ/سلوك واجهة)</p>
                </div>
            </div>
            <div class="section-content">
                <table class="roles-table">
                    <thead>
                        <tr>
                            <th data-i18n="users.table.role">الدور</th>
                            <th>slug</th>
                            <th data-i18n="users.ui.txt_a9965b94">الوصف</th>
                            <th data-i18n="users.ui.txt_e4b15757">المستخدمون</th>
                            <th data-i18n="users.ui.txt_401c3782">الصلاحيات</th>
                            <th class="th-actions" data-i18n="users.ui.txt_8edfb81a">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="rolesTableBody">
                        <!-- Loaded via JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="users-card">
            <table>
                <thead>
                    <tr>
                        <th data-i18n="users.table.name">الاسم الكامل</th>
                        <th data-i18n="users.fields.username">اسم المستخدم</th>
                        <th data-i18n="users.table.role">الدور</th>
                        <th data-i18n="users.table.language">اللغة</th>
                        <th data-i18n="users.table.theme">المظهر</th>
                        <th data-i18n="users.table.direction">الاتجاه</th>
                        <th data-i18n="users.ui.txt_8ae0f595">آخر دخول</th>
                        <th class="th-actions" data-i18n="users.ui.txt_8edfb81a">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <!-- Loaded via JS -->
                </tbody>
            </table>
        </div>
    </div>

    <script src="../public/js/security.js?v=<?= time() ?>"></script>
    <script src="../public/js/users-management.js?v=<?= time() ?>"></script>
</body>

</html>

