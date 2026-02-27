<?php
declare(strict_types=1);

namespace App\Support;

final class MigrationSqlAdapter
{
    private const INSERT_IGNORE_MARKER = '/*WBGL_ON_CONFLICT_IGNORE*/';

    public static function normalizeForDriver(string $sql, string $driver): string
    {
        $normalizedDriver = strtolower(trim($driver));
        if ($normalizedDriver !== 'pgsql') {
            return $sql;
        }

        $rewritten = self::rewriteAutoincrement($sql);
        $rewritten = self::rewriteDatetimeTypes($rewritten);
        $rewritten = self::rewriteInsertOrIgnore($rewritten);

        return $rewritten;
    }

    private static function rewriteAutoincrement(string $sql): string
    {
        $result = preg_replace(
            '/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
            'BIGSERIAL PRIMARY KEY',
            $sql
        );

        return is_string($result) ? $result : $sql;
    }

    private static function rewriteDatetimeTypes(string $sql): string
    {
        $result = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $sql);
        return is_string($result) ? $result : $sql;
    }

    private static function rewriteInsertOrIgnore(string $sql): string
    {
        $tagged = preg_replace(
            '/\bINSERT\s+OR\s+IGNORE\b/i',
            'INSERT ' . self::INSERT_IGNORE_MARKER,
            $sql
        );
        if (!is_string($tagged)) {
            return $sql;
        }

        $rewritten = preg_replace_callback(
            '/INSERT\s+\/\*WBGL_ON_CONFLICT_IGNORE\*\/\s+INTO\b.*?;/is',
            static function (array $match): string {
                $statement = str_replace(self::INSERT_IGNORE_MARKER, '', (string)$match[0]);
                $trimmed = rtrim($statement);
                $hasSemicolon = str_ends_with($trimmed, ';');
                $body = $hasSemicolon ? substr($trimmed, 0, -1) : $trimmed;

                if (preg_match('/\bON\s+CONFLICT\b/i', $body) !== 1) {
                    $body .= ' ON CONFLICT DO NOTHING';
                }

                return $body . ($hasSemicolon ? ';' : '');
            },
            $tagged
        );

        if (!is_string($rewritten)) {
            return str_replace(self::INSERT_IGNORE_MARKER, '', $tagged);
        }

        return str_replace(self::INSERT_IGNORE_MARKER, '', $rewritten);
    }
}
