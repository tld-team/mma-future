<?php
namespace MMAF\DataEngine\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DateTime {
	public static function mysql_now(): string {
		return current_time( 'mysql', true );
	}
}
