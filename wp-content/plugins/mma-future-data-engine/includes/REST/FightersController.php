<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\CPT\FighterPostType;
use MMAF\DataEngine\Repositories\RestReadRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FightersController extends AbstractRestController {
	private RestReadRepository $repository;

	public function __construct() {
		$this->repository = new RestReadRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/fighters/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/fighters/slug/(?P<slug>[A-Za-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item_by_slug' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/fighters/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function get_item( \WP_REST_Request $request ) {
		$fighter_id = absint( $request->get_param( 'id' ) );
		$fighter    = $this->repository->fighter( $fighter_id );

		if ( ! $fighter || 1 === (int) $fighter['deleted_soft'] ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		if ( 1 !== (int) $fighter['is_public'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		$stats         = $this->repository->fighter_stats( $fighter_id );
		$rankings      = $this->repository->fighter_current_rankings( $fighter_id );
		$recent_fights = $this->repository->recent_fights( $fighter_id, 10 );

		return rest_ensure_response(
			$this->fighter_response_payload( $fighter, $stats, $rankings, $recent_fights )
		);
	}

	public function get_item_by_slug( \WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		$post = get_page_by_path( $slug, OBJECT, FighterPostType::POST_TYPE );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		$fighter = $this->repository->fighter_by_wp_post_id( (int) $post->ID );
		if ( ! $fighter || 1 === (int) $fighter['deleted_soft'] ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		if ( 1 !== (int) $fighter['is_public'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'mmaf_fighter_not_found', __( 'Fighter not found.', 'mma-future-data-engine' ), array( 'status' => 404 ) );
		}

		$fighter_id    = (int) $fighter['id'];
		$stats         = $this->repository->fighter_stats( $fighter_id );
		$rankings      = $this->repository->fighter_current_rankings( $fighter_id );
		$recent_fights = $this->repository->recent_fights( $fighter_id, 10 );

		return rest_ensure_response( $this->fighter_response_payload( $fighter, $stats, $rankings, $recent_fights ) );
	}

	public function search( \WP_REST_Request $request ) {
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new \WP_Error( 'mmaf_search_query_too_short', __( 'Search query must be at least 2 characters.', 'mma-future-data-engine' ), array( 'status' => 400 ) );
		}

		$limit              = max( 1, min( 50, absint( $request->get_param( 'limit' ) ?: 10 ) ) );
		$include_non_public = $this->bool_value( $request->get_param( 'include_non_public' ) ) && current_user_can( 'manage_options' );
		$rows               = $this->repository->search_fighters( $query, $limit, $include_non_public );
		$items              = array();

		foreach ( $rows as $row ) {
			$profile = $this->profile_data( $row['wp_post_id'] ?? null );
			$items[] = array(
				'fighter_id'   => (int) $row['id'],
				'display_name' => (string) $row['display_name'],
				'nickname'     => $row['nickname'],
				'slug'         => $profile['slug'],
				'permalink'    => $profile['permalink'],
				'image'        => $profile['image'],
				'weight_class' => $row['weight_class'],
				'nationality'  => $row['nationality'],
				'is_public'    => $this->output_bool( $row['is_public'] ),
				'is_rankable'  => $this->output_bool( $row['is_rankable'] ),
			);
		}

		return rest_ensure_response(
			array(
				'query' => $query,
				'total' => count( $items ),
				'items' => $items,
			)
		);
	}

	private function fighter_response_payload( array $fighter, ?array $stats, array $rankings, array $recent_fights ): array {
		return array(
			'fighter'       => $this->fighter_payload( $fighter ),
			'profile'       => $this->profile_data( $fighter['wp_post_id'] ?? null ),
			'stats'         => $this->stats_payload( $stats ),
			'rankings'      => $this->rankings_payload( $rankings ),
			'recent_fights' => $this->recent_fights_payload( $recent_fights ),
		);
	}

	private function fighter_payload( array $fighter ): array {
		return array(
			'id'                 => (int) $fighter['id'],
			'display_name'       => (string) $fighter['display_name'],
			'nickname'           => $fighter['nickname'],
			'gender'             => $fighter['gender'],
			'date_of_birth'      => $fighter['date_of_birth'],
			'birth_year'         => null === $fighter['birth_year'] ? null : (int) $fighter['birth_year'],
			'age'                => $this->age( $fighter['date_of_birth'] ?? null, $fighter['birth_year'] ?? null ),
			'nationality'        => $fighter['nationality'],
			'weight_class'       => $fighter['weight_class'],
			'status'             => $fighter['status'],
			'rankability_status' => $fighter['rankability_status'],
			'is_public'          => $this->output_bool( $fighter['is_public'] ),
			'is_rankable'        => $this->output_bool( $fighter['is_rankable'] ),
			'in_ufc'             => $this->output_bool( $fighter['in_ufc'] ),
		);
	}

	private function stats_payload( ?array $stats ): ?array {
		if ( null === $stats ) {
			return null;
		}

		return array(
			'wins'             => (int) $stats['wins'],
			'losses'           => (int) $stats['losses'],
			'draws'            => (int) $stats['draws'],
			'nc'               => (int) $stats['nc'],
			'pro_fights_count' => (int) $stats['pro_fights_count'],
			'ko_tko_wins'      => (int) $stats['ko_tko_wins'],
			'submission_wins'  => (int) $stats['submission_wins'],
			'decision_wins'    => (int) $stats['decision_wins'],
			'finish_wins'      => (int) $stats['finish_wins'],
			'finish_rate'      => null === $stats['finish_rate'] ? null : (float) $stats['finish_rate'],
			'last_fight_date'  => $stats['last_fight_date'],
			'streak'           => $stats['streak'],
			'recent_form'      => $stats['recent_form'],
			'activity_status'  => $stats['activity_status'],
		);
	}

	private function rankings_payload( array $rankings ): array {
		$items = array();
		foreach ( $rankings as $ranking ) {
			$items[] = array(
				'board'                 => (string) $ranking['board_key'],
				'rank'                  => (int) $ranking['rank_position'],
				'score'                 => (float) $ranking['total_score'],
				'active_ranking_run_id' => (int) $ranking['ranking_run_id'],
			);
		}

		return $items;
	}

	private function recent_fights_payload( array $recent_fights ): array {
		$items = array();

		foreach ( $recent_fights as $fight ) {
			$items[] = array(
				'bout_id'         => (int) $fight['bout_id'],
				'event_id'        => (int) $fight['event_id'],
				'event_name'      => $fight['event_name'],
				'event_date'      => $fight['event_date'],
				'opponent'        => array(
					'fighter_id'   => null === $fight['opponent_fighter_id'] ? null : (int) $fight['opponent_fighter_id'],
					'display_name' => $fight['opponent_display_name'],
				),
				'result'          => $fight['result_for_fighter'],
				'method_category' => $fight['method_category'],
				'method_detail'   => $fight['method_detail'],
				'round'           => null === $fight['round_number'] ? null : (int) $fight['round_number'],
				'time'            => $fight['time_in_round'],
				'weight_class'    => $fight['weight_class'],
			);
		}

		return $items;
	}
}
