# WBGL Data Integrity Report

- Generated At: `2026-03-07T01:09:21+00:00`
- Driver: `pgsql`
- Status: **PASS**
- Fail violations: `0`
- Warn violations: `0`
- Strict warn mode: `OFF`

| Check ID | Severity | Status | Violations | Title |
|---|---|---|---:|---|
| `DECISION_ORPHAN_GUARANTEE` | FAIL | OK | 0 | guarantee_decisions must reference existing guarantees |
| `READY_REQUIRES_SUPPLIER_BANK` | FAIL | OK | 0 | ready decisions must have both supplier_id and bank_id |
| `RELEASED_REQUIRES_LOCK` | FAIL | OK | 0 | released decisions must stay locked |
| `ACTIVE_ACTION_DOMAIN` | FAIL | OK | 0 | active_action must stay within approved domain |
| `WORKFLOW_STEP_DOMAIN` | FAIL | OK | 0 | workflow_step must stay within approved domain |
| `DECISION_STATUS_DOMAIN` | FAIL | OK | 0 | decision status must stay within approved domain |
| `SIGNATURES_NON_NEGATIVE` | FAIL | OK | 0 | signatures_received must never be negative |
| `UNDO_STATUS_DOMAIN` | FAIL | OK | 0 | undo_requests status values must stay valid |
| `UNDO_ORPHAN_GUARANTEE` | FAIL | OK | 0 | undo_requests must reference existing guarantees |
| `HISTORY_ORPHAN_GUARANTEE` | FAIL | OK | 0 | guarantee_history must reference existing guarantees |
| `OCCURRENCE_ORPHAN_GUARANTEE` | FAIL | OK | 0 | guarantee_occurrences must reference existing guarantees |
| `OCCURRENCE_BATCH_IDENTIFIER_NOT_BLANK` | FAIL | OK | 0 | guarantee_occurrences batch_identifier must not be blank |
| `BATCH_PURITY_NO_MIX` | FAIL | OK | 0 | batch_identifier must not contain both test and real guarantees |
| `INTEGRATION_ARTIFACT_REAL_LEAK` | FAIL | OK | 0 | integration test artifacts must not stay classified as real guarantees |
| `GUARANTEE_MISSING_OCCURRENCE` | FAIL | OK | 0 | every guarantee must have at least one occurrence row |
| `DECISION_UNIQUE_PER_GUARANTEE` | FAIL | OK | 0 | guarantee_decisions must not contain duplicate rows per guarantee |
| `OPERATIONAL_COUNT_ARITHMETIC` | FAIL | OK | 0 | operational count equations must hold (total=open+released, open=ready+pending) |
| `TEST_FLAG_COUNT_ARITHMETIC` | FAIL | OK | 0 | test-flag partition must hold (total=real+test) |
| `ROLE_PERMISSION_ORPHANS` | FAIL | OK | 0 | role_permissions rows must reference existing roles and permissions |
| `USER_PERMISSION_ORPHANS` | FAIL | OK | 0 | user_permissions rows must reference existing users and permissions |
| `HISTORY_EMPTY_EVENT_DETAILS` | WARN | OK | 0 | history rows with blank event_details |
| `PRINT_EVENT_ORPHAN_GUARANTEE` | WARN | OK | 0 | print_events with non-null missing guarantee_id |
| `NOTIFICATION_EMPTY_RECIPIENT` | WARN | OK | 0 | notifications recipient_username must not be blank when explicitly provided |
| `TIMELINE_GENERIC_ACTOR_LABELS` | WARN | OK | 0 | timeline events should avoid generic actor labels |
| `NOTES_GENERIC_ACTOR_LABELS` | WARN | OK | 0 | notes should avoid generic actor labels |
| `ATTACHMENTS_GENERIC_ACTOR_LABELS` | WARN | OK | 0 | attachments should avoid generic actor labels |
| `SUSPECT_TEST_DATA_UNFLAGGED` | WARN | OK | 0 | records with strong test signatures must be marked is_test_data=1 |

