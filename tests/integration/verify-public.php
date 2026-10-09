<?php
require __DIR__.'/bootstrap.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);exit(1);});
use MMAF\DataEngine\Services\PublicReadService;
use MMAF\DataEngine\Services\PublicCacheService;
use MMAF\DataEngine\Services\RegularImport\Transaction;
use MMAF\DataEngine\Migrations\Schema;
function expect($ok,$label){if(!$ok){throw new RuntimeException($label);}echo "PASS: $label\n";}
$t=Schema::table_names();$service=new PublicReadService();
$all=$service->rankings(['board'=>'male','per_page'=>100]);expect(!is_wp_error($all)&&$all['total']>25,'published canonical rankings cover multiple pages');
$second=$service->rankings(['board'=>'male','page'=>2,'per_page'=>25]);$target=$second['items'][0];
$found=$service->rankings(['board'=>'male','q'=>$target['display_name'],'per_page'=>25]);expect(count($found['items'])===1&&$found['items'][0]['rank']===$target['rank'],'search covers the full board and preserves canonical rank');
$top=$service->rankings(['board'=>'male','top15'=>true,'per_page'=>100]);expect(count($top['items'])===15&&max(array_column($top['items'],'rank'))<=15,'Top 15 applies before pagination');
$desc=$service->rankings(['board'=>'male','sort'=>'score','order'=>'desc']);expect($desc['items'][0]['score']>=$desc['items'][1]['score'],'score sorting uses the entire selected board');
$id=(int)$all['items'][0]['fighter_id'];wp_set_current_user(0);$anon=$service->fighter($id);wp_set_current_user(1);$admin=$service->fighter($id);expect($anon===$admin,'administrator and anonymous visitors receive identical public profiles');
expect($admin['dates']['ranking_calculated_at']!==$admin['dates']['stats_updated_at'],'ranking and statistics dates remain separate');
$s=$service->stats(['wins'=>0,'losses'=>0,'draws'=>0,'nc'=>2,'pro_fights_count'=>2,'ko_tko_wins'=>0,'submission_wins'=>0,'decision_wins'=>0,'finish_wins'=>0,'last_fight_date'=>null,'streak'=>null,'recent_form'=>null,'activity_status'=>'no_fights','finish_rate'=>null,'calculated_at'=>'2026-10-08 10:00:00']);expect($s['wins']===0&&$s['win_rate']===null,'known zero and unknown percentage remain distinct');
Transaction::begin();
try{
 $post_id=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_post_id FROM {$t['fighters']} WHERE id=%d",$id));
 wp_update_post(['ID'=>$post_id,'post_status'=>'draft']);expect(is_wp_error($service->fighter($id))&&is_wp_error($service->history($id)),'canonical public fighter with draft post has no public profile or history');
 wp_update_post(['ID'=>$post_id,'post_status'=>'publish']);$wpdb->update($t['fighters'],['is_public'=>0],['id'=>$id]);
 expect(is_wp_error($service->fighter($id)),'hidden fighter is unavailable even to an administrator');
 $q=new WP_Query(['post_type'=>'mmaf_fighter','p'=>$post_id,'post_status'=>'publish']);expect(!$q->have_posts(),'WordPress front queries respect canonical privacy');
 $rest=rest_do_request(new WP_REST_Request('GET','/wp/v2/mmaf_fighter/'.$post_id));expect($rest->get_status()===404,'core WordPress public REST respects canonical privacy');
 $wpdb->update($t['fighters'],['is_public'=>1],['id'=>$id]);
 $page=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' LIMIT 1");$wpdb->update($t['fighters'],['wp_post_id'=>$page],['id'=>$id]);expect(is_wp_error($service->fighter($id)),'a linked post of the wrong type is unavailable');
}finally{Transaction::rollback();clean_post_cache($post_id);}
$history=$service->history($id,['per_page'=>10]);expect(!is_wp_error($history)&&count($history['items'])<=10&&$history['total_pages']===(int)ceil($history['total']/10),'fight history pagination reports accurate totals');
$private=false;foreach($history['items'] as $fight){if(!$fight['opponent']['permalink']){$private=true;}}
expect($private,'private opponents retain a name without a profile link');
PublicCacheService::flush();$old=PublicCacheService::key('proof');do_action('mmaf_public_data_changed','stats');$new=PublicCacheService::key('proof');expect($old!==$new,'successful data changes invalidate application cache keys');
echo "Public contract checks completed.\n";
