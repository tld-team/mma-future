<?php
namespace MMAF\DataEngine\Services;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\Processor;

/** Shared consequences of single and bulk admin decisions; source fetching is separate. */
final class FighterLifecycleService {
    public static function changed(int $id,int $actor,array $before,array $after,bool $sync_post=true,bool $refresh=true): void {
        \MMAF\DataEngine\Services\Operations\Readiness::invalidate($id);
        if ($sync_post) { (new FighterPostSyncService(new FighterRepository()))->sync($id,$after); }
        if (!\MMAF\DataEngine\Services\Operations\Jobs::$current) { Processor::enqueue($id,$actor); }
        if ($refresh && ($before!==$after) && (!empty($before['is_public']) || !empty($before['is_rankable']) || !empty($after['is_public']) || !empty($after['is_rankable']))) { Processor::request_refresh($actor,true); }
        do_action('mmaf_public_data_changed',!empty($before['is_public']) && empty($after['is_public'])?'fighter_hidden':'fighter');
    }
}
