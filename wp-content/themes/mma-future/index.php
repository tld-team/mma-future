<?php
/**
 * The main template file — Blog listing
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 * @package mma-future
 */

get_header();

if (!function_exists('mma_build_blog_url')) {
	/**
	 * Build a blog URL preserving current query params with optional overrides.
	 */
	function mma_build_blog_url($base_url, $overrides = [], $remove = [])
	{
		$params = [];

		if (!empty($_GET['s'])) { // phpcs:ignore WordPress.Security.NonceVerification
			$params['s'] = sanitize_text_field(wp_unslash($_GET['s'])); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if (!empty($_GET['cat'])) { // phpcs:ignore WordPress.Security.NonceVerification
			$params['cat'] = absint($_GET['cat']); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if (!empty($_GET['sort']) && 'newest' !== $_GET['sort']) { // phpcs:ignore WordPress.Security.NonceVerification
			$params['sort'] = sanitize_key($_GET['sort']); // phpcs:ignore WordPress.Security.NonceVerification
		}

		foreach ($overrides as $key => $value) {
			$params[$key] = $value;
		}
		foreach ($remove as $key) {
			unset($params[$key]);
		}

		$params = array_filter($params);

		return $params ? add_query_arg($params, $base_url) : $base_url;
	}
}

/* ---------- Base blog URL ---------- */

$page_for_posts = get_option('page_for_posts');
$blog_base_url = $page_for_posts ? get_permalink($page_for_posts) : home_url('/blog/');

/* ---------- Sanitise inputs ---------- */

$cat_id = isset($_GET['cat']) ? absint($_GET['cat']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$sort = isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'newest'; // phpcs:ignore WordPress.Security.NonceVerification
$paged = get_query_var('paged') ?: get_query_var('page') ?: 1;

/* ---------- Sort whitelist ---------- */

$sort_map = [
	'newest' => ['orderby' => 'date', 'order' => 'DESC'],
	'oldest' => ['orderby' => 'date', 'order' => 'ASC'],
	'comments' => ['orderby' => 'comment_count', 'order' => 'DESC'],
	'title_az' => ['orderby' => 'title', 'order' => 'ASC'],
];

$current_sort = isset($sort_map[$sort]) ? $sort_map[$sort] : $sort_map['newest'];

/* ---------- Custom WP_Query ---------- */

$query_args = [
	'post_type' => 'post',
	'posts_per_page' => 9,
	'paged' => $paged,
	'orderby' => $current_sort['orderby'],
	'order' => $current_sort['order'],
	'ignore_sticky_posts' => 1,
];

if ($search) {
	$query_args['s'] = $search;
}
if ($cat_id > 0) {
	$query_args['cat'] = $cat_id;
}

$blog_query = new WP_Query($query_args);

/* ---------- Category tabs ---------- */

$all_categories = get_categories([
	'orderby' => 'count',
	'order'   => 'DESC',
	'hide_empty' => true,
]);

$visible_cats  = array_slice( $all_categories, 0, 8 );
$overflow_cats = array_slice( $all_categories, 8 );

$visible_ids = wp_list_pluck( $visible_cats, 'term_id' );
if ( $cat_id > 0 && ! in_array( $cat_id, $visible_ids, true ) ) {
	foreach ( $overflow_cats as $idx => $oc ) {
		if ( (int) $oc->term_id === $cat_id ) {
			$visible_cats[7]    = $oc;
			$overflow_cats[$idx] = $all_categories[7];
			$overflow_cats       = array_values( $overflow_cats );
			break;
		}
	}
}

/* ---------- Sort labels ---------- */

$sort_labels = [
	'newest' => __('Newest', 'mma-future'),
	'oldest' => __('Oldest', 'mma-future'),
	'comments' => __('Most Commented', 'mma-future'),
	'title_az' => __('Title A–Z', 'mma-future'),
];
?>

<main id="primary" class="site-main">

	<!-- ==================== Hero ==================== -->
	<section class="bg-gradient-mma py-12 lg:py-16">
		<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
			<div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
				<div>
					<h1 class="font-heading font-extrabold text-gray-900 text-5xl md:text-6xl lg:text-8xl">
						<?php esc_html_e('Blog', 'mma-future'); ?>
					</h1>
					<p class="mt-2 !mb-2 text-slate-600 text-base md:text-lg max-w-xl">
						<?php esc_html_e('Fight analysis, rankings insights, event breakdowns, training & strategy', 'mma-future'); ?>
					</p>
				</div>

				<form action="<?php echo esc_url($blog_base_url); ?>" method="get"
					class="w-full md:w-auto md:min-w-[320px]">
					<?php if ($cat_id): ?>
						<input type="hidden" name="cat" value="<?php echo esc_attr($cat_id); ?>">
					<?php endif; ?>
					<?php if ($sort && 'newest' !== $sort): ?>
						<input type="hidden" name="sort" value="<?php echo esc_attr($sort); ?>">
					<?php endif; ?>
					<label for="blog-search"
						class="sr-only"><?php esc_html_e('Search posts', 'mma-future'); ?></label>
					<div class="relative">
						<input type="search" id="blog-search" name="s" value="<?php echo esc_attr($search); ?>"
							placeholder="<?php esc_attr_e('Search posts…', 'mma-future'); ?>"
							class="w-full h-12 pl-10 pr-4 rounded-lg shadow-sm bg-white/10 text-white placeholder-slate-400 ring-1 ring-white/20 focus:ring-2 focus:ring-primary-400 focus:outline-none transition-all text-sm"
							style="padding-left: 40px !important; border-radius: 8px !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;">
						<svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"
							xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
							stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round"
								d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
						</svg>
					</div>
				</form>
			</div>
		</div>
	</section>

	<!-- ==================== Filters ==================== -->
	<?php
	$total_posts = (int) wp_count_posts( 'post' )->publish;
	$has_filters = ( $cat_id > 0 ) || $search || ( 'newest' !== $sort );

	$active_cat_name = __( 'All', 'mma-future' );
	if ( $cat_id > 0 ) {
		$active_cat_obj = get_term( $cat_id, 'category' );
		if ( $active_cat_obj && ! is_wp_error( $active_cat_obj ) ) {
			$active_cat_name = $active_cat_obj->name;
		}
	}
	$found_posts = (int) $blog_query->found_posts;
	?>
	<section class="bg-slate-50/60">
		<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 -mt-5 relative z-10 pb-2">

			<!-- Row 1: Categories -->
			<div class="relative pt-3 mb-3 pb-0">
				<div
					class="flex items-center gap-2 overflow-x-auto whitespace-nowrap flex-nowrap thin-scrollbar"
					role="navigation"
					aria-label="<?php esc_attr_e( 'Category filter', 'mma-future' ); ?>"
				>
					<a
						href="<?php echo esc_url( mma_build_blog_url( $blog_base_url, [], [ 'cat' ] ) ); ?>"
						class="shrink-0 h-8 px-3 inline-flex items-center gap-1.5 rounded-full border text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-1 <?php echo 0 === $cat_id ? 'bg-[var(--brand)] text-white border-[var(--brand)]' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50 hover:border-slate-300'; ?>"
					>
						<?php esc_html_e( 'All', 'mma-future' ); ?>
						<?php if ( $total_posts > 0 ) : ?>
							<span class="ml-0.5 inline-flex items-center rounded-full px-1.5 py-0.5 text-[11px] font-semibold leading-none tabular-nums bg-slate-100 text-slate-600"><?php echo esc_html( $total_posts ); ?></span>
						<?php endif; ?>
					</a>

					<?php foreach ( $visible_cats as $category ) :
						$is_active = $cat_id === (int) $category->term_id;
					?>
						<a
							href="<?php echo esc_url( mma_build_blog_url( $blog_base_url, [ 'cat' => $category->term_id ] ) ); ?>"
							class="shrink-0 h-8 px-3 inline-flex items-center gap-1.5 rounded-full border text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-1 <?php echo $is_active ? 'bg-[var(--brand)] text-white border-[var(--brand)]' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50 hover:border-slate-300'; ?>"
						>
							<?php echo esc_html( $category->name ); ?>
							<?php if ( $category->count > 0 ) : ?>
								<span class="ml-0.5 inline-flex items-center rounded-full px-1.5 py-0.5 text-[11px] font-semibold leading-none tabular-nums bg-slate-100 text-slate-600"><?php echo esc_html( $category->count ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>

					<?php if ( ! empty( $overflow_cats ) ) : ?>
						<details class="shrink-0 relative">
							<summary class="h-8 px-3 inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white text-sm font-medium text-slate-700 cursor-pointer transition hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-1 list-none [&::-webkit-details-marker]:hidden">
								<?php esc_html_e( 'More', 'mma-future' ); ?>
								<svg class="w-3.5 h-3.5 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
							</summary>
							<div class="absolute left-0 top-full mt-1 z-50 min-w-[200px] rounded-xl border border-slate-200 bg-white shadow-lg p-1.5">
								<?php foreach ( $overflow_cats as $oc ) :
									$oc_active = $cat_id === (int) $oc->term_id;
								?>
									<a
										href="<?php echo esc_url( mma_build_blog_url( $blog_base_url, [ 'cat' => $oc->term_id ] ) ); ?>"
										class="flex items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm transition <?php echo $oc_active ? 'bg-[var(--brand)] text-white font-medium' : 'text-slate-700 hover:bg-slate-50'; ?>"
									>
										<?php echo esc_html( $oc->name ); ?>
										<span class="text-xs tabular-nums <?php echo $oc_active ? 'text-white/70' : 'text-slate-400'; ?>"><?php echo esc_html( $oc->count ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
				</div>
				<div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-[#f8fafc99] to-transparent" aria-hidden="true"></div>
			</div>

			<!-- Divider -->
			<div class="border-b border-slate-200/70"></div>

			<!-- Row 2: Context + Sort -->
			<div class="flex flex-col min-[440px]:flex-row min-[440px]:items-center justify-between gap-0 min-[440px]:gap-2 py-3">
				<div class="h-9 flex items-center !mb-1 min-[440px]:!mb-0">
					<p class="text-sm text-slate-500 !mb-0">
						<?php
						printf(
							/* translators: 1: category name, 2: post count */
							esc_html__( 'Showing: %1$s · %2$s posts', 'mma-future' ),
							'<span class="font-medium text-slate-700">' . esc_html( $active_cat_name ) . '</span>',
							'<span class="tabular-nums font-medium text-slate-700">' . esc_html( $found_posts ) . '</span>'
						);
						?>
					</p>
				</div>

				<div class="flex items-center gap-3 min-[440px]:ml-auto shrink-0">
					<?php if ( $has_filters ) : ?>
						<a
							href="<?php echo esc_url( $blog_base_url ); ?>"
							class="h-9 inline-flex items-center gap-1 text-sm text-blue-600 transition hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-1"
							title="<?php esc_attr_e( 'Clear all filters', 'mma-future' ); ?>"
						>
							<svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
							<?php esc_html_e( 'Clear', 'mma-future' ); ?>
						</a>
					<?php endif; ?>

					<form action="<?php echo esc_url( $blog_base_url ); ?>" method="get" class="relative">
						<?php if ( $search ) : ?>
							<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
						<?php endif; ?>
						<?php if ( $cat_id ) : ?>
							<input type="hidden" name="cat" value="<?php echo esc_attr( $cat_id ); ?>">
						<?php endif; ?>
						<label for="blog-sort" class="sr-only"><?php esc_html_e( 'Sort posts', 'mma-future' ); ?></label>
						<select
							id="blog-sort"
							name="sort"
							onchange="this.form.submit()"
							class="h-9 w-[160px] appearance-none rounded-full border border-slate-200/70 bg-white pl-4 pr-10 text-sm text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-1 cursor-pointer"
						>
							<?php foreach ( $sort_labels as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sort, $key ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
					</form>
				</div>
			</div>

		</div>
	</section>

	<!-- ==================== Posts grid ==================== -->
	<section class="py-12 lg:py-16 bg-secondary-50">
		<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

			<?php if ($blog_query->have_posts()): ?>

				<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
					<?php
					while ($blog_query->have_posts()):
						$blog_query->the_post();
						get_template_part('template-parts/blog/post-card');
					endwhile;
					?>
				</div>

				<?php
				/* ---------- Pagination ---------- */

				$paginate_add_args = [];
				if ($cat_id > 0) {
					$paginate_add_args['cat'] = $cat_id;
				}
				if ($search) {
					$paginate_add_args['s'] = $search;
				}
				if ($sort && 'newest' !== $sort) {
					$paginate_add_args['sort'] = $sort;
				}

				$pagination = paginate_links([
					'base' => trailingslashit($blog_base_url) . '%_%',
					'format' => '?paged=%#%',
					'current' => (int) $paged,
					'total' => $blog_query->max_num_pages,
					'add_args' => $paginate_add_args ?: false,
					'prev_text' => '<svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg><span class="font-normal">' . esc_html__('Prev', 'mma-future') . '</span>',
					'next_text' => '<span class="font-normal">' . esc_html__('Next', 'mma-future') . '</span><svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>',
					'type' => 'array',
				]);

				if ($pagination):
					?>
					<nav class="pg mt-12 flex justify-center"
						aria-label="<?php esc_attr_e('Posts pagination', 'mma-future'); ?>">
						<div class="pg__list">
							<?php
							foreach ($pagination as $link) {
								if (false !== strpos($link, 'prev') || false !== strpos($link, 'next')) {
									$cls = 'pg__btn';
								} elseif (false !== strpos($link, 'current')) {
									$cls = 'pg__num pg__num--active';
								} elseif (false !== strpos($link, 'dots')) {
									$cls = 'pg__dots';
								} else {
									$cls = 'pg__num';
								}
								echo preg_replace( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() output with class swap
									'/class=["\'][^"\']*["\']/',
									'class="' . esc_attr($cls) . '"',
									$link,
									1
								);
							}
							?>
						</div>
					</nav>
				<?php endif; ?>

				<?php wp_reset_postdata(); ?>

			<?php else: ?>

				<!-- Empty state -->
				<div class="flex flex-col items-center justify-center py-20 text-center">
					<div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-6">
						<svg class="w-7 h-7 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none"
							viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round"
								d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6V7.5Z" />
						</svg>
					</div>
					<h2 class="font-heading font-bold text-xl text-heading mb-2">
						<?php esc_html_e('No posts found', 'mma-future'); ?>
					</h2>
					<p class="text-muted text-sm mb-6 max-w-sm">
						<?php esc_html_e('Try changing filters or clearing your search.', 'mma-future'); ?>
					</p>
					<a href="<?php echo esc_url($blog_base_url); ?>"
						class="inline-flex items-center gap-2 h-10 px-6 rounded-xl bg-[var(--brand)] text-white text-sm font-semibold ring-1 ring-black/10 shadow-sm hover:bg-[var(--brand-hover)] hover:shadow-md hover:-translate-y-0.5 transition-all duration-200"><?php esc_html_e('Reset Filters', 'mma-future'); ?></a>
				</div>

			<?php endif; ?>

		</div>
	</section>

</main>

<?php
get_footer();
