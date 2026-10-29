<?php
// Standalone renderer fixture: no WordPress bootstrap, database, or imported data.
namespace MMAF\DataEngine\Services\Upcoming {
	final class CalendarService {
		public static function timezones(): array { return array( 'Europe/Belgrade' => 'Belgrade', 'America/New_York' => 'New York', 'Asia/Tokyo' => 'Tokyo', 'UTC' => 'UTC' ); }
		public static function valid_timezone( ?string $zone ): string { return isset( self::timezones()[ $zone ?? '' ] ) ? $zone : 'Europe/Belgrade'; }
		public static function date_label( \DateTimeImmutable $date ): string { return $date->format( 'l, F j, Y' ); }
		public function month( string $month, string $timezone ): array {
			$first = new \DateTimeImmutable( $month . '-01' );
			$days = array();
			for ( $i = 1; $i <= (int) $first->format( 't' ); $i++ ) {
				$date = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $i );
				$count = $this->total( $date->format( 'Y-m-d' ) );
				$days[] = array( 'date' => $date->format( 'Y-m-d' ), 'day' => $i, 'weekday' => (int) $date->format( 'N' ), 'event_count' => $count, 'events' => array(), 'state' => 7 === $i ? 'partial' : 'covered', 'is_today' => 5 === $i );
			}
			return array( 'month' => $month, 'label' => $first->format( 'F Y' ), 'days' => $days, 'coverage' => array( 'last_refreshed' => '2026-10-28T05:01:00Z' ) );
		}
		private function total( string $date ): int { return array( 1 => 0, 2 => 1, 3 => 3, 4 => 4, 5 => 19, 6 => 23, 7 => 1, 8 => 4, 9 => 4 )[ (int) substr( $date, -2 ) ] ?? 0; }
		public function day( string $date, string $timezone, int $page = 1, int $per_page = 20 ): array {
			$total = $this->total( $date );
			$events = array();
			$titles = array( 'LFA 245: Khamzaev vs. Prescendo', 'Eternal MMA 111', 'OKTAGON 96: Gogoladze vs. Ayari', 'Motor City Cagefights 26', 'ACA 209: Frolov vs. Odilov', 'A very long event name that must wrap without hiding the time or overflowing the calendar column' );
			for ( $i = 0; $i < $total; $i++ ) {
				$bouts = array();
				for ( $j = 0; $j < 9; $j++ ) $bouts[] = array( 'fighter_a' => array( 'name' => 'Fighter A ' . ( $j + 1 ), 'profile_url' => '/fighters/example-a/', 'source_url' => null ), 'fighter_b' => array( 'name' => 'Fighter B ' . ( $j + 1 ), 'profile_url' => null, 'source_url' => 'https://www.tapology.com/' ), 'weight_class' => 'Lightweight' );
				$events[] = array( 'id' => (int) substr( $date, -2 ) * 100 + $i, 'title' => $titles[ $i % count( $titles ) ], 'promotion' => 'Professional MMA', 'date' => $date, 'time' => 1 === $i ? null : ( 'Asia/Tokyo' === $timezone ? '23:30' : '18:30' ), 'venue' => 'Event Arena', 'location' => 'Belgrade, Serbia', 'time_note' => 1 === $i ? 'Start time is not confirmed.' : null, 'source_url' => 'https://www.tapology.com/', 'bouts' => 1 === $i ? array() : $bouts );
			}
			return array( 'date' => $date, 'timezone' => $timezone, 'state' => 7 === (int) substr( $date, -2 ) ? 'partial' : 'covered', 'events' => array_slice( $events, ( $page - 1 ) * $per_page, $per_page ), 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'message' => ! $total ? 'No professional MMA events scheduled for this day.' : ( 7 === (int) substr( $date, -2 ) ? 'Schedule may be updated.' : null ) );
		}
	}
}
namespace {
	$repo = dirname( __DIR__, 2 );
	define( 'ABSPATH', $repo . '/' );
	define( 'MMAF_PLUGIN_FILE', $repo . '/wp-content/plugins/mma-future-data-engine/mma-future-data-engine.php' );
	define( 'MMAF_PLUGIN_VERSION', 'fixture' );
	function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
	function wp_unslash( $value ) { return $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $value ) { return esc_attr( $value ); }
	function esc_url( $value ) { return esc_attr( $value ); }
	function wp_unique_id() { static $id = 0; return ++$id; }
	function rest_url( $path ) { return 'http://127.0.0.1:8098/fixture.php?api=' . ( str_ends_with( $path, '/day' ) ? 'day' : 'month' ); }
	function plugins_url( $path, $file ) { return '/fixture.php?asset=' . basename( $path ); }
	function wp_enqueue_style( ...$args ) {}
	function wp_enqueue_script( ...$args ) {}
	function wp_date( $format, $time ) { return gmdate( $format, $time ); }
	function selected( $a, $b ) { if ( $a === $b ) echo 'selected="selected"'; }
	function add_query_arg( $args ) { return '/fixture.php?' . http_build_query( array_merge( $_GET, $args ) ); }
	if ( PHP_SAPI === 'cli' && isset( $argv[1] ) ) parse_str( $argv[1], $_GET );
	$_GET += array( 'calendar_month' => '2027-05', 'calendar_date' => '2027-05-05', 'calendar_tz' => 'Europe/Belgrade' );
	if ( isset( $_GET['asset'] ) ) {
		$assets = array( 'event-calendar.js' => dirname( MMAF_PLUGIN_FILE ) . '/assets/event-calendar.js', 'event-calendar.css' => dirname( MMAF_PLUGIN_FILE ) . '/assets/event-calendar.css', 'color-scheme.css' => $repo . '/wp-content/themes/mma-future/assets/dist/css/color-scheme.css' );
		if ( ! isset( $assets[ $_GET['asset'] ] ) ) { http_response_code( 404 ); exit; }
		header( 'Content-Type: ' . ( str_ends_with( $_GET['asset'], '.js' ) ? 'text/javascript' : 'text/css' ) );
		readfile( $assets[ $_GET['asset'] ] ); exit;
	}
	if ( isset( $_GET['api'] ) ) {
		header( 'Content-Type: application/json' );
		$service = new \MMAF\DataEngine\Services\Upcoming\CalendarService();
		echo json_encode( 'day' === $_GET['api'] ? $service->day( $_GET['date'], $_GET['timezone'], max( 1, (int) ( $_GET['page'] ?? 1 ) ), max( 1, (int) ( $_GET['per_page'] ?? 20 ) ) ) : $service->month( $_GET['month'], $_GET['timezone'] ) ); exit;
	}
	require dirname( MMAF_PLUGIN_FILE ) . '/includes/Services/Upcoming/Renderer.php';
	?>
<!doctype html><html lang="en" data-theme="<?php echo 'dark' === ( $_GET['theme'] ?? 'light' ) ? 'dark' : 'light'; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MMA Future — Calendar preview</title>
<link rel="stylesheet" href="http://mma-future.local/wp-content/themes/mma-future/assets/dist/css/output.css"><link rel="stylesheet" href="/fixture.php?asset=color-scheme.css"><link rel="stylesheet" href="/fixture.php?asset=event-calendar.css">
<style>body{margin:0;background:var(--mmaf-canvas,#fff);color:var(--mmaf-text,#0f172a);font-family:Arial,sans-serif}main{padding:1px 0 32px}header.preview{padding:16px 24px;border-bottom:1px solid #64748b;font-size:12px}header.preview a{margin-left:12px;text-decoration:underline}</style></head><body><header class="preview">Calendar preview · isolated test data <a href="/fixture.php?theme=light">Light</a><a href="/fixture.php?theme=dark">Dark</a><a href="/fixture.php?nojs=1">Without JavaScript</a></header><main>
<?php \MMAF\DataEngine\Services\Upcoming\Renderer::render(); ?>
</main><?php if ( empty( $_GET['nojs'] ) ) : ?><script src="/fixture.php?asset=event-calendar.js"></script><?php endif; ?></body></html>
<?php
}
