<?php
/**
 * Plugin Name: MMA Manager
 * Description: Single Fight and Event Fight CPT stored in separate databases
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MMA_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MMA_MANAGER_PLUGIN_PATH', plugin_dir_path(__FILE__));

class MMA_Manager_Plugin {
    
    private static $instance = null;
    private $database_handler;
    private $fight_cpt;
    private $event_cpt;
    private $import_admin;
    private $import_data;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    public function init() {
        // Include required files
        $this->include_dependencies();
        
        // Initialize components
        $this->database_handler = new Database_Handler();
        $this->fight_cpt = new Fight_CPT($this->database_handler);
        $this->event_cpt = new Event_CPT($this->database_handler);

        // Inicijalizuj importer
        $this->import_data = new MMA_Data_Importer($this->event_cpt, $this->fight_cpt, MMA_MANAGER_PLUGIN_PATH);

        // Inicijalizuj admin interfejs
        if (is_admin()) {
            new MMA_Data_Admin($this->import_data);
        }
        
        $this->setup_hooks();
    }
    
    private function include_dependencies() {
        require_once MMA_MANAGER_PLUGIN_PATH . 'includes/class-database-handler.php';
        require_once MMA_MANAGER_PLUGIN_PATH . 'includes/class-fight-cpt.php';
        require_once MMA_MANAGER_PLUGIN_PATH . 'includes/class-event-cpt.php';
        require_once MMA_MANAGER_PLUGIN_PATH . 'includes/class-import-admin.php';
        require_once MMA_MANAGER_PLUGIN_PATH . 'includes/class-import-data.php';
    }
    
    private function setup_hooks() {
        add_action('init', array($this->fight_cpt, 'register_cpt'));
        add_action('init', array($this->event_cpt, 'register_cpt'));
        
        // Hook for saving posts
        add_action('save_post_single_fight', array($this->fight_cpt, 'save_to_external_db'), 10, 3);
        add_action('save_post_event_fight', array($this->event_cpt, 'save_to_external_db'), 10, 3);
        
        // Hook for deleting posts
        add_action('delete_post', array($this, 'handle_post_deletion'), 10, 2);
        
        // Hook for displaying posts from external DB
        add_filter('the_content', array($this, 'display_external_content'));
    }
    
    public function activate() {
        $this->include_dependencies();
        $database_handler = new Database_Handler();
        $database_handler->create_tables();
        
        // Flush rewrite rules for CPTs
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Cleanup if needed
        flush_rewrite_rules();
    }
    
    public function handle_post_deletion($post_id, $post) {
        if ($post->post_type === 'single_fight') {
            $this->fight_cpt->delete_from_external_db($post_id);
        } elseif ($post->post_type === 'event_fight') {
            $this->event_cpt->delete_from_external_db($post_id);
        }
    }
    
    public function display_external_content($content) {
        global $post;
        
        if (!is_singular() || !$post) {
            return $content;
        }
        
        if (is_singular('single_fight')) {
            $external_data = $this->fight_cpt->get_from_external_db($post->ID);
            if ($external_data) {
                $content = $this->fight_cpt->format_content($external_data, $content);
            }
        } elseif (is_singular('event_fight')) {
            $external_data = $this->event_cpt->get_from_external_db($post->ID);
            if ($external_data) {
                $content = $this->event_cpt->format_content($external_data, $content);
            }
        }
        
        return $content;
    }
}

// Initialize the plugin
MMA_Manager_Plugin::get_instance();

if (!function_exists('ddd')) {
    function ddd($data) {
        echo "<pre>";
        print_r($data);
        echo "</pre>";
    }
}

if ( ! function_exists( 'mma_log' ) ) {
	function mma_log( $entry, $mode = 'a', $file = 'mma_log' ) {
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