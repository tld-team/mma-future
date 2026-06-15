<?php
namespace MMAF\DataEngine;

use MMAF\DataEngine\CPT\FighterPostType;
use MMAF\DataEngine\Migrations\MigrationRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {
	public static function activate(): void {
		FighterPostType::register();
		MigrationRunner::run();
		flush_rewrite_rules();
	}
}
