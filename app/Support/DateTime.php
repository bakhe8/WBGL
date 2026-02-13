<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use DateTimeImmutable;

/**
 * Centralized DateTime Helper
 * 
 * المكان الوحيد لجميع عمليات التاريخ والوقت في النظام
 * All date/time functions use timezone from Settings
 */
class DateTime
{
    private static ?DateTimeZone $timezone = null;
    
    /**
     * Get configured timezone from Settings
     */
    private static function getTimezone(): DateTimeZone
    {
        if (self::$timezone === null) {
            $settings = new Settings();
            $tz = $settings->get('TIMEZONE', 'Asia/Riyadh');
            self::$timezone = new DateTimeZone($tz);
        }
        return self::$timezone;
    }
    
    /**
     * Get current datetime in configured timezone
     * Format: Y-m-d H:i:s (e.g., 2025-01-05 13:42:00)
     * 
     * استخدم بدلاً من: date('Y-m-d H:i:s')
     */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', self::getTimezone()))
            ->format('Y-m-d H:i:s');
    }
    
    /**
     * Get current date in configured timezone
     * Format: Y-m-d (e.g., 2025-01-05)
     * 
     * استخدم بدلاً من: date('Y-m-d')
     */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', self::getTimezone()))
            ->format('Y-m-d');
    }
    
    /**
     * Get ISO 8601 timestamp with timezone
     * Format: 2025-01-05T13:42:00+03:00
     * 
     * استخدم بدلاً من: date('c')
     */
    public static function timestamp(): string
    {
        return (new DateTimeImmutable('now', self::getTimezone()))
            ->format('c');
    }
    
    /**
     * Format custom datetime string
     * 
     * @param string $format PHP date format (e.g., 'Y-m-d', 'Ymd_His')
     * @param int|null $timestamp Unix timestamp (null = now)
     * 
     * استخدم بدلاً من: date($format) أو date($format, $timestamp)
     */
    public static function format(string $format, ?int $timestamp = null): string
    {
        if ($timestamp !== null) {
            $dt = DateTimeImmutable::createFromFormat('U', (string)$timestamp);
            if ($dt === false) {
                // Fallback to current time if invalid timestamp
                $dt = new DateTimeImmutable('now');
            }
            return $dt->setTimezone(self::getTimezone())->format($format);
        }
        
        return (new DateTimeImmutable('now', self::getTimezone()))
            ->format($format);
    }
    
    /**
     * Convert Unix timestamp to Y-m-d format
     * 
     * @param int $timestamp Unix timestamp
     * 
     * استخدم بدلاً من: date('Y-m-d', $timestamp) أو gmdate('Y-m-d', $timestamp)
     */
    public static function fromUnix(int $timestamp): string
    {
        $dt = DateTimeImmutable::createFromFormat('U', (string)$timestamp);
        if ($dt === false) {
            return self::today(); // Fallback
        }
        
        return $dt->setTimezone(self::getTimezone())->format('Y-m-d');
    }
    
    /**
     * Get configured timezone string
     * 
     * @return string (e.g., 'Asia/Riyadh')
     */
    public static function getConfiguredTimezone(): string
    {
        return self::getTimezone()->getName();
    }
    
    /**
     * Get timezone offset in hours
     * 
     * @return string (e.g., '+03:00')
     */
    public static function getTimezoneOffset(): string
    {
        return (new DateTimeImmutable('now', self::getTimezone()))
            ->format('P');
    }
    
    /**
     * Reset cached timezone (useful after settings change)
     */
    public static function resetTimezone(): void
    {
        self::$timezone = null;
    }
    
    /**
     * Parse date string to Y-m-d format using configured timezone
     * 
     * @param string $dateString Date in any recognizable format
     * @return string|null Y-m-d format or null if invalid
     */
    public static function parse(string $dateString): ?string
    {
        try {
            $dt = new DateTimeImmutable($dateString, self::getTimezone());
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
