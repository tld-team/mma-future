<?php

class Event_CPT {
    
    private $database_handler;
    private $db;
    
    public function __construct($database_handler) {
        $this->database_handler = $database_handler;
        $this->db = $this->database_handler->get_event_db();
    }
    
    public function register_cpt() {
        $labels = array(
            'name' => 'Event Fights',
            'singular_name' => 'Event Fight',
            'menu_name' => 'Event Fights',
            'add_new' => 'Add New Event',
            'add_new_item' => 'Add New Fight Event',
            'edit_item' => 'Edit Fight Event',
            'new_item' => 'New Fight Event',
            'view_item' => 'View Fight Event',
            'search_items' => 'Search Fight Events',
            'not_found' => 'No fight events found',
            'not_find_in_trash' => 'No fight events found in Trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-tickets-alt',
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'event-fights'),
        );
        
        register_post_type('event_fight', $args);
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_to_external_db'), 10, 3);
    }
    
    public function add_meta_boxes() {
        add_meta_box(
            'event_fight_details',
            'Event Details',
            array($this, 'render_meta_box'),
            'event_fight',
            'normal',
            'high'
        );
    }
    
    public function render_meta_box($post) {
        // Get existing data from external DB
        $event_data = $this->get_from_external_db($post->ID);
        
        ?>
        <style>
            .event-meta-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
            .event-meta-fields .full-width {
                grid-column: 1 / -1;
            }
            .event-meta-fields p {
                margin: 0 0 15px 0;
            }
            .event-meta-fields label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .event-meta-fields input[type="text"],
            .event-meta-fields input[type="number"],
            .event-meta-fields input[type="datetime-local"],
            .event-meta-fields input[type="url"] {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
        </style>
        
        <div class="event-meta-fields">
            <!-- Basic Event Information -->
            <div class="full-width">
                <h3>Basic Event Information</h3>
            </div>
            
            <p class="full-width">
                <label for="event_name">Event Name:</label>
                <input type="text" id="event_name" name="event_name" 
                       value="<?php echo esc_attr($event_data->event_name ?? ''); ?>" />
            </p>
            
            <p>
                <label for="event_date">Event Date & Time:</label>
                <input type="datetime-local" id="event_date" name="event_date" 
                       value="<?php echo esc_attr($event_data->event_date ?? ''); ?>" />
            </p>
            
            <p>
                <label for="time">Time Display:</label>
                <input type="text" id="time" name="time" 
                       value="<?php echo esc_attr($event_data->time ?? ''); ?>" 
                       placeholder="e.g., Saturday, November 8, 6:00 PM ET" />
            </p>
            
            <p>
                <label for="total_fights">Total Fights:</label>
                <input type="number" id="total_fights" name="total_fights" 
                       value="<?php echo esc_attr($event_data->total_fights ?? ''); ?>" />
            </p>
            
            <p>
                <label for="mma_bouts">MMA Bouts:</label>
                <input type="number" id="mma_bouts" name="mma_bouts" 
                       value="<?php echo esc_attr($event_data->mma_bouts ?? ''); ?>" />
            </p>

            <!-- Location Information -->
            <div class="full-width">
                <h3>Location Information</h3>
            </div>
            
            <p>
                <label for="venue">Venue:</label>
                <input type="text" id="venue" name="venue" 
                       value="<?php echo esc_attr($event_data->venue ?? ''); ?>" />
            </p>
            
            <p>
                <label for="city">City:</label>
                <input type="text" id="city" name="city" 
                       value="<?php echo esc_attr($event_data->city ?? ''); ?>" />
            </p>
            
            <p>
                <label for="country">Country:</label>
                <input type="text" id="country" name="country" 
                       value="<?php echo esc_attr($event_data->country ?? ''); ?>" />
            </p>
            
            <p>
                <label for="location">Location Display:</label>
                <input type="text" id="location" name="location" 
                       value="<?php echo esc_attr($event_data->location ?? ''); ?>" 
                       placeholder="e.g., San Francisco, California" />
            </p>

            <!-- Promotion Information -->
            <div class="full-width">
                <h3>Promotion Information</h3>
            </div>
            
            <p>
                <label for="promotion">Promotion:</label>
                <input type="text" id="promotion" name="promotion" 
                       value="<?php echo esc_attr($event_data->promotion ?? ''); ?>" />
            </p>
            
            <p>
                <label for="ownership">Ownership:</label>
                <input type="text" id="ownership" name="ownership" 
                       value="<?php echo esc_attr($event_data->ownership ?? ''); ?>" />
            </p>

            <!-- Links -->
            <div class="full-width">
                <h3>Links</h3>
            </div>
            
            <p>
                <label for="link">Event Link:</label>
                <input type="url" id="link" name="link" 
                       value="<?php echo esc_attr($event_data->link ?? ''); ?>" 
                       placeholder="https://" />
            </p>
            
            <p>
                <label for="promotion_link">Promotion Link:</label>
                <input type="url" id="promotion_link" name="promotion_link" 
                       value="<?php echo esc_attr($event_data->promotion_link ?? ''); ?>" 
                       placeholder="https://" />
            </p>
            
            <p>
                <label for="location_link">Location Link:</label>
                <input type="url" id="location_link" name="location_link" 
                       value="<?php echo esc_attr($event_data->location_link ?? ''); ?>" 
                       placeholder="https://" />
            </p>

            <!-- Additional Details -->
            <div class="full-width">
                <h3>Additional Details</h3>
            </div>
            
            <p>
                <label for="us_broadcast">U.S. Broadcast:</label>
                <input type="text" id="us_broadcast" name="us_broadcast" 
                       value="<?php echo esc_attr($event_data->us_broadcast ?? ''); ?>" />
            </p>
            
            <p>
                <label for="enclosure">Enclosure:</label>
                <input type="text" id="enclosure" name="enclosure" 
                       value="<?php echo esc_attr($event_data->enclosure ?? ''); ?>" 
                       placeholder="e.g., Cage, Ring" />
            </p>
        </div>
        <?php
    }
    
    public function save_to_external_db($post_id, $post, $update) {
        global $wpdb;
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Prepare data
        $event_data = array(
            'wp_post_id' => $post_id,
            'event_name' => sanitize_text_field($_POST['event_name'] ?? ''),
            'event_date' => sanitize_text_field($_POST['event_date'] ?? ''),
            'venue' => sanitize_text_field($_POST['venue'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'total_fights' => intval($_POST['total_fights'] ?? 0),
            'link' => esc_url_raw($_POST['link'] ?? ''),
            'time' => sanitize_text_field($_POST['time'] ?? ''),
            'us_broadcast' => sanitize_text_field($_POST['us_broadcast'] ?? ''),
            'promotion' => sanitize_text_field($_POST['promotion'] ?? ''),
            'promotion_link' => esc_url_raw($_POST['promotion_link'] ?? ''),
            'ownership' => sanitize_text_field($_POST['ownership'] ?? ''),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'location_link' => esc_url_raw($_POST['location_link'] ?? ''),
            'enclosure' => sanitize_text_field($_POST['enclosure'] ?? ''),
            'mma_bouts' => intval($_POST['mma_bouts'] ?? 0),
        );
        
        // Check if record exists
        $existing = $this->get_from_external_db($post_id);
        
        if ($existing) {
            // Update
            $wpdb->update(
                $wpdb->prefix . 'event_fights',
                $event_data,
                array('wp_post_id' => $post_id)
            );
        } else {
            // Insert
            $wpdb->insert(
                $wpdb->prefix . 'event_fights',
                $event_data
            );
        }
    }
    
    public function get_from_external_db($post_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}event_fights WHERE wp_post_id = %d",
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
            $wpdb->prefix . 'event_fights',
            array('wp_post_id' => $post_id),
            array('%d') // Format parametra - %d za integer
        );
        
        return $result !== false;
    }
    
    public function format_content($external_data, $original_content) {
        $additional_content = '
        <div class="event-details">
            <h3>Event Details</h3>
            <p><strong>Event Name:</strong> ' . esc_html($external_data->event_name) . '</p>
            <p><strong>Date:</strong> ' . esc_html($external_data->event_date) . '</p>
            <p><strong>Time:</strong> ' . esc_html($external_data->time) . '</p>
            <p><strong>Venue:</strong> ' . esc_html($external_data->venue) . '</p>
            <p><strong>Location:</strong> ' . esc_html($external_data->city) . ', ' . esc_html($external_data->country) . '</p>
            <p><strong>Promotion:</strong> ' . esc_html($external_data->promotion) . '</p>
            <p><strong>Total Fights:</strong> ' . esc_html($external_data->total_fights) . '</p>
            <p><strong>MMA Bouts:</strong> ' . esc_html($external_data->mma_bouts) . '</p>
            <p><strong>Enclosure:</strong> ' . esc_html($external_data->enclosure) . '</p>
        </div>
        ';
        
        return $original_content . $additional_content;
    }
}
?>