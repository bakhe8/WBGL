# Critical Feature Discovery Report

## Protocol Output
Comprehensive feature-level extraction completed from code structure, including hidden behaviors, negative space, emergent effects, timeline intelligence, state transitions, and dead branches.

## All Explicit Features
- Guarantee intake from Excel, manual entry, parse/paste, and email-import workflows.
- Supplier and bank master-data CRUD, plus supplier merge capability.
- Decision save flow for guarantees with status handling.
- Operational actions on guarantees: extend, reduce, release, reopen.
- Batch operations: extend/release/reduce, close, reopen, metadata update, summary.
- Workflow progression through stage approvals up to signing.
- Undo-request lifecycle with submit/approve/reject/execute.
- Historical timeline rendering and per-event historical state viewing.
- Letter preview and print governance endpoints.
- Notes and attachments endpoints.
- User management endpoints (create/update/delete/list) and user preference management.
- Settings management and settings-audit retrieval.
- Scheduler dead-letter listing, resolve, retry.
- Notifications inbox and mark-read actions.
- Metrics and alert snapshot APIs.

## All Implicit Features (Hidden Capability Extraction)
- Record-read/navigation can mutate data: loading a record can auto-match supplier/bank and persist decisions/status transitions.
- Save flow auto-repairs stale supplier ID/name mismatches by clearing mismatched IDs.
- Save flow can auto-create missing suppliers from typed names.
- Save flow can auto-resolve bank from raw bank/label and upsert a bank-only pending decision before manual completion.
- Status is implicitly derived by completeness logic (supplier+bank => ready; otherwise pending), not user-chosen.
- Data edits can implicitly clear `active_action` when status is ready.
- Decision subtype (`correction`) is inferred from prior reopen history, not explicitly selected.
- Duplicate imports do not duplicate guarantees; they create occurrence records and duplicate-import timeline events.
- Batch operations are partial-success engines with per-record blocked/error reporting.
- Break-glass request payload can be passed in multiple shapes and normalized server-side.
- Frontend fetch wrapper silently injects CSRF on mutating same-origin requests.
- Frontend policy layer silently hides/disables unauthorized DOM actions.
- Session hydration (`/api/me`) can update client permission state at runtime without reload.

## Negative Space Analysis
- Prevented: release/extend/reduce on incomplete (`pending`) guarantees unless break-glass path allows.
- Prevented: mutation of released/locked guarantees unless break-glass is explicitly authorized.
- Prevented: undo self-approval/self-execution (requester cannot approve/reject/execute own request).
- Prevented: more than one pending undo request per guarantee.
- Prevented: dead-letter retry unless status is `open`.
- Prevented: batch reopen without mandatory reason.
- Prevented: CSRF-less mutating requests for session-auth clients.
- Prevented: production-mode creation of test data in major intake paths.
- Auto-corrected silently: bank name normalization and replacement in raw data after deterministic match.
- Enforced without explicit UI: visibility SQL fallback `AND 1=0` when no visibility predicates exist.

## All Emergent Features (Cross-Module Interactions)
- Navigation-driven auto-processing: UI record navigation + read endpoint side effects produce autonomous matching behavior.
- Forensic replay engine: timeline hybrid ledger + reconstructed snapshots yields before-state reconstruction even when legacy snapshot payloads are nullified.
- Governance bypass channel: mutation policy + break-glass service create controlled exception handling with reason/ticket/TTL audit.
- Governance queue pattern: reopen endpoint + undo service create two-mode reopening (request workflow vs direct emergency).
- Actionability model: stats + navigation + workflow permissions produce role-scoped actionable queues.
- Print accountability: preview/print endpoints + print-audit service create behavioral tracking of document access/print actions.
- Operational oversight: metrics snapshot + alert rules + dead-letter service provide incident/ops monitoring signals.

## Redundancy-Based Feature Detection
- Duplicated status/filter logic exists in multiple layers (navigation service and page controller), indicating abstraction drift.
- Auto-match and decision upsert logic appears in read endpoint, save endpoint, and smart-processing service; this is both a hidden resilience feature and a maintenance risk.
- Batch action logic duplicates individual endpoint logic with intentional divergences (for example, reduction does not set `active_action` while extension/release do).
- Multiple ingestion paths implement similar import semantics with differing contracts (main import vs email/save-import vs parse variants), implying informal feature forks.
- Bank matching logic is repeated with slightly different matching strategies and side effects across modules.

## Timeline Intelligence Audit
- Forensic capability: event stream stores event type/subtype, changes, actor, and hybrid patch/anchor payloads that allow state reconstruction.
- Behavioral reconstruction: actor identities, reason fields, print events, settings changes, and API denial audit logs support user/action tracing.
- Governance leverage: break-glass events persist reason, ticket reference, TTL, target, and payload; batch reopen and undo workflows preserve accountability chains.
- Implicit rollback ability: undo execution reopens guarantee state (`released/ready` back to `pending`) and historical timeline snapshots enable manual forensic rollback decisions; not a full automatic data rollback engine, but supports controlled reversal workflows.
- Archive continuity: pre-delete history archiving preserves audit lineage before destructive test-data deletion.

## All Governance-Enabling Behaviors
- Break-glass event recording with permission, feature-flag, reason length, optional ticket enforcement, TTL clamping, and expiry metadata.
- Undo-request governance lifecycle with separation of duties and status gates.
- Batch reopen governance requiring reason and specific permission or break-glass override.
- Settings audit diff logging per changed key.
- Print preview/print event auditing with context and source metadata.
- API deny auditing (401/403) with request ID capture.
- Batch audit events for close/reopen actions.
- Legacy endpoint retirement logging.
- History archival before test-data deletion.

## All Automation Behaviors
- Smart-processing auto-matching pipeline after imports and manual/paste creation.
- Deterministic bank matching with optional bank-only decision persistence.
- Supplier suggestion authority and high-confidence auto-application paths.
- Read-time auto-upsert of decisions and status transitions.
- Supplier auto-create on save when typed name is unknown.
- Automatic CSRF token propagation in frontend fetch wrapper.
- Session timeout enforcement and security-header application from settings.
- Notification dedupe by key for recurring governance events.
- Periodic/milestone hybrid-ledger anchor decisions.

## All Protective Mechanisms
- Role/permission checks via centralized guard and endpoint bootstrap.
- Visibility filtering by stage permissions and ownership, with deny-all fallback.
- Released/locked mutation guard via policy service.
- Break-glass permission + policy + audit constraints.
- Lifecycle gates for action eligibility (`ready` requirement for release/extend/reduce).
- Numeric/date/range validation for critical fields (amount, expiry, thresholds).
- Login rate limiting with lockout and `Retry-After`.
- CSRF enforcement for mutating requests (except bearer-token path).
- Session hardening (strict cookies, regeneration, idle/absolute timeouts).
- Security headers and CSP policy.

## All Hidden Constraints
- Canonical persisted decision statuses are effectively `pending`, `ready`, `released`; UI labels like `extended`/`reduced` are presentation states, not persisted lifecycle states.
- `active_action` is set for extension/release flows, intentionally not set for reduction flows.
- Some mutating endpoints require only login (not elevated permission), creating broader mutation surface than action endpoints that require `manage_data`.
- `get-current-state` omits `active_action` while timeline controller expects it, causing action-context loss on "return to current state".
- Timeline writing hard-fails if hybrid columns are unavailable.
- Runtime DB layer is PostgreSQL-only, while migrations are authored in SQLite-like syntax and depend on adapter normalization.
- Multiple modules assume one decision row per guarantee.
- Production mode silently excludes test data from major list/stats flows.

## All Silent Assumptions
- Raw JSON (`raw_data`) remains source-of-truth for many displayed fields.
- Timeline snapshot contract assumes "before-change" semantics for event reconstruction.
- Workflow assumes sequential forward-only stage transitions.
- Multi-signature workflow branch exists but effectively dormant under current `signaturesRequired() = 1`.
- Permissions model assumes role permissions plus user allow/deny overrides; developer role wildcard is trusted.
- Same-origin browser usage is assumed for CSRF and auth-error event flow.
- Core tables and hybrid history columns are assumed pre-existing/migrated.

## State Transition Heatmap
| Domain | From | To | Trigger | Gate/Condition |
|---|---|---|---|---|
| Guarantee Decision Status | no row | pending | bank-only auto decision insert | bank resolved, supplier missing |
| Guarantee Decision Status | no row | ready | decision insert | supplier+bank present |
| Guarantee Decision Status | pending | ready | save/read-time auto-match/smart-process | completeness satisfied |
| Guarantee Decision Status | pending | pending | save/update bank-only | supplier still missing |
| Guarantee Decision Status | ready | ready | save/extend/reduce edits | no lifecycle lock transition |
| Guarantee Decision Status | ready | released | release (single/batch) | must be ready, not blocked |
| Guarantee Decision Status | released | pending | reopen direct or undo execute | permission/break-glass/workflow path |
| Guarantee Decision Status | ready | pending | reopen direct | governed reopen path |
| Workflow Step | draft | audited | workflow advance | `audit_data` permission |
| Workflow Step | audited | analyzed | workflow advance | `analyze_guarantee` permission |
| Workflow Step | analyzed | supervised | workflow advance | `supervise_analysis` permission |
| Workflow Step | supervised | approved | workflow advance | `approve_decision` permission |
| Workflow Step | approved | signed | workflow advance | `sign_letters` permission |
| Undo Request Status | none | pending | submit | no existing pending request |
| Undo Request Status | pending | approved | approve | non-self action |
| Undo Request Status | pending | rejected | reject | non-self action |
| Undo Request Status | approved | executed | execute | non-self action |
| Batch Metadata Status | missing | completed | close batch | creates metadata if absent |
| Batch Metadata Status | missing | active | metadata ensure/create | metadata upsert path |
| Batch Metadata Status | active | completed | close batch | always allowed |
| Batch Metadata Status | completed | active | reopen batch | reason required + permission/break-glass |
| Dead Letter Status | missing | open | record failure insert | job/run token provided |
| Dead Letter Status | open/resolved/retried | open | record failure update | same run token |
| Dead Letter Status | open | resolved | resolve | id exists |
| Dead Letter Status | open | retried | retry | only open allowed |
| Active Action | null/other | extension | extend action | action endpoint/batch |
| Active Action | null/other | release | release action | action endpoint/batch |
| Active Action | any | null | save with data changes | current status ready |

## Dead-Branch Mining
- Multi-signature branch in workflow advance is effectively dormant because required signatures are fixed to 1.
- `ValidationService` appears defined but not wired into active mutation endpoints.
- `AutoAcceptService` appears present without active runtime call sites.
- `ApiPolicyMatrix` acts as policy metadata/test artifact, not runtime request enforcement.
- Legacy status mappings (`approved`, `issued`) persist in UI/timeline display compatibility logic though canonical status handling is `ready/pending/released`.
- `convert-to-real` endpoint constructs repository without required constructor dependency, suggesting an untested/dead path.
- Import/email/save-import path diverges from primary import pipeline, indicating a legacy fork.
- Timeline display service performs redundant double sort.
- `get-current-state`/timeline controller mismatch on `active_action` indicates a latent branch-level behavior gap.

"No additional feature-level behavior is extractable from code structure."
