<?php
require __DIR__ . '/bootstrap.php';

use MMAF\DataEngine\Services\Community\Avatars;
use MMAF\DataEngine\Services\Community\Privacy;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Routes;
use MMAF\DataEngine\Services\Community\Schema;
use MMAF\DataEngine\Services\Community\ValidationFailed;

// Templates are included at file scope on purpose: WordPress loads them in the global scope, where get_header() resets
// names such as $page. Every variable here is prefixed for the same reason.
function assert_community( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** @return array<string,string> The field errors a save produced, or an empty array when it succeeded. */
function community_save_errors( int $user_id, array $input ): array {
	try { ( new Profiles() )->save( $user_id, $input ); return array(); } catch ( ValidationFailed $e ) { return $e->fields(); }
}

/** The page's own markup, without the theme header and footer around it. */
function community_main( string $html ): string {
	return preg_match( '#<main\b.*</main>#s', $html, $match ) ? $match[0] : '';
}

function community_image( int $width, int $height ): string {
	$path  = wp_tempnam( 'mmafc-avatar' );
	$image = imagecreatetruecolor( $width, $height );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 0, 71, 168 ) );
	imagepng( $image, $path );
	return $path;
}

global $wpdb;
add_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
add_filter( 'mmaf_community_feature_enabled', '__return_true' );
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$mmafc_t_suffix = strtolower( wp_generate_password( 8, false, false ) );
$mmafc_t_users  = array();
$mmafc_t_files  = array();
$mmafc_t_get    = $_GET;
$mmafc_t_tables = Schema::tables();
$mmafc_t_post   = 0;
$mmafc_t_done   = false;

try {
	foreach ( array( 'one' => 'subscriber', 'two' => 'subscriber', 'staff' => 'administrator' ) as $mmafc_t_label => $mmafc_t_role ) {
		$mmafc_t_id = wp_create_user( 'community_' . $mmafc_t_label . '_' . $mmafc_t_suffix, wp_generate_password( 24, true, true ), 'community-' . $mmafc_t_label . '-' . $mmafc_t_suffix . '@example.test' );
		if ( is_wp_error( $mmafc_t_id ) ) { throw new RuntimeException( $mmafc_t_id->get_error_message() ); }
		get_userdata( $mmafc_t_id )->set_role( $mmafc_t_role );
		$mmafc_t_users[ $mmafc_t_label ] = (int) $mmafc_t_id;
	}
	$mmafc_t_one   = $mmafc_t_users['one'];
	$mmafc_t_two   = $mmafc_t_users['two'];
	$mmafc_t_login = get_userdata( $mmafc_t_one )->user_login;
	$mmafc_t_email = get_userdata( $mmafc_t_one )->user_email;
	$mmafc_t_post  = (int) wp_insert_post( array( 'post_type' => 'mmaf_fighter', 'post_status' => 'publish', 'post_title' => 'Cf ' . $mmafc_t_suffix ) );
	$profiles      = new Profiles();

	// Creating a profile.
	$mmafc_t_handle = 'tester_' . $mmafc_t_suffix;
	assert_community( array( 'handle' ) === array_keys( community_save_errors( $mmafc_t_one, array( 'display_name' => 'No Handle' ) ) ), 'A profile was created without a handle.' );
	$row = $profiles->save( $mmafc_t_one, array( 'handle' => '  @' . strtoupper( $mmafc_t_handle ) . ' ', 'display_name' => "  Nikola   <b>the</b> Grappler ", 'bio' => "Regional scene <script>alert(1)</script>nerd.\r\n\r\n\r\n\r\nCalling prospects early.", 'socials' => array( 'instagram' => 'https://www.instagram.com/nikola.g/', 'x' => '@Nikola_G', 'tiktok' => '' ) ) );
	assert_community( $mmafc_t_handle === $row['handle'] && 'Nikola the Grappler' === $row['display_name'], 'The handle or display name was not normalised.' );
	assert_community( "Regional scene nerd.\n\nCalling prospects early." === $row['bio'], 'The bio kept markup or runs of blank lines: ' . wp_json_encode( $row['bio'] ) );
	assert_community( null === $row['handle_changed_at'] && 'active' === $row['status'], 'A new profile does not start active with a correctable handle.' );
	assert_community( (int) $row['user_id'] === $mmafc_t_one && $profiles->find_by_handle( strtoupper( $mmafc_t_handle ) )['id'] === $row['id'], 'The profile cannot be found by its handle.' );
	$view = $profiles->present( $row );
	assert_community( array( 'https://www.instagram.com/nikola.g/', 'https://x.com/Nikola_G' ) === wp_list_pluck( $view['socials'], 'url' ), 'Social links were not composed from the usernames.' );
	assert_community( home_url( '/fan/' . $mmafc_t_handle . '/' ) === $view['url'], 'The public profile URL is not built from the handle.' );
	$mmafc_t_public = wp_json_encode( $view ) . wp_json_encode( $profiles->editable( $mmafc_t_one ) );
	assert_community( ! str_contains( $mmafc_t_public, $mmafc_t_login ) && ! str_contains( $mmafc_t_public, $mmafc_t_email ), 'Profile data exposes the sign-in name or email.' );

	// Handle ownership.
	assert_community( isset( community_save_errors( $mmafc_t_two, array( 'handle' => $mmafc_t_handle ) )['handle'] ), 'Two readers could take the same handle.' );
	assert_community( 'cf-' . $mmafc_t_suffix === get_post( $mmafc_t_post )->post_name, 'The fighter fixture did not get the expected slug.' );
	assert_community( str_contains( community_save_errors( $mmafc_t_two, array( 'handle' => 'cf_' . $mmafc_t_suffix ) )['handle'] ?? '', 'taken' ), 'A handle could spell a ranked fighter’s name.' );
	assert_community( str_contains( community_save_errors( $mmafc_t_two, array( 'handle' => 'cf' . $mmafc_t_suffix ) )['handle'] ?? '', 'taken' ), 'A handle could spell a fighter’s name without separators.' );
	assert_community( isset( community_save_errors( $mmafc_t_two, array( 'handle' => 'moderator' ) )['handle'] ), 'A reserved handle was accepted.' );
	$mmafc_t_reserve = static fn( array $reserved ): array => array_merge( $reserved, array( 'rival_' . $mmafc_t_suffix ) );
	add_filter( 'mmaf_community_reserved_handles', $mmafc_t_reserve );
	assert_community( isset( community_save_errors( $mmafc_t_two, array( 'handle' => 'rival_' . $mmafc_t_suffix ) )['handle'] ), 'The reserved-handle filter is ignored.' );
	remove_filter( 'mmaf_community_reserved_handles', $mmafc_t_reserve );
	$profiles->save( $mmafc_t_two, array( 'handle' => 'second_' . $mmafc_t_suffix ) );
	assert_community( 'second_' . $mmafc_t_suffix === $profiles->find_by_user( $mmafc_t_two )['display_name'], 'An empty display name does not fall back to the handle.' );

	// One correction straight away, then the waiting period.
	$mmafc_t_renamed = 'renamed_' . $mmafc_t_suffix;
	$row             = $profiles->save( $mmafc_t_one, array( 'handle' => $mmafc_t_renamed ) );
	assert_community( $mmafc_t_renamed === $row['handle'] && null !== $row['handle_changed_at'] && 'Nikola the Grappler' === $row['display_name'], 'The first handle could not be corrected, or other fields were lost.' );
	assert_community( null === $profiles->find_by_handle( $mmafc_t_handle ), 'The old handle still resolves.' );
	$mmafc_t_locked = community_save_errors( $mmafc_t_one, array( 'handle' => 'third_' . $mmafc_t_suffix ) );
	assert_community( isset( $mmafc_t_locked['handle'] ) && str_contains( $mmafc_t_locked['handle'], 'again on' ), 'A handle could be changed twice in a row.' );
	assert_community( null !== $profiles->editable( $mmafc_t_one )['handle_locked_until'], 'The form is not told the handle is locked.' );
	assert_community( array() === community_save_errors( $mmafc_t_one, array( 'handle' => $mmafc_t_renamed, 'bio' => 'Updated.' ) ), 'A locked handle blocks edits to other fields.' );
	$wpdb->update( $mmafc_t_tables['profiles'], array( 'handle_changed_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ), array( 'user_id' => $mmafc_t_one ) );
	$profiles->set_status( $mmafc_t_one, 'active' ); // Clears the per-request lookup after the direct update.
	assert_community( null === $profiles->editable( $mmafc_t_one )['handle_locked_until'], 'The handle stays locked after the waiting period.' );

	// Field rules.
	assert_community( isset( community_save_errors( $mmafc_t_one, array( 'display_name' => 'MMA Future Staff' ) )['display_name'] ), 'A reader could pose as the site.' );
	assert_community( isset( community_save_errors( $mmafc_t_one, array( 'display_name' => str_repeat( 'n', Profiles::NAME_MAX + 1 ) ) )['display_name'] ), 'An overlong display name was accepted.' );
	assert_community( isset( community_save_errors( $mmafc_t_one, array( 'bio' => str_repeat( 'ž', Profiles::BIO_MAX + 1 ) ) )['bio'] ), 'An overlong bio was accepted.' );
	assert_community( array() === community_save_errors( $mmafc_t_one, array( 'bio' => str_repeat( 'ž', Profiles::BIO_MAX ) ) ), 'A bio of exactly the limit in multi-byte characters was refused.' );
	assert_community( array( 'socials.instagram' ) === array_keys( community_save_errors( $mmafc_t_one, array( 'socials' => array( 'instagram' => 'https://evil.example/nikola' ) ) ) ), 'A link to another site was accepted as a social profile.' );
	assert_community( array() === community_save_errors( $mmafc_t_users['staff'], array( 'handle' => 'desk_' . $mmafc_t_suffix, 'display_name' => 'MMA Future Staff' ) ), 'Staff cannot use the site name.' );

	// Photos.
	$avatars          = new Avatars();
	$mmafc_t_source   = community_image( 400, 300 );
	$mmafc_t_files[]  = $mmafc_t_source;
	$mmafc_t_stored   = $avatars->store_from_path( $mmafc_t_source );
	$mmafc_t_basedir  = untrailingslashit( wp_upload_dir( null, false )['basedir'] );
	$mmafc_t_files[]  = $mmafc_t_basedir . '/' . $mmafc_t_stored;
	$mmafc_t_size     = getimagesize( $mmafc_t_basedir . '/' . $mmafc_t_stored );
	assert_community( 1 === preg_match( '#^mmaf-avatars/[a-f0-9]{32}\.(webp|jpg)$#', $mmafc_t_stored ), 'The stored photo does not have a random name.' );
	assert_community( $mmafc_t_size && Avatars::SIDE === $mmafc_t_size[0] && Avatars::SIDE === $mmafc_t_size[1], 'The photo was not cropped to the square size.' );
	assert_community( '' === $profiles->set_avatar( $mmafc_t_one, $mmafc_t_stored ), 'A first photo reported a previous file.' );
	assert_community( str_ends_with( get_avatar_url( $mmafc_t_one ), $mmafc_t_stored ) && str_ends_with( get_avatar_url( $mmafc_t_email ), $mmafc_t_stored ), 'WordPress avatars do not use the reader’s photo.' );
	assert_community( str_ends_with( get_avatar_url( $mmafc_t_two ), 'community-avatar.svg' ), 'A reader without a photo does not get the local placeholder.' );
	assert_community( ! str_contains( get_avatar_url( $mmafc_t_one ) . get_avatar_url( $mmafc_t_two ), 'gravatar' ), 'A reader avatar still points at Gravatar.' );
	assert_community( ! str_contains( get_avatar_url( $mmafc_t_users['staff'] ), 'mmaf-avatars' ) && ! str_contains( get_avatar_url( $mmafc_t_users['staff'] ), 'community-avatar' ), 'Staff avatars were replaced.' );
	$mmafc_t_small   = community_image( 60, 60 );
	$mmafc_t_text    = wp_tempnam( 'mmafc-text' );
	file_put_contents( $mmafc_t_text, '<?php echo "not an image";' );
	array_push( $mmafc_t_files, $mmafc_t_small, $mmafc_t_text );
	foreach ( array( 'a small image' => $mmafc_t_small, 'a non-image' => $mmafc_t_text ) as $mmafc_t_what => $mmafc_t_path ) {
		try { $avatars->store_from_path( $mmafc_t_path ); $mmafc_t_refused = false; } catch ( ValidationFailed $e ) { $mmafc_t_refused = isset( $e->fields()['avatar'] ); }
		assert_community( $mmafc_t_refused, "The photo upload accepted $mmafc_t_what." );
	}
	$mmafc_t_second  = $avatars->store_from_path( $mmafc_t_source );
	$mmafc_t_files[] = $mmafc_t_basedir . '/' . $mmafc_t_second;
	Avatars::delete( $profiles->set_avatar( $mmafc_t_one, $mmafc_t_second ) );
	assert_community( ! is_file( $mmafc_t_basedir . '/' . $mmafc_t_stored ) && is_file( $mmafc_t_basedir . '/' . $mmafc_t_second ), 'Replacing a photo left the old file or removed the new one.' );
	$mmafc_t_keep    = $mmafc_t_basedir . '/mmaf-avatars/keep-' . $mmafc_t_suffix . '.txt';
	$mmafc_t_files[] = $mmafc_t_keep;
	file_put_contents( $mmafc_t_keep, 'keep' );
	Avatars::delete( 'mmaf-avatars/keep-' . $mmafc_t_suffix . '.txt' );
	Avatars::delete( 'mmaf-avatars/../mmaf-avatars/keep-' . $mmafc_t_suffix . '.txt' );
	assert_community( is_file( $mmafc_t_keep ), 'Deleting a photo can remove a file the uploader did not write.' );

	// REST: the reader's own profile only.
	wp_set_current_user( 0 );
	assert_community( 401 === rest_do_request( new WP_REST_Request( 'GET', '/mma-future/v1/community/profile' ) )->get_status(), 'A signed-out visitor can read the profile form.' );
	wp_set_current_user( $mmafc_t_one );
	$mmafc_t_response = rest_do_request( new WP_REST_Request( 'GET', '/mma-future/v1/community/profile' ) );
	assert_community( 200 === $mmafc_t_response->get_status() && $mmafc_t_renamed === $mmafc_t_response->get_data()['handle'] && true === $mmafc_t_response->get_data()['has_avatar'], 'The profile route does not return the reader’s profile.' );
	$mmafc_t_request = new WP_REST_Request( 'POST', '/mma-future/v1/community/profile' );
	$mmafc_t_request->set_body_params( array( 'handle' => $mmafc_t_renamed, 'display_name' => 'Rest Name', 'bio' => str_repeat( 'x', Profiles::BIO_MAX + 1 ) ) );
	$mmafc_t_response = rest_do_request( $mmafc_t_request );
	assert_community( 400 === $mmafc_t_response->get_status() && isset( $mmafc_t_response->get_data()['data']['fields']['bio'] ), 'An invalid save does not report the field that needs fixing.' );
	assert_community( 'Nikola the Grappler' === $profiles->find_by_user( $mmafc_t_one )['display_name'], 'A rejected save still changed the profile.' );
	$mmafc_t_request = new WP_REST_Request( 'POST', '/mma-future/v1/community/profile' );
	$mmafc_t_request->set_body_params( array( 'display_name' => 'Rest Name' ) );
	$mmafc_t_response = rest_do_request( $mmafc_t_request );
	assert_community( 200 === $mmafc_t_response->get_status() && 'Rest Name' === $mmafc_t_response->get_data()['display_name'] && $mmafc_t_renamed === $mmafc_t_response->get_data()['handle'], 'A partial save did not keep the fields it left out.' );
	$mmafc_t_request = new WP_REST_Request( 'GET', '/mma-future/v1/community/profile/handle' );
	$mmafc_t_request->set_query_params( array( 'value' => 'second_' . $mmafc_t_suffix ) );
	assert_community( false === rest_do_request( $mmafc_t_request )->get_data()['available'], 'The handle check reports a taken handle as free.' );
	$mmafc_t_response = rest_do_request( new WP_REST_Request( 'DELETE', '/mma-future/v1/community/profile/avatar' ) );
	assert_community( 200 === $mmafc_t_response->get_status() && false === $mmafc_t_response->get_data()['has_avatar'] && ! is_file( $mmafc_t_basedir . '/' . $mmafc_t_second ), 'Removing the photo left it in place.' );

	// Pages.
	foreach ( array( 'community-account.php', 'community-profile.php' ) as $mmafc_t_template ) {
		assert_community( ! preg_match( '/\$(page|pages|s|post|posts|id|more|numpages|multipage|authordata|wp_query)\b\s*=(?!=)/', (string) file_get_contents( MMAF_PLUGIN_DIR . 'templates/' . $mmafc_t_template ) ), "$mmafc_t_template assigns a WordPress global that get_header() resets." );
	}
	$profiles->save( $mmafc_t_one, array( 'display_name' => 'Nikola & "Grappler"', 'bio' => "Line one\nLine two & more" ) );
	$_GET = array();
	set_query_var( 'mmaf_community_page', 'account-profile' );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/community-account.php';
	$mmafc_t_account = community_main( (string) ob_get_clean() );
	assert_community( str_contains( $mmafc_t_account, 'data-mmafc-profile' ) && str_contains( $mmafc_t_account, 'value="' . $mmafc_t_renamed . '"' ) && str_contains( $mmafc_t_account, 'aria-current="page">Profile</a>' ), 'The profile form rendered without its fields or tabs.' );
	assert_community( ! str_contains( $mmafc_t_account, $mmafc_t_login ) && ! str_contains( $mmafc_t_account, $mmafc_t_email ), 'The profile form prints the sign-in name or email.' );

	wp_set_current_user( 0 );
	set_query_var( 'mmaf_community_page', 'profile' );
	set_query_var( 'mmaf_community_handle', $mmafc_t_renamed );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/community-profile.php';
	$mmafc_t_profile = community_main( (string) ob_get_clean() );
	assert_community( str_contains( $mmafc_t_profile, 'Nikola &amp; &quot;Grappler&quot;' ) && str_contains( $mmafc_t_profile, '@' . $mmafc_t_renamed ), 'The public profile does not show the escaped name and handle.' );
	assert_community( str_contains( $mmafc_t_profile, "Line one<br />\nLine two &amp; more" ), 'The public profile does not show the escaped bio.' );
	assert_community( str_contains( $mmafc_t_profile, 'rel="nofollow ugc noopener"' ) && str_contains( $mmafc_t_profile, 'https://www.instagram.com/nikola.g/' ), 'Social links are missing or followed.' );
	assert_community( ! str_contains( $mmafc_t_profile, $mmafc_t_login ) && ! str_contains( $mmafc_t_profile, $mmafc_t_email ) && ! str_contains( $mmafc_t_profile, 'gravatar' ), 'The public profile leaks the sign-in name, email or a Gravatar hash.' );
	assert_community( str_starts_with( Routes::document_title( 'Blog' ), 'Nikola & "Grappler" (@' . $mmafc_t_renamed . ') - ' ) && ! empty( Routes::robots( array() )['noindex'] ), 'The public profile does not own its title, or is indexable.' );
	assert_community( MMAF_PLUGIN_DIR . 'templates/community-profile.php' === Routes::template( 'theme.php' ), 'The public profile is not routed to its template.' );
	set_query_var( 'mmaf_community_page', '' );
	set_query_var( 'mmaf_community_handle', '' );

	wp_set_current_user( $mmafc_t_one );
	set_query_var( 'mmaf_notification_page', 'account' );
	ob_start();
	include MMAF_PLUGIN_DIR . 'templates/notifications-page.php';
	$mmafc_t_notifications = (string) ob_get_clean();
	set_query_var( 'mmaf_notification_page', '' );
	assert_community( str_contains( $mmafc_t_notifications, 'class="mmafc-tabs"' ) && str_contains( $mmafc_t_notifications, 'aria-current="page">Notifications</a>' ) && str_contains( $mmafc_t_notifications, 'data-mmaf-settings' ), 'The notifications page lost its content or did not gain the account tabs.' );

	// Moderation and removal.
	$mmafc_t_avatar  = $avatars->store_from_path( $mmafc_t_source );
	$mmafc_t_files[] = $mmafc_t_basedir . '/' . $mmafc_t_avatar;
	$profiles->set_avatar( $mmafc_t_one, $mmafc_t_avatar );
	$profiles->set_status( $mmafc_t_one, 'hidden' );
	assert_community( str_ends_with( get_avatar_url( $mmafc_t_one ), 'community-avatar.svg' ), 'A hidden profile still shows its photo.' );
	$profiles->set_status( $mmafc_t_one, 'active' );
	$mmafc_t_now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $mmafc_t_tables['fan_lists'], array( 'user_id' => $mmafc_t_one, 'created_at' => $mmafc_t_now, 'updated_at' => $mmafc_t_now ) );
	$mmafc_t_list = (int) $wpdb->insert_id;
	$wpdb->insert( $mmafc_t_tables['fan_list_items'], array( 'list_id' => $mmafc_t_list, 'position' => 1, 'fighter_id' => 1, 'picked_at' => $mmafc_t_now ) );
	$mmafc_t_export = Privacy::export( $mmafc_t_email );
	assert_community( str_contains( wp_json_encode( $mmafc_t_export ), '@' . $mmafc_t_renamed ), 'The privacy export omits the profile.' );
	$mmafc_t_erased = Privacy::erase( $mmafc_t_email );
	assert_community( true === $mmafc_t_erased['items_removed'] && null === $profiles->find_by_user( $mmafc_t_one ) && ! is_file( $mmafc_t_basedir . '/' . $mmafc_t_avatar ), 'Erasing a reader left the profile or the photo.' );
	assert_community( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafc_t_tables['fan_lists']} WHERE user_id=%d", $mmafc_t_one ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafc_t_tables['fan_list_items']} WHERE list_id=%d", $mmafc_t_list ) ), 'Erasing a reader left their fan list.' );
	assert_community( array() === Privacy::export( $mmafc_t_email )['data'], 'An erased reader still has profile data to export.' );
	wp_delete_user( $mmafc_t_two );
	assert_community( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mmafc_t_tables['profiles']} WHERE user_id=%d", $mmafc_t_two ) ), 'Deleting an account left its public profile.' );

	$mmafc_t_done = true;
	echo wp_json_encode( array( 'ok' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
	while ( ! $mmafc_t_done && ob_get_level() > 0 ) { ob_end_clean(); }
	$_GET = $mmafc_t_get;
	foreach ( array( 'mmaf_community_page', 'mmaf_community_handle', 'mmaf_notification_page' ) as $mmafc_t_var ) { set_query_var( $mmafc_t_var, '' ); }
	wp_set_current_user( 0 );
	foreach ( $mmafc_t_users as $mmafc_t_id ) { ( new Profiles() )->forget( $mmafc_t_id ); wp_delete_user( $mmafc_t_id ); delete_transient( 'mmafc_rl_profile_' . $mmafc_t_id ); delete_transient( 'mmafc_rl_handle_' . $mmafc_t_id ); delete_transient( 'mmafc_rl_avatar_' . $mmafc_t_id ); }
	foreach ( $mmafc_t_files as $mmafc_t_file ) { if ( is_file( $mmafc_t_file ) ) { unlink( $mmafc_t_file ); } }
	if ( $mmafc_t_post ) { wp_delete_post( $mmafc_t_post, true ); }
	remove_filter( 'mmaf_community_feature_enabled', '__return_true' );
	remove_filter( 'mmaf_notifications_feature_enabled', '__return_true' );
}
