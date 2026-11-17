<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The reader's copy is older than what is stored: the same list was saved from another tab or device in the meantime. */
final class Conflict extends \RuntimeException {}
