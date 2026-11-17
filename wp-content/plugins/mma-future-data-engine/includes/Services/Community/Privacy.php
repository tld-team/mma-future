<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Privacy {
	public static function exporters( array $exporters ): array {
		$exporters['mmaf-community'] = array( 'exporter_friendly_name' => 'MMA Future community profile', 'callback' => array( self::class, 'export' ) );
		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['mmaf-community'] = array( 'eraser_friendly_name' => 'MMA Future community profile', 'callback' => array( self::class, 'erase' ) );
		return $erasers;
	}

	public static function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$row  = $user && Config::schema_ready() ? ( new Profiles() )->find_by_user( (int) $user->ID ) : null;
		if ( ! $row ) { return array( 'data' => array(), 'done' => true ); }
		$view = ( new Profiles() )->present( $row );
		$data = array(
			array( 'name' => 'Handle', 'value' => '@' . $view['handle'] ),
			array( 'name' => 'Display name', 'value' => $view['display_name'] ),
			array( 'name' => 'Bio', 'value' => $view['bio'] ),
			array( 'name' => 'Social links', 'value' => implode( ', ', wp_list_pluck( $view['socials'], 'url' ) ) ),
			array( 'name' => 'Photo', 'value' => $view['has_avatar'] ? $view['avatar_url'] : '' ),
		);
		return array( 'data' => array( array( 'group_id' => 'mmaf-community', 'group_label' => 'MMA Future community profile', 'item_id' => 'mmaf-community-' . $user->ID, 'data' => $data ) ), 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user && Config::schema_ready() ? ( new Profiles() )->forget( (int) $user->ID ) : false;
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	/** Hooked on deleted_user: an account that no longer exists must not leave a public profile or a photo behind. */
	public static function forget_deleted_user( $user_id ): void {
		if ( Config::schema_ready() ) { ( new Profiles() )->forget( (int) $user_id ); }
	}
}
