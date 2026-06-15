<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterDuplicateAuditService {
	public function audit( int $limit = 50 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$limit  = max( 1, min( 100, $limit ) );

		$exact_groups = $wpdb->get_results(
			"
			SELECT normalized_name, COUNT(*) AS fighter_count, GROUP_CONCAT(id ORDER BY id ASC) AS fighter_ids
			FROM {$tables['fighters']}
			WHERE deleted_soft = 0
				AND normalized_name IS NOT NULL
				AND normalized_name <> ''
			GROUP BY normalized_name
			HAVING fighter_count > 1
			ORDER BY fighter_count DESC, normalized_name ASC
			LIMIT 50
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$scraped = $wpdb->get_results(
			"
			SELECT DISTINCT
				f.id,
				f.display_name,
				f.normalized_name,
				f.nationality,
				f.weight_class,
				f.status,
				f.is_public,
				f.is_rankable,
				fs.source_fighter_id
			FROM {$tables['fighters']} f
			INNER JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id AND fs.source_type = 'tapology'
			WHERE f.deleted_soft = 0
				AND f.status = 'provisional'
			ORDER BY f.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$existing = $wpdb->get_results(
			"
			SELECT
				f.id,
				f.display_name,
				f.normalized_name,
				f.nationality,
				f.weight_class,
				f.status,
				f.is_public,
				f.is_rankable,
				MAX(CASE WHEN fs.source_type = 'tapology' THEN 1 ELSE 0 END) AS has_tapology_source
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_sources']} fs ON fs.fighter_id = f.id
			WHERE f.deleted_soft = 0
			GROUP BY f.id
			HAVING has_tapology_source = 0
			ORDER BY f.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$candidates = array();
		$total      = 0;

		foreach ( $scraped as $scraped_fighter ) {
			foreach ( $existing as $existing_fighter ) {
				$match = $this->match_reason( $scraped_fighter, $existing_fighter );
				if ( null === $match ) {
					continue;
				}

				++$total;

				if ( count( $candidates ) >= $limit ) {
					continue;
				}

				$candidates[] = array(
					'scraped_fighter_id'       => (int) $scraped_fighter['id'],
					'scraped_display_name'     => (string) $scraped_fighter['display_name'],
					'scraped_source_fighter_id'=> (string) ( $scraped_fighter['source_fighter_id'] ?? '' ),
					'existing_fighter_id'      => (int) $existing_fighter['id'],
					'existing_display_name'    => (string) $existing_fighter['display_name'],
					'reason'                   => $match['reason'],
					'confidence'               => $match['confidence'],
					'action'                   => 'review manually later',
				);
			}
		}

		return array(
			'exact_normalized_name_groups_count' => count( $exact_groups ),
			'exact_normalized_name_groups'       => $exact_groups,
			'likely_duplicates_count'            => $total,
			'review_candidates'                  => $candidates,
		);
	}

	private function match_reason( array $scraped, array $existing ): ?array {
		$scraped_name = $this->normalized_name( $scraped );
		$existing_name = $this->normalized_name( $existing );

		if ( '' !== $scraped_name && $scraped_name === $existing_name ) {
			return array(
				'reason'     => 'same normalized_name',
				'confidence' => 'high',
			);
		}

		$scraped_tokens = $this->tokens( (string) $scraped['display_name'] );
		$existing_tokens = $this->tokens( (string) $existing['display_name'] );
		if ( count( $scraped_tokens ) < 2 || count( $existing_tokens ) < 2 ) {
			return null;
		}

		$scraped_last = $scraped_tokens[ count( $scraped_tokens ) - 1 ];
		$existing_last = $existing_tokens[ count( $existing_tokens ) - 1 ];
		if ( $scraped_last !== $existing_last ) {
			return null;
		}

		$same_first_initial = substr( $scraped_tokens[0], 0, 1 ) === substr( $existing_tokens[0], 0, 1 );
		$metadata_matches   = $this->metadata_matches( $scraped, $existing );

		if ( $same_first_initial && $metadata_matches > 0 ) {
			return array(
				'reason'     => 'same surname, first initial, and shared nationality/weight class',
				'confidence' => 'medium',
			);
		}

		if ( $same_first_initial ) {
			return array(
				'reason'     => 'same surname and first initial',
				'confidence' => 'low',
			);
		}

		if ( $metadata_matches >= 2 ) {
			return array(
				'reason'     => 'same surname, nationality, and weight class',
				'confidence' => 'low',
			);
		}

		return null;
	}

	private function normalized_name( array $fighter ): string {
		$name = (string) ( $fighter['normalized_name'] ?? '' );

		return '' === $name ? $this->normalize( (string) ( $fighter['display_name'] ?? '' ) ) : $this->normalize( $name );
	}

	private function tokens( string $name ): array {
		$normalized = $this->normalize( $name );
		if ( '' === $normalized ) {
			return array();
		}

		return array_values( array_filter( explode( ' ', $normalized ) ) );
	}

	private function normalize( string $value ): string {
		$value = strtolower( remove_accents( $value ) );
		$value = preg_replace( '/[^a-z0-9 ]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}

	private function metadata_matches( array $a, array $b ): int {
		$matches = 0;

		foreach ( array( 'nationality', 'weight_class' ) as $field ) {
			$a_value = strtolower( trim( (string) ( $a[ $field ] ?? '' ) ) );
			$b_value = strtolower( trim( (string) ( $b[ $field ] ?? '' ) ) );

			if ( '' !== $a_value && '' !== $b_value && $a_value === $b_value ) {
				++$matches;
			}
		}

		return $matches;
	}
}
