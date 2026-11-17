<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The database chose this transaction as a deadlock victim; nothing was written and it is safe to run it again. */
final class Deadlocked extends \RuntimeException {}
