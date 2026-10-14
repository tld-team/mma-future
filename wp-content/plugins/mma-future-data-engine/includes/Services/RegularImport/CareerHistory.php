<?php
namespace MMAF\DataEngine\Services\RegularImport;

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Repositories\FighterSourceRepository;
use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\EventSourceRepository;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Services\FieldProvenanceService;
use MMAF\DataEngine\Services\AuditLogService;
use MMAF\DataEngine\Support\TapologyFighterUrl;
use MMAF\DataEngine\Support\Sanitizer;

/** Career import writes the same canonical tables. Profile rows never become a second public database. */
final class CareerHistory {
	public static function manifest(): array {
		global $wpdb; $t = CanonicalSchema::table_names(); $targets = array(); $review = array();
		$rows = $wpdb->get_results( "SELECT f.id,f.display_name,f.is_public,f.is_rankable,f.rankability_status,fs.source_fighter_id,fs.source_url FROM {$t['fighters']} f LEFT JOIN {$t['fighter_sources']} fs ON fs.fighter_id=f.id AND fs.source_type='tapology' WHERE f.deleted_soft=0 AND (f.is_public=1 OR (f.is_rankable=1 AND f.rankability_status='rankable')) ORDER BY f.id,fs.is_primary DESC,fs.id", ARRAY_A );
		foreach ( $rows as $row ) {
			$id = (int) $row['id']; if ( isset( $targets[$id] ) ) { continue; }
			$identity = TapologyFighterUrl::parse( (string) $row['source_url'] );
			$key = $identity ? ( $identity['source_fighter_id'] ?: 'tapology_fighter_url_' . substr( $identity['source_url_hash'], 0, 24 ) ) : null;
			if ( ! $identity || ( $row['source_fighter_id'] && $key !== $row['source_fighter_id'] ) ) { $review[$id] = array( 'fighter_id' => $id, 'reason' => 'Missing or conflicting source identity.' ); continue; }
			$row['source_fighter_id'] = $key;
			$row['fighter_id'] = $id; unset( $row['id'] ); $targets[$id] = $row; unset( $review[$id] );
		}
		return array( 'schema_version' => 'mmaf_career_targets_v1', 'site_url' => home_url( '/' ), 'created_at' => gmdate( 'c' ), 'targets' => array_values( $targets ), 'excluded_identity_review' => array_values( $review ), 'hash' => hash( 'sha256', wp_json_encode( array_values( $targets ) ) ) );
	}
	public function verify_target( array $profile, int $operation=0 ): array {
		$id = (int) ( $profile['fighter_id'] ?? 0 ); $fighter = ( new FighterRepository() )->find( $id );
		$identity = TapologyFighterUrl::parse( (string) ( $profile['source_url'] ?? '' ) );
		$key = $identity ? ( $identity['source_fighter_id'] ?: 'tapology_fighter_url_' . substr( $identity['source_url_hash'], 0, 24 ) ) : null;
		if ( ! $identity || $key !== ( $profile['source_fighter_id'] ?? '' ) ) { throw new ImportException( 'Career profile URL does not match its declared source identity.', 'identity' ); }
		$sources = new FighterSourceRepository();
		$source = $sources->find_by_source( 'tapology', $key );
		if ( ! $source ) {
			$matches = $sources->find_by_normalized_source_url( 'tapology', $identity['canonical_url'] );
			$source = count( $matches ) === 1 ? $matches[0] : null;
		}
		if ( ! $fighter || ! empty( $fighter['deleted_soft'] ) || ( empty( $fighter['is_public'] ) && ( empty( $fighter['is_rankable'] ) || 'rankable' !== $fighter['rankability_status'] ) && !\MMAF\DataEngine\Services\Operations\Authority::allows($operation,$id) ) || ! $source || (int) $source['fighter_id'] !== $id ) { throw new ImportException( 'Career target no longer matches its canonical identity or approved scope: ' . $id, 'identity' ); }
		return $fighter;
	}
	public function process( array $profile, int $job, int $actor, bool $apply, int $operation=0 ): array {
		$profile['_job_id'] = $job;
		$this->verify_target($profile,$operation);
		$retained=$this->retained_coverage($profile);
		if ($retained) { return ['fighter_id'=>(int)$profile['fighter_id'],'source_fighter_id'=>$profile['source_fighter_id'],'items'=>[],'facts'=>['changes'=>[],'skipped'=>[]],'coverage'=>$retained,'retained_newer'=>true]; }
		$fighter = $this->verify_target( $profile,$operation ); $facts = $this->enrich_facts( $fighter, $profile, $actor, $apply ); $id = (int) $fighter['id']; $items = array(); $accepted = array(); $seen = array(); $conflicts = 0; $unresolved = 0;
		$signatures = array(); $duplicate_conflicts = array();
		foreach ( $profile['rows'] ?? array() as $candidate ) {
			$source_key = (string) ( $candidate['source_bout_id'] ?? '' );
			if ( ! $source_key ) { continue; }
			$signature = wp_json_encode( array( $candidate['result'] ?? '', $candidate['opponent_source_id'] ?? '', $candidate['source_event_id'] ?? '', $candidate['fight_date'] ?? '' ) );
			if ( isset( $signatures[$source_key] ) && $signature !== $signatures[$source_key] ) { $duplicate_conflicts[$source_key] = true; }
			$signatures[$source_key] = $signature;
		}
		foreach ( $profile['rows'] ?? array() as $row ) {
			$key = (string) ( $row['source_bout_id'] ?? '' );
			if ( ! $key ) { $key = hash( 'sha256', wp_json_encode( array( $row['source_event_id'] ?? '', $row['opponent_source_id'] ?? '', $row['fight_date'] ?? '', $row['result'] ?? '', $row['raw_text'] ?? '' ) ) ); }
			if ( isset( $seen[$key] ) ) { continue; } $seen[$key] = true;
			if ( isset( $duplicate_conflicts[$key] ) ) { $row['source_conflict_reason'] = 'The same source bout has contradictory profile rows.'; }
			try { $decision = $apply ? Transaction::run( fn() => $this->row( $profile, $row, $actor, true ) ) : $this->row( $profile, $row, $actor, false ); }
			catch ( \InvalidArgumentException $error ) { $decision = array( 'category' => 'conflict', 'reason' => $error->getMessage() ); }
			$decision['key'] = $key; $items[] = $decision;
			if ( 'conflict' === $decision['category'] ) { ++$conflicts; }
			if ( 'review' === $decision['category'] ) { ++$unresolved; }
			if ( ! empty( $decision['bout_id'] ) ) { $accepted[(int) $decision['bout_id']] = true; }
			if ( $apply && in_array( $decision['category'], array( 'conflict', 'review' ), true ) ) { Review::upsert( $job, 'career_' . $decision['category'], $profile['source_fighter_id'] . ':' . $key, array( 'fighter_id' => $id, 'reason' => $decision['reason'], 'source_row' => $row ), $actor ); }
		}
		$coverage = $this->coverage( $profile, $conflicts, $unresolved, $apply );
		if ( $apply ) { $this->save_coverage( $profile, $coverage, $job, $actor, count( $seen ), $conflicts, $unresolved, array_keys( $accepted ) ); }
		return array( 'fighter_id' => $id, 'source_fighter_id' => $profile['source_fighter_id'], 'items' => $items, 'facts' => $facts, 'coverage' => $coverage );
	}
    /** Each career chunk and its caller checkpoint share the same outer transaction. */
    public function process_chunk(array $profile,int $job,int $actor,int $operation,array $checkpoint=[],int $limit=50): array {
        $this->verify_target($profile,$operation);
        $retained=$this->retained_coverage($profile);if($retained){return ['fighter_id'=>(int)$profile['fighter_id'],'coverage'=>$retained,'facts'=>[],'done'=>true,'checkpoint'=>[]];}
        $hash=hash('sha256',wp_json_encode($profile));
        if(isset($checkpoint['source_hash']) && !hash_equals($checkpoint['source_hash'],$hash)){throw new ImportException('Stored career input changed between checkpoints.','identity');}
        $checkpoint+=['source_hash'=>$hash,'offset'=>0,'seen'=>[],'accepted'=>[],'conflicts'=>0,'unresolved'=>0];
        $signatures=[];$conflicts=[];
        foreach($profile['rows']??[] as $candidate){$key=(string)($candidate['source_bout_id']??'');if(!$key){continue;}$signature=wp_json_encode([$candidate['result']??'',$candidate['opponent_source_id']??'',$candidate['source_event_id']??'',$candidate['fight_date']??'']);if(isset($signatures[$key]) && $signatures[$key]!==$signature){$conflicts[$key]=true;}$signatures[$key]=$signature;}
        $chunk=array_slice($profile['rows']??[],(int)$checkpoint['offset'],$limit);$rows=[];
        foreach($chunk as $row){$key=(string)($row['source_bout_id']??'');if(!$key){$key=hash('sha256',wp_json_encode([$row['source_event_id']??'',$row['opponent_source_id']??'',$row['fight_date']??'',$row['result']??'',$row['raw_text']??'']));}if(isset($checkpoint['seen'][$key])){continue;}$checkpoint['seen'][$key]=true;if(isset($conflicts[$key])){$row['source_conflict_reason']='The same source bout has contradictory profile rows.';}$rows[]=$row;}
        $partial=$profile;$partial['rows']=$rows;$partial['history_exhausted']=false;
        $result=$this->process($partial,$job,$actor,true,$operation);
        foreach($result['items'] as $item){if($item['category']==='conflict'){++$checkpoint['conflicts'];}if($item['category']==='review'){++$checkpoint['unresolved'];}if(!empty($item['bout_id'])){$checkpoint['accepted'][(int)$item['bout_id']]=true;}}
        $checkpoint['offset']+=count($chunk);$done=$checkpoint['offset']>=count($profile['rows']??[]);
        $coverage=$this->coverage($done?$profile:$partial,(int)$checkpoint['conflicts'],(int)$checkpoint['unresolved'],true);
        $this->save_coverage($profile,$coverage,$job,$actor,count($checkpoint['seen']),(int)$checkpoint['conflicts'],(int)$checkpoint['unresolved'],array_keys($checkpoint['accepted']));
        return ['fighter_id'=>(int)$profile['fighter_id'],'source_fighter_id'=>$profile['source_fighter_id'],'coverage'=>$coverage,'facts'=>$result['facts'],'checkpoint'=>$checkpoint,'done'=>$done];
    }

	private function enrich_facts( array $fighter, array $profile, int $actor, bool $apply ): array {
		$changes = array(); $skipped = array(); $provenance = new FieldProvenanceService();
		$allowed = array( 'height_cm', 'height', 'reach_cm', 'stance', 'birthplace', 'fighting_out_of', 'team_name', 'coach_name' );
		foreach ( $profile['verified_facts'] ?? array() as $field => $fact ) {
			if ( ! in_array( $field, $allowed, true ) || 'explicit_profile_label' !== ( $fact['evidence'] ?? '' ) || empty( $fact['label'] ) || empty( $fact['raw_value'] ) ) { continue; }
			$value = Sanitizer::text_or_null( $fact['value'] ?? null );
			if ( ! $value || strlen( $value ) > 250 ) { continue; }
			if ( in_array( $field, array( 'height_cm', 'reach_cm' ), true ) && ( ! ctype_digit( $value ) || (int) $value < 100 || (int) $value > ( 'reach_cm' === $field ? 270 : 260 ) ) ) { continue; }
			if ( $provenance->is_protected( 'fighter', (int) $fighter['id'], $field ) ) { $skipped[$field] = 'manual_protection'; continue; }
			if ( null !== ( $fighter[$field] ?? null ) && '' !== $fighter[$field] && '0' !== (string) $fighter[$field] ) { $skipped[$field] = 'existing_value'; continue; }
			$changes[$field] = $value;
		}
		if ( $apply && $changes ) {
			Repository::check( ( new FighterRepository() )->update( (int) $fighter['id'], $changes ) );
			foreach ( $changes as $field => $value ) { $provenance->upsert_source( 'fighter', (int) $fighter['id'], $field, $value, 'tapology_career_history_profile', $profile['source_fighter_id'], $actor ); }
			( new AuditLogService() )->write( 'career_profile_facts_enriched', 'fighter', (int) $fighter['id'], array_intersect_key( $fighter, $changes ), $changes, 'Explicit source profile labels; empty unprotected fields only', $actor );
		}
		return array( 'changes' => $changes, 'skipped' => $skipped );
	}
	private function save_coverage( array $profile, array $coverage, int $job, int $actor, int $source_rows, int $conflicts, int $unresolved, array $accepted_ids ): void {
		$id = (int) $profile['fighter_id'];
			global $wpdb; $table = CanonicalSchema::table_names()['fighter_career_coverage'];
			Repository::check( $wpdb->replace( $table, array( 'fighter_id' => $id, 'source_type' => 'tapology', 'source_url' => esc_url_raw( $profile['source_url'] ?? '' ), 'source_fetched_at' => $this->mysql_date( $profile['scraped_at'] ?? null ), 'expected_record_json' => wp_json_encode( $profile['expected_record'] ?? null ), 'accepted_bouts_count' => $coverage['accepted_bouts_count'], 'status' => $coverage['status'], 'reason' => $coverage['reason'], 'evidence_json' => wp_json_encode( array( 'record' => $coverage['record'], 'source_scope' => $profile['history_scope'] ?? 'unknown', 'source_scope_verified' => !empty($profile['history_scope_verified']), 'source_status' => $profile['source_status'] ?? 'unavailable', 'source_history_exhausted' => $profile['history_exhausted'] ?? false, 'source_rows' => $source_rows, 'conflicts' => $conflicts, 'unresolved' => $unresolved, 'accepted_bout_ids' => $accepted_ids ) ), 'import_job_id' => $job, 'verified_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );
			( new AuditLogService() )->write( 'career_history_verified', 'fighter', $id, null, $coverage, 'Career history import job ' . $job, $actor );
	}
	public function refresh_coverage( array $profile, int $job, int $actor ): array {
		global $wpdb;
		$retained=$this->retained_coverage($profile);
		if ($retained) { return $retained; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT evidence_json FROM ' . CanonicalSchema::table_names()['fighter_career_coverage'] . ' WHERE fighter_id=%d AND import_job_id=%d', (int) $profile['fighter_id'], $job ), ARRAY_A );
		if ( ! $row ) { throw new ImportException( 'Career target has no processing evidence.', 'audit' ); }
		$evidence = json_decode( $row['evidence_json'], true );
		$coverage = $this->coverage( $profile, (int) $evidence['conflicts'], (int) $evidence['unresolved'], true );
		$this->save_coverage( $profile, $coverage, $job, $actor, (int) $evidence['source_rows'], (int) $evidence['conflicts'], (int) $evidence['unresolved'], $evidence['accepted_bout_ids'] );
		return $coverage;
	}
	/** A shared bout correction must invalidate an old completeness claim for either participant. */
	public function refresh_record_coverage(array $row,int $actor): void {
		$evidence=json_decode($row['evidence_json'],true) ?: [];
		$profile=['fighter_id'=>(int)$row['fighter_id'],'source_url'=>$row['source_url'],'scraped_at'=>$row['source_fetched_at'],'expected_record'=>json_decode($row['expected_record_json'],true),'history_scope'=>$evidence['source_scope']??'unknown','history_scope_verified'=>$evidence['source_scope_verified']??($row['status']==='complete'),'history_exhausted'=>$evidence['source_history_exhausted']??false,'source_status'=>$evidence['source_status']??($row['status']==='unavailable'?'unavailable':'success')];
		$profile['source_error_reason']=$row['reason'];
		$coverage=$this->coverage($profile,(int)($evidence['conflicts']??0),(int)($evidence['unresolved']??0),true);
		if ($coverage['accepted_bouts_count']===(int)$row['accepted_bouts_count'] && $coverage['record']===($evidence['record']??null)) { return; }
		$this->save_coverage($profile,$coverage,(int)$row['import_job_id'],$actor,(int)($evidence['source_rows']??0),(int)($evidence['conflicts']??0),(int)($evidence['unresolved']??0),$evidence['accepted_bout_ids']??[]);
	}
	private function retained_coverage(array $profile): ?array {
		global $wpdb; $table=CanonicalSchema::table_names()['fighter_career_coverage'];
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE fighter_id=%d",(int)$profile['fighter_id']),ARRAY_A);
		$incoming=\MMAF\DataEngine\Services\DataPipeline\ProfileStore::date($profile['scraped_at']??null);
		if (!$row || !$row['source_fetched_at'] || ($incoming && $incoming>=$row['source_fetched_at'])) { return null; }
		$evidence=json_decode($row['evidence_json'],true) ?: [];
		return ['status'=>$row['status'],'accepted_bouts_count'=>(int)$row['accepted_bouts_count'],'record'=>$evidence['record']??null,'expected_record'=>json_decode($row['expected_record_json'],true),'reason'=>$row['reason'],'retained_newer_source'=>true];
	}

	private function row( array $profile, array $row, int $actor, bool $apply ): array {
		global $wpdb; $t = CanonicalSchema::table_names(); $id = (int) $profile['fighter_id'];
		$exclude = ( ! empty( $row['source_division'] ) && 'pro' !== $row['source_division'] ) || ! empty( $row['is_exhibition'] ) || ! empty( $row['is_amateur'] ) || ! empty( $row['is_cancelled'] ) || ! empty( $row['is_upcoming'] ) || ( ! empty( $row['discipline'] ) && 'mma' !== $row['discipline'] );
		if ( $exclude ) { return $this->exclude_existing( $profile, $row, $actor, $apply ); }
		if ( ! empty( $row['source_conflict_reason'] ) ) { throw new \InvalidArgumentException( (string) $row['source_conflict_reason'] ); }
		$result = (string) ( $row['result'] ?? '' );
		if ( ! in_array( $result, array( 'win', 'loss', 'draw', 'no_contest' ), true ) ) { return array( 'category' => 'review', 'reason' => $row['bout_detail_limitation'] ?? 'Final W/L/D/NC result is not confirmed.' ); }
		// A confirmed NC remains valid even when the source also says overturned.
		if ( ! empty( $row['is_overturned'] ) && 'no_contest' !== $result && empty( $row['final_result_verified'] ) ) { return array( 'category' => 'review', 'reason' => 'Changed final result needs bout/event evidence.' ); }
		$date = Sanitizer::valid_date_or_null( $row['fight_date'] ?? '' );
		if ( ! $date || $date > gmdate( 'Y-m-d' ) ) { return array( 'category' => 'review', 'reason' => 'Missing or future fight date.' ); }
		$source_id = $row['source_bout_id'] ?? '';
		$source = $source_id ? ( new BoutSourceRepository() )->find_by_source( 'tapology', $source_id ) : null;
		$existing = $source && $source['bout_id'] ? ( new BoutRepository() )->find( (int) $source['bout_id'] ) : null;
		$opponent_identity = TapologyFighterUrl::parse( (string) ( $row['opponent_url'] ?? '' ) );
		if ( ! $opponent_identity ) { return array( 'category' => 'review', 'reason' => 'Opponent source identity is not confirmed.' ); }
		$opponent_key = $opponent_identity['source_fighter_id'] ?: 'tapology_fighter_url_' . substr( $opponent_identity['source_url_hash'], 0, 24 );
		if ( ( ! empty( $row['opponent_source_id'] ) && $row['opponent_source_id'] !== $opponent_key ) || $opponent_key === $profile['source_fighter_id'] ) { return array( 'category' => 'review', 'reason' => 'Opponent source identity is not confirmed.' ); }
		$opp_repo = new FighterSourceRepository();
		$opp_source = $opp_repo->find_by_source( 'tapology', $opponent_identity['source_fighter_id'] );
		$url_sources = $opp_repo->find_by_identity_hash( 'tapology', $opponent_identity['source_url_hash'] );
		$url_fighters = array_unique( array_map( 'intval', array_column( $url_sources, 'fighter_id' ) ) );
		if ( count( $url_fighters ) > 1 || ( $opp_source && $url_fighters && ! in_array( (int) $opp_source['fighter_id'], $url_fighters, true ) ) ) { throw new \InvalidArgumentException( 'Opponent source ID and exact source URL identify different fighters.' ); }
		if ( ! $opp_source && $url_sources ) { $opp_source = $url_sources[0]; }
		$opponent = $opp_source && $opp_source['fighter_id'] ? ( new FighterRepository() )->find( (int) $opp_source['fighter_id'] ) : null;
		if ( $opponent && ! empty( $opponent['deleted_soft'] ) ) { throw new \InvalidArgumentException( 'Opponent identity is soft-deleted.' ); }
		$event_source_id = $row['source_event_id'] ?? '';
		if ( ! preg_match( '/^tapology_event_([0-9]+)$/D', $event_source_id, $event_number ) || ! preg_match( '~^https://(?:www\.)?tapology\.com/fightcenter/events/' . $event_number[1] . '(?:-|/|$)~', (string) ( $row['event_url'] ?? '' ) ) ) { return array( 'category' => 'review', 'reason' => $row['bout_detail_limitation'] ?? 'Event source identity is not confirmed.' ); }
		$event_source = ( new EventSourceRepository() )->find_by_source( 'tapology', $event_source_id );
		$event = $event_source && $event_source['event_id'] ? ( new EventRepository() )->find( (int) $event_source['event_id'] ) : null;
		if ( $event && ( ! empty( $event['deleted_soft'] ) || ( $event['event_date'] && $date !== $event['event_date'] ) ) ) { throw new \InvalidArgumentException( 'Event identity/date conflicts with canonical data.' ); }
		$professional = ! empty( $row['is_professional'] ) && 'mma' === ( $row['discipline'] ?? '' );
		$professional = $professional || ( 'professional_mma' === ( $profile['history_scope'] ?? '' ) && ! empty( $profile['history_scope_verified'] ) );
		if ( ! $professional && ! ( $existing && in_array( $existing['status'], array( 'valid', 'completed' ), true ) ) ) { return array( 'category' => 'review', 'reason' => 'Professional MMA scope needs source evidence.' ); }
		if ( $source_id ) {
			if ( ! preg_match( '/^tapology_bout_([0-9]+)$/D', $source_id, $bout_number ) ) { throw new \InvalidArgumentException( 'Invalid source bout ID.' ); }
			if ( ! empty( $row['bout_url'] ) && ! preg_match( '~^https://(?:www\.)?tapology\.com/fightcenter/bouts/' . $bout_number[1] . '(?:-|/|$)~', (string) $row['bout_url'] ) ) { throw new \InvalidArgumentException( 'Bout source ID and URL conflict.' ); }
			if ( empty( $row['bout_url'] ) && 'profile_dom_bout_id' !== ( $row['source_identity_evidence'] ?? '' ) ) { return array( 'category' => 'review', 'reason' => 'Source bout ID needs a matching link or explicit profile DOM evidence.' ); }
		}
		if ( ! $existing && $event && $opponent ) {
			$matches = $wpdb->get_col( $wpdb->prepare( "SELECT b.id FROM {$t['bouts']} b JOIN {$t['bout_participants']} a ON a.bout_id=b.id AND a.fighter_id=%d JOIN {$t['bout_participants']} o ON o.bout_id=b.id AND o.fighter_id=%d WHERE b.event_id=%d AND b.deleted_soft=0", $id, (int) $opponent['id'], (int) $event['id'] ) );
			if ( $source_id ) {
				// A different confirmed source bout ID is a different bout, even if
				// the same opponents fought more than once at the same event.
				$matches = array_values( array_filter( $matches, function( $match ) use ( $wpdb, $t ) {
					return ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['bout_sources']} WHERE bout_id=%d AND source_type='tapology' AND source_bout_id REGEXP '^tapology_bout_[0-9]+$' LIMIT 1", (int) $match ) );
				} ) );
			}
			if ( count( $matches ) > 1 ) { throw new \InvalidArgumentException( 'Multiple bouts match these confirmed participants and event.' ); }
			if ( $matches ) { $existing = ( new BoutRepository() )->find( (int) $matches[0] ); }
		}
		if ( ! $source_id && ( ! $event || ! $opponent ) ) { return array( 'category' => 'review', 'reason' => 'Without a bout ID, both canonical participants and event must already be confirmed.' ); }
		// Explicit source record flags affect counting, independently of history display.
		$record_ineligible = array_key_exists( 'record_eligible', $row ) && false === $row['record_eligible'];
		if ( $record_ineligible && 'source_profile_record_ineligible_mma' !== ( $row['record_eligibility_evidence'] ?? '' ) ) { return array( 'category' => 'review', 'reason' => 'Record exclusion needs explicit source evidence.' ); }
		$target_status = $record_ineligible || ( $existing && 'excluded_record_ineligible' === $existing['status'] ) ? 'excluded_record_ineligible' : 'valid';
		$method_changes = array();
		if ( $existing && 'unknown' === $existing['method_category'] && in_array( $row['method_category'] ?? 'unknown', array( 'ko_tko', 'submission', 'decision', 'dq', 'draw', 'no_contest' ), true ) ) {
			$provenance = new FieldProvenanceService();
			if ( ! $provenance->is_protected( 'bout', (int) $existing['id'], 'method_category' ) ) { $method_changes['method_category'] = $row['method_category']; }
			if ( ! $provenance->is_protected( 'bout', (int) $existing['id'], 'method_detail' ) ) { $method_changes['method_detail'] = Sanitizer::text_or_null( $row['method'] ?? '' ); }
		}
		if ( $existing ) {
			$participants = ( new BoutParticipantRepository() )->list_by_bout( (int) $existing['id'] );
			$ids = array_map( 'intval', array_column( $participants, 'fighter_id' ) ); $expected = array( $id, (int) ( $opponent['id'] ?? 0 ) ); sort( $ids ); sort( $expected );
			if ( $ids !== $expected || ! $event || (int) $existing['event_id'] !== (int) $event['id'] || ! empty( $existing['deleted_soft'] ) ) { throw new \InvalidArgumentException( 'Bout identity conflicts with its canonical participants/event.' ); }
			if ( ! in_array( $existing['status'], array( 'valid', 'completed', 'excluded_overturned', 'excluded_record_ineligible' ), true ) ) { return array( 'category' => 'review', 'reason' => 'Canonical bout status requires review before historical inclusion.' ); }
			$own = current( array_filter( $participants, static fn( $p ) => (int) $p['fighter_id'] === $id ) );
			if ( ! $method_changes && $own['result_for_fighter'] === $result && ( $existing['status'] === $target_status || ( 'completed' === $existing['status'] && 'valid' === $target_status ) ) ) { return array( 'category' => 'unchanged', 'bout_id' => (int) $existing['id'] ); }
			if ( $own['result_for_fighter'] !== $result && empty( $row['final_result_verified'] ) ) { throw new \InvalidArgumentException( 'Changed final result requires verified bout/event evidence.' ); }
			$provenance = new FieldProvenanceService();
			foreach ( array( 'result_type', 'status', 'is_scoring_candidate' ) as $field ) { if ( $provenance->is_protected( 'bout', (int) $existing['id'], $field ) ) { throw new \InvalidArgumentException( 'Final result is protected by a manual decision.' ); } }
			foreach ( $participants as $p ) { if ( $provenance->is_protected( 'bout_participant', (int) $p['id'], 'result_for_fighter' ) ) { throw new \InvalidArgumentException( 'Participant result is protected by a manual decision.' ); } }
			if ( ! $apply ) { return array( 'category' => 'updated', 'bout_id' => (int) $existing['id'] ); }
		} elseif ( ! $apply ) { return array( 'category' => 'created', 'source_bout_id' => $source_id ); }
		if ( ! $apply ) { return array( 'category' => 'unchanged' ); }
		if ( ! $opponent ) {
			$name = Sanitizer::text_or_null( $row['opponent_name'] ?? '' ); if ( ! $name ) { throw new \InvalidArgumentException( 'A confirmed opponent name is required.' ); }
			$opponent_id = ( new FighterRepository() )->insert( array( 'display_name' => $name, 'normalized_name' => Sanitizer::normalize_name( $name ), 'weight_class' => 'unknown', 'status' => 'provisional', 'rankability_status' => 'pending_review', 'is_public' => 0, 'is_rankable' => 0 ) );
			if ( ! $opponent_id ) { throw new \RuntimeException( 'Could not save historical opponent.' ); }
			Repository::check( ( new FighterSourceRepository() )->upsert_for_fighter( $opponent_id, array( 'source_type' => 'tapology', 'source_fighter_id' => $opponent_identity['source_fighter_id'], 'source_url' => $opponent_identity['canonical_url'], 'confidence' => 100, 'is_verified' => 0, 'is_primary' => 1 ) ) );
			$opponent = ( new FighterRepository() )->find( $opponent_id );
			Review::upsert( (int) ( $profile['_job_id'] ?? 0 ), 'career_opponent', $opponent_key, array( 'fighter_id' => $opponent_id, 'source_url' => $opponent_identity['canonical_url'] ), $actor );
		}
		if ( ! $event ) {
			$name = Sanitizer::text_or_null( $row['event_name'] ?? '' ); if ( ! $name ) { throw new \InvalidArgumentException( 'A confirmed event name is required.' ); }
			$event_id = ( new EventRepository() )->insert( array( 'event_name' => $name, 'normalized_event_name' => Sanitizer::normalize_name( $name ), 'event_date' => $date, 'status' => 'valid' ) );
			if ( ! $event_id ) { throw new \RuntimeException( 'Could not save historical event.' ); }
			Repository::check( ( new EventSourceRepository() )->upsert_for_event( $event_id, array( 'source_type' => 'tapology', 'source_event_id' => $event_source_id, 'source_event_numeric_id' => $event_number[1], 'source_url' => esc_url_raw( $row['event_url'] ), 'identity_hash' => hash( 'sha256', 'tapology|' . $event_source_id ), 'raw_payload' => wp_json_encode( $row ), 'is_verified' => 1, 'is_primary' => 1 ) ) );
			$event = ( new EventRepository() )->find( $event_id );
		}
		$result_type = in_array( $result, array( 'win', 'loss' ), true ) ? 'win_loss' : ( 'draw' === $result ? 'draw' : 'no_contest' );
		$method = $row['method_category'] ?? 'unknown'; if ( ! in_array( $method, \MMAF\DataEngine\Services\BoutService::METHOD_CATEGORIES, true ) ) { $method = 'unknown'; }
		$payload = array( 'event_id' => (int) $event['id'], 'status' => $target_status, 'result_type' => $result_type, 'is_scoring_candidate' => 'valid' === $target_status && 'win_loss' === $result_type ? 1 : 0 );
		$payload += $method_changes;
		if ( ! $existing ) { $payload += array( 'weight_class' => Sanitizer::weight_class( $row['weight_class'] ?? 'unknown' ), 'method_category' => $method, 'method_detail' => Sanitizer::text_or_null( $row['method'] ?? '' ), 'round_number' => isset( $row['round'] ) && (int) $row['round'] > 0 && (int) $row['round'] <= 99 ? (int) $row['round'] : null, 'time_in_round' => preg_match( '/^\d{1,2}:\d{2}$/D', $row['time'] ?? '' ) ? $row['time'] : null ); }
		$bouts = new BoutRepository(); $bout_id = $existing ? (int) $existing['id'] : $bouts->insert( $payload );
		if ( $existing ) { Repository::check( $bouts->update( $bout_id, $payload ) ); } if ( ! $bout_id ) { throw new \RuntimeException( 'Could not save historical bout.' ); }
		$own_record = self::record( $row['prefight_record_raw'] ?? null ); $opp_record = self::record( $row['opponent_record_raw'] ?? null );
		$opposite = array( 'win' => 'loss', 'loss' => 'win', 'draw' => 'draw', 'no_contest' => 'no_contest' )[$result];
		if ( $existing ) {
			foreach ( $participants as &$p ) { unset( $p['id'], $p['created_at'], $p['updated_at'] ); $p['result_for_fighter'] = (int) $p['fighter_id'] === $id ? $result : $opposite; $p['is_winner'] = 'win' === $p['result_for_fighter'] ? 1 : 0; } unset( $p );
		} else {
			$participants = array( $this->participant( $id, (int) $opponent['id'], 'fighter_a', $profile['source_fighter_id'], $profile['display_name'] ?? '', $result, $own_record, $opp_record ), $this->participant( (int) $opponent['id'], $id, 'fighter_b', $opponent_key, $opponent['display_name'], $opposite, $opp_record, $own_record ) );
		}
		( new BoutParticipantRepository() )->replace_exactly_two( $bout_id, $participants );
		$source_id = $source_id ?: 'tapology_history_' . hash( 'sha256', $event_source_id . '|' . min( $id, (int) $opponent['id'] ) . '|' . max( $id, (int) $opponent['id'] ) );
		if ( ! $existing || ! $source ) {
			// Insert an additional source without overwriting an existing real bout ID.
			$known = ( new BoutSourceRepository() )->find_by_source( 'tapology', $source_id );
			if ( $known && (int) $known['bout_id'] !== $bout_id ) { throw new \InvalidArgumentException( 'Historical source identity is already attached to another bout.' ); }
			if ( ! $known ) { Repository::check( $wpdb->insert( $t['bout_sources'], array( 'bout_id' => $bout_id, 'event_source_id' => ( new BoutSourceRepository() )->find_event_source_id( (int) $event['id'], 'tapology' ), 'source_type' => 'tapology', 'source_bout_id' => $source_id, 'source_url' => esc_url_raw( $row['bout_url'] ?? '' ), 'identity_hash' => hash( 'sha256', 'tapology|' . $source_id ), 'content_hash' => hash( 'sha256', wp_json_encode( $row ) ), 'raw_payload' => wp_json_encode( $row ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ); }
		}
		foreach ( $payload as $field => $value ) { ( new FieldProvenanceService() )->upsert_source( 'bout', $bout_id, $field, $value, 'tapology_career_history', $source_id, $actor ); }
		( new AuditLogService() )->write( 'career_bout_imported', 'bout', $bout_id, $existing, $payload, 'Confirmed career history result', $actor );
		return array( 'category' => $existing ? 'updated' : 'created', 'bout_id' => $bout_id );
	}
	/** Correct a previous import only with an exact source bout and explicit source classification. */
	private function exclude_existing( array $profile, array $row, int $actor, bool $apply ): array {
		$decision = array( 'category' => 'excluded', 'reason' => 'Not a completed professional MMA result.' );
		$source = ( new BoutSourceRepository() )->find_by_source( 'tapology', $row['source_bout_id'] ?? '' );
		$existing = $source ? ( new BoutRepository() )->find( (int) $source['bout_id'] ) : null;
		if ( ! $existing || ! in_array( $existing['status'], array( 'valid', 'completed' ), true ) ) { return $decision; }
		if ( ! empty( $row['source_conflict_reason'] ) ) { throw new \InvalidArgumentException( $row['source_conflict_reason'] ); }
		if ( 'profile_dom_classification' !== ( $row['classification_evidence'] ?? '' ) ) { return array( 'category' => 'review', 'reason' => 'Changing canonical bout classification requires explicit source evidence.' ); }
		// A pending source result must never silently overwrite a confirmed result.
		if ( ! empty( $row['is_upcoming'] ) || ! empty( $row['is_cancelled'] ) ) { return array( 'category' => 'review', 'reason' => 'Confirmed canonical result conflicts with pending or cancelled source status.' ); }
		$participants = ( new BoutParticipantRepository() )->list_by_bout( (int) $existing['id'] );
		$own = array_filter( $participants, static fn( $p ) => (int) $p['fighter_id'] === (int) $profile['fighter_id'] );
		$opponent = array_values( array_filter( $participants, static fn( $p ) => (int) $p['fighter_id'] !== (int) $profile['fighter_id'] ) );
		$identity = TapologyFighterUrl::parse( $row['opponent_url'] ?? '' );
		$matches = $identity ? ( new FighterSourceRepository() )->find_by_identity_hash( 'tapology', $identity['source_url_hash'] ) : array();
		$matching_ids = array_unique( array_map( 'intval', array_column( $matches, 'fighter_id' ) ) );
		$event_source = ( new EventSourceRepository() )->find_by_source( 'tapology', $row['source_event_id'] ?? '' );
		$event = ( new EventRepository() )->find( (int) $existing['event_id'] );
		if ( ! $own || count( $participants ) !== 2 || count( $matching_ids ) !== 1 || $matching_ids[0] !== (int) ( $opponent[0]['fighter_id'] ?? 0 ) || ! $event_source || (int) $event_source['event_id'] !== (int) $existing['event_id'] || ! $event || $event['event_date'] !== ( $row['fight_date'] ?? '' ) || ! empty( $existing['deleted_soft'] ) ) { throw new \InvalidArgumentException( 'Excluded source bout conflicts with canonical participants, event or date.' ); }
		$status = ! empty( $row['is_amateur'] ) || in_array( $row['source_division'] ?? '', array( 'am', 'amateur' ), true ) ? 'excluded_amateur' : ( ! empty( $row['is_exhibition'] ) ? 'excluded_exhibition' : 'excluded_non_mma' );
		$provenance = new FieldProvenanceService();
		foreach ( array( 'status', 'is_scoring_candidate' ) as $field ) { if ( $provenance->is_protected( 'bout', (int) $existing['id'], $field ) ) { throw new \InvalidArgumentException( 'Bout classification is protected by a manual decision.' ); } }
		$decision = array( 'category' => 'updated', 'bout_id' => (int) $existing['id'], 'reason' => 'Confirmed source classification: ' . $status );
		if ( $apply ) {
			$payload = array( 'status' => $status, 'is_scoring_candidate' => 0 );
			Repository::check( ( new BoutRepository() )->update( (int) $existing['id'], $payload ) );
			foreach ( $payload as $field => $value ) { $provenance->upsert_source( 'bout', (int) $existing['id'], $field, $value, 'tapology_career_history', $row['source_bout_id'], $actor ); }
			( new AuditLogService() )->write( 'career_bout_classified', 'bout', (int) $existing['id'], $existing, $payload, 'Explicit profile DOM classification', $actor );
		}
		return $decision;
	}
	private function participant( int $id, int $opponent, string $role, string $source, string $name, string $result, ?array $own, ?array $opp ): array {
		$p = array( 'fighter_id' => $id, 'opponent_fighter_id' => $opponent, 'participant_role' => $role, 'source_fighter_id' => $source, 'source_name' => $name, 'result_for_fighter' => $result, 'is_winner' => 'win' === $result ? 1 : 0 );
		foreach ( array( 'wins', 'losses', 'draws', 'nc', 'record_raw' ) as $key ) { $p['prefight_' . $key] = $own[$key] ?? null; $p['opponent_prefight_' . $key] = $opp[$key] ?? null; }
		$p['opponent_prefight_diff'] = $opp ? $opp['wins'] - $opp['losses'] : null; return $p;
	}
	public static function record( ?string $raw ): ?array {
		if ( ! $raw || ! preg_match( '/^\s*(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?(?:\s*\((\d+)\s*(?:NC|N\/C)\))?\s*$/iD', $raw, $m ) ) { return null; }
		return array( 'wins' => (int) $m[1], 'losses' => (int) $m[2], 'draws' => isset( $m[3] ) ? (int) $m[3] : 0, 'nc' => isset( $m[4] ) ? (int) $m[4] : 0, 'record_raw' => trim( $raw ) );
	}
	private function coverage( array $profile, int $conflicts, int $unresolved, bool $after_apply ): array {
		global $wpdb; $t = CanonicalSchema::table_names();
		$record = array( 'wins' => 0, 'losses' => 0, 'draws' => 0, 'nc' => 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.result_for_fighter,COUNT(DISTINCT b.id) AS total FROM {$t['bout_participants']} p JOIN {$t['bouts']} b ON b.id=p.bout_id JOIN {$t['events']} e ON e.id=b.event_id WHERE p.fighter_id=%d AND b.deleted_soft=0 AND e.deleted_soft=0 AND b.status IN ('valid','completed') GROUP BY p.result_for_fighter", (int) $profile['fighter_id'] ), ARRAY_A );
		$map = array( 'win' => 'wins', 'loss' => 'losses', 'draw' => 'draws', 'no_contest' => 'nc' ); foreach ( $rows as $row ) { if ( isset( $map[$row['result_for_fighter']] ) ) { $record[$map[$row['result_for_fighter']]] = (int) $row['total']; } }
		$expected = $profile['expected_record'] ?? null; $match = is_array( $expected );
		foreach ( $record as $key => $value ) { if ( ! isset( $expected[$key] ) || (int) $expected[$key] !== $value ) { $match = false; } }
		$complete = $match && ! $conflicts && ! $unresolved && 'professional_mma' === ( $profile['history_scope'] ?? '' ) && ! empty( $profile['history_scope_verified'] ) && ! empty( $profile['history_exhausted'] ) && 'success' === ( $profile['source_status'] ?? '' );
		$confirmed = array_filter( $profile['rows'] ?? array(), static fn( $r ) => ! empty( $r['is_professional'] ) && 'mma' === ( $r['discipline'] ?? '' ) && empty( $r['is_amateur'] ) && empty( $r['is_cancelled'] ) && empty( $r['is_upcoming'] ) && in_array( $r['result'] ?? '', array( 'win','loss','draw','no_contest' ), true ) );
		$status = $conflicts ? 'conflict' : ( $complete ? 'complete' : ( $confirmed || array_sum( $record ) ? 'partial' : 'unavailable' ) );
		$reason = $complete ? null : ( 'success' !== ( $profile['source_status'] ?? '' ) ? ( $profile['source_error_reason'] ?? 'The source profile was unavailable at verification.' ) : ( $conflicts ? $conflicts . ' unresolved identity/result conflict(s).' : ( $unresolved ? $unresolved . ' row(s) need professional MMA identity or result evidence.' : ( ! $match ? 'Recorded W/L/D/NC totals do not match a verified complete source record.' : 'Source career scope or exhaustion has not been confirmed.' ) ) ) );
		if ( ! $complete && ! $conflicts && ! $unresolved ) {
			if ( 'unavailable' === $status && 'success' === ( $profile['source_status'] ?? '' ) ) { $reason = 'The source contains no confirmed completed professional MMA history.'; }
			elseif ( ! is_array( $expected ) ) { $reason = 'The source does not provide a separate confirmed professional MMA aggregate record.'; }
			elseif ( ! $match ) { $reason = 'Recorded W/L/D/NC ' . implode( '/', $record ) . ' differs from the source aggregate ' . implode( '/', $expected ) . '; missing history or final-result totals require review.'; }
		}
		return array( 'status' => $status, 'accepted_bouts_count' => array_sum( $record ), 'record' => $record, 'expected_record' => $expected, 'reason' => $reason, 'after_apply' => $after_apply );
	}
	private function mysql_date( ?string $date ): ?string { return $date && false !== strtotime( $date ) ? gmdate( 'Y-m-d H:i:s', strtotime( $date ) ) : null; }
}
