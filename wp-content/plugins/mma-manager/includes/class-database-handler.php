<?php

class Database_Handler {
    
    private $fight_db;
    private $event_db;
    
    public function __construct() {
        $this->create_tables();
    }
    
    public function create_tables() {
        $this->create_fight_table();
        $this->create_event_table();
    }
    
    private function create_fight_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'single_fights';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wp_post_id bigint(20) NOT NULL,
            wp_event_id bigint(20) NOT NULL,
            fighter1_name varchar(255),
            fighter2_name varchar(255),
            fighter1_id bigint(20) NOT NULL,
            fighter2_id bigint(20) NOT NULL,
            fight_date datetime,
            location varchar(255),
            result text,
            competition_type varchar(100),
            fight_link_url varchar(500),
            fight_link_text varchar(100),
            win_method varchar(100),
            round varchar(100),
            fighter_left_name varchar(255),
            fighter_left_link varchar(500),
            fighter_left_image varchar(500),
            fighter_left_record varchar(50),
            fighter_left_trend varchar(50),
            fighter_left_result varchar(100),
            fighter_right_name varchar(255),
            fighter_right_link varchar(500),
            fighter_right_image varchar(500),
            fighter_right_record varchar(50),
            fighter_right_trend varchar(50),
            fighter_right_result varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wp_post_id (wp_post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function create_event_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'event_fights';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wp_post_id bigint(20) NOT NULL,
            event_name varchar(255),
            event_date datetime,
            venue varchar(255),
            city varchar(100),
            country varchar(100),
            total_fights int(11) DEFAULT 0,
            mma_bouts int(11) DEFAULT 0,
            link varchar(500),
            time varchar(100),
            us_broadcast varchar(255),
            promotion varchar(255),
            promotion_link varchar(500),
            ownership varchar(255),
            location varchar(255),
            location_link varchar(500),
            enclosure varchar(100),
            main_event_fight_id bigint(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wp_post_id (wp_post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function get_fight_db() {
        return $this->fight_db;
    }
    
    public function get_event_db() {
        return $this->event_db;
    }
}
?>