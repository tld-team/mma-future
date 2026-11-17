<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reader photos. The upload is never kept: it is decoded, cropped to a square and written out again under a random name,
 * which drops EXIF data and anything that is not pixels. Files are plain uploads, not Media Library attachments, so they
 * do not clutter the editors' library or expose an author id through the media REST route.
 */
final class Avatars {
	public const DIRECTORY = 'mmaf-avatars';
	public const SIDE      = 256;

	private const MIN_SIDE   = 96;
	private const MAX_PIXELS = 24000000;
	private const FILE_RULE  = '#^' . self::DIRECTORY . '/[a-f0-9]{32}\.(?:webp|jpg)\z#';

	/**
	 * @param array{tmp_name?:mixed,error?:mixed,size?:mixed} $file One entry of the request's uploaded files.
	 * @return string Path relative to the uploads directory.
	 * @throws ValidationFailed When the reader should choose a different image.
	 */
	public function store_upload( array $file ): string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$path  = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$limit = Config::avatar_max_bytes();
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) ( $file['size'] ?? 0 ) > $limit ) { throw new ValidationFailed( array( 'avatar' => 'That image is over ' . size_format( $limit ) . '. Choose a smaller one.' ) ); }
		if ( UPLOAD_ERR_OK !== $error || '' === $path || ! is_uploaded_file( $path ) ) { throw new ValidationFailed( array( 'avatar' => 'Choose an image to upload.' ) ); }
		return $this->store_from_path( $path );
	}

	/** @throws ValidationFailed */
	public function store_from_path( string $source ): string {
		if ( ! is_file( $source ) || filesize( $source ) > Config::avatar_max_bytes() ) { throw new ValidationFailed( array( 'avatar' => 'That image is over ' . size_format( Config::avatar_max_bytes() ) . '. Choose a smaller one.' ) ); }
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image is an expected input.
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) { throw new ValidationFailed( array( 'avatar' => 'Use a JPG, PNG or WebP image.' ) ); }
		if ( $info[0] < self::MIN_SIDE || $info[1] < self::MIN_SIDE ) { throw new ValidationFailed( array( 'avatar' => 'Use an image at least ' . self::MIN_SIDE . ' pixels wide and tall.' ) ); }
		// Checked before decoding: a small file can declare dimensions that exhaust memory once unpacked.
		if ( $info[0] * $info[1] > self::MAX_PIXELS ) { throw new ValidationFailed( array( 'avatar' => 'That image has too many pixels. Use one under 24 megapixels.' ) ); }

		// GD only: it decodes the three allowed formats and nothing else.
		$only_gd = static fn(): array => array( 'WP_Image_Editor_GD' );
		add_filter( 'wp_image_editors', $only_gd, 99 );
		try { $editor = wp_get_image_editor( $source ); } finally { remove_filter( 'wp_image_editors', $only_gd, 99 ); }
		$unreadable = new ValidationFailed( array( 'avatar' => 'That image couldn’t be read. Try another one.' ) );
		if ( is_wp_error( $editor ) ) { throw $unreadable; }
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) { $editor->maybe_exif_rotate(); }
		$size = $editor->get_size();
		$side = min( self::SIDE, (int) $size['width'], (int) $size['height'] );
		if ( ( (int) $size['width'] !== $side || (int) $size['height'] !== $side ) && is_wp_error( $editor->resize( $side, $side, true ) ) ) { throw $unreadable; }

		$webp      = $editor::supports_mime_type( 'image/webp' );
		$directory = self::base_dir() . '/' . self::DIRECTORY;
		if ( ! wp_mkdir_p( $directory ) ) { throw new \RuntimeException( 'The photo could not be stored. Try again.' ); }
		$editor->set_quality( 82 );
		$saved = $editor->save( $directory . '/' . bin2hex( random_bytes( 16 ) ) . ( $webp ? '.webp' : '.jpg' ), $webp ? 'image/webp' : 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) { throw new \RuntimeException( 'The photo could not be stored. Try again.' ); }
		return self::DIRECTORY . '/' . basename( (string) $saved['path'] );
	}

	/** Deletes only files this class wrote: the stored path must have the exact shape store_from_path() produces. */
	public static function delete( string $relative_path ): void {
		if ( ! preg_match( self::FILE_RULE, $relative_path ) ) { return; }
		$file = self::base_dir() . '/' . $relative_path;
		if ( is_file( $file ) ) { wp_delete_file( $file ); }
	}

	/** The stored photo, or the neutral placeholder. */
	public static function url( string $relative_path ): string {
		if ( ! preg_match( self::FILE_RULE, $relative_path ) ) { return plugins_url( 'assets/community-avatar.svg', MMAF_PLUGIN_FILE ); }
		return trailingslashit( (string) wp_upload_dir( null, false )['baseurl'] ) . $relative_path;
	}

	/**
	 * Readers get their own photo or the placeholder wherever WordPress prints an avatar, so no Gravatar request (an MD5 of
	 * the reader's email) ever leaves the page. Staff avatars are left to WordPress.
	 *
	 * @param array $args
	 * @param mixed $id_or_email
	 */
	public static function avatar_data( $args, $id_or_email ) {
		if ( ! is_array( $args ) || ! Config::area_enabled( 'profiles' ) ) { return $args; }
		$user_id = self::user_id( $id_or_email );
		if ( ! Config::is_reader( $user_id ) ) { return $args; }
		$row  = ( new Profiles() )->find_by_user( $user_id );
		$path = $row && 'active' === $row['status'] ? (string) $row['avatar_path'] : '';
		$args['url']          = self::url( $path );
		$args['found_avatar'] = true;
		return $args;
	}

	/** @param mixed $id_or_email */
	private static function user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) { return absint( $id_or_email ); }
		if ( $id_or_email instanceof \WP_User ) { return (int) $id_or_email->ID; }
		if ( $id_or_email instanceof \WP_Post ) { return (int) $id_or_email->post_author; }
		if ( $id_or_email instanceof \WP_Comment ) { return (int) $id_or_email->user_id; }
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) { $user = get_user_by( 'email', $id_or_email ); return $user ? (int) $user->ID : 0; }
		return 0;
	}

	private static function base_dir(): string {
		return untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
	}
}
