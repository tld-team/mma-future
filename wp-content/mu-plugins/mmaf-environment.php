<?php
/** Local copies never send external mail or automatically resume copied jobs. */
if (!defined('ABSPATH')) { exit; }
if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local') {
    if (!defined('DISABLE_WP_CRON')) { define('DISABLE_WP_CRON', true); }
    if (!defined('MMAF_LOCAL_MAIL_ENABLED') || !MMAF_LOCAL_MAIL_ENABLED) {
        add_filter('pre_wp_mail', static function ($pre) { return $pre ?? false; }, PHP_INT_MAX);
    }
}
