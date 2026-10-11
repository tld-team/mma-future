<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;

/** Queries shared by public REST responses and server-rendered pages. */
final class PublicReadRepository {
	private array $tables;
	public function __construct() { $this->tables = Schema::table_names(); }

	public function rankings( int $run_id, string $board, array $options ): array {
		global $wpdb;
		$t = $this->tables;
		$where = array( 'r.ranking_run_id = %d', 'r.board_key = %s', 'f.deleted_soft = 0', 'f.is_public = 1' );
		$args = array( $run_id, $board );
		if ( ! empty( $options['top15'] ) ) { $where[] = 'r.rank_position <= 15'; }
		$q = trim( (string) ( $options['q'] ?? '' ) );
		if ( '' !== $q ) {
			$where[] = '(f.display_name LIKE %s OR f.normalized_name LIKE %s OR f.nickname LIKE %s OR f.nationality LIKE %s)';
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$args = array_merge( $args, array_fill( 0, 4, $like ) );
		}
		$where[] = $this->public_post_condition();
		$from = "FROM {$t['ranking_snapshots']} r INNER JOIN {$t['fighters']} f ON f.id=r.fighter_id LEFT JOIN {$t['fighter_stats_current']} s ON s.fighter_id=f.id WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) );
		$sort = 'score' === ( $options['sort'] ?? '' ) ? 'r.total_score' : 'r.rank_position';
		$order = 'desc' === strtolower( (string) ( $options['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
		$limit = max( 1, min( 100, (int) ( $options['per_page'] ?? 25 ) ) );
		$offset = ( max( 1, (int) ( $options['page'] ?? 1 ) ) - 1 ) * $limit;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, f.display_name,f.nickname,f.gender,f.date_of_birth,f.birth_year,f.nationality,f.weight_class,f.wp_post_id,s.wins,s.losses,s.draws,s.nc,s.pro_fights_count,s.finish_wins,s.finish_rate,s.last_fight_date,s.streak,s.recent_form,s.activity_status,s.calculated_at AS stats_calculated_at {$from} ORDER BY {$sort} {$order},r.rank_position ASC,r.fighter_id ASC LIMIT %d OFFSET %d",
			array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		return array( 'total' => $total, 'rows' => $rows ?: array() );
	}

	public function fights( int $fighter_id, array $options ): array {
		global $wpdb;
		$t = $this->tables;
		$where = array( 'p.fighter_id=%d', 'b.deleted_soft=0', "b.status IN ('valid','completed','excluded_record_ineligible')", 'e.deleted_soft=0', "p.result_for_fighter IN ('win','loss','draw','no_contest')" );
		$args = array( $fighter_id );
		$result = (string) ( $options['result'] ?? '' );
		if ( in_array( $result, array( 'win','loss','draw','no_contest' ), true ) ) { $where[]='p.result_for_fighter=%s'; $args[]=$result; }
		$method = (string) ( $options['method'] ?? '' );
		if ( 'other' === $method ) { $where[]="b.method_category IN ('dq','draw','no_contest')"; }
		elseif ( in_array( $method, array( 'ko_tko','submission','decision','unknown' ), true ) ) { $where[]='b.method_category=%s'; $args[]=$method; }
		$from = "FROM {$t['bout_participants']} p INNER JOIN {$t['bouts']} b ON b.id=p.bout_id INNER JOIN {$t['events']} e ON e.id=b.event_id LEFT JOIN {$t['fighters']} opp ON opp.id=p.opponent_fighter_id WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) );
		$order = 'oldest' === ( $options['sort'] ?? '' ) ? 'ASC' : 'DESC';
		$limit = max( 1, min( 100, (int) ( $options['per_page'] ?? 10 ) ) );
		$offset = ( max( 1, (int) ( $options['page'] ?? 1 ) ) - 1 ) * $limit;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.status AS bout_status,p.bout_id,p.opponent_fighter_id,p.result_for_fighter,b.event_id,b.weight_class,b.method_category,b.method_detail,b.round_number,b.time_in_round,e.event_name,e.event_date,e.location,e.promotion_name,opp.display_name AS opponent_display_name,CASE WHEN opp.is_public=1 AND opp.deleted_soft=0 THEN opp.wp_post_id ELSE NULL END AS opponent_wp_post_id {$from} ORDER BY e.event_date {$order},b.id {$order} LIMIT %d OFFSET %d",
			array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		return array( 'total'=>$total, 'rows'=>$rows ?: array() );
	}

	public function coverage( int $fighter_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['fighter_career_coverage']} WHERE fighter_id=%d", $fighter_id ), ARRAY_A );
		return $row ?: null;
	}

    public function previous_activation(int $active_id,string $board): ?array {
        global $wpdb;$ledger=\MMAF\DataEngine\Services\Operations\Schema::tables()['activations'];
        $current=$wpdb->get_row($wpdb->prepare("SELECT activated_at,audit_id FROM $ledger WHERE ranking_run_id=%d AND board_key=%s ORDER BY activated_at DESC,audit_id DESC LIMIT 1",$active_id,$board),ARRAY_A);
        if(!$current){return null;}
        $row=$wpdb->get_row($wpdb->prepare("SELECT ranking_run_id,activated_at FROM $ledger WHERE board_key=%s AND ranking_run_id<>%d AND (activated_at<%s OR (activated_at=%s AND audit_id<%d)) ORDER BY activated_at DESC,audit_id DESC LIMIT 1",$board,$active_id,$current['activated_at'],$current['activated_at'],$current['audit_id']),ARRAY_A);
        $run=$row?(new RankingRunRepository())->find((int)$row['ranking_run_id']):null;
        if($run){$run['activated_at']=$row['activated_at'];}return $run;
    }
    public function movers(int $current,int $previous,string $board,int $limit=3): array {
        global $wpdb;$t=$this->tables;
        return $wpdb->get_results($wpdb->prepare("SELECT c.fighter_id,c.rank_position current_rank,p.rank_position previous_rank,(p.rank_position-c.rank_position) rank_change,f.display_name,f.wp_post_id FROM {$t['ranking_snapshots']} c JOIN {$t['ranking_snapshots']} p ON p.ranking_run_id=%d AND p.board_key=c.board_key AND p.fighter_id=c.fighter_id JOIN {$t['fighters']} f ON f.id=c.fighter_id WHERE c.ranking_run_id=%d AND c.board_key=%s AND p.rank_position>c.rank_position AND f.is_public=1 AND f.deleted_soft=0 AND ".$this->public_post_condition()." ORDER BY rank_change DESC,c.rank_position,f.id LIMIT %d",$previous,$current,$board,min(10,max(1,$limit))),ARRAY_A)?:[];
    }
    public function new_entries(int $current,int $previous,string $board,array $options=[]): array {
        global $wpdb;$t=$this->tables;
        $from="FROM {$t['ranking_snapshots']} c LEFT JOIN {$t['ranking_snapshots']} p ON p.ranking_run_id=%d AND p.board_key=c.board_key AND p.fighter_id=c.fighter_id JOIN {$t['fighters']} f ON f.id=c.fighter_id WHERE c.ranking_run_id=%d AND c.board_key=%s AND p.id IS NULL AND f.is_public=1 AND f.deleted_soft=0 AND ".$this->public_post_condition();
        $args=[$previous,$current,$board];$total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $from",$args));
        if(isset($options['include_new']) && !$options['include_new']){return ['total'=>$total,'rows'=>[]];}
        $pagination='';if(isset($options['new_page']) || isset($options['new_per_page'])){$pagination=' LIMIT %d OFFSET %d';$limit=min(100,max(1,(int)($options['new_per_page']??25)));$args[]=$limit;$args[]=(max(1,(int)($options['new_page']??1))-1)*$limit;}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT c.fighter_id,c.rank_position current_rank,f.display_name,f.wp_post_id $from ORDER BY c.rank_position,f.id $pagination",$args),ARRAY_A)?:[];
        return ['total'=>$total,'rows'=>$rows];
    }
    /** Card data in one bounded query, without biography, history or source payloads. */
    public function cards(array $posts): array {
        global $wpdb;$t=$this->tables;$in=implode(',',array_map('absint',$posts));if(!$in){return [];}
        $run=(new RankingRunRepository())->get_active_ranking_run_id();
        return $wpdb->get_results($wpdb->prepare("SELECT f.id,f.wp_post_id,f.display_name,f.gender,f.weight_class,f.nationality,f.date_of_birth,f.birth_year,f.nickname,s.wins,s.losses,s.draws,s.nc,s.pro_fights_count,c.status coverage_status,r.rank_position FROM {$t['fighters']} f LEFT JOIN {$t['fighter_stats_current']} s ON s.fighter_id=f.id LEFT JOIN {$t['fighter_career_coverage']} c ON c.fighter_id=f.id LEFT JOIN {$t['ranking_snapshots']} r ON r.fighter_id=f.id AND r.ranking_run_id=%d AND r.board_key='overall' WHERE f.wp_post_id IN ($in) AND f.is_public=1 AND f.deleted_soft=0 AND ".$this->public_post_condition(),$run),ARRAY_A)?:[];
    }

	public function boards( int $run_id ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT board_key FROM {$this->tables['ranking_snapshots']} WHERE ranking_run_id=%d ORDER BY board_key", $run_id ) ) ?: array();
	}

	public function entities( string $type, string $query, int $limit=20 ): array {
		global $wpdb;
		$limit=max(1,min(50,$limit)); $like='%'.$wpdb->esc_like($query).'%';
		if ( 'event' === $type ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT id,event_name AS text,event_date FROM {$this->tables['events']} WHERE deleted_soft=0 AND event_name LIKE %s ORDER BY event_date DESC,id DESC LIMIT %d",$like,$limit ),ARRAY_A ) ?: array();
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT f.id,f.display_name AS text FROM {$this->tables['fighters']} f WHERE f.is_public=1 AND f.deleted_soft=0 AND ".$this->public_post_condition()." AND (f.display_name LIKE %s OR f.nickname LIKE %s) ORDER BY f.display_name,f.id LIMIT %d",$like,$like,$limit ),ARRAY_A ) ?: array();
	}

	public function event( int $id ): ?array {
		global $wpdb;
		$row=$wpdb->get_row($wpdb->prepare("SELECT id,event_name,event_date,location,promotion_name FROM {$this->tables['events']} WHERE id=%d AND deleted_soft=0",$id),ARRAY_A);
		return $row ?: null;
	}
	public function upcoming_events( int $limit = 3 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT id,event_name,event_date,location,promotion_name FROM {$this->tables['events']} WHERE deleted_soft=0 AND status IN ('valid','scheduled') AND event_date>%s ORDER BY event_date,id LIMIT %d", wp_date( 'Y-m-d' ), max( 1, min( 10, $limit ) ) ), ARRAY_A ) ?: array();
	}
	public function public_post_ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( "SELECT f.wp_post_id FROM {$this->tables['fighters']} f WHERE f.is_public=1 AND f.deleted_soft=0 AND " . $this->public_post_condition() ) ?: array() );
	}

	private function public_post_condition(): string {
		global $wpdb;
		return "EXISTS (SELECT 1 FROM {$wpdb->posts} public_post WHERE public_post.ID=f.wp_post_id AND public_post.post_type='mmaf_fighter' AND public_post.post_status='publish')";
	}
}
