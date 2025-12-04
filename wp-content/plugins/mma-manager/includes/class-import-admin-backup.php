<?php

class MMA_Data_AdminNackup {
    
    private $importer;
    
    public function __construct($importer) {
        $this->importer = $importer;
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Import Data',
            'Import Data',
            'manage_options',
            'mma-import',
            array($this, 'import_page'),
            '',
            30
        );
    }
    
    public function import_page() {
        $results = array();
        
        // Procesiraj formu
        if (isset($_POST['mma_import_nonce']) && wp_verify_nonce($_POST['mma_import_nonce'], 'mma_import_data')) {
            $results = $this->process_import();
        }
        ?>
        <div class="wrap">
            <h1>Import MMA Data</h1>
            
            <div class="card">
                <h2>Import from JSON File</h2>
                <p>Import event and fight data from JSON file located in plugin directory.</p>
                
                <form method="post">
                    <?php wp_nonce_field('mma_import_data', 'mma_import_nonce'); ?>
                    <p>
                        <label for="json_file_path">JSON File Path (optional):</label>
                        <input type="text" id="json_file_path" name="json_file_path" 
                               value="<?php echo esc_attr(MMA_MANAGER_PLUGIN_PATH . '/data.json'); ?>" 
                               accept=".json" class="regular-text" />

                               
                        <input type="file" id="json_file_path" name="json_file_path" 
                               accept=".json" class="regular-text" />
                        <br><small>Leave empty to use default: plugin-directory/data.json</small>
                    </p>
                    
                    <p>
                        <input type="submit" name="import_data" class="button button-primary" 
                               value="Import Data" />
                    </p>
                </form>
            </div>
            
            <?php if (!empty($results)): ?>
            <div class="card">
                <h2>Import Results</h2>
                <?php if (!empty($results['events'])): ?>
                    <h3>Events Imported/Updated: <?php echo count($results['events']); ?></h3>
                    <ul>
                        <?php foreach ($results['events'] as $event): ?>
                            <li><?php echo esc_html($event['title']); ?> (ID: <?php echo $event['post_id']; ?>) - <?php echo $event['action']; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                
                <?php if (!empty($results['fights'])): ?>
                    <h3>Fights Imported/Updated: <?php echo count($results['fights']); ?></h3>
                    <ul>
                        <?php foreach ($results['fights'] as $fight): ?>
                            <li><?php echo esc_html($fight['title']); ?> (ID: <?php echo $fight['post_id']; ?>) - <?php echo $fight['action']; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                
                <?php if (!empty($results['errors'])): ?>
                    <h3>Errors:</h3>
                    <ul style="color: red;">
                        <?php foreach ($results['errors'] as $error): ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function process_import() {
        $json_file_path = sanitize_text_field($_POST['json_file_path'] ?? '');
        
        if (empty($json_file_path)) {
            $json_file_path = ''; // Koristiće default putanju
        }
        
        $results = $this->importer->import_from_json($json_file_path);
        
        if (is_wp_error($results)) {
            return array('errors' => array($results->get_error_message()));
        }
        
        return $results;
    }
}