<?php
get_header();
global $wp_query;
$service = mmaf_public_service();
$cards = $service ? $service->fighter_cards(wp_list_pluck($wp_query->posts,'ID')) : [];
$page_number = max( 1, (int) get_query_var( 'paged' ) );
$search_query = mmaf_directory_search_query();
$archive_url = get_post_type_archive_link( 'mmaf_fighter' );
?>
<main id="primary" class="mmaf-directory" data-mmaf-directory>
	<div class="mmaf-container">
		<header class="mmaf-directory__header">
			<div>
				<p class="mmaf-directory__eyebrow">The MMA Future roster</p>
				<h1>Fighter profiles</h1>
				<p class="mmaf-directory__intro">Get to know the fighters. Explore their records, recent fights and career details.</p>
			</div>
			<a class="mmaf-button mmaf-button--secondary" href="<?php echo esc_url( mmaf_rankings_url() ); ?>">View rankings <?php mmaf_view( 'icon-arrow' ); ?></a>
		</header>
		<form class="mmaf-directory__search" role="search" aria-label="Search fighter profiles" method="get" action="<?php echo esc_url( $archive_url ); ?>">
			<div class="mmaf-directory__search-field">
				<svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"></path></svg>
				<input type="search" id="mmaf-fighter-search" name="mmaf_fighter_q" aria-label="Search fighters" aria-controls="mmaf-directory-results" placeholder="Search fighter…" maxlength="128" value="<?php echo esc_attr( $search_query ); ?>">
			</div>
			<button class="mmaf-button" type="submit">Search</button>
			<a class="mmaf-directory__clear" data-directory-clear href="<?php echo esc_url( $archive_url ); ?>"<?php echo '' === $search_query ? ' hidden' : ''; ?>>Clear search</a>
		</form>
		<p class="mmaf-browser-status" role="status" aria-live="polite" data-directory-status></p>
		<div id="mmaf-directory-results" class="mmaf-browser-results" data-directory-results data-total="<?php echo (int) $wp_query->found_posts; ?>" data-page="<?php echo (int) $page_number; ?>" data-total-pages="<?php echo max( 1, (int) $wp_query->max_num_pages ); ?>">
		<?php if ( have_posts() && $service ) : ?>
		<div class="mmaf-directory__summary"><span><?php echo esc_html( number_format_i18n( $wp_query->found_posts ) . ( 1 === (int) $wp_query->found_posts ? ' fighter profile' : ' fighter profiles' ) ); ?></span><span>Page <?php echo (int) $page_number; ?> of <?php echo (int) $wp_query->max_num_pages; ?></span></div>
		<div class="mmaf-directory__grid">
			<?php while ( have_posts() ) : the_post();
				$context = $cards[get_the_ID()] ?? null;
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
						<div><dt><?php echo mmaf_record_label($context); ?></dt><dd><?php echo esc_html( mmaf_fighter_record_available( $context ) ? mmaf_record( mmaf_display_record($context) ) : 'Not available' ); ?></dd></div>
						<?php if ( ! empty( $context['fighter']['in_ufc'] ) ) : ?><div><dt>Status</dt><dd>Signed with the UFC</dd></div><?php else : ?><div><dt>Overall rank</dt><dd><?php echo esc_html( $overall ? '#' . $overall['rank'] : 'Unranked' ); ?></dd></div><?php endif; ?>
					</dl>
					<div class="mmaf-profile-card__footer"><span>View profile</span><?php mmaf_view( 'icon-arrow' ); ?></div>
				</a>
			</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination( array( 'mid_size' => 1, 'prev_text' => 'Previous', 'next_text' => 'Next', 'aria_label' => 'Fighter profiles pages' ) ); ?>
		<?php else : mmaf_view( 'empty-state', array( 'title' => '' !== $search_query ? 'No fighters found' : 'No fighter profiles available', 'description' => '' !== $search_query ? 'No fighter profiles match “' . $search_query . '”. Try another name or clear your search.' : 'Published fighter profiles will appear here.' ) ); endif; ?>
		</div>
	</div>
</main>
<?php get_footer(); ?>
