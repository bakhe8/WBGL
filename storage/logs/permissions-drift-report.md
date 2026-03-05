# WBGL Permissions Drift Report

- Generated At: `2026-03-05T18:16:41+00:00`
- Driver: `pgsql`
- Status: **PASS**

## Summary

| Metric | Value |
|---|---:|
| DB permissions | 52 |
| Expected permissions | 52 |
| Missing in DB | 0 |
| Unknown in code | 0 |
| Duplicate permission slugs | 0 |
| Orphan role_permissions rows | 0 |
| Roles without permissions | 0 |
| Critical endpoint contract mismatches | 0 |

## Missing In DB

- None

## Unknown In Code

- None

## Duplicate Permission Slugs

- None

## Roles Without Permissions

- None

## Role Permission Matrix

- **المفوض بالتوقيع**: `attachments_upload`, `attachments_view`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `sign_letters`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`
- **محلل ضمانات**: `analyze_guarantee`, `attachments_upload`, `attachments_view`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`
- **مدخل بيانات**: `attachments_upload`, `attachments_view`, `bank_manage`, `guarantee_extend`, `guarantee_reduce`, `guarantee_release`, `guarantee_save`, `import_banks`, `import_commit_batch`, `import_convert_to_real`, `import_email`, `import_excel`, `import_matching_overrides`, `import_paste`, `import_suppliers`, `manage_data`, `manual_entry`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `supplier_manage`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`, `ui_full_filters_view`
- **مدقق بيانات**: `attachments_upload`, `attachments_view`, `audit_data`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`
- **مدير معتمد**: `approve_decision`, `attachments_upload`, `attachments_view`, `break_glass_override`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `reopen_batch`, `reopen_guarantee`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`
- **مشرف ضمانات**: `attachments_upload`, `attachments_view`, `navigation_view_batches`, `navigation_view_statistics`, `notes_create`, `notes_view`, `reopen_batch`, `reopen_guarantee`, `supervise_analysis`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`
- **مطور النظام**: `alerts_view`, `analyze_guarantee`, `approve_decision`, `attachments_upload`, `attachments_view`, `audit_data`, `bank_manage`, `break_glass_override`, `guarantee_extend`, `guarantee_reduce`, `guarantee_release`, `guarantee_save`, `import_banks`, `import_commit_batch`, `import_convert_to_real`, `import_email`, `import_excel`, `import_matching_overrides`, `import_paste`, `import_suppliers`, `manage_data`, `manage_roles`, `manage_users`, `manual_entry`, `metrics_view`, `navigation_view_batches`, `navigation_view_maintenance`, `navigation_view_settings`, `navigation_view_statistics`, `navigation_view_users`, `notes_create`, `notes_view`, `reopen_batch`, `reopen_guarantee`, `roles_create`, `roles_delete`, `roles_update`, `settings_audit_view`, `sign_letters`, `supervise_analysis`, `supplier_manage`, `timeline_export`, `timeline_view`, `ui_change_direction`, `ui_change_language`, `ui_change_theme`, `ui_full_filters_view`, `users_create`, `users_delete`, `users_manage_overrides`, `users_update`

## Critical Endpoint Contract

| Endpoint | Expected | Configured | Status |
|---|---|---|---|
| `api/save-and-next.php` | `guarantee_save` | `guarantee_save` | `OK` |
| `api/update-guarantee.php` | `guarantee_save` | `guarantee_save` | `OK` |
| `api/extend.php` | `guarantee_extend` | `guarantee_extend` | `OK` |
| `api/reduce.php` | `guarantee_reduce` | `guarantee_reduce` | `OK` |
| `api/release.php` | `guarantee_release` | `guarantee_release` | `OK` |
| `api/undo-requests.php` | `manage_data` | `manage_data` | `OK` |
| `api/settings.php` | `manage_users` | `manage_users` | `OK` |
| `api/users/list.php` | `manage_users` | `manage_users` | `OK` |
| `api/roles/create.php` | `manage_roles` | `manage_roles` | `OK` |

