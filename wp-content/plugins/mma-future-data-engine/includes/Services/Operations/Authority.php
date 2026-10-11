<?php
namespace MMAF\DataEngine\Services\Operations;

/** Explicit administrative preparation intent, separate from public/rankable flags. */
final class Authority {
    public static function allows(int $operation,int $fighter): bool {
        if ($operation<1 || $fighter<1) { return false; }
        global $wpdb; $t=Schema::tables();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$t['jobs']} o JOIN {$t['items']} i ON i.operation_id=o.id WHERE o.id=%d AND i.fighter_id=%d AND o.action IN ('approve_and_prepare','mark_public','mark_verified','mark_rankable') AND o.status='running' AND o.confirmed_at IS NOT NULL AND i.status='preparing' LIMIT 1",$operation,$fighter));
    }
    public static function can_publish(int $operation,int $fighter): bool {
        if($operation<1 || $fighter<1){return false;}
        global $wpdb;$t=Schema::tables();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$t['jobs']} o JOIN {$t['items']} i ON i.operation_id=o.id WHERE o.id=%d AND i.fighter_id=%d AND o.action IN ('approve_and_prepare','mark_public','mark_verified','mark_rankable') AND o.status='running' AND o.phase='approve' AND o.confirmed_at IS NOT NULL AND i.status='prepared' LIMIT 1",$operation,$fighter));
    }
}
