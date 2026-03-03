<?php

/**
 * Partial: Timeline Section
 * Enhanced timeline using TimelineHelper
 * Required variables: $timeline (array of events)
 */

require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

if (!isset($timeline)) {
    $timeline = [];
}

$eventCount = count($timeline);
?>

<aside class="timeline-panel" id="timeline-section">
    <header class="timeline-header mb-2 relative">
        <div class="timeline-title">
            <span>⏲️</span>
            <span data-i18n="timeline.header.title">Timeline</span>
        </div>
        <span class="timeline-count cursor-help" data-event-count="<?= $eventCount ?>">
            <span><?= $eventCount ?></span>
            <span data-i18n="timeline.header.event_label">حدث</span>
        </span>
    </header>
    <div class="timeline-body timeline-body--compact h-full overflow-y-auto">
        <div class="timeline-list">

            <?php if (empty($timeline)): ?>
                <div class="text-center text-gray-400 text-sm py-8">
                    <span data-i18n="timeline.empty.no_events">لا توجد أحداث في التاريخ</span>
                </div>
            <?php else: ?>
                <?php foreach ($timeline as $index => $event):
                    // Use TimelineRecorder for labels and icons
                    $eventLabel = \App\Services\TimelineRecorder::getEventDisplayLabel($event);
                    $eventIcon = \App\Services\TimelineRecorder::getEventIcon($event);
                    $eventLabelKeyMap = [
                        'استيراد' => 'timeline.event_label.import',
                        'Import' => 'timeline.event_label.import',
                        'استيراد مكرر' => 'timeline.event_label.reimport',
                        'Reimport' => 'timeline.event_label.reimport',
                        'تطابق تلقائي' => 'timeline.event_label.auto_match',
                        'Auto match' => 'timeline.event_label.auto_match',
                        'تطابق يدوي' => 'timeline.event_label.manual_match',
                        'Manual match' => 'timeline.event_label.manual_match',
                        'اعتماد' => 'timeline.event_label.approval',
                        'Approval' => 'timeline.event_label.approval',
                        'تمديد' => 'timeline.event_label.extend',
                        'Extend' => 'timeline.event_label.extend',
                        'تخفيض' => 'timeline.event_label.reduce',
                        'Reduce' => 'timeline.event_label.reduce',
                        'إفراج' => 'timeline.event_label.release',
                        'Release' => 'timeline.event_label.release',
                        'تغيير حالة' => 'timeline.event_label.status_change',
                        'Status change' => 'timeline.event_label.status_change',
                        'إعادة فتح' => 'timeline.event_label.reopen',
                        'Reopen' => 'timeline.event_label.reopen',
                        'تصحيح بيانات' => 'timeline.event_label.correction',
                        'Correction' => 'timeline.event_label.correction',
                        'تحديث المرحلة' => 'timeline.event_label.stage_update',
                        'Stage update' => 'timeline.event_label.stage_update',
                        'تم التدقيق' => 'timeline.event_label.audited',
                        'Audited' => 'timeline.event_label.audited',
                        'تم التحليل' => 'timeline.event_label.analyzed',
                        'Analyzed' => 'timeline.event_label.analyzed',
                        'تم الإشراف' => 'timeline.event_label.supervised',
                        'Supervised' => 'timeline.event_label.supervised',
                        'تم الاعتماد' => 'timeline.event_label.approved',
                        'Approved' => 'timeline.event_label.approved',
                        'تم التوقيع' => 'timeline.event_label.signed',
                        'Signed' => 'timeline.event_label.signed',
                        'تحديث مسار العمل' => 'timeline.event_label.workflow_advance',
                        'Workflow advance' => 'timeline.event_label.workflow_advance',
                        'اختيار القرار' => 'timeline.event_label.decision_select',
                        'Decision select' => 'timeline.event_label.decision_select',
                        'تحديث' => 'timeline.event_label.update',
                        'Update' => 'timeline.event_label.update',
                    ];
                    $eventLabelKey = $eventLabelKeyMap[$eventLabel] ?? null;
                    $eventId = (int)($event['event_id'] ?? $event['id'] ?? 0);
                    $eventType = (string)($event['event_type'] ?? '');
                    $eventSubtype = (string)($event['event_subtype'] ?? '');

                    // Parse event_details JSON
                    $eventDetailsRaw = $event['event_details'] ?? null;
                    $details = $eventDetailsRaw ? json_decode($eventDetailsRaw, true) : [];
                    $changes = $details['changes'] ?? [];
                    $statusChange = $details['status_change'] ?? null;
                    $snapshotPayload = htmlspecialchars($event['snapshot_data'] ?? '{}', ENT_QUOTES, 'UTF-8');
                    $eventDetailsPayload = htmlspecialchars($event['event_details'] ?? '{}', ENT_QUOTES, 'UTF-8');
                    $letterSnapshotPayload = htmlspecialchars($event['letter_snapshot'] ?? 'null', ENT_QUOTES, 'UTF-8');

                    // Tone mapping based on event type/subtype (locale-safe)
                    $tone = 'muted';
                    if ($eventType === 'import') {
                        $tone = 'slate';
                    }
                    if ($eventType === 'reimport' || str_starts_with($eventSubtype, 'duplicate_')) {
                        $tone = 'warning';
                    }
                    if (
                        $eventType === 'auto_matched' ||
                        in_array($eventSubtype, ['ai_match', 'auto_match', 'bank_match', 'bank_change'], true)
                    ) {
                        $tone = 'info';
                    }
                    if (in_array($eventSubtype, ['manual_edit', 'supplier_change'], true)) {
                        $tone = 'success';
                    }
                    if ($eventSubtype === 'extension') {
                        $tone = 'amber';
                    }
                    if ($eventSubtype === 'reduction') {
                        $tone = 'violet';
                    }
                    if (
                        $eventSubtype === 'release' ||
                        in_array($eventType, ['release', 'released'], true)
                    ) {
                        $tone = 'danger';
                    }

                    $isLatest = $index === 0; // Latest event (current state)
                ?>
                    <div class="timeline-event-wrapper timeline-event-wrapper--interactive"
                        data-event-id="<?= $eventId ?>"
                        data-event-type="<?= $eventType !== '' ? $eventType : 'unknown' ?>"
                        data-event-subtype="<?= $eventSubtype ?>"
                        data-snapshot='<?= $snapshotPayload ?>'
                        data-event-details='<?= $eventDetailsPayload ?>'
                        data-letter-snapshot='<?= $letterSnapshotPayload ?>'
                        data-is-latest="<?= $isLatest ? '1' : '0' ?>">

                        <!-- Timeline Connector -->
                        <?php if ($index < count($timeline) - 1): ?>
                            <div class="timeline-event-connector"></div>
                        <?php endif; ?>

                        <?php
                        $hasSnapshot = !empty($event['snapshot_data']) && $event['snapshot_data'] !== '{}' && $event['snapshot_data'] !== 'null';
                        $hasAnchor = !empty($event['anchor_snapshot']) && $event['anchor_snapshot'] !== '{}' && $event['anchor_snapshot'] !== 'null';
                        ?>
                        <!-- Dot -->
                        <div class="timeline-dot timeline-dot--event timeline-tone--<?= htmlspecialchars($tone) ?> <?= ($hasSnapshot || $hasAnchor) ? 'timeline-dot-anchor' : '' ?>"></div>

                        <!-- Event Card -->
                        <div class="timeline-event-card timeline-event-card--interactive timeline-tone--<?= htmlspecialchars($tone) ?>">

                            <!-- Event Header -->
                            <div class="timeline-event-header">
                                <div class="timeline-event-title-group">
                                    <span class="timeline-event-icon"><?= $eventIcon ?></span>
                                    <span class="timeline-event-label timeline-tone-text"<?= $eventLabelKey ? ' data-i18n="' . htmlspecialchars($eventLabelKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                        <?= htmlspecialchars($eventLabel) ?>
                                    </span>
                                </div>
                                <?php if ($isLatest): ?>
                                    <span class="timeline-latest-badge" data-i18n="timeline.badges.latest_event">آخر حدث</span>
                                <?php endif; ?>
                            </div>

                            <!-- Event Changes Details -->

                            <?php
                            // 🆕 Show import source for import/reimport events
                            if (in_array($eventType, ['import', 'reimport'], true)):
                                $sourceLabels = [
                                    'excel' => ['label_key' => 'timeline.source.excel', 'tone' => 'info'],
                                    'smart_paste' => ['label_key' => 'timeline.source.smart_paste', 'tone' => 'violet'],
                                    'smart_paste_multi' => ['label_key' => 'timeline.source.smart_paste_multi', 'tone' => 'violet'],
                                    'duplicate_smart_paste' => ['label_key' => 'timeline.source.duplicate_smart_paste', 'tone' => 'warning'],
                                    'duplicate_excel' => ['label_key' => 'timeline.source.duplicate_excel', 'tone' => 'warning'],
                                    'manual' => ['label_key' => 'timeline.source.manual', 'tone' => 'success']
                                ];
                                // Fix: Check if event_subtype exists, if not check event_details
                                $source = $eventSubtype;
                                if (!$source) {
                                    $details = json_decode($event['event_details'] ?? '{}', true);
                                    $source = $details['source'] ?? 'excel';
                                }

                                $sourceInfo = $sourceLabels[$source] ?? ['label_key' => 'timeline.source.file_import', 'tone' => 'muted'];
                            ?>
                                <div class="timeline-source-badge timeline-source--<?= htmlspecialchars($sourceInfo['tone']) ?>">
                                    <span data-i18n="<?= htmlspecialchars($sourceInfo['label_key']) ?>"></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($changes)): ?>
                                <div class="timeline-change-block">
                                    <?php
                                    // STRICT RENDERING RULE: Only show fields allowed for this event type
                                    $allowedFields = ['supplier_id', 'bank_id', 'amount', 'expiry_date', 'status', 'workflow_step'];
                                    if ($eventSubtype === 'extension') {
                                        $allowedFields = ['expiry_date'];
                                    } elseif ($eventSubtype === 'reduction') {
                                        $allowedFields = ['amount'];
                                    } elseif (in_array($eventSubtype, ['supplier_change', 'manual_edit'], true)) {
                                        $allowedFields = ['supplier_id', 'bank_id'];
                                    } elseif (
                                        $eventType === 'auto_matched' ||
                                        in_array($eventSubtype, ['ai_match', 'auto_match', 'bank_match', 'bank_change'], true)
                                    ) {
                                        $allowedFields = ['bank_name', 'supplier_name', 'supplier_id', 'bank_id'];
                                    } elseif (
                                        $eventSubtype === 'release' ||
                                        in_array($eventType, ['release', 'released'], true)
                                    ) {
                                        $allowedFields = ['status'];
                                    } elseif (
                                        $eventSubtype === 'workflow_advance' ||
                                        $eventType === 'status_change'
                                    ) {
                                        $allowedFields = ['workflow_step', 'signatures_received', 'status'];
                                    } elseif ($eventType === 'modified' && $eventSubtype === '') {
                                        $allowedFields = [];
                                    }

                                    // Filter changes
                                    $visibleChanges = array_filter($changes, function ($change) use ($allowedFields) {
                                        return in_array($change['field'], $allowedFields);
                                    });
                                    ?>

                                    <?php foreach ($visibleChanges as $change): ?>
                                        <?php
                                        $fieldLabelKeys = [
                                            'supplier_id' => 'timeline.fields.supplier',
                                            'bank_id' => 'timeline.fields.bank',
                                            'bank_name' => 'timeline.fields.bank',
                                            'supplier_name' => 'timeline.fields.supplier',
                                            'amount' => 'timeline.fields.amount',
                                            'expiry_date' => 'timeline.fields.expiry_date',
                                            'status' => 'timeline.fields.status',
                                            'workflow_step' => 'timeline.fields.workflow_step'
                                        ];
                                        $fieldLabelKey = $fieldLabelKeys[$change['field']] ?? '';
                                        $fieldLabelFallback = $change['field'];
                                        ?>

                                        <div class="timeline-change-row">
                                            <strong class="timeline-change-label">
                                                • <span data-i18n="<?= htmlspecialchars($fieldLabelKey) ?>"><?= htmlspecialchars($fieldLabelFallback) ?></span>:
                                            </strong>

                                            <?php if ($change['field'] === 'supplier_id' || $change['field'] === 'bank_id'): ?>
                                                <?php
                                                $oldName = $change['old_value']['name'] ?? null;
                                                $newName = $change['new_value']['name'] ?? null;
                                                ?>

                                                <?php if ($oldName && trim((string)$oldName) !== ''): ?>
                                                    <span class="timeline-change-old">
                                                        <?= htmlspecialchars($oldName) ?>
                                                    </span>
                                                    <span class="timeline-change-arrow">→</span>
                                                <?php endif; ?>

                                                <span class="timeline-change-new">
                                                    <?= htmlspecialchars($newName ?? '') ?>
                                                </span>

                                                <?php if (isset($change['confidence'])): ?>
                                                    <span class="timeline-change-confidence">
                                                        (<?= round($change['confidence']) ?>%)
                                                    </span>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <?php
                                                $oldVal = $change['old_value'] ?? null;
                                                $newVal = $change['new_value'] ?? null;

                                                // Map 'approved' to 'ready' for display (backward compatibility)
                                                if ($change['field'] === 'status') {
                                                    if ($oldVal === 'approved') $oldVal = 'ready';
                                                    if ($newVal === 'approved') $newVal = 'ready';
                                                }
                                                ?>

                                                <?php if ($oldVal): ?>
                                                    <span class="timeline-change-old">
                                                        <?= htmlspecialchars($oldVal) ?>
                                                    </span>
                                                    <span class="timeline-change-arrow">→</span>
                                                <?php endif; ?>

                                                <span class="timeline-change-new">
                                                    <?= htmlspecialchars($newVal ?? '') ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($statusChange): ?>
                                        <div class="timeline-status-row">
                                            <strong class="timeline-change-label">• <span data-i18n="timeline.status.label">الحالة</span>:</strong>
                                            <span class="timeline-change-new">
                                                <?php
                                                // Map old 'approved' to 'ready' for display
                                                $displayStatus = ($statusChange === 'approved') ? 'ready' : $statusChange;
                                                echo htmlspecialchars($displayStatus);
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($event['change_reason'])): ?>
                                <!-- Action event (extension/reduction/release) with formatted description -->
                                <div class="timeline-change-block">
                                    <?= $event['change_reason'] /* HTML formatted */ ?>
                                </div>
                            <?php endif; ?>




                            <!-- Date and User -->
                            <div class="timeline-event-meta">
                                <span><?= htmlspecialchars($event['created_at'] ?? '') ?></span>
                                <?php
                                $displayCreatorRaw = trim((string)($event['created_by'] ?? ''));
                                if ($displayCreatorRaw === '') {
                                    $displayCreatorRaw = 'system';
                                }

                                // Map icons
                                $icon = '👤';
                                $actorLower = strtolower($displayCreatorRaw);
                                $isSystemActor = str_contains($actorLower, 'system') || str_contains($actorLower, 'bot');
                                if ($isSystemActor) {
                                    $icon = '🤖';
                                }
                                $actorKeyMap = [
                                    'system' => 'timeline.actor.system',
                                    'النظام' => 'timeline.actor.system',
                                    'بواسطة النظام' => 'timeline.actor.system',
                                    'user' => 'timeline.actor.user',
                                    'web_user' => 'timeline.actor.user',
                                    'بواسطة المستخدم' => 'timeline.actor.user',
                                ];
                                $actorKey = $actorKeyMap[$displayCreatorRaw] ?? null;
                                ?>
                                <span class="timeline-event-user">
                                    <?= $icon ?>
                                    <?php if ($actorKey): ?>
                                        <span data-i18n="<?= htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($displayCreatorRaw) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($displayCreatorRaw) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <!-- Click hint -->
                            <div class="timeline-event-hint">
                                <?php if ($isLatest): ?>
                                    <span data-i18n="timeline.hints.current_state">👁️ انقر لعرض الحالة الحالية</span>
                                <?php else: ?>
                                    <span data-i18n="timeline.hints.before_event">🕐 انقر لعرض الحالة قبل هذا الحدث</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>
