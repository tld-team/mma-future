<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Routes;

// Template-scope variables are WordPress globals here. Never use names such as $page, $s or $post: get_header() resets them.
// Only the profile row is printed. Anything that depends on who is looking is filled in by the script, so this page stays cacheable.
$mmafc_view = ( new Profiles() )->present( (array) Routes::public_profile() );
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
				<p class="mmafc-profile__owner" data-mmafc-owner hidden><a class="mmafc-button mmafc-button--secondary" href="<?php echo esc_url( home_url( '/account/profile/' ) ); ?>">Edit profile</a></p>
			</div>
		</article>
	</div>
</main>
<?php
get_footer();
