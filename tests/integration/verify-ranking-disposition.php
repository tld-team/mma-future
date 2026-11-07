<?php
/** Synthetic checks for the shared ranking-disposition gate. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/synthetic-fixtures.php';

use MMAF\DataEngine\Migrations\MigrationRunner;
use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\DataPipeline\ProfileStore;
use MMAF\DataEngine\Services\DataPipeline\Schema as PipelineSchema;
use MMAF\DataEngine\Services\RankingDispositionService;
use MMAF\DataEngine\Services\RegularImport\{Transaction,WriteGuard};

function disposition_expect( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
}

function disposition_profile( array $fighter, int $wins, int $losses ): void {
	$profile = fixture_profile( $fighter, array(), array( 'wins' => $wins, 'losses' => $losses, 'draws' => 0, 'no_contests' => 0 ) );
	( new ProfileStore() )->put( $profile, 'tapology_fighter_profiles_v0_1' );
}

set_exception_handler( static function( Throwable $error ): void { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); } );
MigrationRunner::run();
wp_set_current_user( 1 );
global $wpdb;
$tables = Schema::table_names();
$queue = PipelineSchema::tables()['queue'];
Transaction::begin();
WriteGuard::$worker = true;
try {
	$service = new RankingDispositionService();
	$repo = new FighterRepository();
	$reference = '2026-11-08';

	$benson = fixture_fighter( 948880001, 'Disposition Benson', array( 'date_of_birth' => '1998-01-01' ) );
	disposition_profile( $benson, 8, 11 );
	$wpdb->insert( $queue, array( 'fighter_id' => $benson['fighter_id'], 'status' => 'review', 'import_job_id' => 0, 'actor_user_id' => 1, 'attempts' => 0, 'requested_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$decision = $service->classify( $repo->find( $benson['fighter_id'] ), $reference );
	disposition_expect( RankingDispositionService::TEMPORARY === $decision['outcome'] && in_array( 'loss_limit', $decision['reasons'], true ), '8–11 is temporarily outside ranking for the loss limit' );
	$service->apply( $benson['fighter_id'], 1, $reference, false );
	$stored = $repo->find( $benson['fighter_id'] );
	disposition_expect( 'paused' === $stored['tracking_mode'] && 0 === (int) $stored['is_rankable'] && 'ineligible_loss_limit' === $stored['rankability_status'], 'loss-limit decision removes ranking permission without hiding the fighter' );
	disposition_expect( 'excluded' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue WHERE fighter_id=%d", $benson['fighter_id'] ) ), 'existing ranking preparation is closed as excluded' );
	$service->apply( $benson['fighter_id'], 1, $reference, false );
	disposition_expect( 'excluded' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $queue WHERE fighter_id=%d", $benson['fighter_id'] ) ), 'repeating the exclusion does not reopen review' );

	$marte = fixture_fighter( 948880002, 'Disposition Marte', array( 'date_of_birth' => '1987-01-01' ) );
	$age = $service->classify( $repo->find( $marte['fighter_id'] ), $reference );
	disposition_expect( RankingDispositionService::PERMANENT === $age['outcome'] && in_array( 'age_over_34', $age['reasons'], true ), 'a confirmed 39-year-old is permanently outside ranking' );
	$thirty_four = fixture_fighter( 948880003, 'Disposition Age 34', array( 'date_of_birth' => '1991-11-09' ) );
	$age34 = $service->classify( $repo->find( $thirty_four['fighter_id'] ), $reference );
	disposition_expect( ! in_array( 'age_over_34', $age34['reasons'], true ), 'a fighter still aged 34 is not excluded by age' );
	$birth_year_only = fixture_fighter( 948880004, 'Disposition Year Only', array( 'date_of_birth' => null, 'birth_year' => 1991 ) );
	$year_only = $service->classify( $repo->find( $birth_year_only['fighter_id'] ), $reference );
	disposition_expect( ! in_array( 'age_over_34', $year_only['reasons'], true ), 'a borderline birth year does not cause a premature permanent exclusion' );

	$thirty = fixture_fighter( 948880005, 'Disposition Thirty Bouts', array( 'date_of_birth' => '1999-01-01' ) );
	disposition_profile( $thirty, 30, 0 );
	$thirty_decision = $service->classify( $repo->find( $thirty['fighter_id'] ), $reference );
	disposition_expect( ! in_array( 'confirmed_pro_fights_over_30', $thirty_decision['reasons'], true ), 'exactly 30 professional bouts is not a permanent exclusion' );
	$thirty_one = fixture_fighter( 948880006, 'Disposition Thirty One Bouts', array( 'date_of_birth' => '1999-01-01' ) );
	disposition_profile( $thirty_one, 31, 0 );
	$over_limit = $service->classify( $repo->find( $thirty_one['fighter_id'] ), $reference );
	disposition_expect( RankingDispositionService::PERMANENT === $over_limit['outcome'] && in_array( 'confirmed_pro_fights_over_30', $over_limit['reasons'], true ), '31 professional bouts is permanently outside ranking' );
	$early = fixture_fighter( 948880007, 'Disposition Early Career', array( 'date_of_birth' => '1999-01-01' ) );
	disposition_profile( $early, 4, 99 );
	$early_decision = $service->classify( $repo->find( $early['fighter_id'] ), $reference );
	disposition_expect( ! in_array( 'loss_limit', $early_decision['reasons'], true ), '0–4 wins has no loss-limit exclusion' );
	$ufc = fixture_fighter( 948880008, 'Disposition UFC', array( 'in_ufc' => 1, 'date_of_birth' => '1999-01-01' ) );
	$ufc_decision = $service->classify( $repo->find( $ufc['fighter_id'] ), $reference );
	disposition_expect( RankingDispositionService::PERMANENT === $ufc_decision['outcome'] && in_array( 'confirmed_ufc_signing', $ufc_decision['reasons'], true ), 'a confirmed UFC signing is permanently outside ranking' );
} finally {
	Transaction::rollback();
	WriteGuard::$worker = false;
}
echo "Ranking-disposition integration checks completed.\n";
