<?php
namespace MMAF\DataEngine\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sanitizer {
	public const FIGHTER_STATUSES = array(
		'provisional',
		'verified',
		'merged',
		'hidden',
		'retired',
		'deleted_soft',
	);

	public const RANKABILITY_STATUSES = array(
		'rankable',
		'ineligible_age',
		'ineligible_inactive',
		'ineligible_ufc',
		'ineligible_too_many_fights',
		'ineligible_loss_limit',
		'insufficient_data',
		'pending_review',
		'not_public',
	);

	public const GENDERS = array(
		'',
		'male',
		'female',
	);

	public const WEIGHT_CLASSES = array(
		'unknown',
		'flyweight',
		'bantamweight',
		'featherweight',
		'lightweight',
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
		'women_strawweight',
		'women_flyweight',
		'women_bantamweight',
		'women_featherweight',
	);

	public const SOURCE_TYPES = array(
		'manual',
		'tapology',
		'sherdog',
		'ufcstats',
		'other',
	);

	public static function normalize_name( string $value ): string {
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$value = trim( (string) $value );

		return preg_replace( '/\s+/', ' ', $value );
	}

	public static function text_or_null( $value ): ?string {
		$value = sanitize_text_field( wp_unslash( $value ) );

		return '' === $value ? null : $value;
	}

	public static function bool_int( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	public static function valid_date_or_null( $value ): ?string {
		$value = self::text_or_null( $value );

		if ( null === $value ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		$parts = explode( '-', $value );

		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : null;
	}

	public static function valid_year_or_null( $value ): ?int {
		$value = self::text_or_null( $value );

		if ( null === $value ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}$/', $value ) ) {
			return null;
		}

		$year         = (int) $value;
		$current_year = (int) gmdate( 'Y' );

		if ( $year < 1900 || $year > $current_year + 1 ) {
			return null;
		}

		return $year;
	}

	public static function fighter_status( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::FIGHTER_STATUSES, true ) ? $value : 'provisional';
	}

	public static function rankability_status( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::RANKABILITY_STATUSES, true ) ? $value : 'pending_review';
	}

	public static function gender( $value ): ?string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::GENDERS, true ) && '' !== $value ? $value : null;
	}

	public static function weight_class( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::WEIGHT_CLASSES, true ) ? $value : 'unknown';
	}

	public static function source_type( $value ): string {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, self::SOURCE_TYPES, true ) ? $value : 'manual';
	}

	public static function int_or_null( $value ): ?int {
		$value = self::text_or_null( $value );

		if ( null === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
