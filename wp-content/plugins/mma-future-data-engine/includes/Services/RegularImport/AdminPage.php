<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class AdminPage {
    public static function menu(): void {
        add_submenu_page('mmaf',__('MMA Future Import','mma-future-data-engine'),__('Import','mma-future-data-engine'),'manage_options','mmaf-regular-import',[self::class,'render']);
        remove_submenu_page('mmaf','mmaf-import');
    }
    public static function assets(string $hook): void {
        if(strpos($hook,'mmaf-regular-import')===false){return;}
        wp_enqueue_style('mmaf-regular-import',plugins_url('assets/regular-import.css',MMAF_PLUGIN_FILE),[],MMAF_PLUGIN_VERSION);
        wp_enqueue_script('mmaf-regular-import',plugins_url('assets/regular-import.js',MMAF_PLUGIN_FILE),['wp-i18n'],MMAF_PLUGIN_VERSION,true);
        wp_localize_script('mmaf-regular-import','MMAFImport',['api'=>rest_url('mma-future/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'site'=>home_url('/'),'environment'=>wp_get_environment_type()]);
    }
    public static function render(): void {
        if(!current_user_can('manage_options')){wp_die(esc_html__('You do not have permission to import data.','mma-future-data-engine'));}
        ?>
        <div class="wrap mmaf-import" id="mmaf-import-app">
            <header class="mmaf-import-heading"><div><p class="mmaf-eyebrow">MMA FUTURE · DATA ENGINE</p><h1><?php esc_html_e('Import scraper data','mma-future-data-engine'); ?></h1><p><?php esc_html_e('Check your package, review the changes, and let the import finish in the background.','mma-future-data-engine'); ?></p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mmaf-import')); ?>"><?php esc_html_e('Advanced tools','mma-future-data-engine'); ?></a></header>
            <div class="mmaf-destination"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><strong><?php echo esc_html(home_url()); ?></strong><span class="mmaf-badge"><?php echo esc_html(ucfirst(wp_get_environment_type())); ?></span><span id="mmaf-worker-state"></span></div>
            <nav class="nav-tab-wrapper mmaf-tabs" aria-label="<?php esc_attr_e('Import sections','mma-future-data-engine'); ?>"><a href="#new" class="nav-tab nav-tab-active" data-tab="new"><?php esc_html_e('New import','mma-future-data-engine'); ?></a><a href="#history" class="nav-tab" data-tab="history"><?php esc_html_e('History','mma-future-data-engine'); ?></a><a href="#setup" class="nav-tab" data-tab="setup"><?php esc_html_e('Setup','mma-future-data-engine'); ?></a></nav>
            <div id="mmaf-message" role="alert" hidden></div><div id="mmaf-announcer" class="screen-reader-text" aria-live="polite"></div>
            <section id="mmaf-panel-new">
                <ol class="mmaf-steps" aria-label="<?php esc_attr_e('Import progress','mma-future-data-engine'); ?>"><?php foreach(['Upload','Check','Import','Results'] as $i=>$label): ?><li data-step="<?php echo (int)$i; ?>"><span><?php echo (int)$i+1; ?></span><?php echo esc_html__($label,'mma-future-data-engine'); ?></li><?php endforeach; ?></ol>
                <div class="mmaf-card" id="mmaf-upload-card"><h2><?php esc_html_e('Upload your scraper package','mma-future-data-engine'); ?></h2><p><?php esc_html_e('Choose the ZIP created by the scraper. Package checks start automatically after upload.','mma-future-data-engine'); ?></p>
                    <label class="mmaf-dropzone" id="mmaf-dropzone" for="mmaf-file"><span class="dashicons dashicons-upload" aria-hidden="true"></span><strong><?php esc_html_e('Choose a ZIP or drop it here','mma-future-data-engine'); ?></strong><span><?php esc_html_e('One package · up to 128 MiB','mma-future-data-engine'); ?></span><input id="mmaf-file" type="file" accept=".zip,application/zip"></label>
                    <div id="mmaf-upload-progress" hidden><label for="mmaf-upload-bar" id="mmaf-upload-label"></label><progress id="mmaf-upload-bar" max="100" value="0"></progress></div>
                    <p class="description"><?php esc_html_e('The next screen shows proposed changes. Database writes begin only after you select Start import.','mma-future-data-engine'); ?></p>
                </div>
                <div id="mmaf-job"></div>
            </section>
            <section id="mmaf-panel-history" hidden><div class="mmaf-card"><h2><?php esc_html_e('Import history','mma-future-data-engine'); ?></h2><div id="mmaf-history"></div></div></section>
            <section id="mmaf-panel-setup" hidden><div class="mmaf-card"><h2><?php esc_html_e('Background processing','mma-future-data-engine'); ?></h2><p><?php esc_html_e('These checks are configured once by the site administrator. Regular imports then run entirely from this page.','mma-future-data-engine'); ?></p><div id="mmaf-setup"></div></div></section>
        </div>
        <?php
    }
}
