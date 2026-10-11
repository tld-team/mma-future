<?php
$database=getenv('MMAF_TEST_DB');
if(!in_array($database,['mmaf_scale_small','mmaf_scale_large'],true)){throw new RuntimeException('A named synthetic scale database is required.');}
define('ABSPATH',getenv('MMAF_WP_CORE')?:dirname(__DIR__).'/');
define('DB_NAME',$database);define('DB_HOST',getenv('MMAF_SCALE_DB_HOST')?:'localhost:/private/tmp/mmaf-import-test.sock');define('DB_USER','root');define('DB_PASSWORD',getenv('MMAF_SCALE_DB_PASSWORD')?:'');define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');define('WP_ENVIRONMENT_TYPE','local');define('DISABLE_WP_CRON',true);define('WP_HOME','http://example.test');define('WP_SITEURL',WP_HOME);define('MMAF_IMPORT_STORAGE_DIR',sys_get_temp_dir().'/mmaf-scale-private');
if(getenv('MMAF_SCALE_INSTALL')){define('WP_INSTALLING',true);}
$table_prefix='wp_';
if(getenv('MMAF_OPERATIONS_DIR')){require __DIR__.'/runtime-gate.php';}
require ABSPATH.'wp-settings.php';
