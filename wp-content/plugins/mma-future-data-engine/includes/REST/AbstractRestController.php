<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Services\Formula\FormulaRegistry;
use MMAF\DataEngine\Services\Formula\FormulaV13;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractRestController {
	protected function bool_value( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	protected function output_bool( $value ): bool {
		return 1 === (int) $value;
	}

	protected function json_value( $value ) {
		if ( null === $value || '' === (string) $value ) {
			return null;
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	protected function age( ?string $date_of_birth, $birth_year ): ?int {
		$today = current_time( 'Y-m-d' );

		if ( $date_of_birth && '0000-00-00' !== $date_of_birth ) {
			try {
				return (int) ( new \DateTimeImmutable( $date_of_birth ) )->diff( new \DateTimeImmutable( $today ) )->y;
			} catch ( \Throwable $error ) {
				return null;
			}
		}

		$year = (int) $birth_year;
		if ( $year > 0 ) {
			return max( 0, (int) substr( $today, 0, 4 ) - $year );
		}

		return null;
	}

	protected function profile_data( $wp_post_id ): array {
		$post_id = (int) $wp_post_id;
		if ( $post_id <= 0 ) {
			return array(
				'wp_post_id' => null,
				'slug'       => null,
				'permalink'  => null,
				'excerpt'    => null,
				'image'      => null,
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return array(
				'wp_post_id' => $post_id,
				'slug'       => null,
				'permalink'  => null,
				'excerpt'    => null,
				'image'      => null,
			);
		}

		$image_id = get_post_thumbnail_id( $post_id );

		return array(
			'wp_post_id' => $post_id,
			'slug'       => $post->post_name,
			'permalink'  => get_permalink( $post ),
			'excerpt'    => get_the_excerpt( $post ),
			'image'      => $this->image_data( $image_id ),
		);
	}

	protected function image_data( $attachment_id ): ?array {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! $url ) {
			return null;
		}

		return array(
			'id'  => $attachment_id,
			'url' => $url,
			'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	protected function warnings_count( $warnings_json ): int {
		$decoded = $this->json_value( $warnings_json );

		return is_array( $decoded['warnings'] ?? null ) ? count( $decoded['warnings'] ) : 0;
	}

	protected function ranking_formula_version( array $row, ?string $fallback = null ): string {
		$formula_version = (string) ( $row['formula_version'] ?? '' );

		if ( '' !== $formula_version ) {
			return $formula_version;
		}

		return null === $fallback || '' === $fallback ? FormulaRegistry::current_version() : $fallback;
	}

	protected function ranking_public_score( array $row, ?string $fallback_formula_version = null ): ?float {
		$formula_version = $this->ranking_formula_version( $row, $fallback_formula_version );
		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return is_numeric( $row['total_score'] ?? null ) ? round( (float) $row['total_score'], 3 ) : null;
		}

		if ( is_numeric( $row['normalized_score'] ?? null ) ) {
			return round( (float) $row['normalized_score'], 3 );
		}

		$legacy_raw_score = is_numeric( $row['total_score'] ?? null ) ? (float) $row['total_score'] : 0.0;

		return round( ( new FormulaV13() )->normalized_score( $legacy_raw_score ), 3 );
	}

	protected function ranking_raw_public_score( array $row ): ?float {
		if ( is_numeric( $row['raw_score'] ?? null ) ) {
			return (float) $row['raw_score'];
		}

		return is_numeric( $row['total_score'] ?? null ) ? (float) $row['total_score'] : null;
	}

	protected function ranking_performance_raw_public_score( array $row, ?string $fallback_formula_version = null ): ?float {
		$formula_version = $this->ranking_formula_version( $row, $fallback_formula_version );
		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return null;
		}

		$breakdown = $this->json_value( $row['breakdown_json'] ?? '' );
		$value = $breakdown['performance_raw_score'] ?? $breakdown['raw_score_before_confidence'] ?? null;

		return is_numeric( $value ) ? (float) $value : null;
	}

	protected function ranking_confidence_public_score( array $row, ?string $fallback_formula_version = null ): ?float {
		$formula_version = $this->ranking_formula_version( $row, $fallback_formula_version );
		if ( FormulaRegistry::uses_direct_scores( $formula_version ) ) {
			return null;
		}

		return is_numeric( $row['confidence_score'] ?? null ) ? (float) $row['confidence_score'] : null;
	}
}
