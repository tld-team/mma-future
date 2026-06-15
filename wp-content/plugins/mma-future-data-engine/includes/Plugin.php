<?php
namespace MMAF\DataEngine;

use MMAF\DataEngine\Admin\AdminMenu;
use MMAF\DataEngine\CLI\FighterCommand;
use MMAF\DataEngine\CLI\HealthCommand;
use MMAF\DataEngine\CLI\ImportDryRunCommand;
use MMAF\DataEngine\CLI\ImportJsonCommand;
use MMAF\DataEngine\CLI\ImportLatestBundleCommand;
use MMAF\DataEngine\CLI\ImportRunCommand;
use MMAF\DataEngine\CLI\RankingsCommand;
use MMAF\DataEngine\CLI\StatsCommand;
use MMAF\DataEngine\CPT\FighterPostType;
use MMAF\DataEngine\Migrations\MigrationRunner;
use MMAF\DataEngine\REST\RestServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function register(): void {
		if ( get_option( 'mmaf_db_version' ) !== MMAF_DB_VERSION ) {
			MigrationRunner::run();
		}

		add_action( 'init', array( FighterPostType::class, 'register' ) );
		RestServiceProvider::register();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( AdminMenu::class, 'register' ) );
			add_action( 'admin_post_mmaf_rebuild_stats', array( \MMAF\DataEngine\Admin\StatsPage::class, 'handle_admin_post' ) );
			add_action( 'admin_post_mmaf_system_snapshot_export', array( \MMAF\DataEngine\Admin\ImportPage::class, 'handle_snapshot_export' ) );
			add_action( 'admin_enqueue_scripts', array( \MMAF\DataEngine\Admin\FightersPage::class, 'enqueue_assets' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			HealthCommand::register();
			StatsCommand::register();
			RankingsCommand::register();
			ImportDryRunCommand::register();
			ImportRunCommand::register();
			ImportJsonCommand::register();
			ImportLatestBundleCommand::register();
			FighterCommand::register();
		}
	}
}
