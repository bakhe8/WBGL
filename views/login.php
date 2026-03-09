<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;

// Redirect if already logged in
if (AuthService::isLoggedIn()) {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="login.title">تسجيل الدخول | WBGL</title>
    <?php include __DIR__ . '/../partials/ui-bootstrap.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/public/css/design-system.css">
    <style>
        :root {
            --login-bg-layer-a: var(--accent-primary-light, rgba(59, 130, 246, 0.2));
            --login-bg-layer-b: var(--theme-overlay-soft, rgba(0, 0, 0, 0.06));
            --login-bg-start: var(--bg-body, #f1f5f9);
            --login-bg-end: var(--bg-secondary, #f8fafc);
            --login-card-bg: var(--theme-glass-surface, var(--bg-card, #ffffff));
            --login-card-border: var(--border-primary, #e2e8f0);
            --login-card-shadow: var(--shadow-xl, 0 8px 30px rgba(0, 0, 0, 0.12));
            --login-title-color: var(--text-primary, #1e293b);
            --login-subtitle-color: var(--text-muted, #64748b);
            --login-label-color: var(--text-secondary, #475569);
            --login-input-bg: var(--bg-neutral, #fafbfc);
            --login-input-border: var(--border-primary, #e2e8f0);
            --login-input-focus-bg: var(--bg-card, #ffffff);
            --login-input-text: var(--text-primary, #1e293b);
            --login-input-placeholder: var(--text-light, #94a3b8);
            --login-toggle-bg: var(--theme-overlay-soft, rgba(0, 0, 0, 0.05));
            --login-toggle-bg-hover: var(--theme-overlay-medium, rgba(0, 0, 0, 0.1));
            --login-toggle-border: var(--border-primary, #e2e8f0);
            --login-toggle-text: var(--text-primary, #1e293b);
            --login-focus-ring: var(--theme-focus-ring-medium, rgba(59, 130, 246, 0.2));
        }

        body {
            background:
                radial-gradient(900px 420px at 8% 0%, var(--login-bg-layer-a) 0%, transparent 60%),
                radial-gradient(720px 360px at 95% 100%, var(--login-bg-layer-b) 0%, transparent 70%),
                linear-gradient(135deg, var(--login-bg-start) 0%, var(--login-bg-end) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: var(--space-lg);
        }

        .login-card {
            background: var(--login-card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--login-card-border);
            border-radius: var(--radius-xl);
            padding: var(--space-2xl);
            width: 100%;
            max-width: 420px;
            box-shadow: var(--login-card-shadow);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: var(--space-2xl);
        }

        .brand-logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--accent-primary), var(--theme-brand-gradient-end, var(--accent-primary-hover)));
            border-radius: var(--radius-lg);
            margin: 0 auto var(--space-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--btn-primary-text, #ffffff);
            box-shadow: 0 10px 20px var(--theme-primary-shadow-btn, rgba(37, 99, 235, 0.25));
        }

        .login-title {
            color: var(--login-title-color);
            font-size: var(--font-size-2xl);
            font-weight: var(--font-weight-black);
            margin-bottom: var(--space-xs);
        }

        .login-subtitle {
            color: var(--login-subtitle-color);
            font-size: var(--font-size-base);
        }

        .form-group {
            margin-bottom: var(--space-lg);
        }

        .form-label {
            display: block;
            color: var(--login-label-color);
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-medium);
            margin-bottom: var(--space-sm);
        }

        .form-control {
            width: 100%;
            background: var(--login-input-bg);
            border: 1px solid var(--login-input-border);
            border-radius: var(--radius-md);
            padding: var(--space-md);
            color: var(--login-input-text);
            font-family: inherit;
            font-size: var(--font-size-base);
            transition: all var(--transition-base);
        }

        .form-control::placeholder {
            color: var(--login-input-placeholder);
        }

        .form-control:focus {
            outline: none;
            background: var(--login-input-focus-bg);
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 4px var(--login-focus-ring);
        }

        .btn-login {
            width: 100%;
            background: var(--accent-primary);
            color: var(--btn-primary-text, #ffffff);
            border: none;
            border-radius: var(--radius-md);
            padding: var(--space-md);
            font-size: var(--font-size-lg);
            font-weight: var(--font-weight-bold);
            cursor: pointer;
            transition: all var(--transition-base);
            margin-top: var(--space-md);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-sm);
        }

        .btn-login:hover {
            background: var(--accent-primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px var(--theme-primary-shadow-btn, rgba(37, 99, 235, 0.25));
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .error-message {
            background: var(--theme-danger-surface, rgba(239, 68, 68, 0.12));
            border: 1px solid var(--theme-danger-border, rgba(239, 68, 68, 0.3));
            color: var(--theme-danger-text, #991b1b);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            font-size: var(--font-size-sm);
            margin-bottom: var(--space-lg);
            display: none;
            text-align: center;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid var(--accent-primary-light);
            border-radius: 50%;
            border-top-color: var(--btn-primary-text, #ffffff);
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .ui-toggle-group {
            position: fixed;
            top: 16px;
            inset-inline-start: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            z-index: 10;
        }

        .lang-toggle {
            background: var(--login-toggle-bg);
            border: 1px solid var(--login-toggle-border);
            color: var(--login-toggle-text);
            border-radius: 999px;
            padding: 8px 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: background var(--transition-base), border-color var(--transition-base), transform var(--transition-base);
        }

        .lang-toggle:hover {
            background: var(--login-toggle-bg-hover);
            border-color: var(--border-neutral, #cbd5e1);
            transform: translateY(-1px);
        }

        .lang-toggle:focus-visible,
        .btn-login:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px var(--login-focus-ring);
        }

        @media (max-width: 560px) {
            body {
                padding: var(--space-md);
            }

            .login-card {
                padding: var(--space-xl);
            }

            .ui-toggle-group {
                top: 12px;
                inset-inline-start: 12px;
                gap: 6px;
            }

            .lang-toggle {
                padding: 6px 10px;
            }
        }
    </style>
</head>

<body class="login-page" data-i18n-namespaces="common,auth">
    <div class="ui-toggle-group">
        <button type="button" class="lang-toggle" data-wbgl-lang-toggle data-i18n-title="nav.language">
            <span>🌐</span>
            <span data-i18n="nav.language">اللغة</span>
            <span id="wbgl-lang-current">AR</span>
        </button>
        <button type="button" class="lang-toggle" data-wbgl-theme-toggle data-i18n-title="nav.theme">
            <span>🎨</span>
            <span data-i18n="nav.theme">المظهر</span>
            <span id="wbgl-theme-current">SYS</span>
        </button>
    </div>

    <div class="login-card">
        <div class="login-header">
            <div class="brand-logo">B</div>
            <h1 class="login-title" data-i18n="login.brand_title">WBGL System</h1>
            <p class="login-subtitle" data-i18n="login.subtitle">نظام إدارة الضمانات البنكية</p>
        </div>

        <div id="error-alert" class="error-message"></div>

        <form id="login-form">
            <div class="form-group">
                <label class="form-label" for="username" data-i18n="login.username">اسم المستخدم</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="أدخل اسم المستخدم" data-i18n-placeholder="login.username_placeholder" required autocomplete="username">
            </div>

            <div class="form-group">
                <label class="form-label" for="password" data-i18n="login.password">كلمة المرور</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <button type="submit" id="submit-btn" class="btn-login">
                <span data-i18n="login.submit">دخول</span>
                <div id="spinner" class="spinner"></div>
            </button>
        </form>
    </div>

    <script src="/public/js/security.js"></script>
    <script src="/public/js/i18n.js"></script>
    <script src="/public/js/direction.js"></script>
    <script src="/public/js/theme.js"></script>
    <script src="/public/js/policy.js"></script>
    <script src="/public/js/nav-manifest.js"></script>
    <script src="/public/js/ui-runtime.js"></script>
    <script src="/public/js/global-shortcuts.js"></script>
    <script src="/public/js/main.js"></script>
    <script>
        const loginT = (key, fallback, params) => {
            if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
                return window.WBGLI18n.t(key, fallback, params);
            }
            return fallback || key;
        };

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const btn = document.getElementById('submit-btn');
            const spinner = document.getElementById('spinner');
            const errorAlert = document.getElementById('error-alert');

            // UI State
            btn.disabled = true;
            spinner.style.display = 'block';
            errorAlert.style.display = 'none';

            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());

            try {
                const response = await fetch('/api/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                let result = {};
                try {
                    result = await response.json();
                } catch (parseError) {
                    result = {};
                }

                if (response.ok && result?.success === true) {
                    if (result?.user?.preferences?.language && window.WBGLI18n) {
                        await window.WBGLI18n.setLanguage(result.user.preferences.language, {
                            persist: false
                        });
                    }
                    if (result?.user?.preferences?.theme && window.WBGLTheme) {
                        await window.WBGLTheme.setTheme(result.user.preferences.theme, {
                            persist: false,
                            source: 'login'
                        });
                    }
                    window.location.href = '/index.php';
                } else {
                    errorAlert.textContent = result?.message || result?.error || loginT('auth.ui.txt_9930d1b1', 'auth.ui.txt_9930d1b1');
                    errorAlert.style.display = 'block';
                }
            } catch (error) {
                errorAlert.textContent = loginT('auth.ui.txt_7181b69d', 'auth.ui.txt_7181b69d');
                errorAlert.style.display = 'block';
            } finally {
                btn.disabled = false;
                spinner.style.display = 'none';
            }
        });
    </script>
</body>

</html>
