# WBGL Observability Runbook (Wave-3 Seed)

## Scope

This runbook documents the initial observability baseline delivered in WBGL:

1. Request correlation via `X-Request-Id` on API responses.
2. Privileged operational metrics snapshot endpoint.
3. Privileged operational alerts endpoint with threshold-based rules.
4. Maintenance dashboard cards/alerts wired to metrics snapshots.
5. CI + integration coverage for metrics/alerts/permission guard behavior.

## API Contracts

## `GET /api/metrics.php`

- Auth: `wbgl_api_require_permission('manage_users')`
- Response:

```json
{
  "success": true,
  "data": {
    "generated_at": "2026-02-26T23:12:14+03:00",
    "counters": {
      "open_dead_letters": 0,
      "pending_undo_requests": 0,
      "approved_undo_requests": 0,
      "unread_notifications": 0,
      "print_events_24h": 0,
      "api_access_denied_24h": 0,
      "scheduler_failures_24h": 0
    },
    "scheduler": {
      "latest": {
        "id": 123,
        "run_token": "token",
        "job_name": "notify-expiry.php",
        "status": "success",
        "exit_code": 0,
        "duration_ms": 154,
        "started_at": "2026-02-26 20:00:00",
        "finished_at": "2026-02-26 20:00:01"
      }
    }
  }
}
```

## Request Correlation

- Every API request now emits `X-Request-Id`.
- If client sends a valid `X-Request-Id`, WBGL keeps it.
- Otherwise WBGL generates one (`wbgl_<random>`).
- Auth failures include `request_id` in JSON error and in `audit_trail_events.details_json`.

## `GET /api/alerts.php`

- Auth: `wbgl_api_require_permission('manage_users')`
- Response includes:
1. `metrics` snapshot.
2. `alerts.summary` (`total_rules`, `triggered`, `healthy`).
3. `alerts.alerts[]` with rule status (`ok` / `triggered`).

## Dashboard Wiring

- `views/maintenance.php` renders:
1. Counters cards (dead-letters, scheduler failures, denied API, unread notifications, pending undo).
2. Active alerts list from `OperationalAlertService`.

## Validation Checklist

1. `curl -i /api/me.php` returns `X-Request-Id`.
2. `GET /api/metrics.php` with non-privileged token returns `403`.
3. `GET /api/metrics.php` with privileged token returns `200` and `counters` object.
4. `GET /api/alerts.php` with non-privileged token returns `403`.
5. `GET /api/alerts.php` with privileged token returns `200` and `alerts.summary`.
6. `tests/Integration/EnterpriseApiFlowsTest.php` passes metrics/alerts/request-id assertions.

## Next Step (Wave-3 Expansion)

1. Add dashboard views over metrics snapshots.
2. Add threshold-based alert rules (dead-letter growth, failure spikes, slow jobs).
3. Add durable time-series sink for historical trend tracking.
