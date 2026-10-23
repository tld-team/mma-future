<?php
namespace MMAF\DataEngine\Services\Upcoming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {
	private const DEFAULT_TITLE = 'Upcoming MMA Events';
	private const DEFAULT_INTRO = 'Professional MMA events scheduled for the next 10 days. Select a date to see the complete fight card.';

	public static function render( array $settings = array() ): void {
		$timezone = CalendarService::valid_timezone( sanitize_text_field( wp_unslash( $_GET['calendar_tz'] ?? '' ) ) );
		$zone = new \DateTimeZone( $timezone );
		$today = new \DateTimeImmutable( 'today', $zone );
		$month = sanitize_text_field( wp_unslash( $_GET['calendar_month'] ?? $today->format( 'Y-m' ) ) );
		$selected = sanitize_text_field( wp_unslash( $_GET['calendar_date'] ?? $today->format( 'Y-m-d' ) ) );
		$service = new CalendarService();
		try {
			$month_data = $service->month( $month, $timezone );
			$day_data = $service->day( $selected, $timezone );
		} catch ( \Throwable $error ) {
			$month = $today->format( 'Y-m' );
			$selected = $today->format( 'Y-m-d' );
			$month_data = $service->month( $month, $timezone );
			$day_data = $service->day( $selected, $timezone );
		}
		self::enqueue_assets();
		$id = 'mmaf-calendar-' . wp_unique_id();
		$title = trim( (string) ( $settings['title'] ?? '' ) );
		$intro = trim( (string) ( $settings['intro'] ?? '' ) );
		if ( '' === $title || 'Kalendar MMA događaja' === $title ) {
			$title = self::DEFAULT_TITLE;
		}
		if ( '' === $intro || 'Pregled profesionalnih MMA događaja u narednih 10 dana. Izaberite datum za kompletan program borbi.' === $intro ) {
			$intro = self::DEFAULT_INTRO;
		}
		?>
		<section id="<?php echo esc_attr( $id ); ?>" class="mmaf-calendar" data-endpoint="<?php echo esc_url( rest_url( 'mma-future/v1/calendar' ) ); ?>" data-day-endpoint="<?php echo esc_url( rest_url( 'mma-future/v1/calendar/day' ) ); ?>" data-month="<?php echo esc_attr( $month ); ?>" data-selected="<?php echo esc_attr( $selected ); ?>" data-timezone="<?php echo esc_attr( $timezone ); ?>">
			<header class="mmaf-calendar__intro">
				<p class="mmaf-calendar__eyebrow">Upcoming events</p>
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $intro ) : ?><p><?php echo esc_html( $intro ); ?></p><?php endif; ?>
			</header>
			<div class="mmaf-calendar__panel">
				<div class="mmaf-calendar__toolbar">
					<div class="mmaf-calendar__nav" aria-label="Change month">
						<button type="button" class="mmaf-calendar__nav-button" data-calendar-action="previous" aria-label="Previous month">←</button>
						<h3 class="mmaf-calendar__month" aria-live="polite"><?php echo esc_html( $month_data['label'] ); ?></h3>
						<button type="button" class="mmaf-calendar__nav-button" data-calendar-action="next" aria-label="Next month">→</button>
						<button type="button" class="mmaf-calendar__today" data-calendar-action="today">Today</button>
					</div>
					<label class="mmaf-calendar__timezone">Time zone
						<select data-calendar-timezone>
							<?php foreach ( CalendarService::timezones() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $timezone, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<div class="mmaf-calendar__grid-wrap" data-calendar-grid><?php self::render_grid( $month_data, $selected ); ?></div>
				<div class="mmaf-calendar__legend" aria-label="Legend">
					<span><i class="is-covered"></i> Schedule covered</span><span><i class="is-today"></i> Today</span><span><i class="is-selected"></i> Selected day</span><span><i class="is-uncovered"></i> Schedule pending</span>
				</div>
				<p class="mmaf-calendar__refreshed" data-calendar-refreshed><?php echo $month_data['coverage'] ? 'Last updated: ' . esc_html( wp_date( 'M j, Y, H:i', strtotime( $month_data['coverage']['last_refreshed'] ) ) ) : 'No successful schedule import yet.'; ?></p>
			</div>
			<div class="mmaf-calendar__day" data-calendar-day aria-live="polite"><?php self::render_day( $day_data ); ?></div>
			<noscript><p class="mmaf-calendar__noscript">To change the month or time zone without JavaScript, add <code>calendar_month</code>, <code>calendar_date</code>, and <code>calendar_tz</code> to the URL.</p></noscript>
		</section>
		<?php
	}

	private static function render_grid( array $month, string $selected ): void {
		$weekdays = array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' );
		$offset = (int) ( $month['days'][0]['weekday'] ?? 1 ) - 1;
		?>
		<table class="mmaf-calendar__grid">
			<caption class="screen-reader-text"><?php echo esc_html( $month['label'] ); ?></caption>
			<thead><tr><?php foreach ( $weekdays as $weekday ) : ?><th scope="col"><?php echo esc_html( $weekday ); ?></th><?php endforeach; ?></tr></thead>
			<tbody><tr>
			<?php for ( $i = 0; $i < $offset; $i++ ) : ?><td class="mmaf-calendar__blank" aria-hidden="true"></td><?php endfor; ?>
			<?php foreach ( $month['days'] as $index => $day ) :
				$position = $offset + $index;
				if ( $position && 0 === $position % 7 ) { echo '</tr><tr>'; }
				$selectable = 'past' !== $day['state'] && ( 'uncovered' !== $day['state'] || $day['event_count'] > 0 );
				$classes = array( 'mmaf-calendar__cell', 'is-' . $day['state'] );
				if ( $day['is_today'] ) { $classes[] = 'is-today'; }
				if ( $selected === $day['date'] ) { $classes[] = 'is-selected'; }
				?>
				<td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-date="<?php echo esc_attr( $day['date'] ); ?>">
					<?php if ( $selectable ) : ?><button type="button" data-calendar-date="<?php echo esc_attr( $day['date'] ); ?>" <?php echo $selected === $day['date'] ? 'aria-pressed="true"' : 'aria-pressed="false"'; ?> <?php echo $day['is_today'] ? 'aria-current="date"' : ''; ?>><?php else : ?><div class="mmaf-calendar__disabled"><?php endif; ?>
						<span class="mmaf-calendar__number"><?php echo esc_html( (string) $day['day'] ); ?></span>
						<span class="mmaf-calendar__mobile-count"><?php echo $day['event_count'] ? esc_html( (string) $day['event_count'] ) : ''; ?></span>
						<span class="mmaf-calendar__events">
							<?php foreach ( $day['events'] as $event ) : ?><span><?php echo esc_html( $event['title'] ); ?></span><?php endforeach; ?>
							<?php if ( $day['event_count'] > 2 ) : ?><em>+ <?php echo esc_html( (string) ( $day['event_count'] - 2 ) ); ?> more</em><?php endif; ?>
						</span>
					<?php echo $selectable ? '</button>' : '</div>'; ?>
				</td>
			<?php endforeach;
			$used = $offset + count( $month['days'] );
			for ( $i = $used; $i % 7; $i++ ) { echo '<td class="mmaf-calendar__blank" aria-hidden="true"></td>'; }
			?></tr></tbody>
		</table>
		<?php
	}

	private static function render_day( array $day ): void {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day['date'] );
		?><div class="mmaf-calendar__day-heading"><p>Selected day</p><h3><?php echo esc_html( $date ? CalendarService::date_label( $date ) : $day['date'] ); ?></h3></div><?php
		if ( $day['message'] ) {
			echo '<p class="mmaf-calendar__empty">' . esc_html( $day['message'] ) . '</p>';
		}
		foreach ( $day['events'] as $event ) : ?>
			<article class="mmaf-calendar__event">
				<div class="mmaf-calendar__event-head"><div><p><?php echo esc_html( $event['promotion'] ?: 'MMA event' ); ?></p><h4><?php echo esc_html( $event['title'] ); ?></h4></div><time datetime="<?php echo esc_attr( $event['date'] . ( $event['time'] ? 'T' . $event['time'] : '' ) ); ?>"><?php echo esc_html( $event['time'] ?: 'Time TBC' ); ?></time></div>
				<p class="mmaf-calendar__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $event['venue'], $event['location'] ) ) ) ?: 'Location TBC' ); ?></p>
				<?php if ( $event['time_note'] ) : ?><p class="mmaf-calendar__note"><?php echo esc_html( $event['time_note'] ); ?></p><?php endif; ?>
				<ol class="mmaf-calendar__bouts">
					<?php foreach ( $event['bouts'] as $bout ) : ?><li><span><?php self::fighter( $bout['fighter_a'] ); ?></span><b>vs</b><span><?php self::fighter( $bout['fighter_b'] ); ?></span><?php if ( $bout['weight_class'] ) : ?><small><?php echo esc_html( $bout['weight_class'] ); ?></small><?php endif; ?></li><?php endforeach; ?>
				</ol>
				<?php if ( $event['source_url'] ) : ?><a class="mmaf-calendar__source" href="<?php echo esc_url( $event['source_url'] ); ?>" target="_blank" rel="noopener noreferrer">View on Tapology ↗</a><?php endif; ?>
			</article>
		<?php endforeach;
	}

	private static function fighter( array $fighter ): void {
		$url = $fighter['profile_url'] ?: $fighter['source_url'];
		if ( $url ) {
			echo '<a href="' . esc_url( $url ) . '"' . ( $fighter['profile_url'] ? '' : ' target="_blank" rel="noopener noreferrer"' ) . '>' . esc_html( $fighter['name'] ) . '</a>';
		} else {
			echo esc_html( $fighter['name'] );
		}
	}

	private static function enqueue_assets(): void {
		wp_enqueue_style( 'mmaf-event-calendar', plugins_url( 'assets/event-calendar.css', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION );
		wp_enqueue_script( 'mmaf-event-calendar', plugins_url( 'assets/event-calendar.js', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION, true );
	}
}
