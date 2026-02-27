# WBGL No-Reinvention Policy

Last update: 2026-02-26

This policy prevents "reinventing the wheel" during WBGL upgrades.

## 1) Rule of Extension First

Before adding any new file/module, the developer must:

1. Search for an existing implementation seed.
2. Reuse or extend that seed whenever technically possible.
3. Create new surface only when extension is not viable.

## 2) Mandatory Reuse Seeds (Current WBGL)

- Auth/session seed: `app/Support/AuthService.php`
- Permission seed: `app/Support/Guard.php`
- API input/response support: `app/Support/Input.php`, `app/Support/autoload.php`
- Maintenance surface: `maint/index.php` + existing `maint/*.php`
- Settings/config behavior: `app/Support/Settings.php`
- DB access seed: `app/Support/Database.php`

## 3) What Was Added and Why (Evidence)

- `api/_bootstrap.php`
  - Built on top of existing `AuthService` + `Guard`.
  - Goal: centralize endpoint guard calls without rewriting auth logic.

- `maint/migrate.php` + `maint/migration-status.php`
  - Added under existing `maint/` surface.
  - Goal: provide versioned SQL migration discipline; legacy project had ad-hoc DB scripts only.

- `maint/run-execution-loop.php`
  - Reads existing comparison corpus in `../Docs`.
  - Goal: execution orchestration and measurable progress, not domain logic duplication.

## 4) PR Governance Requirements

Every PR must include:

- `REUSE-REF:` paths to the existing WBGL files reused as seeds.
- `NEW-SURFACE-JUSTIFICATION:` only when adding new sensitive files.

`Change Gate` enforces both.

## 5) Stop Conditions

Stop and redesign if a change:

- duplicates an existing service/repository/support utility,
- introduces a parallel auth/permission mechanism,
- adds a new workflow path while an existing one can be extended safely.
