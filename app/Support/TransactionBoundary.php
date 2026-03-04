<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Provides a consistent transaction boundary wrapper.
 *
 * If a transaction is already open, the callback runs inside it without
 * owning commit/rollback. Otherwise, this helper owns begin/commit/rollback.
 */
final class TransactionBoundary
{
    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function run(PDO $db, callable $callback)
    {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownsTransaction && $db->inTransaction()) {
                $db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

