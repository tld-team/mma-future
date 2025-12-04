<?php

/**
 * MMA_Data_Importer - Glavna klasa za importovanje MMA podataka iz JSON fajla
 * 
 * Ova klasa je zadužena za čitanje JSON fajla, parsiranje podataka,
 * kreiranje/update WordPress postova i čuvanje podataka u external bazu
 */
class MMA_Data_Importer {
    
    private $event_cpt;      // Instanca Event_CPT klase za rad sa event postovima
    private $fight_cpt;      // Instanca Fight_CPT klase za rad sa fight postovima
    private $plugin_path;    // Putanja do plugin direktorijuma
    
    /**
     * Konstruktor - Inicijalizuje importer sa potrebnim zavisnostima
     * 
     * @param object $event_cpt   - Event_CPT instanca
     * @param object $fight_cpt   - Fight_CPT instanca  
     * @param string $plugin_path - Apsolutna putanja do plugin direktorijuma
     */
    public function __construct($event_cpt, $fight_cpt, $plugin_path) {
        $this->event_cpt = $event_cpt;
        $this->fight_cpt = $fight_cpt;
        $this->plugin_path = $plugin_path;
    }
    
    /**
     * Glavna metoda za import podataka iz JSON fajla
     * 
     * Ova metoda:
     * 1. Proverava postojanje JSON fajla
     * 2. Učitava i parsira JSON sadržaj
     * 3. Procesira event i fight podatke
     * 4. Vraća rezultate importa
     * 
     * @param string $json_file_path - Putanja do JSON fajla (opciono)
     * @return array|WP_Error - Niz sa rezultatima ili WP_Error objekat
     */
    public function import_from_json($json_file_path = '') {
        // Ako nije specificirana putanja, koristi default
        if (empty($json_file_path)) {
            $json_file_path = $this->plugin_path . '/data.json';
        }
        
        // Provera postojanja fajla
        if (!file_exists($json_file_path)) {
            return new WP_Error('file_not_found', 'JSON file not found: ' . $json_file_path);
        }
        
        // Učitavanje JSON sadržaja
        $json_content = file_get_contents($json_file_path);
        $data = json_decode($json_content, true); // true vraća asocijativni niz
        
        // Provera JSON grešaka
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON: ' . json_last_error_msg());
        }
        
        // Provera da li ima podataka
        if (empty($data)) {
            return new WP_Error('empty_data', 'No data found in JSON file');
        }
        
        // Inicijalizacija rezultata
        $results = array(
            'events' => array(),  // Uspešno importovani eventi
            'fights' => array(),  // Uspešno importovani fightovi
            'errors' => array()   // Greške tokom importa
        );
        // Procesiranje EVENT podataka
        if (isset($data)) {
            foreach ($data as $key => $d) {
                $fights = $d['event']['fights'];
                $result = $this->import_event($d, $fights);
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                } else {
                    $results['events'][] = $result;
                }                
            }
        }
        
        return $results;
    }
    
    /**
     * Importuje pojedinačni event iz JSON podataka
     * 
     * Metoda:
     * 1. Priprema podatke za WordPress post
     * 2. Proverava da li event već postoji
     * 3. Kreira ili update-uje post
     * 4. Postavlja featured image
     * 5. Čuva dodatne podatke u external bazi
     * 
     * @param array $event_data - Niz sa podacima o eventu
     * @return array|WP_Error - Rezultat operacije
     */
    public function import_event($event_data, $fights) {

        // basic event data
        $name = $event_data['text'] ?? 'Unknown Event';
        $link = $event_data['link'] ?? '';
        $time = $event_data['time'] ?? '';

        //location data
        $country = $event_data['location']['country'] ?? '';
        $city = $event_data['location']['city'] ?? '';
        $venue = $event_data['location']['venue'] ?? '';

        //fullevent data
        $us_broadcast = $event_data['event']['u.s._broadcast'] ?? '';
        $preliminary_card = $event_data['event']['preliminary_card'] ?? '';
        $promotion_link = $event_data['event']['promotion_link'] ?? '';
        $promotion = $event_data['event']['promotion'] ?? '';
        $tapology_accounts_link = $event_data['event']['tapology_accounts_link'] ?? '';
        $tapology_accounts = $event_data['event']['tapology_accounts'] ?? '';
        $location_link = $event_data['event']['location_link'] ?? '';
        $enclosure = $event_data['event']['enclosure'] ?? '';
        $mma_bouts = $event_data['event']['mma_bouts'] ?? '';

        // Priprema osnovnih podataka za WordPress post
        $post_data = array(
            'post_title'   => $name,
            'post_status'  => 'publish', // Postavlja se kao objavljen
            'post_type'    => 'event_fight', // Custom post type
            'post_date'    => $this->parse_event_date($time ?? ''),
        );


        // Provera da li event već postoji (sprečava duplikate)
        $is_exist_post = $this->is_exist_post($name, 'event_fight');
        
        if ($is_exist_post !== false) {
            // UPDATE postojećeg posta
            $post_data['ID'] = $is_exist_post;
            $post_id = wp_update_post($post_data);
        } else {
            // KREIRANJE novog posta
            $post_id = wp_insert_post($post_data);                       
        }
       
        // Provera grešaka pri kreiranju/update-u posta
        if (is_wp_error($post_id)) {

            return $post_id;
        }
        
        // Priprema podataka za external bazu
        $external_data = array(
            'wp_post_id' => $post_id,
            'event_name' => $name ?? '',
            'event_date' => $this->parse_event_date($time ?? ''),
            'venue' => $venue ?? '',
            'city' => $city ?? '',
            'country' => $country ?? '',
            'total_fights' => count($fights) ?? 0,
            'mma_bouts' => $mma_bouts ?? 0,
            'link' => $link ?? '',
            'time' => $time ?? '',
            'us_broadcast' => $us_broadcast ?? '',
            'promotion' => $promotion ?? '',
            'promotion_link' => $promotion_link ?? '',
            'ownership' => $ownership ?? '',
            'location' => $location ?? '',
            'location_link' => $location_link ?? '',
            'enclosure' => $enclosure ?? '',
        );
        
        // Čuvanje u external bazi preko Event_CPT klase
        $this->save_event_to_external_db($post_id, $external_data);


        // Procesiranje FIGHT podataka
        if (isset($fights)) {
            foreach ($fights as $fight_data) {

                $result = $this->import_fight($fight_data, $post_id);
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                } else {
                    $results['fights'][] = $result;
                }
            }
        }
        // Povratni rezultat
        return array(
            'post_id' => $post_id,
            'title' => $post_data['post_title'],
            'action' => $is_exist_post ? 'updated' : 'created'
        );
    }
    
    /**
     * Importuje pojedinačni fight iz JSON podataka
     * 
     * Slično kao import_event, ali za fight postove
     * 
     * @param array $fight_data - Niz sa podacima o fightu
     * @return array|WP_Error - Rezultat operacije
     */
    public function import_fight($fight_data, $event_id) {
        $competition_type = $fight_data['competition_type'] ?? "";
        $link_url = $fight_data['link']['url'] ?? "";
        $link_text = $fight_data['link']['text'] ?? "";
        $final_type = $fight_data['final']['type'] ?? "";
        $final_win_method = $fight_data['final']['win_method'] ?? "";
        $round = $fight_data['final']['round'] ?? "";
        
        $fighter_left_result = $fight_data['sum']['fighter_left']['result'] ?? "";
        $fighter_left_name = $fight_data['sum']['fighter_left']['name'] ?? "";
        $fighter_left_image = $fight_data['sum']['fighter_left']['image'] ?? "";
        $fighter_left_link = $fight_data['sum']['fighter_left']['link'] ?? "";
        $fighter_left_process = $fight_data['sum']['fighter_left']['process'] ?? ['no data', 'no data'];
        $fighter_right_result = $fight_data['sum']['fighter_right']['result'] ?? "";
        $fighter_right_image = $fight_data['sum']['fighter_right']['image'] ?? "";
        $fighter_right_name = $fight_data['sum']['fighter_right']['name'] ?? "";
        $fighter_right_link = $fight_data['sum']['fighter_right']['link'] ?? "";
        $fighter_right_process = $fight_data['sum']['fighter_right']['process'] ?? ['no data', 'no data'];

        $post_title = $fighter_left_name . ' vs ' . $fighter_right_name;
        
        // Priprema podataka za post
        $post_data = array(
            'post_title'   => $post_title,
            'post_content' => $this->generate_fight_content($fight_data),
            'post_status'  => 'publish',
            'post_type'    => 'single_fight',
        );
        
        // Provera postojećeg fighta
        $existing_post_id = $this->is_exist_post($post_title, 'single_fight');
        
        if ($existing_post_id !== false) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $left_fighter_id = $this->is_exist_post($fighter_left_name, 'fighter');
        $right_fighter_id = $this->is_exist_post($fighter_right_name, 'fighter');

        // Priprema kompletnih podataka za external bazu
        $external_data = array(
            'wp_post_id' => $post_id,
            'wp_event_id' => $event_id,
            'left_fighter_id' => $left_fighter_id,
            'right_fighter_id' => $right_fighter_id,
            'competition_type' => $competition_type,
            'fight_link_url' => $link_url,
            'fight_link_text' => $link_text,
            'win_method' => $final_win_method,
            'round' => $round,
            // Fighter 1 (Left) - svi podaci o prvom borcu
            'fighter_left_name' => $fighter_left_name,
            'fighter_left_link' => $fighter_left_link,
            'fighter_left_image' => $fighter_left_image,
            'fighter_left_record' => isset($fighter_left_process[1]) ? $fighter_left_process[1] : 'no data',
            'fighter_left_trend' => isset($fighter_left_process[0]) ? $fighter_left_process[0] : 'no data',
            'fighter_left_result' => $fighter_left_result,
            // Fighter 2 (Right) - svi podaci o drugom borcu
            'fighter_right_name' => $fighter_right_name,
            'fighter_right_link' => $fighter_right_link,
            'fighter_right_image' => $fighter_right_image,
            'fighter_right_record' => isset($fighter_right_process[1]) ? sanitize_text_field($fighter_right_process[1]) : 'no data',
            'fighter_right_trend' => isset($fighter_right_process[0]) ? sanitize_text_field($fighter_right_process[0]) : 'no data',
            'fighter_right_result' => $fighter_right_result ? $fighter_right_result : '',
        );
        
        // Čuvanje u external bazi
        $this->save_fight_to_external_db($post_id, $external_data, $event_id);
        
        return array(
            'post_id' => $post_id,
            'title' => $post_title,
            'action' => $existing_post_id ? 'updated' : 'created'
        );
    }
    
    // =========================================================================
    // POMOĆNE METODE - Privatne metode za internu upotrebu
    // =========================================================================
    
    
    private function parse_event_date($date_string) {
        // Očisti višestruke razmake
        $ciscenje_razmaka = preg_replace('/\s+/', ' ', trim($date_string));
        
        // Eliminiši ET
        $ciscenje_razmaka = str_replace(' ET', '', $ciscenje_razmaka);
        
        
        // Pokušaj direktnu konverziju
        $timestamp = strtotime($ciscenje_razmaka);
        
        if ($timestamp === false) {
            // Ako ne uspe, pokušaj sa custom parsiranjem
            $array_of_strings = explode(', ', $ciscenje_razmaka);
            
            if (count($array_of_strings) >= 3) {
                $mont_date = explode(' ', $array_of_strings[1]);
                
                if (count($mont_date) >= 2) {
                    $month = $mont_date[0];
                    $day = $mont_date[1];
                    $year = date('Y');
                    $time = $array_of_strings[2];
                    
                    // Kreiraj bolji format za parsiranje
                    $datetime_string = $day . ' ' . $month . ' ' . $year . ' ' . $time;
                    $timestamp = strtotime($datetime_string);
                }
            }
        }
    
        if ($timestamp !== false) {
            // Za database format koristite ovako:
            $db_format = date('Y-m-d H:i:s', $timestamp);
            // Ili za WordPress display format:
            $display_format = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            
            
            return $db_format; // ili $db_format ako želite database format
        } else {
            $formatted_datetime = current_time(get_option('date_format') . ' ' . get_option('time_format'));
            
            return $formatted_datetime;
        }
    }

    /**
     * Generuje HTML sadržaj za fight post
     * 
     * @param array $fight_data - Podaci o fightu
     * @return string - Generisani HTML sadržaj
     */
    private function generate_fight_content($fight_data) {
        $content = '';
        
        // Dodaje competition type ako postoji
        if (!empty($fight_data['competition_type'])) {
            $content .= '<p><strong>Competition Type:</strong> ' . esc_html($fight_data['competition_type']) . '</p>';
        }
        
        // Dodaje rezultat fighta
        if (!empty($fight_data['final']['win_method'])) {
            $content .= '<p><strong>Result:</strong> ' . esc_html($fight_data['final']['win_method']) . '</p>';
        }
        
        // Dodaje informacije o rundama
        if (!empty($fight_data['final']['round'])) {
            $content .= '<p><strong>Round:</strong> ' . esc_html($fight_data['final']['round']) . '</p>';
        }
        
        return $content;
    }
    
    /**
     * Čuva event podatke u external bazu preko Event_CPT klase
     * 
     * @param int $post_id - ID posta
     * @param array $data - Podaci za čuvanje
     */
    public function save_event_to_external_db($post_id, $data) {
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'event_fights';
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            // Display notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>Please save the event to update the external database.</p></div>';
            });

            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            // Display notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>You do not have permission to update the external database.</p></div>';
            });

            return;
        }
        
        // Priprema podataka za unos
        $event_data = array(
            'wp_post_id' => $post_id,
            'event_name' => isset($data['event_name']) ? sanitize_text_field($data['event_name']) : '',
            'event_date' => isset($data['event_date']) ? sanitize_text_field($data['event_date']) : '',
            'venue' => isset($data['venue']) ? sanitize_text_field($data['venue']) : '',
            'city' => isset($data['city']) ? sanitize_text_field($data['city']) : '',
            'country' => isset($data['country']) ? sanitize_text_field($data['country']) : '',
            'total_fights' => isset($data['total_fights']) ? intval($data['total_fights']) : 0,
            'link' => isset($data['link']) ? esc_url_raw($data['link']) : '',
            'time' => isset($data['time']) ? sanitize_text_field($data['time']) : '',
            'us_broadcast' => isset($data['us_broadcast']) ? sanitize_text_field($data['us_broadcast']) : '',
            'promotion' => isset($data['promotion']) ? sanitize_text_field($data['promotion']) : '',
            'promotion_link' => isset($data['promotion_link']) ? esc_url_raw($data['promotion_link']) : '',
            'ownership' => isset($data['ownership']) ? sanitize_text_field($data['ownership']) : '',
            'location' => isset($data['location']) ? sanitize_text_field($data['location']) : '',
            'location_link' => isset($data['location_link']) ? esc_url_raw($data['location_link']) : '',
            'enclosure' => isset($data['enclosure']) ? sanitize_text_field($data['enclosure']) : '',
            'mma_bouts' => isset($data['mma_bouts']) ? intval($data['mma_bouts']) : 0
        );
        
        // Provera da li zapis već postoji (based on wp_post_id)
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE wp_post_id = %d", 
            $post_id
        ));
        

        if ($existing_id !== null) {
            // Ažuriranje postojećeg zapisa
            $result = $wpdb->update(
                $table_name,
                $event_data,
                array('wp_post_id' => $post_id)
            );
            
            if ($result === false) {
                error_log('Greška pri ažuriranju podataka eventa: ' . $wpdb->last_error);
                return false;
            }
            return $existing_id;
        } else {
            // Unos novog zapisa
            $result = $wpdb->insert(
                $table_name,
                $event_data
            );
            
            if ($result === false) {
                error_log('Greška pri unosu podataka eventa: ' . $wpdb->last_error);

                return false;
            }
            return $wpdb->insert_id;
        }

    }
    
    /**
     * Čuva fight podatke u external bazu preko Fight_CPT klase
     * 
     * @param int $post_id - ID posta
     * @param array $data - Podaci za čuvanje
     */
    private function save_fight_to_external_db($post_id, $data, $event_id) {
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'single_fights';
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            // Display notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>Please save the event to update the external database.</p></div>';
            });

            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            // Display notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>You do not have permission to update the external database.</p></div>';
            });

            return;
        }
        // mma_log(sprintf('Saving fight to external database: %s', print_r($data, true)));
        // Priprema podataka za unos
        $event_data = array(            
            'wp_post_id' => $post_id,
            'wp_event_id' => $event_id,
            'fighter1_name' => isset($data['left_fighter_id']) ? get_the_title($data['left_fighter_id']) : 'no name',
            'fighter2_name' => isset($data['right_fighter_id']) ? get_the_title($data['right_fighter_id']) : 'no name',
            'fighter1_id' => isset($data['left_fighter_id']) ? intval($data['left_fighter_id']) : 0,
            'fighter2_id' => isset($data['right_fighter_id']) ? intval($data['right_fighter_id']) : 0,
            'fight_date' => isset($data['fight_date']) ? $data['fight_date'] : '',
            'location' => isset($data['location']) ? sanitize_text_field($data['location']) : '',
            'result' => isset($data['result']) ? sanitize_text_field($data['result']) : '',
            'competition_type' => isset($data['competition_type']) ? sanitize_text_field($data['competition_type']) : '',
            'fight_link_url' => isset($data['fight_link_url']) ? esc_url_raw($data['fight_link_url']) : '',
            'fight_link_text' => isset($data['fight_link_text']) ? sanitize_text_field($data['fight_link_text']) : '',
            'win_method' => isset($data['win_method']) ? sanitize_text_field($data['win_method']) : '',
            'round' => isset($data['round']) ? sanitize_text_field($data['round']) : '',
            'fighter_left_name' => isset($data['fighter_left_name']) ? sanitize_text_field($data['fighter_left_name']) : '',
            'fighter_left_link' => isset($data['fighter_left_link']) ? sanitize_text_field($data['fighter_left_link']) : '',
            'fighter_left_image' => isset($data['fighter_left_image']) ? sanitize_text_field($data['fighter_left_image']) : '',
            'fighter_left_record' => isset($data['fighter_left_record']) ? sanitize_text_field($data['fighter_left_record']) : '',
            'fighter_left_trend' => isset($data['fighter_left_trend']) ? sanitize_text_field($data['fighter_left_trend']) : '',
            'fighter_left_result' => isset($data['fighter_left_result']) ? sanitize_text_field($data['fighter_left_result']) : '',
            'fighter_right_name' => isset($data['fighter_right_name']) ? sanitize_text_field($data['fighter_right_name']) : '',
            'fighter_right_link' => isset($data['fighter_right_link']) ? sanitize_text_field($data['fighter_right_link']) : '',
            'fighter_right_image' => isset($data['fighter_right_image']) ? sanitize_text_field($data['fighter_right_image']) : '',
            'fighter_right_record' => isset($data['fighter_right_record']) ? sanitize_text_field($data['fighter_right_record']) : '',
            'fighter_right_trend' => isset($data['fighter_right_trend']) ? sanitize_text_field($data['fighter_right_trend']) : '',
            'fighter_right_result' => isset($data['fighter_right_result']) ? sanitize_text_field($data['fighter_right_result']) : '',
        );
        
        // Provera da li zapis već postoji (based on wp_post_id)
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE wp_post_id = %d", 
            $post_id
        ));
        

        if ($existing_id !== null) {
            // Ažuriranje postojećeg zapisa
            $result = $wpdb->update(
                $table_name,
                $event_data,
                array('wp_post_id' => $post_id)
            );
            
            if ($result === false) {
                error_log('Greška pri ažuriranju podataka eventa: ' . $wpdb->last_error);
                return false;
            }
            return $existing_id;
        } else {
            // Unos novog zapisa
            $result = $wpdb->insert(
                $table_name,
                $event_data
            );
            
            if ($result === false) {
                error_log('Greška pri unosu podataka eventa: ' . $wpdb->last_error);

                return false;
            }
            return $wpdb->insert_id;
        }
    }

    private function is_exist_post($title, $post_type = 'event_fight', $post_status = 'any') {
        $args = array(
            'post_type' => $post_type,
            'post_status' => $post_status,
            'title' => $title,
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            $post_id = $query->posts[0];
            return $post_id ? $post_id : false;
        }
        
        return false;
    }
}