<?php
/**
 * Plugin Name: MMA Future Data Engine
 * Description: Canonical sports data engine for MMA Future fighters, events, bouts, imports, stats, and rankings. Produced by TLD Team.
 * Version: 2.1.0
 * Author: Luka Mutic
 * Author URI: https://tldteam.com
 * Text Domain: mma-future-data-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMAF_PLUGIN_FILE', __FILE__ );
define( 'MMAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMAF_PLUGIN_VERSION', '2.0.0' );
define( 'MMAF_DB_VERSION', '1.4.3' );

require_once MMAF_PLUGIN_DIR . 'includes/Support/Capabilities.php';
require_once MMAF_PLUGIN_DIR . 'includes/Support/DateTime.php';
require_once MMAF_PLUGIN_DIR . 'includes/Support/Logger.php';
require_once MMAF_PLUGIN_DIR . 'includes/Support/Sanitizer.php';
require_once MMAF_PLUGIN_DIR . 'includes/Support/TapologyFighterUrl.php';
require_once MMAF_PLUGIN_DIR . 'includes/Migrations/Schema.php';
require_once MMAF_PLUGIN_DIR . 'includes/Migrations/MigrationRunner.php';
require_once MMAF_PLUGIN_DIR . 'includes/CPT/FighterPostType.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/FighterRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/FighterSourceRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/FighterAliasRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/EventRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/EventSourceRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/BoutRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/BoutSourceRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/BoutParticipantRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/FighterStatsRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/FighterStatsOverrideRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/RankingRunRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/RankingCurrentRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/RestReadRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/SourceImportRunRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Repositories/SourceImportItemRepository.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Formula/FormulaV12.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Formula/FormulaV13.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Formula/FormulaV14.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/FighterPostSyncService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/FieldProvenanceService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/AuditLogService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/SystemSnapshotExportService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/SystemSnapshotImportService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/FighterService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/EventService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/BoutService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/StatsRebuildService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/EligibilityService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/FighterReadinessService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/RankingCalculatorService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/RankingActivationService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/System/SystemCheckService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/FighterDuplicateAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/BoutIntegrityAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/DataQualityReportService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/FighterIdentityAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/FighterEnrichmentAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/FightHistoryCompletenessAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/FightHistoryStagingReportService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/ProfileRecordComparisonService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Audit/PostImportAuditService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/ScraperJsonValidator.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/EventImportPreviewService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/FighterIdentityPreviewService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/BoutImportPreviewService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/ScraperJsonDryRunService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/ScraperJsonImportService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/ScraperLatestBundleService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/GenderInferenceService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/FighterProfileEnrichmentPreviewService.php';
require_once MMAF_PLUGIN_DIR . 'includes/Services/Import/FighterProfileEnrichmentApplyService.php';
require_once MMAF_PLUGIN_DIR . 'includes/REST/RestServiceProvider.php';
require_once MMAF_PLUGIN_DIR . 'includes/REST/AbstractRestController.php';
require_once MMAF_PLUGIN_DIR . 'includes/REST/RankingsController.php';
require_once MMAF_PLUGIN_DIR . 'includes/REST/FightersController.php';
require_once MMAF_PLUGIN_DIR . 'includes/REST/HealthController.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/HealthPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/FightersPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/EventsPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/BoutsPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/StatsPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/RankingsPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/FighterProfileEnrichmentPreviewPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/ImportPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/DataAuditPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/ReviewPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/SystemCheckPage.php';
require_once MMAF_PLUGIN_DIR . 'includes/Admin/AdminMenu.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/HealthCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/StatsCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/RankingsCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/ImportDryRunCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/ImportRunCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/ImportJsonCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/ImportLatestBundleCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/CLI/FighterCommand.php';
require_once MMAF_PLUGIN_DIR . 'includes/Activator.php';
require_once MMAF_PLUGIN_DIR . 'includes/Deactivator.php';
require_once MMAF_PLUGIN_DIR . 'includes/Plugin.php';

function mmaf_activate_plugin() {
	\MMAF\DataEngine\Activator::activate();
}

function mmaf_deactivate_plugin() {
	\MMAF\DataEngine\Deactivator::deactivate();
}

function mmaf_bootstrap_plugin() {
	\MMAF\DataEngine\Plugin::instance()->register();
}

register_activation_hook( MMAF_PLUGIN_FILE, 'mmaf_activate_plugin' );
register_deactivation_hook( MMAF_PLUGIN_FILE, 'mmaf_deactivate_plugin' );

add_action( 'plugins_loaded', 'mmaf_bootstrap_plugin' );
