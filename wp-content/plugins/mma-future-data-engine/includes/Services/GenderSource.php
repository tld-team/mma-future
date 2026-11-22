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
