<?php
/** Render one accessible home link with OS-aware logo sources. */
function mmaf_brand_link( string $place = 'header' ): void {
	$classes = array(
		'header' => 'block w-full max-w-[150px] h-20 flex items-center overflow-hidden no-underline transition-all duration-300 hover:opacity-80 header-logo',
		'mobile' => 'block w-full max-w-[120px] h-16 flex items-center overflow-hidden no-underline',
		'footer' => 'block w-full max-w-[180px] mb-4 no-underline transition-opacity duration-300 hover:opacity-80',
	);
	$image_classes = array(
		'header' => 'w-full max-h-20 object-contain transition-all duration-300 header-logo-img',
		'mobile' => 'w-full max-h-16 object-contain',
		'footer' => 'w-full h-auto object-contain',
	);
	$place = isset( $classes[ $place ] ) ? $place : 'header';
	$light_id = (int) get_theme_mod( 'custom_logo' );
	$dark_id = (int) get_theme_mod( 'mmaf_dark_logo' );
	$light_url = $light_id ? wp_get_attachment_image_url( $light_id, 'full' ) : false;
	$dark_url = $dark_id ? wp_get_attachment_image_url( $dark_id, 'full' ) : false;
	if ( ! $dark_url && $light_url && 'mm-future.png' === basename( (string) wp_parse_url( $light_url, PHP_URL_PATH ) ) ) {
		$dark_url = add_query_arg( 'ver', mmaf_asset_version( 'assets/dist/images/logo-dark.png' ), get_template_directory_uri() . '/assets/dist/images/logo-dark.png' );
	}
	$name = get_bloginfo( 'name' );
	printf( '<a href="%s" class="mmaf-brand mmaf-brand--%s %s%s" rel="home" aria-label="%s">', esc_url( home_url( '/' ) ), esc_attr( $place ), esc_attr( $classes[ $place ] ), $light_url && ! $dark_url ? ' mmaf-brand--light-fallback' : '', esc_attr( $name ) );
	if ( $light_url ) {
		echo '<picture>';
		if ( $dark_url ) {
			printf( '<source media="(prefers-color-scheme: dark)" srcset="%s">', esc_url( $dark_url ) );
		}
		$meta = wp_get_attachment_metadata( $light_id );
		printf( '<img src="%s" alt="" class="%s" width="%d" height="%d">', esc_url( $light_url ), esc_attr( $image_classes[ $place ] ), (int) ( $meta['width'] ?? 636 ), (int) ( $meta['height'] ?? 443 ) );
		echo '</picture>';
	} else {
		printf( '<span class="mmaf-brand__title font-heading font-bold">%s</span>', esc_html( $name ) );
	}
	echo '</a>';
}
