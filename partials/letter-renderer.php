<?php
/**
 * Letter Renderer - Unified for Preview & Batch Print
 * 
 * This file replaces the old preview-section.php
 * It can be used in both contexts:
 * - Single preview (index.php)
 * - Batch print (batch-print.php in a loop)
 * 
 * Required variables:
 * @var array $record - Guarantee data with all fields
 * @var bool $showPlaceholder - (optional) Show "no action" state if true (default: true)
 */

use App\Services\LetterBuilder;

// Check if $record is provided
if (!isset($record)) {
    return;
}

// Check if action exists
$hasAction = !empty($record['active_action']);

// Show placeholder if requested and no action exists
if (($showPlaceholder ?? true) && !$hasAction) {
    include __DIR__ . '/preview-placeholder.php';
    return;
}

// Build letter data using LetterBuilder
$letterData = LetterBuilder::prepare($record, $record['active_action']);

// Render letter using template
echo LetterBuilder::render($letterData);
