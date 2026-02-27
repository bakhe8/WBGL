# WBGL P0 Execution Baseline (No Deadlines)

Last update: 2026-02-26

This file locks the active execution mode for WBGL improvement work.

Anti-reinvention governance reference:
- `docs/NO_REINVENTION_POLICY.md`

Enterprise transition reference:
- `docs/WBGL_ENTERPRISE_GRADE_EXECUTION_PLAN.md`

## Approved Operating Decisions

1. Priority-first order: `P0 -> P1 -> P2` (no date commitments).
2. Browser printing only (no PDF archival scope in current phase).
3. Single-account governance flow:
   - PR only
   - required `Change Gate`
   - no external mandatory approver
4. Progressive delivery:
   - small change sets
   - verify after each batch
   - no broad refactors in one step
5. Release-readiness gate is mandatory:
   - `docs/WBGL_EXECUTION_LOOP_STATUS.json` must be refreshed on sensitive changes
   - readiness checks must stay green (`next_batch=[]`, guarded API baseline, migrations pending=0, scheduler/history/print/config/ux ready)
6. Enterprise-Grade track runs in staged waves:
   - Wave-1 Security baseline
   - Wave-2 Integration/E2E
   - Wave-3 Observability
   - Wave-4 DB Cutover
   - Wave-5 SoD/Compliance

## P0 Workstream (Active)

1. API protection hardening (central bootstrap + endpoint guards).
2. Login rate limiting on `api/login.php` (5 attempts / 1 minute).
3. Undo governance foundation (undo_requests entity + submit/approve/reject/execute API).
4. Migration discipline (versioned SQL migrations + status/apply scripts).
5. Test baseline upgrade (replace smoke-only coverage with real unit/service checks).

## Commands

```bash
# DB migration status
php maint/migration-status.php

# DB migration dry-run
php maint/migrate.php --dry-run

# Apply pending migrations
php maint/migrate.php

# Run the roadmap execution loop status refresh
php maint/run-execution-loop.php

# Run focused unit tests
vendor/bin/phpunit tests/Unit/ApiBootstrapTest.php
vendor/bin/phpunit tests/Unit/Services/TimelineRecorderPatchTest.php

# Release-critical flow acceptance wiring checks
vendor/bin/phpunit tests/Unit/ReleaseCriticalFlowsWiringTest.php
vendor/bin/phpunit tests/Unit/UxA11yWiringTest.php
```
