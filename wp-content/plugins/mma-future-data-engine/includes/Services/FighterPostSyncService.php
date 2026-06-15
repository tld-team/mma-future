<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\CPT\FighterPostType;
use MMAF\DataEngine\Repositories\FighterRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterPostSyncService {
	private FighterRepository $fighters;

	public function __construct( ?FighterRepository $fighters = null ) {
		$this->fighters = $fighters ?: new FighterRepository();
	}

	public function sync( int $fighter_id, array $fighter ): int {
		$post_status = ! empty( $fighter['is_public'] ) ? 'publish' : 'draft';
		$post_id     = isset( $fighter['wp_post_id'] ) ? (int) $fighter['wp_post_id'] : 0;

		if ( $post_id > 0 && $this->fighters->wp_post_exists( $post_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_title'  => (string) $fighter['display_name'],
					'post_name'   => sanitize_title( (string) $fighter['display_name'] ),
					'post_status' => $post_status,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				throw new \RuntimeException( $updated->get_error_message() );
			}

			return $post_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => FighterPostType::POST_TYPE,
				'post_title'  => (string) $fighter['display_name'],
				'post_name'   => sanitize_title( (string) $fighter['display_name'] ),
				'post_status' => $post_status,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		$this->fighters->update_wp_post_id( $fighter_id, (int) $post_id );

		return (int) $post_id;
	}
}
