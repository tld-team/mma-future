<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutImportPreviewService {
	private BoutRepository $bouts;
	private BoutSourceRepository $bout_sources;
	private string $bouts_table;
	private string $participants_table;

	public function __construct() {
		$tables                   = Schema::table_names();
		$this->bouts              = new BoutRepository();
		$this->bout_sources       = new BoutSourceRepository();
		$this->bouts_table        = $tables['bouts'];
		$this->participants_table = $tables['bout_participants'];
	}

	public function preview_bouts( array $events, array $event_previews, array $fighter_previews, array &$warnings, array &$conflicts ): array {
		$previews        = array();
		$source_ids      = array();
		$identity_hashes = array();
		$actions         = array(
			'create_candidate'     => 0,
			'update_candidate'     => 0,
			'no_change_candidate'  => 0,
			'review_bout_match'    => 0,
			'excluded_amateur'     => 0,
			'excluded_cancelled'   => 0,
			'excluded_overturned'  => 0,
			'upcoming_review'      => 0,
			'skipped_non_scoring'  => 0,
		);
		$non_scoring = 0;

		foreach ( $events as $event_index => $event ) {
			foreach ( (array) ( $event['bouts'] ?? array() ) as $bout_index => $bout ) {
				$source_bout_id = (string) ( $bout['source_bout_id'] ?? '' );
				$identity_hash  = (string) ( $bout['identity_hash'] ?? '' );

				if ( '' !== $source_bout_id ) {
					if ( isset( $source_ids[ $source_bout_id ] ) ) {
						$conflicts[] = 'Duplicate source_bout_id in JSON: ' . $source_bout_id;
					}
					$source_ids[ $source_bout_id ] = true;
				}

				if ( '' !== $identity_hash ) {
					if ( isset( $identity_hashes[ $identity_hash ] ) ) {
						$conflicts[] = 'Duplicate bout identity_hash in JSON: ' . $identity_hash;
					}
					$identity_hashes[ $identity_hash ] = true;
				}

				$preview = $this->preview_bout( $event, $bout, $event_index, $bout_index, $event_previews, $fighter_previews, $warnings, $conflicts );
				$actions[ $preview['action'] ] = ( $actions[ $preview['action'] ] ?? 0 ) + 1;
				if ( ! $preview['scoring_candidate'] ) {
					++$non_scoring;
				}
				$previews[] = $preview;
			}
		}

		return array(
			'items'        => $previews,
			'actions'      => $actions,
			'non_scoring'  => $non_scoring,
		);
	}

	private function preview_bout( array $event, array $bout, int $event_index, int $bout_index, array $event_previews, array $fighter_previews, array &$warnings, array &$conflicts ): array {
		$source_bout_id = (string) ( $bout['source_bout_id'] ?? '' );
		$content_hash   = (string) ( $bout['content_hash'] ?? '' );
		$identity_hash  = (string) ( $bout['identity_hash'] ?? '' );
		$flags          = (array) ( $bout['flags'] ?? array() );
		$result         = (array) ( $bout['result'] ?? array() );
		$source_event_id = (string) ( $event['source_event_id'] ?? ( $bout['source_event_id'] ?? '' ) );
		$event_preview  = isset( $event_previews[ $source_event_id ] ) ? $event_previews[ $source_event_id ] : null;
		$warning_count  = count( (array) ( $bout['warnings'] ?? array() ) );
		$matched_bout   = null;
		$action         = 'create_candidate';
		$reason         = 'No source mapping or canonical duplicate found.';

		if ( ! empty( $flags['is_amateur'] ) ) {
			$action = 'excluded_amateur';
			$reason = 'Bout is marked amateur by source flags.';
		} elseif ( ! empty( $flags['is_cancelled'] ) ) {
			$action = 'excluded_cancelled';
			$reason = 'Bout is marked cancelled by source flags.';
		} elseif ( ! empty( $flags['is_overturned'] ) ) {
			$action = 'excluded_overturned';
			$reason = 'Bout is marked overturned by source flags.';
		} elseif ( ! empty( $flags['is_upcoming'] ) ) {
			$action = 'upcoming_review';
			$reason = 'Bout is upcoming and cannot be scoring data.';
		} elseif ( isset( $flags['is_scoring_candidate'] ) && false === $flags['is_scoring_candidate'] ) {
			$action = 'skipped_non_scoring';
			$reason = 'Source marks bout as non-scoring candidate.';
		} else {
			$matched_source = '' !== $source_bout_id ? $this->bout_sources->find_by_source( 'tapology', $source_bout_id ) : null;

			if ( $matched_source ) {
				$matched_bout = ! empty( $matched_source['bout_id'] ) ? $this->bouts->find( (int) $matched_source['bout_id'] ) : null;
				$action       = (string) $matched_source['content_hash'] === $content_hash ? 'no_change_candidate' : 'update_candidate';
				$reason       = 'Exact source bout mapping exists.';

				if ( ! empty( $matched_source['identity_hash'] ) && $matched_source['identity_hash'] !== $identity_hash ) {
					$warnings[] = 'source_bout_id ' . $source_bout_id . ' is mapped but incoming identity_hash differs; exact source ID will update the existing bout and log review context.';
					++$warning_count;
				}
			} else {
				$duplicate = $this->find_likely_duplicate( $event_preview, $bout, $fighter_previews );
				if ( $duplicate ) {
					$matched_bout = $duplicate;
					$action       = 'review_bout_match';
					$reason       = 'Canonical bout with same event, fighters, and result shape exists.';
				}
			}
		}

		if ( empty( $result['method_category'] ) || 'unknown' === (string) $result['method_category'] ) {
			$warnings[] = 'Bout ' . ( '' !== $source_bout_id ? $source_bout_id : '#' . ( $bout_index + 1 ) ) . ' has missing/unknown method category.';
			++$warning_count;
		}

		$fighter_a = (array) ( $bout['fighter_a'] ?? array() );
		$fighter_b = (array) ( $bout['fighter_b'] ?? array() );

		return array(
			'event_index'            => $event_index,
			'bout_index'             => $bout_index,
			'source_event_id'        => $source_event_id,
			'event_name'             => (string) ( $event['event_name'] ?? '' ),
			'event_date'             => (string) ( $event['event_date'] ?? '' ),
			'source_bout_id'         => $source_bout_id,
			'fighter_a'              => (string) ( $fighter_a['name'] ?? '' ),
			'fighter_b'              => (string) ( $fighter_b['name'] ?? '' ),
			'result_type'            => (string) ( $bout['result_type'] ?? '' ),
			'method_category'        => (string) ( $result['method_category'] ?? '' ),
			'method_detail'          => (string) ( $result['method_detail'] ?? '' ),
			'round'                  => isset( $result['round'] ) ? (int) $result['round'] : null,
			'time'                   => (string) ( $result['time'] ?? '' ),
			'weight_class'           => (string) ( $bout['weight_class'] ?? '' ),
			'identity_hash'          => $identity_hash,
			'content_hash'           => $content_hash,
			'action'                 => $action,
			'reason'                 => $reason,
			'scoring_candidate'      => ! empty( $flags['is_scoring_candidate'] ),
			'matched_bout_id'        => $matched_bout ? (int) $matched_bout['id'] : null,
			'warning_count'          => $warning_count,
		);
	}

	private function find_likely_duplicate( ?array $event_preview, array $bout, array $fighter_previews ): ?array {
		global $wpdb;

		if ( ! $event_preview || empty( $event_preview['matched_event_id'] ) ) {
			return null;
		}

		$fighter_a_key = $this->fighter_ref_key( (array) ( $bout['fighter_a'] ?? array() ) );
		$fighter_b_key = $this->fighter_ref_key( (array) ( $bout['fighter_b'] ?? array() ) );
		$fighter_a     = $fighter_previews[ $fighter_a_key ] ?? null;
		$fighter_b     = $fighter_previews[ $fighter_b_key ] ?? null;

		if ( empty( $fighter_a['matched_fighter_id'] ) || empty( $fighter_b['matched_fighter_id'] ) ) {
			return null;
		}

		$result = (array) ( $bout['result'] ?? array() );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT b.*
				FROM {$this->participants_table} p1
				INNER JOIN {$this->participants_table} p2 ON p2.bout_id = p1.bout_id
				INNER JOIN {$this->bouts_table} b ON b.id = p1.bout_id
				WHERE b.event_id = %d
					AND p1.fighter_id = %d
					AND p2.fighter_id = %d
					AND COALESCE(b.result_type, '') = %s
					AND COALESCE(b.method_category, '') = %s
					AND COALESCE(b.round_number, 0) = %d
					AND COALESCE(b.time_in_round, '') = %s
				LIMIT 1
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $event_preview['matched_event_id'],
				(int) $fighter_a['matched_fighter_id'],
				(int) $fighter_b['matched_fighter_id'],
				(string) ( $bout['result_type'] ?? '' ),
				(string) ( $result['method_category'] ?? '' ),
				(int) ( $result['round'] ?? 0 ),
				(string) ( $result['time'] ?? '' )
			),
			ARRAY_A
		);

		return ! empty( $rows[0] ) ? $rows[0] : null;
	}

	private function fighter_ref_key( array $ref ): string {
		if ( ! empty( $ref['source_fighter_id'] ) ) {
			return 'source:' . (string) $ref['source_fighter_id'];
		}

		$source_url_hash = \MMAF\DataEngine\Support\TapologyFighterUrl::source_url_hash( (string) ( $ref['url'] ?? '' ) );
		if ( null !== $source_url_hash ) {
			return 'url_hash:' . $source_url_hash;
		}

		return 'name:' . \MMAF\DataEngine\Support\Sanitizer::normalize_name( (string) ( $ref['name'] ?? '' ) );
	}

}
