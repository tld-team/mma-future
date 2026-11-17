<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanRanking;

/**
 * The "Fans" view of the rankings page. Included by FanBoard::render() with $data (one page of the board) and $labels.
 * Board and page links are plain links; the view is never page-cached, so each load shows the current standings.
 *
 * @var array<string,mixed>  $data
 * @var array<string,string> $labels
 */
$mmafc_boards = array( 'overall' => 'All', 'male' => 'Men', 'female' => 'Women' ) + array_diff_key( $labels, array( 'overall' => 1, 'male' => 1, 'female' => 1 ) );
$mmafc_lists  = (int) $data['lists'];
?>
<section class="mmafc-page mmafc-board">
	<div class="mmafc-shell mmafc-shell--wide">
		<div class="mmafc-board__head">
			<div class="mmafc-head">
				<h1>Fan ranking</h1>
				<p>How MMA Future fans rate the prospects. <?php echo esc_html( 1 === $mmafc_lists ? 'Based on 1 fan Top 10.' : 'Based on ' . number_format_i18n( $mmafc_lists ) . ' fan Top 10s.' ); ?></p>
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

		<?php if ( ! $data['items'] ) : ?>
			<div class="mmafc-card mmafc-empty">
				<h2 class="mmafc-empty__title"><?php echo 'overall' === $data['board'] ? 'Be the first to rank the prospects' : 'No fan picks in this division yet'; ?></h2>
				<p>Fighters appear here as soon as fans put them in a Top 10.</p>
			</div>
		<?php else : ?>
			<div class="mmafc-card mmafc-card--flush">
				<table class="mmafc-table">
					<caption class="mmafc-visually-hidden">Fan ranking, <?php echo esc_html( $mmafc_boards[ $data['board'] ] ); ?></caption>
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
						<?php foreach ( $data['items'] as $mmafc_row ) : $mmafc_diff = (int) $mmafc_row['difference']; ?>
							<tr>
								<td class="mmafc-table__rank"><span class="mmafc-table__pos"><?php echo (int) $mmafc_row['fan_rank']; ?></span></td>
								<td>
									<a class="mmafc-fighter" href="<?php echo esc_url( $mmafc_row['permalink'] ); ?>">
										<?php if ( '' !== $mmafc_row['image_url'] ) : ?><img class="mmafc-fighter__img" src="<?php echo esc_url( $mmafc_row['image_url'] ); ?>" alt="" width="40" height="40" loading="lazy"><?php else : ?><span class="mmafc-fighter__img mmafc-fighter__img--empty" aria-hidden="true"></span><?php endif; ?>
										<span class="mmafc-fighter__text">
											<span class="mmafc-fighter__name"><?php echo esc_html( $mmafc_row['display_name'] ); ?></span>
											<span class="mmafc-fighter__meta"><?php echo esc_html( implode( ' · ', array_filter( array( ucwords( str_replace( '_', ' ', $mmafc_row['weight_class'] ) ), $mmafc_row['record'], $mmafc_row['nationality'] ) ) ) ); ?></span>
										</span>
									</a>
								</td>
								<td class="mmafc-table__num" data-label="Points"><strong class="mmafc-table__points"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['points'] ) ); ?></strong></td>
								<td class="mmafc-table__num" data-label="In Top 10s"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['picks'] ) ); ?></td>
								<td class="mmafc-table__num" data-label="#1 picks"><?php echo esc_html( number_format_i18n( (int) $mmafc_row['firsts'] ) ); ?></td>
								<td class="mmafc-table__num" data-label="Official">
									#<?php echo (int) $mmafc_row['board_rank']; ?>
									<?php if ( $mmafc_diff > 0 ) : ?><span class="mmafc-delta mmafc-delta--up" title="Fans rate this fighter higher than the official ranking"><span aria-hidden="true">▲</span><span class="mmafc-visually-hidden">Fans rate higher by </span><?php echo (int) $mmafc_diff; ?></span>
									<?php elseif ( $mmafc_diff < 0 ) : ?><span class="mmafc-delta mmafc-delta--down" title="Fans rate this fighter lower than the official ranking"><span aria-hidden="true">▼</span><span class="mmafc-visually-hidden">Fans rate lower by </span><?php echo (int) abs( $mmafc_diff ); ?></span>
									<?php else : ?><span class="mmafc-delta" title="Fans and the official ranking agree"><span aria-hidden="true">=</span><span class="mmafc-visually-hidden">Same as fans</span></span><?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $data['total_pages'] > 1 ) : $mmafc_args = 'overall' === $data['board'] ? array() : array( FanBoard::BOARD_VAR => $data['board'] ); ?>
				<nav class="mmafc-pager" aria-label="Fan ranking pages">
					<?php if ( $data['page'] > 1 ) : ?><a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( FanBoard::url( true, $mmafc_args + ( $data['page'] > 2 ? array( FanBoard::PAGE_VAR => $data['page'] - 1 ) : array() ) ) ); ?>">Previous</a><?php endif; ?>
					<span class="mmafc-pager__text">Page <?php echo (int) $data['page']; ?> of <?php echo (int) $data['total_pages']; ?></span>
					<?php if ( $data['page'] < $data['total_pages'] ) : ?><a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( FanBoard::url( true, $mmafc_args + array( FanBoard::PAGE_VAR => $data['page'] + 1 ) ) ); ?>">Next</a><?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>

		<p class="mmafc-board__how">Each Top 10 gives its fighters <?php echo esc_html( implode( ', ', FanRanking::points() ) ); ?> points, from first place to tenth. Only fighters in the official ranking are counted.</p>
	</div>
</section>
