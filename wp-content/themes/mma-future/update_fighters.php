<?php
// Funkcija za kreiranje postova iz JSON fajla
function create_fighters_from_json() {
    // Putanja do JSON fajla
    $json_file = get_template_directory() . '/fighters.json';
    
    // Proveri da li fajl postoji
    if (!file_exists($json_file)) {
        error_log('Fighters JSON file not found: ' . $json_file);
        return false;
    }
    
    // Učitaj JSON fajl
    $json_data = file_get_contents($json_file);
    $fighters = json_decode($json_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decode error: ' . json_last_error_msg());
        return false;
    }
    
    if (empty($fighters)) {
        error_log('No fighters data found in JSON file');
        return false;
    }
    
    $created_posts = 0;
    $errors = 0;
    
    foreach ($fighters as $fighter) {
        try {
            // Proveri da li post već postoji
            $existing_post = get_page_by_title($fighter['name'], OBJECT, 'fighter');
            
            if ($existing_post) {
                error_log('Fighter post already exists: ' . $fighter['name']);
                continue;
            }
            
            // Kreiraj novi post
            $post_data = array(
                'post_title'    => sanitize_text_field($fighter['name']),
                'post_type'     => 'fighter',
                'post_status'   => 'publish',
                'post_author'   => 1,
            );
            
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                throw new Exception('Failed to create post: ' . $post_id->get_error_message());
            }
            
            // ACF polja - Results Details grupa
            if (isset($fighter['wins'])) {
                update_field('results_details_fighter_wins', sanitize_text_field($fighter['wins']), $post_id);
            }
            
            if (isset($fighter['losses'])) {
                update_field('results_details_fighter_losses', sanitize_text_field($fighter['losses']), $post_id);
            }
            
            if (isset($fighter['finishes'])) {
                update_field('results_details_fighter_finishes', sanitize_text_field($fighter['finishes']), $post_id);
            }
            
            if (isset($fighter['combined_opponent_score'])) {
                update_field('results_details_fighter_combined_opponent_score', sanitize_text_field($fighter['combined_opponent_score']), $post_id);
            }
            
            // ACF polja - Essential Details grupa
            if (isset($fighter['height'])) {
                update_field('essential_details_fighter_height', sanitize_text_field($fighter['height']), $post_id);
            }
            
            if (isset($fighter['nationality'])) {
                update_field('essential_details_fighter_nationality', sanitize_text_field($fighter['nationality']), $post_id);
            }
            
            if (isset($fighter['date_of_birth'])) {
                update_field('essential_details_fighter_date_of_birth', sanitize_text_field($fighter['date_of_birth']), $post_id);
            }
            
            // Taxonomy - Fighter Category
            if (isset($fighter['fighter-category'])) {
                $term_result = wp_set_object_terms($post_id, $fighter['fighter-category'], 'fighter-category');
                
                if (is_wp_error($term_result)) {
                    throw new Exception('Failed to set taxonomy: ' . $term_result->get_error_message());
                }
            }
            
            $created_posts++;
            error_log('Successfully created fighter: ' . $fighter['name']);
            
        } catch (Exception $e) {
            $errors++;
            error_log('Error creating fighter ' . $fighter['name'] . ': ' . $e->getMessage());
        }
    }
    
    return array(
        'created' => $created_posts,
        'errors' => $errors,
        'total' => count($fighters)
    );
}

// Funkcija za pokretanje procesa (možeš je pozvati preko admin menija ili WP-CLI)
function run_fighters_import() {
    $result = create_fighters_from_json();
    
    if ($result === false) {
        return 'Failed to process JSON file';
    }
    
    return sprintf(
        'Import completed. Created: %d, Errors: %d, Total: %d',
        $result['created'],
        $result['errors'],
        $result['total']
    );
}

// Dodaj admin menu za import (opciono)
add_action('admin_menu', 'add_fighters_import_menu');

function add_fighters_import_menu() {
    add_management_page(
        'Fighters Import',
        'Fighters Import',
        'manage_options',
        'fighters-import',
        'fighters_import_page'
    );
}

function fighters_import_page() {
    ?>
    <div class="wrap">
        <h1>Fighters Import from JSON</h1>
        <?php
        if (isset($_POST['run_import']) && check_admin_referer('fighters_import_nonce')) {
            echo '<div class="notice notice-info"><p>' . run_fighters_import() . '</p></div>';
        }
        ?>
        <form method="post">
            <?php wp_nonce_field('fighters_import_nonce'); ?>
            <p>Click the button below to import fighters from the JSON file.</p>
            <input type="submit" name="run_import" class="button button-primary" value="Run Import">
        </form>
    </div>
    <?php
}