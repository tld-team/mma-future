<?php

class Fight_CPT {
    
    private $database_handler;
    private $db;
    
    public function __construct($database_handler) {
        $this->database_handler = $database_handler;
        $this->db = $database_handler->get_fight_db();
    }
    
    public function register_cpt() {
        $labels = array(
            'name' => 'Single Fights',
            'singular_name' => 'Single Fight',
            'menu_name' => 'Single Fights',
            'add_new' => 'Add New Fight',
            'add_new_item' => 'Add New Single Fight',
            'edit_item' => 'Edit Single Fight',
            'new_item' => 'New Single Fight',
            'view_item' => 'View Single Fight',
            'search_items' => 'Search Single Fights',
            'not_found' => 'No single fights found',
            'not_found_in_trash' => 'No single fights found in Trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-shield',
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'single-fights'),
        );
        
        register_post_type('single_fight', $args);
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_to_external_db'), 10, 3);
    }
    
    public function add_meta_boxes() {
        add_meta_box(
            'single_fight_details',
            'Fight Details',
            array($this, 'render_meta_box'),
            'single_fight',
            'normal',
            'high'
        );
    }
    
    public function render_meta_box($post) {
        // Get existing data from external DB
        $fight_data = $this->get_from_external_db($post->ID);
        
        wp_nonce_field('single_fight_nonce', 'single_fight_nonce');
        ?>
        <style>
            .fight-meta-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
            .fight-meta-fields .full-width {
                grid-column: 1 / -1;
            }
            .fight-meta-fields .fighter-section {
                grid-column: span 1;
                background: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 15px;
            }
            .fight-meta-fields p {
                margin: 0 0 15px 0;
            }
            .fight-meta-fields label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .fight-meta-fields input[type="text"],
            .fight-meta-fields input[type="url"],
            .fight-meta-fields textarea,
            .fight-meta-fields select {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .fighter-columns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
        </style>
        
        <div class="fight-meta-fields">
            <!-- Basic Fight Information -->
            <div class="full-width">
                <h3>Basic Fight Information</h3>
            </div>
            
            <p class="full-width">
                <label for="competition_type">Competition Type:</label>
                <input type="text" id="competition_type" name="competition_type" 
                       value="<?php echo esc_attr($fight_data->competition_type ?? ''); ?>" 
                       placeholder="e.g., Professional MMA" />
            </p>
            
            <p>
                <label for="fight_link_url">Fight Link URL:</label>
                <input type="url" id="fight_link_url" name="fight_link_url" 
                       value="<?php echo esc_attr($fight_data->fight_link_url ?? ''); ?>" 
                       placeholder="/fightcenter/bouts/..." />
            </p>
            
            <p>
                <label for="fight_link_text">Fight Link Text:</label>
                <input type="text" id="fight_link_text" name="fight_link_text" 
                       value="<?php echo esc_attr($fight_data->fight_link_text ?? ''); ?>" 
                       placeholder="e.g., Matchup Page" />
            </p>

            <!-- Final Result -->
            <div class="full-width">
                <h3>Fight Result</h3>
            </div>
            
            <p>
                <label for="win_method">Win Method:</label>
                <input type="text" id="win_method" name="win_method" 
                       value="<?php echo esc_attr($fight_data->win_method ?? ''); ?>" 
                       placeholder="e.g., Decision, Unanimous" />
            </p>
            
            <p>
                <label for="round">Round:</label>
                <input type="text" id="round" name="round" 
                       value="<?php echo esc_attr($fight_data->round ?? ''); ?>" 
                       placeholder="e.g., 3 Rounds, 15:00 Total" />
            </p>

            <!-- Fighter 1 (Left) -->
            <div class="fighter-section">
                <h3>Fighter 1 (Left)</h3>
                <div class="fighter-columns">
                    <p>
                        <label for="fighter_left_name">Name:</label>
                        <input type="text" id="fighter_left_name" name="fighter_left_name" 
                               value="<?php echo esc_attr($fight_data->fighter_left_name ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_left_link">Profile Link:</label>
                        <input type="url" id="fighter_left_link" name="fighter_left_link" 
                               value="<?php echo esc_attr($fight_data->fighter_left_link ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_left_image">Image URL:</label>
                        <input type="url" id="fighter_left_image" name="fighter_left_image" 
                               value="<?php echo esc_attr($fight_data->fighter_left_image ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_left_record">Record:</label>
                        <input type="text" id="fighter_left_record" name="fighter_left_record" 
                               value="<?php echo esc_attr($fight_data->fighter_left_record ?? ''); ?>" 
                               placeholder="e.g., 4-0" />
                    </p>
                    
                    <p>
                        <label for="fighter_left_trend">Trend:</label>
                        <input type="text" id="fighter_left_trend" name="fighter_left_trend" 
                               value="<?php echo esc_attr($fight_data->fighter_left_trend ?? ''); ?>" 
                               placeholder="e.g., Up to, Down to" />
                    </p>
                    
                    <p>
                        <label for="fighter_left_result">Result:</label>
                        <input type="text" id="fighter_left_result" name="fighter_left_result" 
                               value="<?php echo esc_attr($fight_data->fighter_left_result ?? ''); ?>" />
                    </p>
                </div>
            </div>

            <!-- Fighter 2 (Right) -->
            <div class="fighter-section">
                <h3>Fighter 2 (Right)</h3>
                <div class="fighter-columns">
                    <p>
                        <label for="fighter_right_name">Name:</label>
                        <input type="text" id="fighter_right_name" name="fighter_right_name" 
                               value="<?php echo esc_attr($fight_data->fighter_right_name ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_right_link">Profile Link:</label>
                        <input type="url" id="fighter_right_link" name="fighter_right_link" 
                               value="<?php echo esc_attr($fight_data->fighter_right_link ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_right_image">Image URL:</label>
                        <input type="url" id="fighter_right_image" name="fighter_right_image" 
                               value="<?php echo esc_attr($fight_data->fighter_right_image ?? ''); ?>" />
                    </p>
                    
                    <p>
                        <label for="fighter_right_record">Record:</label>
                        <input type="text" id="fighter_right_record" name="fighter_right_record" 
                               value="<?php echo esc_attr($fight_data->fighter_right_record ?? ''); ?>" 
                               placeholder="e.g., 2-1" />
                    </p>
                    
                    <p>
                        <label for="fighter_right_trend">Trend:</label>
                        <input type="text" id="fighter_right_trend" name="fighter_right_trend" 
                               value="<?php echo esc_attr($fight_data->fighter_right_trend ?? ''); ?>" 
                               placeholder="e.g., Up to, Down to" />
                    </p>
                    
                    <p>
                        <label for="fighter_right_result">Result:</label>
                        <input type="text" id="fighter_right_result" name="fighter_right_result" 
                               value="<?php echo esc_attr($fight_data->fighter_right_result ?? ''); ?>" />
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function save_to_external_db($post_id, $post, $update) {
        global $wpdb;
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['single_fight_nonce']) || 
            !wp_verify_nonce($_POST['single_fight_nonce'], 'single_fight_nonce')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Check if post type is correct
        if ($post->post_type !== 'single_fight') {
            return;
        }
        
        // Prepare data
        $fight_data = array(
            'wp_post_id' => $post_id,
            'competition_type' => sanitize_text_field($_POST['competition_type'] ?? ''),
            'fight_link_url' => esc_url_raw($_POST['fight_link_url'] ?? ''),
            'fight_link_text' => sanitize_text_field($_POST['fight_link_text'] ?? ''),
            'win_method' => sanitize_text_field($_POST['win_method'] ?? ''),
            'round' => sanitize_text_field($_POST['round'] ?? ''),
            // Fighter 1 (Left)
            'fighter_left_name' => sanitize_text_field($_POST['fighter_left_name'] ?? ''),
            'fighter_left_link' => esc_url_raw($_POST['fighter_left_link'] ?? ''),
            'fighter_left_image' => esc_url_raw($_POST['fighter_left_image'] ?? ''),
            'fighter_left_record' => sanitize_text_field($_POST['fighter_left_record'] ?? ''),
            'fighter_left_trend' => sanitize_text_field($_POST['fighter_left_trend'] ?? ''),
            'fighter_left_result' => sanitize_text_field($_POST['fighter_left_result'] ?? ''),
            // Fighter 2 (Right)
            'fighter_right_name' => sanitize_text_field($_POST['fighter_right_name'] ?? ''),
            'fighter_right_link' => esc_url_raw($_POST['fighter_right_link'] ?? ''),
            'fighter_right_image' => esc_url_raw($_POST['fighter_right_image'] ?? ''),
            'fighter_right_record' => sanitize_text_field($_POST['fighter_right_record'] ?? ''),
            'fighter_right_trend' => sanitize_text_field($_POST['fighter_right_trend'] ?? ''),
            'fighter_right_result' => sanitize_text_field($_POST['fighter_right_result'] ?? ''),
        );
        
        // Check if record exists
        $existing = $this->get_from_external_db($post_id);
        
        if ($existing) {
            // Update
            $wpdb->update(
                $wpdb->prefix . 'single_fights',
                $fight_data,
                array('wp_post_id' => $post_id)
            );
        } else {
            // Insert
            $wpdb->insert(
                $wpdb->prefix . 'single_fights',
                $fight_data
            );
        }
    }
    
    public function get_from_external_db($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'single_fights';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE wp_post_id = %d",
                $post_id
            )
        );
    }
    
    public function delete_from_external_db($post_id) {
        global $wpdb;
        
        // Dodajte provjeru ako je post_id validan
        if (!is_numeric($post_id) || $post_id <= 0) {
            return false;
        }
        
        // Sigurnosno escapiranje
        $post_id = absint($post_id);
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'single_fights',
            array('wp_post_id' => $post_id),
            array('%d') // Format parametra - %d za integer
        );
        
        return $result !== false;
    }
    
    public function format_content($external_data, $original_content) {
        $additional_content = '
        <div class="fight-details">
            <h3>Fight Details</h3>
            <p><strong>Competition Type:</strong> ' . esc_html($external_data->competition_type) . '</p>
            <p><strong>Result:</strong> ' . esc_html($external_data->win_method) . ' - ' . esc_html($external_data->round) . '</p>
            
            <div class="fighters-comparison">
                <div class="fighter fighter-left">
                    <h4>' . esc_html($external_data->fighter_left_name) . '</h4>
                    <p><strong>Record:</strong> ' . esc_html($external_data->fighter_left_record) . '</p>
                    <p><strong>Trend:</strong> ' . esc_html($external_data->fighter_left_trend) . '</p>
                    <p><strong>Result:</strong> ' . esc_html($external_data->fighter_left_result) . '</p>
                </div>
                
                <div class="fighter fighter-right">
                    <h4>' . esc_html($external_data->fighter_right_name) . '</h4>
                    <p><strong>Record:</strong> ' . esc_html($external_data->fighter_right_record) . '</p>
                    <p><strong>Trend:</strong> ' . esc_html($external_data->fighter_right_trend) . '</p>
                    <p><strong>Result:</strong> ' . esc_html($external_data->fighter_right_result) . '</p>
                </div>
            </div>
            
            <p><a href="' . esc_url($external_data->fight_link_url) . '">' . esc_html($external_data->fight_link_text) . '</a></p>
        </div>
        ';
        
        return $original_content . $additional_content;
    }
}
?>