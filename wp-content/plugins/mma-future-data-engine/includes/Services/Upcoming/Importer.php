<?php
namespace MMAF\DataEngine\Services\Upcoming;

use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Importer {
	public const CONTRACT = 'mmaf_upcoming_v1';
	private Repository $repository;
	private array $tables;
	private array $fighter_cache = array();

	public function __construct() {
		$this->repository = new Repository();
		$this->tables = Schema::tables();
	}

	public function inspect_zip( string $path ): array {
		if ( ! is_file( $path ) || filesize( $path ) > 25 * MB_IN_BYTES ) {
			throw new \RuntimeException( 'Paket ne postoji ili je veći od 25 MB.' );
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'ZIP podrška nije dostupna.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( 'ZIP paket nije moguće otvoriti.' );
		}
		try {
			$allowed = array( 'latest/package_manifest.json', 'latest/upcoming.json', 'latest/run_manifest.json' );
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( str_contains( $name, '..' ) || str_starts_with( $name, '/' ) || ! in_array( $name, $allowed, true ) ) {
					throw new \RuntimeException( 'Paket sadrži nedozvoljenu putanju: ' . $name );
				}
			}
			$manifest_raw = $zip->getFromName( 'latest/package_manifest.json' );
			$document_raw = $zip->getFromName( 'latest/upcoming.json' );
			$run_raw = $zip->getFromName( 'latest/run_manifest.json' );
			if ( false === $manifest_raw || false === $document_raw || false === $run_raw ) {
				throw new \RuntimeException( 'Paket nema obavezne fajlove.' );
			}
			$manifest = json_decode( $manifest_raw, true, 128, JSON_THROW_ON_ERROR );
			$document = json_decode( $document_raw, true, 512, JSON_THROW_ON_ERROR );
			$run = json_decode( $run_raw, true, 128, JSON_THROW_ON_ERROR );
			if ( 'mmaf-upcoming-bundle' !== ( $manifest['format'] ?? '' ) || 'upcoming' !== ( $manifest['mode'] ?? '' ) || self::CONTRACT !== ( $manifest['contract'] ?? '' ) ) {
				throw new \RuntimeException( 'Paket nije podržani upcoming format.' );
			}
			foreach ( array( 'upcoming.json' => $document_raw, 'run_manifest.json' => $run_raw ) as $name => $contents ) {
				$expected = (string) ( $manifest['files'][ $name ]['sha256'] ?? '' );
				if ( ! $expected || ! hash_equals( $expected, hash( 'sha256', $contents ) ) ) {
					throw new \RuntimeException( 'Checksum nije ispravan za ' . $name . '.' );
				}
			}
			$this->validate_document( $document );
			if ( (string) ( $run['run_id'] ?? '' ) !== (string) ( $document['run_id'] ?? '' ) ) {
				throw new \RuntimeException( 'Run manifest i podaci nemaju isti run ID.' );
			}
			return array(
				'bundle_hash' => hash_file( 'sha256', $path ),
				'manifest'    => $manifest,
				'document'    => $document,
				'run'         => $run,
			);
		} catch ( \JsonException $error ) {
			throw new \RuntimeException( 'Paket sadrži neispravan JSON: ' . $error->getMessage() );
		} finally {
			$zip->close();
		}
	}

	public function preview( array $package ): array {
		global $wpdb;
		$summary = array(
			'events' => array( 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'stale' => 0, 'review' => 0 ),
			'bouts'  => array( 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'review' => 0 ),
			'protected_fields' => 0,
			'possible_duplicates' => array(),
		);
		foreach ( $package['document']['events'] as $event ) {
			$existing = $this->repository->find_by_stable_id( (string) $event['stable_id'] );
			if ( ! $existing ) {
				$summary['events']['new']++;
				$similar = $wpdb->get_row(
					$wpdb->prepare( "SELECT id,stable_id,title,event_date FROM {$this->tables['events']} WHERE event_date=%s AND title=%s LIMIT 1", $event['date'], $event['title'] ),
					ARRAY_A
				);
				if ( $similar ) {
					$summary['possible_duplicates'][] = array( 'incoming' => $event['stable_id'], 'existing' => $similar );
				}
			} elseif ( $this->is_stale( $event, $existing ) ) {
				$summary['events']['stale']++;
			} elseif ( hash( 'sha256', wp_json_encode( $event ) ) === hash( 'sha256', (string) $existing['source_json'] ) ) {
				$summary['events']['unchanged']++;
			} else {
				$summary['events']['changed']++;
			}
			$summary['protected_fields'] += count( $this->decode( $existing['overrides_json'] ?? null ) );
			if ( empty( $event['full_card_read'] ) || empty( $event['bouts'] ) ) {
				$summary['events']['review']++;
			}
			$existing_bouts = $existing ? $this->repository->bouts_for_events( array( (int) $existing['id'] ) )[ (int) $existing['id'] ] ?? array() : array();
			$by_id = array_column( $existing_bouts, null, 'stable_id' );
			foreach ( $event['bouts'] as $bout ) {
				$old = $by_id[ $bout['stable_id'] ] ?? null;
				if ( ! $old ) {
					$summary['bouts']['new']++;
				} elseif ( hash( 'sha256', wp_json_encode( $bout ) ) === hash( 'sha256', (string) $old['source_json'] ) ) {
					$summary['bouts']['unchanged']++;
				} else {
					$summary['bouts']['changed']++;
				}
				if ( 'confirmed' !== ( $bout['scope_status'] ?? '' ) ) {
					$summary['bouts']['review']++;
				}
			}
		}
		return $summary;
	}

	public function apply( array $package, int $actor_user_id ): array {
		global $wpdb;
		$existing_import = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['imports']} WHERE bundle_hash=%s", $package['bundle_hash'] ), ARRAY_A );
		if ( $existing_import && 'completed' === $existing_import['status'] ) {
			return array( 'idempotent' => true, 'import_id' => (int) $existing_import['id'], 'summary' => $this->decode( $existing_import['summary_json'] ) );
		}
		$coverage = $package['document']['coverage'];
		$wpdb->query( 'START TRANSACTION' );
		try {
			$now = gmdate( 'Y-m-d H:i:s' );
			if ( $existing_import ) {
				$import_id = (int) $existing_import['id'];
				$this->checked_update( $this->tables['imports'], array( 'status' => 'importing', 'created_by' => $actor_user_id, 'summary_json' => null, 'finished_at' => null ), array( 'id' => $import_id ) );
			} else {
				$this->checked_insert(
					$this->tables['imports'],
					array(
						'bundle_hash' => $package['bundle_hash'],
						'run_id' => (string) $package['document']['run_id'],
						'status' => 'importing',
						'coverage_from' => $coverage['from'],
						'coverage_to' => $coverage['to'],
						'coverage_timezone' => $coverage['timezone'],
						'coverage_complete' => ! empty( $coverage['complete'] ) ? 1 : 0,
						'manifest_json' => wp_json_encode( $package['manifest'] ),
						'created_by' => $actor_user_id,
						'created_at' => $now,
					)
				);
				$import_id = (int) $wpdb->insert_id;
			}
			$summary = array( 'events_created' => 0, 'events_updated' => 0, 'events_stale' => 0, 'bouts_created' => 0, 'bouts_updated' => 0, 'bouts_withdrawn' => 0 );
			foreach ( $package['document']['events'] as $event ) {
				$this->apply_event( $event, $import_id, $actor_user_id, $summary );
			}
			$this->checked_update(
				$this->tables['imports'],
				array( 'status' => 'completed', 'summary_json' => wp_json_encode( $summary ), 'finished_at' => $now ),
				array( 'id' => $import_id )
			);
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( $wpdb->last_error );
			}
			$wpdb->query( 'COMMIT' );
			$this->repository->bump_revision();
			return array( 'idempotent' => false, 'import_id' => $import_id, 'summary' => $summary );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function apply_event( array $source, int $import_id, int $actor, array &$summary ): void {
		global $wpdb;
		$existing = $this->repository->find_by_stable_id( (string) $source['stable_id'] );
		if ( $existing && $this->is_stale( $source, $existing ) ) {
			$summary['events_stale']++;
			return;
		}
		$overrides = $this->decode( $existing['overrides_json'] ?? null );
		$source_values = array(
			'title' => $source['title'], 'promotion' => $source['promotion'] ?? null, 'event_date' => $source['date'],
			'event_datetime_utc' => $this->mysql_datetime( $source['start_utc'] ?? null ), 'source_time_text' => $source['source_time_text'] ?? null,
			'source_timezone' => $source['source_timezone'] ?? null, 'time_note' => $source['time_note'] ?? null,
			'venue' => $source['venue'] ?? null, 'location' => $source['location'] ?? null, 'status' => $source['status'] ?? 'scheduled',
		);
		$values = $this->effective_values( $source_values, $overrides );
		$status = in_array( $values['status'], array( 'cancelled', 'postponed' ), true ) ? $values['status'] : 'published';
		$row = array_merge(
			$values,
			array(
				'stable_id' => sanitize_text_field( $source['stable_id'] ), 'source_type' => 'tapology',
				'source_event_id' => $source['source_event_id'] ?? null, 'source_url' => esc_url_raw( $source['source_url'] ?? '' ) ?: null,
				'status' => $status, 'is_manual' => 0, 'source_json' => wp_json_encode( $source ), 'last_import_id' => $import_id,
				'source_observed_at' => $this->mysql_datetime( $source['source_observed_at'] ?? null ) ?: gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$before = $existing;
		if ( $existing ) {
			$this->checked_update( $this->tables['events'], $row, array( 'id' => (int) $existing['id'] ) );
			$event_id = (int) $existing['id'];
			$summary['events_updated']++;
		} else {
			$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$row['scope_confirmed'] = 0;
			$row['hidden'] = 0;
			$this->checked_insert( $this->tables['events'], $row );
			$event_id = (int) $wpdb->insert_id;
			$summary['events_created']++;
		}
		foreach ( $source['bouts'] as $bout ) {
			$this->apply_bout( $bout, $event_id, $import_id, $summary );
		}
		if ( ! empty( $source['full_card_read'] ) ) {
			$withdrawn = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tables['bouts']} SET hidden=1,status='hidden',updated_at=%s WHERE event_id=%d AND is_manual=0 AND (last_import_id IS NULL OR last_import_id<>%d)", gmdate( 'Y-m-d H:i:s' ), $event_id, $import_id ) );
			if ( false === $withdrawn ) {
				throw new \RuntimeException( $wpdb->last_error ?: 'Nije moguće povući nestale parove.' );
			}
			$summary['bouts_withdrawn'] += (int) $withdrawn;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->tables['bouts']} WHERE event_id=%d AND hidden=0 AND scope_status='confirmed' AND status NOT IN ('cancelled','hidden')", $event_id ) );
		$scope = $count > 0 ? 1 : 0;
		$final_status = $scope ? $status : ( in_array( $status, array( 'cancelled', 'postponed' ), true ) ? $status : 'draft' );
		$this->checked_update( $this->tables['events'], array( 'scope_confirmed' => $scope, 'status' => $final_status ), array( 'id' => $event_id ) );
		$this->audit( 'event', $event_id, $existing ? 'source_update' : 'source_create', $before, $this->repository->find_event( $event_id ), $import_id, $actor );
	}

	private function apply_bout( array $source, int $event_id, int $import_id, array &$summary ): void {
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables['bouts']} WHERE stable_id=%s", $source['stable_id'] ), ARRAY_A );
		$overrides = $this->decode( $existing['overrides_json'] ?? null );
		$a = $source['fighter_a'];
		$b = $source['fighter_b'];
		$source_values = array(
			'bout_order' => (int) $source['order'], 'weight_class' => $source['weight_class'] ?? null, 'status' => $source['status'] ?? 'scheduled',
			'scope_status' => $source['scope_status'], 'scope_evidence' => $source['scope_evidence'] ?? null,
			'fighter_a_name' => $a['name'], 'fighter_a_source_id' => $a['source_fighter_id'] ?? null, 'fighter_a_url' => $a['source_url'] ?? null,
			'fighter_b_name' => $b['name'], 'fighter_b_source_id' => $b['source_fighter_id'] ?? null, 'fighter_b_url' => $b['source_url'] ?? null,
		);
		$row = array_merge(
			$this->effective_values( $source_values, $overrides ),
			array(
				'event_id' => $event_id, 'stable_id' => sanitize_text_field( $source['stable_id'] ), 'source_bout_id' => $source['source_bout_id'] ?? null,
				'source_url' => esc_url_raw( $source['source_url'] ?? '' ) ?: null, 'fighter_a_id' => $this->fighter_id( $a['source_fighter_id'] ?? null ),
				'fighter_b_id' => $this->fighter_id( $b['source_fighter_id'] ?? null ), 'is_manual' => 0, 'hidden' => (int) ( $existing['hidden'] ?? 0 ),
				'source_json' => wp_json_encode( $source ), 'last_import_id' => $import_id, 'source_observed_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( $existing ) {
			$this->checked_update( $this->tables['bouts'], $row, array( 'id' => (int) $existing['id'] ) );
			$summary['bouts_updated']++;
		} else {
			$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->checked_insert( $this->tables['bouts'], $row );
			$summary['bouts_created']++;
		}
	}

	private function fighter_id( ?string $source_id ): ?int {
		global $wpdb;
		if ( ! $source_id ) {
			return null;
		}
		if ( array_key_exists( $source_id, $this->fighter_cache ) ) {
			return $this->fighter_cache[ $source_id ];
		}
		$table = CanonicalSchema::table_names()['fighter_sources'];
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT fighter_id FROM $table WHERE source_type='tapology' AND source_fighter_id=%s AND fighter_id IS NOT NULL ORDER BY is_verified DESC,is_primary DESC,id LIMIT 1", $source_id ) );
		$this->fighter_cache[ $source_id ] = $id ?: null;
		return $this->fighter_cache[ $source_id ];
	}

	private function validate_document( array $document ): void {
		if ( self::CONTRACT !== ( $document['schema_version'] ?? '' ) || 'tapology' !== ( $document['source'] ?? '' ) ) {
			throw new \RuntimeException( 'Nepodržan dokument najava.' );
		}
		$coverage = $document['coverage'] ?? array();
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $coverage['from'] ?? '' ) );
		$to = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $coverage['to'] ?? '' ) );
		if ( ! $from || ! $to || $to < $from || (int) $from->diff( $to )->days > 29 ) {
			throw new \RuntimeException( 'Period pokrivenosti mora biti između 1 i 30 dana.' );
		}
		try {
			new \DateTimeZone( (string) ( $coverage['timezone'] ?? '' ) );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Paket nema ispravnu IANA vremensku zonu.' );
		}
		$event_ids = array();
		$bout_ids = array();
		foreach ( $document['events'] ?? array() as $event ) {
			$id = (string) ( $event['stable_id'] ?? '' );
			if ( ! $id || isset( $event_ids[ $id ] ) || empty( $event['title'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $event['date'] ?? '' ) ) ) {
				throw new \RuntimeException( 'Događaj nema stabilan identitet, naziv ili datum.' );
			}
			$event_ids[ $id ] = true;
			foreach ( $event['bouts'] ?? array() as $bout ) {
				$bout_id = (string) ( $bout['stable_id'] ?? '' );
				if ( ! $bout_id || isset( $bout_ids[ $bout_id ] ) || 'confirmed' !== ( $bout['scope_status'] ?? '' ) || empty( $bout['fighter_a']['name'] ) || empty( $bout['fighter_b']['name'] ) ) {
					throw new \RuntimeException( 'Par nema stabilan identitet ili potvrdu profesionalnog MMA obuhvata.' );
				}
				$bout_ids[ $bout_id ] = true;
			}
		}
	}

	private function effective_values( array $source, array $overrides ): array {
		foreach ( $source as $field => $value ) {
			if ( isset( $overrides[ $field ] ) && ! empty( $overrides[ $field ]['active'] ) ) {
				$source[ $field ] = $overrides[ $field ]['value'] ?? null;
			}
		}
		return $source;
	}

	private function is_stale( array $source, array $existing ): bool {
		$incoming = strtotime( (string) ( $source['source_observed_at'] ?? '' ) );
		$current = strtotime( (string) ( $existing['source_observed_at'] ?? '' ) );
		return $incoming && $current && $incoming < $current;
	}

	private function mysql_datetime( ?string $value ): ?string {
		if ( ! $value ) {
			return null;
		}
		$time = strtotime( $value );
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null;
	}

	private function decode( ?string $json ): array {
		$value = $json ? json_decode( $json, true ) : array();
		return is_array( $value ) ? $value : array();
	}

	private function checked_insert( string $table, array $row ): void {
		global $wpdb;
		if ( false === $wpdb->insert( $table, $row ) ) {
			throw new \RuntimeException( $wpdb->last_error ?: 'Upis nije uspeo.' );
		}
	}

	private function checked_update( string $table, array $row, array $where ): void {
		global $wpdb;
		if ( false === $wpdb->update( $table, $row, $where ) ) {
			throw new \RuntimeException( $wpdb->last_error ?: 'Izmena nije uspela.' );
		}
	}

	private function audit( string $type, int $id, string $action, ?array $before, ?array $after, ?int $import, int $actor ): void {
		$this->checked_insert(
			$this->tables['audit'],
			array(
				'entity_type' => $type, 'entity_id' => $id, 'action' => $action,
				'before_json' => $before ? wp_json_encode( $before ) : null, 'after_json' => $after ? wp_json_encode( $after ) : null,
				'import_id' => $import, 'actor_user_id' => $actor, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
