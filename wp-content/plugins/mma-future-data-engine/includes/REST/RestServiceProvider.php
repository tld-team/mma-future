<?php
namespace MMAF\DataEngine\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestServiceProvider {
	public const NAMESPACE = 'mma-future/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		( new RankingsController() )->register_routes();
		( new FightersController() )->register_routes();
		( new HealthController() )->register_routes();
	}

	public static function public_permission(): bool {
		return true;
	}
}
