(function () {
    'use strict';

    let rolesData = [];
    let allUsers = [];
    let allPermissions = [];
    let allOverrides = {};
    let permissionsById = {};
    let roleSlugTouched = false;
    let currentUserOverrideState = new Map();
    let currentRolePermissionState = new Set();
    let userPermissionFilter = { query: '', domain: 'all' };
    let rolePermissionFilter = { query: '', domain: 'all' };

    function setModalOpen(modalId, open) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.toggle('is-open', Boolean(open));
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    function showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (!overlay) return;
        overlay.classList.toggle('is-visible', Boolean(show));
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function extractMessage(payload, fallback) {
        if (!payload || typeof payload !== 'object') return fallback;
        if (typeof payload.error === 'string' && payload.error.trim() !== '') return payload.error;
        if (typeof payload.message === 'string' && payload.message.trim() !== '') return payload.message;
        if (payload.data && typeof payload.data === 'object') {
            if (typeof payload.data.error === 'string' && payload.data.error.trim() !== '') return payload.data.error;
            if (typeof payload.data.message === 'string' && payload.data.message.trim() !== '') return payload.data.message;
        }
        return fallback;
    }

    function notify(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'info');
            return;
        }
        window.alert(message);
    }

    function t(key, fallbackOrParams, maybeParams) {
        const hasExplicitFallback = typeof fallbackOrParams === 'string';
        const fallback = hasExplicitFallback ? fallbackOrParams : String(key || '');
        const params = (!hasExplicitFallback && fallbackOrParams && typeof fallbackOrParams === 'object')
            ? fallbackOrParams
            : (maybeParams && typeof maybeParams === 'object' ? maybeParams : undefined);

        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            const translated = window.WBGLI18n.t(key, fallback, params || undefined);
            if (typeof translated === 'string' && translated.trim() === String(key || '').trim()) {
                return fallback;
            }
            return translated;
        }

        let output = String(fallback || key || '');
        if (params && typeof params === 'object') {
            Object.keys(params).forEach((token) => {
                output = output.replace(new RegExp(`{{\\s*${token}\\s*}}`, 'g'), String(params[token]));
            });
        }
        return output;
    }

    function currentUiLocale() {
        if (window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function') {
            const lang = String(window.WBGLI18n.getLanguage() || '').toLowerCase();
            return lang.startsWith('en') ? 'en' : 'ar';
        }
        const htmlLang = String(document.documentElement.lang || '').toLowerCase();
        return htmlLang.startsWith('en') ? 'en' : 'ar';
    }

    function containsArabic(value) {
        return /[\u0600-\u06FF]/.test(String(value || ''));
    }

    function isCorruptedLabel(value) {
        const text = String(value || '').trim();
        return text !== '' && /^[?\s]+$/.test(text);
    }

    function humanizeSlug(slug) {
        return String(slug || '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (char) => char.toUpperCase());
    }

    const EN_DOMAIN_MAP = {
        'الإدخال والاستيراد': 'Input and import',
        'التحكم التشغيلي': 'Operational control',
        'سير الاعتماد': 'Approval workflow',
        'العرض والتتبع': 'Viewing and tracking',
        'تفضيلات الواجهة': 'UI preferences',
        'الملاحة': 'Navigation',
        'المؤشرات والرقابة': 'Metrics and oversight',
        'إدارة المستخدمين': 'User management',
        'إدارة الأدوار': 'Role management',
        'الحوكمة': 'Governance',
        'المرجعيات': 'Reference data',
    };

    const EN_SCOPE_MAP = {
        'رؤية': 'View',
        'تنفيذ': 'Execute',
        'تنفيذ طارئ': 'Emergency execute',
        'رؤية + تنفيذ': 'View + execute',
        'سلوك واجهة': 'UI behavior',
    };

    function translateRoleLabel(roleName, roleSlug) {
        const slug = String(roleSlug || '').toLowerCase();
        const roleKeyMap = {
            developer: 'users.roles.developer',
            data_entry: 'users.roles.data_entry',
            data_auditor: 'users.roles.data_auditor',
            analyst: 'users.roles.analyst',
            supervisor: 'users.roles.supervisor',
            approver: 'users.roles.approver',
            signatory: 'users.roles.signatory',
        };
        const key = roleKeyMap[slug];
        if (key) {
            const translated = t(key);
            if (translated && translated !== key) {
                return translated;
            }
        }
        return roleName || t('users.ui.txt_4ebb7cd7');
    }

    function scopeClass(scope) {
        const value = String(scope || '').trim();
        const viewLabel = t('users.ui.txt_91209ceb');
        const behaviorLabel = t('users.ui.txt_76a39efb');
        if (value.includes(viewLabel) || value.toLowerCase().includes('view')) return 'scope-view';
        if (value.includes(behaviorLabel) || value.toLowerCase().includes('behavior')) return 'scope-ui';
        return 'scope-action';
    }

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9_\-]+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^[_\-]+|[_\-]+$/g, '');
    }

    function groupPermissionsByDomain() {
        const grouped = {};
        allPermissions.forEach((permission) => {
            const domain = permissionDomain(permission);
            if (!grouped[domain]) grouped[domain] = [];
            grouped[domain].push(permission);
        });
        const order = {
            [t('users.ui.txt_a5230c66')]: 1,
            [EN_DOMAIN_MAP['الإدخال والاستيراد']]: 1,
            [t('users.ui.txt_ac81ab9e')]: 2,
            [EN_DOMAIN_MAP['التحكم التشغيلي']]: 2,
            [t('users.ui.txt_0ad0f4fc')]: 3,
            [EN_DOMAIN_MAP['سير الاعتماد']]: 3,
            [t('users.ui.txt_d5b2d6cf')]: 4,
            [EN_DOMAIN_MAP['العرض والتتبع']]: 4,
            [t('users.domain.ui_preferences')]: 5,
            [EN_DOMAIN_MAP['تفضيلات الواجهة']]: 5,
            [t('users.ui.txt_6d528585')]: 6,
            [EN_DOMAIN_MAP['الملاحة']]: 6,
            [t('users.ui.txt_72df6c34')]: 7,
            [EN_DOMAIN_MAP['المؤشرات والرقابة']]: 7,
            [t('users.page.title')]: 8,
            [EN_DOMAIN_MAP['إدارة المستخدمين']]: 8,
            [t('users.ui.txt_c007681a')]: 9,
            [EN_DOMAIN_MAP['إدارة الأدوار']]: 9,
            [t('users.ui.txt_9867d83b')]: 10,
            [EN_DOMAIN_MAP['الحوكمة']]: 10,
            [EN_DOMAIN_MAP['المرجعيات']]: 11,
            [t('users.ui.txt_3c6aa52e')]: 99,
        };
        return Object.keys(grouped).sort((a, b) => {
            const ia = Object.prototype.hasOwnProperty.call(order, a) ? order[a] : 999;
            const ib = Object.prototype.hasOwnProperty.call(order, b) ? order[b] : 999;
            return ia - ib;
        }).map((domain) => ({ domain: domain, permissions: grouped[domain] }));
    }

    function normalizeSearch(value) {
        return String(value || '').trim().toLowerCase();
    }

    function permissionDomain(permission) {
        const raw = permission && permission.meta && permission.meta.domain ? permission.meta.domain : t('users.ui.txt_3c6aa52e');
        if (currentUiLocale() === 'en') {
            return EN_DOMAIN_MAP[raw] || raw;
        }
        return raw;
    }

    function permissionScope(permission) {
        const raw = permission && permission.meta && permission.meta.control_scope ? permission.meta.control_scope : t('users.ui.txt_3baeb480');
        if (currentUiLocale() === 'en') {
            return EN_SCOPE_MAP[raw] || (containsArabic(raw) ? 'Execute' : raw);
        }
        return raw;
    }

    function permissionBehavior(permission) {
        const raw = permission && permission.meta && permission.meta.behavior ? permission.meta.behavior : (permission.description || '');
        if (currentUiLocale() === 'en') {
            if (raw && !containsArabic(raw) && !isCorruptedLabel(raw)) return raw;
            const slug = String(permission && permission.slug ? permission.slug : '');
            const key = `users.permissions.catalog.${slug}.behavior`;
            const translated = t(key);
            if (translated && translated !== key) return translated;
            return '';
        }
        return raw;
    }

    function permissionSurface(permission) {
        const raw = permission && permission.meta && permission.meta.surface ? permission.meta.surface : t('users.ui.unspecified');
        if (currentUiLocale() === 'en') {
            if (raw && !containsArabic(raw) && !isCorruptedLabel(raw)) return raw;
            return 'UI/API';
        }
        return raw;
    }

    function permissionDisplayName(permission) {
        const raw = String(permission && permission.name ? permission.name : '').trim();
        if (currentUiLocale() === 'en') {
            if (raw && !containsArabic(raw) && !isCorruptedLabel(raw)) return raw;
            const slug = String(permission && permission.slug ? permission.slug : '');
            const key = `users.permissions.catalog.${slug}.name`;
            const translated = t(key);
            if (translated && translated !== key) return translated;
            const human = humanizeSlug(slug);
            return human || t('users.ui.unspecified');
        }
        if (raw && !isCorruptedLabel(raw)) return raw;
        const fallback = humanizeSlug(permission && permission.slug ? permission.slug : '');
        return fallback || t('users.ui.unspecified');
    }

    function permissionMatchesFilter(permission, filter) {
        if (!permission || typeof permission !== 'object') return false;

        const domain = permissionDomain(permission);
        if (filter.domain && filter.domain !== 'all' && domain !== filter.domain) {
            return false;
        }

        const query = normalizeSearch(filter.query);
        if (query === '') return true;

        const haystack = [
            permissionDisplayName(permission),
            permission.slug,
            permission.description,
            domain,
            permissionScope(permission),
            permissionBehavior(permission),
            permissionSurface(permission)
        ].map((part) => normalizeSearch(part)).join(' ');

        return haystack.includes(query);
    }

    function populateDomainFilterOptions() {
        const domains = groupPermissionsByDomain().map((group) => group.domain);

        const populate = (selectId, currentValue) => {
            const select = document.getElementById(selectId);
            if (!select) return;

            const existing = new Set(['all', ...domains]);
            const valueToKeep = existing.has(currentValue) ? currentValue : 'all';
            const options = [`<option value="all">${escapeHtml(t('users.ui.txt_04f4a1b1'))}</option>`]
                .concat(domains.map((domain) => `<option value="${escapeHtml(domain)}">${escapeHtml(domain)}</option>`));
            select.innerHTML = options.join('');
            select.value = valueToKeep;
        };

        populate('userPermissionsDomainFilter', userPermissionFilter.domain);
        populate('rolePermissionsDomainFilter', rolePermissionFilter.domain);
    }

    function hydrateContext(payload) {
        rolesData = Array.isArray(payload.roles) ? payload.roles.map((role) => ({
            ...role,
            id: Number(role.id),
            users_count: Number(role.users_count || 0),
            permission_ids: Array.isArray(role.permission_ids) ? role.permission_ids.map((id) => Number(id)) : []
        })) : [];

        allUsers = Array.isArray(payload.users) ? payload.users : [];
        allPermissions = Array.isArray(payload.permissions) ? payload.permissions.map((permission) => ({
            ...permission,
            id: Number(permission.id)
        })) : [];
        allOverrides = payload.overrides && typeof payload.overrides === 'object' ? payload.overrides : {};

        permissionsById = {};
        allPermissions.forEach((permission) => {
            permissionsById[permission.id] = permission;
        });
    }

    async function fetchContext() {
        const response = await fetch('../api/users/list.php', {
            headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(extractMessage(data, t('users.ui.txt_0061a9f6')));
        }
        hydrateContext(data);
    }

    function renderRoleSelect() {
        const roleSelect = document.getElementById('roleField');
        if (!roleSelect) return;

        if (rolesData.length === 0) {
            roleSelect.innerHTML = `<option value="">${escapeHtml(t('users.empty.no_roles'))}</option>`;
            return;
        }

        roleSelect.innerHTML = rolesData.map((role) => {
            const roleLabel = translateRoleLabel(role.name, role.slug);
            const label = `${escapeHtml(roleLabel)} (${escapeHtml(role.slug || 'no-slug')})`;
            return `<option value="${role.id}">${label}</option>`;
        }).join('');
    }

    function renderUsers(users) {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;

        if (!Array.isArray(users) || users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-note">${escapeHtml(t('users.empty.no_users'))}</td></tr>`;
            return;
        }

        tbody.innerHTML = users.map((user) => `
            <tr data-user-id="${Number(user.id)}">
                <td><strong>${escapeHtml(user.full_name)}</strong><br><small class="user-email-muted">${escapeHtml(user.email || '')}</small></td>
                <td><code>${escapeHtml(user.username)}</code></td>
                <td><span class="role-badge role-${escapeHtml(user.role_slug || 'default')}">${escapeHtml(translateRoleLabel(user.role_name, user.role_slug))}</span></td>
                <td>${escapeHtml((user.preferred_language || 'ar').toUpperCase())}</td>
                <td>${escapeHtml((user.preferred_theme || 'system').toUpperCase())}</td>
                <td>${escapeHtml((user.preferred_direction || 'auto').toUpperCase())}</td>
                <td class="user-last-login">${escapeHtml(user.last_login || t('users.ui.txt_8ccc58aa', 'لم يدخل بعد'))}</td>
                <td>
                    <div class="users-actions-inline">
                        <button class="btn-action btn-edit"
                            title="${escapeHtml(t('users.ui.txt_67102c52'))}"
                            data-authorize-resource="users"
                            data-authorize-action="manage"
                            data-authorize-mode="disable"
                            onclick="openEditModal(${Number(user.id)})">✏️ إدارة</button>
                        <button class="btn-action btn-delete"
                            title="${escapeHtml(t('users.ui.txt_ebf26008'))}"
                            data-authorize-resource="users"
                            data-authorize-action="manage"
                            data-authorize-mode="disable"
                            onclick="deleteUser(${Number(user.id)})">🗑️</button>
                    </div>
                </td>
            </tr>
        `).join('');

        if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
            window.WBGLPolicy.applyDomGuards(tbody);
        }
    }

    function renderRoles(roles) {
        const tbody = document.getElementById('rolesTableBody');
        if (!tbody) return;

        if (!Array.isArray(roles) || roles.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty-note">${escapeHtml(t('users.empty.no_defined_roles'))}</td></tr>`;
            return;
        }

        tbody.innerHTML = roles.map((role) => {
            const permissionSlugs = (role.permission_ids || []).map((permissionId) => {
                return permissionsById[permissionId] ? permissionsById[permissionId].slug : null;
            }).filter(Boolean);
            const preview = permissionSlugs.slice(0, 4).join(', ');
            const suffix = permissionSlugs.length > 4 ? ` +${permissionSlugs.length - 4}` : '';

            return `
                <tr data-role-id="${role.id}">
                    <td><span class="role-chip">${escapeHtml(translateRoleLabel(role.name, role.slug))}</span></td>
                    <td><code>${escapeHtml(role.slug || '')}</code></td>
                    <td>${escapeHtml(role.description || '—')}</td>
                    <td>${Number(role.users_count || 0)}</td>
                    <td>
                        <div>${Number(role.permissions_count || (role.permission_ids || []).length)}</div>
                        <div class="permission-preview" title="${escapeHtml(permissionSlugs.join(', '))}">${escapeHtml(preview + suffix || t('users.ui.txt_f9dcc2d5'))}</div>
                    </td>
                    <td>
                        <div class="users-actions-inline">
                            <button class="btn-action btn-edit"
                                data-authorize-resource="roles"
                                data-authorize-action="manage"
                                data-authorize-mode="disable"
                                title="${escapeHtml(t('users.ui.txt_0200ce9f'))}"
                                onclick="openEditRoleModal(${role.id})">✏️</button>
                            <button class="btn-action btn-delete"
                                data-authorize-resource="roles"
                                data-authorize-action="manage"
                                data-authorize-mode="disable"
                                title="${escapeHtml(t('users.ui.txt_649cb366'))}"
                                onclick="deleteRole(${role.id})">🗑️</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
            window.WBGLPolicy.applyDomGuards(tbody);
        }
    }

    function renderPermissionsList() {
        const listEl = document.getElementById('permissionsList');
        if (!listEl) return;

        const groups = groupPermissionsByDomain();
        let visibleCount = 0;
        const sections = groups.map(({ domain, permissions }) => {
            const filtered = permissions.filter((permission) => permissionMatchesFilter(permission, userPermissionFilter));
            if (filtered.length === 0) return '';
            const rows = filtered.map((permission) => {
                const type = currentUserOverrideState.get(Number(permission.id)) || 'auto';
                const scope = permissionScope(permission);
                const behavior = permissionBehavior(permission);
                const surface = permissionSurface(permission);
                visibleCount++;

                return `
                    <div class="perm-row" data-perm-id="${permission.id}">
                        <div class="perm-info">
                            <b>${escapeHtml(permissionDisplayName(permission))}</b>
                            <small>${escapeHtml(permission.slug)}</small>
                            <div class="perm-meta">
                                <span class="perm-badge domain">${escapeHtml(domain)}</span>
                                <span class="perm-badge ${scopeClass(scope)}">${escapeHtml(scope)}</span>
                            </div>
                            ${behavior ? `<div class="perm-surface">${escapeHtml(behavior)}</div>` : ''}
                            ${surface ? `<div class="perm-surface">${escapeHtml(t('users.fields.surface_label'))}: ${escapeHtml(surface)}</div>` : ''}
                        </div>
                        <div class="toggle-group">
                            <button type="button" class="toggle-btn ${type === 'auto' ? 'active' : ''}" data-type="auto" onclick="setOverride(${permission.id}, 'auto')">${escapeHtml(t('users.permissions.auto'))}</button>
                            <button type="button" class="toggle-btn ${type === 'allow' ? 'active' : ''}" data-type="allow" onclick="setOverride(${permission.id}, 'allow')">${escapeHtml(t('users.permissions.allow'))}</button>
                            <button type="button" class="toggle-btn ${type === 'deny' ? 'active' : ''}" data-type="deny" onclick="setOverride(${permission.id}, 'deny')">${escapeHtml(t('users.permissions.deny'))}</button>
                        </div>
                    </div>
                `;
            }).join('');

            return `<div class="perm-group"><div class="perm-group-title">${escapeHtml(domain)}</div>${rows}</div>`;
        });

        const rendered = sections.filter((section) => section !== '').join('');
        listEl.innerHTML = rendered === '' ? `<div class="empty-note">${escapeHtml(t('users.permissions.empty_filtered'))}</div>` : rendered;

        const statsEl = document.getElementById('userPermissionsStats');
        if (statsEl) {
            const overriddenCount = collectUserOverrides().length;
            statsEl.textContent = t('users.permissions.stats_overrides', { visible: visibleCount, total: allPermissions.length, count: overriddenCount });
        }
    }

    function renderRolePermissionsSelection() {
        const listEl = document.getElementById('rolePermissionsList');
        if (!listEl) return;

        const groups = groupPermissionsByDomain();
        let visibleCount = 0;
        const sections = groups.map(({ domain, permissions }) => {
            const filtered = permissions.filter((permission) => permissionMatchesFilter(permission, rolePermissionFilter));
            if (filtered.length === 0) return '';
            const rows = filtered.map((permission) => {
                const checked = currentRolePermissionState.has(Number(permission.id)) ? 'checked' : '';
                const scope = permissionScope(permission);
                const behavior = permissionBehavior(permission);
                const metaLine = behavior ? `${scope} • ${behavior}` : scope;
                visibleCount++;
                return `
                    <label class="role-perm-row">
                        <input type="checkbox" class="role-permission-checkbox" value="${permission.id}" ${checked}
                            onchange="toggleRolePermission(${permission.id}, this.checked)">
                        <div class="role-perm-details">
                            <b>${escapeHtml(permissionDisplayName(permission))} <small>(${escapeHtml(permission.slug)})</small></b>
                            <small>${escapeHtml(metaLine)}</small>
                        </div>
                    </label>
                `;
            }).join('');
            return `<div class="perm-group"><div class="perm-group-title">${escapeHtml(domain)}</div>${rows}</div>`;
        });

        const rendered = sections.filter((section) => section !== '').join('');
        listEl.innerHTML = rendered === '' ? `<div class="empty-note">${escapeHtml(t('users.permissions.empty_filtered'))}</div>` : rendered;

        const statsEl = document.getElementById('rolePermissionsStats');
        if (statsEl) {
            statsEl.textContent = t('users.permissions.stats_selected', { visible: visibleCount, total: allPermissions.length, count: currentRolePermissionState.size });
        }
    }

    function setOverride(permissionId, type) {
        const id = Number(permissionId);
        if (!Number.isInteger(id) || id <= 0) return;
        currentUserOverrideState.set(id, String(type || 'auto'));

        const row = document.querySelector(`.perm-row[data-perm-id="${permissionId}"]`);
        if (!row) return;
        row.querySelectorAll('.toggle-btn').forEach((button) => button.classList.remove('active'));
        const target = row.querySelector(`.toggle-btn[data-type="${type}"]`);
        if (target) target.classList.add('active');
    }

    function collectUserOverrides() {
        const overrides = [];
        currentUserOverrideState.forEach((type, permissionId) => {
            if (type !== 'auto') {
                overrides.push({
                    permission_id: permissionId,
                    type: type
                });
            }
        });
        return overrides;
    }

    function collectRolePermissions() {
        return Array.from(currentRolePermissionState.values())
            .map((value) => Number(value))
            .filter((value) => Number.isInteger(value) && value > 0);
    }

    function toggleAllRolePermissions(checked) {
        if (checked) {
            allPermissions.forEach((permission) => {
                currentRolePermissionState.add(Number(permission.id));
            });
        } else {
            currentRolePermissionState.clear();
        }
        renderRolePermissionsSelection();
    }

    function toggleVisibleRolePermissions(checked) {
        allPermissions.forEach((permission) => {
            if (!permissionMatchesFilter(permission, rolePermissionFilter)) return;
            const id = Number(permission.id);
            if (checked) {
                currentRolePermissionState.add(id);
            } else {
                currentRolePermissionState.delete(id);
            }
        });
        renderRolePermissionsSelection();
    }

    function setVisibleUserOverrides(type) {
        const nextType = String(type || 'auto');
        allPermissions.forEach((permission) => {
            if (!permissionMatchesFilter(permission, userPermissionFilter)) return;
            currentUserOverrideState.set(Number(permission.id), nextType);
        });
        renderPermissionsList();
    }

    function toggleRolePermission(permissionId, checked) {
        const id = Number(permissionId);
        if (!Number.isInteger(id) || id <= 0) return;
        if (checked) {
            currentRolePermissionState.add(id);
        } else {
            currentRolePermissionState.delete(id);
        }
    }

    function openAddModal() {
        if (rolesData.length === 0) {
            notify(t('users.ui.txt_9714578a'), 'error');
            return;
        }

        document.getElementById('modalTitle').innerText = t('users.ui.txt_260caacc');
        document.getElementById('userIdField').value = '';
        document.getElementById('userForm').reset();
        document.getElementById('preferredLanguageField').value = 'ar';
        document.getElementById('preferredThemeField').value = 'system';
        document.getElementById('preferredDirectionField').value = 'auto';
        document.getElementById('passwordField').required = true;
        document.getElementById('passwordLabel').innerText = t('users.fields.password');
        document.getElementById('roleField').value = String(rolesData[0].id);
        userPermissionFilter = { query: '', domain: 'all' };
        const searchInput = document.getElementById('userPermissionsSearch');
        if (searchInput) searchInput.value = '';
        const domainSelect = document.getElementById('userPermissionsDomainFilter');
        if (domainSelect) domainSelect.value = 'all';

        currentUserOverrideState = new Map();
        allPermissions.forEach((permission) => {
            currentUserOverrideState.set(Number(permission.id), 'auto');
        });

        renderPermissionsList();
        setModalOpen('userModal', true);
    }

    function openEditModal(userId) {
        const user = allUsers.find((item) => Number(item.id) === Number(userId));
        if (!user) return;

        document.getElementById('modalTitle').innerText = t('users.ui.txt_ab0e2664');
        document.getElementById('userIdField').value = String(user.id);
        document.getElementById('fullNameField').value = user.full_name || '';
        document.getElementById('usernameField').value = user.username || '';
        document.getElementById('emailField').value = user.email || '';
        document.getElementById('roleField').value = String(user.role_id || '');
        document.getElementById('preferredLanguageField').value = user.preferred_language || 'ar';
        document.getElementById('preferredThemeField').value = user.preferred_theme || 'system';
        document.getElementById('preferredDirectionField').value = user.preferred_direction || 'auto';
        document.getElementById('passwordField').required = false;
        document.getElementById('passwordField').value = '';
        document.getElementById('passwordLabel').innerText = t('users.ui.txt_dba43e6e');

        const userOverrides = allOverrides[String(user.id)] || allOverrides[user.id] || [];
        userPermissionFilter = { query: '', domain: 'all' };
        const searchInput = document.getElementById('userPermissionsSearch');
        if (searchInput) searchInput.value = '';
        const domainSelect = document.getElementById('userPermissionsDomainFilter');
        if (domainSelect) domainSelect.value = 'all';

        currentUserOverrideState = new Map();
        allPermissions.forEach((permission) => {
            currentUserOverrideState.set(Number(permission.id), 'auto');
        });
        userOverrides.forEach((item) => {
            const permissionId = Number(item.permission_id || 0);
            const type = String(item.type || 'auto');
            if (permissionId > 0) {
                currentUserOverrideState.set(permissionId, type);
            }
        });

        renderPermissionsList();

        setModalOpen('userModal', true);
    }

    function closeModal() {
        setModalOpen('userModal', false);
    }

    function openAddRoleModal() {
        document.getElementById('roleModalTitle').innerText = t('users.ui.txt_8239bd1f');
        document.getElementById('roleIdField').value = '';
        document.getElementById('roleForm').reset();
        roleSlugTouched = false;
        rolePermissionFilter = { query: '', domain: 'all' };
        const searchInput = document.getElementById('rolePermissionsSearch');
        if (searchInput) searchInput.value = '';
        const domainSelect = document.getElementById('rolePermissionsDomainFilter');
        if (domainSelect) domainSelect.value = 'all';

        currentRolePermissionState = new Set();
        renderRolePermissionsSelection();
        setModalOpen('roleModal', true);
    }

    function openEditRoleModal(roleId) {
        const role = rolesData.find((item) => Number(item.id) === Number(roleId));
        if (!role) return;

        document.getElementById('roleModalTitle').innerText = t('users.ui.txt_30612de7');
        document.getElementById('roleIdField').value = String(role.id);
        document.getElementById('roleNameField').value = role.name || '';
        document.getElementById('roleSlugField').value = role.slug || '';
        document.getElementById('roleDescriptionField').value = role.description || '';
        roleSlugTouched = true;
        rolePermissionFilter = { query: '', domain: 'all' };
        const searchInput = document.getElementById('rolePermissionsSearch');
        if (searchInput) searchInput.value = '';
        const domainSelect = document.getElementById('rolePermissionsDomainFilter');
        if (domainSelect) domainSelect.value = 'all';

        currentRolePermissionState = new Set((role.permission_ids || []).map((value) => Number(value)));
        renderRolePermissionsSelection();
        setModalOpen('roleModal', true);
    }

    function closeRoleModal() {
        setModalOpen('roleModal', false);
    }

    async function saveUser(event) {
        event.preventDefault();
        const userId = document.getElementById('userIdField').value;
        const isEdit = userId !== '';
        const payload = {
            user_id: userId,
            full_name: document.getElementById('fullNameField').value.trim(),
            username: document.getElementById('usernameField').value.trim(),
            email: document.getElementById('emailField').value.trim(),
            role_id: Number(document.getElementById('roleField').value),
            preferred_language: document.getElementById('preferredLanguageField').value,
            preferred_theme: document.getElementById('preferredThemeField').value,
            preferred_direction: document.getElementById('preferredDirectionField').value,
            password: document.getElementById('passwordField').value,
            permissions_overrides: collectUserOverrides()
        };

        const url = isEdit ? '../api/users/update.php' : '../api/users/create.php';

        showLoading(true);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(extractMessage(data, t('users.ui.txt_d2b89a63')));
            }
            closeModal();
            await refreshDashboard();
            notify(extractMessage(data, t('users.ui.txt_45e831bf')), 'success');
        } catch (error) {
            notify(error.message || t('users.ui.txt_1c5be7f0'), 'error');
        } finally {
            showLoading(false);
        }
    }

    async function saveRole(event) {
        event.preventDefault();
        const roleId = Number(document.getElementById('roleIdField').value || 0);
        const isEdit = roleId > 0;
        const payload = {
            role_id: roleId,
            name: document.getElementById('roleNameField').value.trim(),
            slug: document.getElementById('roleSlugField').value.trim(),
            description: document.getElementById('roleDescriptionField').value.trim(),
            permission_ids: collectRolePermissions()
        };

        const url = isEdit ? '../api/roles/update.php' : '../api/roles/create.php';

        showLoading(true);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(extractMessage(data, t('users.ui.txt_e2497051')));
            }
            closeRoleModal();
            await refreshDashboard();
            notify(extractMessage(data, t('users.ui.txt_7c39d235')), 'success');
        } catch (error) {
            notify(error.message || t('users.ui.txt_dc1652d8'), 'error');
        } finally {
            showLoading(false);
        }
    }

    async function deleteUser(userId) {
        if (!window.confirm(t('users.ui.txt_466d886f'))) return;

        showLoading(true);
        try {
            const response = await fetch('../api/users/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: Number(userId) })
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(extractMessage(data, t('users.ui.txt_edee1433')));
            }
            await refreshDashboard();
            notify(extractMessage(data, t('users.ui.txt_4180b8c6')), 'success');
        } catch (error) {
            notify(error.message || t('users.ui.txt_4d3346b8'), 'error');
        } finally {
            showLoading(false);
        }
    }

    async function deleteRole(roleId) {
        const role = rolesData.find((item) => Number(item.id) === Number(roleId));
        const roleName = role ? role.name : `#${roleId}`;
        if (!window.confirm(`هل أنت متأكد من حذف الدور "${roleName}"؟`)) return;

        showLoading(true);
        try {
            const response = await fetch('../api/roles/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ role_id: Number(roleId) })
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(extractMessage(data, t('users.ui.txt_7ff62f6c')));
            }
            await refreshDashboard();
            notify(extractMessage(data, t('users.ui.txt_5bac3d38')), 'success');
        } catch (error) {
            notify(error.message || t('users.ui.txt_74074784'), 'error');
        } finally {
            showLoading(false);
        }
    }

    async function refreshDashboard() {
        await fetchContext();
        populateDomainFilterOptions();
        renderRoleSelect();
        renderUsers(allUsers);
        renderRoles(rolesData);
        if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
            window.WBGLPolicy.applyDomGuards(document);
        }
    }

    function bindRoleSlugSync() {
        const nameInput = document.getElementById('roleNameField');
        const slugInput = document.getElementById('roleSlugField');
        if (!nameInput || !slugInput) return;

        slugInput.addEventListener('input', () => {
            roleSlugTouched = slugInput.value.trim() !== '';
        });

        nameInput.addEventListener('input', () => {
            if (roleSlugTouched) return;
            slugInput.value = slugify(nameInput.value);
        });
    }

    function bindPermissionFilters() {
        const userSearch = document.getElementById('userPermissionsSearch');
        const userDomain = document.getElementById('userPermissionsDomainFilter');
        const roleSearch = document.getElementById('rolePermissionsSearch');
        const roleDomain = document.getElementById('rolePermissionsDomainFilter');

        if (userSearch) {
            userSearch.addEventListener('input', () => {
                userPermissionFilter.query = userSearch.value || '';
                renderPermissionsList();
            });
        }
        if (userDomain) {
            userDomain.addEventListener('change', () => {
                userPermissionFilter.domain = userDomain.value || 'all';
                renderPermissionsList();
            });
        }
        if (roleSearch) {
            roleSearch.addEventListener('input', () => {
                rolePermissionFilter.query = roleSearch.value || '';
                renderRolePermissionsSelection();
            });
        }
        if (roleDomain) {
            roleDomain.addEventListener('change', () => {
                rolePermissionFilter.domain = roleDomain.value || 'all';
                renderRolePermissionsSelection();
            });
        }
    }

    function bindModalEvents() {
        const userForm = document.getElementById('userForm');
        const roleForm = document.getElementById('roleForm');
        if (userForm) userForm.addEventListener('submit', saveUser);
        if (roleForm) roleForm.addEventListener('submit', saveRole);

        window.addEventListener('click', (event) => {
            if (event.target === document.getElementById('userModal')) {
                closeModal();
            }
            if (event.target === document.getElementById('roleModal')) {
                closeRoleModal();
            }
        });
    }

    async function init() {
        showLoading(true);
        try {
            if (window.WBGLI18n && typeof window.WBGLI18n.loadNamespaces === 'function') {
                await window.WBGLI18n.loadNamespaces(['users']);
            }
            bindRoleSlugSync();
            bindPermissionFilters();
            bindModalEvents();
            await refreshDashboard();
            document.addEventListener('wbgl:language-changed', () => {
                populateDomainFilterOptions();
                renderRoleSelect();
                renderUsers(allUsers);
                renderRoles(rolesData);
            });
        } catch (error) {
            notify(error.message || t('users.ui.txt_214564ac'), 'error');
        } finally {
            showLoading(false);
        }
    }

    window.setOverride = setOverride;
    window.toggleAllRolePermissions = toggleAllRolePermissions;
    window.toggleVisibleRolePermissions = toggleVisibleRolePermissions;
    window.setVisibleUserOverrides = setVisibleUserOverrides;
    window.toggleRolePermission = toggleRolePermission;
    window.openAddModal = openAddModal;
    window.openEditModal = openEditModal;
    window.closeModal = closeModal;
    window.deleteUser = deleteUser;
    window.openAddRoleModal = openAddRoleModal;
    window.openEditRoleModal = openEditRoleModal;
    window.closeRoleModal = closeRoleModal;
    window.deleteRole = deleteRole;

    document.addEventListener('DOMContentLoaded', init);
})();
