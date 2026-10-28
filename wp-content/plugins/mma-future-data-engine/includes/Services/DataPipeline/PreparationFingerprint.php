<?php
namespace MMAF\DataEngine\Services\DataPipeline;
use MMAF\DataEngine\Migrations\Schema as Canonical;

/** A source hash alone cannot see shared bout corrections or manual decisions. */
final class PreparationFingerprint {
    public const VERSION='career_period_tracking_v2';
    public static function for_fighter(int $id,?string $sourceHash): string {
        global $wpdb;$t=Canonical::table_names();
        $fighter=$wpdb->get_row($wpdb->prepare("SELECT id,date_of_birth,gender,weight_class,status,is_public,is_rankable,rankability_status,tracking_mode,tracking_revision,deleted_soft FROM {$t['fighters']} WHERE id=%d",$id),ARRAY_A);
        $boutIds=$wpdb->prepare("SELECT bout_id FROM {$t['bout_participants']} WHERE fighter_id=%d",$id);
        $fighterIds="SELECT fighter_id FROM {$t['bout_participants']} WHERE bout_id IN ($boutIds)";
        $sources=$wpdb->get_results($wpdb->prepare("SELECT fighter_id,source_type,source_fighter_id,source_url,identity_hash FROM {$t['fighter_sources']} WHERE fighter_id=%d OR fighter_id IN ($fighterIds) ORDER BY id",$id),ARRAY_A);
        $bouts=$wpdb->get_results("SELECT b.*,e.event_date,e.status event_status,e.deleted_soft event_deleted FROM {$t['bouts']} b JOIN {$t['events']} e ON e.id=b.event_id WHERE b.id IN ($boutIds) ORDER BY b.id",ARRAY_A);
        $participants=$wpdb->get_results("SELECT * FROM {$t['bout_participants']} WHERE bout_id IN ($boutIds) ORDER BY id",ARRAY_A);
        $boutSources=$wpdb->get_results("SELECT * FROM {$t['bout_sources']} WHERE bout_id IN ($boutIds) ORDER BY id",ARRAY_A);
        $manual=$wpdb->get_results($wpdb->prepare("SELECT entity_type,entity_id,field_name,source_type,locked,value_hash FROM {$t['field_provenance']} WHERE (entity_type='fighter' AND (entity_id=%d OR entity_id IN ($fighterIds))) OR (entity_type='bout' AND entity_id IN ($boutIds)) OR (entity_type='event' AND entity_id IN (SELECT event_id FROM {$t['bouts']} WHERE id IN ($boutIds))) ORDER BY entity_type,entity_id,field_name,id",$id),ARRAY_A);
        if($wpdb->last_error){throw new \RuntimeException('Cannot fingerprint preparation inputs.');}
        foreach([&$bouts,&$participants,&$boutSources] as &$rows){foreach($rows as &$row){unset($row['created_at'],$row['updated_at'],$row['first_seen_at'],$row['last_seen_at'],$row['last_import_run_id']);}unset($row);}unset($rows);
        $career=(new \MMAF\DataEngine\Services\Scoring\CareerRecords())->for_fighters([$id])[$id]??null;
        return hash('sha256',wp_json_encode([self::VERSION,$sourceHash,$fighter,$sources,$bouts,$participants,$boutSources,$manual,$career]));
    }
}
