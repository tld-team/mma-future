<?php
/**
 * mma-future functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package mma-future
 */

if ( ! defined( '_S_VERSION' ) ) {
	// Replace the version number of the theme on each release.
	define( '_S_VERSION', '1.0.0' );
}
/** helper test functions */
function dd($array): void {
	echo "<pre>";
	print_r($array);
	echo "</pre>";
}

if ( ! function_exists( 'tld_log' ) ) {
	function tld_log( $entry, $mode = 'a', $file = 'tld_log' ) {
		// Get WordPress uploads directory.
		$upload_dir = wp_upload_dir();

		$upload_dir = $upload_dir['basedir'];
		$upload_dir = dirname(__FILE__);
		// If the entry is array, json_encode.
		if ( is_array( $entry ) ) {
			$entry = json_encode( $entry );
		}
		// Write the log file.
		$file  = $upload_dir . '/' . $file . '.log';
		$file  = fopen( $file, $mode );
		$bytes = fwrite( $file, current_time( 'mysql' ) . "::" . $entry . "\n" );
		fclose( $file );
		return $bytes;
	}
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function mma_future_setup() {
	/*
		* Make theme available for translation.
		* Translations can be filed in the /languages/ directory.
		* If you're building a theme based on mma-future, use a find and replace
		* to change 'mma-future' to the name of your theme in all the template files.
		*/
	load_theme_textdomain( 'mma-future', get_template_directory() . '/languages' );

	// Add default posts and comments RSS feed links to head.
	add_theme_support( 'automatic-feed-links' );

	/*
		* Let WordPress manage the document title.
		* By adding theme support, we declare that this theme does not use a
		* hard-coded <title> tag in the document head, and expect WordPress to
		* provide it for us.
		*/
	add_theme_support( 'title-tag' );

	/*
		* Enable support for Post Thumbnails on posts and pages.
		*
		* @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		*/
	add_theme_support( 'post-thumbnails' );

	// This theme uses wp_nav_menu() in one location.
	register_nav_menus(
		array(
			'menu-1' => esc_html__( 'Primary', 'mma-future' ),
		)
	);

	/*
		* Switch default core markup for search form, comment form, and comments
		* to output valid HTML5.
		*/
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
		)
	);

	// Set up the WordPress core custom background feature.
	add_theme_support(
		'custom-background',
		apply_filters(
			'mma_future_custom_background_args',
			array(
				'default-color' => 'ffffff',
				'default-image' => '',
			)
		)
	);

	// Add theme support for selective refresh for widgets.
	add_theme_support( 'customize-selective-refresh-widgets' );

	/**
	 * Add support for core custom logo.
	 *
	 * @link https://codex.wordpress.org/Theme_Logo
	 */
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 250,
			'width'       => 250,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);
}
add_action( 'after_setup_theme', 'mma_future_setup' );

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function mma_future_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'mma_future_content_width', 640 );
}
add_action( 'after_setup_theme', 'mma_future_content_width', 0 );

/**
 * Register widget area.
 *
 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
 */
function mma_future_widgets_init() {
	register_sidebar(
		array(
			'name'          => esc_html__( 'Sidebar', 'mma-future' ),
			'id'            => 'sidebar-1',
			'description'   => esc_html__( 'Add widgets here.', 'mma-future' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'mma_future_widgets_init' );

/**
 * Enqueue scripts and styles.
 */
function mma_future_scripts() {
	/** ==============================            custom styles and scripts            ============================== */
	/**  */
	wp_enqueue_style( 'mma-main', get_template_directory_uri() . '/assets/dist/css/output.css' );
	wp_enqueue_script( 'mma-main', get_template_directory_uri() . '/assets/dist/js/main.js', array(), _S_VERSION, true );
	// wp_enqueue_script( 'tailwindcss', 'https://cdn.tailwindcss.com' );
	wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' );


    /** ================================================================================================================ */


	/** ==============================            default styles and scripts            ============================== */

	wp_enqueue_style( 'mma-future-style', get_stylesheet_uri(), array(), _S_VERSION );
	wp_style_add_data( 'mma-future-style', 'rtl', 'replace' );

	wp_enqueue_script( 'mma-future-navigation', get_template_directory_uri() . '/js/navigation.js', array(), _S_VERSION, true );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'mma_future_scripts' );


/**
 * Enqueue admin scripts
 */
function mma_future_admin_scripts($hook) {
	global $screen_options;
	dd($screen_options);
	if ( 'post.php' === $hook ) {
		wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' );
		wp_enqueue_style( 'mma-main', get_template_directory_uri() . '/assets/dist/css/output.css' );
		wp_enqueue_script( 'mma-main', get_template_directory_uri() . '/assets/dist/js/main.js', array(), _S_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'mma_future_admin_scripts' );


/**
 * Include helper functions
 */
require_once get_template_directory() . '/inc/helper-function.php';

/**
 * Implement the Custom Header feature.
 */
require get_template_directory() . '/inc/custom-header.php';

/**
 * Custom template tags for this theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Functions which enhance the theme by hooking into WordPress.
 */
require get_template_directory() . '/inc/template-functions.php';

/**
 * Customizer additions.
 */
require get_template_directory() . '/inc/customizer.php';

/**
 * Register blocks
 */
require get_template_directory() . '/inc/register-blocks.php';

/**
 * Custom navigation walker classes
 */
require get_template_directory() . '/inc/class-custom-nav-walker.php';

/**
 * Load Jetpack compatibility file.
 */
if ( defined( 'JETPACK__VERSION' ) ) {
	require get_template_directory() . '/inc/jetpack.php';
}

