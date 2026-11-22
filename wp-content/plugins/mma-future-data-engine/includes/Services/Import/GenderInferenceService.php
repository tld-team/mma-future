<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Support\WeightClassResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides a fighter's gender from a scraped Tapology profile.
 *
 * Sources, strongest first:
 *  1. The pronoun and women's-ranking markers Tapology prints in the fighter's regional ranking text.
 *  2. A weight class that only men fight at (welterweight and above).
 *
 * A weight class never yields "female": Tapology lists men at Strawweight as well.
 */
final class GenderInferenceService {
	public const SOURCE_EXPLICIT     = 'tapology_profile_explicit_gender';
	public const SOURCE_WEIGHT_CLASS = 'tapology_profile_inferred_weight_class';
	public const SOURCE_OPPONENTS    = 'inferred_opponent_gender';

	/** Provenance source types whose value a stronger source may correct. */
	public const INFERRED_SOURCE_TYPES = array(
		self::SOURCE_WEIGHT_CLASS,
		self::SOURCE_OPPONENTS,
	);

	private const MALE_ONLY_WEIGHT_CLASSES = array(
		'welterweight',
		'middleweight',
		'light_heavyweight',
		'heavyweight',
		'super_heavyweight',
		'super_heavy',
	);

	/** @param array $summary The scraped profile_summary. */
	public function infer( array $summary ): array {
		$explicit = $this->explicit_from_summary( $summary );
		if ( null !== $explicit['gender'] ) {
			return $explicit;
		}

		$weight_class = $summary['weight_class'] ?? null;
		$fallback     = $this->infer_from_weight_class( is_string( $weight_class ) ? $weight_class : null );
		if ( '' !== $explicit['warning'] ) {
			// Contradictory page evidence must reach a person; a weight class must not paper over it.
			return $this->none( $explicit['warning'] );
		}

		return $fallback;
	}

	/** Gender stated by the page itself; requires the evidence to agree with the stated value. */
	public function explicit_from_summary( array $summary ): array {
		$gender   = isset( $summary['gender'] ) && is_string( $summary['gender'] ) ? strtolower( trim( $summary['gender'] ) ) : '';
		$evidence = isset( $summary['gender_evidence'] ) && is_array( $summary['gender_evidence'] ) ? $summary['gender_evidence'] : array();

		$pronoun       = isset( $evidence['pronoun'] ) && is_string( $evidence['pronoun'] ) ? strtolower( trim( $evidence['pronoun'] ) ) : '';
		$women_markers = ! empty( $evidence['women_p4p_ranking'] ) || ! empty( $evidence['female_grouping_note'] );
		$status        = isset( $evidence['status'] ) && is_string( $evidence['status'] ) ? $evidence['status'] : '';

		if ( 'conflict' === $status ) {
			return $this->none( 'gender_evidence_conflict' );
		}

		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			return $this->none();
		}

		$consistent = 'female' === $gender
			? ( 'he' !== $pronoun && ( 'she' === $pronoun || $women_markers ) )
			: ( 'he' === $pronoun && ! $women_markers );
		if ( ! $consistent ) {
			return $this->none( 'gender_evidence_inconsistent' );
		}

		$excerpt = isset( $evidence['excerpt'] ) && is_string( $evidence['excerpt'] ) ? trim( $evidence['excerpt'] ) : '';

		return array(
			'gender'                 => $gender,
			'source'                 => 'explicit_profile_ranking_text',
			'confidence'             => 'high',
			'warning'                => '',
			'provenance_source_type' => self::SOURCE_EXPLICIT,
			'evidence_excerpt'       => function_exists( 'mb_substr' ) ? mb_substr( $excerpt, 0, 240 ) : substr( $excerpt, 0, 240 ),
		);
	}

	public function infer_from_weight_class( ?string $weight_class ): array {
		$normalized = WeightClassResolver::normalize( (string) $weight_class );
		if ( '' === $normalized ) {
			return $this->none();
		}

		if ( 0 === strpos( $normalized, 'women_' ) ) {
			return $this->inferred( 'female', 'explicit_women_weight_class', 'high' );
		}

		if ( 1 === preg_match( '/^(men|mens|male)_/', $normalized ) ) {
			return $this->inferred( 'male', 'explicit_men_weight_class', 'high' );
		}

		if ( in_array( $normalized, self::MALE_ONLY_WEIGHT_CLASSES, true ) ) {
			return $this->inferred( 'male', 'male_only_supported_weight_class', 'medium' );
		}

		return $this->none();
	}

	public static function is_inferred_source_type( string $source_type ): bool {
		return in_array( $source_type, self::INFERRED_SOURCE_TYPES, true );
	}

	private function inferred( string $gender, string $source, string $confidence ): array {
		return array(
			'gender'                 => $gender,
			'source'                 => $source,
			'confidence'             => $confidence,
			'warning'                => '',
			'provenance_source_type' => self::SOURCE_WEIGHT_CLASS,
			'evidence_excerpt'       => '',
		);
	}

	private function none( string $warning = '' ): array {
		return array(
			'gender'                 => null,
			'source'                 => '',
			'confidence'             => '',
			'warning'                => $warning,
			'provenance_source_type' => '',
			'evidence_excerpt'       => '',
		);
	}
}
