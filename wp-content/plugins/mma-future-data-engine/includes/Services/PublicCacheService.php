<?php
namespace MMAF\DataEngine\Services;
use MMAF\DataEngine\Services\RegularImport\WriteGuard;

/** One revision and one page-cache purge per successful request/bulk operation. */
final class PublicCacheService {
 private static bool $pending=false;
 public static function register(): void {
  add_action('mmaf_public_data_changed',array(self::class,'changed'));
  add_action('acf/save_post',static function(){self::changed('editorial');},30);
  add_action('save_post',static function($id){if(!wp_is_post_revision($id)&&!wp_is_post_autosave($id)){self::changed('post');}},30);
  foreach(array('added_post_meta','updated_post_meta','deleted_post_meta') as $hook){add_action($hook,static function($meta,$post,$key){if($key==='_thumbnail_id'){self::changed('image');}},10,3);}
  add_action('shutdown',array(self::class,'flush'),999);
 }
 public static function changed(string $reason='data'): void {
  if(WriteGuard::$worker&&$reason!=='import_completed'){return;}
  if(self::$pending){return;}
  self::$pending=true;
  update_option('mmaf_public_data_revision',wp_generate_uuid4(),false);
 }
 public static function key(string $type,array $args=[]): string {
  return 'mmaf_'.hash('sha256',$type.'|'.get_option('mmaf_public_data_revision','initial').'|'.(new \MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id().'|'.wp_json_encode($args));
 }
 public static function remember(string $type,array $args,callable $loader){
  $key=self::key($type,$args);$value=get_transient($key);
  if(false!==$value){return $value;}
  $value=$loader();if(!is_wp_error($value)){set_transient($key,$value,5*MINUTE_IN_SECONDS);}return $value;
 }
 public static function flush(): void {
  if(!self::$pending){return;}self::$pending=false;
  if(function_exists('wpfc_clear_all_cache')){wpfc_clear_all_cache();}
  elseif(isset($GLOBALS['wp_fastest_cache'])&&is_callable(array($GLOBALS['wp_fastest_cache'],'deleteCache'))){$GLOBALS['wp_fastest_cache']->deleteCache(true);}
 }
}
