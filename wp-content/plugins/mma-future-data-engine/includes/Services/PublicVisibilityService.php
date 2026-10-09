<?php
namespace MMAF\DataEngine\Services;
use MMAF\DataEngine\Migrations\Schema;

/** Canonical publication decisions also govern WordPress archives, search and core REST. */
final class PublicVisibilityService {
 public static function register(): void {
  add_filter('posts_where',array(self::class,'where'),10,2);
  add_filter('rest_mmaf_fighter_query',array(self::class,'rest_query'),10,2);
  add_filter('rest_pre_dispatch',array(self::class,'rest_single'),10,3);
 }
 public static function where(string $where,\WP_Query $query): string {
  global $wpdb;
  if(is_admin() || ($query->get('mmaf_private_editor') && defined('REST_REQUEST') && REST_REQUEST && current_user_can('manage_options'))){return $where;}
  $table=Schema::table_names()['fighters'];
  return $where." AND ({$wpdb->posts}.post_type<>'mmaf_fighter' OR EXISTS (SELECT 1 FROM $table public_fighter WHERE public_fighter.wp_post_id={$wpdb->posts}.ID AND public_fighter.is_public=1 AND public_fighter.deleted_soft=0 AND {$wpdb->posts}.post_status='publish'))";
 }
 public static function rest_query(array $args,\WP_REST_Request $request): array {
  if($request->get_param('context')==='edit'&&current_user_can('manage_options')){$args['mmaf_private_editor']=true;}
  return $args;
 }
 public static function rest_single($result,\WP_REST_Server $server,\WP_REST_Request $request){
  if(!in_array($request->get_method(),array('GET','HEAD'),true)||!preg_match('~^/wp/v2/mmaf_fighter/(\d+)$~D',$request->get_route(),$match)){return $result;}
  if($request->get_param('context')==='edit'&&current_user_can('edit_post',(int)$match[1])){return $result;}
  global $wpdb;$table=Schema::table_names()['fighters'];
  $visible=$wpdb->get_var($wpdb->prepare("SELECT f.id FROM $table f JOIN {$wpdb->posts} p ON p.ID=f.wp_post_id WHERE f.wp_post_id=%d AND f.is_public=1 AND f.deleted_soft=0 AND p.post_type='mmaf_fighter' AND p.post_status='publish'",(int)$match[1]));
  return $visible?$result:new \WP_Error('rest_post_invalid_id','Invalid post ID.',array('status'=>404));
 }
}
