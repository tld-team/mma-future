<?php
namespace MMAF\DataEngine\Services\Community;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Per-reader limits. Keyed on the account, never the address: readers behind one network share an IP. */
final class RateLimit {
	public static function allow( string $bucket, int $user_id, int $max, int $seconds ): bool {
		$key   = 'mmafc_rl_' . $bucket . '_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= $max ) { return false; }
		set_transient( $key, $count + 1, $seconds );
		return true;
	}
}
