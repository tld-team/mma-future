<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Support\Sanitizer;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterIdentityPreviewService {
	private FighterRepository $fighters;
	private FighterSourceRepository $fighter_sources;
	private string $fighters_table;
	private string $aliases_table;

	public function __construct() {
		$tables                = Schema::table_names();
		$this->fighters        = new FighterRepository();
		$this->fighter_sources = new FighterSourceRepository();
		$this->fighters_table  = $tables['fighters'];
		$this->aliases_table   = $tables['fighter_aliases'];
	}

	public function preview_fighters( array $events, array &$warnings, array &$conflicts ): array {
		$refs              = $this->collect_refs( $events );
		$previews          = array();
		$source_id_names   = array();
		$normalized_names  = array();
		$actions           = array(
			'exact_source_match'            => 0,
			'exact_source_url_hash_match'   => 0,
			'likely_match_review'           => 0,
			'create_provisional_candidate'  => 0,
			'create_provisional_with_url_only_identity' => 0,
			'unresolved_fighter_ref'        => 0,
			'ambiguous_source_url_hash'     => 0,
		);

		foreach ( $refs as $key => $ref ) {
			$source_fighter_id = (string) ( $ref['source_fighter_id'] ?? '' );
			$name              = (string) ( $ref['name'] ?? '' );
			$normalized_name   = Sanitizer::normalize_name( $name );
			$source_url        = (string) ( $ref['url'] ?? '' );
			$url_hash          = TapologyFighterUrl::source_url_hash( $source_url );

			if ( '' === $source_fighter_id && null === $url_hash ) {
				$warnings[] = 'Fighter reference is missing source_fighter_id: ' . $name;
			} else {
				if ( isset( $source_id_names[ $source_fighter_id ] ) && $source_id_names[ $source_fighter_id ] !== $normalized_name ) {
					$conflicts[] = 'source_fighter_id ' . $source_fighter_id . ' appears with different names in one JSON.';
				}
				if ( '' !== $source_fighter_id ) {
					$source_id_names[ $source_fighter_id ] = $normalized_name;
				}
			}

			if ( '' !== $normalized_name ) {
				$normalized_names[ $normalized_name ][] = $name;
			}

			if ( empty( $ref['prefight_record'] ) || ! is_array( $ref['prefight_record'] ) || ! empty( $ref['prefight_record']['is_missing'] ) ) {
				$warnings[] = 'Missing prefight record for fighter reference: ' . $name;
			}

			$preview = $this->preview_ref( $ref, $key, $warnings, $conflicts );
			$actions[ $preview['action'] ] = ( $actions[ $preview['action'] ] ?? 0 ) + 1;
			$previews[ $key ] = $preview;
		}

		foreach ( $normalized_names as $normalized_name => $names ) {
			if ( count( $names ) > 1 ) {
				$warnings[] = 'Duplicate fighter name appears in JSON: ' . $names[0];
			}
		}

		return array(
			'items'             => array_values( $previews ),
			'actions'           => $actions,
			'by_ref_key'        => $previews,
			'total_refs'        => array_sum( array_map( static fn( $ref ) => (int) $ref['seen_count'], $refs ) ),
			'unique_refs'       => count( $refs ),
		);
	}

	private function collect_refs( array $events ): array {
		$refs = array();

		foreach ( $events as $event ) {
			foreach ( (array) ( $event['bouts'] ?? array() ) as $bout ) {
				foreach ( array( 'fighter_a', 'fighter_b' ) as $role ) {
					if ( empty( $bout[ $role ] ) || ! is_array( $bout[ $role ] ) ) {
						continue;
					}

					$ref = $bout[ $role ];
					$key = $this->fighter_ref_key( $ref );

					if ( ! isset( $refs[ $key ] ) ) {
						$ref['seen_count'] = 0;
						$refs[ $key ]      = $ref;
					}

					++$refs[ $key ]['seen_count'];
				}
			}
		}

		return $refs;
	}

	private function preview_ref( array $ref, string $key, array &$warnings, array &$conflicts ): array {
		$name              = (string) ( $ref['name'] ?? '' );
		$source_fighter_id = (string) ( $ref['source_fighter_id'] ?? '' );
		$source_url        = (string) ( $ref['url'] ?? '' );
		$source_url_hash   = TapologyFighterUrl::source_url_hash( $source_url );
		$normalized_name   = Sanitizer::normalize_name( $name );
		$matched_fighter   = null;
		$action            = 'create_provisional_candidate';
		$confidence        = 0;
		$reason            = 'No source mapping or strong normalized-name match found.';

		$source = $this->fighter_sources->find_by_source( 'tapology', $source_fighter_id );
		if ( $source ) {
			$matched_fighter = ! empty( $source['fighter_id'] ) ? $this->fighters->find( (int) $source['fighter_id'] ) : null;
			$action          = 'exact_source_match';
			$confidence      = 100;
			$reason          = 'Exact source fighter mapping exists.';

			if ( $matched_fighter && Sanitizer::normalize_name( (string) $matched_fighter['display_name'] ) !== $normalized_name ) {
				$warnings[] = 'source_fighter_id ' . $source_fighter_id . ' maps to ' . $matched_fighter['display_name'] . ' but incoming name is ' . $name . '; source ID remains primary and canonical name is preserved.';
			}
		} elseif ( null !== $source_url_hash ) {
			$url_matches = $this->fighter_sources->find_by_identity_hash( 'tapology', $source_url_hash );
			if ( empty( $url_matches ) ) {
				$url_matches = $this->fighter_sources->find_by_normalized_source_url( 'tapology', $source_url );
			}
			$fighter_ids = array();
			foreach ( $url_matches as $url_match ) {
				$fighter_id = (int) ( $url_match['fighter_id'] ?? 0 );
				if ( $fighter_id > 0 ) {
					$fighter_ids[ $fighter_id ] = true;
				}
			}

			if ( 1 === count( $fighter_ids ) ) {
				$matched_fighter = $this->fighters->find( (int) array_key_first( $fighter_ids ) );
				$action          = 'exact_source_url_hash_match';
				$confidence      = 100;
				$reason          = 'Exact canonical source URL hash mapping exists.';
			} elseif ( count( $fighter_ids ) > 1 ) {
				$action     = 'ambiguous_source_url_hash';
				$confidence = 0;
				$reason     = 'Multiple canonical fighters share this normalized source URL.';
				$conflicts[] = 'Ambiguous source URL match for ' . $name . '.';
			} elseif ( '' === $source_fighter_id ) {
				$action = 'create_provisional_with_url_only_identity';
				$reason = 'No existing canonical URL hash mapping found; URL-only identity can create a provisional fighter.';
			}
		} elseif ( '' !== $normalized_name ) {
			$likely = $this->find_likely_by_normalized_name( $normalized_name );
			if ( 1 === count( $likely ) ) {
				$matched_fighter = $likely[0];
				$action          = 'likely_match_review';
				$confidence      = 85;
				$reason          = 'One canonical fighter or alias has the same normalized name.';
			} elseif ( count( $likely ) > 1 ) {
				$action     = 'unresolved_fighter_ref';
				$confidence = 0;
				$reason     = 'Multiple canonical fighters or aliases share this normalized name.';
				$conflicts[] = 'Ambiguous fighter name match for ' . $name . '.';
			}
		}

		return array(
			'ref_key'                    => $key,
			'source_fighter_id'          => $source_fighter_id,
			'source_url_hash'            => $source_url_hash,
			'source_fighter_numeric_id'  => (string) ( $ref['source_fighter_numeric_id'] ?? '' ),
			'source_name'                => $name,
			'source_url'                 => $source_url,
			'seen_count'                 => (int) ( $ref['seen_count'] ?? 1 ),
			'matched_fighter_id'         => $matched_fighter ? (int) $matched_fighter['id'] : null,
			'matched_fighter'            => $matched_fighter ? (string) $matched_fighter['display_name'] : null,
			'action'                     => $action,
			'confidence'                 => $confidence,
			'reason'                     => $reason,
			'future_status'              => in_array( $action, array( 'create_provisional_candidate', 'create_provisional_with_url_only_identity' ), true ) ? 'provisional' : null,
			'future_rankability_status'  => in_array( $action, array( 'create_provisional_candidate', 'create_provisional_with_url_only_identity' ), true ) ? 'pending_review' : null,
			'future_is_public'           => in_array( $action, array( 'create_provisional_candidate', 'create_provisional_with_url_only_identity' ), true ) ? 0 : null,
			'future_is_rankable'         => in_array( $action, array( 'create_provisional_candidate', 'create_provisional_with_url_only_identity' ), true ) ? 0 : null,
		);
	}

	private function fighter_ref_key( array $ref ): string {
		if ( ! empty( $ref['source_fighter_id'] ) ) {
			return 'source:' . (string) $ref['source_fighter_id'];
		}

		$source_url_hash = TapologyFighterUrl::source_url_hash( (string) ( $ref['url'] ?? '' ) );
		if ( null !== $source_url_hash ) {
			return 'url_hash:' . $source_url_hash;
		}

		return 'name:' . Sanitizer::normalize_name( (string) ( $ref['name'] ?? '' ) );
	}

	private function find_likely_by_normalized_name( string $normalized_name ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT DISTINCT f.*
				FROM {$this->fighters_table} f
				LEFT JOIN {$this->aliases_table} a ON a.fighter_id = f.id
				WHERE f.normalized_name = %s OR a.normalized_alias = %s
				LIMIT 5
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized_name,
				$normalized_name
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
