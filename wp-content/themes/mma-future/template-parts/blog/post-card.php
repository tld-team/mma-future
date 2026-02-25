<?php
/**
 * Post card for the blog archive grid.
 *
 * Expects to be called inside a WP_Query loop (the_post() already called).
 *
 * @package mma-future
 */

$post_categories = get_the_category();
$first_category  = ! empty( $post_categories ) ? $post_categories[0] : null;
$word_count      = str_word_count( wp_strip_all_tags( get_the_content() ) );
$read_time       = max( 1, (int) ceil( $word_count / 200 ) );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'group' ); ?>>
	<a href="<?php the_permalink(); ?>" class="flex flex-col h-full bg-white rounded-xl ring-1 ring-black/10 shadow-sm overflow-hidden hover:shadow-md hover:ring-black/15 hover:-translate-y-0.5 transition-all duration-200">
		<div class="relative aspect-video overflow-hidden bg-slate-100">
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'medium_large', [
					'class'   => 'w-full h-full object-cover transition-transform duration-300 group-hover:scale-105',
					'loading' => 'lazy',
				] ); ?>
			<?php else : ?>
				<div class="w-full h-full flex items-center justify-center bg-gradient-mma">
					<svg class="w-10 h-10 text-white/20" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 19.5V4.5a2.25 2.25 0 0 0-2.25-2.25H3.75A2.25 2.25 0 0 0 1.5 4.5v15a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
				</div>
			<?php endif; ?>

			<?php if ( $first_category ) : ?>
				<span class="absolute top-3 left-3 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide bg-[var(--brand)] text-white shadow-sm">
					<?php echo esc_html( $first_category->name ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="flex flex-col flex-1 p-5">
			<h3 class="font-heading font-bold text-heading text-lg leading-snug line-clamp-3 mb-2">
				<?php the_title(); ?>
			</h3>

			<p class="text-muted text-sm line-clamp-2 mb-4">
				<?php echo esc_html( get_the_excerpt() ); ?>
			</p>

			<div class="mt-auto flex items-center gap-3 text-xs text-slate-500">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
					<?php echo esc_html( get_the_date() ); ?>
				</time>
				<span class="w-1 h-1 rounded-full bg-slate-300"></span>
				<span>
					<?php
					/* translators: %d: estimated reading time in minutes */
					printf( esc_html__( '%d min read', 'mma-future' ), $read_time );
					?>
				</span>
			</div>
		</div>
	</a>
</article>
