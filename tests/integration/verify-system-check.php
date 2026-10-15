<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/synthetic-fixtures.php';
use MMAF\DataEngine\Migrations\MigrationRunner;
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard,Repository};
use MMAF\DataEngine\Services\System\SystemCheckService;
function diagnostic_check($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
MigrationRunner::run();global $wpdb;
Transaction::begin();WriteGuard::$worker=true;wp_set_current_user(1);
try{
    do_action('rest_api_init');$routes=rest_get_server()->get_routes();
    $probe=new ReflectionMethod(SystemCheckService::class,'public_write_routes');$service=new SystemCheckService();
    diagnostic_check($probe->invoke($service,$routes)===[] && get_current_user_id()===1,'registered import and operations write permissions deny anonymous users and restore the administrator identity');
    $subscriber=wp_insert_user(['user_login'=>'diagnostic-'.wp_generate_uuid4(),'user_pass'=>'isolated-fixture-only','role'=>'subscriber']);
    wp_set_current_user($subscriber);$checked=0;
    foreach($routes as $route=>$handlers){
        if(!str_starts_with($route,'/mma-future/v1/')){continue;}
        foreach($handlers as $handler){
            if(!is_array($handler) || !is_array($handler['methods']??null)){continue;}
            foreach($handler['methods'] as $method=>$enabled){
                if(!$enabled || in_array($method,['GET','HEAD','OPTIONS'],true)){continue;}
                $request=new WP_REST_Request($method,$route);$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
                $result=call_user_func($handler['permission_callback'],$request);
                diagnostic_check($result===false || $result===null || is_wp_error($result),'subscriber with a valid nonce cannot write '.$method.' '.$route);++$checked;
            }
        }
    }
    diagnostic_check($checked>=7,'all current importer and operations mutation permissions are exercised without executing handlers');
    wp_set_current_user(1);$handler_calls=0;$handler=static function()use(&$handler_calls){++$handler_calls;throw new RuntimeException('A diagnostic must never execute a mutation.');};
    $faults=[
        '/mma-future/v1/fixture-public'=>[['methods'=>['POST'=>true],'permission_callback'=>'__return_true','callback'=>$handler]],
        '/mma-future/v1/fixture-missing'=>[['methods'=>['PUT'=>true],'callback'=>$handler]],
        '/mma-future/v1/fixture-throws'=>[['methods'=>['DELETE'=>true],'permission_callback'=>static function(){throw new RuntimeException('Broken permission callback');},'callback'=>$handler]],
        '/mma-future/v1/fixture-denied'=>[['methods'=>['POST'=>true],'permission_callback'=>static fn()=>new WP_Error('denied','Not permitted',['status'=>403]),'callback'=>$handler]],
        '/mma-future/v1/fixture-disabled'=>[['methods'=>['POST'=>false,'OPTIONS'=>true],'permission_callback'=>'__return_true','callback'=>$handler]],
        '/mma-future/v10/unrelated'=>[['methods'=>['POST'=>true],'permission_callback'=>'__return_true','callback'=>$handler]],
    ];
    diagnostic_check($probe->invoke($service,array_merge($routes,$faults))===['POST /mma-future/v1/fixture-public','PUT /mma-future/v1/fixture-missing','DELETE /mma-future/v1/fixture-throws'],'exposed, missing and broken permissions fail while explicit denial, disabled methods and unrelated namespaces do not');
    diagnostic_check($handler_calls===0 && get_current_user_id()===1,'fault probes never execute mutation handlers and restore the caller even after a permission exception');
    fixture_canonical_baseline();$t=MMAF\DataEngine\Migrations\Schema::table_names();
    $id=$wpdb->get_var("SELECT bout_id FROM {$t['bout_sources']} WHERE source_bout_id='tapology_bout_949899910'");
    diagnostic_check((int)$id>0,'an accepted synthetic bout is available for the unknown-method regression');
    Repository::check($wpdb->update($t['bouts'],['method_category'=>'unknown','is_scoring_candidate'=>1],['id'=>$id]));
    $bout_check=new ReflectionMethod(SystemCheckService::class,'check_bout_integrity');$bout_check->invoke($service);
    $checks=(new ReflectionProperty(SystemCheckService::class,'checks'))->getValue($service);$methods=array_column($checks,null,'key');
    diagnostic_check($methods['scoring_candidates_unknown_method']['status']==='warning' && $methods['scoring_candidates_unknown_method']['value']>=1,'unknown finish methods remain visible as a neutral-formula quality warning');
    diagnostic_check($methods['malformed_bouts']['status']==='pass','neutral unknown methods do not change structural bout integrity');
    echo 'METRICS '.wp_json_encode(['peak_memory'=>memory_get_peak_usage(true)])."\n";
}finally{Transaction::rollback();WriteGuard::$worker=false;wp_set_current_user(0);wp_cache_flush();}
