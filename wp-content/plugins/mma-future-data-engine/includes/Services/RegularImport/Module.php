<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Module {
    public static function register(): void {
        add_action('rest_api_init',[Controller::class,'register']);
        add_action('admin_menu',[AdminPage::class,'menu'],30);
        add_action('admin_enqueue_scripts',[AdminPage::class,'assets']);
        add_action('admin_init',[WriteGuard::class,'adminGuard'],1);
        add_action('admin_post_mmaf_regular_import_download',[Controller::class,'download']);
        add_action('pre_post_update',static function($id){if(get_post_type($id)==='mmaf_fighter'){WriteGuard::assertAllowed();}},1);
        if(defined('WP_CLI')&&WP_CLI){
            \WP_CLI::add_command('mmaf import-job work',static function($args,$assoc){
                try{$result=(new Engine())->work();\WP_CLI::line(wp_json_encode($result));}
                catch(ImportException $e){if($e->reason==='busy'){\WP_CLI::line('{"worked":false,"busy":true}');return;}\WP_CLI::error($e->getMessage());}
            });
            \WP_CLI::add_command('mmaf import-job cleanup',static function(){Cleanup::run();\WP_CLI::success('Import retention checked.');});
        }
    }
}
