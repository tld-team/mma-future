<?php
namespace MMAF\DataEngine\Services;
use MMAF\DataEngine\Services\RegularImport\WriteGuard;

/** One revision and one page-cache purge per successful request/bulk operation. */
final class PublicCacheService {
 // Bump when the cached public response contract or its storage format changes.
 // 6: values are stored as ASCII-only JSON strings instead of PHP-serialized arrays.
 private const CONTRACT_VERSION=6;
 private static bool $pending=false;
 public static function register(): void {
  add_action('mmaf_public_data_changed',array(self::class,'changed'));
  add_action('acf/save_post',static function(){self::changed('editorial');},30);
  add_action('save_post',static function($id){if(!wp_is_post_revision($id)&&!wp_is_post_autosave($id)){self::changed('post');}},30);
  foreach(array('added_post_meta','updated_post_meta','deleted_post_meta') as $hook){add_action($hook,static function($meta,$post,$key){if(in_array($key,array('_thumbnail_id','hero_image'),true)){self::changed('image');}},10,3);}
  add_action('shutdown',array(self::class,'flush'),999);
 }
 public static function changed(string $reason='data'): void {
  if(\MMAF\DataEngine\Services\Operations\Jobs::$current && !in_array($reason,['fighter_hidden','operation_completed'],true)){return;}
  if(WriteGuard::$worker&&$reason!=='import_completed'){return;}
  if(\MMAF\DataEngine\Services\RegularImport\Transaction::active()){\MMAF\DataEngine\Services\RegularImport\Transaction::after_commit(static function()use($reason){self::changed($reason);});return;}
  if(self::$pending){return;}
  self::$pending=true;
  update_option('mmaf_public_data_revision',wp_generate_uuid4(),false);
 }
 public static function key(string $type,array $args=[]): string {
  return 'mmaf_'.hash('sha256',self::CONTRACT_VERSION.'|'.$type.'|'.get_option('mmaf_public_data_revision','initial').'|'.(new \MMAF\DataEngine\Repositories\RankingRunRepository())->get_active_ranking_run_id().'|'.wp_json_encode($args));
 }
 public static function remember(string $type,array $args,callable $loader){
  $key=self::key($type,$args);$cached=self::decode(get_transient($key));
  if(null!==$cached){return $cached['value'];}
  $value=$loader();
  if(!is_wp_error($value)){$encoded=self::encode($value);if(null!==$encoded){set_transient($key,$encoded,5*MINUTE_IN_SECONDS);}}
  return $value;
 }
 /**
  * ASCII-only JSON (non-ASCII escaped as \uXXXX). wpdb only scans non-ASCII queries with
  * get_table_from_query(), whose regex exhausts pcre.backtrack_limit on ~1 MB payloads and
  * prints a deprecation notice into the REST body. Values that would not survive the JSON
  * round trip unchanged (objects, invalid UTF-8, NAN) are not cached.
  */
 public static function encode($value): ?string {
  $envelope=array('value'=>$value);
  $encoded=wp_json_encode($envelope,JSON_PRESERVE_ZERO_FRACTION);
  if(!is_string($encoded)||1===preg_match('/[^\x00-\x7F]/',$encoded)){return null;}
  return json_decode($encoded,true)===$envelope?$encoded:null;
 }
 /** @return array{value:mixed}|null Null for a cache miss: missing, legacy, non-string or undecodable values. */
 public static function decode($stored): ?array {
  if(!is_string($stored)||''===$stored){return null;}
  $decoded=json_decode($stored,true);
  return is_array($decoded)&&array_key_exists('value',$decoded)?$decoded:null;
 }
 public static function flush(): void {
  if(!self::$pending){return;}self::$pending=false;
  if(function_exists('wpfc_clear_all_cache')){wpfc_clear_all_cache();}
  elseif(isset($GLOBALS['wp_fastest_cache'])&&is_callable(array($GLOBALS['wp_fastest_cache'],'deleteCache'))){$GLOBALS['wp_fastest_cache']->deleteCache(true);}
 }
 public static function retry_invalidation(): bool {
  if(!get_option('mmaf_after_commit_error')){return false;}
  try{
   self::$pending=false;self::changed('invalidation_retry');self::flush();
   delete_option('mmaf_after_commit_error');return true;
  }catch(\Throwable $error){return false;}
 }
}
