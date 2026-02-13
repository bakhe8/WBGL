# WBGL — Full Operational Audit (Evidence-Driven)

**Date:** 2026-02-13  
**Method:** Static analysis + runtime/tooling checks  
**Scope:** Current codebase behavior only (no speculation)  
**Operating Assumption for Prioritization:** Offline on one home device, single trusted user

---

## 1) Operational Map (As-Implemented)

### 1.1 Entry and data flow
1. **UI Entry:** `index.php` loads current guarantee, decisions, timeline display data, notes/attachments, and navigation metadata.
2. **Import channels:**
   - Excel: `api/import.php` → `ImportService::importFromExcel()` → duplicate handling + occurrence tracking + import timeline event + smart processing.
   - Smart Paste: `ParseCoordinatorService` path (single/multi row) → create/find guarantee → post-processing.
   - Manual create endpoint exists (`api/manual-entry.php`) but no in-repo call reference detected.
3. **Decision lifecycle:** `api/save-and-next.php` updates/creates one row in `guarantee_decisions`, evaluates status with `StatusEvaluator`, records timeline events, and returns next record metadata.
4. **Actions lifecycle:**
   - Extend: `api/extend.php` (requires `ready`, not locked) → updates expiry in raw_data + active_action + timeline event.
   - Reduce: `api/reduce.php` (requires `ready`, not locked, and strictly lower amount) → updates amount + active_action + timeline event.
   - Release: `api/release.php` (requires `ready`) → lock + status released + active_action + release letter preview.
5. **Timeline rendering:** `api/get-timeline.php` resolves record by **index** and renders sidebar partial.

### 1.2 State model (effective)
- Status authority (intended): `ready` if supplier_id + bank_id, else `pending`.
- Released state uses lock semantics plus explicit status update in release endpoint.
- Active action pointer is stored on decision row and cleared on key data changes in `save-and-next` (ADR-007 behavior).

---

## 2) Phase Findings Summary

### Critical
1. **Navigation/record source mismatch creates wrong-record risk by index-based APIs.**

### High
2. **Test infrastructure is non-executable (phpunit binary target missing).**

### Medium
3. **Status evaluator depends on `global $db` (fragile hidden dependency).**
4. **Dynamic SQL interpolation remains in verification queries (low exploitability after int-cast, but unsafe pattern).**
5. **Timeline/get-record index endpoints hard-cap to latest 100 rows, diverging from full navigation behavior.**

### Low / Cleanup
6. **Potential orphan endpoints/views/services (no internal references detected).**
7. **Mixed response contract types across APIs (JSON vs HTML fragments) increase client complexity.**

### Later Phase (Security Hardening)
8. **No application-layer auth/session/CSRF controls in API surface (deferred by deployment model).**
9. **Attachment upload policy hardening (allowlist/MIME/size/permissions) is deferred to future expansion.**

---

## 3) Detailed Findings (Evidence + Impact + Fix)

### F-01 (Critical) — Inconsistent navigation authority
**Evidence**
- `api/get-record.php` uses `ORDER BY imported_at DESC LIMIT 100` by index.
- `api/get-timeline.php` uses same index strategy.
- `NavigationService` uses filtered traversal over IDs and statuses; `save-and-next` uses `NavigationService`.

**Confirmed impact**
- Index-oriented calls can point to a different logical record order than ID/filter navigation flow.
- Timeline panel can drift from the record currently navigated via filter-aware flows.

**Root cause**
- Two competing ordering authorities: (A) imported_at DESC + index, (B) NavigationService filtered ID order.

**Fix direction**
- Replace index-based record resolution with `NavigationService::getIdByIndex(...)` or direct ID-based API contract across UI.
- Remove hard-coded `LIMIT 100` from record/timeline fetch path.

---

### F-02 (Later Phase) — Missing API protection primitives
**Evidence**
- Repository-wide PHP grep for `session_start`, `$_SESSION`, CSRF tokens, auth headers, login password checks returned no matches.

**Operational impact under current model**
- With one trusted local user on one offline machine, immediate exploitation surface is limited.
- Risk becomes relevant if multi-user, LAN exposure, remote access, or cloud deployment is introduced.

**Root cause**
- No centralized middleware/policy layer; endpoints are directly callable.

**Fix direction (deferred)**
- Keep as deferred security backlog item.
- Activate only when deployment model expands beyond single offline user.

---

### F-03 (High) — Testability broken
**Evidence**
- Runtime command: `php vendor/bin/phpunit` fails with include path to missing `vendor/phpunit/phpunit/phpunit`.

**Confirmed impact**
- No reliable regression safety net can run in current environment.

**Root cause**
- Incomplete/invalid vendor state for PHPUnit binary target.

**Fix direction**
- Reinstall dev dependencies (`composer install` with dev), then re-run full suite.
- If no tests actually exist, align `phpunit.xml` and CI expectations accordingly.

---

### F-04 (Later Phase) — Attachment upload hardening gaps
**Evidence**
- `api/upload-attachment.php` creates storage directory with mode `0777`.
- No extension/MIME allowlist enforcement.
- No explicit file size policy enforcement.
- `uploaded_by` is hardcoded (`User`).

**Operational impact under current model**
- Impact is limited in current single-user offline setup.
- Becomes important immediately if external files/users/access paths are introduced.

**Fix direction (deferred)**
- Enforce strict allowlist (`pdf`, office docs, images only), validate MIME server-side, set max size, deny executable extensions.
- Use `0755`/`0700` with non-public storage and controlled download endpoint.
- Persist real actor identity.

---

### F-05 (Medium) — Hidden global dependency in status authority
**Evidence**
- `app/Services/StatusEvaluator.php` uses `global $db` in `evaluateFromDatabase`.

**Confirmed impact**
- Function behavior depends on external global setup and can fail silently in non-page contexts.

**Fix direction**
- Pass PDO explicitly to evaluator method or fetch via `Database::connect()` inside method consistently.

---

### F-06 (Medium) — SQL interpolation pattern remains
**Evidence**
- `api/update_bank.php`: `SELECT id FROM banks WHERE id = $bankId`
- `api/update_supplier.php`: `SELECT id FROM suppliers WHERE id = $supplierId`

**Confirmed impact**
- Current risk reduced by integer parsing, but coding pattern is unsafe and may regress with future refactors.

**Fix direction**
- Use prepared statements for all queries, including verification queries.

---

### F-07 (Medium) — Hard limit on index APIs
**Evidence**
- `get-record` / `get-timeline` both use `LIMIT 100`.

**Confirmed impact**
- Records beyond first 100 are invisible via those index-driven endpoints.

**Fix direction**
- Replace with filter-aware pagination/navigation service.

---

### F-08 (Low) — Potential orphan code
**Evidence**
- No internal references detected for: `api/manual-entry.php`, `api/parse-paste-v2.php`, `api/history.php`, `views/confidence-demo.php`.
- Multiple service classes also appeared definition-only in previous usage scans.

**Status**
- **Non-confirmed as dead code** (could be externally called/bookmarked/manual QA routes).

**Verification method**
- Add lightweight endpoint access logging + route inventory test for 7 days, then remove unhit code safely.

---

## 4) Reliability Observations

1. **Decision path strictness improved**: `save-and-next` now enforces existing `bank_id` before status can become ready.
2. **Action gates are present**: extend/reduce/release reject pending or locked records.
3. **Timeline discipline exists**: snapshot → update → record implemented consistently in action endpoints.
4. **But authority split remains**: index APIs are still not aligned with NavigationService.
5. **Security hardening items are intentionally deferred** based on current offline single-user operating model.

---

## 5) Remediation Backlog (Prioritized)

### Quick Wins (1–2 days)
1. Unify get-record/get-timeline with NavigationService ID resolution.
2. Remove `LIMIT 100` from operational navigation path.
3. Convert interpolated verification queries to prepared statements.
4. Refactor `StatusEvaluator::evaluateFromDatabase` to explicit dependency injection.

### Medium (3–7 days)
5. Standardize API response contracts (clear JSON envelope for state endpoints).
6. Build route map and deprecate confirmed-orphan endpoints/services.
7. Restore test pipeline: install/fix PHPUnit + create smoke coverage for decision/actions flows.

### Later Phase (If deployment model expands)
8. Add minimal auth/CSRF guard middleware for all mutating endpoints.
9. Replace upload folder mode and enforce extension/MIME/size policy.
10. Replace hardcoded `uploaded_by` with actor extraction from authenticated identity.

---

## 6) Verification Checklist (Post-Fix)

1. **Navigation integrity test**: same record ID across UI record panel, timeline panel, and save-and-next under all filters/search.
2. **Large dataset test**: >100 records remain navigable end-to-end without index truncation.
3. **Status evaluator test**: evaluator works in isolated CLI/test context without global variable.
4. **Regression test run**: `php vendor/bin/phpunit` executes successfully and reports suite outcome.
5. **(Later phase)** Security + upload policy tests are activated when deployment model changes.

---

## 7) Confirmed vs Non-Confirmed Matrix

### Confirmed
- Navigation authority split and ordering mismatch.
- Broken PHPUnit execution path.
- Global DB dependency in status evaluator.
- SQL interpolation pattern in two update endpoints.

### Deferred by Operating Model
- Missing in-app auth/session/CSRF primitives.
- Upload hardening gaps.

### Non-Confirmed (needs runtime verification)
- Orphan endpoints/views/services are truly unused in production traffic.

**How to confirm quickly:** enable request logging for candidate paths and collect usage window before deletion.
