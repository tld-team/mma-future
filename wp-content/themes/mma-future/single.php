<?php
/**
 * Single blog post template — premium editorial layout.
 *
 * @package mma-future
 */

get_header();

if (is_singular('post')):

	while (have_posts()):
		the_post();

		/* ------------------------------------------------------------------ */
		/*  DATA PREP                                                          */
		/* ------------------------------------------------------------------ */
		$post_id = get_the_ID();
		$categories = get_the_category();
		$first_cat = !empty($categories) ? $categories[0] : null;
		$tags = get_the_tags();
		$content_raw = get_the_content();
		$word_count = str_word_count(wp_strip_all_tags($content_raw));
		$read_time = max(1, (int) ceil($word_count / 200));

		$excerpt = get_the_excerpt();
		if (empty($excerpt)) {
			$excerpt = wp_trim_words(wp_strip_all_tags($content_raw), 24, '…');
		}

		$pub_date = get_the_date();
		$pub_iso = get_the_date('c');
		$mod_date = get_the_modified_date();
		$mod_iso = get_the_modified_date('c');
		$show_mod = (get_the_modified_date('U') > get_the_date('U'));

		$author_id = get_the_author_meta('ID');
		$author_name = get_the_author();
		$author_url = get_author_posts_url($author_id);
		$author_bio = get_the_author_meta('description');
		$author_avatar = get_avatar_url($author_id, ['size' => 96]);

		$permalink = get_the_permalink();

		/* Optional meta fields */
		$key_takeaways = get_post_meta($post_id, 'key_takeaways', true);
		$key_takeaways_text = get_post_meta($post_id, 'key_takeaways_text', true);
		$related_fighters = get_post_meta($post_id, 'related_fighters', true);
		$related_events = get_post_meta($post_id, 'related_events', true);

		/* Featured image caption */
		$thumb_id = get_post_thumbnail_id();
		$thumb_caption = $thumb_id ? wp_get_attachment_caption($thumb_id) : '';
		?>

		<!-- ============================================================
	 B) READING PROGRESS BAR
	 ============================================================ -->
		<div id="js-progress-bar" class="fixed top-0 left-0 h-[3px] z-[100]"
			style="width:0;background:var(--brand,#0047A8);will-change:width;"></div>

		<main id="primary" class="site-main bg-white">

			<!-- ============================================================
		 A) POST HEADER / HERO
		 ============================================================ -->
			<header class="pt-8 pb-6 sm:pt-12 sm:pb-8">
				<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

					<?php if ($first_cat): ?>
						<a href="<?php echo esc_url(get_category_link($first_cat->term_id)); ?>"
							class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wide mb-4 transition-colors"
							style="background:rgba(var(--brand-rgb),0.08);color:var(--brand);">
							<?php echo esc_html($first_cat->name); ?>
						</a>
					<?php endif; ?>

					<h1 class="font-heading font-bold text-slate-900 leading-tight mb-4"
						style="font-size:clamp(1.75rem,4vw,2.75rem);">
						<?php the_title(); ?>
					</h1>

					<?php if ($excerpt): ?>
						<p class="text-lg sm:text-xl text-slate-500 leading-relaxed mb-6 max-w-6xl">
							<?php echo esc_html($excerpt); ?>
						</p>
					<?php endif; ?>

					<!-- Meta row -->
					<div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500 mb-4">
						<a href="<?php echo esc_url($author_url); ?>"
							class="flex items-center gap-2 hover:text-slate-700 transition-colors">
							<img src="<?php echo esc_url($author_avatar); ?>" alt="<?php echo esc_attr($author_name); ?>"
								class="w-6 h-6 max-w-[48px] max-h-[48px] rounded-full object-cover ring-1 ring-slate-200"
								loading="lazy" />
							<span class="font-medium text-slate-700"><?php echo esc_html($author_name); ?></span>
						</a>
						<span class="w-1 h-1 rounded-full bg-slate-300 hidden sm:block"></span>
						<time datetime="<?php echo esc_attr($pub_iso); ?>"><?php echo esc_html($pub_date); ?></time>
						<span class="w-1 h-1 rounded-full bg-slate-300 hidden sm:block"></span>
						<span><?php printf(esc_html__('%d min read', 'mma-future'), $read_time); ?></span>
						<?php if ($show_mod): ?>
							<span class="w-1 h-1 rounded-full bg-slate-300 hidden sm:block"></span>
							<span class="italic">
								<?php printf(esc_html__('Updated %s', 'mma-future'), esc_html($mod_date)); ?>
							</span>
						<?php endif; ?>
					</div>

					<!-- Action row -->
					<?php
					$blog_page_id = get_option('page_for_posts');
					$blog_url = $blog_page_id ? get_permalink($blog_page_id) : home_url('/blog/');
					$newsletter_url = home_url('/newsletter/');
					?>
					<div class="!flex !flex-wrap !items-center !justify-between !gap-x-4 !gap-y-2 !w-full" id="js-action-bar"
						data-post-id="<?php echo (int) $post_id; ?>">
						<div class="!flex !items-center !gap-1">
							<a href="<?php echo esc_url($blog_url); ?>"
								class="group !inline-flex !items-center !gap-2 !h-9 !px-2.5 !rounded-lg !text-sm !font-medium !text-slate-600 !no-underline !shadow-none transition-all hover:!bg-slate-50 hover:!text-slate-900 focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-blue-500 focus-visible:!ring-offset-2 active:!translate-y-[1px]">
								<svg class="!w-4 !h-4 !shrink-0 !text-slate-400 group-hover:!text-slate-600 transition-colors"
									fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round"
										d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
								</svg>
								<span><?php esc_html_e('All posts', 'mma-future'); ?></span>
							</a>
							<span class="!mx-1 !h-4 !w-px !bg-slate-200 !inline-block"></span>
							<a href="<?php echo esc_url($newsletter_url); ?>"
								class="group !inline-flex !items-center !gap-2 !h-9 !px-2.5 !rounded-lg !text-sm !font-medium !text-slate-600 !no-underline !shadow-none transition-all hover:!bg-slate-50 hover:!text-slate-900 focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-blue-500 focus-visible:!ring-offset-2 active:!translate-y-[1px]">
								<svg class="!w-4 !h-4 !shrink-0 !text-slate-400 group-hover:!text-slate-600 transition-colors"
									fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round"
										d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
								</svg>
								<span><?php esc_html_e('Newsletter', 'mma-future'); ?></span>
							</a>
						</div>
						<button type="button" data-action="copy"
							class="!shrink-0 !whitespace-nowrap !leading-none !inline-flex !items-center !gap-2 !h-10 !px-4 !rounded-lg !border !border-transparent !bg-[#0047A8] hover:!bg-[#003a8a] !text-white !text-sm !font-medium !shadow-none transition-all focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-blue-500 focus-visible:!ring-offset-2 active:!translate-y-[1px] !cursor-pointer">
							<svg data-icon-copy class="!w-4 !h-4 !shrink-0" fill="none" stroke="currentColor" stroke-width="2"
								viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round"
									d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
							</svg>
							<svg data-icon-check class="!w-4 !h-4 !shrink-0 hidden" fill="none" stroke="currentColor"
								stroke-width="2.5" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
							</svg>
							<span data-label
								class="!inline-block !leading-none"><?php esc_html_e('Copy link', 'mma-future'); ?></span>
						</button>
					</div>
				</div>

				<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
					<hr class="border-slate-200/70" />
				</div>
			</header>

			<!-- ============================================================
		 C) FEATURED IMAGE
		 ============================================================ -->
			<?php if (has_post_thumbnail()): ?>
				<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mb-8 sm:mb-12">
					<figure class="m-0">
						<?php the_post_thumbnail('large', [
							'class' => 'w-full rounded-xl sm:rounded-2xl object-cover aspect-video',
							'loading' => 'eager',
						]); ?>
						<?php if ($thumb_caption): ?>
							<figcaption class="mt-3 text-center text-sm text-slate-400 italic">
								<?php echo esc_html($thumb_caption); ?>
							</figcaption>
						<?php endif; ?>
					</figure>
				</div>
			<?php endif; ?>

			<!-- ============================================================
		 D) MAIN 3-COLUMN LAYOUT
		 ============================================================ -->
			<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
				<div class="grid grid-cols-12 gap-8">

					<!-- ========== MIDDLE: ARTICLE ========== -->
					<div class="col-span-12 lg:col-span-9">

						<?php
						/* ============================================================
						   F) KEY TAKEAWAYS CALLOUT (optional)
						   ============================================================ */
						$takeaway_items = [];
						if (is_array($key_takeaways) && !empty($key_takeaways)) {
							$takeaway_items = $key_takeaways;
						} elseif (is_string($key_takeaways_text) && trim($key_takeaways_text) !== '') {
							$takeaway_items = array_filter(array_map('trim', explode("\n", $key_takeaways_text)));
						}
						if (!empty($takeaway_items)):
							$takeaway_items = array_slice($takeaway_items, 0, 6);
							?>
							<div class="mb-8 rounded-xl p-5 sm:p-6 border border-blue-200/60"
								style="background:rgba(var(--brand-rgb),0.04);">
								<h2 class="font-heading text-base font-bold text-slate-800 mb-4 flex items-center gap-2"
									style="font-size:1.05rem;">
									<svg class="w-5 h-5 flex-shrink-0" style="color:var(--brand);" fill="none" stroke="currentColor"
										stroke-width="2" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round"
											d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
									</svg>
									<?php esc_html_e('Key Takeaways', 'mma-future'); ?>
								</h2>
								<ol class="space-y-2.5 list-none m-0 p-0">
									<?php foreach ($takeaway_items as $idx => $item): ?>
										<li class="flex items-start gap-3 text-sm text-slate-700 leading-relaxed">
											<span
												class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold text-white mt-0.5"
												style="background:var(--brand);">
												<?php echo (int) ($idx + 1); ?>
											</span>
											<span><?php echo esc_html($item); ?></span>
										</li>
									<?php endforeach; ?>
								</ol>
							</div>
						<?php endif; ?>

						<!-- ============================================================
					 G) ARTICLE CONTENT
					 ============================================================ -->
						<article id="post-<?php the_ID(); ?>" <?php post_class('js-post-content'); ?>>
							<div class="
						text-slate-700 text-base sm:text-[17px] leading-7 sm:leading-8
						[&>h2]:font-heading [&>h2]:font-bold [&>h2]:text-slate-900 [&>h2]:text-xl [&>h2]:sm:text-2xl [&>h2]:mt-10 [&>h2]:mb-4 [&>h2]:leading-tight
						[&>h3]:font-heading [&>h3]:font-semibold [&>h3]:text-slate-800 [&>h3]:text-lg [&>h3]:sm:text-xl [&>h3]:mt-8 [&>h3]:mb-3 [&>h3]:leading-snug
						[&>h4]:font-heading [&>h4]:font-semibold [&>h4]:text-slate-800 [&>h4]:text-base [&>h4]:sm:text-lg [&>h4]:mt-6 [&>h4]:mb-2
						[&>p]:mb-5
						[&>ul]:mb-5 [&>ul]:pl-5 [&>ul]:list-disc [&>ul]:space-y-1
						[&>ol]:mb-5 [&>ol]:pl-5 [&>ol]:list-decimal [&>ol]:space-y-1
						[&>blockquote]:border-l-4 [&>blockquote]:pl-5 [&>blockquote]:py-2 [&>blockquote]:my-6 [&>blockquote]:italic [&>blockquote]:text-slate-500
						[&>a]:font-medium [&>a]:underline [&>a]:underline-offset-2
						[&_a]:font-medium [&_a]:underline [&_a]:underline-offset-2
						[&>img]:rounded-xl [&>img]:my-6
						[&>figure]:my-6 [&>figure_img]:rounded-xl
						[&>hr]:my-8 [&>hr]:border-slate-200/70
					" style="--tw-prose-links:var(--brand);">
								<?php
								the_content();

								wp_link_pages([
									'before' => '<div class="flex items-center gap-2 mt-8 pt-6 border-t border-slate-200/70 text-sm text-slate-500">',
									'after' => '</div>',
								]);
								?>
							</div>
						</article>

						<!-- Inline link color for article anchors -->
						<style>
							.js-post-content a:not([class]) {
								color: var(--brand);
							}

							.js-post-content blockquote {
								border-color: var(--brand);
							}
						</style>

						<!-- ============================================================
					 I) AUTHOR BIO
					 ============================================================ -->
						<div class="mt-6 pt-5 border-t border-slate-200/70">
							<div class="flex items-center gap-3">
								<a href="<?php echo esc_url($author_url); ?>" class="shrink-0">
									<img src="<?php echo esc_url($author_avatar); ?>"
										alt="<?php echo esc_attr($author_name); ?>"
										class="w-10 h-10 max-w-[40px] max-h-[40px] rounded-full object-cover ring-1 ring-slate-200"
										loading="lazy" />
								</a>
								<div class="min-w-0">
									<div class="flex items-center gap-1.5">
										<span class="text-xs text-slate-400"><?php esc_html_e('Written by', 'mma-future'); ?></span>
										<a href="<?php echo esc_url($author_url); ?>"
											class="font-heading font-semibold text-slate-900 text-sm hover:opacity-80 transition-opacity">
											<?php echo esc_html($author_name); ?>
										</a>
									</div>
									<?php if ($author_bio): ?>
										<p class="text-xs text-slate-500 leading-normal mt-0.5 line-clamp-1 mb-0">
											<?php echo esc_html($author_bio); ?>
										</p>
									<?php endif; ?>
									<?php
									$social_fields = [
										'twitter' => ['fab fa-x-twitter', 'Twitter'],
										'facebook' => ['fab fa-facebook-f', 'Facebook'],
										'instagram' => ['fab fa-instagram', 'Instagram'],
										'linkedin' => ['fab fa-linkedin-in', 'LinkedIn'],
									];
									$has_social = false;
									foreach ($social_fields as $key => $meta) {
										$val = get_the_author_meta($key, $author_id);
										if ($val) {
											$has_social = true;
											break;
										}
									}
									if ($has_social): ?>
										<div class="flex items-center gap-2 mt-3">
											<?php foreach ($social_fields as $key => $meta):
												$val = get_the_author_meta($key, $author_id);
												if (!$val)
													continue;
												$url = ($key === 'twitter') ? 'https://x.com/' . ltrim($val, '@') : $val;
												?>
												<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"
													class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 hover:text-white transition-colors"
													style="--hover-bg:var(--brand);" onmouseenter="this.style.background='var(--brand)'"
													onmouseleave="this.style.background=''"
													aria-label="<?php echo esc_attr($meta[1]); ?>">
													<i class="<?php echo esc_attr($meta[0]); ?> text-xs"></i>
												</a>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- ============================================================
					 J) TAGS ROW (optional)
					 ============================================================ -->
						<?php if ($tags && !is_wp_error($tags)): ?>
							<div class="mt-6 flex flex-wrap gap-2">
								<?php foreach ($tags as $tag): ?>
									<a href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>"
										class="inline-block px-3 py-1 rounded-full text-xs font-medium text-slate-500 border border-slate-200 hover:border-slate-300 hover:text-slate-700 transition-colors">
										#<?php echo esc_html($tag->name); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

					</div><!-- /middle column -->

					<!-- ========== RIGHT: SIDEBAR (desktop) ========== -->
					<aside class="hidden lg:block lg:col-span-3">
						<div class="sticky top-24 space-y-6">

							<?php
							/* ============================================================
							   H-1) RELATED FIGHTERS (optional)
							   ============================================================ */
							$fighter_ids = [];
							if (is_array($related_fighters) && !empty($related_fighters)) {
								$fighter_ids = array_map('absint', $related_fighters);
							} elseif (is_string($related_fighters) && trim($related_fighters) !== '') {
								$fighter_ids = array_map('absint', array_filter(explode(',', $related_fighters)));
							}
							$fighter_ids = array_slice(array_filter($fighter_ids), 0, 3);

							if (!empty($fighter_ids)):
								$fighters_q = new WP_Query([
									'post_type' => 'fighter',
									'post__in' => $fighter_ids,
									'posts_per_page' => 3,
									'orderby' => 'post__in',
									'no_found_rows' => true,
								]);
								if ($fighters_q->have_posts()):
									?>
									<div class="rounded-xl border border-slate-200/70 bg-white p-4">
										<h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
											<?php esc_html_e('Related Fighters', 'mma-future'); ?>
										</h3>
										<ul class="space-y-3 list-none m-0 p-0">
											<?php while ($fighters_q->have_posts()):
												$fighters_q->the_post();
												$f_name = get_the_title();
												$f_link = get_the_permalink();
												$f_class = get_post_meta(get_the_ID(), 'weight_class', true);
												$initials = implode('', array_map(function ($w) {
													return mb_strtoupper(mb_substr($w, 0, 1)); }, explode(' ', $f_name)));
												$initials = mb_substr($initials, 0, 2);
												?>
												<li>
													<a href="<?php echo esc_url($f_link); ?>" class="flex items-center gap-3 group">
														<span
															class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white"
															style="background:var(--brand);">
															<?php echo esc_html($initials); ?>
														</span>
														<span>
															<span
																class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors"><?php echo esc_html($f_name); ?></span>
															<?php if ($f_class): ?>
																<span
																	class="block text-xs text-slate-400"><?php echo esc_html($f_class); ?></span>
															<?php endif; ?>
														</span>
													</a>
												</li>
											<?php endwhile;
											wp_reset_postdata(); ?>
										</ul>
									</div>
								<?php endif; endif; ?>

							<?php
							/* ============================================================
							   H-2) RELATED EVENTS (optional)
							   ============================================================ */
							$event_ids = [];
							if (is_array($related_events) && !empty($related_events)) {
								$event_ids = array_map('absint', $related_events);
							} elseif (is_string($related_events) && trim($related_events) !== '') {
								$event_ids = array_map('absint', array_filter(explode(',', $related_events)));
							}
							$event_ids = array_slice(array_filter($event_ids), 0, 3);

							if (!empty($event_ids)):
								$events_q = new WP_Query([
									'post_type' => 'event',
									'post__in' => $event_ids,
									'posts_per_page' => 3,
									'orderby' => 'post__in',
									'no_found_rows' => true,
								]);
								if ($events_q->have_posts()):
									?>
									<div class="rounded-xl border border-slate-200/70 bg-white p-4">
										<h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
											<?php esc_html_e('Related Events', 'mma-future'); ?>
										</h3>
										<ul class="space-y-3 list-none m-0 p-0">
											<?php while ($events_q->have_posts()):
												$events_q->the_post();
												$e_date = get_post_meta(get_the_ID(), 'event_date', true);
												?>
												<li>
													<a href="<?php echo esc_url(get_the_permalink()); ?>" class="block group">
														<span
															class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors"><?php the_title(); ?></span>
														<?php if ($e_date): ?>
															<span
																class="block text-xs text-slate-400 mt-0.5"><?php echo esc_html($e_date); ?></span>
														<?php endif; ?>
													</a>
												</li>
											<?php endwhile;
											wp_reset_postdata(); ?>
										</ul>
									</div>
								<?php endif; endif; ?>

							<?php
							/* ============================================================
							   H-3) TRENDING POSTS (always)
							   ============================================================ */
							$trending_q = new WP_Query([
								'post_type' => 'post',
								'posts_per_page' => 5,
								'post__not_in' => [$post_id],
								'orderby' => 'comment_count',
								'order' => 'DESC',
								'date_query' => [['after' => '30 days ago']],
								'no_found_rows' => true,
							]);
							if (!$trending_q->have_posts()) {
								$trending_q = new WP_Query([
									'post_type' => 'post',
									'posts_per_page' => 5,
									'post__not_in' => [$post_id],
									'orderby' => 'date',
									'order' => 'DESC',
									'no_found_rows' => true,
								]);
							}
							if ($trending_q->have_posts()):
								?>
								<div class="rounded-xl border border-slate-200/70 bg-white p-4">
									<h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
										<?php esc_html_e('Trending', 'mma-future'); ?>
									</h3>
									<ol class="space-y-3 list-none m-0 p-0">
										<?php $t_num = 0;
										while ($trending_q->have_posts()):
											$trending_q->the_post();
											$t_num++;
											$t_cats = get_the_category();
											$t_cat = !empty($t_cats) ? $t_cats[0] : null;
											$t_wc = str_word_count(wp_strip_all_tags(get_the_content()));
											$t_rt = max(1, (int) ceil($t_wc / 200));
											?>
											<li class="flex items-start gap-3">
												<span
													class="flex-shrink-0 w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-400 mt-0.5">
													<?php echo (int) $t_num; ?>
												</span>
												<div class="min-w-0">
													<a href="<?php echo esc_url(get_the_permalink()); ?>"
														class="block text-sm font-medium text-slate-700 hover:text-slate-900 transition-colors leading-snug line-clamp-2">
														<?php the_title(); ?>
													</a>
													<div class="flex items-center gap-2 mt-1 text-xs text-slate-400">
														<?php if ($t_cat): ?>
															<span><?php echo esc_html($t_cat->name); ?></span>
															<span class="w-0.5 h-0.5 rounded-full bg-slate-300"></span>
														<?php endif; ?>
														<span><?php printf(esc_html__('%d min', 'mma-future'), $t_rt); ?></span>
													</div>
												</div>
											</li>
										<?php endwhile;
										wp_reset_postdata(); ?>
									</ol>
								</div>
							<?php endif; ?>

							<!-- Upcoming Events (static) -->
							<div class="rounded-xl border border-slate-200/70 bg-white p-4">
								<h3 class="!text-[11px] !leading-none font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2 mb-3 whitespace-nowrap">
									<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
										<rect x="3" y="4" width="18" height="18" rx="2" ry="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
									</svg>
									<?php esc_html_e('Upcoming Events', 'mma-future'); ?>
								</h3>
								<ul class="space-y-1.5 list-none m-0 p-0">
									<li>
										<a href="<?php echo esc_url(home_url('/event/ufc-301-las-vegas/')); ?>"
											class="group flex items-start gap-3 rounded-lg px-2 py-2 -mx-2 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
											<span class="mt-[7px] h-2 w-2 rounded-full bg-[#0047A8] flex-shrink-0"></span>
											<span class="min-w-0 flex-1">
												<span class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 leading-snug">UFC 301: Las Vegas</span>
												<span class="block text-xs text-slate-400 mt-0.5">Mar 15, 2026</span>
											</span>
											<svg xmlns="http://www.w3.org/2000/svg" class="mt-1 h-3.5 w-3.5 flex-shrink-0 text-slate-300 opacity-0 transition group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
										</a>
									</li>
									<li>
										<a href="<?php echo esc_url(home_url('/event/ufc-fight-night-sao-paulo/')); ?>"
											class="group flex items-start gap-3 rounded-lg px-2 py-2 -mx-2 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
											<span class="mt-[7px] h-2 w-2 rounded-full bg-[#0047A8] flex-shrink-0"></span>
											<span class="min-w-0 flex-1">
												<span class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 leading-snug">UFC Fight Night: São Paulo</span>
												<span class="block text-xs text-slate-400 mt-0.5">Mar 1, 2026</span>
											</span>
											<svg xmlns="http://www.w3.org/2000/svg" class="mt-1 h-3.5 w-3.5 flex-shrink-0 text-slate-300 opacity-0 transition group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
										</a>
									</li>
									<li>
										<a href="<?php echo esc_url(home_url('/event/ufc-302-new-york/')); ?>"
											class="group flex items-start gap-3 rounded-lg px-2 py-2 -mx-2 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
											<span class="mt-[7px] h-2 w-2 rounded-full bg-[#0047A8] flex-shrink-0"></span>
											<span class="min-w-0 flex-1">
												<span class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 leading-snug">UFC 302: New York</span>
												<span class="block text-xs text-slate-400 mt-0.5">Apr 6, 2026</span>
											</span>
											<svg xmlns="http://www.w3.org/2000/svg" class="mt-1 h-3.5 w-3.5 flex-shrink-0 text-slate-300 opacity-0 transition group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
										</a>
									</li>
								</ul>
							</div>

						</div><!-- /sticky -->
					</aside>

				</div><!-- /grid -->
			</div><!-- /container -->

			<!-- ============================================================
		 MOBILE SIDEBAR (below article, visible on < lg)
		 ============================================================ -->
			<div class="lg:hidden max-w-6xl mx-auto px-4 sm:px-6 pb-8 space-y-6">
				<?php
				if (!empty($fighter_ids)):
					$fighters_q2 = new WP_Query([
						'post_type' => 'fighter',
						'post__in' => $fighter_ids,
						'posts_per_page' => 3,
						'orderby' => 'post__in',
						'no_found_rows' => true,
					]);
					if ($fighters_q2->have_posts()):
						?>
						<div class="rounded-xl border border-slate-200/70 bg-white p-4">
							<h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
								<?php esc_html_e('Related Fighters', 'mma-future'); ?></h3>
							<ul class="space-y-3 list-none m-0 p-0">
								<?php while ($fighters_q2->have_posts()):
									$fighters_q2->the_post();
									$f_name = get_the_title();
									$f_link = get_the_permalink();
									$f_class = get_post_meta(get_the_ID(), 'weight_class', true);
									$initials = mb_substr(implode('', array_map(function ($w) {
										return mb_strtoupper(mb_substr($w, 0, 1)); }, explode(' ', $f_name))), 0, 2);
									?>
									<li>
										<a href="<?php echo esc_url($f_link); ?>" class="flex items-center gap-3 group">
											<span
												class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white"
												style="background:var(--brand);"><?php echo esc_html($initials); ?></span>
											<span>
												<span
													class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors"><?php echo esc_html($f_name); ?></span>
												<?php if ($f_class): ?><span
														class="block text-xs text-slate-400"><?php echo esc_html($f_class); ?></span><?php endif; ?>
											</span>
										</a>
									</li>
								<?php endwhile;
								wp_reset_postdata(); ?>
							</ul>
						</div>
					<?php endif; endif; ?>

				<?php
				if (!empty($event_ids)):
					$events_q2 = new WP_Query([
						'post_type' => 'event',
						'post__in' => $event_ids,
						'posts_per_page' => 3,
						'orderby' => 'post__in',
						'no_found_rows' => true,
					]);
					if ($events_q2->have_posts()):
						?>
						<div class="rounded-xl border border-slate-200/70 bg-white p-4">
							<h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
								<?php esc_html_e('Related Events', 'mma-future'); ?></h3>
							<ul class="space-y-3 list-none m-0 p-0">
								<?php while ($events_q2->have_posts()):
									$events_q2->the_post();
									$e_date = get_post_meta(get_the_ID(), 'event_date', true);
									?>
									<li>
										<a href="<?php echo esc_url(get_the_permalink()); ?>" class="block group">
											<span
												class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors"><?php the_title(); ?></span>
											<?php if ($e_date): ?><span
													class="block text-xs text-slate-400 mt-0.5"><?php echo esc_html($e_date); ?></span><?php endif; ?>
										</a>
									</li>
								<?php endwhile;
								wp_reset_postdata(); ?>
							</ul>
						</div>
					<?php endif; endif; ?>
			</div>

			<!-- ============================================================
		 K) RELATED POSTS
		 ============================================================ -->
			<?php
			$cat_ids = wp_list_pluck($categories, 'term_id');
			if (!empty($cat_ids)):
				$related_q = new WP_Query([
					'post_type' => 'post',
					'posts_per_page' => 3,
					'post__not_in' => [$post_id],
					'category__in' => $cat_ids,
					'orderby' => 'rand',
					'no_found_rows' => true,
				]);
				if ($related_q->have_posts()):
					?>
					<section class="border-t border-slate-200/70 bg-slate-50/40">
						<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">
							<h2 class="font-heading font-bold text-slate-900 text-2xl sm:text-3xl mb-8">
								<?php esc_html_e('Related Posts', 'mma-future'); ?>
							</h2>
							<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
								<?php while ($related_q->have_posts()):
									$related_q->the_post();
									$r_cats = get_the_category();
									$r_cat = !empty($r_cats) ? $r_cats[0] : null;
									$r_wc = str_word_count(wp_strip_all_tags(get_the_content()));
									$r_rt = max(1, (int) ceil($r_wc / 200));
									?>
									<a href="<?php echo esc_url(get_the_permalink()); ?>"
										class="group flex flex-col bg-white rounded-xl ring-1 ring-black/10 shadow-sm overflow-hidden hover:shadow-md hover:ring-black/15 hover:-translate-y-0.5 transition-all duration-200">
										<div class="relative aspect-video overflow-hidden bg-slate-100">
											<?php if (has_post_thumbnail()): ?>
												<?php the_post_thumbnail('medium_large', [
													'class' => 'w-full h-full object-cover transition-transform duration-300 group-hover:scale-105',
													'loading' => 'lazy',
												]); ?>
											<?php else: ?>
												<div class="w-full h-full flex items-center justify-center bg-slate-200">
													<svg class="w-10 h-10 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.5"
														viewBox="0 0 24 24">
														<path stroke-linecap="round" stroke-linejoin="round"
															d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 19.5V4.5a2.25 2.25 0 0 0-2.25-2.25H3.75A2.25 2.25 0 0 0 1.5 4.5v15a2.25 2.25 0 0 0 2.25 2.25Z" />
													</svg>
												</div>
											<?php endif; ?>
											<?php if ($r_cat): ?>
												<span
													class="absolute top-3 left-3 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide bg-[var(--brand)] text-white shadow-sm">
													<?php echo esc_html($r_cat->name); ?>
												</span>
											<?php endif; ?>
										</div>
										<div class="flex flex-col flex-1 p-5">
											<h3 class="font-heading font-bold text-slate-900 text-lg leading-snug line-clamp-2 mb-2">
												<?php the_title(); ?></h3>
											<div class="mt-auto flex items-center gap-3 text-xs text-slate-500">
												<time
													datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
												<span class="w-1 h-1 rounded-full bg-slate-300"></span>
												<span><?php printf(esc_html__('%d min read', 'mma-future'), $r_rt); ?></span>
											</div>
										</div>
									</a>
								<?php endwhile;
								wp_reset_postdata(); ?>
							</div>
						</div>
					</section>
				<?php endif; endif; ?>

		</main>

		<!-- ============================================================
	 INLINE JS: Progress bar, TOC, Share/Copy/Bookmark
	 ============================================================ -->
		<script>
			(function () {
				/* --- Progress bar --- */
				var bar = document.getElementById('js-progress-bar');
				var article = document.querySelector('.js-post-content');
				if (bar && article) {
					var ticking = false;
					var update = function () {
						var scrollY = window.scrollY;
						var articleBottom = article.getBoundingClientRect().bottom + scrollY;
						var target = Math.max(1, articleBottom - window.innerHeight);
						var pct = Math.min(100, Math.max(0, (scrollY / target) * 100));
						bar.style.width = pct + '%';
						ticking = false;
					};
					window.addEventListener('scroll', function () {
						if (!ticking) { ticking = true; requestAnimationFrame(update); }
					}, { passive: true });
					update();
				}

				/* --- Copy link button --- */
				var copyBtn = document.querySelector('[data-action="copy"]');
				if (copyBtn) {
					var postUrl = '<?php echo esc_js(get_the_permalink()); ?>';

					function fallbackCopy(text) {
						var ta = document.createElement('textarea');
						ta.value = text;
						ta.style.cssText = 'position:fixed;opacity:0;left:-9999px';
						document.body.appendChild(ta);
						ta.select();
						try { document.execCommand('copy'); } catch (e) { }
						document.body.removeChild(ta);
					}

					function showCopiedState() {
						var lbl = copyBtn.querySelector('[data-label]');
						var iconCopy = copyBtn.querySelector('[data-icon-copy]');
						var iconCheck = copyBtn.querySelector('[data-icon-check]');
						if (lbl) lbl.textContent = '<?php echo esc_js(__('Copied', 'mma-future')); ?>';
						if (iconCopy) iconCopy.classList.add('hidden');
						if (iconCheck) iconCheck.classList.remove('hidden');
						setTimeout(function () {
							if (lbl) lbl.textContent = '<?php echo esc_js(__('Copy link', 'mma-future')); ?>';
							if (iconCopy) iconCopy.classList.remove('hidden');
							if (iconCheck) iconCheck.classList.add('hidden');
						}, 1500);
					}

					copyBtn.addEventListener('click', function () {
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(postUrl).then(showCopiedState).catch(function () {
								fallbackCopy(postUrl);
								showCopiedState();
							});
						} else {
							fallbackCopy(postUrl);
							showCopiedState();
						}
					});
				}
			})();
		</script>

		<?php
	endwhile;

else:
	/* Non-post single templates fall through to default behavior */
	?>
	<main id="primary" class="site-main">
		<?php
		while (have_posts()):
			the_post();
			get_template_part('template-parts/content', get_post_type());
		endwhile;
		?>
	</main>
	<?php
endif;

get_footer();
