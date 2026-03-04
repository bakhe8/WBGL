# وضع الحوكمة في CI (Governance Mode)

## الهدف
توحيد تشغيل تقارير الحوكمة (`permissions drift` + `data integrity`) داخل CI مع خيار تشغيل صارم عند الحاجة.

## المتغير المعتمد
- `WBGL_GOVERNANCE_STRICT`
  - القيمة `0` (أو فارغة): تشغيل طبيعي.
  - القيمة `1`: تشغيل صارم.

## السلوك
1. عند الوضع الطبيعي:
   - `data-integrity-check` بدون `--strict-warn`.
   - `permissions-drift-report` بدون `--strict`.
2. عند الوضع الصارم (`WBGL_GOVERNANCE_STRICT=1`):
   - `data-integrity-check --strict-warn`
   - `permissions-drift-report --strict`

## المخرجات
يتم رفع Artifact موحد باسم:
- `wbgl-governance-artifacts`

ويشمل:
- `storage/logs/data-integrity-report.json`
- `storage/logs/data-integrity-report.md`
- `storage/logs/permissions-drift-report.json`
- `storage/logs/permissions-drift-report.md`
- `storage/logs/governance-summary.md`
