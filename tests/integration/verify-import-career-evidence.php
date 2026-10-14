<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';

use MMAF\DataEngine\Migrations\MigrationRunner;
use MMAF\DataEngine\Services\DataPipeline\{ProfileStore,Processor};
use MMAF\DataEngine\Services\RegularImport\{Engine,Repository,Transaction,WriteGuard};

function check($ok,string $reason): void {
    if(!$ok){throw new RuntimeException($reason);}
    echo "PASS: $reason\n";
}
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
MigrationRunner::run();
wp_set_current_user(1);
add_filter('pre_http_request',static function(){throw new RuntimeException('Stored preparation attempted a network request.');});
WriteGuard::$worker=true;
Transaction::begin();
try {
    global $wpdb;
    $repo=new Repository();
    $kind='actual_stored_career_profile';
    $legacy='actual_stored_career_pro';
    $column=$wpdb->get_row("SHOW COLUMNS FROM {$repo->tables['items']} WHERE Field='kind'",ARRAY_A);
    check($column['Type']==='varchar(64)','import evidence storage preserves complete career labels');
    $fighter=fixture_fighter(948877701,'Synthetic evidence fighter',['is_public'=>1]);
    $opponent=fixture_fighter(948877702,'Synthetic evidence opponent');
    $profile=fixture_profile($fighter,[fixture_fight(948877703,$opponent)],['wins'=>1,'losses'=>0,'draws'=>0,'no_contests'=>0]);
    (new ProfileStore())->put($profile,'tapology_fighter_profiles_v0_1');
    $job=$repo->create('synthetic-career-evidence.zip',1,1);
    $result=(new Processor())->prepare($fighter['fighter_id'],1,$job);
    $items=$repo->items($job,$kind);
    check(!empty($result['done']) && count($items)===1 && $items[0]['kind']===$kind,'real stored preparation retains its full evidence kind');
    check(isset((new Engine())->actualCounts($job)['stored_career_profile']),'actual import counters include prepared careers');
    $refresh=Processor::refresh_import_coverage($job,0,10);
    check($refresh['cursor']===(int)$items[0]['id'] && $refresh['done'],'final verification reads stored career evidence');
    $items=$repo->items($job,$kind);
    check(json_decode($items[0]['payload_json'],true)['coverage']['status']==='complete','final career verification preserves confirmed full coverage');

    $saved=$items[0];
    Repository::check($wpdb->update($repo->tables['items'],['kind'=>$legacy],['id'=>$saved['id']]));
    // Invoke only the DML repair inside the fixture transaction: schema DDL commits.
    $repair=new ReflectionMethod(MigrationRunner::class,'repair_import_item_kinds');
    $repair->invoke(null);
    $restored=$repo->items($job,$kind)[0];
    check($restored['id']===$saved['id'] && $restored['payload_json']===$saved['payload_json'] && $restored['category']===$saved['category'],'migration restores truncated evidence without changing its identity, contents or outcome');
    $repair->invoke(null);
    check(count($repo->items($job,$kind))===1 && !$repo->items($job,$legacy),'career evidence repair is idempotent');

    $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$repo->tables['items']}");
    try {
        $repo->putItem($job,str_repeat('x',65),'oversized','fixture','review',[]);
        throw new RuntimeException('Oversized kind accepted');
    } catch(InvalidArgumentException $e) {
        check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$repo->tables['items']}")===$before,'oversized item labels fail before writing truncated evidence');
    }
    $repo->putItem($job,$legacy,(string)$fighter['fighter_id'],$fighter['source_fighter_id'],'review',['legacy'=>'conflicting fixture']);
    try {
        $repair->invoke(null);
        throw new RuntimeException('Conflicting evidence overwritten');
    } catch(RuntimeException $e) {
        check(strpos($e->getMessage(),'Conflicting career evidence labels')===0 && count($repo->items($job,$kind))===1 && count($repo->items($job,$legacy))===1,'conflicting legacy and full evidence is retained and explicitly blocks repair');
    }
} finally {
    Transaction::rollback();
    WriteGuard::$worker=false;
    wp_cache_flush();
}
