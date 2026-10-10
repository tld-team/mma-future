<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $heading = 2 === ( $heading_level ?? 3 ) ? 'h2' : 'h3'; ?>
<div class="mmaf-empty-state">
	<span class="mmaf-empty-state__icon" aria-hidden="true"><i class="far fa-list-alt"></i></span>
	<div class="mmaf-empty-state__body">
		<?php echo '<' . $heading . '>' . esc_html( $title ) . '</' . $heading . '>'; ?>
		<p><?php echo esc_html( $description ); ?></p>
		<?php if ( ! empty( $show_links ) ) : ?>
		<div class="mmaf-empty-state__links">
			<a href="<?php echo esc_url( get_post_type_archive_link( 'mmaf_fighter' ) ); ?>">Explore fighter profiles <?php mmaf_view( 'icon-arrow' ); ?></a>
			<a href="<?php echo esc_url( mmaf_rankings_url() ); ?>">View rankings</a>
		</div>
		<?php endif; ?>
	</div>
</div>
