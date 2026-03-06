<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Request-scoped schema inspection cache.
 *
 * Avoids repeated information_schema lookups for table/column existence
 * across hot paths (metrics, notifications, archive, audit).
 */
final class SchemaInspector
{
    /** @var array<string,bool> */
    private static array $tableCache = [];

    /** @var array<string,bool> */
    private static array $columnCache = [];

    public static function tableExists(PDO $db, string $tableName, string $schema = 'public'): bool
    {
        $key = self::cacheKey($db, $schema . '.table.' . strtolower(trim($tableName)));
        if (array_key_exists($key, self::$tableCache)) {
            return self::$tableCache[$key];
        }

        try {
            $stmt = $db->prepare(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_name = ?
                 LIMIT 1"
            );
            $stmt->execute([$schema, $tableName]);
            $exists = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $exists = false;
        }

        self::$tableCache[$key] = $exists;
        return $exists;
    }

    public static function columnExists(
        PDO $db,
        string $tableName,
        string $columnName,
        string $schema = 'public'
    ): bool {
        $key = self::cacheKey(
            $db,
            $schema . '.column.' . strtolower(trim($tableName)) . '.' . strtolower(trim($columnName))
        );
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        try {
            $stmt = $db->prepare(
                "SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND column_name = ?
                 LIMIT 1"
            );
            $stmt->execute([$schema, $tableName, $columnName]);
            $exists = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $exists = false;
        }

        self::$columnCache[$key] = $exists;
        return $exists;
    }

    private static function cacheKey(PDO $db, string $suffix): string
    {
        return spl_object_id($db) . ':' . $suffix;
    }
}

