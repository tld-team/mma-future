<?php
/** Explicit, repeatable content migration. No runtime fallbacks resurrect deleted copy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function mmaf_content_defaults(): array {
	$link = static function( $path, $title ) { return array( 'url' => '/rankings/' === $path ? mmaf_rankings_url() : home_url( $path ), 'title' => $title, 'target' => '' ); };
	return array(
		'about-hero-section' => array( 'section_title' => 'The next generation of MMA, ranked by results.', 'section_description' => 'MMA Future follows emerging professional fighters through recorded results and a transparent scoring formula.', 'primary_link' => $link( '/rankings/', 'Explore rankings' ), 'secondary_link' => $link( '/contact/', 'Contact us' ), 'badges' => array( array( 'badge_text' => 'Transparent methodology' ), array( 'badge_text' => 'Professional MMA results' ) ) ),
		'about-featured-section' => array( 'section_title' => 'Why MMA Future exists', 'section_description' => 'A consistent view of emerging fighters, built from professional MMA results and reviewed source data.', 'features' => array( array( 'feature_title' => 'Clarity over hype', 'feature_description' => 'Recorded results provide the foundation for every calculation.' ), array( 'feature_title' => 'A consistent formula', 'feature_description' => 'The same published scoring rules apply across each ranking board.' ), array( 'feature_title' => 'Transparency and review', 'feature_description' => 'Draft calculations are reviewed before a ranking becomes public.' ) ) ),
		'about-how-it-works-section' => array( 'section_title' => 'How it works', 'section_description' => 'From source results to a reviewed ranking.', 'steps' => array( array( 'step_title' => 'Collect and verify', 'step_description' => 'Source identities, professional MMA results and career coverage are checked before canonical import.' ), array( 'step_title' => 'Rebuild statistics', 'step_description' => 'Recorded wins, losses, draws and no contests are calculated from accepted fights.' ), array( 'step_title' => 'Review and activate', 'step_description' => 'Formula v1.5 produces a direct score total. A ranking draft becomes public only after activation.' ) ), 'principles' => array( array( 'principle_text' => 'Formula v1.5 includes win/loss, finish, age and opponent-record contributions where the required facts are known.' ), array( 'principle_text' => 'A score is a direct total. It is not a percentage or a score out of 100.' ), array( 'principle_text' => 'Career coverage, statistics and ranking activation are separate checks with separate dates.' ) ) ),
		'about-highlighted-data-section' => array( 'section_title' => 'Data, updates and limitations', 'data_sources_text' => '<p>Tapology source identities and recorded professional MMA results support the canonical data model. Editorial content and manually verified facts are maintained separately.</p>', 'update_schedule_text' => 'Imports, statistics rebuilds, ranking calculations and ranking activations happen separately. Each public view shows its relevant update dates.', 'limitations_text' => 'Recorded fights may represent a partial career. Complete professional MMA history is shown only when the source coverage and W/L/D/NC totals have been verified.' ),
		'about-faq-section' => array( 'section_title' => 'Frequently asked questions', 'section_description' => 'Understanding the rankings and their coverage.' ),
		'about-founder-bio-section' => array( 'section_title' => 'Meet the founder' ),
		'about-our-team-section' => array( 'section_title' => 'Our team', 'section_description' => 'Interested in contributing to MMA Future?', 'primary_link' => $link( '/contact/', 'Get in touch' ) ),
		'about-partners-logos-section' => array( 'section_title' => 'Our partners', 'disclaimer' => '' ),
		'about-cta-section' => array( 'section_title' => 'Explore the future of MMA', 'section_description' => 'Discover the active rankings or get in touch with the team.', 'primary_link' => $link( '/rankings/', 'View rankings' ), 'secondary_link' => $link( '/contact/', 'Contact us' ) ),
		'short-ranking-overview' => array( 'section_title' => 'Ranking overview', 'section_description' => 'The leading fighters in the active ranking.', 'ranking_board' => 'overall', 'top_five_count' => 5, 'show_top_movers' => 1, 'top_movers_title' => 'Top Movers', 'primary_link' => $link( '/rankings/', 'View all rankings' ) ),
		'all-rankings-table-section' => array( 'section_title' => 'MMA Future Rankings', 'section_description' => 'Explore the active rankings by board, fighter and score.', 'default_board' => 'male', 'rows_per_page' => 25, 'show_score_breakdown' => 1, 'empty_state_text' => 'No rankings match these filters.' ),
		'blog-latest-posts-section' => array( 'section_title' => 'Latest from the blog', 'section_description' => 'Analysis, breakdowns, and insights from the world of MMA.', 'posts_count' => 4, 'primary_link' => $link( '/blog/', 'View all articles' ) ),
		'contact-main-section' => array( 'section_title' => 'Contact MMA Future', 'section_description' => 'Questions about the data, a correction or an idea for collaboration? Get in touch.', 'quick_links' => array( array( 'link' => $link( '/about/', 'About & Methodology' ) ), array( 'link' => $link( '/rankings/', 'Explore rankings' ) ) ), 'form_title' => 'Send a message', 'submit_label' => 'Send message', 'privacy_notice' => 'Your message is sent to our team by email. Message contents are not stored in the website database.', 'contact_reasons' => array( array( 'reason_title' => 'Data correction', 'reason_description' => 'Help us verify a fighter, result or source identity.' ), array( 'reason_title' => 'Collaboration', 'reason_description' => 'Discuss a contribution or collaboration.' ), array( 'reason_title' => 'General enquiry', 'reason_description' => 'Ask about MMA Future.' ) ), 'form_name_label' => 'Name', 'form_name_placeholder' => 'Your name', 'form_email_label' => 'Email', 'form_email_placeholder' => 'you@example.com', 'form_organization_label' => 'Organization', 'form_organization_placeholder' => 'Optional', 'form_website_label' => 'Website', 'form_website_placeholder' => 'https://', 'form_reason_label' => 'Reason', 'form_reason_placeholder' => 'Choose a reason', 'form_message_label' => 'Message', 'form_message_placeholder' => 'How can we help?' ),
	);
}
function mmaf_migrate_content( $apply = false ): array {
	if ( ! function_exists( 'acf_get_field_groups' ) ) { throw new RuntimeException( 'ACF PRO is required.' ); }
	$keys = array();
	foreach ( acf_get_field_groups() as $group ) {
		foreach ( $group['location'] as $and ) { foreach ( $and as $rule ) {
			if ( in_array( $rule['param'], array( 'block', 'options_page' ), true ) ) { $keys[$rule['value']] = acf_get_fields( $group ); }
		} }
	}
	$encode = static function( $fields, $values, $prefix = '' ) use ( &$encode ) {
		$data = array();
		foreach ( $fields as $field ) {
			$name = $field['name']; if ( ! array_key_exists( $name, $values ) ) { continue; }
			$key = $prefix . $name; $value = $values[$name];
			$data[$key] = 'repeater' === $field['type'] ? count( $value ) : $value; $data['_' . $key] = $field['key'];
			if ( 'repeater' === $field['type'] ) { foreach ( $value as $index => $row ) { $data += $encode( $field['sub_fields'], $row, $key . '_' . $index . '_' ); } }
		}
		return $data;
	};
	$report = array( 'apply' => $apply, 'pages' => array(), 'options' => array(), 'review' => array( 'Demo founders, team members and partner logos were not published as confirmed content.' ) );
	$defaults = mmaf_content_defaults();
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1 ) ) as $page ) {
		$changed = false;
		$walk = static function( $blocks ) use ( &$walk, &$changed, $defaults, $keys, $encode ) {
			foreach ( $blocks as &$block ) {
				$name = str_replace( 'acf/', '', $block['blockName'] ?? '' );
				if ( isset( $defaults[$name] ) && isset( $keys[$block['blockName']] ) ) {
					$old = $block['attrs']['data'] ?? array(); $values = array();
					foreach ( $defaults[$name] as $key => $value ) { if ( ! array_key_exists( $key, $old ) && ! array_key_exists( '_' . $key, $old ) ) { $values[$key] = $value; } }
					if ( $values ) { $block['attrs']['data'] = $old + $encode( $keys[$block['blockName']], $values ); $changed = true; }
				}
				$block['innerBlocks'] = $walk( $block['innerBlocks'] );
			} unset( $block ); return $blocks;
		};
		$blocks = $walk( parse_blocks( $page->post_content ) );
		if ( $changed ) { $report['pages'][] = array( 'id' => $page->ID, 'title' => $page->post_title ); if ( $apply ) { $result = wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => serialize_blocks( $blocks ) ) ), true ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } } }
	}
	$settings = array( 'top_bar_enabled' => 1, 'top_bar_label' => 'Latest updates', 'top_bar_scroll_duration' => 38, 'top_bar_messages' => array( array( 'message_text' => 'Explore the active MMA Future rankings', 'message_enabled' => 1, 'message_link' => array( 'url' => mmaf_rankings_url(), 'title' => 'Explore rankings', 'target' => '' ) ), array( 'message_text' => 'Learn how the MMA Future Score is calculated', 'message_enabled' => 1, 'message_link' => array( 'url' => home_url( '/about/' ), 'title' => 'Our methodology', 'target' => '' ) ) ), 'footer_description' => 'Follow emerging MMA fighters through recorded results and transparent rankings.', 'footer_menu_titles' => array( 'explore' => 'Explore', 'resources' => 'Resources', 'contact' => 'Contact' ), 'footer_copyright' => 'MMA Future. All rights reserved.', 'contact_success_message' => 'Your message has been accepted. Thank you for contacting MMA Future.', 'contact_error_message' => 'Unable to send your message. Please try again later.', 'faq_items' => array( array( 'question' => 'Is the MMA Future Score out of 100?', 'answer' => '<p>No. Formula v1.5 produces a direct total from its score contributions.</p>', 'show_on' => 'both' ), array( 'question' => 'Why do the statistics and rankings show different dates?', 'answer' => '<p>Statistics can be rebuilt after an import. A ranking changes only after a reviewed calculation is activated.</p>', 'show_on' => 'both' ), array( 'question' => 'Does every profile include a complete career?', 'answer' => '<p>Only verified complete careers use the complete-history label. Other profiles show recorded fights and the available coverage information.</p>', 'show_on' => 'both' ) ) );
	foreach ( $keys['mmaf-site-settings'] ?? array() as $field ) {
		$name = $field['name']; if ( ! array_key_exists( $name, $settings ) || false !== get_option( 'options_' . $name, false ) || false !== get_option( '_options_' . $name, false ) ) { continue; }
		$report['options'][] = $name; if ( $apply ) { update_field( $field['key'], $settings[$name], 'option' ); }
	}
	$report['menus'] = mmaf_migrate_footer_menus( $apply );
	$report['navigation'] = mmaf_migrate_navigation( $apply );
	return $report;
}
/** Replace only known prototype links; keep user-created navigation and legacy pages intact. */
function mmaf_migrate_navigation( bool $apply ): array {
	$locations = get_nav_menu_locations();
	$menu_id = (int) ( $locations['menu-1'] ?? 0 );
	$changes = array();
	foreach ( $menu_id ? ( wp_get_nav_menu_items( $menu_id ) ?: array() ) : array() as $item ) {
		$url = null;
		$title = $item->title;
		if ( 'Rankings' === $title && '#rankings' === $item->url ) { $url = mmaf_rankings_url(); }
		if ( 'Single Fighter (test e.g.)' === $title && '/single-fighter/' === wp_parse_url( $item->url, PHP_URL_PATH ) ) {
			$url = get_post_type_archive_link( 'mmaf_fighter' );
			$title = 'Fighters';
		}
		if ( ! $url ) { continue; }
		$changes[] = array( 'id' => $item->ID, 'old_title' => $item->title, 'old_url' => $item->url, 'title' => $title, 'url' => $url );
		if ( $apply ) {
			$result = wp_update_nav_menu_item( $menu_id, $item->ID, array(
				'menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-type' => 'custom',
				'menu-item-status' => $item->post_status, 'menu-item-position' => $item->menu_order,
				'menu-item-parent-id' => $item->menu_item_parent, 'menu-item-target' => $item->target,
				'menu-item-classes' => implode( ' ', $item->classes ), 'menu-item-xfn' => $item->xfn,
				'menu-item-description' => $item->description, 'menu-item-attr-title' => $item->attr_title,
			) );
			if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
		}
	}
	if ( $apply && $changes ) { do_action( 'mmaf_public_data_changed', 'menus' ); }
	return $changes;
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'mmaf frontend migrate', static function( $args, $assoc ) {
		if ( isset( $assoc['apply'] ) && ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) ) { WP_CLI::error( 'This migration is limited to local/dev/staging.' ); }
		WP_CLI::line( wp_json_encode( mmaf_migrate_content( isset( $assoc['apply'] ) ), JSON_PRETTY_PRINT ) );
	} );
}

/** Seed missing menu locations once. Existing menus and deliberately empty assigned menus remain intact. */
function mmaf_migrate_footer_menus( bool $apply ): array {
 $locations = get_theme_mod('nav_menu_locations',array());$report=array();
 $items=array('explore'=>array('Home'=>'/','Rankings'=>'/rankings/','Fighters'=>'/fighters/','Blog'=>'/blog/'),'resources'=>array('About'=>'/about/','Contact'=>'/contact/'));
 foreach($items as $column=>$links){
  $location='footer-'.$column;if(!empty($locations[$location])){continue;}
  $report[]=$location;if(!$apply){continue;}
  $name='MMA Future Footer '.ucfirst($column);$menu=wp_get_nav_menu_object($name);$id=$menu?(int)$menu->term_id:wp_create_nav_menu($name);
  if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}
  if(!wp_get_nav_menu_items($id)){foreach($links as $label=>$path){$saved=wp_update_nav_menu_item($id,0,array('menu-item-title'=>$label,'menu-item-url'=>$path==='/rankings/'?mmaf_rankings_url():home_url($path),'menu-item-status'=>'publish','menu-item-type'=>'custom'));if(is_wp_error($saved)){throw new RuntimeException($saved->get_error_message());}}}
  $locations[$location]=$id;
 }
 if($apply&&$report){set_theme_mod('nav_menu_locations',$locations);do_action('mmaf_public_data_changed','menus');}
 return $report;
}

/** Read-only content/identity inventory. Values and internal mail settings are never emitted. */
function mmaf_frontend_inventory(): array {
 $report=array('blocks'=>array(),'legacy_profiles'=>array(),'legacy_relations'=>array(),'retired_field_values'=>array());
 $walk=static function($blocks,$post_id)use(&$walk,&$report){foreach($blocks as $block){$name=$block['blockName']??'';if(str_starts_with($name,'acf/')){
  if(!isset($report['blocks'][$name])){$report['blocks'][$name]=array('instances'=>0,'post_ids'=>array(),'field_names'=>array());}
  ++$report['blocks'][$name]['instances'];$report['blocks'][$name]['post_ids'][]=$post_id;
  foreach(($block['attrs']['data']??array())as$key=>$value){if(!str_starts_with($key,'_')){$report['blocks'][$name]['field_names'][$key]=true;}
   if(in_array($key,array('test','single-fighter-detail'),true)&&!empty($value)){$report['retired_field_values'][]=array('post_id'=>$post_id,'block'=>$name,'field'=>$key,'requires_review'=>true);}}
 }$walk($block['innerBlocks']??array(),$post_id);}};
 foreach(get_posts(array('post_type'=>array('page','post','fighter'),'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1))as$post){
  $walk(parse_blocks($post->post_content),$post->ID);
  if($post->post_type==='fighter'){$id=(int)mmaf_site_field('legacy_canonical_fighter_id',$post->ID);$report['legacy_profiles'][]=array('post_id'=>$post->ID,'title'=>$post->post_title,'status'=>$id?'explicit_mapping':'source_identity_missing','canonical_id'=>$id?:null);}
 }
 foreach($report['blocks'] as &$row){$row['post_ids']=array_values(array_unique($row['post_ids']));$row['field_names']=array_keys($row['field_names']);}unset($row);
 return $report;
}
if(defined('WP_CLI')&&WP_CLI){WP_CLI::add_command('mmaf frontend audit',static function(){WP_CLI::line(wp_json_encode(mmaf_frontend_inventory(),JSON_PRETTY_PRINT));});}

/** Repair only exact signatures of defaults damaged by the previous unslashed seed. */
function mmaf_repair_seeded_content( bool $apply = false ): array {
 $report = array( 'apply' => $apply, 'pages' => array(), 'fields' => array() );
 $defaults = mmaf_content_defaults();
 $flatten = static function( array $values, string $prefix = '' ) use ( &$flatten ): array {
  $out = array();
  foreach ( $values as $key => $value ) {
   $name = $prefix . $key;
   if ( is_array( $value ) && array_is_list( $value ) ) { foreach ( $value as $index => $row ) { if ( is_array( $row ) ) { $out += $flatten( $row, $name . '_' . $index . '_' ); } } }
   else { $out[$name] = $value; }
  }
  return $out;
 };
 $signatures = array();
 foreach ( $defaults as $name => $values ) {
  foreach ( $flatten( $values ) as $key => $value ) {
   if ( ! is_string( $value ) ) { continue; }
   $broken = json_decode( wp_unslash( serialize_block_attributes( array( 'value' => $value ) ) ), true );
   if ( isset( $broken['value'] ) && $broken['value'] !== $value ) { $signatures['acf/' . $name][$key] = array( $broken['value'], $value ); }
  }
 }
 foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1 ) ) as $page ) {
  $changed = false;
  $walk = static function( array $blocks ) use ( &$walk, &$changed, &$report, $signatures, $page ): array {
   foreach ( $blocks as &$block ) {
    foreach ( $signatures[$block['blockName'] ?? ''] ?? array() as $key => [$damaged, $original] ) {
     if ( isset( $block['attrs']['data'][$key] ) && $block['attrs']['data'][$key] === $damaged ) {
      $block['attrs']['data'][$key] = $original; $changed = true;
      $report['fields'][] = array( 'post_id' => $page->ID, 'block' => $block['blockName'], 'field' => $key );
     }
    }
    $block['innerBlocks'] = $walk( $block['innerBlocks'] ?? array() );
   } unset( $block );
   return $blocks;
  };
  $blocks = $walk( parse_blocks( $page->post_content ) );
  if ( ! $changed ) { continue; }
  $report['pages'][] = $page->ID;
  if ( $apply ) {
   $result = wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => serialize_blocks( $blocks ) ) ), true );
   if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
  }
 }
 if ( $apply && $report['pages'] ) { do_action( 'mmaf_public_data_changed', 'content_repair' ); }
 return $report;
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
 WP_CLI::add_command( 'mmaf frontend repair-content', static function( $args, $assoc ) {
  if ( isset( $assoc['apply'] ) && ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) ) { WP_CLI::error( 'This repair is limited to local/dev/staging.' ); }
  WP_CLI::line( wp_json_encode( mmaf_repair_seeded_content( isset( $assoc['apply'] ) ), JSON_PRETTY_PRINT ) );
 } );
}
