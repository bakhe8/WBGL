<?php
declare(strict_types=1);

namespace App\Support;

class Input
{
    public static function string(array $input, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return $default;
    }

    public static function int(array $input, string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function bool(array $input, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    public static function array(array $input, string $key, ?array $default = null): ?array
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        return is_array($value) ? $value : $default;
    }
}
