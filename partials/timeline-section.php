<?php

declare(strict_types=1);

use App\Services\TimelinePresentationNormalizer;

/**
 * Partial: Timeline Section
 * Required variable: $timeline (array of events from TimelineDisplayService)
 */

require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

if (!isset($timeline) || !is_array($timeline)) {
    $timeline = [];
}

$eventCount = count($timeline);
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

$fieldLabelKeys = [
    'supplier_id' => 'timeline.fields.supplier',
    'supplier_name' => 'timeline.fields.supplier',
    'bank_id' => 'timeline.fields.bank',
    'bank_name' => 'timeline.fields.bank',
    'amount' => 'timeline.fields.amount',
    'expiry_date' => 'timeline.fields.expiry_date',
    'status' => 'timeline.fields.status',
    'workflow_step' => 'timeline.fields.workflow_step',
    'active_action' => 'timeline.fields.active_action',
    'signatures_received' => 'timeline.fields.signatures_received',
];

?>

<aside class="timeline-panel" id="timeline-section">
    <header class="timeline-header mb-2 relative">
        <div class="timeline-title">
            <span>⏲️</span>
            <span data-i18n="timeline.header.title">Timeline</span>
        </div>
        <span class="timeline-count cursor-help" data-event-count="<?= (int)$eventCount ?>">
            <span><?= (int)$eventCount ?></span>
            <span data-i18n="timeline.header.event_label">حدث</span>
        </span>
    </header>

    <div class="timeline-body timeline-body--compact h-full overflow-y-auto">
        <div class="timeline-list">
            <?php if ($timeline === []): ?>
                <div class="text-center text-gray-400 text-sm py-8">
                    <span data-i18n="timeline.empty.no_events">لا توجد أحداث في التاريخ</span>
                </div>
            <?php else: ?>
                <?php foreach ($timeline as $index => $event): ?>
                    <?php
                    $eventId = (int)($event['event_id'] ?? $event['id'] ?? 0);
                    $eventType = (string)($event['event_type'] ?? 'unknown');
                    $eventSubtype = (string)($event['event_subtype'] ?? '');
                    $eventLabel = \App\Services\TimelineRecorder::getEventDisplayLabel($event);
                    $eventLabelKey = $eventLabelKeyMap[$eventLabel] ?? null;
                    $eventIcon = \App\Services\TimelineRecorder::getEventIcon($event);
                    $isLatest = $index === 0;
                    $tone = (string)($event['tone'] ?? 'muted');

                    $resolvedSnapshot = $event['snapshot'] ?? [];
                    $snapshotRaw = json_encode($resolvedSnapshot, JSON_UNESCAPED_UNICODE);
                    if (!is_string($snapshotRaw) || trim($snapshotRaw) === '') {
                        $snapshotRaw = '{}';
                    }
                    $snapshotPayload = htmlspecialchars($snapshotRaw, ENT_QUOTES, 'UTF-8');
                    $eventDetailsPayload = htmlspecialchars((string)($event['event_details'] ?? '{}'), ENT_QUOTES, 'UTF-8');
                    $letterSnapshotPayload = htmlspecialchars((string)($event['letter_snapshot'] ?? 'null'), ENT_QUOTES, 'UTF-8');

                    $actor = $event['actor'] ?? null;
                    if (!is_array($actor)) {
                        $actor = TimelinePresentationNormalizer::actorFromEvent([
                            'created_by' => $event['created_by_raw'] ?? $event['created_by'] ?? 'system',
                        ]);
                    }

                    $changes = $event['changes'] ?? [];
                    if (!is_array($changes)) {
                        $changes = [];
                    }

                    if ($changes === []) {
                        $detailsDecoded = json_decode((string)($event['event_details'] ?? '{}'), true);
                        $rawChanges = is_array($detailsDecoded) ? ($detailsDecoded['changes'] ?? []) : [];
                        if (is_array($rawChanges)) {
                            foreach ($rawChanges as $rawChange) {
                                if (is_array($rawChange) && isset($rawChange['field'])) {
                                    $changes[] = TimelinePresentationNormalizer::normalizeChange($rawChange);
                                }
                            }
                        }
                    }

                    $statusChangePresent = $event['status_change_present'] ?? null;
                    if (!is_array($statusChangePresent) && isset($event['status_change'])) {
                        $statusChangePresent = TimelinePresentationNormalizer::presentValue('status', $event['status_change']);
                    }

                    $sourceInfo = $event['source_info'] ?? null;
                    $hasSnapshot = !empty($event['snapshot_data']) && $event['snapshot_data'] !== '{}' && $event['snapshot_data'] !== 'null';
                    $hasAnchor = !empty($event['anchor_snapshot']) && $event['anchor_snapshot'] !== '{}' && $event['anchor_snapshot'] !== 'null';
                    ?>

                    <div class="timeline-event-wrapper timeline-event-wrapper--interactive"
                        data-event-id="<?= $eventId ?>"
                        data-event-type="<?= htmlspecialchars($eventType !== '' ? $eventType : 'unknown', ENT_QUOTES, 'UTF-8') ?>"
                        data-event-subtype="<?= htmlspecialchars($eventSubtype, ENT_QUOTES, 'UTF-8') ?>"
                        data-snapshot='<?= $snapshotPayload ?>'
                        data-event-details='<?= $eventDetailsPayload ?>'
                        data-letter-snapshot='<?= $letterSnapshotPayload ?>'
                        data-is-latest="<?= $isLatest ? '1' : '0' ?>">

                        <?php if ($index < count($timeline) - 1): ?>
                            <div class="timeline-event-connector"></div>
                        <?php endif; ?>

                        <div class="timeline-dot timeline-dot--event timeline-tone--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?> <?= ($hasSnapshot || $hasAnchor) ? 'timeline-dot-anchor' : '' ?>"></div>

                        <div class="timeline-event-card timeline-event-card--interactive timeline-tone--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="timeline-event-header">
                                <div class="timeline-event-title-group">
                                    <span class="timeline-event-icon"><?= htmlspecialchars($eventIcon, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="timeline-event-label timeline-tone-text"<?= $eventLabelKey ? ' data-i18n="' . htmlspecialchars($eventLabelKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                        <?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                                <?php if ($isLatest): ?>
                                    <span class="timeline-latest-badge" data-i18n="timeline.badges.latest_event">آخر حدث</span>
                                <?php endif; ?>
                            </div>

                            <?php if (is_array($sourceInfo) && isset($sourceInfo['label_key'], $sourceInfo['tone'])): ?>
                                <div class="timeline-source-badge timeline-source--<?= htmlspecialchars((string)$sourceInfo['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span data-i18n="<?= htmlspecialchars((string)$sourceInfo['label_key'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($changes !== []): ?>
                                <div class="timeline-change-block">
                                    <?php foreach ($changes as $change): ?>
                                        <?php
                                        if (!is_array($change)) {
                                            continue;
                                        }
                                        $field = (string)($change['field'] ?? '');
                                        if ($field === '') {
                                            continue;
                                        }
                                        $fieldLabelKey = $fieldLabelKeys[$field] ?? null;
                                        $oldPresent = is_array($change['old_present'] ?? null)
                                            ? $change['old_present']
                                            : TimelinePresentationNormalizer::presentValue($field, $change['old_value'] ?? null);
                                        $newPresent = is_array($change['new_present'] ?? null)
                                            ? $change['new_present']
                                            : TimelinePresentationNormalizer::presentValue($field, $change['new_value'] ?? null);
                                        $oldRaw = $change['old_value'] ?? null;
                                        $hasOldValue = !($oldRaw === null || $oldRaw === '' || (is_array($oldRaw) && $oldRaw === []));
                                        ?>
                                        <div class="timeline-change-row">
                                            <strong class="timeline-change-label">
                                                •
                                                <?php if ($fieldLabelKey): ?>
                                                    <span data-i18n="<?= htmlspecialchars($fieldLabelKey, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                                :
                                            </strong>

                                            <?php if ($hasOldValue): ?>
                                                <span class="timeline-change-old"<?= !empty($oldPresent['i18n_key']) ? ' data-i18n="' . htmlspecialchars((string)$oldPresent['i18n_key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                                    <?= htmlspecialchars((string)($oldPresent['display'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <span class="timeline-change-arrow">→</span>
                                            <?php endif; ?>

                                            <span class="timeline-change-new"<?= !empty($newPresent['i18n_key']) ? ' data-i18n="' . htmlspecialchars((string)$newPresent['i18n_key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                                <?= htmlspecialchars((string)($newPresent['display'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                            </span>

                                            <?php if (isset($change['confidence']) && $change['confidence'] !== null): ?>
                                                <span class="timeline-change-confidence">(<?= (int)round((float)$change['confidence']) ?>%)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php
                                    $hasStatusInChanges = false;
                                    foreach ($changes as $existingChange) {
                                        if (is_array($existingChange) && (string)($existingChange['field'] ?? '') === 'status') {
                                            $hasStatusInChanges = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <?php if (is_array($statusChangePresent) && !$hasStatusInChanges): ?>
                                        <div class="timeline-status-row">
                                            <strong class="timeline-change-label">• <span data-i18n="timeline.status.label">الحالة</span>:</strong>
                                            <span class="timeline-change-new"<?= !empty($statusChangePresent['i18n_key']) ? ' data-i18n="' . htmlspecialchars((string)$statusChangePresent['i18n_key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                                <?= htmlspecialchars((string)($statusChangePresent['display'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($event['change_reason'])): ?>
                                <div class="timeline-change-block">
                                    <?= (string)$event['change_reason'] ?>
                                </div>
                            <?php endif; ?>

                            <div class="timeline-event-meta">
                                <span><?= htmlspecialchars((string)($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="timeline-event-user">
                                    <?= htmlspecialchars((string)($actor['icon'] ?? '👤'), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($actor['i18n_key'])): ?>
                                        <span data-i18n="<?= htmlspecialchars((string)$actor['i18n_key'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)($actor['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars((string)($actor['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </span>
                            </div>

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
