<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Input a reader can correct. Each message belongs to one form field, so the page can show it beside that field. */
final class ValidationFailed extends \RuntimeException {
	/** @var array<string,string> */
	private array $fields;

	/** @param array<string,string> $fields Field name => message for the reader. */
	public function __construct( array $fields ) {
		$this->fields = $fields;
		parent::__construct( (string) ( reset( $fields ) ?: 'Check the highlighted fields.' ) );
	}

	/** @return array<string,string> */
	public function fields(): array {
		return $this->fields;
	}
}
