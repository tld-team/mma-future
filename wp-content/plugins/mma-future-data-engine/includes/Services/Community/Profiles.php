<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A reader's public identity: handle, display name, bio, social usernames and avatar.
 * Nothing here ever exposes the WordPress login, nicename or email, because those are derived from the sign-in address.
 */
final class Profiles {
	public const NAME_MAX = 40;
	public const BIO_MAX  = 280;

	/** Display names that would pass for the site or its staff. */
	private const NAME_BLOCKLIST = '/mma\s*future|\b(?:official|staff|admin|administrator|moderator)\b/iu';

	/** @var array<int,array<string,mixed>|null> One lookup per reader per request; avatars ask repeatedly. */
	private static array $by_user = array();

	public function find_by_user( int $user_id ): ?array {
		if ( $user_id < 1 ) { return null; }
		if ( ! array_key_exists( $user_id, self::$by_user ) ) {
			global $wpdb;
			$table = Schema::tables()['profiles'];
			self::$by_user[ $user_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d", $user_id ), ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return self::$by_user[ $user_id ];
	}

	public function find_by_handle( string $handle ): ?array {
		$handle = Handles::normalize( $handle );
		if ( '' !== Handles::problem( $handle ) ) { return null; }
		global $wpdb;
		$table = Schema::tables()['profiles'];
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE handle=%s", $handle ), ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Why this reader cannot take the handle, or '' when they can (including when it is already theirs). */
	public function handle_problem( string $handle, int $user_id ): string {
		$handle  = Handles::normalize( $handle );
		$problem = Handles::problem( $handle, array_map( 'strval', (array) apply_filters( 'mmaf_community_reserved_handles', array() ) ) );
		if ( '' !== $problem ) { return $problem; }
		$own = $this->find_by_user( $user_id );
		if ( $own && $own['handle'] === $handle ) { return ''; }
		$locked = $this->handle_locked_until( $own );
		if ( $locked ) { return 'You can change your handle again on ' . wp_date( 'F j, Y', strtotime( $locked . ' UTC' ) ) . '.'; }
		$taken = $this->find_by_handle( $handle );
		if ( $taken || $this->is_fighter_name( $handle ) ) { return 'That handle’s already taken. Try another.'; }
		return '';
	}

	/** UTC datetime until which the handle cannot change, or null. The first handle can be corrected once straight away. */
	public function handle_locked_until( ?array $row ): ?string {
		$days = Config::handle_change_days();
		if ( ! $row || empty( $row['handle_changed_at'] ) || $days < 1 ) { return null; }
		$until = strtotime( $row['handle_changed_at'] . ' UTC' ) + $days * DAY_IN_SECONDS;
		return $until > time() ? gmdate( 'Y-m-d H:i:s', $until ) : null;
	}

	/**
	 * Create or update the reader's profile.
	 *
	 * @param array{handle?:mixed,display_name?:mixed,bio?:mixed,socials?:mixed} $input
	 * @throws ValidationFailed When a field needs correcting.
	 */
	public function save( int $user_id, array $input ): array {
		global $wpdb;
		$table  = Schema::tables()['profiles'];
		$row    = $this->find_by_user( $user_id );
		$errors = array();

		$handle = Handles::normalize( self::text( $input['handle'] ?? ( $row['handle'] ?? '' ) ) );
		$reason = $this->handle_problem( $handle, $user_id );
		if ( '' !== $reason ) { $errors['handle'] = $reason; }

		$name = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( self::text( $input['display_name'] ?? ( $row['display_name'] ?? '' ) ) ) ) );
		if ( '' === $name ) { $name = $handle; }
		if ( self::length( $name ) > self::NAME_MAX ) { $errors['display_name'] = 'Use ' . self::NAME_MAX . ' characters or fewer.'; }
		elseif ( Config::is_reader( $user_id ) && preg_match( self::NAME_BLOCKLIST, $name ) ) { $errors['display_name'] = 'Choose a different name.'; }

		$bio = sanitize_textarea_field( self::text( $input['bio'] ?? ( $row['bio'] ?? '' ) ) );
		$bio = trim( (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( array( "\r\n", "\r" ), "\n", $bio ) ) );
		if ( self::length( $bio ) > self::BIO_MAX ) { $errors['bio'] = 'Keep your bio to ' . self::BIO_MAX . ' characters.'; }

		list( $socials, $social_errors ) = array_key_exists( 'socials', $input ) ? Socials::clean( $input['socials'] ) : array( self::socials( $row ), array() );
		$errors += $social_errors;
		if ( $errors ) { throw new ValidationFailed( $errors ); }

		$now    = gmdate( 'Y-m-d H:i:s' );
		$fields = array( 'handle' => $handle, 'display_name' => $name, 'bio' => $bio, 'socials_json' => $socials ? wp_json_encode( $socials ) : null, 'updated_at' => $now );
		$quiet  = $wpdb->suppress_errors( true ); // A lost race for a handle is an expected outcome, not a database fault to print.
		if ( $row ) {
			if ( $row['handle'] !== $handle ) { $fields['handle_changed_at'] = $now; }
			$written = $wpdb->update( $table, $fields, array( 'id' => (int) $row['id'] ) );
		} else {
			$written = $wpdb->insert( $table, $fields + array( 'user_id' => $user_id, 'status' => 'active', 'created_at' => $now ) );
		}
		$failure = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $quiet );
		unset( self::$by_user[ $user_id ] );
		if ( false === $written ) {
			if ( false !== stripos( $failure, 'duplicate' ) ) { throw new ValidationFailed( array( 'handle' => 'That handle’s already taken. Try another.' ) ); }
			throw new \RuntimeException( 'Your profile could not be saved. Try again.' );
		}
		return (array) $this->find_by_user( $user_id );
	}

	/** Record a stored avatar (or '' to clear it) and return the file it replaced so the caller can delete it. */
	public function set_avatar( int $user_id, string $relative_path ): string {
		global $wpdb;
		$row = $this->find_by_user( $user_id );
		if ( ! $row ) { throw new ValidationFailed( array( 'handle' => 'Choose a handle and save your profile before adding a photo.' ) ); }
		$wpdb->update( Schema::tables()['profiles'], array( 'avatar_path' => $relative_path, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) );
		unset( self::$by_user[ $user_id ] );
		return (string) $row['avatar_path'];
	}

	public function set_status( int $user_id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, array( 'active', 'hidden' ), true ) ) { throw new \InvalidArgumentException( 'Unknown profile status.' ); }
		$wpdb->update( Schema::tables()['profiles'], array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'user_id' => $user_id ) );
		unset( self::$by_user[ $user_id ] );
	}

	/** Remove everything Community holds about a reader: the avatar file, the profile and their fan list. */
	public function forget( int $user_id ): bool {
		global $wpdb;
		$tables = Schema::tables();
		$row    = $this->find_by_user( $user_id );
		if ( $row && '' !== (string) $row['avatar_path'] ) { Avatars::delete( (string) $row['avatar_path'] ); }
		$list_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['fan_lists']} WHERE user_id=%d", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $list_ids as $list_id ) { $wpdb->delete( $tables['fan_list_items'], array( 'list_id' => $list_id ) ); }
		$wpdb->delete( $tables['fan_lists'], array( 'user_id' => $user_id ) );
		$wpdb->delete( $tables['profiles'], array( 'user_id' => $user_id ) );
		unset( self::$by_user[ $user_id ] );
		if ( $list_ids ) { FanRanking::changed(); }
		return (bool) $row || (bool) $list_ids;
	}

	/** What anyone may see. Built only from the profile row and the registration month. */
	public function present( array $row ): array {
		$user = get_userdata( (int) $row['user_id'] );
		return array(
			'handle'       => (string) $row['handle'],
			'display_name' => '' !== (string) $row['display_name'] ? (string) $row['display_name'] : (string) $row['handle'],
			'bio'          => (string) $row['bio'],
			'socials'      => Socials::links( self::socials( $row ) ),
			'avatar_url'   => Avatars::url( (string) $row['avatar_path'] ),
			'has_avatar'   => '' !== (string) $row['avatar_path'],
			'member_since' => $user ? mysql2date( 'F Y', $user->user_registered ) : '',
			'url'          => self::url( (string) $row['handle'] ),
		);
	}

	/** The reader's own view for the edit form, whether or not a profile exists yet. */
	public function editable( int $user_id ): array {
		$row    = $this->find_by_user( $user_id );
		$locked = $this->handle_locked_until( $row );
		return array(
			'has_profile'         => (bool) $row,
			'handle'              => $row ? (string) $row['handle'] : '',
			'handle_suggestion'   => $row ? '' : $this->suggest_handle( $user_id ),
			'handle_locked_until' => $locked ? wp_date( 'F j, Y', strtotime( $locked . ' UTC' ) ) : null,
			'display_name'        => $row ? (string) $row['display_name'] : '',
			'bio'                 => $row ? (string) $row['bio'] : '',
			'socials'             => self::socials( $row ),
			'avatar_url'          => Avatars::url( $row ? (string) $row['avatar_path'] : '' ),
			'has_avatar'          => $row && '' !== (string) $row['avatar_path'],
			'status'              => $row ? (string) $row['status'] : 'active',
			'url'                 => $row ? self::url( (string) $row['handle'] ) : '',
		);
	}

	public static function url( string $handle ): string {
		return home_url( '/fan/' . rawurlencode( $handle ) . '/' );
	}

	/** @return array<string,string> */
	private static function socials( ?array $row ): array {
		$decoded = $row && ! empty( $row['socials_json'] ) ? json_decode( (string) $row['socials_json'], true ) : array();
		return is_array( $decoded ) ? array_filter( $decoded, 'is_string' ) : array();
	}

	private function suggest_handle( int $user_id ): string {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$handle = Handles::suggestion( wp_rand( 0, 99999 ) );
			if ( ! $this->find_by_handle( $handle ) ) { return $handle; }
		}
		return '';
	}

	/** A handle spelling a ranked fighter's name would read as that fighter's own account. */
	private function is_fighter_name( string $handle ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='mmaf_fighter' AND post_status='publish' AND (REPLACE(post_name,'-','_')=%s OR REPLACE(post_name,'-','')=%s) LIMIT 1", $handle, $handle ) );
	}

	/** @param mixed $value */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
