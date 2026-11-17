<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Routes;

// Template-scope variables are WordPress globals here. Never use names such as $page, $s or $post: get_header() resets them.
// Only the profile row is printed. Anything that depends on who is looking is filled in by the script, so this page stays cacheable.
$mmafc_row   = (array) Routes::public_profile();
$mmafc_view  = ( new Profiles() )->present( $mmafc_row );
$mmafc_fan   = Config::area_enabled( 'fan' );
$mmafc_picks = $mmafc_fan ? ( new FanLists() )->items( (int) $mmafc_row['user_id'] ) : array();
status_header( 200 );
get_header();
?>
<main id="primary" class="mmafc-page">
	<div class="mmafc-shell">
		<article class="mmafc-card mmafc-profile" data-mmafc-public-profile="<?php echo esc_attr( $mmafc_view['handle'] ); ?>">
			<img class="mmafc-avatar mmafc-avatar--xl" src="<?php echo esc_url( $mmafc_view['avatar_url'] ); ?>" alt="" width="128" height="128">
			<div class="mmafc-profile__body">
				<h1 class="mmafc-profile__name"><?php echo esc_html( $mmafc_view['display_name'] ); ?></h1>
				<p class="mmafc-profile__handle">@<?php echo esc_html( $mmafc_view['handle'] ); ?></p>
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
				<ol class="mmafc-rows mmafc-rows--public">
					<?php foreach ( $mmafc_picks as $mmafc_pick ) : $mmafc_name = '' !== $mmafc_pick['display_name'] ? $mmafc_pick['display_name'] : 'Fighter no longer listed'; ?>
						<li class="mmafc-row<?php echo $mmafc_pick['eligible'] ? '' : ' mmafc-row--muted'; ?>">
							<span class="mmafc-row__pos"><?php echo (int) $mmafc_pick['position']; ?></span>
							<?php if ( '' !== $mmafc_pick['image_url'] ) : ?><img class="mmafc-row__img" src="<?php echo esc_url( $mmafc_pick['image_url'] ); ?>" alt="" width="40" height="40" loading="lazy"><?php else : ?><span class="mmafc-row__img mmafc-row__img--empty" aria-hidden="true"></span><?php endif; ?>
							<span class="mmafc-row__body">
								<?php if ( '' !== $mmafc_pick['permalink'] ) : ?><a class="mmafc-row__name" href="<?php echo esc_url( $mmafc_pick['permalink'] ); ?>"><?php echo esc_html( $mmafc_name ); ?></a><?php else : ?><span class="mmafc-row__name"><?php echo esc_html( $mmafc_name ); ?></span><?php endif; ?>
								<span class="mmafc-row__meta"><?php echo esc_html( implode( ' · ', array_filter( array( ucwords( str_replace( '_', ' ', $mmafc_pick['weight_class'] ) ), $mmafc_pick['record'] ) ) ) ); ?></span>
							</span>
							<?php if ( $mmafc_pick['eligible'] ) : ?><span class="mmafc-row__official">Official #<?php echo (int) $mmafc_pick['official_rank']; ?></span><?php else : ?><span class="mmafc-row__note"><?php echo esc_html( $mmafc_pick['note'] ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
