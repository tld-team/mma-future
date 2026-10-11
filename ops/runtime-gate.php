<?php
/** Private bootstrap, required from wp-config before advanced-cache. No WordPress dependency. */
if (!defined('ABSPATH')) { exit; }
$mmaf_operations_dir = getenv('MMAF_OPERATIONS_DIR') ?: (defined('MMAF_OPERATIONS_DIR') ? MMAF_OPERATIONS_DIR : null);
if (!$mmaf_operations_dir) { return; }
if (!defined('MMAF_OPERATIONS_DIR')) { define('MMAF_OPERATIONS_DIR', $mmaf_operations_dir); }

function mmaf_runner_owns_write_lock(): bool {
    $fd = getenv('MMAF_DEPLOY_WRITE_FD');
    $path = getenv('MMAF_WRITE_LOCK') ?: (defined('MMAF_WRITE_LOCK') ? MMAF_WRITE_LOCK : null);
    if (!(defined('WP_CLI') && WP_CLI) || !$path || $fd === false || !ctype_digit($fd)) { return false; }
    static $handle = null;
    if ($handle) { return true; }
    $handle = @fopen('php://fd/'.$fd, 'r+');
    $actual = $handle ? fstat($handle) : false; $expected = @stat($path);
    if (!$actual || !$expected || $actual['ino'] !== $expected['ino'] || $actual['dev'] !== $expected['dev'] || !flock($handle, LOCK_EX | LOCK_NB)) {
        if ($handle) { fclose($handle); } $handle = null; return false;
    }
    return true;
}
function mmaf_runtime_unavailable(): void {
    if (defined('WP_CLI') && WP_CLI) { fwrite(STDERR, "MMA Future writes or code are briefly locked.\n"); exit(75); }
    http_response_code(503); header('Retry-After: 60'); header('Cache-Control: no-store');
    echo 'MMA Future is briefly updating. Please try again shortly.'; exit;
}
$mmaf_probe = false;
if (!(defined('WP_CLI') && WP_CLI) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    $mmaf_token = @file_get_contents($mmaf_operations_dir.'/probe.token');
    $mmaf_header = $_SERVER['HTTP_X_MMAF_RELEASE_PROBE'] ?? '';
    $mmaf_probe = $mmaf_token && strlen($mmaf_header) === 64 && hash_equals($mmaf_token, $mmaf_header);
}
$mmaf_owned = mmaf_runner_owns_write_lock();
$mmaf_locks = [];
if (!$mmaf_owned && !$mmaf_probe) {
    if (is_file($mmaf_operations_dir.'/maintenance.flag')) { mmaf_runtime_unavailable(); }
    $mmaf_is_writer = (defined('WP_CLI') && WP_CLI) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'wp-cron.php')
        || (str_contains($_SERVER['REQUEST_URI'] ?? '', '/wp-admin/') && isset($_GET['action']));
    if ($mmaf_is_writer) {
        if (is_file($mmaf_operations_dir.'/recovery-readonly.flag')) { mmaf_runtime_unavailable(); }
        if (is_file($mmaf_operations_dir.'/writes-paused.flag')) { mmaf_runtime_unavailable(); }
        $mmaf_writer_lock = @fopen($mmaf_operations_dir.'/writers.lock', 'c');
        if (!$mmaf_writer_lock || !flock($mmaf_writer_lock, LOCK_SH | LOCK_NB)) { mmaf_runtime_unavailable(); }
        $mmaf_locks[] = $mmaf_writer_lock;
        if (is_file($mmaf_operations_dir.'/writes-paused.flag')) { mmaf_runtime_unavailable(); }
        if (is_file($mmaf_operations_dir.'/recovery-readonly.flag')) { mmaf_runtime_unavailable(); }
    }
    $mmaf_request_lock = @fopen($mmaf_operations_dir.'/requests.lock', 'c');
    if (!$mmaf_request_lock || !flock($mmaf_request_lock, LOCK_SH | LOCK_NB)) { mmaf_runtime_unavailable(); }
    $mmaf_locks[] = $mmaf_request_lock;
    if (is_file($mmaf_operations_dir.'/maintenance.flag')) { mmaf_runtime_unavailable(); }
    register_shutdown_function(static function () use ($mmaf_locks) {
        foreach ($mmaf_locks as $handle) { flock($handle, LOCK_UN); fclose($handle); }
    });
}
