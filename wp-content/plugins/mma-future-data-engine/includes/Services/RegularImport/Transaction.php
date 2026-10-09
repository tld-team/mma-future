<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Nested services use savepoints; only the owner commits the batch and checkpoint. */
final class Transaction {
    private static int $depth = 0;
    public static function begin(): void {
        global $wpdb;
        self::query(self::$depth ? 'SAVEPOINT mmaf_tx_' . self::$depth : 'START TRANSACTION');
        ++self::$depth;
    }
    public static function commit(): void {
        if (self::$depth < 1) { throw new \LogicException('No active import transaction.'); }
        $depth = self::$depth - 1;
        self::query($depth ? 'RELEASE SAVEPOINT mmaf_tx_' . $depth : 'COMMIT');
        self::$depth = $depth;
    }
    public static function rollback(): void {
        if (!self::$depth) { return; }
        --self::$depth;
        self::query(self::$depth ? 'ROLLBACK TO SAVEPOINT mmaf_tx_' . self::$depth : 'ROLLBACK');
    }
    public static function run(callable $callback) {
        self::begin();
        try { $result = $callback(); self::commit(); return $result; }
        catch (\Throwable $error) { self::rollback(); throw $error; }
    }
    private static function query(string $sql): void {
        global $wpdb;
        if (false === $wpdb->query($sql)) { throw new \RuntimeException('Database transaction failed: ' . $wpdb->last_error); }
    }
}
