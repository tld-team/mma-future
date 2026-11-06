<?php
namespace MMAF\DataEngine\Services\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EmailComposer {
	public function compose( int $user_id, array $items ): array {
		$user = get_userdata( $user_id );
		$zone = Config::timezone( $user_id );
		$events = array();
		foreach ( $items as $item ) {
			$p = json_decode( (string) $item['payload_json'], true ) ?: array();
			$key = (string) ( $p['event_id'] ?? 0 );
			$events[ $key ]['title'] = $p['event_title'] ?? 'Upcoming event';
			$events[ $key ]['promotion'] = $p['promotion'] ?? '';
			$events[ $key ]['date'] = $p['event_date'] ?? '';
			$events[ $key ]['datetime'] = $p['event_datetime_utc'] ?? null;
			$events[ $key ]['calendar_url'] = $p['calendar_url'] ?? home_url( '/calendar/' );
			$events[ $key ]['bouts'][ (int) ( $p['bout_id'] ?? $item['bout_id'] ) ][] = $p;
		}
		$tokens = new Tokens();
		$unsubscribe = $tokens->action_url( 'unsubscribe', $tokens->issue( 'unsubscribe', $user_id, $user ? $user->user_email : null, array(), YEAR_IN_SECONDS ) );
		$subject = count( $events ) > 1 ? sprintf( '%d MMA event updates', count( $events ) ) : 'Your MMA event update';
		$html = '<!doctype html><html><body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif"><div style="max-width:640px;margin:0 auto;padding:28px 16px"><div style="background:#fff;border-radius:16px;padding:28px"><h1 style="margin-top:0">' . esc_html( $subject ) . '</h1>';
		$text = $subject . "\n\n";
		foreach ( $events as $event ) {
			$date = $this->date_label( $event, $zone );
			$html .= '<section style="border-top:1px solid #e2e8f0;padding-top:20px;margin-top:20px"><h2 style="margin:0 0 4px">' . esc_html( $event['title'] ) . '</h2><p style="margin:0 0 16px;color:#64748b">' . esc_html( trim( $event['promotion'] . ' · ' . $date, ' ·' ) ) . '</p>';
			$text .= $event['title'] . ' — ' . $date . "\n";
			foreach ( $event['bouts'] as $bout_id => $versions ) {
				$p = end( $versions ); $labels = array_values( array_unique( array_map( static fn( array $v ): string => self::kind_label( (string) ( $v['kind'] ?? '' ) ), $versions ) ) );
				$matchup = trim( (string) ( $p['fighter_a_name'] ?? '' ) . ' vs ' . (string) ( $p['fighter_b_name'] ?? '' ) );
				$a=$this->fighter_link((int)($p['fighter_a_id']??0),(string)($p['fighter_a_name']??''));$b=$this->fighter_link((int)($p['fighter_b_id']??0),(string)($p['fighter_b_name']??''));$notes=array_values(array_unique(array_filter(array_map(static fn(array $v):string=>(string)($v['change_note']??''),$versions))));
				$html .= '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:12px 0"><div style="font-size:12px;text-transform:uppercase;color:#0047a8;font-weight:700">' . esc_html( implode( ' · ', $labels ) ) . '</div><strong style="display:block;margin-top:6px">' . $a . ' vs ' . $b . '</strong>';
				if($notes){$html.='<p style="margin:8px 0 0;color:#475569">'.esc_html(implode(' ',$notes)).'</p>';}
				$text .= implode( ', ', $labels ) . ': ' . $matchup . "\n";
				if($notes){$text.=implode(' ',$notes)."\n";}
				if ( $this->reminder_available( $p, $zone ) ) {
					$token = $tokens->issue( 'reminder', $user_id, $user ? $user->user_email : null, array( 'bout_id' => (int) $bout_id ), 45 * DAY_IN_SECONDS );
					$url = $tokens->action_url( 'reminder', $token );
					$html .= '<p style="margin:14px 0 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#0047a8;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px">Remind me</a></p>';
					$text .= 'Remind me: ' . $url . "\n";
				}
				$html .= '</div>';
			}
			$html .= '<p><a href="' . esc_url( $event['calendar_url'] ) . '">View calendar</a></p></section>';
			$text .= 'Calendar: ' . $event['calendar_url'] . "\n\n";
		}
		$html .= '<p style="margin-top:28px;font-size:13px;color:#64748b"><a href="' . esc_url( home_url( '/account/' ) ) . '">Manage notifications</a> · <a href="' . esc_url( $unsubscribe ) . '">Unsubscribe</a></p></div></div></body></html>';
		$text .= "Manage notifications: " . home_url( '/account/' ) . "\nUnsubscribe: " . $unsubscribe;
		return array( 'subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => array( 'List-Unsubscribe: <' . $unsubscribe . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
	}

	private function date_label( array $event, string $zone ): string {
		if ( ! empty( $event['datetime'] ) ) { $dt = new \DateTimeImmutable( $event['datetime'], new \DateTimeZone( 'UTC' ) ); return $dt->setTimezone( new \DateTimeZone( $zone ) )->format( 'D, M j, Y H:i T' ) . ' (event start)'; }
		return (string) $event['date'] . ' (date only; bout time is not confirmed)';
	}
	private function reminder_available( array $p, string $zone ): bool { $today = ( new \DateTimeImmutable( 'now', new \DateTimeZone( $zone ) ) )->format( 'Y-m-d' ); return ! empty( $p['event_date'] ) && $p['event_date'] > $today && ! in_array( $p['event_status'] ?? '', array( 'cancelled','postponed' ), true ); }
	private function fighter_link(int $id,string $name): string { $fighter=$id?(new Repository())->public_fighter($id):null;return $fighter&&!empty($fighter['profile_url'])?'<a href="'.esc_url($fighter['profile_url']).'" style="color:#0047a8">'.esc_html($name).'</a>':esc_html($name); }
	private static function kind_label( string $kind ): string { return array( 'new_bout'=>'New fight','schedule_changed'=>'Schedule changed','opponent_changed'=>'Opponent changed','cancelled'=>'Cancelled','postponed'=>'Postponed','reminder'=>'Reminder' )[ $kind ] ?? 'Update'; }
}
