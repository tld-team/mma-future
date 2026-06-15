<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Repositories\RestReadRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthController extends AbstractRestController {
	private RestReadRepository $repository;

	public function __construct() {
		$this->repository = new RestReadRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
			)
		);
	}

	public function get_item( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->repository->public_health_summary() );
	}
}
