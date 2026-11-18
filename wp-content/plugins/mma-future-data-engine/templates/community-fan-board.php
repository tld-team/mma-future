<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanRanking;

/**
 * The "Fans" view of the rankings page. Included by FanBoard::render() with $data (one page of the board) and $labels.
 * Board and page links are plain links; the view is never page-cached, so each load shows the current standings.
 *
 * The first page leads with the fans' Top 10 as a fight card (#1 large, #2 and #3 beside each other, then 4–10), then
 * 11–30 as compact rows. Later pages are rows only.
 *
 * @var array<string,mixed>  $data
 * @var array<string,string> $labels
 */
$mmafc_boards   = array( 'overall' => 'All', 'male' => 'Men', 'female' => 'Women' ) + array_diff_key( $labels, array( 'overall' => 1, 'male' => 1, 'female' => 1 ) );
$mmafc_lists    = (int) $data['lists'];
$mmafc_waiting  = (int) ( $data['waiting'] ?? 0 );
$mmafc_next     = ! empty( $data['next_counts_at'] ) ? (int) strtotime( (string) $data['next_counts_at'] ) : 0;
$mmafc_hours    = Config::fan_min_account_hours();
$mmafc_items    = (array) $data['items'];
$mmafc_first    = 1 === (int) $data['page'];
$mmafc_featured = $mmafc_first ? array_slice( $mmafc_items, 0, FanBoard::FEATURED ) : array();
$mmafc_rest     = $mmafc_first ? array_slice( $mmafc_items, FanBoard::FEATURED ) : $mmafc_items;
$mmafc_leader   = $mmafc_featured ? max( 1, (int) $mmafc_featured[0]['points'] ) : 1;
$mmafc_when     = $mmafc_next > time() ? 'in ' . human_time_diff( time(), $mmafc_next ) : 'within a few minutes';
$mmafc_count    = static fn( int $n, string $one, string $many ): string => 1 === $n ? '1 ' . $one : number_format_i18n( $n ) . ' ' . $many;

/** The distance from the official board, as an arrow and a sentence for screen readers. */
$mmafc_delta = static function ( int $diff ): string {
	if ( $diff > 0 ) { return '<span class="mmafc-delta mmafc-delta--up" title="Fans rate this fighter higher than the official ranking"><span aria-hidden="true">▲</span><span class="mmafc-visually-hidden">Fans rate higher by </span>' . (int) $diff . '</span>'; }
	if ( $diff < 0 ) { return '<span class="mmafc-delta mmafc-delta--down" title="Fans rate this fighter lower than the official ranking"><span aria-hidden="true">▼</span><span class="mmafc-visually-hidden">Fans rate lower by </span>' . (int) abs( $diff ) . '</span>'; }
	return '<span class="mmafc-delta" title="Fans and the official ranking agree"><span aria-hidden="true">=</span><span class="mmafc-visually-hidden">Same as fans</span></span>';
};
$mmafc_meta = static fn( array $row ): string => implode( ' · ', array_filter( array( ucwords( str_replace( '_', ' ', (string) $row['weight_class'] ) ), (string) $row['record'], (string) $row['nationality'] ) ) );
?>
<section class="mmafc-page mmafc-board">
	<div class="mmafc-shell mmafc-shell--wide">
		<div class="mmafc-board__head">
			<div class="mmafc-head">
				<h1>Fan ranking</h1>
				<p>How MMA Future fans rate the prospects. <?php echo esc_html( 'Based on ' . $mmafc_count( $mmafc_lists, 'fan Top 10.', 'fan Top 10s.' ) ); ?></p>
				<?php if ( $mmafc_waiting > 0 && $mmafc_lists > 0 ) : // With no counted list yet, the empty state below says it. ?>
					<p class="mmafc-board__waiting"><?php echo esc_html( $mmafc_count( $mmafc_waiting, 'more list from a new account starts', 'more lists from new accounts start' ) . ' counting ' . $mmafc_when . '.' ); ?></p>
				<?php endif; ?>
			</div>
			<?php echo FanBoard::source_toggle( 'fans' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in FanBoard. ?>
		</div>

		<div class="mmafc-board__bar">
			<nav class="mmafc-filter" aria-label="Division">
				<?php foreach ( $mmafc_boards as $mmafc_key => $mmafc_label ) : ?>
					<a class="mmafc-filter__link<?php echo $mmafc_key === $data['board'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( FanBoard::url( true, 'overall' === $mmafc_key ? array() : array( FanBoard::BOARD_VAR => $mmafc_key ) ) ); ?>"<?php echo $mmafc_key === $data['board'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $mmafc_label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<a class="mmafc-button" href="<?php echo esc_url( FanBoard::editor_url() ); ?>">Build your Top 10</a>
		</div>

		<?php if ( ! $mmafc_items ) : ?>
			<div class="mmafc-card mmafc-empty">
				<?php if ( $mmafc_waiting > 0 && 0 === $mmafc_lists ) : ?>
					<h2 class="mmafc-empty__title">The first fan Top 10s are in</h2>
					<p><?php echo esc_html( $mmafc_count( $mmafc_waiting, 'list starts', 'lists start' ) . ' counting ' . $mmafc_when . '. New accounts wait ' . $mmafc_count( $mmafc_hours, 'hour', 'hours' ) . ', so a batch of fresh accounts can’t move the ranking at once.' ); ?></p>
				<?php elseif ( 'overall' === $data['board'] ) : ?>
					<h2 class="mmafc-empty__title">Be the first to rank the prospects</h2>
					<p>Fighters appear here as soon as fans put them in a Top 10.</p>
				<?php else : ?>
					<h2 class="mmafc-empty__title">No fan picks in this division yet</h2>
					<p>Fighters appear here as soon as fans put them in a Top 10.</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $mmafc_featured ) : ?>
			<?php // The fans' Top 10 as a fight card, in the same visual language as a reader's own Top 10 on their profile. ?>
			<ol class="mmafc-rows mmafc-rows--public mmafc-fightcard mmafc-board__top" aria-label="Fans’ Top 10, <?php echo esc_attr( $mmafc_boards[ $data['board'] ] ); ?>">
				<?php foreach ( $mmafc_featured as $mmafc_row ) : $mmafc_pos = (int) $mmafc_row['fan_rank']; $mmafc_tier = 1 === $mmafc_pos ? 'main' : ( $mmafc_pos <= 3 ? 'co' : 'card' ); $mmafc_px = array( 'main' => 96, 'co' => 64, 'card' => 40 )[ $mmafc_tier ]; ?>
					<li class="mmafc-row mmafc-fightcard__pick mmafc-fightcard__pick--<?php echo esc_attr( $mmafc_tier ); ?>">
						<?php if ( 'main' === $mmafc_tier ) : ?><span class="mmafc-fightcard__label">Fans’ number one</span><?php endif; ?>
						<span class="mmafc-row__pos"><?php echo (int) $mmafc_pos; ?></span>
						<?php if ( '' !== $mmafc_row['image_url'] ) : ?><img class="mmafc-row__img" src="<?php echo esc_url( $mmafc_row['image_url'] ); ?>" alt="" width="<?php echo (int) $mmafc_px; ?>" height="<?php echo (int) $mmafc_px; ?>" loading="lazy"><?php else : ?><span class="mmafc-row__img mmafc-row__img--empty" aria-hidden="true"></span><?php endif; ?>
						<span class="mmafc-row__body">
							<a class="mmafc-row__name" href="<?php echo esc_url( $mmafc_row['permalink'] ); ?>"><?php echo esc_html( $mmafc_row['display_name'] ); ?></a>
							<span class="mmafc-row__meta"><?php echo esc_html( $mmafc_meta( $mmafc_row ) ); ?></span>
							<span class="mmafc-row__meta mmafc-board__stats"><?php echo esc_html( 'In ' . $mmafc_count( (int) $mmafc_row['picks'], 'Top 10', 'Top 10s' ) . ( (int) $mmafc_row['firsts'] > 0 ? ' · ' . $mmafc_count( (int) $mmafc_row['firsts'], '#1 pick', '#1 picks' ) : '' ) ); ?></span>
							<span class="mmafc-board__share" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * (int) $mmafc_row['points'] / $mmafc_leader, 1 ) ); ?>%"></span></span>
						</span>
						<span class="mmafc-board__score">
							<strong class="mmafc-row__points"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['points'] ) ); ?> pts</strong>
							<span class="mmafc-row__official">Official #<?php echo (int) $mmafc_row['board_rank']; ?><?php echo $mmafc_delta( (int) $mmafc_row['difference'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup and integers. ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php if ( $mmafc_rest ) : $mmafc_from = (int) $mmafc_rest[0]['fan_rank']; $mmafc_to = (int) $mmafc_rest[ count( $mmafc_rest ) - 1 ]['fan_rank']; ?>
			<h2 class="mmafc-board__section"><?php echo $mmafc_first ? 'The chasing pack' : 'Fan ranking'; ?> <span><?php echo (int) $mmafc_from; ?>–<?php echo (int) $mmafc_to; ?></span></h2>
			<div class="mmafc-card mmafc-card--flush">
				<table class="mmafc-table">
					<caption class="mmafc-visually-hidden">Fan ranking, <?php echo esc_html( $mmafc_boards[ $data['board'] ] ); ?>, places <?php echo (int) $mmafc_from; ?> to <?php echo (int) $mmafc_to; ?></caption>
					<thead>
						<tr>
							<th scope="col" class="mmafc-table__rank">Fan rank</th>
							<th scope="col">Fighter</th>
							<th scope="col" class="mmafc-table__num">Points</th>
							<th scope="col" class="mmafc-table__num">In Top 10s</th>
							<th scope="col" class="mmafc-table__num">#1 picks</th>
							<th scope="col" class="mmafc-table__num">Official</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mmafc_rest as $mmafc_row ) : ?>
							<tr>
								<td class="mmafc-table__rank"><span class="mmafc-table__pos"><?php echo (int) $mmafc_row['fan_rank']; ?></span></td>
								<td>
									<a class="mmafc-fighter" href="<?php echo esc_url( $mmafc_row['permalink'] ); ?>">
										<?php if ( '' !== $mmafc_row['image_url'] ) : ?><img class="mmafc-fighter__img" src="<?php echo esc_url( $mmafc_row['image_url'] ); ?>" alt="" width="40" height="40" loading="lazy"><?php else : ?><span class="mmafc-fighter__img mmafc-fighter__img--empty" aria-hidden="true"></span><?php endif; ?>
										<span class="mmafc-fighter__text">
											<span class="mmafc-fighter__name"><?php echo esc_html( $mmafc_row['display_name'] ); ?></span>
											<span class="mmafc-fighter__meta"><?php echo esc_html( $mmafc_meta( $mmafc_row ) ); ?></span>
										</span>
									</a>
								</td>
								<td class="mmafc-table__num" data-label="Points"><strong class="mmafc-table__points"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['points'] ) ); ?></strong></td>
								<td class="mmafc-table__num" data-label="In Top 10s"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['picks'] ) ); ?></td>
								<td class="mmafc-table__num" data-label="#1 picks"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['firsts'] ) ); ?></td>
								<td class="mmafc-table__num" data-label="Official">#<?php echo (int) $mmafc_row['board_rank']; ?><?php echo $mmafc_delta( (int) $mmafc_row['difference'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup and integers. ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ( $data['total_pages'] > 1 ) : $mmafc_args = 'overall' === $data['board'] ? array() : array( FanBoard::BOARD_VAR => $data['board'] ); ?>
			<nav class="mmafc-pager" aria-label="Fan ranking pages">
				<?php if ( $data['page'] > 1 ) : ?><a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( FanBoard::url( true, $mmafc_args + ( $data['page'] > 2 ? array( FanBoard::PAGE_VAR => $data['page'] - 1 ) : array() ) ) ); ?>">Previous</a><?php endif; ?>
				<span class="mmafc-pager__text">Page <?php echo (int) $data['page']; ?> of <?php echo (int) $data['total_pages']; ?></span>
				<?php if ( $data['page'] < $data['total_pages'] ) : ?><a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( FanBoard::url( true, $mmafc_args + array( FanBoard::PAGE_VAR => $data['page'] + 1 ) ) ); ?>"><?php echo $mmafc_first ? 'Full standings' : 'Next'; ?></a><?php endif; ?>
			</nav>
		<?php endif; ?>

		<p class="mmafc-board__how">Each Top 10 gives its fighters <?php echo esc_html( implode( ', ', FanRanking::points() ) ); ?> points, from first place to tenth. Only fighters in the official ranking are counted<?php echo $mmafc_hours > 0 ? esc_html( ', and a new account’s list counts once the account is ' . $mmafc_count( $mmafc_hours, 'hour', 'hours' ) . ' old' ) : ''; ?>.</p>
	</div>
</section>
