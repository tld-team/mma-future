<?php
// CI uses only synthetic fixtures. No staging dump, source package or secret is read.
$core=getenv('MMAF_WP_CORE');
if(!$core || !is_file($core.'/wp-config.php')){throw new RuntimeException('A synthetic CI WordPress configuration is required.');}
// WP-CLI defines this before evaluating wp-config; direct PHP fixture runs must do the same.
if(!defined('ABSPATH')){define('ABSPATH',rtrim($core,'/').'/');}
require $core.'/wp-config.php';
