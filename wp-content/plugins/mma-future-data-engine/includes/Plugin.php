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
		// Schema changes run explicitly under deployment write coordination.
		// In particular, loading an older compatible release must never downgrade it.
		if ( version_compare((string) get_option('mmaf_db_version', '0'), MMAF_DB_VERSION, '<') ) {
			add_action('admin_notices', static function() {
				if (current_user_can(\MMAF\DataEngine\Support\Capabilities::MANAGE)) {
					echo '<div class="notice notice-error"><p>MMA Future database migration is required. Run the verified release migration before starting data jobs.</p></div>';
				}
			});
		}
		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('mmaf system migrate', static function() {
				MigrationRunner::run();
				\WP_CLI::success('Verified database schema ' . get_option('mmaf_db_version'));
			});
		}

		add_action( 'init', array( FighterPostType::class, 'register' ) );
		RestServiceProvider::register();
		Services\PublicVisibilityService::register();
		Services\PublicCacheService::register();
		Services\RegularImport\Module::register();
		Services\DataPipeline\Processor::register();
        Services\Scoring\Admin::register();
        Services\Scoring\Command::register();
        Services\Operations\Module::register();

		if ( is_admin() ) {
			add_action( 'admin_init', array( \MMAF\DataEngine\Admin\FightersPage::class, 'handle_bulk_request' ) );
			add_action( 'admin_init', array( \MMAF\DataEngine\Admin\RankingsPage::class, 'handle_calculation_request' ) );
			add_action( 'admin_menu', array( AdminMenu::class, 'register' ) );
			add_action( 'admin_post_mmaf_rebuild_stats', array( \MMAF\DataEngine\Admin\StatsPage::class, 'handle_admin_post' ) );
			add_action( 'admin_post_mmaf_system_snapshot_export', array( \MMAF\DataEngine\Admin\ImportPage::class, 'handle_snapshot_export' ) );
			add_action( 'admin_enqueue_scripts', array( \MMAF\DataEngine\Admin\FightersPage::class, 'enqueue_assets' ) );
			add_action( 'admin_enqueue_scripts', array( \MMAF\DataEngine\Admin\RankingsPage::class, 'enqueue_assets' ) );
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
