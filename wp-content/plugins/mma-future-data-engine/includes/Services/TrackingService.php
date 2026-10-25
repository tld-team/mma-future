<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\RegularImport\{Mutex,Repository,Transaction,WriteGuard};

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One canonical tracking decision shared by imports, preparation, scoring and admin. */
final class TrackingService {
	public const NORMAL = 'normal';
	public const PAUSED = 'paused';
	public const OPPONENT_ONLY = 'opponent_only';
	public const MODES = array( self::NORMAL, self::PAUSED, self::OPPONENT_ONLY );

	public static function mode( array $fighter ): string {
		$mode = (string) ( $fighter['tracking_mode'] ?? self::NORMAL );
		return in_array( $mode, self::MODES, true ) ? $mode : self::NORMAL;
	}

	public static function permits_regular_work( array $fighter ): bool {
		return self::NORMAL === self::mode( $fighter );
	}

	public function set_mode( array $fighter_ids, string $mode, string $reason, string $evidence_url = '', string $source = 'admin', int $actor = 0 ): array {
		WriteGuard::assertAllowed();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $fighter_ids ) ) ) );
		if ( ! $ids || ! in_array( $mode, self::MODES, true ) ) { throw new \InvalidArgumentException( 'Select fighters and a valid tracking mode.' ); }
		$reason = sanitize_key( $reason );
		if ( '' === $reason ) { throw new \InvalidArgumentException( 'A tracking decision reason is required.' ); }
		if ( $evidence_url && ! filter_var( $evidence_url, FILTER_VALIDATE_URL ) ) { throw new \InvalidArgumentException( 'Tracking evidence must be a valid URL.' ); }
		$source = sanitize_key( $source ) ?: 'admin';

		$result = Mutex::run( function() use ( $ids, $mode, $reason, $evidence_url, $source, $actor ) {
			return Transaction::run( function() use ( $ids, $mode, $reason, $evidence_url, $source, $actor ) {
				global $wpdb; $table = Schema::table_names()['fighters']; $now = gmdate( 'Y-m-d H:i:s' );
				$changed = array(); $unchanged = array();
				foreach ( $ids as $id ) {
					$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
					if ( ! $before || (int) $before['deleted_soft'] ) { $unchanged[] = $id; continue; }
					if ( ! empty($before['in_ufc']) && self::OPPONENT_ONLY !== $mode ) { throw new \RuntimeException( 'A confirmed UFC signing must be corrected before this fighter can leave opponent-only tracking.' ); }
					$values = array(
						'tracking_mode' => $mode, 'tracking_reason' => $reason, 'tracking_source' => $source,
						'tracking_evidence_url' => $evidence_url ? esc_url_raw( $evidence_url ) : null,
						'tracking_changed_at' => $now, 'tracking_revision' => (int) ( $before['tracking_revision'] ?? 0 ) + 1,
						'tracking_return_candidate_at' => null, 'updated_at' => $now,
					);
					if ( self::NORMAL !== $mode ) {
						$values['is_public'] = 0; $values['is_rankable'] = 0;
						$values['rankability_status'] = $this->rankability_status( $mode, $reason );
						if ( self::PAUSED === $mode ) {
							$values['tracking_last_checked_at']=$now;
							$values['tracking_last_checked_bout_id']=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(p.bout_id),0) FROM ".Schema::table_names()['bout_participants']." p JOIN ".Schema::table_names()['bouts']." b ON b.id=p.bout_id WHERE p.fighter_id=%d AND b.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest')",$id));
						}
					} else {
						// Restoring tracking creates a review candidate; it never republishes or reranks by itself.
						$values['is_public'] = 0; $values['is_rankable'] = 0; $values['rankability_status'] = 'pending_review';
					}
					if ( self::mode( $before ) === $mode && (string) ( $before['tracking_reason'] ?? '' ) === $reason && (string) ( $before['tracking_evidence_url'] ?? '' ) === (string) $values['tracking_evidence_url'] && empty($before['tracking_return_candidate_at']) ) { $unchanged[] = $id; continue; }
					Repository::check( $wpdb->update( $table, $values, array( 'id' => $id ) ) );
					$after = array_merge( $before, $values );
					( new AuditLogService() )->write( 'fighter_tracking_mode_changed', 'fighter', $id, $this->decision_snapshot( $before ), $this->decision_snapshot( $after ), $reason, $actor );
					$changed[$id] = array( 'before' => $before, 'after' => $after );
				}
				return array( 'changed' => $changed, 'unchanged' => $unchanged );
			} );
		} );

		foreach ( $result['changed'] as $id => $rows ) { FighterLifecycleService::changed( (int) $id, $actor, $rows['before'], $rows['after'], true, false ); }
		if ( $result['changed'] ) { \MMAF\DataEngine\Services\DataPipeline\Processor::request_refresh( $actor, true ); }
		return array( 'selected' => count( $ids ), 'updated' => count( $result['changed'] ), 'unchanged' => count( $result['unchanged'] ) );
	}

	public function recheck_due( int $fighter_id ): bool {
		global $wpdb; $t = Schema::table_names();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT tracking_mode,tracking_last_checked_at,updated_at,COALESCE(tracking_last_checked_bout_id,0) checked,(SELECT COALESCE(MAX(p.bout_id),0) FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id=f.id AND b.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest') AND (e.event_date IS NULL OR e.event_date<=UTC_DATE())) latest,(SELECT MAX(GREATEST(COALESCE(b.updated_at,b.created_at,'1000-01-01 00:00:00'),COALESCE(e.updated_at,e.created_at,'1000-01-01 00:00:00'))) FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id=f.id AND b.deleted_soft=0) relevant_updated_at FROM {$t['fighters']} f WHERE f.id=%d", $fighter_id ), ARRAY_A );
		return $row && self::PAUSED === (string) $row['tracking_mode'] && ((int) $row['latest'] > (int) $row['checked'] || (!empty($row['tracking_last_checked_at']) && ((string)$row['updated_at']>(string)$row['tracking_last_checked_at'] || (string)$row['relevant_updated_at']>(string)$row['tracking_last_checked_at'])));
	}

	/** Mark paused fighters for admin review once per newly observed completed professional bout. */
	public function refresh_paused_candidates( string $reference_date, int $actor = 0 ): array {
		global $wpdb; $t = Schema::table_names(); $profiles=\MMAF\DataEngine\Services\DataPipeline\Schema::tables()['profiles'];$now = gmdate( 'Y-m-d H:i:s' );
		$due=(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT f.id,f.updated_at,f.tracking_last_checked_at,COALESCE(f.tracking_last_checked_bout_id,0) checked,COALESCE(MAX(CASE WHEN b.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest') AND (e.event_date IS NULL OR e.event_date<=UTC_DATE()) THEN p.bout_id ELSE 0 END),0) latest,MAX(GREATEST(COALESCE(b.updated_at,b.created_at,'1000-01-01 00:00:00'),COALESCE(e.updated_at,e.created_at,'1000-01-01 00:00:00'))) relevant_updated_at FROM {$t['fighters']} f LEFT JOIN {$t['bout_participants']} p ON p.fighter_id=f.id LEFT JOIN {$t['bouts']} b ON b.id=p.bout_id LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE f.deleted_soft=0 AND f.tracking_mode='paused' GROUP BY f.id HAVING latest>checked OR updated_at>tracking_last_checked_at OR relevant_updated_at>tracking_last_checked_at) pending");
		$rows = $wpdb->get_results( "SELECT f.id,f.updated_at,f.tracking_last_checked_at,COALESCE(f.tracking_last_checked_bout_id,0) checked,COALESCE(MAX(CASE WHEN b.deleted_soft=0 AND b.status IN ('valid','completed') AND p.result_for_fighter IN ('win','loss','draw','no_contest') AND (e.event_date IS NULL OR e.event_date<=UTC_DATE()) THEN p.bout_id ELSE 0 END),0) latest,MAX(GREATEST(COALESCE(b.updated_at,b.created_at,'1000-01-01 00:00:00'),COALESCE(e.updated_at,e.created_at,'1000-01-01 00:00:00'))) relevant_updated_at,MAX(CASE WHEN b.deleted_soft=0 AND b.status IN ('valid','completed') THEN e.event_date END) latest_event_date,(SELECT MAX(pr.source_fetched_at) FROM {$t['fighter_sources']} fs JOIN $profiles pr ON pr.source_type=fs.source_type AND (pr.source_fighter_id=fs.source_fighter_id OR pr.source_url=fs.source_url) WHERE fs.fighter_id=f.id AND fs.source_type='tapology' AND pr.payload_json IS NOT NULL AND pr.content_hash IS NOT NULL) fresh_profile_at FROM {$t['fighters']} f LEFT JOIN {$t['bout_participants']} p ON p.fighter_id=f.id LEFT JOIN {$t['bouts']} b ON b.id=p.bout_id LEFT JOIN {$t['events']} e ON e.id=b.event_id WHERE f.deleted_soft=0 AND f.tracking_mode='paused' GROUP BY f.id HAVING updated_at>tracking_last_checked_at OR relevant_updated_at>tracking_last_checked_at OR (latest>checked AND fresh_profile_at IS NOT NULL AND (latest_event_date IS NULL OR DATE(fresh_profile_at)>=latest_event_date))", ARRAY_A ) ?: array();
		if ( ! $rows ) { return array( 'checked' => 0, 'candidates' => 0, 'profile_refresh_pending' => $due ); }
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$evaluations = ( new FighterReadinessService() )->evaluate_fighter_ids( $ids, $reference_date );
		$by_id = array(); foreach ( $evaluations['items'] ?? $evaluations as $item ) { $by_id[(int) ( $item['fighter']['id'] ?? $item['fighter_id'] ?? 0 )] = $item; }
		$candidates = 0;
		foreach ( $rows as $row ) {
			$id = (int) $row['id']; $item = $by_id[$id] ?? array();
			$blockers = array_values( array_diff( (array) ( $item['formula_blocker_codes'] ?? array() ), array( 'not_public','not_verified','not_rankable_flag','rankability_status_not_rankable','ineligible_status_provisional','tracking_mode_paused' ) ) );
			$is_candidate = empty( $blockers ) && ! empty( $item );
			Repository::check( $wpdb->update( $t['fighters'], array( 'tracking_last_checked_at' => $now, 'tracking_last_checked_bout_id' => (int) $row['latest'], 'tracking_return_candidate_at' => $is_candidate ? $now : null, 'updated_at' => $now ), array( 'id' => $id ) ) );
			if ( $is_candidate ) { ++$candidates; ( new AuditLogService() )->write( 'fighter_tracking_return_candidate', 'fighter', $id, null, array( 'latest_bout_id' => (int) $row['latest'], 'reference_date' => $reference_date ), 'A new completed professional bout triggered eligibility review.', $actor ); }
		}
		return array( 'checked' => count( $rows ), 'candidates' => $candidates, 'profile_refresh_pending' => max(0,$due-count($rows)) );
	}

	public function export_manifest(): array {
		global $wpdb; $t = Schema::table_names();
		$rows = $wpdb->get_results( "SELECT f.id,f.display_name,f.tracking_mode,f.tracking_reason,f.tracking_source,f.tracking_evidence_url,f.tracking_changed_at,f.tracking_revision,f.tracking_last_checked_bout_id,f.tracking_return_candidate_at,s.source_fighter_id,s.source_numeric_id,s.source_url,s.identity_hash FROM {$t['fighters']} f LEFT JOIN {$t['fighter_sources']} s ON s.id=(SELECT s2.id FROM {$t['fighter_sources']} s2 WHERE s2.fighter_id=f.id AND s2.source_type='tapology' ORDER BY s2.is_primary DESC,s2.is_verified DESC,s2.id ASC LIMIT 1) WHERE f.deleted_soft=0 ORDER BY f.id", ARRAY_A ) ?: array();
		$items = array(); $counts = array_fill_keys( self::MODES, 0 );
		foreach ( $rows as $row ) {
			$mode = self::mode( $row ); ++$counts[$mode];
			$items[] = array( 'fighter_id' => (int) $row['id'], 'display_name' => $row['display_name'], 'source_fighter_id' => $row['source_fighter_id'], 'source_numeric_id' => $row['source_numeric_id'], 'source_url' => $row['source_url'], 'identity_hash' => $row['identity_hash'], 'tracking_mode' => $mode, 'reason' => $row['tracking_reason'], 'source' => $row['tracking_source'], 'evidence_url' => $row['tracking_evidence_url'], 'changed_at' => $row['tracking_changed_at'], 'revision' => (int) $row['tracking_revision'], 'recheck_due' => self::PAUSED === $mode ? $this->recheck_due( (int) $row['id'] ) : false, 'return_candidate' => ! empty( $row['tracking_return_candidate_at'] ) );
		}
		return array( 'schema_version' => 'mmaf_tracking_decisions_v1', 'exported_at' => gmdate( 'c' ), 'revision' => hash( 'sha256', wp_json_encode( $items, JSON_UNESCAPED_SLASHES ) ), 'counts' => $counts, 'fighters' => $items );
	}

	public function review_rows( int $limit = 500 ): array {
		global $wpdb; $t = Schema::table_names(); $limit = min( 1000, max( 1, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT id,display_name,tracking_mode,tracking_reason,tracking_source,tracking_evidence_url,tracking_changed_at,tracking_last_checked_at,tracking_return_candidate_at,in_ufc,date_of_birth,birth_year FROM {$t['fighters']} WHERE deleted_soft=0 AND tracking_mode<>'normal' ORDER BY tracking_return_candidate_at IS NULL,tracking_return_candidate_at DESC,tracking_changed_at DESC,id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	}

	/** Read-only proposal list. It never treats is_rankable=0 as an exclusion reason. */
	public function suggestion_rows( int $limit = 1000 ): array {
		global $wpdb; $t=Schema::table_names();$limit=min(5000,max(1,$limit));
		$rows=$wpdb->get_results($wpdb->prepare("SELECT f.*,s.wins,s.losses,s.pro_fights_count,s.last_fight_date FROM {$t['fighters']} f LEFT JOIN {$t['fighter_stats_current']} s ON s.fighter_id=f.id WHERE f.deleted_soft=0 AND f.tracking_mode='normal' ORDER BY f.id LIMIT %d",$limit),ARRAY_A)?:[];
		$ids=array_map('intval',array_column($rows,'id'));$career=$ids?(new \MMAF\DataEngine\Services\Scoring\CareerRecords())->for_fighters($ids):[];
		$config=\MMAF\DataEngine\Services\Formula\FormulaRegistry::current_config()['eligibility'];$cutoff=(new \DateTimeImmutable(current_time('Y-m-d')))->modify('-'.((int)$config['inactivity_months']).' months')->format('Y-m-d');$out=[];
		foreach($rows as $row){$id=(int)$row['id'];$permanent=[];$temporary=[];$evidence=[];
			if((int)$row['in_ufc']===1){$permanent[]='confirmed_ufc_signing';}
			if(!empty($row['date_of_birth'])){$age=(new \DateTimeImmutable($row['date_of_birth']))->diff(new \DateTimeImmutable(current_time('Y-m-d')))->y;if($age>(int)$config['max_age']){$permanent[]='age_over_34';$evidence['age']=$age;}}
			$record=$career[$id]??null;if(($record['status']??'')==='accepted' && (int)($record['record']['pro_fights_count']??0)>(int)$config['max_pro_fights_count']){$permanent[]='confirmed_pro_fights_over_30';$evidence['career_record']=$record['record'];$evidence['career_source_url']=$record['source_url']??null;}
			if(!$permanent && !empty($row['last_fight_date']) && $row['last_fight_date']<$cutoff){$temporary[]='inactive_24_months';}
			$allowed=null;foreach($config['loss_limit_by_wins']??[] as $minimum_wins=>$maximum_losses){if((int)$row['wins']>=(int)$minimum_wins){$allowed=(int)$maximum_losses;break;}}
			if(!$permanent && $allowed!==null && (int)$row['losses']>$allowed){$temporary[]='loss_limit';$evidence['recorded_record']=['wins'=>(int)$row['wins'],'losses'=>(int)$row['losses'],'allowed_losses'=>$allowed];}
			$reasons=$permanent?:$temporary;if(!$reasons){continue;}$out[]=['fighter_id'=>$id,'display_name'=>$row['display_name'],'suggested_mode'=>$permanent?self::OPPONENT_ONLY:self::PAUSED,'reasons'=>$reasons,'evidence'=>$evidence,'conflict'=>count($reasons)>1];
		}
		return $out;
	}

	private function rankability_status( string $mode, string $reason ): string {
		if ( self::PAUSED === $mode ) { return false !== strpos( $reason, 'inactive' ) ? 'ineligible_inactive' : ( false !== strpos( $reason, 'loss' ) ? 'ineligible_loss_limit' : 'pending_review' ); }
		if ( false !== strpos( $reason, 'ufc' ) ) { return 'ineligible_ufc'; }
		if ( false !== strpos( $reason, 'age' ) ) { return 'ineligible_age'; }
		if ( false !== strpos( $reason, 'fight' ) || false !== strpos( $reason, 'bout' ) ) { return 'ineligible_too_many_fights'; }
		return 'not_public';
	}

	private function decision_snapshot( array $row ): array {
		return array_intersect_key( $row, array_flip( array( 'tracking_mode','tracking_reason','tracking_source','tracking_evidence_url','tracking_changed_at','tracking_revision','tracking_last_checked_at','tracking_last_checked_bout_id','tracking_return_candidate_at','is_public','is_rankable','rankability_status' ) ) );
	}
}
