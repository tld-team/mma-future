<?php
$latest_posts = new WP_Query( array(
	'post_type'      => 'post',
	'posts_per_page' => 4,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'post_status'    => 'publish',
) );

if ( ! $latest_posts->have_posts() ) {
	wp_reset_postdata();
	return;
}

$blog_page_id = get_option( 'page_for_posts' );
$blog_url     = $blog_page_id ? get_permalink( $blog_page_id ) : home_url( '/blog/' );
?>
<section class="blog-latest-posts-section py-12 sm:py-16">
	<div class="blog-latest-posts-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

		<div class="blog-latest-posts-section__header text-center">
			<h2 class="blog-latest-posts-section__title text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
				Latest from the blog
			</h2>
			<p class="blog-latest-posts-section__subtitle mt-3 max-w-2xl mx-auto text-base sm:text-lg text-slate-600 leading-relaxed">
				Analysis, breakdowns, and insights from the world of MMA — backed by data, not hype.
			</p>
		</div>

		<div class="blog-latest-posts-section__grid mt-8 sm:mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
			<?php while ( $latest_posts->have_posts() ) : $latest_posts->the_post(); ?>
				<?php
				$permalink  = esc_url( get_permalink() );
				$title      = esc_html( get_the_title() );
				$excerpt    = get_the_excerpt();
				if ( ! $excerpt ) {
					$excerpt = wp_trim_words( get_the_content(), 20, '&hellip;' );
				}
				$word_count = str_word_count( wp_strip_all_tags( get_the_content() ) );
				$read_time  = max( 1, (int) ceil( $word_count / 200 ) );
				$date       = esc_html( get_the_date( 'M j, Y' ) );
				$categories = get_the_category();
				$category   = ! empty( $categories ) ? $categories[0] : null;
				?>
				<a href="<?php echo $permalink; ?>"
				   class="blog-latest-posts-section__card group rounded-2xl border border-slate-200/70 bg-white overflow-hidden transition-colors hover:border-slate-300 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">

					<div class="blog-latest-posts-section__thumb relative aspect-video bg-slate-100 overflow-hidden">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php echo get_the_post_thumbnail( get_the_ID(), 'medium_large', array(
								'class'   => 'blog-latest-posts-section__thumb-img w-full h-full object-cover transition-transform duration-300 group-hover:scale-105',
								'loading' => 'lazy',
							) ); ?>
						<?php else : ?>
							<div class="blog-latest-posts-section__thumb-img w-full h-full flex items-center justify-center bg-slate-100">
								<svg class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/>
								</svg>
							</div>
						<?php endif; ?>

						<?php if ( $category ) : ?>
							<span class="blog-latest-posts-section__badge absolute top-3 left-3 inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wider bg-white text-[#0047A8] shadow-sm">
								<?php echo esc_html( $category->name ); ?>
							</span>
						<?php endif; ?>
					</div>

					<div class="blog-latest-posts-section__content p-5">
						<h3 class="blog-latest-posts-section__post-title text-base font-bold text-slate-900 leading-snug line-clamp-2">
							<?php echo $title; ?>
						</h3>

						<?php if ( $excerpt ) : ?>
							<p class="blog-latest-posts-section__excerpt mt-2 text-sm text-slate-600 leading-relaxed line-clamp-2">
								<?php echo esc_html( $excerpt ); ?>
							</p>
						<?php endif; ?>

						<div class="blog-latest-posts-section__meta mt-4 flex items-center gap-3 text-xs text-slate-500">
							<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo $date; ?></time>
							<span class="w-1 h-1 rounded-full bg-slate-300" aria-hidden="true"></span>
							<span><?php echo esc_html( $read_time ); ?> min read</span>
						</div>
					</div>
				</a>
			<?php endwhile; ?>
		</div>

		<div class="blog-latest-posts-section__cta-wrap mt-10 flex justify-center">
			<a href="<?php echo esc_url( $blog_url ); ?>"
			   class="blog-latest-posts-section__cta inline-flex items-center justify-center h-11 px-5 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-[#0047A8] hover:text-white hover:border-[#0047A8] hover:shadow-md transition-all duration-200 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
				View all articles
			</a>
		</div>

	</div>
</section>
<?php wp_reset_postdata(); ?>
