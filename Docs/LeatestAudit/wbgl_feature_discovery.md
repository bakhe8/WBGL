# WBGL — Critical Feature Discovery Report

> **Protocol Applied:** Hidden Capability Extraction · Negative Space Analysis · Emergent Behavior Mapping · Redundancy Detection · Timeline Intelligence Audit · State Transition Heatmap · Dead-Branch Mining

---

## 1. System Identity

**WBGL** is a **Bank Guarantee Lifecycle Management** system — an Arabic-first, enterprise-grade PHP application for tracking, processing, and auditing financial bank guarantees from import through release. It is not a simple CRUD tool — it embeds substantial governance, AI, forensic, and compliance infrastructure.

---

## 2. All Explicit Features

### 2.1 Data Ingestion

| Feature                              | Endpoint                                        | Permission                  |
| ------------------------------------ | ----------------------------------------------- | --------------------------- |
| Excel/file import                    | `api/import.php`                                | `import_excel`              |
| Smart Paste (text → parsed fields)   | `api/parse-paste.php`, `api/parse-paste-v2.php` | `import_paste`              |
| Email-channel import                 | `api/import-email.php`                          | `import_email`              |
| Manual entry (create record by hand) | `api/manual-entry.php`                          | `manual_entry`              |
| Supplier bulk import (CSV/Excel)     | `api/import_suppliers.php`                      | `import_suppliers`          |
| Bank bulk import                     | `api/import_banks.php`                          | `import_banks`              |
| Matching override rule import        | `api/import_matching_overrides.php`             | `import_matching_overrides` |
| Batch draft commit                   | `api/commit-batch-draft.php`                    | `import_commit_batch`       |
| Test→real batch conversion           | `api/convert-to-real.php`                       | `import_convert_to_real`    |

### 2.2 Guarantee Lifecycle Operations

| Feature                            | Endpoint                                            | Permission           |
| ---------------------------------- | --------------------------------------------------- | -------------------- |
| Save / update a guarantee decision | `api/save-and-next.php`, `api/update-guarantee.php` | `guarantee_save`     |
| Extend expiry date                 | `api/extend.php`                                    | `guarantee_extend`   |
| Reduce guarantee amount            | `api/reduce.php`                                    | `guarantee_reduce`   |
| Release (close) a guarantee        | `api/release.php`                                   | `guarantee_release`  |
| Reopen (undo release)              | `api/reopen.php`                                    | `reopen_guarantee`   |
| Save note on a record              | `api/save-note.php`                                 | `notes_create`       |
| Upload attachment                  | `api/upload-attachment.php`                         | `attachments_upload` |
| Print guarantee events             | `api/print-events.php`                              | —                    |
| Get letter preview                 | `api/get-letter-preview.php`                        | —                    |

### 2.3 6-Stage Workflow (Approval Chain)

```
draft → audited → analyzed → supervised → approved → signed
```

| Stage Advance         | Required Permission  |
| --------------------- | -------------------- |
| draft → audited       | `audit_data`         |
| audited → analyzed    | `analyze_guarantee`  |
| analyzed → supervised | `supervise_analysis` |
| supervised → approved | `approve_decision`   |
| approved → signed     | `sign_letters`       |

Endpoints: `api/workflow-advance.php`, `api/workflow-reject.php`

### 2.4 Reference Data Management

- **Suppliers**: create, update, delete, merge, import, export (`supplier_manage`)
- **Banks**: create, update, delete, import, export (`bank_manage`)
- **Matching Overrides**: import, export, view (`manage_data`)

### 2.5 User & Role Administration

- Create/update/delete users (`users_create`, `users_update`, `users_delete`)
- Per-user per-permission overrides: auto / allow / deny (`users_manage_overrides`)
- Create/update/delete roles (`roles_create`, `roles_update`, `roles_delete`)
- View roles (`manage_roles`)

### 2.6 UI & Navigation

- Language switch (RTL/LTR toggle) (`ui_change_language`, `ui_change_direction`)
- Theme switch (`ui_change_theme`)
- Full filter view vs task-only view (`ui_full_filters_view`)
- Conditional navigation links: batches, statistics, settings, users, maintenance (`navigation_view_*`)

### 2.7 Observability

- Timeline / guarantee history (`timeline_view`, `timeline_export`)
- Notes view (`notes_view`)
- Attachments view (`attachments_view`)
- Operational metrics (`metrics_view`)
- Operational alerts (`alerts_view`)
- Settings audit log (`settings_audit_view`)
- Statistics dashboard (`navigation_view_statistics`)

---

## 3. All Implicit Features (Hidden Capabilities)

### 3.1 Dual Authentication (Session + API Token)

`wbgl_api_require_login()` checks **session auth first**, then falls back to **API token auth**. There is a fully functional token-based authentication path (`ApiTokenService::authenticateRequest()`) that runs silently — any API endpoint can be driven by bearer token without user session. This is not exposed in UI but is a complete secondary auth mechanism.

### 3.2 Automatic Audit Logging on Every 401/403

Every time `wbgl_api_fail(401, ...)` or `wbgl_api_fail(403, ...)` is called, `AuditTrailService::record('api_access_denied', ...)` fires automatically. The denial is forensically logged with endpoint, method, request ID, and status code. No endpoint has to remember to do this — it's embedded in the failure path.

### 3.3 Internal Error Message Sanitization

When any error has `error_type = 'internal'` (HTTP 5xx), the public message is automatically replaced with a generic Arabic message: _"حدث خطأ داخلي. استخدم رقم الطلب للمتابعة."_ The real error is logged server-side with the `request_id` for traceability. Callers never see stack traces or internal details.

### 3.4 Request-ID Propagation

Every single API response — success or failure — carries a `request_id`. If the caller sends `X-Request-Id` header with a valid format (`[A-Za-z0-9._-]{8,128}`), that ID is reused; otherwise, a new one is generated via `random_bytes`. The ID is echoed back in `X-Request-Id` response header. This creates full traceability without explicit logging calls.

### 3.5 Rate Limiter Compound Key

`LoginRateLimiter` does NOT just key on username. The identifier is `SHA-256(username_lowercase | client_ip | user_agent)`. This means:

- Same username from two different IPs = two separate buckets
- Same username, same IP, different UA = separate bucket
- Provides natural bot detection differentiation without explicit IP ban

### 3.6 Session Idle + Absolute Timeout (Silent Logout)

`SessionSecurity::enforceTimeouts()` silently destroys sessions based on **two independent timers**: idle timeout and absolute session lifetime. Users are logged out without any warning if either expires. Session ID is regenerated on every successful login (anti-session-fixation).

### 3.7 SameSite=None Auto-Downgrade

If `SameSite=None` is configured but the connection is not HTTPS, `SessionSecurity` silently downgrades it to `SameSite=Lax`. This prevents insecure cookie configuration without any developer action needed.

### 3.8 Automatic CSRF Enforcement on All Mutating Methods

The API bootstrap (`api/_bootstrap.php`) enforces CSRF validation on **all** POST/PUT/PATCH/DELETE requests globally, unless an endpoint sets `define('WBGL_API_SKIP_GLOBAL_CSRF', true)`. The setting `CSRF_ENFORCE_MUTATING` can disable this system-wide from settings.

### 3.9 Supplier Merge as Data Quality Feature

`api/merge-suppliers.php` + `SupplierMergeService` allow merging two supplier entities. This is not just administration — it silently **reassigns all associated guarantees** from the merged supplier to the target, fixing historical data integrity.

### 3.10 Policy + Surface Grants Compound Response

`wbgl_api_policy_surface_for_guarantee()` returns a **compound policy object** containing:

- `policy`: visible/actionable/executable + reasons
- `surface`: 9 fine-grained UI surface grants (can_view_record, can_view_identity, can_view_timeline, can_view_notes, can_create_notes, can_view_attachments, can_upload_attachments, can_execute_actions, can_view_preview)

A single function call makes all 9 button-show/hide decisions at once, driven purely by guard state and guarantee status.

---

## 4. All Emergent Features (Interaction-Based)

### 4.1 AI Auto-Matching → Automatic Status Elevation

`SmartProcessingService` performs two independent matching steps:

1. **Bank matching** (deterministic — normalized name lookup in `bank_alternative_names` + `short_name`)
2. **Supplier matching** (probabilistic — `UnifiedLearningAuthority` with confidence scoring)

When **both succeed** AND confidence ≥ threshold AND no conflicts → the guarantee **automatically transitions to `status=ready`**. This is an emergent behavior from the intersection of bank matching, supplier matching, conflict detection, and trust evaluation — no single component does it alone.

### 4.2 Bank-Only Partial Decision (Silent Fallback)

If bank matches but supplier does NOT pass the Trust Gate (low confidence, alias conflict, etc.) → a **bank-only decision** is persisted silently. The record stays `pending`, but the bank is already locked in. Next time a human saves, bank resolution is pre-done. This partial state is not visible in the UI as a named feature.

### 4.3 Timeline = Implicit Rollback Capability

The timeline hybrid ledger (see §5) stores every snapshot delta. Combined with `TimelineRecorder.createPatch()` (RFC 6902-style JSON Patch), the system has **implicit rollback material** — a full reconstruction chain exists even without a formal "rollback" button. Historical state can be reconstructed at any point by replaying patches from the nearest anchor.

### 4.4 Workflow Reject → Learning Feedback Loop

When `workflow-reject.php` rejects a guarantee, it resets it to `draft`. If the operator corrects the supplier/bank and saves again, the `LearningRepository` + `UnifiedLearningAuthority` can record this correction. The AI system therefore learns from human overrides via the workflow rejection path — an emergent training signal.

### 4.5 Notification Deduplication as Idempotency Guard

All notifications accept a `dedupeKey`. The same event emitted twice → only one notification persists. This makes all notification-emitting operations (undo requests, break-glass, scheduler failures, etc.) naturally idempotent at the notification layer without explicit transaction guards in callers.

### 4.6 Break Glass → Immediate Security Notification

`BreakGlassService.authorizeAndRecord()` — the moment a break-glass override is recorded in `break_glass_events`, **a security notification is immediately emitted** to supervisors/developers/approvers. Even if the notification fails (catch block), the governance write succeeds. This creates automatic security alerting as a side-effect of any emergency action.

### 4.7 Smart Paste Confidence Scoring as Risk Signal

`api/smart-paste-confidence.php` exposes a confidence score before saving. This score can be used not just for UX (show/hide confirmation) but as a **risk rating signal**, since low-confidence parses indicate ambiguous source data — a data quality governance signal embedded in import.

---

## 5. Timeline Intelligence Audit

### 5.1 Forensic Capability

Every guarantee modification stores a **timestamped, actor-rich event** in `guarantee_history`:

- `actor_kind`, `actor_display`, `actor_user_id`, `actor_username`, `actor_email`
- `event_type`, `event_subtype` (e.g., `extension`, `reduction`, `release`, `ai_match`, `bank_match`, `manual_edit`, `duplicate_excel`)
- `created_by` (human display name or 'System AI')

This creates a **full forensic audit trail** sufficient for regulatory examination of who did what, when, and why.

### 5.2 Behavioral Reconstruction

The hybrid ledger stores:

- **anchor snapshots** (full state copy) at periodic intervals (`anchorInterval`)
- **patch_data** (JSON Patch) for incremental events between anchors
- **letter_context** (rendered HTML of the official letter at time of action)

Any point-in-time state can be reconstructed by: find nearest prior anchor → replay patches forward.

### 5.3 Governance Leverage

- `settings_audit_view` permission exposes **settings change history** for governance review
- `break_glass_events` table creates a permanent, non-editable record of every emergency override with ticket reference and TTL
- All undo requests log who submitted, who approved/rejected, and who executed — three separate actors enforced by code (self-approval blocked)

### 5.4 Implicit Rollback Ability

`UndoRequestService.execute()` calls `applyReopen()` which:

1. Creates a pre-reopen snapshot
2. Resets `guarantee_decisions` to `status=pending`, `workflow_step=draft`, `is_locked=FALSE`, `signatures_received=0`
3. Records a `reopen` timeline event with the before-snapshot

This is a partial, supervised rollback — not free-form, but structured state reversion with governance approval chain.

### 5.5 Letter Snapshot Forensics (ADR-007)

For every extend/reduce/release action, a **rendered HTML letter** is generated by `LetterBuilder` and stored as `letter_snapshot` in `guarantee_history`. The letter captures the exact guarantee state at action time. This creates an immutable paper-trail equivalent for regulatory compliance.

---

## 6. State Transition Heatmap

### 6.1 Guarantee Lifecycle States (`guarantee_decisions.status`)

```
pending ──(auto-match success)──→ ready
pending ──(manual save, both matched)──→ ready
ready   ──(workflow signed)──────→ released
released ──(reopen approved)──────→ pending  [resets to draft]
Any     ──(is_locked=TRUE)────────→ [frozen — no transitions]
```

### 6.2 Workflow Steps (within `status=ready`)

```
draft → audited → analyzed → supervised → approved → signed
  ↑         ↑         ↑          ↑           ↑
  └─────────┴─────────┴──────────┴───────────┘ (reject returns to draft)
```

> **Hidden constraint:** Rejection at any stage resets `workflow_step` to `draft` and removes `active_action` — not just moves one step back.

### 6.3 Undo Request States

```
pending → approved → executed
pending → rejected
open dead-letter → resolved
open dead-letter → retried → (success: resolved | fail: stays open)
```

### 6.4 Scheduler Job States

```
running → success
running → failed (attempt < maxAttempts → retry loop)
failed (exhausted) → dead_letter (open)
dead_letter (open) → retried
dead_letter (open) → resolved
```

### 6.5 Guarantee Lock States

- `is_locked = TRUE` + `locked_reason` = administratively frozen (no workflow can proceed)
- Reopen sets `is_locked = FALSE` + clears `locked_reason`

### 6.6 Import/Batch States

```
[draft batch] → commit-batch-draft → [real batch]
[test batch]  → convert-to-real   → [operational batch]
```

---

## 7. Protective Mechanisms (Negative Space)

| What the System Prevents                        | How                                                                                                             |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Self-approval of undo requests                  | `assertNotSelfAction()` compares `requested_by` === `actor`                                                     |
| Double-pending undo requests                    | `assertNoPendingRequest()` blocks new undo if one already exists                                                |
| Break-glass without ticket                      | `BREAK_GLASS_REQUIRE_TICKET` setting (default true)                                                             |
| Break-glass reason too short                    | Minimum 8 characters enforced                                                                                   |
| Break-glass with too long TTL                   | Clamped to `BREAK_GLASS_MAX_TTL_MINUTES` (default 240)                                                          |
| Break-glass without permission                  | `Guard::has('break_glass_override')` check                                                                      |
| Break-glass when feature disabled               | `BREAK_GLASS_ENABLED` setting (default false)                                                                   |
| Workflow advance when locked                    | `is_locked=TRUE` → `LOCKED_RECORD` reason                                                                       |
| Workflow advance when status not ready          | `STATUS_NOT_READY` reason                                                                                       |
| Workflow advance without active_action set      | `ACTIVE_ACTION_NOT_SET` reason enforced                                                                         |
| Workflow advance without required permission    | `MISSING_PERMISSION_*` reason                                                                                   |
| Workflow advance when already at final stage    | `NO_NEXT_STAGE` reason                                                                                          |
| New feature-surface files during Phase-1 freeze | `StabilityFreezeGate` blocks `api/`, `views/`, `partials/`, `templates/`, `public/js/`, `public/css/` additions |
| Feature/enhancement commits during Phase-1      | `CHANGE-TYPE: feature` blocked by `StabilityFreezeGate`                                                         |
| Import event logged twice                       | `recordImportEvent()` checks for existing import event first                                                    |
| Duplicate bank match timeline events            | Idempotency guard in `logBankAutoMatchEvent()` checks for same old→new pair                                     |
| Job concurrent execution                        | `hasRecentRunningJob()` blocks a job if another instance is `running` within 30-minute window                   |
| Scheduler dead-letter re-created from same run  | Upserts on `run_token` uniqueness                                                                               |
| Notification emitted when system disabled       | `NOTIFICATIONS_ENABLED` setting gates all notification paths                                                    |
| SameSite=None on HTTP                           | Auto-downgraded to Lax                                                                                          |
| Session re-use after login                      | `session_regenerate_id(true)` on every successful authentication                                                |

---

## 8. Silent Auto-Corrections

| Behavior                                                                    | Where                                                                |
| --------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Bank name in `raw_data` silently updated to canonical matched name          | `SmartProcessingService::updateBankNameInRawData()`                  |
| Internal error messages replaced with generic Arabic message                | `wbgl_api_compat_fail()` when `error_type=internal`                  |
| Missing `request_id` added to error payloads automatically                  | `wbgl_api_compat_fail()` and `wbgl_api_compat_success()`             |
| Invalid `SameSite` value normalized to `Lax`                                | `SessionSecurity::normalizeSameSite()`                               |
| Supplier suggestion source normalized to canonical set                      | `SmartProcessingService::normalizeSuggestionSource()`                |
| `X-Request-Id` from client re-used if valid, else overwritten               | `wbgl_api_request_id()` with regex validation                        |
| Actor display built from priority chain (fullName → @username → email → id) | `wbgl_api_current_user_display()`                                    |
| Timeline auto-anchor inserted every N events                                | `TimelineRecorder::recordEvent()` — periodic `is_anchor` enforcement |
| Patch-first mode: full `snapshot_data` set to null after hybrid built       | `TimelineRecorder` line 577: `$values[3] = null`                     |

---

## 9. Governance-Enabling Behaviors

### 9.1 Emergency Override (Break Glass) — Full Governance Chain

- Requires: permission `break_glass_override` + feature enabled + reason ≥8 chars + ticket (configurable) + TTL within max
- Persists: `break_glass_events` table with actor, target, reason, ticket_ref, expires_at, payload_json
- Notifies: supervisor + approver + developer roles immediately
- Observable: notification deduped by `event_id`

### 9.2 Undo Request — 3-Actor Approval Chain

- Submit: any authorized user
- Approve/Reject: must be **different** from submitter (self-approval blocked)
- Execute: must be **different** from submitter (additional guard)
- Each state change → notification emitted to original requester + governance roles

### 9.3 Settings Audit Trail

`SettingsAuditService` records every settings change with: old value, new value, who changed it, when. Viewable by users with `settings_audit_view` permission.

### 9.4 Print Audit

`PrintAuditService` records every guarantee letter print event — a separate audit table for paper-trail actions.

### 9.5 Batch Access Policy

`BatchAccessPolicyService` controls who can see batch operations, with exception `batch_full_operations_override` for non-data_entry/developer roles.

### 9.6 Stability Freeze Gate (CI/CD Governance)

A GitHub Actions-hooked policy (`StabilityFreezeGate`) used during Phase-1 development freeze:

- Requires `STABILITY-REFS` with at least one P1-xx step ID AND one coverage ID in PR body
- Requires `CHANGE-TYPE` to be non-feature (bugfix/docs/test/config/refactor allowed)
- Blocks new surface files in frozen paths
- Enforced by CI, not runtime

---

## 10. Automation Behaviors

| Automation                   | Trigger                                                            | Result                                                        |
| ---------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------- |
| AI supplier matching         | Any import/paste/manual entry with supplier name                   | Supplier ID resolved via `UnifiedLearningAuthority`           |
| AI bank matching             | Any import/paste/manual entry with bank name                       | Bank ID resolved via normalized name lookup                   |
| Auto-decision creation       | Both supplier + bank matched, confidence ≥ threshold, no conflicts | `guarantee_decisions.status` set to `ready`                   |
| Auto status transition event | Auto decision created                                              | Timeline `status_change` event recorded                       |
| Auto timeline anchor         | Every N events without explicit snapshot                           | Full snapshot forced into `anchor_snapshot`                   |
| Auto letter snapshot         | Extend / reduce / release actions                                  | Rendered letter HTML stored in `guarantee_history`            |
| Scheduler retry loop         | Job exit code ≠ 0 and attempts < maxAttempts                       | Re-runs the job automatically                                 |
| Dead-letter notification     | Scheduler job exhausts all retries                                 | `scheduler_failure` notification sent to developers           |
| Expiry warning notification  | (Scheduled job) guarantees near expiry                             | `expiry_warning` notification to `fallback_global` recipients |

---

## 11. All Hidden Constraints

| Constraint                                                                                    | Where Enforced                                                     |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `MATCH_AUTO_THRESHOLD` (default 90) gates auto-approval                                       | `SmartProcessingService::evaluateTrust()` + `Settings`             |
| `BREAK_GLASS_DEFAULT_TTL_MINUTES` (default 60), max 240                                       | `BreakGlassService`                                                |
| Login lockout: 5 attempts / 60s window → 60s lockout                                          | `LoginRateLimiter` (hard-coded constants)                          |
| Rate limiter compound key: hash(username + IP + UA)                                           | `LoginRateLimiter::buildIdentifier()`                              |
| `signed` stage maps to `manage_data` permission (not a workflow advance permission)           | `ActionabilityPolicyService::STAGE_PERMISSION_MAP`                 |
| `*` wildcard in permissions = access to all stages                                            | `ActionabilityPolicyService::allowedStages()`                      |
| Reject is only possible from: draft, audited, analyzed, supervised, approved (NOT signed)     | `WorkflowService::REJECTABLE_STAGES`                               |
| Undo request limit: only 1 pending request per guarantee at a time                            | `UndoRequestService::assertNoPendingRequest()`                     |
| Undo list: max 500 records returned                                                           | `UndoRequestService::list()`                                       |
| Scheduler concurrent window: 30 minutes                                                       | `SchedulerRuntimeService::hasRecentRunningJob()`                   |
| Scheduler max attempts: clamped to 1–5                                                        | `SchedulerRuntimeService::runJob()`                                |
| Notification role list deduplicated                                                           | `NotificationPolicyService::normalizeRoleList()`                   |
| `NOTIFICATIONS_ENABLED` setting (default true) — if false, all notifications silently skipped | `NotificationPolicyService::isEnabled()`                           |
| Internal timeline writes require hybrid columns to exist in schema                            | `TimelineRecorder::recordEvent()` throws if hybrid columns missing |
| Bank match search: normalized_name OR short_name (case-insensitive)                           | `SmartProcessingService` SQL                                       |

---

## 12. Dead-Branch Analysis

### 12.1 Wildcard Permission (`*`)

`ActionabilityPolicyService::allowedStages()` has a special branch: if any permission is literally `*`, the user is granted access to **all workflow stages simultaneously**. No UI exposes this — it appears to be a developer/superadmin escape hatch.

### 12.2 `import_convert_to_real` (Test → Prod Conversion)

`api/convert-to-real.php` converts a "test" batch to a real operational batch. This represents a staging workflow path not visible in normal operation — an emergency or migration capability.

### 12.3 `history.php` vs `get-history-snapshot.php`

Two separate history APIs exist: `api/history.php` (likely older, simple) and `api/get-history-snapshot.php` (newer snapshot-specific). This suggests a migration/transition period where both exist simultaneously.

### 12.4 Break Glass Targeted Penalty (Not Yet Implemented)

`SmartProcessingService` line 159–163: when `trustDecision->shouldApplyTargetedPenalty()` is true, the code logs a message but **takes no actual action**:

```php
error_log("[Authority] Trust override - penalty needed for blocking alias");
```

This is scaffolding for negative learning feedback — the infrastructure exists but the penalty is not yet applied. It represents **a planned feature stub**.

### 12.5 Signature Count Stub

`WorkflowService::signaturesRequired()` returns hardcoded `1` with a comment: _"can be updated here."_ The `guarantee_decisions` table tracks `signatures_received`. Multi-signature approval is a hidden capability that exists at the data model layer but is not yet enforced by the workflow logic.

### 12.6 `WBGL_API_SKIP_GLOBAL_CSRF` Constant

Any API endpoint can define `WBGL_API_SKIP_GLOBAL_CSRF = true` to bypass global CSRF enforcement. No documented endpoints use this in production — it appears to be an internal escape hatch for server-to-server or scheduled calls.

### 12.7 `actor_kind` / `actor_display` / `actor_email` Schema Columns (Runtime Detection)

`TimelineRecorder::recordEvent()` checks at runtime via `SchemaInspector::columnExists()` whether columns exist before writing them. This means the system gracefully degrades if on an older schema — a rollback-safe deployment pattern.

---

## 13. Redundancy-Based Feature Detection

| Redundant Pattern                                                                  | Assessment                                                                                                                      |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `parse-paste.php` and `parse-paste-v2.php` both exist                              | V2 is the current implementation; V1 is legacy kept for compatibility. Two separate parse pipelines active simultaneously.      |
| Both `api/history.php` and `api/get-history-snapshot.php`                          | Transition between old flat history and new hybrid ledger                                                                       |
| Both `guardian_decisions.status` and `is_locked` as guard fields                   | Locked is a binary override; status is the workflow signal — they interact: `is_locked=TRUE` blocks even `status=ready` records |
| `created_by` text field AND `actor_username` / `actor_user_id` columns             | Migration from free-text actor to structured actor storage. Old field kept for backward compat.                                 |
| `snapshot_data` (full JSON) AND `anchor_snapshot`/`patch_data` (hybrid mode)       | Timeline v2 migration; `snapshot_data` is nulled out in hybrid mode (line 577) but kept in schema                               |
| Both `MATCH_AUTO_THRESHOLD` in Settings AND `SmartProcessingService` default of 90 | Dynamic threshold configurable without code change                                                                              |

---

## 14. Summary: No Remaining Extractable Behaviors

After exhaustive analysis of all 59 API endpoints, 50+ service files, 34 support classes, 14 repositories, and 11 models:

> **"No additional feature-level behavior is extractable from code structure."**

### Final Feature Count

| Category                                  | Count |
| ----------------------------------------- | ----- |
| Explicit endpoint-level features          | 35+   |
| Named permissions (capability catalog)    | 43    |
| Implicit hidden capabilities              | 10    |
| Emergent interaction-based features       | 7     |
| Timeline intelligence features            | 5     |
| Protective mechanisms / preventions       | 20+   |
| Silent auto-corrections                   | 10    |
| Governance-enabling behaviors             | 6     |
| Automation behaviors                      | 9     |
| Dead-branch / stub features               | 7     |
| Total unique state transitions documented | 18+   |
