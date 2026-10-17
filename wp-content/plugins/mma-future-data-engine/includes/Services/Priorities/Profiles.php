<?php
namespace MMAF\DataEngine\Services\Priorities;
use MMAF\DataEngine\Support\TapologyFighterUrl;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\Import\ProvisionalFighterFactory;
use MMAF\DataEngine\Services\RegularImport\ImportException;

final class Profiles {
    public static function plan(array $profile,array $bindings): ?array {
        $key=$profile['source_fighter_id']??'';
        if(!isset($bindings[$key])){return null;}
        $identity=TapologyFighterUrl::parse((string)($profile['source_url']??''));
        if(!$identity || $identity['source_fighter_id']!==$key){throw new ImportException('Priority profile source ID and URL disagree.','priority');}
        if(($profile['status']??'')!=='success' || !ProfileStore::date($profile['scraped_at']??$profile['profile_scraped_at']??null) || !trim((string)($profile['display_name']??''))){return null;}
        return ['ref_key'=>$key,'source_fighter_id'=>$key,'source_url'=>$identity['source_url'],'source_name'=>$profile['display_name'],'source_url_hash'=>$identity['source_url_hash'],'action'=>'create_provisional_candidate'];
    }
    public static function apply(array $profile,array $bindings,int $actor,int $job): ?array {
        $plan=self::plan($profile,$bindings);if(!$plan){return null;}
        $created=(new ProvisionalFighterFactory())->create(['source_fighter_id'=>$plan['source_fighter_id'],'url'=>$plan['source_url'],'name'=>$plan['source_name']],$actor);
        Registry::bindCreated($plan['source_fighter_id'],$created['id'],$actor);
        \MMAF\DataEngine\Services\Operations\Membership::record($job,$created['id'],'priority_profile');
        return $created;
    }
}
