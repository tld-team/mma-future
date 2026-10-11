<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Nested services use savepoints; only the owner commits the batch and checkpoint. */
final class Transaction {
    private static int $depth = 0;
    private static array $callbacks=[];
    private static array $marks=[];
    public static function active(): bool { return self::$depth>0; }
    public static function after_commit(callable $callback): void { if(!self::$depth){$callback();return;} self::$callbacks[]=$callback; }
    public static function begin(): void {
        global $wpdb;
        self::query(self::$depth ? 'SAVEPOINT mmaf_tx_' . self::$depth : 'START TRANSACTION');
        self::$marks[self::$depth]=count(self::$callbacks);
        ++self::$depth;
    }
    public static function commit(): void {
        if (self::$depth < 1) { throw new \LogicException('No active import transaction.'); }
        $depth = self::$depth - 1;
        self::query($depth ? 'RELEASE SAVEPOINT mmaf_tx_' . $depth : 'COMMIT');
        self::$depth = $depth;
        unset(self::$marks[$depth]);
        if(!$depth){$callbacks=self::$callbacks;self::$callbacks=[];foreach($callbacks as $callback){try{$callback();}catch(\Throwable $error){update_option('mmaf_after_commit_error',['at'=>gmdate('Y-m-d H:i:s'),'reason'=>'Post-commit invalidation needs retry'],false);}}}

    }
    public static function rollback(): void {
        if (!self::$depth) { return; }
        --self::$depth;
        self::$callbacks=array_slice(self::$callbacks,0,self::$marks[self::$depth]??0);unset(self::$marks[self::$depth]);
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
