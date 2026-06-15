<?php
namespace MMAF\DataEngine\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {
	public static function error( string $message, array $context = array() ): void {
		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		error_log( '[MMAF Data Engine] ' . $message );
	}
}
