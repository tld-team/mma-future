<?php
// Supply a local isolated WordPress bootstrap; credentials remain outside Git.
$bootstrap=getenv('MMAF_TEST_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) { throw new RuntimeException('Set MMAF_TEST_BOOTSTRAP to an isolated local WordPress bootstrap.'); }
$integration_bootstrap_ready=false;
register_shutdown_function(static function()use(&$integration_bootstrap_ready){
    if(!$integration_bootstrap_ready){fwrite(STDERR,"Integration bootstrap failed before fixtures could run.\n");exit(1);}
});
require $bootstrap;
if (wp_get_environment_type()!=='local' || !in_array(DB_NAME,['mmaf_frontend_test','mmaf_frontend_dev'],true)) { throw new RuntimeException('An isolated local fixture database is required.'); }
$integration_bootstrap_ready=true;
