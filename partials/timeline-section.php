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
            <span>Timeline</span>
        </div>
        <span class="timeline-count cursor-help" title="<?= $eventCount ?> أحداث">
            <span><?= $eventCount ?></span> حدث
        </span>
    </header>
    <div class="timeline-body h-full overflow-y-auto" style="padding-right: 4px;">
        <div class="timeline-list">
            
            <?php if (empty($timeline)): ?>
                <div class="text-center text-gray-400 text-sm py-8">
                    لا توجد أحداث في التاريخ
                </div>
            <?php else: ?>
                <?php foreach ($timeline as $index => $event): 
                    // Use TimelineRecorder for labels and icons
                    $eventLabel = \App\Services\TimelineRecorder::getEventDisplayLabel($event);
                    $eventIcon = \App\Services\TimelineRecorder::getEventIcon($event);
                    
                    // Parse event_details JSON
                    $eventDetailsRaw = $event['event_details'] ?? null;
                    $details = $eventDetailsRaw ? json_decode($eventDetailsRaw, true) : [];
                    $changes = $details['changes'] ?? [];
                    $statusChange = $details['status_change'] ?? null;
                    $trigger = $details['trigger'] ?? 'manual';
                    
                    // Color mapping based on event label
                    $labelColors = [
                        'استيراد' => ['border' => '#64748b', 'text' => '#334155'],
                        'محاولة استيراد مكرر' => ['border' => '#f59e0b', 'text' => '#92400e'],
                        'تطابق تلقائي' => ['border' => '#3b82f6', 'text' => '#1e40af'],
                        'تطابق يدوي' => ['border' => '#059669', 'text' => '#047857'],
                        'تعديل يدوي' => ['border' => '#059669', 'text' => '#047857'],
                        'تمديد' => ['border' => '#ca8a04', 'text' => '#a16207'],
                        'تخفيض' => ['border' => '#7c3aed', 'text' => '#5b21b6'],
                        'إفراج' => ['border' => '#dc2626', 'text' => '#991b1b'],
                    ];
                    
                    $colors = $labelColors[$eventLabel] ?? ['border' => '#94a3b8', 'text' => '#475569'];
                    $isFirst = $index === 0;
                    $isLatest = $index === 0;  // Latest event (current state)
                ?>
                    <div class="timeline-event-wrapper" 
                         data-event-id="<?= $event['id'] ?>"
                         data-event-type="<?= $event['event_type'] ?? 'unknown' ?>"
                         data-event-subtype="<?= $event['event_subtype'] ?? '' ?>"
                         data-snapshot='<?= htmlspecialchars($event['snapshot_data'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>'
                         data-letter-snapshot='<?= htmlspecialchars($event['letter_snapshot'] ?? 'null', ENT_QUOTES, 'UTF-8') ?>'
                         data-is-latest="<?= $isLatest ? '1' : '0' ?>"
                         style="position: relative; padding-right: 12px; margin-bottom: 10px; cursor: pointer;">
                        
                        <!-- Timeline Connector -->
                        <?php if ($index < count($timeline) - 1): ?>
                        <div style="position: absolute; right: 3px; top: 14px; bottom: -10px; width: 2px; background: #e2e8f0;"></div>
                        <?php endif; ?>
                        
                        <!-- Dot -->
                        <div style="position: absolute; right: -2px; top: 8px; width: 10px; height: 10px; border-radius: 50%; background: <?= $colors['border'] ?>; border: 2px solid white; box-shadow: 0 0 0 1px #e2e8f0; z-index: 1;"></div>
                        
                        <!-- Event Card -->
                        <div class="timeline-event-card" style="background: white; border: 1px solid #e2e8f0; border-right: 3px solid <?= $colors['border'] ?>; border-radius: 4px; padding: 10px 12px; margin-right: 16px; transition: all 0.2s;" 
                             onmouseover="this.style.borderRightWidth='4px'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.1)'"
                             onmouseout="this.style.borderRightWidth='3px'; this.style.boxShadow='none'">
                            
                            <!-- Event Header -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 14px;"><?= $eventIcon ?></span>
                                    <span style="font-weight: 600; color: <?= $colors['text'] ?>; font-size: 13px;">
                                        <?= htmlspecialchars($eventLabel) ?>
                                    </span>
                                </div>
                                <?php if ($isLatest): ?>
                                <span style="background: #1e293b; color: white; font-size: 9px; font-weight: 600; padding: 2px 6px; border-radius: 2px;">آخر حدث</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Event Changes Details -->
                            
                            <?php 
                            // 🆕 Show import source for import/reimport events
                            if (in_array($event['event_type'] ?? '', ['import', 'reimport'])):
                                $sourceLabels = [
                                    'excel' => ['text' => '📁 ملف Excel', 'color' => '#3b82f6'],
                                    'smart_paste' => ['text' => '⚡ لصق ذكي', 'color' => '#8b5cf6'],
                                    'smart_paste_multi' => ['text' => '⚡ لصق ذكي', 'color' => '#8b5cf6'],
                                    'duplicate_smart_paste' => ['text' => '🔄 لصق مكرر', 'color' => '#f59e0b'],
                                    'duplicate_excel' => ['text' => '🔄 استيراد مكرر', 'color' => '#f59e0b'],
                                    'manual' => ['text' => '✍️ إدخال يدوي', 'color' => '#10b981']
                                ];
                                // Fix: Check if event_subtype exists, if not check event_details
                                $source = $event['event_subtype'] ?? '';
                                if (!$source) {
                                    $details = json_decode($event['event_details'] ?? '{}', true);
                                    $source = $details['source'] ?? 'excel';
                                }
                                
                                $sourceInfo = $sourceLabels[$source] ?? ['text' => '📁 استيراد ملف', 'color' => '#6b7280'];
                            ?>
                            <div style="font-size: 11px; color: <?= $sourceInfo['color'] ?>; font-weight: 500; margin: 6px 0; padding: 4px 8px; background: <?= $sourceInfo['color'] ?>15; border-radius: 3px; border-left: 3px solid <?= $sourceInfo['color'] ?>;">
                                <?= $sourceInfo['text'] ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (($event['event_type'] ?? '') === 'modified' && $index < 2): ?>
                            <!-- DEBUG EVENT <?= $event['id'] ?>: raw=<?= htmlspecialchars(substr($event['event_details'] ?? 'NULL', 0, 100)) ?> | changes_count=<?= count($changes) ?> -->
                            <?php endif; ?>
                            <?php if (!empty($changes)): ?>
                            <div style="font-size: 12px; color: #475569; line-height: 1.6; margin: 6px 0; padding: 6px 8px; background: #f8fafc; border-radius: 3px;">
                                <?php 
                                // STRICT RENDERING RULE: Only show fields allowed for this event type
                                $allowedFields = [];
                                if ($eventLabel === 'تمديد الضمان') $allowedFields = ['expiry_date'];
                                elseif ($eventLabel === 'تخفيض قيمة الضمان') $allowedFields = ['amount'];
                                elseif ($eventLabel === 'اعتماد بيانات المورد أو البنك') $allowedFields = ['supplier_id', 'bank_id'];
                                elseif ($eventLabel === 'تطابق تلقائي') $allowedFields = ['bank_name', 'supplier_name', 'supplier_id', 'bank_id'];
                                elseif ($eventLabel === 'إفراج الضمان') $allowedFields = ['status']; // Usually handled by status logic, but implicit change might exist
                                elseif ($eventLabel === 'تحديث بيانات') $allowedFields = []; // Show nothing for generic updates to avoid noise
                                else $allowedFields = ['supplier_id', 'bank_id', 'amount', 'expiry_date', 'status']; // Fallback for pure debug? Or restrict? Let's restrict.

                                // Filter changes
                                $visibleChanges = array_filter($changes, function($change) use ($allowedFields) {
                                    return in_array($change['field'], $allowedFields);
                                });
                                ?>

                                <?php foreach ($visibleChanges as $change): ?>
                                    <?php
                                    $fieldLabels = [
                                        'supplier_id' => 'المورد',
                                        'bank_id' => 'البنك',
                                        'bank_name' => 'البنك',
                                        'supplier_name' => 'المورد',
                                        'amount' => 'المبلغ',
                                        'expiry_date' => 'تاريخ الانتهاء',
                                        'status' => 'الحالة'
                                    ];
                                    $fieldLabel = $fieldLabels[$change['field']] ?? $change['field'];
                                    ?>
                                    
                                    <div style="margin-bottom: 4px;">
                                        <strong style="color: #1e293b;">• <?= $fieldLabel ?>:</strong>
                                        
                                        <?php if ($change['field'] === 'supplier_id' || $change['field'] === 'bank_id'): ?>
                                            <?php
                                            $oldName = $change['old_value']['name'] ?? null;
                                            $newName = $change['new_value']['name'] ?? null;
                                            ?>
                                            
                                            <?php if ($oldName && $oldName !== 'غير محدد'): ?>
                                                <span style="color: #dc2626; text-decoration: line-through; opacity: 0.8;">
                                                    <?= htmlspecialchars($oldName) ?>
                                                </span>
                                                <span style="color: #64748b; margin: 0 4px;">→</span>
                                            <?php endif; ?>
                                            
                                            <span style="color: #059669; font-weight: 500;">
                                                <?= htmlspecialchars($newName ?? '') ?>
                                            </span>
                                            
                                            <?php if (isset($change['confidence'])): ?>
                                                <span style="color: #3b82f6; font-size: 11px; margin-left: 4px;">
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
                                                <span style="color: #dc2626; text-decoration: line-through; opacity: 0.8;">
                                                    <?= htmlspecialchars($oldVal) ?>
                                                </span>
                                                <span style="color: #64748b; margin: 0 4px;">→</span>
                                            <?php endif; ?>
                                            
                                            <span style="color: #059669; font-weight: 500;">
                                                <?= htmlspecialchars($newVal ?? '') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($statusChange): ?>
                                <div style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #e2e8f0;">
                                    <strong style="color: #1e293b;">• الحالة:</strong>
                                    <span style="color: #059669; font-weight: 500;">
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
                            <div style="font-size: 12px; color: #475569; line-height: 1.6; margin: 6px 0; padding: 6px 8px; background: #f8fafc; border-radius: 3px;">
                                <?= $event['change_reason'] /* HTML formatted */ ?>
                            </div>
                            <?php endif; ?>
                            
                            
                            
                            
                            <!-- Date and User -->
                            <div style="font-size: 11px; color: #64748b; margin-top: 6px; padding-top: 6px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between;">
                                <span><?= htmlspecialchars($event['created_at'] ?? '') ?></span>
                                <?php 
                                // Format the display with icons
                                $rawCreator = $event['created_by'] ?? 'System';
                                $displayCreator = match(trim($rawCreator)) {
                                    'بواسطة النظام', 'System', 'System AI', 'النظام' => '🤖 النظام',
                                    'بواسطة المستخدم', 'User', 'web_user', 'user' => '👤 المستخدم',
                                    default => '👤 ' . str_replace('بواسطة ', '', $rawCreator)
                                };
                                ?>
                                <span style="font-weight: 500;"><?= $displayCreator ?></span>
                            </div>
                            
                            <!-- Click hint -->
                            <div style="font-size: 10px; color: #94a3b8; margin-top: 4px; text-align: center;">
                                <?php if ($isLatest): ?>
                                    👁️ انقر لعرض الحالة الحالية
                                <?php else: ?>
                                    🕐 انقر لعرض الحالة قبل هذا الحدث
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>
