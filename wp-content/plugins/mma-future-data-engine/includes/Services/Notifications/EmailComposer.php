<?php
namespace MMAF\DataEngine\Services\Notifications;

use MMAF\DataEngine\REST\AbstractRestController;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Turns one user's due notification items into a single email.
 *
 * This class decides what the email says; templates/email/notification.php decides how it looks.
 */
final class EmailComposer {
	private const DEFAULT_NEW_NOTE = 'A new upcoming fight has been announced.';

	/**
	 * @param bool $preview Admin preview and test sends: no secure tokens are issued, action links point to the account page.
	 */
	public function compose( int $user_id, array $items, bool $preview = false ): array {
		$user     = get_userdata( $user_id );
		$email    = $user ? $user->user_email : null;
		$zone     = Config::timezone( $user_id );
		$repo     = new Repository();
		$followed = array_flip( $repo->active_fighter_ids( $user_id ) );
		$tokens   = new Tokens();
		$account  = home_url( '/account/' );

		$grouped = array();
		foreach ( $items as $item ) {
			$p         = json_decode( (string) $item['payload_json'], true ) ?: array();
			$p['kind'] = (string) ( $p['kind'] ?? $item['kind'] ?? '' );
			$event_key = (string) ( $p['event_id'] ?? 0 );
			$grouped[ $event_key ]['payload'] = $p;
			$grouped[ $event_key ]['bouts'][ (int) ( $p['bout_id'] ?? $item['bout_id'] ?? 0 ) ][] = $p;
		}

		$events = array(); $kinds = array(); $matchups = array(); $followed_names = array();
		foreach ( $grouped as $group ) {
			$e     = $group['payload'];
			$bouts = array();
			foreach ( $group['bouts'] as $bout_id => $versions ) {
				$p          = end( $versions );
				$bout_kinds = array_values( array_unique( array_map( static fn( array $v ): string => (string) $v['kind'], $versions ) ) );
				foreach ( $bout_kinds as $kind ) { $kinds[ $kind ] = true; }
				$a = $this->fighter( (int) ( $p['fighter_a_id'] ?? 0 ), (string) ( $p['fighter_a_name'] ?? '' ), $followed, $repo );
				$b = $this->fighter( (int) ( $p['fighter_b_id'] ?? 0 ), (string) ( $p['fighter_b_name'] ?? '' ), $followed, $repo );
				foreach ( array( $a, $b ) as $fighter ) { if ( $fighter['followed'] ) { $followed_names[ $fighter['name'] ] = true; } }
				$notes = array_values( array_unique( array_filter( array_map( static fn( array $v ): string => (string) ( $v['change_note'] ?? '' ), $versions ), static fn( string $note ): bool => '' !== $note && self::DEFAULT_NEW_NOTE !== $note ) ) );
				$remind_url = '';
				if ( ! in_array( 'reminder', $bout_kinds, true ) && $this->reminder_available( $p, $zone ) && ! $repo->has_active_reminder( $user_id, (int) $bout_id ) ) {
					$remind_url = $preview ? $account : $tokens->action_url( 'reminder', $tokens->issue( 'reminder', $user_id, $email, array( 'bout_id' => (int) $bout_id ), 45 * DAY_IN_SECONDS ) );
				}
				$matchups[] = $a['name'] . ' vs ' . $b['name'];
				$bouts[]    = array(
					'labels'       => array_map( array( self::class, 'kind_label' ), $bout_kinds ),
					'alert'        => (bool) array_intersect( $bout_kinds, array( 'cancelled', 'postponed' ) ),
					'a'            => $a,
					'b'            => $b,
					'weight_class' => (string) ( $p['weight_class'] ?? '' ),
					'note'         => implode( ' ', $notes ),
					'remind_url'   => $remind_url,
					'remind_label' => empty( $p['event_datetime_utc'] ) ? 'Remind me the day before' : 'Remind me 24h before',
				);
			}
			$events[] = array(
				'title'        => (string) ( $e['event_title'] ?? '' ) ?: 'Upcoming event',
				'promotion'    => (string) ( $e['promotion'] ?? '' ),
				'when'         => $this->date_label( $e, $zone ),
				'where'        => $this->place_label( $e ),
				'calendar_url' => (string) ( $e['calendar_url'] ?? '' ) ?: home_url( '/calendar/' ),
				'bouts'        => $bouts,
			);
		}

		$only_reminders = array( 'reminder' ) === array_keys( $kinds );
		$copy           = $this->copy( array_keys( $kinds ), $matchups );
		$names          = array_keys( $followed_names );
		$model          = array(
			'subject'         => $copy['subject'],
			'preheader'       => $events ? trim( $events[0]['title'] . ' · ' . $events[0]['when'], ' ·' ) : '',
			'eyebrow'         => $only_reminders ? 'Fight reminder' : 'Fight alert',
			'headline'        => $copy['headline'],
			'intro'           => $copy['intro'],
			'events'          => $events,
			'logo_url'        => self::logo_url(),
			'home_url'        => home_url( '/' ),
			'site_text'       => $only_reminders ? 'See the full card and what else is coming up.' : 'Rankings, fighter profiles and the fight calendar are updated as results come in.',
			'site_label'      => $only_reminders ? 'Open the fight calendar' : "See what's new on MMA Future",
			'site_url'        => $only_reminders && $events ? $events[0]['calendar_url'] : home_url( '/' ),
			'reason'          => $names ? 'You are receiving this because you follow ' . self::sentence_list( $names ) . ' on MMA Future.' : 'You are receiving this because you follow fighters on MMA Future.',
			'account_url'     => $account,
			'unsubscribe_url' => $preview ? $account : $tokens->action_url( 'unsubscribe', $tokens->issue( 'unsubscribe', $user_id, $email, array(), YEAR_IN_SECONDS ) ),
		);

		$headers = $preview ? array() : array( 'List-Unsubscribe: <' . $model['unsubscribe_url'] . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' );
		return array( 'subject' => $model['subject'], 'html' => self::render( $model ), 'text' => $this->text( $model ), 'headers' => $headers );
	}

	/** Subject, headline and opening line for the mix of updates in this email. */
	private function copy( array $kinds, array $matchups ): array {
		$count = count( $matchups );
		$one   = 1 === $count ? $matchups[0] : '';
		if ( array( 'reminder' ) === $kinds ) {
			return $one
				? array( 'subject' => 'Fight reminder: ' . $one, 'headline' => $one . ' is almost here', 'intro' => 'You asked us to remind you about this fight. Here are the details.' )
				: array( 'subject' => sprintf( 'Reminder: %d fights coming up', $count ), 'headline' => sprintf( '%d fights you asked about are coming up', $count ), 'intro' => 'You asked us to remind you about these fights. Here are the details.' );
		}
		if ( array( 'new_bout' ) === $kinds ) {
			return $one
				? array( 'subject' => 'New fight announced: ' . $one, 'headline' => 'New fight announced: ' . $one, 'intro' => 'A fighter you follow has a new fight on the calendar.' )
				: array( 'subject' => sprintf( '%d new fights for fighters you follow', $count ), 'headline' => sprintf( '%d new fights for fighters you follow', $count ), 'intro' => 'Fighters you follow have new fights on the calendar.' );
		}
		if ( $one ) {
			$label = 1 === count( $kinds ) ? self::kind_label( $kinds[0] ) : 'Fight update';
			return array( 'subject' => $label . ': ' . $one, 'headline' => $label . ': ' . $one, 'intro' => 'There is an update to a fight involving a fighter you follow.' );
		}
		return array( 'subject' => sprintf( '%d fight updates for fighters you follow', $count ), 'headline' => sprintf( '%d fight updates for fighters you follow', $count ), 'intro' => 'Here is what changed for the fighters you follow.' );
	}

	private function fighter( int $id, string $name, array $followed, Repository $repo ): array {
		$public = $id ? $repo->public_fighter( $id ) : null;
		$photo  = '';
		if ( $public ) {
			foreach ( AbstractRestController::fighter_image_ids( (int) $public['wp_post_id'] ) as $attachment_id ) {
				// The hard-cropped square size: Outlook ignores object-fit, so the file itself must be square.
				$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
				if ( $url ) { $photo = $url; break; }
			}
		}
		$name  = '' !== trim( $name ) ? trim( $name ) : 'TBA';
		$words = preg_split( '/\s+/', $name ) ?: array( $name );
		$initials = mb_strtoupper( mb_substr( $words[0], 0, 1 ) . ( count( $words ) > 1 ? mb_substr( (string) end( $words ), 0, 1 ) : '' ) );
		return array( 'name' => $name, 'url' => (string) ( $public['profile_url'] ?? '' ), 'photo' => $photo, 'initials' => $initials, 'followed' => $id > 0 && isset( $followed[ $id ] ) );
	}

	private function date_label( array $p, string $zone ): string {
		if ( ! empty( $p['event_datetime_utc'] ) ) {
			$dt = new \DateTimeImmutable( (string) $p['event_datetime_utc'], new \DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( new \DateTimeZone( $zone ) )->format( 'l, F j, Y · H:i T' );
		}
		if ( empty( $p['event_date'] ) ) { return 'Date to be confirmed'; }
		return ( new \DateTimeImmutable( (string) $p['event_date'], new \DateTimeZone( 'UTC' ) ) )->format( 'l, F j, Y' ) . ' · start time to be confirmed';
	}

	private function place_label( array $p ): string {
		$venue = trim( (string) ( $p['venue'] ?? '' ) ); $location = trim( (string) ( $p['location'] ?? '' ) );
		if ( '' !== $venue && '' !== $location && false !== stripos( $location, $venue ) ) { return $location; }
		return implode( ', ', array_filter( array( $venue, $location ) ) );
	}

	private function reminder_available( array $p, string $zone ): bool { return ! in_array( $p['event_status'] ?? '', array( 'cancelled','postponed' ), true ) && Reminders::in_time( (string) ( $p['event_date'] ?? '' ), ( $p['event_datetime_utc'] ?? null ) ?: null, $zone ); }

	private function text( array $m ): string {
		$text = $m['headline'] . "\n" . $m['intro'] . "\n\n";
		foreach ( $m['events'] as $event ) {
			$text .= trim( $event['promotion'] . ' — ' . $event['title'], ' —' ) . "\n" . $event['when'] . "\n" . ( $event['where'] ? $event['where'] . "\n" : '' );
			foreach ( $event['bouts'] as $bout ) {
				$text .= "\n" . implode( ', ', $bout['labels'] ) . ': ' . $bout['a']['name'] . ' vs ' . $bout['b']['name'] . ( $bout['weight_class'] ? ' (' . $bout['weight_class'] . ')' : '' ) . "\n";
				if ( $bout['note'] ) { $text .= $bout['note'] . "\n"; }
				if ( $bout['remind_url'] ) { $text .= $bout['remind_label'] . ': ' . $bout['remind_url'] . "\n"; }
			}
			$text .= "\n";
		}
		$text .= $m['site_label'] . ': ' . $m['site_url'] . "\n\n" . $m['reason'] . "\nManage notifications: " . $m['account_url'] . "\nUnsubscribe: " . $m['unsubscribe_url'];
		return $text;
	}

	private static function render( array $m ): string {
		ob_start();
		include MMAF_PLUGIN_DIR . 'templates/email/notification.php';
		return (string) ob_get_clean();
	}

	/** The light-on-dark logo for the email header; an empty string falls back to the site name as text. */
	private static function logo_url(): string {
		$custom = (int) get_theme_mod( 'mmaf_dark_logo' );
		$url    = $custom ? (string) wp_get_attachment_image_url( $custom, 'medium' ) : '';
		if ( '' === $url && is_file( get_template_directory() . '/assets/dist/images/logo-dark.png' ) ) { $url = get_template_directory_uri() . '/assets/dist/images/logo-dark.png'; }
		return (string) apply_filters( 'mmaf_notifications_email_logo_url', $url );
	}

	private static function sentence_list( array $names ): string {
		$names = array_values( $names );
		if ( count( $names ) > 3 ) { return implode( ', ', array_slice( $names, 0, 3 ) ) . ' and ' . ( count( $names ) - 3 ) . ' more'; }
		$last = array_pop( $names );
		return $names ? implode( ', ', $names ) . ' and ' . $last : (string) $last;
	}

	public static function kind_label( string $kind ): string { return array( 'new_bout'=>'New fight','schedule_changed'=>'Schedule changed','opponent_changed'=>'Opponent changed','cancelled'=>'Cancelled','postponed'=>'Postponed','reminder'=>'Reminder' )[ $kind ] ?? 'Update'; }
}
