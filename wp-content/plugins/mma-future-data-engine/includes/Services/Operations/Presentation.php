<?php
namespace MMAF\DataEngine\Services\Operations;

/** Human-readable operation state shared by admin HTML and private REST responses. */
final class Presentation {
    public static function label(string $value): string {
        return [
            'previewing'=>'Preparing preview','ready'=>'Ready for confirmation','running'=>'In progress','paused'=>'Paused',
            'completed'=>'Completed','cancelled'=>'Cancelled','failed'=>'Technical failure','review'=>'Needs review','noop'=>'No change needed',
            'pending'=>'Waiting','preview_pending'=>'Waiting for preview','preparing'=>'Preparing saved profile','prepared'=>'Prepared; validation pending',
            'preview'=>'Checking selected fighters','preview_manifest'=>'Freezing preview and summary','prepare'=>'Preparing profiles and careers',
            'stats'=>'Updating statistics','validate'=>'Validating publication and ranking','ranking'=>'Preparing inactive ranking draft',
            'approve_and_prepare'=>'Approve & prepare','validate_selected_for_ranking'=>'Check ranking eligibility',
            'prepare_publish_profile'=>'Prepare & publish profile','mark_public'=>'Prepare public profiles','mark_verified'=>'Prepare verified status','mark_rankable'=>'Prepare ranking eligibility',
            'fact_protection_report'=>'Review existing fact protection','fact_evidence'=>'Collecting fact protection evidence','mark_not_public'=>'Hide public profiles','mark_not_rankable'=>'Remove ranking eligibility','move_to_provisional'=>'Return to provisional',
            'stored_profile_missing'=>'Stored profile unavailable','identity_conflict'=>'Source identity belongs to more than one fighter',
            'excluded_identity'=>'Identity is hidden, merged or excluded','technical_failure'=>'Preparation failed; technical retry is available',
            'changed_since_preview'=>'Data changed after preview; create a new preview','changed_after_preparation'=>'Data changed after preparation; review before publication',
            'identity_changed'=>'Saved source identity changed','identity_or_facts_review'=>'Source identity or facts need individual review',
            'ranking_ineligible'=>'Prepared, but existing ranking rules are not satisfied','fighter_not_found'=>'Selected fighter no longer exists',
        ][$value]??ucfirst(str_replace('_',' ',$value));
    }
    public static function commands(array $job): array {
        $out=[];$status=$job['status'];
        if(!empty($job['checkpoint']['local_copy_paused'])){return in_array($status,['completed','cancelled'],true)?[]:['cancel'=>'Close historical operation'];}
        if($job['kind']==='fighters' && (int)($job['checkpoint']['fingerprint_version']??1)!==Readiness::FINGERPRINT_VERSION && !in_array($status,['completed','cancelled'],true)){return ['repreview'=>'Create fresh preview','cancel'=>'Cancel future steps'];}
        if($status==='ready'){$out['start']='Confirm manifest and start';}
        if(in_array($status,['previewing','running'],true)){$out['pause']='Pause';}
        if($status==='paused'){$out['resume']='Resume';}
        if($status==='failed'){$out['retry_job']='Retry failed operation';}
        if(!in_array($status,['completed','cancelled'],true)){$out['cancel']='Cancel future steps';}
        if($job['kind']==='fighters' && $job['confirmed_at'] && in_array($status,['completed','failed','paused'],true)){$out['retry']='Retry selected technical failures';}
        return $out;
    }
    public static function progress(array $job,array $counts): array {
        $done=array_sum(array_intersect_key($counts,array_flip(['completed','noop','review','failed'])));
        $phase=$job['status']==='ready'?'Review frozen selection':($job['status']==='cancelled'?'No further steps will run':self::label($job['phase']));
        return ['phase'=>$phase,'processed'=>$done,'total'=>(int)$job['total'],'counts'=>$counts,
            'status_label'=>$job['status']==='completed' && (($counts['review']??0)+($counts['failed']??0)>0)?'Completed with issues':self::label($job['status']),
            'allowed_commands'=>self::commands($job)];
    }
}
