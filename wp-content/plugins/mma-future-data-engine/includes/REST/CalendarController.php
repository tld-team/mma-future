<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Services\Upcoming\CalendarService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CalendarController {
	public function register_routes(): void {
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/calendar',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'month' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
				'args' => array(
					'month' => array( 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^\d{4}-\d{2}$/', (string) $value ) ),
					'timezone' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
		register_rest_route(
			RestServiceProvider::NAMESPACE,
			'/calendar/day',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'day' ),
				'permission_callback' => array( RestServiceProvider::class, 'public_permission' ),
				'args' => array(
					'date' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ),
					'timezone' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page' => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function month( \WP_REST_Request $request ) {
		try {
			$timezone = CalendarService::valid_timezone( $request->get_param( 'timezone' ) );
			$month = $request->get_param( 'month' ) ?: ( new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) ) )->format( 'Y-m' );
			return rest_ensure_response( ( new CalendarService() )->month( $month, $timezone ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'mmaf_calendar_invalid_request', $error->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function day( \WP_REST_Request $request ) {
		try {
			return rest_ensure_response(
				( new CalendarService() )->day(
					(string) $request->get_param( 'date' ),
					CalendarService::valid_timezone( $request->get_param( 'timezone' ) ),
					(int) $request->get_param( 'page' ),
					(int) $request->get_param( 'per_page' )
				)
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'mmaf_calendar_invalid_request', $error->getMessage(), array( 'status' => 400 ) );
		}
	}
}
