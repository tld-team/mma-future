<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Badge;
use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\Levels;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Ratings;
use MMAF\DataEngine\Services\Community\Reputation;
use MMAF\DataEngine\Services\Community\Routes;

// Template-scope variables are WordPress globals here. Never use names such as $page, $s or $post: get_header() resets them.
// Only the profile row is printed. Anything that depends on who is looking is filled in by the script, so this page stays cacheable.
$mmafc_row   = (array) Routes::public_profile();
$mmafc_view  = ( new Profiles() )->present( $mmafc_row );
$mmafc_fan   = Config::area_enabled( 'fan' );
$mmafc_picks = $mmafc_fan ? ( new FanLists() )->items( (int) $mmafc_row['user_id'] ) : array();
$mmafc_rep   = Config::area_enabled( 'reputation' ) && Config::is_reader( (int) $mmafc_row['user_id'] ) ? Reputation::summary( (int) $mmafc_row['user_id'], $mmafc_row )['level'] : null;
// Totals are the same for every visitor; the visitor's own rating and the controls are filled in by the script.
$mmafc_props = $mmafc_rep && $mmafc_picks ? ( new Ratings() )->totals( (int) $mmafc_row['user_id'] ) : null;
status_header( 200 );
get_header();
?>
<main id="primary" class="mmafc-page">
	<div class="mmafc-shell">
		<article class="mmafc-card mmafc-profile" data-mmafc-public-profile="<?php echo esc_attr( $mmafc_view['handle'] ); ?>">
			<span class="mmafc-avatar-frame mmafc-avatar-frame--<?php echo esc_attr( $mmafc_rep ? Levels::tier( (int) $mmafc_rep['level'] ) : 'blue' ); ?>"><img class="mmafc-avatar mmafc-avatar--xl" src="<?php echo esc_url( $mmafc_view['avatar_url'] ); ?>" alt="" width="128" height="128"></span>
			<div class="mmafc-profile__body">
				<h1 class="mmafc-profile__name"><?php echo esc_html( $mmafc_view['display_name'] ); ?></h1>
				<p class="mmafc-profile__handle">@<?php echo esc_html( $mmafc_view['handle'] ); ?></p>
				<?php if ( $mmafc_rep ) : ?>
					<div class="mmafc-profile__level">
						<?php echo Badge::svg( $mmafc_rep, 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from integers and an escaped label. ?>
						<div class="mmafc-profile__level-body">
							<p class="mmafc-profile__level-name">Level <?php echo (int) $mmafc_rep['level']; ?> · <?php echo esc_html( $mmafc_rep['name'] ); ?></p>
							<p class="mmafc-profile__meta"><?php echo (int) $mmafc_rep['xp']; ?> XP<?php echo null === $mmafc_rep['next'] ? '' : ' · ' . (int) $mmafc_rep['to_next'] . ' to ' . esc_html( (string) $mmafc_rep['next_name'] ); ?></p>
							<div class="mmafc-meter" role="progressbar" aria-label="Progress to the next level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) round( $mmafc_rep['progress'] * 100 ); ?>"><span class="mmafc-meter__fill" style="width:<?php echo esc_attr( (string) round( $mmafc_rep['progress'] * 100, 1 ) ); ?>%"></span></div>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $mmafc_view['member_since'] ) : ?>
					<p class="mmafc-profile__meta">Member since <?php echo esc_html( $mmafc_view['member_since'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $mmafc_view['bio'] ) : ?>
					<p class="mmafc-profile__bio"><?php echo nl2br( esc_html( $mmafc_view['bio'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $mmafc_view['socials'] ) : ?>
					<ul class="mmafc-profile__socials" aria-label="Social links">
						<?php foreach ( $mmafc_view['socials'] as $mmafc_link ) : ?>
							<li><a class="mmafc-chip" href="<?php echo esc_url( $mmafc_link['url'] ); ?>" target="_blank" rel="nofollow ugc noopener"><span class="mmafc-chip__label"><?php echo esc_html( $mmafc_link['label'] ); ?></span> @<?php echo esc_html( $mmafc_link['username'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="mmafc-profile__owner" data-mmafc-owner hidden>
					<a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( home_url( '/account/profile/' ) ); ?>">Edit profile</a>
					<?php if ( $mmafc_fan ) : ?><a class="mmafc-button mmafc-button--quiet" href="<?php echo esc_url( FanBoard::editor_url() ); ?>"><?php echo $mmafc_picks ? 'Edit Top 10' : 'Build your Top 10'; ?></a><?php endif; ?>
				</p>
			</div>
		</article>

		<?php if ( $mmafc_picks ) : ?>
			<section class="mmafc-card mmafc-top10" aria-labelledby="mmafc-top10-title">
				<div class="mmafc-editor__head">
					<h2 class="mmafc-editor__title" id="mmafc-top10-title">Top 10</h2>
					<a class="mmafc-link" href="<?php echo esc_url( FanBoard::url( true ) ); ?>">Fan ranking</a>
				</div>
				<?php // A fight card: the reader's #1 as the main event, 2 and 3 as the co-main events, then the rest of the card. ?>
				<ol class="mmafc-rows mmafc-rows--public mmafc-fightcard">
					<?php foreach ( $mmafc_picks as $mmafc_pick ) : $mmafc_name = '' !== $mmafc_pick['display_name'] ? $mmafc_pick['display_name'] : 'Fighter no longer listed'; $mmafc_pos = (int) $mmafc_pick['position']; $mmafc_tier = 1 === $mmafc_pos ? 'main' : ( $mmafc_pos <= 3 ? 'co' : 'card' ); $mmafc_px = array( 'main' => 96, 'co' => 64, 'card' => 40 )[ $mmafc_tier ]; ?>
						<li class="mmafc-row mmafc-fightcard__pick mmafc-fightcard__pick--<?php echo esc_attr( $mmafc_tier ); ?><?php echo $mmafc_pick['eligible'] ? '' : ' mmafc-row--muted'; ?>">
							<?php if ( 'main' === $mmafc_tier ) : ?><span class="mmafc-fightcard__label">Main event</span><?php endif; ?>
							<span class="mmafc-row__pos"><?php echo $mmafc_pos; ?></span>
							<?php if ( '' !== $mmafc_pick['image_url'] ) : ?><img class="mmafc-row__img" src="<?php echo esc_url( $mmafc_pick['image_url'] ); ?>" alt="" width="<?php echo (int) $mmafc_px; ?>" height="<?php echo (int) $mmafc_px; ?>" loading="lazy"><?php else : ?><span class="mmafc-row__img mmafc-row__img--empty" aria-hidden="true"></span><?php endif; ?>
							<span class="mmafc-row__body">
								<?php if ( '' !== $mmafc_pick['permalink'] ) : ?><a class="mmafc-row__name" href="<?php echo esc_url( $mmafc_pick['permalink'] ); ?>"><?php echo esc_html( $mmafc_name ); ?></a><?php else : ?><span class="mmafc-row__name"><?php echo esc_html( $mmafc_name ); ?></span><?php endif; ?>
								<span class="mmafc-row__meta"><?php echo esc_html( implode( ' · ', array_filter( array( ucwords( str_replace( '_', ' ', $mmafc_pick['weight_class'] ) ), $mmafc_pick['record'] ) ) ) ); ?></span>
							</span>
							<?php if ( $mmafc_pick['eligible'] ) : $mmafc_gap = (int) $mmafc_pick['official_rank'] - $mmafc_pos; ?>
								<span class="mmafc-row__official">Official #<?php echo (int) $mmafc_pick['official_rank']; ?>
									<?php if ( 0 === $mmafc_gap ) : ?><span class="mmafc-gap mmafc-gap--same" title="Same place as the official ranking"><span aria-hidden="true">=</span><span class="mmafc-visually-hidden">same as official</span></span>
									<?php else : ?><span class="mmafc-gap mmafc-gap--<?php echo $mmafc_gap > 0 ? 'up' : 'down'; ?>"><span aria-hidden="true"><?php echo $mmafc_gap > 0 ? '▲' : '▼'; ?><?php echo (int) abs( $mmafc_gap ); ?></span><span class="mmafc-visually-hidden"><?php echo esc_html( abs( $mmafc_gap ) . ( 1 === abs( $mmafc_gap ) ? ' place ' : ' places ' ) . ( $mmafc_gap > 0 ? 'higher' : 'lower' ) . ' than official' ); ?></span></span><?php endif; ?>
								</span>
							<?php else : ?><span class="mmafc-row__note"><?php echo esc_html( $mmafc_pick['note'] ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php if ( null !== $mmafc_props ) : ?>
					<div class="mmafc-props" data-mmafc-props="<?php echo esc_attr( $mmafc_view['handle'] ); ?>">
						<p class="mmafc-props__totals" data-mmafc-props-totals>
							<?php if ( $mmafc_props['count'] ) : ?>
								<?php echo esc_html( implode( ' · ', array_map( static fn( int $value, string $label ): string => $label . ' ' . (int) $mmafc_props[ $value ], array_keys( Ratings::VALUES ), Ratings::VALUES ) ) ); ?>
							<?php else : ?>No props yet.<?php endif; ?>
						</p>
						<div class="mmafc-props__give" data-mmafc-props-give hidden>
							<p class="mmafc-props__label" id="mmafc-props-label">Give props to this Top 10</p>
							<div class="mmafc-props__choices" role="group" aria-labelledby="mmafc-props-label">
								<?php foreach ( Ratings::VALUES as $mmafc_value => $mmafc_label ) : ?>
									<button type="button" class="mmafc-props__choice" data-mmafc-props-value="<?php echo (int) $mmafc_value; ?>" aria-pressed="false"><span class="mmafc-props__dots" aria-hidden="true"><?php echo esc_html( str_repeat( '●', $mmafc_value ) ); ?></span> <?php echo esc_html( $mmafc_label ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<p class="mmafc-hint" data-mmafc-props-reason hidden></p>
						<p class="mmafc-status" data-mmafc-props-status role="status" aria-live="polite"></p>
					</div>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
