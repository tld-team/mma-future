<?php
namespace MMAF\DataEngine\Services;

use MMAF\DataEngine\Services\Import\GenderInferenceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Says where a fighter's stored gender comes from, from its field provenance. */
final class GenderSource {
	public const NONE         = 'none';
	public const MANUAL       = 'manual';
	public const CONFIRMED    = 'confirmed';
	public const OPPONENTS    = 'opponents';
	public const WEIGHT_CLASS = 'weight_class';
	public const UNKNOWN      = 'unknown_origin';

	private const MANUAL_SOURCE_TYPES = array( 'manual_verified', 'manual_admin', 'admin_override', 'admin_verified', 'admin_protected' );

	public const FILTERS = array(
		'confirmed' => 'Confirmed by Tapology',
		'inferred'  => 'Inferred, unconfirmed',
		'manual'    => 'Set manually',
		'conflict'  => 'Conflicts with Tapology',
	);

	/**
	 * @param array $sources FieldProvenanceService::sources_for_value() rows.
	 * @return array{state:string,conflict:bool}
	 */
	public static function classify( array $sources, string $current ): array {
		$current = strtolower( trim( $current ) );
		if ( ! in_array( $current, array( 'male', 'female' ), true ) ) {
			return array( 'state' => self::NONE, 'conflict' => false );
		}

		$manual   = false;
		$backing  = array();
		$conflict = false;
		foreach ( $sources as $source ) {
			$type = (string) ( $source['source_type'] ?? '' );
			if ( 1 === (int) ( $source['locked'] ?? 0 ) || in_array( $type, self::MANUAL_SOURCE_TYPES, true ) ) {
				$manual = true;
			}
			if ( ! empty( $source['matches_current'] ) ) {
				$backing[ $type ] = true;
			} elseif ( GenderInferenceService::SOURCE_EXPLICIT === $type ) {
				$conflict = true;
			}
		}

		if ( $manual ) {
			$state = self::MANUAL;
		} elseif ( isset( $backing[ GenderInferenceService::SOURCE_EXPLICIT ] ) ) {
			$state = self::CONFIRMED;
		} elseif ( isset( $backing[ GenderInferenceService::SOURCE_OPPONENTS ] ) ) {
			$state = self::OPPONENTS;
		} elseif ( isset( $backing[ GenderInferenceService::SOURCE_WEIGHT_CLASS ] ) ) {
			$state = self::WEIGHT_CLASS;
		} else {
			$state = self::UNKNOWN;
		}

		return array( 'state' => $state, 'conflict' => $conflict );
	}

	public static function label( string $state ): string {
		$labels = array(
			self::NONE         => 'No gender set.',
			self::MANUAL       => 'Set manually. Imports never change it.',
			self::CONFIRMED    => 'Confirmed by the Tapology profile text (he/she, women\'s ranking).',
			self::OPPONENTS    => 'Inferred from opponents of a confirmed gender. Not yet confirmed by the Tapology profile.',
			self::WEIGHT_CLASS => 'Inferred from the weight class only. Not yet confirmed by the Tapology profile.',
			self::UNKNOWN      => 'Origin not recorded. Imports will not change it.',
		);

		return $labels[ $state ] ?? '';
	}

	/**
	 * SQL condition: the fighter at `$alias` has a gender a person set or the Tapology profile confirms.
	 * Only such genders may be used as evidence about someone else.
	 */
	public static function trusted_sql( string $alias, string $provenance_table ): string {
		$alias = preg_replace( '/[^a-z_]/', '', $alias );

		return "{$alias}.gender IN ('male','female') AND EXISTS (SELECT 1 FROM {$provenance_table} tp WHERE tp.entity_type = 'fighter' AND tp.entity_id = {$alias}.id AND tp.field_name = 'gender'"
			. " AND (tp.locked = 1 OR tp.source_type IN ('" . implode( "','", self::MANUAL_SOURCE_TYPES ) . "')"
			. " OR (tp.source_type = '" . GenderInferenceService::SOURCE_EXPLICIT . "' AND tp.value_hash = SHA2(LOWER(TRIM({$alias}.gender)), 256))))";
	}

	public const TARGETS_SCHEMA_VERSION = 'mmaf_gender_targets_v1';
	public const TARGET_STATUSES        = array( 'provisional', 'verified' );

	/**
	 * Fighters whose gender only a fresh look at the Tapology profile can settle, for the scraper's
	 * gender queue: those without a gender, and women whose gender was only inferred.
	 * A gender an admin set or cleared is theirs, and a conflict needs a person, so neither is listed.
	 *
	 * @param string[] $statuses Fighter statuses to include.
	 */
	public static function export_targets( array $statuses = self::TARGET_STATUSES, bool $tracked_only = true ): array {
		global $wpdb;

		$tables   = \MMAF\DataEngine\Migrations\Schema::table_names();
		$statuses = array_values( array_intersect( array_map( 'strval', $statuses ), \MMAF\DataEngine\Support\Sanitizer::FIGHTER_STATUSES ) ) ?: self::TARGET_STATUSES;
		$manual   = "EXISTS (SELECT 1 FROM {$tables['field_provenance']} gp WHERE gp.entity_type = 'fighter' AND gp.entity_id = f.id AND gp.field_name = 'gender' AND (gp.locked = 1 OR gp.source_type IN ('" . implode( "','", self::MANUAL_SOURCE_TYPES ) . "')))";
		$missing  = "(f.gender IS NULL OR f.gender IN ('','unknown')) AND NOT {$manual}";
		$inferred = "f.gender = 'female' AND (" . self::filter_sql( 'inferred', $tables['field_provenance'] ) . ')';
		$where    = "f.deleted_soft = 0 AND f.status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')" . ( $tracked_only ? " AND f.tracking_mode = 'normal'" : '' );

		$rows = $wpdb->get_results(
			"SELECT f.id, f.display_name, f.status, f.tracking_mode, f.gender, f.weight_class, s.source_fighter_id, s.source_url,
				CASE WHEN {$missing} THEN 'missing' ELSE 'inferred_female' END AS reason
			FROM {$tables['fighters']} f
			LEFT JOIN {$tables['fighter_sources']} s ON s.id = (SELECT s2.id FROM {$tables['fighter_sources']} s2 WHERE s2.fighter_id = f.id AND s2.source_type = 'tapology' ORDER BY s2.is_primary DESC, s2.is_verified DESC, s2.id ASC LIMIT 1)
			WHERE {$where} AND (({$missing}) OR ({$inferred}))
			ORDER BY f.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();

		$counts = array( 'missing' => 0, 'inferred_female' => 0, 'without_tapology_source' => 0 );
		$items  = array();
		foreach ( $rows as $row ) {
			++$counts[ $row['reason'] ];
			if ( '' === (string) $row['source_url'] && '' === (string) $row['source_fighter_id'] ) {
				++$counts['without_tapology_source'];
			}
			$items[] = array(
				'fighter_id'        => (int) $row['id'],
				'display_name'      => $row['display_name'],
				'status'            => $row['status'],
				'tracking_mode'     => $row['tracking_mode'],
				'gender'            => $row['gender'],
				'weight_class'      => $row['weight_class'],
				'reason'            => $row['reason'],
				'source_fighter_id' => $row['source_fighter_id'],
				'source_url'        => $row['source_url'],
			);
		}

		return array(
			'schema_version' => self::TARGETS_SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'statuses'       => $statuses,
			'tracked_only'   => $tracked_only,
			'counts'         => $counts,
			'fighters'       => $items,
		);
	}

	/** WHERE fragment over the fighters alias `f` for one of the FILTERS keys; null for an unknown key. */
	public static function filter_sql( string $filter, string $provenance_table ): ?string {
		$has_gender = "f.gender IN ('male','female')";
		$row        = "FROM {$provenance_table} gp WHERE gp.entity_type = 'fighter' AND gp.entity_id = f.id AND gp.field_name = 'gender'";
		$matches    = 'gp.value_hash = SHA2(LOWER(TRIM(f.gender)), 256)';
		$manual     = "EXISTS (SELECT 1 {$row} AND (gp.locked = 1 OR gp.source_type IN ('" . implode( "','", self::MANUAL_SOURCE_TYPES ) . "')))";
		$explicit   = "gp.source_type = '" . GenderInferenceService::SOURCE_EXPLICIT . "'";
		$inferred   = "gp.source_type IN ('" . implode( "','", GenderInferenceService::INFERRED_SOURCE_TYPES ) . "')";

		switch ( $filter ) {
			case 'manual':
				return "{$has_gender} AND {$manual}";
			case 'confirmed':
				return "{$has_gender} AND NOT {$manual} AND EXISTS (SELECT 1 {$row} AND {$explicit} AND {$matches})";
			case 'inferred':
				return "{$has_gender} AND NOT {$manual} AND NOT EXISTS (SELECT 1 {$row} AND {$explicit} AND {$matches}) AND EXISTS (SELECT 1 {$row} AND {$inferred} AND {$matches})";
			case 'conflict':
				return "{$has_gender} AND EXISTS (SELECT 1 {$row} AND {$explicit} AND NOT {$matches})";
		}

		return null;
	}
}
