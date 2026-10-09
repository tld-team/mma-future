<?php
// Supply a local isolated WordPress bootstrap; credentials remain outside Git.
$bootstrap=getenv('MMAF_TEST_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) { throw new RuntimeException('Set MMAF_TEST_BOOTSTRAP to an isolated local WordPress bootstrap.'); }
require $bootstrap;
if (wp_get_environment_type()!=='local' || !in_array(DB_NAME,['mmaf_frontend_test','mmaf_frontend_dev'],true)) { throw new RuntimeException('An isolated local fixture database is required.'); }
