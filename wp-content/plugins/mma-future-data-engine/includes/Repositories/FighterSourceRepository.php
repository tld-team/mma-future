<?php
namespace MMAF\DataEngine\Repositories;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Support\DateTime;
use MMAF\DataEngine\Support\TapologyFighterUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterSourceRepository {
	private string $table;

	public function __construct() {
		$tables      = Schema::table_names();
		$this->table = $tables['fighter_sources'];
	}

	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_source( string $source_type, ?string $source_fighter_id ): ?array {
		global $wpdb;

		if ( null === $source_fighter_id || '' === $source_fighter_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_type = %s AND source_fighter_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_type,
				$source_fighter_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find_by_normalized_source_url( string $source_type, string $source_url ): array {
		global $wpdb;

		$normalized_url = TapologyFighterUrl::normalize( $source_url );
		if ( '' === $normalized_url ) {
			return array();
		}

		$identity_hash = $this->identity_hash_for_source( $source_type, null, $normalized_url );
		if ( null !== $identity_hash ) {
			$hash_rows = $this->find_by_identity_hash( $source_type, $identity_hash );
			if ( ! empty( $hash_rows ) ) {
				return $hash_rows;
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_type = %s AND source_url IS NOT NULL AND source_url <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_type
			),
			ARRAY_A
		);

		$matches = array();
		foreach ( (array) $rows as $row ) {
			if ( TapologyFighterUrl::normalize( (string) ( $row['source_url'] ?? '' ) ) === $normalized_url ) {
				$matches[] = $row;
			}
		}

		return $matches;
	}

	public function find_by_identity_hash( string $source_type, ?string $identity_hash ): array {
		global $wpdb;

		if ( null === $identity_hash || '' === $identity_hash ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_type = %s AND identity_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_type,
				$identity_hash
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function identity_hash_for_source( string $source_type, ?string $source_fighter_id, ?string $source_url ): ?string {
		if ( 'tapology' === $source_type && null !== $source_url && '' !== $source_url ) {
			$source_url_hash = TapologyFighterUrl::source_url_hash( $source_url );
			if ( null !== $source_url_hash ) {
				return $source_url_hash;
			}
		}

		$basis = $source_type . '|' . ( $source_fighter_id ?? '' ) . '|' . ( $source_url ?? '' );
		return '' === trim( $basis, '|' ) ? null : hash( 'sha256', $basis );
	}

	public function find_for_fighter( int $fighter_id, string $source_type = 'tapology' ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE fighter_id = %d AND source_type = %s ORDER BY is_primary DESC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fighter_id,
				$source_type
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find_first_for_fighter( int $fighter_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE fighter_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1", $fighter_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	public function upsert_for_fighter( int $fighter_id, array $data ): bool {
		global $wpdb;

		$source_type = (string) ( $data['source_type'] ?? '' );
		if ( 'tapology' === $source_type && ! empty( $data['source_url'] ) ) {
			$tapology = TapologyFighterUrl::parse( (string) $data['source_url'] );
			if ( $tapology ) {
				$data['source_fighter_id'] = $tapology['source_fighter_id'];
				$data['source_numeric_id'] = $tapology['source_numeric_id'];
				$data['source_slug']       = $data['source_slug'] ?? $tapology['source_slug'];
				$data['source_url']        = $tapology['canonical_url'];
			}
		}

		$existing = $this->find_by_source( $source_type, $data['source_fighter_id'] ?? null );
		if ( ! $existing ) {
			$identity_hash = $this->identity_hash_for_source( $source_type, $data['source_fighter_id'] ?? null, $data['source_url'] ?? null );
			$hash_matches  = $this->find_by_identity_hash( $source_type, $identity_hash );
			if ( ! empty( $hash_matches ) ) {
				$existing = $hash_matches[0];
			}
		}
		if ( ! $existing ) {
			$existing = $this->find_for_fighter( $fighter_id, $source_type );
		}

		$now = DateTime::mysql_now();

		$data['fighter_id']     = $fighter_id;
		$data['identity_hash']  = $this->identity_hash_for_source( $source_type, $data['source_fighter_id'] ?? null, $data['source_url'] ?? null );

		if ( $existing ) {
			$data['updated_at'] = $now;

			return false !== $wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				null,
				array( '%d' )
			);
		}

		$data['created_at'] = $now;
		$data['updated_at'] = null;

		return false !== $wpdb->insert( $this->table, $data );
	}
}
