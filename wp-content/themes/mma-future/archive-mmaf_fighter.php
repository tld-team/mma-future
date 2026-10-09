<?php get_header(); ?>
<main id="primary" class="mmaf-section"><div class="mmaf-container"><h1>Fighter profiles</h1>
<div class="mmaf-card-grid">
<?php while(have_posts()):the_post();$fighter=(new \MMAF\DataEngine\Repositories\RestReadRepository())->fighter_by_wp_post_id(get_the_ID());$context=$fighter?mmaf_public_service()->fighter((int)$fighter['id']):null;if(!$context||is_wp_error($context)){continue;} ?>
<article class="mmaf-card"><a href="<?php the_permalink(); ?>"><?php mmaf_fighter_image($context); ?><h2><?php the_title(); ?></h2></a><p><?php echo esc_html(mmaf_record($context['stats'])); ?></p></article>
<?php endwhile; ?></div><?php the_posts_pagination(array('mid_size'=>1)); ?></div></main>
<?php get_footer(); ?>
