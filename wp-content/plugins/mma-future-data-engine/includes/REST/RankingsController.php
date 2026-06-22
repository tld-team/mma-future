<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Repositories\RestReadRepository;
use MMAF\DataEngine\Services\Formula\FormulaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankingsController extends AbstractRestController {
	private RestReadRepository $repository;

	public function __construct() {
		$this->repository = new RestReadRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/rankings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
				'args'                => array(
					'board' => array(
						'default'           => 'overall',
						'sanitize_callback' => 'sanitize_key',
					),
					'page' => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
					'include_breakdown' => array(
						'default' => false,
					),
				),
			)
		);
	}

	public function get_items( \WP_REST_Request $request ) {
		$board             = sanitize_key( (string) $request->get_param( 'board' ) );
		$page              = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page          = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ) ) );
		$include_breakdown = $this->bool_value( $request->get_param( 'include_breakdown' ) );
		$active_run        = $this->repository->active_ranking_run();
		$ranking_state     = $this->repository->ranking_state_summary();

		if ( ! $active_run ) {
			return rest_ensure_response(
				array(
					'board'                 => $board,
					'active_ranking_run_id' => null,
					'latest_completed_ranking_run_id' => $ranking_state['latest_completed_run_id'],
					'has_newer_completed_draft' => $ranking_state['has_newer_completed_draft'],
					'active_calculated_at'  => null,
					'latest_completed_calculated_at' => $ranking_state['latest_completed_calculated_at'],
					'formula_version'       => null,
					'generated_at'          => null,
					'page'                  => $page,
					'per_page'              => $per_page,
					'total'                 => 0,
					'total_pages'           => 0,
					'items'                 => array(),
					'message'               => __( 'No active ranking run is available.', 'mma-future-data-engine' ),
				)
			);
		}

		$result = $this->repository->ranking_rows( $board, $page, $per_page, current_user_can( 'manage_options' ) );
		$items  = array();

		foreach ( $result['rows'] as $row ) {
			$profile = $this->profile_data( $row['wp_post_id'] ?? null );
			$formula_version = $this->ranking_formula_version( $row, (string) $active_run['formula_version'] );
			$item    = array(
				'rank'         => (int) $row['rank_position'],
				'fighter_id'   => (int) $row['fighter_id'],
				'display_name' => (string) $row['display_name'],
				'nickname'     => $row['nickname'],
				'slug'         => $profile['slug'],
				'permalink'    => $profile['permalink'],
				'image'        => $profile['image'],
				'gender'       => $row['gender'],
				'weight_class' => $row['weight_class'],
				'nationality'  => $row['nationality'],
				'age'          => $this->age( $row['date_of_birth'] ?? null, $row['birth_year'] ?? null ),
				'record'       => array(
					'wins'             => (int) ( $row['wins'] ?? 0 ),
					'losses'           => (int) ( $row['losses'] ?? 0 ),
					'draws'            => (int) ( $row['draws'] ?? 0 ),
					'nc'               => (int) ( $row['nc'] ?? 0 ),
					'pro_fights_count' => (int) ( $row['pro_fights_count'] ?? 0 ),
				),
				'stats'        => array(
					'finish_wins'     => (int) ( $row['finish_wins'] ?? 0 ),
					'finish_rate'     => null === $row['finish_rate'] ? null : (float) $row['finish_rate'],
					'last_fight_date' => $row['last_fight_date'],
					'streak'          => $row['streak'],
					'recent_form'     => $row['recent_form'],
					'activity_status' => $row['activity_status'],
				),
				'score'          => $this->ranking_public_score( $row, $formula_version ),
				'raw_score'      => $this->ranking_raw_public_score( $row ),
				'sample_size'    => (int) ( $row['sample_size'] ?? 0 ),
				'warnings_count' => $this->warnings_count( $row['warnings_json'] ),
			);

			if ( FormulaRegistry::uses_normalized_scores( $formula_version ) ) {
				$item['performance_raw_score'] = $this->ranking_performance_raw_public_score( $row, $formula_version );
				$item['confidence_score'] = $this->ranking_confidence_public_score( $row, $formula_version );
			}

			if ( $include_breakdown ) {
				$item['breakdown']      = $this->json_value( $row['breakdown_json'] );
				$item['eligibility']    = $this->json_value( $row['eligibility_json'] );
				$item['warnings']       = $this->json_value( $row['warnings_json'] );
				$item['source_summary'] = $this->json_value( $row['source_summary_json'] );
				$item['quality_flags']  = $this->json_value( $row['quality_flags_json'] ?? '' );
			}

			$items[] = $item;
		}

		return rest_ensure_response(
			array(
				'board'                 => $board,
				'active_ranking_run_id' => (int) $active_run['id'],
				'latest_completed_ranking_run_id' => $ranking_state['latest_completed_run_id'],
				'has_newer_completed_draft' => $ranking_state['has_newer_completed_draft'],
				'active_calculated_at'  => $ranking_state['active_calculated_at'],
				'latest_completed_calculated_at' => $ranking_state['latest_completed_calculated_at'],
				'formula_version'       => (string) $active_run['formula_version'],
				'generated_at'          => (string) $active_run['calculated_at'],
				'page'                  => $page,
				'per_page'              => $per_page,
				'total'                 => (int) $result['total'],
				'total_pages'           => (int) ceil( (int) $result['total'] / $per_page ),
				'items'                 => $items,
			)
		);
	}
}
