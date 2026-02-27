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
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.125);
            border-radius: var(--radius-xl);
            padding: var(--space-2xl);
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
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
            background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
            border-radius: var(--radius-lg);
            margin: 0 auto var(--space-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }

        .login-title {
            color: white;
            font-size: var(--font-size-2xl);
            font-weight: var(--font-weight-black);
            margin-bottom: var(--space-xs);
        }

        .login-subtitle {
            color: rgba(255, 255, 255, 0.5);
            font-size: var(--font-size-base);
        }

        .form-group {
            margin-bottom: var(--space-lg);
        }

        .form-label {
            display: block;
            color: rgba(255, 255, 255, 0.8);
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-medium);
            margin-bottom: var(--space-sm);
        }

        .form-control {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            padding: var(--space-md);
            color: white;
            font-family: inherit;
            font-size: var(--font-size-base);
            transition: all var(--transition-base);
        }

        .form-control:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
        }

        .btn-login {
            width: 100%;
            background: var(--accent-primary);
            color: white;
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
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
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
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
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
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
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
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 999px;
            padding: 8px 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
        }

        .lang-toggle:hover {
            background: rgba(255, 255, 255, 0.14);
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
        <button type="button" class="lang-toggle" data-wbgl-direction-toggle data-i18n-title="nav.direction">
            <span>↔</span>
            <span data-i18n="nav.direction">الاتجاه</span>
            <span id="wbgl-direction-current">AUTO</span>
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
                    if (result?.user?.preferences?.direction_override && window.WBGLDirection) {
                        await window.WBGLDirection.setOverride(result.user.preferences.direction_override, {
                            persist: false,
                            source: 'login'
                        });
                    }
                    window.location.href = '/index.php';
                } else {
                    errorAlert.textContent = result?.message || result?.error || 'فشل تسجيل الدخول';
                    errorAlert.style.display = 'block';
                }
            } catch (error) {
                errorAlert.textContent = 'حدث خطأ في الاتصال بالخادم';
                errorAlert.style.display = 'block';
            } finally {
                btn.disabled = false;
                spinner.style.display = 'none';
            }
        });
    </script>
</body>

</html>
