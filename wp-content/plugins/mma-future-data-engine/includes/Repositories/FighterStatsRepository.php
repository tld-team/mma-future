<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterStatsRepository {
	private string $table;
	private string $fighters_table;
	private string $events_table;
	private string $bouts_table;
	private string $participants_table;
	private string $system_state_table;

	public function __construct() {
		$tables                    = Schema::table_names();
		$this->table               = $tables['fighter_stats_current'];
		$this->fighters_table      = $tables['fighters'];
		$this->events_table        = $tables['events'];
		$this->bouts_table         = $tables['bouts'];
		$this->participants_table  = $tables['bout_participants'];
		$this->system_state_table  = $tables['system_state'];
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function list_active_fighters(): array {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT id, display_name, deleted_soft FROM {$this->fighters_table} WHERE deleted_soft = 0 ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function list_bouts_with_events(): array {
		global $wpdb;

		return $wpdb->get_results(
			"
			SELECT
				b.*,
				e.id AS event_exists,
				e.event_date
			FROM {$this->bouts_table} b
			LEFT JOIN {$this->events_table} e ON e.id = b.event_id AND e.deleted_soft = 0
			ORDER BY b.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function list_participants_with_fighters(): array {
		global $wpdb;

		return $wpdb->get_results(
			"
			SELECT
				p.*,
				f.id AS fighter_exists,
				f.deleted_soft AS fighter_deleted_soft
			FROM {$this->participants_table} p
			LEFT JOIN {$this->fighters_table} f ON f.id = p.fighter_id
			ORDER BY p.bout_id ASC, p.id ASC
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function replace_all( iterable $rows ): void {
		global $wpdb;

		$now = DateTime::mysql_now();

		\MMAF\DataEngine\Services\RegularImport\Transaction::begin();

		try {
			$deleted = $wpdb->query( "DELETE FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $deleted ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not clear fighter stats table.', 'mma-future-data-engine' ) );
			}

			foreach ( $rows as $row ) {
				$row['created_at'] = $now;
				$row['updated_at'] = $now;

				$inserted = $wpdb->insert( $this->table, $row );
				if ( false === $inserted ) {
					throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not insert fighter stats row.', 'mma-future-data-engine' ) );
				}
			}

			\MMAF\DataEngine\Services\RegularImport\Transaction::commit();
		} catch ( \Throwable $error ) {
			\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();
			throw $error;
		}
	}

	public function get_last_rebuild_summary(): ?array {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT state_value FROM {$this->system_state_table} WHERE state_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'last_stats_rebuild_summary'
			)
		);

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	public function set_last_rebuild_summary( array $summary ): void {
		global $wpdb;

		$state_value = wp_json_encode( $summary );
		$updated_at  = DateTime::mysql_now();

		$wpdb->query(
			$wpdb->prepare(
				"
				INSERT INTO {$this->system_state_table} (state_key, state_value, autoload, updated_at)
				VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), autoload = VALUES(autoload), updated_at = VALUES(updated_at)
				", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'last_stats_rebuild_summary',
				$state_value,
				'no',
				$updated_at
			)
		);
	}

	public function find_stat_by_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE fighter_id = %d LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	/** Current records for one admin page, independent of readiness refreshes. */
	public function records_for_fighters(array $ids): array {
		global $wpdb;
		$ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
		if (!$ids) { return []; }
		$rows=$wpdb->get_results("SELECT fighter_id,wins,losses,draws,nc FROM {$this->table} WHERE fighter_id IN (".implode(',',$ids).")",ARRAY_A);
		if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
		$out=[];
		foreach ($rows as $row) { $out[(int)$row['fighter_id']]=$row; }
		return $out;
	}

	public function active_count(): int {
		global $wpdb;
		return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->fighters_table} WHERE deleted_soft=0");
	}
	public function fighter_batches(int $size=250): \Generator {
		global $wpdb; $last=0;
		do {
			$rows=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$this->fighters_table} WHERE deleted_soft=0 AND id>%d ORDER BY id LIMIT %d",$last,$size),ARRAY_A);
			if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
			if (!$rows) { break; }
			$last=(int)end($rows)['id']; yield array_map('intval',array_column($rows,'id'));
		} while(count($rows)===$size);
	}
    public function bout_batch(int $last,int $size=250): array {
        global $wpdb;
			$bouts=$wpdb->get_results($wpdb->prepare("SELECT b.*,e.id AS event_exists,e.event_date FROM {$this->bouts_table} b LEFT JOIN {$this->events_table} e ON e.id=b.event_id AND e.deleted_soft=0 WHERE b.id>%d ORDER BY b.id LIMIT %d",$last,$size),ARRAY_A);
			if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
			if (!$bouts) { return []; }
			$ids=implode(',',array_map('intval',array_column($bouts,'id')));
			$participants=$wpdb->get_results("SELECT p.*,f.id AS fighter_exists,f.deleted_soft AS fighter_deleted_soft FROM {$this->participants_table} p LEFT JOIN {$this->fighters_table} f ON f.id=p.fighter_id WHERE p.bout_id IN ($ids) ORDER BY p.bout_id,p.id",ARRAY_A);
			if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
			$grouped=[]; foreach ($participants as $row) { $grouped[(int)$row['bout_id']][]=$row; }
            foreach ($bouts as &$bout) { $bout['_participants']=$grouped[(int)$bout['id']]??[]; } unset($bout);
            return $bouts;
    }
    public function bout_stream(int $size=250): \Generator {
        $last=0;
        do { $batch=$this->bout_batch($last,$size); foreach($batch as $bout){$last=(int)$bout['id'];yield $bout;} } while(count($batch)===$size);
    }

	public function history_for_fighters(array $ids): array {
		global $wpdb; $in=implode(',',array_map('intval',$ids));
		if (!$in) { return []; }
		$rows=$wpdb->get_results("SELECT p.*,b.id,b.bout_order,b.status,b.deleted_soft,b.method_category,b.result_type,b.is_scoring_candidate,e.id AS event_exists,e.event_date FROM {$this->participants_table} p JOIN {$this->bouts_table} b ON b.id=p.bout_id LEFT JOIN {$this->events_table} e ON e.id=b.event_id AND e.deleted_soft=0 WHERE p.fighter_id IN ($in) ORDER BY p.fighter_id,b.id",ARRAY_A);
		if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
		if (!$rows) { return []; }
		// Aggregate once for this batch. Correlated counts can cause repeated full
		// scans when the optimizer estimates a large IN selection inaccurately.
		$bout_ids=implode(',',array_unique(array_map('intval',array_column($rows,'id'))));
		$counts=$wpdb->get_results("SELECT p.bout_id,COUNT(*) AS participant_count,SUM(p.result_for_fighter IN ('win','loss','draw','no_contest')) AS countable_participants,COUNT(DISTINCT p.fighter_id)=2 AND ((SUM(p.result_for_fighter='win')=1 AND SUM(p.result_for_fighter='loss')=1) OR SUM(p.result_for_fighter='draw')=2 OR SUM(p.result_for_fighter='no_contest')=2) AS reciprocal_results,SUM(EXISTS(SELECT 1 FROM {$this->participants_table} other WHERE other.bout_id=p.bout_id AND other.fighter_id=p.opponent_fighter_id AND other.opponent_fighter_id=p.fighter_id AND other.fighter_id<>p.fighter_id))=2 AS reciprocal_identities FROM {$this->participants_table} p WHERE p.bout_id IN ($bout_ids) GROUP BY p.bout_id",ARRAY_A);
		if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
		$by_bout=[]; foreach ($counts as $count) { $by_bout[(int)$count['bout_id']]=$count; }
		foreach ($rows as &$row) {
			$count=$by_bout[(int)$row['id']]??[];
			$row['participant_count']=(int)($count['participant_count']??0);
			$row['countable_participants']=(int)($count['countable_participants']??0);
			$row['reciprocal_results']=(int)($count['reciprocal_results']??0);
			$row['reciprocal_identities']=(int)($count['reciprocal_identities']??0);
		} unset($row);
		return $rows;
	}
}
