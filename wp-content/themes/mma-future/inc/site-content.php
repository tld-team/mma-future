<?php
/** Editorial content belongs to ACF; canonical facts belong to the Data Engine. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_site_field( $name, $context = false ) {
	return function_exists( 'get_field' ) ? get_field( $name, $context ) : null;
}
function mmaf_site_setting( $name ) { return mmaf_site_field( $name, 'option' ); }
function mmaf_public_service() {
	return class_exists( '\MMAF\DataEngine\Services\PublicReadService' ) ? new \MMAF\DataEngine\Services\PublicReadService() : null;
}
function mmaf_site_image( $value, $size = 'medium', $attributes = array() ) {
	$id = is_array( $value ) ? (int) ( $value['ID'] ?? $value['id'] ?? 0 ) : (int) $value;
	return $id ? wp_get_attachment_image( $id, $size, false, $attributes ) : '';
}
function mmaf_site_link( $link, $class = 'mmaf-button' ) {
	if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['title'] ) ) { return; }
	$target = '_blank' === ( $link['target'] ?? '' ) ? '_blank' : '_self';
	printf( '<a class="%s" href="%s" target="%s"%s>%s</a>', esc_attr( $class ), esc_url( $link['url'] ), esc_attr( $target ), '_blank' === $target ? ' rel="noopener noreferrer"' : '', esc_html( $link['title'] ) );
}
function mmaf_section_heading( $heading = 'h2' ) {
	if ( $text = mmaf_site_field( 'eyebrow' ) ) { echo '<p class="mmaf-eyebrow">' . esc_html( $text ) . '</p>'; }
	if ( $text = mmaf_site_field( 'section_title' ) ) { echo '<' . $heading . '>' . esc_html( $text ) . '</' . $heading . '>'; }
	if ( $text = mmaf_site_field( 'section_description' ) ) { echo '<p class="mmaf-lead">' . esc_html( $text ) . '</p>'; }
}
function mmaf_section_links() {
	echo '<div class="mmaf-actions">'; mmaf_site_link( mmaf_site_field( 'primary_link' ) ); mmaf_site_link( mmaf_site_field( 'secondary_link' ), 'mmaf-button mmaf-button--secondary' ); echo '</div>';
}
add_action( 'after_setup_theme', static function() {
	register_nav_menus( array( 'footer-explore' => 'Footer — Explore', 'footer-resources' => 'Footer — Resources' ) );
} );
add_action( 'acf/init', static function() {
	if ( function_exists( 'acf_add_options_page' ) ) {
		acf_add_options_page( array( 'page_title' => 'MMA Future — Site Settings', 'menu_title' => 'Site Settings', 'menu_slug' => 'mmaf-site-settings', 'parent_slug' => 'mmaf', 'capability' => 'manage_options', 'redirect' => false, 'autoload' => false ) );
	}
	if ( ! class_exists( 'acf_field_select' ) ) { return; }
	class MMAF_Entity_Field extends acf_field_select {
		public function initialize() {
			$this->name = 'mmaf_entity'; $this->label = 'Canonical MMA entity'; $this->category = 'relational';
			$this->defaults = array( 'multiple' => 0, 'ui' => 1, 'ajax' => 1, 'allow_null' => 1, 'choices' => array(), 'return_format' => 'value', 'entity_type' => 'fighter' );
		}
		public function render_field( $field ) {
			$field['ui'] = 1; $field['ajax'] = 1; $field['choices'] = array();
			$repository = new \MMAF\DataEngine\Repositories\PublicReadRepository();
			foreach ( array_filter( (array) $field['value'] ) as $id ) {
				if ( 'event' === ( $field['entity_type'] ?? 'fighter' ) ) { $row = $repository->event( (int) $id ); $label = $row['event_name'] ?? null; }
				else { $row = ( new \MMAF\DataEngine\Repositories\FighterRepository() )->find( (int) $id ); $label = $row['display_name'] ?? null; }
				if ( $label ) { $field['choices'][(int) $id] = $label . ' (#' . (int) $id . ')'; }
			}
			parent::render_field( $field );
		}
		public function validate_value( $valid, $value, $field, $input ) {
			if ( ! $valid || ! $value ) { return $valid; }
			foreach ( (array) $value as $id ) {
				if ( ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return 'Choose a canonical entity from the search results.'; }
				$repo = new \MMAF\DataEngine\Repositories\PublicReadRepository();
				$exists = 'event' === ( $field['entity_type'] ?? '' ) ? $repo->event( (int) $id ) : mmaf_public_service()->fighter( (int) $id );
				if ( ! $exists || is_wp_error( $exists ) ) { return 'This entity is no longer available for public content.'; }
			}
			return $valid;
		}
	}
	acf_register_field_type( 'MMAF_Entity_Field' );
}, 5 );
add_filter( 'acf/fields/select/query', static function( $results, $options ) {
	$field = acf_get_field( $options['field_key'] ?? '' );
	if ( ! $field || 'mmaf_entity' !== $field['type'] ) { return $results; }
	if ( ! current_user_can( 'edit_posts' ) ) { return array(); }
	$rows = ( new \MMAF\DataEngine\Repositories\PublicReadRepository() )->entities( $field['entity_type'] ?? 'fighter', sanitize_text_field( $options['s'] ?? '' ), 20 );
	return array_map( static function( $r ) { return array( 'id' => (string) $r['id'], 'text' => $r['text'] . ' (#' . $r['id'] . ')' ); }, $rows );
}, 10, 2 );
foreach ( array( 'ranking_board', 'default_board' ) as $name ) {
	add_filter( 'acf/load_field/name=' . $name, static function( $field ) {
		$field['choices'] = \MMAF\DataEngine\Services\PublicReadService::board_labels(); return $field;
	} );
}
add_action( 'customize_register', static function( $manager ) {
	$manager->add_section( 'mmaf_site_settings', array( 'title' => 'MMA Future — Site Settings', 'priority' => 30, 'description' => '<a href="' . esc_url( admin_url( 'admin.php?page=mmaf-site-settings' ) ) . '">Edit Top Bar, Footer, Contact, Partners and FAQ</a>' ) );
	$manager->add_setting( 'mmaf_settings_shortcut', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	$manager->add_control( 'mmaf_settings_shortcut', array( 'section' => 'mmaf_site_settings', 'type' => 'hidden', 'label' => 'Use Site Settings to edit global content.' ) );
} );
add_action( 'wp_enqueue_scripts', static function() {
	$directory = get_template_directory(); $uri = get_template_directory_uri();
	wp_enqueue_style( 'mmaf-integration', $uri . '/assets/dist/css/integration.css', array( 'mma-main' ), (string) filemtime( $directory . '/assets/dist/css/integration.css' ) );
	wp_enqueue_script( 'mmaf-integration', $uri . '/assets/dist/js/integration.js', array(), (string) filemtime( $directory . '/assets/dist/js/integration.js' ), true );
	wp_localize_script( 'mmaf-integration', 'mmafSite', array( 'rest' => esc_url_raw( rest_url() ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
} );

function mmaf_render_top_bar() {
	$messages = array_values( array_filter( (array) mmaf_site_setting( 'top_bar_messages' ), static function( $m ) { return ! empty( $m['message_enabled'] ) && ! empty( $m['message_text'] ); } ) );
	if ( ! mmaf_site_setting( 'top_bar_enabled' ) || ! $messages ) { return; }
	$duration = max( 10, min( 180, (int) mmaf_site_setting( 'top_bar_scroll_duration' ) ) );
	echo '<div class="site-topbar' . ( count( $messages ) === 1 ? ' site-topbar--static' : '' ) . '" style="--mmaf-marquee-duration:' . $duration . 's"><div class="site-topbar__inner mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 min-h-10 py-2 flex items-center gap-4">';
	if ( $label = mmaf_site_setting( 'top_bar_label' ) ) { echo '<div class="site-topbar__label flex items-center gap-2 flex-none pr-4 border-r border-[#CBD5E1]"><span class="site-topbar__label-dot inline-block w-1.5 h-1.5 rounded-full bg-[#1D4E89]" aria-hidden="true"></span><span class="site-topbar__label-text text-[11px] sm:text-[12px] font-heading font-semibold uppercase tracking-wider">' . esc_html( $label ) . '</span></div>'; }
	echo '<div class="site-topbar__marquee flex-1 min-w-0 pl-1" aria-label="Site updates"><div class="site-topbar__fade-left" aria-hidden="true"></div><div class="site-topbar__fade-right" aria-hidden="true"></div><div class="site-topbar__track">';
	for ( $copy = 0; $copy < ( count( $messages ) > 1 ? 2 : 1 ); $copy++ ) {
		echo '<div class="mmaf-topbar-group flex items-center whitespace-nowrap pr-10"' . ( $copy ? ' aria-hidden="true" inert' : '' ) . '>';
		foreach ( $messages as $index => $message ) {
			if ( $index ) { echo '<span class="site-topbar__separator mx-4 text-[10px] leading-none" aria-hidden="true">•</span>'; }
			$link = $message['message_link'] ?? null;
			if ( ! empty( $link['url'] ) ) { $link['title'] = $message['message_text']; mmaf_site_link( $link, 'site-topbar__item' ); }
			else { echo '<span class="site-topbar__item">' . esc_html( $message['message_text'] ) . '</span>'; }
		}
		echo '</div>';
	}
	echo '</div></div></div></div>';
}

/** Preserve existing page slugs while resolving older ranking links. */
function mmaf_rankings_url(): string {
 $page=get_page_by_path('all-rankings');if(!$page){$page=get_page_by_path('rankings');}
 return $page&&$page->post_status==='publish'?get_permalink($page):home_url('/rankings/');
}
add_action('template_redirect',static function(){
 if(!is_404()){return;}
 $request=untrailingslashit((string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH));
 $alias=untrailingslashit((string)wp_parse_url(home_url('/rankings/'),PHP_URL_PATH));
 if($request===$alias&&untrailingslashit(mmaf_rankings_url())!==untrailingslashit(home_url('/rankings/'))){wp_safe_redirect(mmaf_rankings_url(),301);exit;}
});

/** Only an explicitly verified legacy mapping may redirect to a public canonical profile. */
add_action('template_redirect',static function(){
 if(!is_singular('fighter')){return;}
 $id=(int)mmaf_site_field('legacy_canonical_fighter_id',get_queried_object_id());
 if(!$id||!mmaf_public_service()){return;}
 $fighter=mmaf_public_service()->fighter($id);
 if(!is_wp_error($fighter)&&!empty($fighter['profile']['permalink'])){wp_safe_redirect($fighter['profile']['permalink'],301);exit;}
});
