<?php
declare(strict_types=1);

namespace App\Services;

use DateTime;
use Exception;

/**
 * PreviewFormatter
 * 
 * Centralized logic for formatting official letters.
 * Handles Arabic numerals, dates, and phrasing logic.
 * Ensures fidelity across Index Preview and Batch Print.
 */
class PreviewFormatter
{
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    
    private const ARABIC_MONTHS = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];

    /**
     * Convert Western numerals (0-9) to Arabic-Indic numerals (٠-٩)
     */
    public static function toArabicNumerals($text): string
    {
        if (empty($text)) return (string)$text;
        
        return preg_replace_callback('/\d/', function($matches) {
            return self::ARABIC_DIGITS[(int)$matches[0]];
        }, (string)$text);
    }

    /**
     * Format date to Arabic usage: "١ يناير ٢٠٢٥"
     */
    public static function formatArabicDate($dateStr): string
    {
        if (empty($dateStr)) return '';
        
        try {
            $timestamp = strtotime($dateStr);
            if ($timestamp === false) return $dateStr;
            
            $day = date('j', $timestamp);
            $month = (int)date('n', $timestamp);
            $year = date('Y', $timestamp);
            
            $arabicDay = self::toArabicNumerals($day);
            $arabicYear = self::toArabicNumerals($year);
            $monthName = self::ARABIC_MONTHS[$month] ?? '';
            
            return "{$arabicDay} {$monthName} {$arabicYear}";
        } catch (Exception $e) {
            return $dateStr;
        }
    }

    /**
     * Translate guarantee type to Arabic
     */
    public static function translateType(string $englishType): string
    {
        $translations = [
            'Final' => 'النهائي',
            'Preliminary' => 'الابتدائي',
            'Performance' => 'الأداء',
            'Advance Payment' => 'الدفعة المقدمة',
        ];
        return $translations[$englishType] ?? $englishType;
    }

    /**
     * Get the exact introductory phrase based on guarantee type
     */
    public static function getIntroPhrase(string $rawType): string
    {
        if (stripos($rawType, 'Final') !== false || stripos($rawType, 'نهائي') !== false) {
            return 'إشارة إلى الضمان البنكي النهائي الموضح أعلاه';
        } elseif (stripos($rawType, 'Advance') !== false || stripos($rawType, 'دفعة مقدمة') !== false) {
            return 'إشارة إلى ضمان الدفعة المقدمة البنكي الموضح أعلاه';
        } elseif (stripos($rawType, 'Initial') !== false || stripos($rawType, 'ابتدائي') !== false) {
            return 'إشارة إلى الضمان البنكي الابتدائي الموضح أعلاه';
        } else {
            return 'إشارة إلى الضمان البنكي الموضح أعلاه';
        }
    }
}
