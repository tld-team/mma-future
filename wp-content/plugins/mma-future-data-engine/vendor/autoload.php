<?php
// Bundled, pinned and namespaced dependencies. No runtime downloads.
spl_autoload_register(static function (string $class): void {
    $maps = [
        'MMAFVendor\\JsonMachine\\' => __DIR__ . '/json-machine/src/',
        'MMAFVendor\\Ifsnop\\' => __DIR__ . '/mysqldump-php/src/Ifsnop/',
    ];
    foreach ($maps as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
            return;
        }
    }
});
require_once __DIR__ . '/json-machine/src/functions.php';
