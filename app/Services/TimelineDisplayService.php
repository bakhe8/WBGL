<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * TimelineDisplayService
 * 
 * Handles loading and formatting timeline events for display
 * Centralizes timeline presentation logic
 * 
 * @version 1.0
 */
class TimelineDisplayService
{
    // Note: Timeline icons are managed by TimelineRecorder::getEventIcon()
    // The iconMap was removed as dead code (never reached in practice)
    
    /**
     * Get formatted timeline events for display
     * 
     * @param PDO $db Database connection
     * @param int $guaranteeId Guarantee ID
     * @param string|null $importedAt Fallback import date
     * @param string|null $importSource Fallback import source
     * @param string|null $importedBy Fallback imported by
     * @return array Array of formatted timeline events
     */
    public static function getEventsForDisplay(
        PDO $db,
        int $guaranteeId,
        ?string $importedAt = null,
        ?string $importSource = null,
        ?string $importedBy = null
    ): array {
        $timeline = [];
        
        try {
            // Load from guarantee_history table (unified timeline)
            $stmt = $db->prepare('
                SELECT * FROM guarantee_history 
                WHERE guarantee_id = ? 
                ORDER BY created_at DESC, id DESC
            ');
            $stmt->execute([$guaranteeId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($history as $event) {
                $timeline[] = [
                    'id' => 'history_' . $event['id'],
                    'event_id' => $event['id'],
                    'event_type' => $event['event_type'] ?? 'unknown',
                    'event_subtype' => $event['event_subtype'] ?? null,
                    'type' => $event['event_type'] ?? 'unknown',
                    'icon' => '📋',  // Fallback; actual icons from TimelineRecorder::getEventIcon()
                    'action' => $event['event_type'] ?? 'unknown',
                    'date' => $event['created_at'],
                    'created_at' => $event['created_at'],
                    'event_details' => $event['event_details'] ?? null,
                    'change_reason' => '',
                    'description' => json_encode(json_decode($event['event_details'] ?? '{}', true)),
                    'user' => $event['created_by'] ?? 'النظام',
                    'created_by' => $event['created_by'] ?? 'النظام', // ✅ Pass through for view compatibility
                    'snapshot' => json_decode($event['snapshot_data'] ?? '{}', true),
                    'snapshot_data' => $event['snapshot_data'] ?? '{}',
                    'letter_snapshot' => $event['letter_snapshot'] ?? null,
                    'source_badge' => in_array(
                        $event['created_by'] ?? 'system', 
                        ['system', 'System', 'System AI', 'النظام', 'بواسطة النظام']
                    ) ? '🤖 نظام' : '👤 مستخدم'
                ];
            }
        } catch (\Exception $e) {
            // If error, keep empty array
        }
        
        // Sort timeline by date (most recent first)
        usort($timeline, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Sort all timeline events by date descending
        usort($timeline, function($a, $b) {
            $dateA = $a['date'] ?? $a['created_at'] ?? '1970-01-01';
            $dateB = $b['date'] ?? $b['created_at'] ?? '1970-01-01';
            return strtotime($dateB) - strtotime($dateA);
        });
        
        // Add import event if no events found
        if (empty($timeline) && $importedAt) {
            $timeline[] = [
                'id' => 'import_1',
                'type' => 'import',
                'event_type' => 'import',
                'icon' => '📥',
                'action' => 'import',
                'date' => $importedAt,
                'created_at' => $importedAt,
                'change_reason' => 'استيراد من ' . ($importSource ?? 'Excel'),
                'description' => 'استيراد من ' . ($importSource ?? 'Excel'),
                'user' => htmlspecialchars($importedBy ?? 'النظام', ENT_QUOTES),
                'source_badge' => '🤖 نظام',
                'changes' => []
            ];
        }
        
        return $timeline;
    }
}
