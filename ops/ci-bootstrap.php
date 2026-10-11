<?php
// CI uses only synthetic fixtures. No staging dump, source package or secret is read.
$core=getenv('MMAF_WP_CORE');
if(!$core || !is_file($core.'/wp-config.php')){throw new RuntimeException('A synthetic CI WordPress configuration is required.');}
require $core.'/wp-config.php';
