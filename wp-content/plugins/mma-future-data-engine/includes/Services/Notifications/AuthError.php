<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A sign-in or registration problem the reader can act on. `field` names the form field it belongs to, when there is one. */
final class AuthError extends \RuntimeException {
	public string $field;
	public int $status;

	public function __construct( string $message, string $field = '', int $status = 400 ) {
		parent::__construct( $message );
		$this->field  = $field;
		$this->status = $status;
	}
}
