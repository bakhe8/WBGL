# WBGL Security Baseline (Wave-1)

Last Updated: 2026-02-26

## Scope

This baseline hardens session/auth request handling without changing business workflows:

1. Session cookie hardening (`HttpOnly`, `SameSite`, secure-on-HTTPS, strict mode)
2. Session timeout enforcement (idle + absolute)
3. Logout hard destroy (session + CSRF token cleanup)
4. Central HTTP security headers (CSP + frame/content/referrer/permissions policies)
5. Central CSRF enforcement for mutating requests
6. Login rate-limit fingerprint expansion (username + IP + user-agent)

## Implemented Components

- `app/Support/SessionSecurity.php`
- `app/Support/SecurityHeaders.php`
- `app/Support/CsrfGuard.php`
- `public/js/security.js`

## Wiring Points

- `app/Support/autoload.php`
  - Applies session hardening
  - Applies security headers
  - Enforces non-API CSRF for mutating web requests
- `api/_bootstrap.php`
  - Enforces CSRF for mutating API requests
- `api/login.php`
  - Validates CSRF before login processing
- `app/Support/AuthService.php`
  - Login: regenerates session + rotates CSRF
  - Logout: hard session destroy

## Frontend Coverage

CSRF injection runtime is loaded in:

- `partials/unified-header.php`
- `views/login.php`
- `views/users.php`
- `views/batch-print.php`

`public/js/security.js` auto-injects `X-CSRF-Token` on same-origin mutating `fetch` calls.

## Runtime Settings

Defined in `app/Support/Settings.php`:

- `SECURITY_HEADERS_ENABLED` (default `true`)
- `CSRF_ENFORCE_MUTATING` (default `true`)
- `SESSION_IDLE_TIMEOUT_SECONDS` (default `1800`)
- `SESSION_ABSOLUTE_TIMEOUT_SECONDS` (default `43200`)

## Validation

- Unit suite includes security baseline wiring checks:
  - `tests/Unit/SecurityBaselineWiringTest.php`
- Loop status includes `security_baseline` block:
  - `docs/WBGL_EXECUTION_LOOP_STATUS.json`

