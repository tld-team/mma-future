<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterReadinessService {
	private const BULK_CONTEXT = 'Bulk fighter readiness operation';
	private const EXCLUDED_STATUSES = array( 'hidden', 'merged', 'retired', 'deleted_soft' );
	private const INELIGIBLE_RANKABILITY_STATUSES = array(
		'ineligible_age',
		'ineligible_inactive',
		'ineligible_ufc',
		'ineligible_too_many_fights',
		'ineligible_loss_limit',
	);

	private string $fighters_table;
	private string $sources_table;
	private string $stats_table;
	private EligibilityService $eligibility;
	private AuditLogService $audit_log;

	public function __construct() {
		$tables                = Schema::table_names();
		$this->fighters_table  = $tables['fighters'];
		$this->sources_table   = $tables['fighter_sources'];
		$this->stats_table     = $tables['fighter_stats_current'];
		$this->eligibility     = new EligibilityService();
		$this->audit_log       = new AuditLogService();
	}

	public function report( ?string $reference_date = null ): array {
		$items  = $this->evaluate_rows( $this->load_rows(), $reference_date );
		$counts = $this->empty_counts();
		$buckets = $this->empty_buckets();
		$blockers = array();

		foreach ( $items as $item ) {
			$fighter = $item['fighter'];
			++$counts['total_fighters'];

			if ( 'verified' === (string) $fighter['status'] ) {
				++$counts['verified_fighters'];
			}
			if ( 'provisional' === (string) $fighter['status'] ) {
				++$counts['provisional_fighters'];
			}
			if ( 1 === (int) $fighter['is_public'] ) {
				++$counts['public_fighters'];
			}
			if ( 1 === (int) $fighter['is_rankable'] ) {
				++$counts['rankable_fighters'];
			}
			if ( $item['has_tapology_source'] ) {
				++$counts['tapology_mapped_fighters'];
			} else {
				++$counts['fighters_without_tapology_mapping'];
			}
			if ( $item['has_stats'] ) {
				++$counts['fighters_with_stats'];
			}
			if ( ! $item['has_birth'] ) {
				++$counts['fighters_missing_dob_or_birth_year'];
			}
			if ( ! $item['has_weight_class'] ) {
				++$counts['fighters_missing_weight_class'];
			}
			if ( ! $item['has_last_fight'] ) {
				++$counts['fighters_missing_last_fight'];
			}
			if ( $item['blocked_by_insufficient_data'] ) {
				++$counts['fighters_blocked_by_insufficient_data'];
			}
			if ( $item['blocked_by_review_state'] ) {
				++$counts['fighters_blocked_by_public_rankable_review_state'];
			}

			$bucket = $item['readiness_bucket'];
			if ( isset( $buckets[ $bucket ] ) ) {
				++$buckets[ $bucket ]['count'];
			}

			foreach ( $item['blocker_codes'] as $code ) {
				if ( ! isset( $blockers[ $code ] ) ) {
					$blockers[ $code ] = 0;
				}
				++$blockers[ $code ];
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $a['readiness_score'] !== $b['readiness_score'] ) {
					return $b['readiness_score'] <=> $a['readiness_score'];
				}

				return (int) $a['fighter']['id'] <=> (int) $b['fighter']['id'];
			}
		);

		return array(
			'counts'     => $counts,
			'buckets'    => $buckets,
			'blockers'   => $blockers,
			'closest'    => array_slice( $items, 0, 15 ),
			'items'      => $items,
			'generated_at' => DateTime::mysql_now(),
		);
	}

	public function promotion_report( ?string $reference_date = null, int $limit = 25 ): array {
		$items = $this->evaluate_rows( $this->load_rows(), $reference_date );
		$limit = max( 1, min( 100, $limit ) );

		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $a['readiness_score'] !== $b['readiness_score'] ) {
					return $b['readiness_score'] <=> $a['readiness_score'];
				}

				return (int) $a['fighter']['id'] <=> (int) $b['fighter']['id'];
			}
		);

		$summary = array(
			'total_fighters' => count( $items ),
			'ready_for_verified' => 0,
			'ready_for_public' => 0,
			'ready_for_rankable' => 0,
			'data_formula_ready_but_needs_review_state' => 0,
			'blocked_for_rankable' => 0,
		);
		$candidates = array(
			'ready_for_verified' => array(),
			'ready_for_public' => array(),
			'ready_for_rankable' => array(),
			'data_formula_ready_but_needs_review_state' => array(),
		);
		$rankable_blockers = array();

		foreach ( $items as $item ) {
			$fighter = $item['fighter'];

			if ( 'verified' !== (string) ( $fighter['status'] ?? '' ) && empty( $this->verified_blockers( $item ) ) ) {
				++$summary['ready_for_verified'];
				$this->append_candidate( $candidates['ready_for_verified'], $item, $limit );
			}

			if ( 1 !== (int) ( $fighter['is_public'] ?? 0 ) && empty( $item['public_blocker_codes'] ) ) {
				++$summary['ready_for_public'];
				$this->append_candidate( $candidates['ready_for_public'], $item, $limit );
			}

			if ( ( 1 !== (int) ( $fighter['is_rankable'] ?? 0 ) || 'rankable' !== (string) ( $fighter['rankability_status'] ?? '' ) ) && empty( $item['rankable_blocker_codes'] ) ) {
				++$summary['ready_for_rankable'];
				$this->append_candidate( $candidates['ready_for_rankable'], $item, $limit );
			}

			if ( 'A' === (string) $item['readiness_bucket'] && ! empty( $item['state_blocker_codes'] ) && empty( $item['data_blocker_codes'] ) && empty( $item['formula_blocker_codes'] ) ) {
				++$summary['data_formula_ready_but_needs_review_state'];
				$this->append_candidate( $candidates['data_formula_ready_but_needs_review_state'], $item, $limit );
			}

			if ( ! empty( $item['rankable_blocker_codes'] ) ) {
				++$summary['blocked_for_rankable'];
				foreach ( $item['rankable_blocker_codes'] as $code ) {
					if ( ! isset( $rankable_blockers[ $code ] ) ) {
						$rankable_blockers[ $code ] = 0;
					}
					++$rankable_blockers[ $code ];
				}
			}
		}

		arsort( $rankable_blockers );

		return array(
			'generated_at' => DateTime::mysql_now(),
			'reference_date' => $reference_date ? $reference_date : current_time( 'Y-m-d' ),
			'limit' => $limit,
			'summary' => $summary,
			'top_rankable_blockers' => $rankable_blockers,
			'candidates' => $candidates,
			'notes' => array(
				'read_only' => true,
				'no_statuses_changed' => true,
				'no_rankings_recalculated_or_activated' => true,
				'recommended_order' => array(
					'Review data/formula-ready candidates manually.',
					'Mark a small reviewed subset as verified.',
					'Mark verified candidates public only after profile identity is confirmed.',
					'Mark public candidates rankable only after a fresh stats rebuild and readiness validation.',
				),
			),
		);
	}

	public function evaluate_fighter_ids( array $fighter_ids, ?string $reference_date = null ): array {
		$fighter_ids = $this->normalize_ids( $fighter_ids );
		if ( empty( $fighter_ids ) ) {
			return array();
		}

		return $this->evaluate_rows( $this->load_rows( $fighter_ids ), $reference_date );
	}

	public function evaluate_fighters_for_table( array $fighters, ?string $reference_date = null ): array {
		$ids = array();
		foreach ( $fighters as $fighter ) {
			$ids[] = (int) ( $fighter['id'] ?? 0 );
		}

		$items = $this->evaluate_fighter_ids( $ids, $reference_date );
		$indexed = array();
		foreach ( $items as $item ) {
			$indexed[ (int) $item['fighter']['id'] ] = $item;
		}

		return $indexed;
	}

	public function process_bulk_action( string $action, array $fighter_ids, int $user_id ): array {
		global $wpdb;

		$fighter_ids = $this->normalize_ids( $fighter_ids );
		$result = array(
			'action'         => $action,
			'selected_count' => count( $fighter_ids ),
			'updated_count'  => 0,
			'ready_count'    => 0,
			'blocked_count'  => 0,
			'skipped_count'  => 0,
			'updated'        => array(),
			'ready'          => array(),
			'blocked'        => array(),
			'skipped'        => array(),
			'reason_counts'  => array(),
			'message'        => '',
			'next_step'      => __( 'No rankings were recalculated or activated.', 'mma-future-data-engine' ),
		);

		if ( empty( $fighter_ids ) ) {
			$result['message'] = __( 'No fighters were selected.', 'mma-future-data-engine' );
			return $result;
		}

		$items = $this->evaluate_fighter_ids( $fighter_ids );
		$updates = array();

		foreach ( $items as $item ) {
			$decision = $this->bulk_decision( $action, $item );
			if ( 'blocked' === $decision['status'] ) {
				++$result['blocked_count'];
				$result['blocked'][] = $this->result_row( $item, $decision['reasons'] );
				$this->add_reason_counts( $result, $decision['reasons'] );
				continue;
			}

			if ( 'ready' === $decision['status'] ) {
				++$result['ready_count'];
				$result['ready'][] = $this->result_row( $item, $decision['reasons'] );
				$this->add_reason_counts( $result, $decision['reasons'] );
				continue;
			}

			if ( 'skipped' === $decision['status'] ) {
				++$result['skipped_count'];
				$result['skipped'][] = $this->result_row( $item, $decision['reasons'] );
				$this->add_reason_counts( $result, $decision['reasons'] );
				continue;
			}

			if ( ! empty( $decision['update'] ) ) {
				$updates[] = array(
					'item'   => $item,
					'update' => $decision['update'],
				);
			}
		}

		if ( 'validate_selected_for_ranking' === $action ) {
			$result['message'] = sprintf(
				/* translators: 1: selected count, 2: ready count, 3: blocked count. */
				__( '%1$d selected. %2$d ready for rankable promotion. %3$d blocked.', 'mma-future-data-engine' ),
				$result['selected_count'],
				$result['ready_count'],
				$result['blocked_count']
			);
			$result['next_step'] = __( 'Fill missing readiness data, then mark a small reviewed subset as verified, public, and rankable. No rankings were recalculated or activated.', 'mma-future-data-engine' );
			return $result;
		}

		if ( empty( $updates ) ) {
			$result['message'] = sprintf(
				/* translators: 1: selected count, 2: blocked count, 3: skipped count. */
				__( '%1$d selected. 0 updated. %2$d blocked. %3$d skipped/no-op.', 'mma-future-data-engine' ),
				$result['selected_count'],
				$result['blocked_count'],
				$result['skipped_count']
			);
			return $result;
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $updates as $pending ) {
				$item = $pending['item'];
				$before = $item['fighter'];
				$fighter_id = (int) $before['id'];
				$update = $pending['update'];
				$update['updated_at'] = DateTime::mysql_now();

				$updated = $wpdb->update(
					$this->fighters_table,
					$update,
					array( 'id' => $fighter_id ),
					null,
					array( '%d' )
				);

				if ( false === $updated ) {
					throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not update fighter readiness state.', 'mma-future-data-engine' ) );
				}

				$after = $this->load_fighter( $fighter_id );
				$this->audit_log->write(
					'fighter_bulk_' . $action,
					'fighter',
					$fighter_id,
					$this->audit_state( $before ),
					$this->audit_state( $after ?: array_merge( $before, $update ) ),
					self::BULK_CONTEXT,
					$user_id
				);

				++$result['updated_count'];
				$result['updated'][] = $this->result_row( $item, array() );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		$result['message'] = sprintf(
			/* translators: 1: selected count, 2: updated count, 3: blocked count, 4: skipped count. */
			__( '%1$d selected. %2$d updated. %3$d blocked. %4$d skipped/no-op.', 'mma-future-data-engine' ),
			$result['selected_count'],
			$result['updated_count'],
			$result['blocked_count'],
			$result['skipped_count']
		);

		if ( in_array( $action, array( 'mark_rankable', 'mark_not_rankable', 'move_to_provisional' ), true ) ) {
			$result['next_step'] = __( 'Recommended next step: run a full stats rebuild if source data changed, then recalculate draft rankings manually. No rankings were recalculated or activated.', 'mma-future-data-engine' );
		}

		return $result;
	}

	private function bulk_decision( string $action, array $item ): array {
		$fighter = $item['fighter'];
		$id = (int) $fighter['id'];

		if ( $id <= 0 ) {
			return $this->blocked( array( 'invalid_fighter_id' ) );
		}

		switch ( $action ) {
			case 'validate_selected_for_ranking':
				return empty( $item['rankable_blocker_codes'] ) ? $this->ready( array( 'ready_for_rankable_promotion' ) ) : $this->blocked( $item['rankable_blocker_codes'] );

			case 'mark_verified':
				$reasons = $this->verified_blockers( $item );
				if ( ! empty( $reasons ) ) {
					return $this->blocked( $reasons );
				}
				if ( 'verified' === (string) $fighter['status'] ) {
					return $this->skipped( array( 'already_verified' ) );
				}
				return $this->update( array( 'status' => 'verified' ) );

			case 'mark_public':
				$reasons = $this->public_blockers( $item );
				if ( ! empty( $reasons ) ) {
					return $this->blocked( $reasons );
				}
				if ( 1 === (int) $fighter['is_public'] ) {
					return $this->skipped( array( 'already_public' ) );
				}
				return $this->update( array( 'is_public' => 1 ) );

			case 'mark_not_public':
				if ( 0 === (int) $fighter['is_public'] && 0 === (int) $fighter['is_rankable'] && 'not_public' === (string) $fighter['rankability_status'] ) {
					return $this->skipped( array( 'already_not_public' ) );
				}
				return $this->update(
					array(
						'is_public'          => 0,
						'is_rankable'        => 0,
						'rankability_status' => 'not_public',
					)
				);

			case 'mark_rankable':
				if ( ! empty( $item['rankable_blocker_codes'] ) ) {
					return $this->blocked( $item['rankable_blocker_codes'] );
				}
				if ( 1 === (int) $fighter['is_rankable'] && 'rankable' === (string) $fighter['rankability_status'] ) {
					return $this->skipped( array( 'already_rankable' ) );
				}
				return $this->update(
					array(
						'is_rankable'        => 1,
						'rankability_status' => 'rankable',
					)
				);

			case 'mark_not_rankable':
				if ( 0 === (int) $fighter['is_rankable'] && 'rankable' !== (string) $fighter['rankability_status'] ) {
					return $this->skipped( array( 'already_not_rankable' ) );
				}
				$next_status = in_array( (string) $fighter['rankability_status'], self::INELIGIBLE_RANKABILITY_STATUSES, true ) ? (string) $fighter['rankability_status'] : 'pending_review';
				return $this->update(
					array(
						'is_rankable'        => 0,
						'rankability_status' => $next_status,
					)
				);

			case 'move_to_provisional':
				if ( 'provisional' === (string) $fighter['status'] && 'pending_review' === (string) $fighter['rankability_status'] && 0 === (int) $fighter['is_public'] && 0 === (int) $fighter['is_rankable'] ) {
					return $this->skipped( array( 'already_provisional_pending_review' ) );
				}
				return $this->update(
					array(
						'status'             => 'provisional',
						'rankability_status' => 'pending_review',
						'is_public'          => 0,
						'is_rankable'        => 0,
					)
				);
		}

		return $this->blocked( array( 'unsupported_bulk_action' ) );
	}

	private function verified_blockers( array $item ): array {
		$fighter = $item['fighter'];
		$reasons = array();

		if ( '' === trim( (string) ( $fighter['display_name'] ?? '' ) ) ) {
			$reasons[] = 'missing_display_name';
		}
		if ( ! $item['has_tapology_source'] ) {
			$reasons[] = 'missing_tapology_mapping';
		}
		if ( in_array( (string) ( $fighter['status'] ?? '' ), self::EXCLUDED_STATUSES, true ) || 1 === (int) ( $fighter['deleted_soft'] ?? 0 ) ) {
			$reasons[] = 'invalid_hidden_deleted_merged_or_retired_status';
		}

		return array_values( array_unique( $reasons ) );
	}

	private function public_blockers( array $item ): array {
		$fighter = $item['fighter'];
		$reasons = $this->verified_blockers( $item );

		if ( 'verified' !== (string) ( $fighter['status'] ?? '' ) ) {
			$reasons[] = 'not_verified';
		}
		if ( empty( $fighter['gender'] ) ) {
			$reasons[] = 'missing_gender';
		}
		if ( ! $item['has_weight_class'] ) {
			$reasons[] = 'missing_weight_class';
		}
		if ( ! $item['has_countable_bout_history'] ) {
			$reasons[] = 'no_countable_bout_history';
		}

		return array_values( array_unique( $reasons ) );
	}

	private function rankable_blockers( array $item ): array {
		$fighter = $item['fighter'];
		$reasons = array_merge( $this->public_blockers( $item ), $item['formula_blocker_codes'] );

		if ( 1 !== (int) ( $fighter['is_public'] ?? 0 ) ) {
			$reasons[] = 'not_public';
		}
		if ( in_array( (string) ( $fighter['status'] ?? '' ), self::EXCLUDED_STATUSES, true ) || 1 === (int) ( $fighter['deleted_soft'] ?? 0 ) ) {
			$reasons[] = 'invalid_hidden_deleted_merged_or_retired_status';
		}

		return array_values( array_unique( $reasons ) );
	}

	private function evaluate_rows( array $rows, ?string $reference_date = null ): array {
		$reference_date = $reference_date ? $reference_date : current_time( 'Y-m-d' );
		$items = array();

		foreach ( $rows as $row ) {
			$stats = $this->stats_from_row( $row );
			$fighter = $this->fighter_from_row( $row );
			$simulated = $fighter;
			$simulated['status'] = 'verified';
			$simulated['is_rankable'] = 1;
			$simulated['rankability_status'] = 'rankable';

			$current_evaluation = $this->eligibility->evaluate( $fighter, $stats, $reference_date );
			$formula_evaluation = $this->eligibility->evaluate( $simulated, $stats, $reference_date );

			$item = array(
				'fighter'                    => $fighter,
				'stats'                      => $stats,
				'current_eligibility'        => $current_evaluation,
				'formula_eligibility'        => $formula_evaluation,
				'has_tapology_source'        => ! empty( $row['tapology_source_row_id'] ) && ! empty( $row['tapology_source_url'] ),
				'has_birth'                  => ! empty( $fighter['date_of_birth'] ) || ! empty( $fighter['birth_year'] ),
				'has_weight_class'           => ! empty( $fighter['weight_class'] ) && 'unknown' !== (string) $fighter['weight_class'],
				'has_stats'                  => is_array( $stats ),
				'has_last_fight'             => is_array( $stats ) && ! empty( $stats['last_fight_date'] ),
				'has_countable_bout_history' => is_array( $stats ) && (int) ( $stats['pro_fights_count'] ?? 0 ) > 0,
			);

			$item['data_blocker_codes'] = $this->data_blockers( $item );
			$item['state_blocker_codes'] = $this->state_blockers( $item );
			$item['formula_blocker_codes'] = $this->formula_blockers( $formula_evaluation );
			$item['rankable_blocker_codes'] = $this->rankable_blockers( $item );
			$item['public_blocker_codes'] = $this->public_blockers( $item );
			$item['blocker_codes'] = array_values( array_unique( array_merge( $item['data_blocker_codes'], $item['state_blocker_codes'], $item['formula_blocker_codes'] ) ) );
			$item['blocked_by_insufficient_data'] = ! empty( $item['data_blocker_codes'] ) || in_array( 'insufficient_data', (array) $fighter['rankability_status'], true );
			$item['blocked_by_review_state'] = ! empty( $item['state_blocker_codes'] );
			$item['readiness_bucket'] = $this->bucket_for_item( $item );
			$item['readiness_score'] = $this->readiness_score( $item );
			$item['eligibility_preview'] = empty( $item['rankable_blocker_codes'] ) ? 'ready' : 'blocked';
			$item['blocker_summary'] = $this->summary_for_codes( array_slice( $item['rankable_blocker_codes'], 0, 5 ) );

			$items[] = $item;
		}

		return $items;
	}

	private function data_blockers( array $item ): array {
		$fighter = $item['fighter'];
		$reasons = array();

		if ( ! $item['has_tapology_source'] ) {
			$reasons[] = 'missing_tapology_mapping';
		}
		if ( ! $item['has_birth'] ) {
			$reasons[] = 'missing_dob_or_birth_year';
		}
		if ( empty( $fighter['gender'] ) ) {
			$reasons[] = 'missing_gender';
		}
		if ( ! $item['has_weight_class'] ) {
			$reasons[] = 'missing_weight_class';
		}
		if ( ! empty( $fighter['gender'] ) && $item['has_weight_class'] && ! $this->is_weight_class_compatible( (string) $fighter['gender'], (string) $fighter['weight_class'] ) ) {
			$reasons[] = 'weight_class_gender_mismatch';
		}
		if ( ! $item['has_stats'] ) {
			$reasons[] = 'missing_stats_row';
		}
		if ( $item['has_stats'] && ! $item['has_last_fight'] ) {
			$reasons[] = 'missing_last_fight';
		}
		if ( ! $item['has_countable_bout_history'] ) {
			$reasons[] = 'no_countable_bout_history';
		}

		return array_values( array_unique( $reasons ) );
	}

	private function state_blockers( array $item ): array {
		$fighter = $item['fighter'];
		$reasons = array();

		if ( 'verified' !== (string) ( $fighter['status'] ?? '' ) ) {
			$reasons[] = 'not_verified';
		}
		if ( 1 !== (int) ( $fighter['is_public'] ?? 0 ) ) {
			$reasons[] = 'not_public';
		}
		if ( 1 !== (int) ( $fighter['is_rankable'] ?? 0 ) ) {
			$reasons[] = 'not_rankable_flag';
		}
		if ( 'rankable' !== (string) ( $fighter['rankability_status'] ?? '' ) ) {
			$reasons[] = 'rankability_status_not_rankable';
		}
		if ( in_array( (string) ( $fighter['status'] ?? '' ), self::EXCLUDED_STATUSES, true ) || 1 === (int) ( $fighter['deleted_soft'] ?? 0 ) ) {
			$reasons[] = 'invalid_hidden_deleted_merged_or_retired_status';
		}

		return array_values( array_unique( $reasons ) );
	}

	private function formula_blockers( array $evaluation ): array {
		$map = array(
			'deleted_soft' => 'deleted_soft',
			'insufficient_data_missing_birthdate' => 'missing_dob_or_birth_year',
			'ineligible_age_35_plus' => 'age_rule_fails',
			'insufficient_data_missing_last_fight_date' => 'missing_last_fight',
			'ineligible_inactive_24_months' => 'inactivity_rule_fails',
			'ineligible_ufc' => 'ufc_gate_fails',
			'ineligible_too_many_fights' => 'too_many_fights_rule_fails',
			'ineligible_loss_limit' => 'loss_limit_rule_fails',
		);
		$reasons = array();

		foreach ( (array) ( $evaluation['reasons'] ?? array() ) as $reason ) {
			if ( in_array( $reason, array( 'not_rankable_flag', 'rankability_status_not_rankable', 'ineligible_status_provisional' ), true ) ) {
				continue;
			}
			$reasons[] = $map[ $reason ] ?? $reason;
		}

		return array_values( array_unique( $reasons ) );
	}

	private function bucket_for_item( array $item ): string {
		$data = $item['data_blocker_codes'];
		$without_source = array_values( array_diff( $data, array( 'missing_tapology_mapping', 'missing_gender' ) ) );
		sort( $without_source );

		if ( empty( $item['rankable_blocker_codes'] ) ) {
			return 'A';
		}
		if ( empty( array_diff( $item['rankable_blocker_codes'], $item['state_blocker_codes'] ) ) ) {
			return 'A';
		}
		if ( array( 'missing_dob_or_birth_year' ) === $without_source ) {
			return 'B';
		}
		if ( array( 'missing_weight_class' ) === $without_source ) {
			return 'C';
		}
		if ( array( 'missing_dob_or_birth_year', 'missing_weight_class' ) === $without_source ) {
			return 'D';
		}
		if ( array_intersect( $data, array( 'missing_stats_row', 'missing_last_fight', 'no_countable_bout_history' ) ) ) {
			return 'E';
		}

		return 'F';
	}

	private function readiness_score( array $item ): int {
		$score = 100;
		$score -= count( $item['data_blocker_codes'] ) * 12;
		$score -= count( $item['formula_blocker_codes'] ) * 10;
		$score -= count( $item['state_blocker_codes'] ) * 3;

		return max( 0, $score );
	}

	private function load_rows( array $fighter_ids = array() ): array {
		global $wpdb;

		$where = '';
		if ( ! empty( $fighter_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $fighter_ids ), '%d' ) );
			$where = $wpdb->prepare( "WHERE f.id IN ({$placeholders})", $fighter_ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		return $wpdb->get_results(
			"
			SELECT
				f.*,
				s.id AS stats_row_id,
				s.wins,
				s.losses,
				s.draws,
				s.nc,
				s.pro_fights_count,
				s.finish_rate,
				s.last_fight_date,
				s.calculated_at AS stats_calculated_at,
				ts.id AS tapology_source_row_id,
				ts.source_url AS tapology_source_url,
				ts.is_verified AS tapology_source_verified,
				CASE WHEN COALESCE(ts.valid_tapology_source_count, 0) > 0 THEN 1 ELSE 0 END AS has_valid_tapology_source,
				COALESCE(ts.tapology_source_count, 0) AS tapology_source_count,
				COALESCE(ts.malformed_tapology_source_count, 0) AS malformed_tapology_source_count
			FROM {$this->fighters_table} f
			LEFT JOIN {$this->stats_table} s ON s.fighter_id = f.id
			LEFT JOIN (
				SELECT
					fighter_id,
					MIN(id) AS id,
					MAX(source_url) AS source_url,
					MAX(is_verified) AS is_verified,
					COUNT(*) AS tapology_source_count,
					SUM(CASE WHEN source_url IS NOT NULL AND source_url <> '' AND identity_hash IS NOT NULL AND identity_hash <> '' THEN 1 ELSE 0 END) AS valid_tapology_source_count,
					SUM(CASE WHEN source_url IS NULL OR source_url = '' OR identity_hash IS NULL OR identity_hash = '' THEN 1 ELSE 0 END) AS malformed_tapology_source_count
				FROM {$this->sources_table}
				WHERE source_type = 'tapology' AND fighter_id IS NOT NULL
				GROUP BY fighter_id
			) ts ON ts.fighter_id = f.id
			{$where}
			ORDER BY f.updated_at DESC, f.created_at DESC, f.id DESC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	private function load_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->fighters_table} WHERE id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	private function fighter_from_row( array $row ): array {
		$fighter = $row;
		foreach ( array( 'stats_row_id', 'wins', 'losses', 'draws', 'nc', 'pro_fights_count', 'finish_rate', 'last_fight_date', 'stats_calculated_at', 'tapology_source_row_id', 'tapology_source_url', 'tapology_source_verified' ) as $field ) {
			unset( $fighter[ $field ] );
		}

		return $fighter;
	}

	private function stats_from_row( array $row ): ?array {
		if ( empty( $row['stats_row_id'] ) ) {
			return null;
		}

		return array(
			'id'               => (int) $row['stats_row_id'],
			'wins'             => (int) ( $row['wins'] ?? 0 ),
			'losses'           => (int) ( $row['losses'] ?? 0 ),
			'draws'            => (int) ( $row['draws'] ?? 0 ),
			'nc'               => (int) ( $row['nc'] ?? 0 ),
			'pro_fights_count' => (int) ( $row['pro_fights_count'] ?? 0 ),
			'finish_rate'      => null === $row['finish_rate'] ? null : (float) $row['finish_rate'],
			'last_fight_date'  => $row['last_fight_date'] ?? null,
			'calculated_at'    => $row['stats_calculated_at'] ?? null,
		);
	}

	private function normalize_ids( array $fighter_ids ): array {
		$ids = array();
		foreach ( $fighter_ids as $fighter_id ) {
			$fighter_id = absint( $fighter_id );
			if ( $fighter_id > 0 ) {
				$ids[] = $fighter_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function empty_counts(): array {
		return array(
			'total_fighters' => 0,
			'verified_fighters' => 0,
			'provisional_fighters' => 0,
			'public_fighters' => 0,
			'rankable_fighters' => 0,
			'tapology_mapped_fighters' => 0,
			'fighters_without_tapology_mapping' => 0,
			'fighters_with_stats' => 0,
			'fighters_missing_dob_or_birth_year' => 0,
			'fighters_missing_weight_class' => 0,
			'fighters_missing_last_fight' => 0,
			'fighters_blocked_by_insufficient_data' => 0,
			'fighters_blocked_by_public_rankable_review_state' => 0,
		);
	}

	private function empty_buckets(): array {
		return array(
			'A' => array( 'label' => __( 'Ready except review/public/rankable state', 'mma-future-data-engine' ), 'count' => 0 ),
			'B' => array( 'label' => __( 'Missing only DOB/birth year', 'mma-future-data-engine' ), 'count' => 0 ),
			'C' => array( 'label' => __( 'Missing only weight class', 'mma-future-data-engine' ), 'count' => 0 ),
			'D' => array( 'label' => __( 'Missing DOB/birth year + weight class', 'mma-future-data-engine' ), 'count' => 0 ),
			'E' => array( 'label' => __( 'Missing last fight or stats issue', 'mma-future-data-engine' ), 'count' => 0 ),
			'F' => array( 'label' => __( 'Should remain provisional / insufficient data', 'mma-future-data-engine' ), 'count' => 0 ),
		);
	}

	private function summary_for_codes( array $codes ): string {
		if ( empty( $codes ) ) {
			return __( 'Ready', 'mma-future-data-engine' );
		}

		return implode( ', ', array_map( array( $this, 'reason_label' ), $codes ) );
	}

	public function reason_label( string $code ): string {
		$labels = array(
			'missing_tapology_mapping' => __( 'missing Tapology mapping', 'mma-future-data-engine' ),
			'missing_dob_or_birth_year' => __( 'missing DOB/birth year', 'mma-future-data-engine' ),
			'missing_gender' => __( 'missing gender', 'mma-future-data-engine' ),
			'missing_weight_class' => __( 'missing weight class', 'mma-future-data-engine' ),
			'missing_stats_row' => __( 'missing stats row', 'mma-future-data-engine' ),
			'missing_last_fight' => __( 'missing last fight', 'mma-future-data-engine' ),
			'no_countable_bout_history' => __( 'no countable bout history', 'mma-future-data-engine' ),
			'not_verified' => __( 'not verified', 'mma-future-data-engine' ),
			'not_public' => __( 'not public', 'mma-future-data-engine' ),
			'not_rankable_flag' => __( 'rankable flag off', 'mma-future-data-engine' ),
			'rankability_status_not_rankable' => __( 'rankability status not rankable', 'mma-future-data-engine' ),
			'invalid_hidden_deleted_merged_or_retired_status' => __( 'hidden/deleted/merged/retired', 'mma-future-data-engine' ),
			'age_rule_fails' => __( 'age rule fails', 'mma-future-data-engine' ),
			'inactivity_rule_fails' => __( 'inactivity rule fails', 'mma-future-data-engine' ),
			'ufc_gate_fails' => __( 'UFC gate fails', 'mma-future-data-engine' ),
			'too_many_fights_rule_fails' => __( 'too many fights rule fails', 'mma-future-data-engine' ),
			'loss_limit_rule_fails' => __( 'loss limit rule fails', 'mma-future-data-engine' ),
			'weight_class_gender_mismatch' => __( 'weight class/gender mismatch', 'mma-future-data-engine' ),
			'missing_display_name' => __( 'missing display name', 'mma-future-data-engine' ),
			'already_verified' => __( 'already verified', 'mma-future-data-engine' ),
			'already_public' => __( 'already public', 'mma-future-data-engine' ),
			'already_not_public' => __( 'already not public', 'mma-future-data-engine' ),
			'already_rankable' => __( 'already rankable', 'mma-future-data-engine' ),
			'already_not_rankable' => __( 'already not rankable', 'mma-future-data-engine' ),
			'already_provisional_pending_review' => __( 'already provisional/pending review', 'mma-future-data-engine' ),
			'ready_for_rankable_promotion' => __( 'ready for rankable promotion', 'mma-future-data-engine' ),
		);

		return $labels[ $code ] ?? str_replace( '_', ' ', $code );
	}

	private function append_candidate( array &$candidates, array $item, int $limit ): void {
		if ( count( $candidates ) >= $limit ) {
			return;
		}

		$candidates[] = $this->candidate_row( $item );
	}

	private function candidate_row( array $item ): array {
		$fighter = $item['fighter'];
		$stats = is_array( $item['stats'] ) ? $item['stats'] : array();

		return array(
			'id' => (int) ( $fighter['id'] ?? 0 ),
			'name' => (string) ( $fighter['display_name'] ?? '' ),
			'status' => (string) ( $fighter['status'] ?? '' ),
			'rankability_status' => (string) ( $fighter['rankability_status'] ?? '' ),
			'is_public' => (int) ( $fighter['is_public'] ?? 0 ),
			'is_rankable' => (int) ( $fighter['is_rankable'] ?? 0 ),
			'gender' => (string) ( $fighter['gender'] ?? '' ),
			'weight_class' => (string) ( $fighter['weight_class'] ?? '' ),
			'pro_fights_count' => (int) ( $stats['pro_fights_count'] ?? 0 ),
			'last_fight_date' => $stats['last_fight_date'] ?? null,
			'readiness_bucket' => (string) $item['readiness_bucket'],
			'readiness_score' => (int) $item['readiness_score'],
			'public_blockers' => array_values( (array) $item['public_blocker_codes'] ),
			'rankable_blockers' => array_values( (array) $item['rankable_blocker_codes'] ),
		);
	}

	private function add_reason_counts( array &$result, array $reasons ): void {
		foreach ( $reasons as $reason ) {
			if ( ! isset( $result['reason_counts'][ $reason ] ) ) {
				$result['reason_counts'][ $reason ] = 0;
			}
			++$result['reason_counts'][ $reason ];
		}
	}

	private function result_row( array $item, array $reasons ): array {
		return array(
			'id'      => (int) $item['fighter']['id'],
			'name'    => (string) $item['fighter']['display_name'],
			'reasons' => $reasons,
		);
	}

	private function audit_state( array $fighter ): array {
		return array(
			'id'                 => isset( $fighter['id'] ) ? (int) $fighter['id'] : null,
			'display_name'       => $fighter['display_name'] ?? null,
			'status'             => $fighter['status'] ?? null,
			'rankability_status' => $fighter['rankability_status'] ?? null,
			'is_public'          => isset( $fighter['is_public'] ) ? (int) $fighter['is_public'] : null,
			'is_rankable'        => isset( $fighter['is_rankable'] ) ? (int) $fighter['is_rankable'] : null,
			'deleted_soft'       => isset( $fighter['deleted_soft'] ) ? (int) $fighter['deleted_soft'] : null,
		);
	}

	private function blocked( array $reasons ): array {
		return array( 'status' => 'blocked', 'reasons' => array_values( array_unique( $reasons ) ) );
	}

	private function skipped( array $reasons ): array {
		return array( 'status' => 'skipped', 'reasons' => array_values( array_unique( $reasons ) ) );
	}

	private function ready( array $reasons ): array {
		return array( 'status' => 'ready', 'reasons' => array_values( array_unique( $reasons ) ) );
	}

	private function update( array $update ): array {
		return array( 'status' => 'update', 'reasons' => array(), 'update' => $update );
	}

	private function is_weight_class_compatible( string $gender, string $weight_class ): bool {
		$male = array(
			'flyweight',
			'bantamweight',
			'featherweight',
			'lightweight',
			'welterweight',
			'middleweight',
			'light_heavyweight',
			'heavyweight',
		);
		$female = array(
			'women_strawweight',
			'women_flyweight',
			'women_bantamweight',
			'women_featherweight',
		);

		if ( 'male' === $gender ) {
			return in_array( $weight_class, $male, true );
		}

		if ( 'female' === $gender ) {
			return in_array( $weight_class, $female, true );
		}

		return false;
	}
}
