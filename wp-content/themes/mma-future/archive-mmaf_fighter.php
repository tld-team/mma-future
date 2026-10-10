<?php
get_header();
global $wp_query;
$service = mmaf_public_service();
$repository = $service ? new \MMAF\DataEngine\Repositories\RestReadRepository() : null;
$page_number = max( 1, (int) get_query_var( 'paged' ) );
?>
<main id="primary" class="mmaf-directory">
	<div class="mmaf-container">
		<header class="mmaf-directory__header">
			<div>
				<p class="mmaf-directory__eyebrow">The MMA Future roster</p>
				<h1>Fighter profiles</h1>
				<p class="mmaf-directory__intro">Get to know the fighters. Explore their records, recent fights and career details.</p>
			</div>
			<a class="mmaf-button mmaf-button--secondary" href="<?php echo esc_url( mmaf_rankings_url() ); ?>">View rankings <?php mmaf_view( 'icon-arrow' ); ?></a>
		</header>
		<?php if ( have_posts() && $service ) : ?>
		<div class="mmaf-directory__summary"><span><?php echo esc_html( number_format_i18n( $wp_query->found_posts ) ); ?> fighter profiles</span><span>Page <?php echo (int) $page_number; ?> of <?php echo (int) $wp_query->max_num_pages; ?></span></div>
		<div class="mmaf-directory__grid">
			<?php while ( have_posts() ) : the_post();
				$fighter = $repository->fighter_by_wp_post_id( get_the_ID() );
				$context = $fighter ? $service->fighter( (int) $fighter['id'] ) : null;
				if ( ! $context || is_wp_error( $context ) ) { continue; }
				$facts = mmaf_fighter_facts( $context['fighter'] );
				$overall = null;
				foreach ( $context['rankings'] as $ranking ) { if ( 'overall' === $ranking['board'] ) { $overall = $ranking; break; } }
			?>
			<article class="mmaf-profile-card">
				<a class="mmaf-profile-card__link" href="<?php echo esc_url( $context['profile']['permalink'] ); ?>">
					<div class="mmaf-profile-card__identity">
						<?php mmaf_fighter_image( $context, 'mmaf-profile-card__portrait' ); ?>
						<div><p class="mmaf-profile-card__division"><?php echo esc_html( $facts['Division'] ?: 'MMA fighter' ); ?></p><h2><?php echo esc_html( $context['fighter']['display_name'] ); ?></h2><?php if ( $facts['Nationality'] ) : ?><p class="mmaf-profile-card__country"><?php echo esc_html( $facts['Nationality'] ); ?></p><?php endif; ?></div>
					</div>
					<dl class="mmaf-profile-card__stats">
						<div><dt><?php echo 'complete' === $context['career_coverage']['status'] ? 'Pro record' : 'Recorded fights'; ?></dt><dd><?php echo esc_html( mmaf_fighter_record_available( $context ) ? mmaf_record( $context['stats'] ) : 'Not available' ); ?></dd></div>
						<div><dt>Overall rank</dt><dd><?php echo esc_html( $overall ? '#' . $overall['rank'] : 'Unranked' ); ?></dd></div>
					</dl>
					<div class="mmaf-profile-card__footer"><span>View profile</span><?php mmaf_view( 'icon-arrow' ); ?></div>
				</a>
			</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( array( 'mid_size' => 1, 'prev_text' => 'Previous', 'next_text' => 'Next', 'aria_label' => 'Fighter profiles pages' ) ); ?>
		<?php else : mmaf_view( 'empty-state', array( 'title' => 'No fighter profiles available', 'description' => 'Published fighter profiles will appear here.' ) ); endif; ?>
	</div>
</main>
<?php get_footer(); ?>
