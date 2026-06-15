<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {
	public static function register(): void {
		add_menu_page(
			__( 'MMA Future', 'mma-future-data-engine' ),
			__( 'MMA Future', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf',
			array( HealthPage::class, 'render' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Health', 'mma-future-data-engine' ),
			__( 'Health', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-health',
			array( HealthPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Fighters', 'mma-future-data-engine' ),
			__( 'Fighters', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-fighters',
			array( FightersPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Events', 'mma-future-data-engine' ),
			__( 'Events', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-events',
			array( EventsPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Bouts', 'mma-future-data-engine' ),
			__( 'Bouts', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-bouts',
			array( BoutsPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Stats', 'mma-future-data-engine' ),
			__( 'Stats', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-stats',
			array( StatsPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Rankings', 'mma-future-data-engine' ),
			__( 'Rankings', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-rankings',
			array( RankingsPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Import', 'mma-future-data-engine' ),
			__( 'Import', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-import',
			array( ImportPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Data Audit', 'mma-future-data-engine' ),
			__( 'Data Audit', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-data-audit',
			array( DataAuditPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future Review', 'mma-future-data-engine' ),
			__( 'Review', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-review',
			array( ReviewPage::class, 'render' )
		);

		add_submenu_page(
			'mmaf',
			__( 'MMA Future System Check', 'mma-future-data-engine' ),
			__( 'System Check', 'mma-future-data-engine' ),
			Capabilities::MANAGE,
			'mmaf-system-check',
			array( SystemCheckPage::class, 'render' )
		);
	}
}
