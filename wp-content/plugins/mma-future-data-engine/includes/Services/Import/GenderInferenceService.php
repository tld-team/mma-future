<?php
namespace MMAF\DataEngine\Services\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GenderInferenceService {
	public function infer_from_weight_class( ?string $weight_class ): array {
		$raw = trim( (string) $weight_class );
		if ( '' === $raw ) {
			return array(
				'gender'     => null,
				'source'     => '',
				'confidence' => '',
				'warning'    => '',
			);
		}

		$normalized = $this->normalize_weight_class( $raw );
		if ( false !== strpos( $normalized, 'women' ) || 0 === strpos( $normalized, 'women_' ) ) {
			return $this->high_confidence( 'female', 'explicit_women_weight_class' );
		}

		if ( 0 === strpos( $normalized, 'men_' ) || false !== strpos( $normalized, 'mens_' ) || false !== strpos( $normalized, 'male_' ) ) {
			return $this->high_confidence( 'male', 'explicit_men_weight_class' );
		}

		if ( 'strawweight' === $normalized ) {
			return $this->high_confidence( 'female', 'women_only_supported_weight_class' );
		}

		if ( in_array( $normalized, array( 'lightweight', 'welterweight', 'middleweight', 'light_heavyweight', 'heavyweight' ), true ) ) {
			return $this->high_confidence( 'male', 'male_only_supported_weight_class' );
		}

		return array(
			'gender'     => null,
			'source'     => '',
			'confidence' => '',
			'warning'    => '',
		);
	}

	private function high_confidence( string $gender, string $source ): array {
		return array(
			'gender'     => $gender,
			'source'     => $source,
			'confidence' => 'high',
			'warning'    => '',
		);
	}

	private function normalize_weight_class( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace(
			array( "women's", 'womens', 'women ', "men's", 'mens', 'men ', 'male ' ),
			array( 'women ', 'women ', 'women ', 'men ', 'men ', 'men ', 'male ' ),
			$value
		);
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( (string) $value, '_' );
		if ( 'light_heavy' === $value ) {
			return 'light_heavyweight';
		}

		return $value;
	}
}
