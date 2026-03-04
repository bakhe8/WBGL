# سجل التنفيذ المتتابع — WBGL

هذا السجل يُحدّث عبر `app/Scripts/sequential-execution.php`.

---

## 2026-03-04T00:00:00Z | P1-01
- المرحلة: P1 — تثبيت عقود API
- المهمة: إغلاق موجة توحيد العقود (A)
- الربط المرجعي: A-001, A-010, A-020, A-030, A-040, A-050, A-060, A-070, A-080, A-090, A-100, A-110, A-120, A-130, A-140, A-150, A-160, A-170, A-180, A-190, A-200, A-210, A-220, A-230, A-240, A-250, A-260, A-270, A-280, A-290, A-300, A-310, A-320, A-330, A-340, A-350
- الدليل: تم إغلاق موجة A بالكامل مع اختبارات Unit+Integration خضراء.
- الملفات المرجعية:
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
  - tests/Integration/EnterpriseApiFlowsTest.php

## 2026-03-04T00:00:00Z | P2-01
- المرحلة: P2 — تفكيك save-and-next
- المهمة: إكمال التفكيك المرحلي لخدمة save-and-next
- الربط المرجعي: B-010, B-020, B-030, B-040, B-050
- الدليل: تم تحويل save-and-next إلى orchestration service مرحليًا بدون تغيير سلوكي.
- الملفات المرجعية:
  - api/save-and-next.php
  - app/Services/SaveAndNextApplicationService.php

## 2026-03-04T00:00:00Z | P3-01
- المرحلة: P3 — استقرار قاعدة البيانات
- المهمة: تثبيت baseline migration وrehearsal
- الربط المرجعي: C-010
- الدليل: تم اعتماد baseline migration وتشغيل rehearsal ناجح.
- الملفات المرجعية:
  - database/migrations/20260225_000000_pgsql_baseline_core_schema.sql
  - app/Scripts/rehearse-migrations.php

## 2026-03-04T00:00:00Z | P4-01
- المرحلة: P4 — تنظيف الإرث
- المهمة: إزالة dead code الآمن
- الربط المرجعي: D-010
- الدليل: تم حذف dead code المؤكد بدون regressions.
- الملفات المرجعية:
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
  - tests/Integration/EnterpriseApiFlowsTest.php

## 2026-03-04T00:00:00Z | P5-01
- المرحلة: P5 — حراس الاستدامة
- المهمة: تفعيل الحراس الآلية للعقود والاستقرار
- الربط المرجعي: E-010, E-020
- الدليل: تم إضافة الحراس الآلية وتوحيد fail helper مركزيًا.
- الملفات المرجعية:
  - tests/Unit/ApiContractGuardWiringTest.php
  - api/_bootstrap.php

## 2026-03-04T01:02:45Z | P6-01 (START)
- المرحلة: P6 — إعادة ضبط حدود طبقة العرض
- المهمة: فصل بناء record HTML عن endpoint `get-record`
- الربط المرجعي: G-010
- الدليل: تم ترقية manifest إلى v1.1 وتفعيل الحلقة الجديدة مع تعيين P6-01 كخطوة نشطة.
- الملفات المرجعية:
  - Docs/WBGL-EXECUTION-SEQUENCE-AR.json
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - .github/workflows/change-gate.yml
## 2026-03-04T01:09:08+00:00 | P6-01
- المرحلة: P6 — إعادة ضبط حدود طبقة العرض
- المهمة: فصل بناء record HTML عن endpoint `get-record`
- الربط المرجعي: G-010
- الدليل: تم فصل تجميع ورندر get-record إلى خدمة GetRecordPresentationService مع إبقاء السلوك الخارجي ثابتًا واختبارات Unit+Integration خضراء.
- الملفات المرجعية:
  - api/get-record.php
  - app/Services/GetRecordPresentationService.php
  - tests/Integration/EnterpriseApiFlowsTest.php
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
- الخطوة التالية: P6-02

## 2026-03-04T01:12:40+00:00 | P6-02
- المرحلة: P6 — إعادة ضبط حدود طبقة العرض
- المهمة: فصل timeline/history rendering عن endpoints الخاصة بها
- الربط المرجعي: G-020
- الدليل: تم نقل منطق timeline/history read rendering إلى TimelineReadPresentationService وتحويل endpoints إلى adapters نحيفة مع الحفاظ على السلوك واجتياز Unit+Integration.
- الملفات المرجعية:
  - api/get-timeline.php
  - api/get-history-snapshot.php
  - app/Services/TimelineReadPresentationService.php
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
- الخطوة التالية: P7-01

## 2026-03-04T01:16:58+00:00 | P7-01
- المرحلة: P7 — صحة البيانات والمعاملات
- المهمة: توحيد transaction boundaries لعمليات lifecycle الحرجة
- الربط المرجعي: G-030
- الدليل: تم توحيد transaction boundaries عبر TransactionBoundary في مسارات lifecycle الحرجة مع حارس wiring واختبارات Unit+Integration خضراء.
- الملفات المرجعية:
  - app/Support/TransactionBoundary.php
  - api/extend.php
  - api/reduce.php
  - api/release.php
  - app/Services/SaveAndNextApplicationService.php
  - app/Services/UndoRequestService.php
  - tests/Unit/Services/TransactionBoundaryWiringTest.php
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
- الخطوة التالية: P7-02

## 2026-03-04T01:19:15+00:00 | P7-02
- المرحلة: P7 — صحة البيانات والمعاملات
- المهمة: إضافة فحص سلامة بيانات وتشغيله ضمن CI
- الربط المرجعي: G-040
- الدليل: تم إضافة data-integrity-check وربطه في CI بعد التكامل للتحقق من invariants القاعدية الحرجة مع نتائج خضراء.
- الملفات المرجعية:
  - app/Scripts/data-integrity-check.php
  - .github/workflows/ci.yml
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
- الخطوة التالية: P8-01

## 2026-03-04T01:34:42Z | P9-01 (START)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم ترقية manifest إلى v1.2 وتفعيل الخطوة الأولى للدورة الجديدة كـ in-progress.
- الملفات المرجعية:
  - Docs/WBGL-EXECUTION-SEQUENCE-AR.json
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md

## 2026-03-04T01:36:11Z | P9-01 (PROGRESS)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم نقل كتلة overview metrics من view إلى StatisticsDashboardService مع بقاء الاختبارات خضراء.
- الملفات المرجعية:
  - views/statistics.php
  - app/Services/StatisticsDashboardService.php
  - tests/Unit/Services/StatisticsDashboardWiringTest.php

## 2026-03-04T01:39:31Z | P9-01 (PROGRESS)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم نقل كتلة batch/suppliers/banks من `views/statistics.php` إلى `StatisticsDashboardService::fetchBatchAndSupplierBlocks` مع نجاح Unit + Integration.
- الملفات المرجعية:
  - views/statistics.php
  - app/Services/StatisticsDashboardService.php
  - tests/Unit/Services/StatisticsDashboardWiringTest.php

## 2026-03-04T01:41:27Z | P9-01 (PROGRESS)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم نقل كتلة time/performance من `views/statistics.php` إلى `StatisticsDashboardService::fetchTimePerformanceBlocks` مع بقاء الاختبارات خضراء.
- الملفات المرجعية:
  - views/statistics.php
  - app/Services/StatisticsDashboardService.php
  - tests/Unit/Services/StatisticsDashboardWiringTest.php

## 2026-03-04T01:43:14Z | P9-01 (PROGRESS)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم نقل كتلة expiration/actions من `views/statistics.php` إلى `StatisticsDashboardService::fetchExpirationActionBlocks` مع نجاح Unit + Integration.
- الملفات المرجعية:
  - views/statistics.php
  - app/Services/StatisticsDashboardService.php
  - tests/Unit/Services/StatisticsDashboardWiringTest.php

## 2026-03-04T01:19:44+00:00 | P8-01
- المرحلة: P8 — حوكمة التنفيذ v1.1
- المهمة: إغلاق الأدلة والتوثيق التشغيلي للمرحلة الجديدة
- الربط المرجعي: G-050
- الدليل: تم إغلاق توثيق v1.1 وتحديث الخطة/الحالة/السجل مع اكتمال جميع خطوات التسلسل بدون blocked steps.
- الملفات المرجعية:
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - Docs/WBGL-EXECUTION-LOG-AR.md
- الحالة: جميع الخطوات مكتملة.

## 2026-03-04T01:49:25+00:00 | P9-01
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في statistics إلى خدمة لوحة مؤشرات
- الربط المرجعي: H-010
- الدليل: تم فصل منطق البيانات بالكامل من views/statistics.php إلى StatisticsDashboardService (overview, batch/suppliers, time/performance, expiration/actions, AI/ML, financial/types, urgent list) مع بقاء الصفحة adapter للعرض فقط واجتياز Unit+Integration+guard.
- الملفات المرجعية:
  - views/statistics.php
  - app/Services/StatisticsDashboardService.php
  - tests/Unit/Services/StatisticsDashboardWiringTest.php
  - storage/logs/phpunit-enterprise.xml
- الخطوة التالية: P9-02

## 2026-03-04T01:49:25+00:00 | P9-02 (START)
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في settings إلى خدمة قراءة/حوكمة
- الربط المرجعي: H-020
- الدليل: تم بدء الخطوة تلقائيًا بعد إغلاق P9-01 عبر `sequential-execution complete`.
- الملفات المرجعية:
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md

## 2026-03-04T01:51:53+00:00 | P9-02
- المرحلة: P9 — تفكيك شاشات العرض الثقيلة
- المهمة: فصل منطق البيانات في settings إلى خدمة قراءة/حوكمة
- الربط المرجعي: H-020
- الدليل: تم فصل bootstrap logic في views/settings.php إلى SettingsDashboardService (settings snapshot + locale/direction + current time label) وأصبحت الصفحة تعتمد view-model جاهز مع اختبارات Unit+Integration+guard خضراء.
- الملفات المرجعية:
  - views/settings.php
  - app/Services/SettingsDashboardService.php
  - tests/Unit/Services/SettingsDashboardWiringTest.php
  - storage/logs/phpunit-enterprise.xml
- الخطوة التالية: P10-01

## 2026-03-04T01:55:04+00:00 | P10-01
- المرحلة: P10 — حوكمة الصلاحيات
- المهمة: تقرير دوري لانحراف الصلاحيات وربطه في CI
- الربط المرجعي: H-030
- الدليل: تم إنشاء permissions-drift-report وربطه في CI مع رفع artifacts (JSON/MD) للتحقق الدوري من انحراف role-permission matrix؛ التشغيل المحلي أظهر PASS بدون missing/unknown/orphans/duplicates.
- الملفات المرجعية:
  - app/Scripts/permissions-drift-report.php
  - .github/workflows/ci.yml
  - Docs/PERMISSIONS-DRIFT-REPORT-AR.md
  - storage/logs/permissions-drift-report.json
- الخطوة التالية: P11-01

## 2026-03-04T01:58:24+00:00 | P11-01
- المرحلة: P11 — تعميق سلامة البيانات
- المهمة: توسيع data-integrity-check وإضافة artifact تفصيلي
- الربط المرجعي: H-040
- الدليل: تم توسيع data-integrity-check بإضافة invariants إضافية (status/signatures/orphans/role-user permission links) وإخراج artifact JSON+Markdown وربطه في CI؛ كما تم مواءمة فحص notifications مع تصميم الإشعارات العامة ليصبح الناتج النهائي محليًا: Fail=0 وWarn=0.
- الملفات المرجعية:
  - app/Scripts/data-integrity-check.php
  - .github/workflows/ci.yml
  - Docs/DATA-INTEGRITY-REPORT-AR.md
  - storage/logs/data-integrity-report.json
- الخطوة التالية: P12-01

## 2026-03-04T01:59:13+00:00 | P12-01
- المرحلة: P12 — إغلاق دورة v1.2
- المهمة: إغلاق التوثيق التشغيلي وإثبات الاستقرار
- الربط المرجعي: H-050
- الدليل: تم إغلاق دورة v1.2 توثيقيًا بعد إنجاز H-010..H-040 مع تثبيت الأدلة في plan/state/log واعتماد artifacts الحوكمة (permissions drift + data integrity) ضمن CI.
- الملفات المرجعية:
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - Docs/WBGL-EXECUTION-LOG-AR.md
  - .github/workflows/ci.yml
- الحالة: جميع الخطوات مكتملة.

## 2026-03-04T02:12:21+00:00 | P13-01
- المرحلة: P13 — مواءمة المهاجرات مع PostgreSQL بشكل صارم
- المهمة: تنظيف/توحيد مigrations الجداول التشغيلية المتأخرة على صيغة PostgreSQL
- الربط المرجعي: I-010
- الدليل: تم توحيد المهاجرات التشغيلية المتأخرة المستهدفة على صيغة PostgreSQL الصريحة (BIGSERIAL/TIMESTAMP) مع إضافة حارس Unit يمنع عودة AUTOINCREMENT؛ والتحقق نجح (dry-run/status/unit/integration/guard).
- الملفات المرجعية:
  - database/migrations/20260226_000004_create_notifications_table.sql
  - database/migrations/20260226_000007_create_print_events_table.sql
  - database/migrations/20260226_000010_create_scheduler_dead_letters_table.sql
  - tests/Unit/PostgresLateMigrationsWiringTest.php
  - storage/logs/phpunit-enterprise.xml
- الخطوة التالية: P14-01

## 2026-03-04T02:14:33+00:00 | P14-01
- المرحلة: P14 — تقوية اختبارات حوكمة الصلاحيات
- المهمة: إضافة Contract/Wiring tests لمسارات الصلاحيات الحرجة وربطها مع drift reports
- الربط المرجعي: I-020
- الدليل: تم تقوية عقد الصلاحيات الحرجة بإضافة اختبار ثابت للمسارات الحساسة وتوسيع permissions-drift-report ليُخرج critical_endpoint_contract ويُفشل عند mismatch؛ النتائج خضراء (Unit+Integration+guard) مع mismatches=0.
- الملفات المرجعية:
  - app/Scripts/permissions-drift-report.php
  - tests/Unit/PermissionCriticalEndpointContractTest.php
  - tests/Unit/PermissionsDriftReportWiringTest.php
  - Docs/PERMISSIONS-DRIFT-REPORT-AR.md
  - storage/logs/permissions-drift-report.json
- الخطوة التالية: P15-01

## 2026-03-04T02:17:02+00:00 | P15-01
- المرحلة: P15 — تحسين الاعتمادية التشغيلية
- المهمة: تعزيز فحوصات integrity/drift بـ strict mode اختياري وتقارير CI أوضح
- الربط المرجعي: I-030
- الدليل: تم تحسين وضوح تقارير الحوكمة عبر تشغيل موحد في CI مع strict mode اختياري (WBGL_GOVERNANCE_STRICT)، وإضافة governance-summary.md كملخص artifact؛ جميع الفحوصات خضراء والتقارير PASS.
- الملفات المرجعية:
  - .github/workflows/ci.yml
  - app/Scripts/governance-summary.php
  - Docs/GOVERNANCE-CI-MODE-AR.md
  - storage/logs/governance-summary.md
  - storage/logs/permissions-drift-report.json
  - storage/logs/data-integrity-report.json
- الخطوة التالية: P16-01

## 2026-03-04T02:17:46+00:00 | P16-01
- المرحلة: P16 — إغلاق دورة v1.3
- المهمة: إغلاق توثيق وتشغيل دورة v1.3 بالأدلة النهائية
- الربط المرجعي: I-040
- الدليل: تم إغلاق دورة v1.3 بالكامل بعد تنفيذ I-010..I-030 وتثبيت الأدلة في plan/state/log؛ كما تم اعتماد نموذج حوكمة CI المحسن (strict optional + governance summary) مع تحقق نهائي أخضر.
- الملفات المرجعية:
  - Docs/WBGL-ACTIONABLE-WORKFLOW-REALIGNMENT-PLAN-AR.md
  - Docs/WBGL-EXECUTION-STATE-AR.json
  - Docs/WBGL-EXECUTION-LOG-AR.md
  - .github/workflows/ci.yml
  - Docs/GOVERNANCE-CI-MODE-AR.md
  - storage/logs/governance-summary.md
- الحالة: جميع الخطوات مكتملة.

