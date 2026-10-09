<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Storage {
    public const ZIP_LIMIT = 134217728;
    public const EXPANDED_LIMIT = 1073741824;
    public const CHUNK_SIZE = 1048576;
    public const BATCH_BYTES = 4194304;
    public static function root(): string {
        $root = defined('MMAF_IMPORT_STORAGE_DIR') ? MMAF_IMPORT_STORAGE_DIR : dirname(rtrim(ABSPATH, '/\\')) . '/mmaf-private-imports';
        if (!is_dir($root) && !wp_mkdir_p($root)) { throw new ImportException('Private import storage cannot be created. Open Setup.', 'storage'); }
        $real = realpath($root);
        if (!$real || is_link($root)) { throw new ImportException('Private import storage is invalid.', 'storage'); }
        $public_roots = [realpath(ABSPATH)];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) { $public_roots[] = realpath($_SERVER['DOCUMENT_ROOT']); }
        foreach ($public_roots as $public) {
            if ($public && ($real === $public || strpos($real . '/', rtrim($public, '/') . '/') === 0)) {
                throw new ImportException('Import storage must be outside the public web directory.', 'storage');
            }
        }
        $site = substr(hash('sha256', home_url('/') . '|' . DB_NAME . '|' . $GLOBALS['wpdb']->prefix), 0, 20);
        $dir = $real . '/' . $site;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) { throw new ImportException('Private storage is not writable.', 'storage'); }
        chmod($real, 0700); chmod($dir, 0700);
        return $dir;
    }
    public static function job(int $id): string {
        if ($id < 1) { throw new \InvalidArgumentException('Invalid job identifier.'); }
        $dir = self::root() . '/job-' . $id;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) { throw new ImportException('Cannot create import directory.', 'storage'); }
        chmod($dir, 0700); return $dir;
    }
    public static function write(string $path, array $data): void {
        self::writeBytes($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public static function writeBytes(string $path, string $data): void {
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data)) { @unlink($temp); throw new ImportException('Could not save import progress. Check free disk space.', 'storage'); }
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new ImportException('Could not finalize import file.', 'storage'); }
    }
    public static function read(string $path): array {
        if (!is_file($path)) { throw new ImportException('An import file is missing. Open Technical details.', 'missing_file'); }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($data)) { throw new ImportException('An import file has invalid contents.'); }
        return $data;
    }
    public static function removeTree(string $dir): void {
        $root = self::root() . '/';
        $real = realpath($dir);
        if (!$real || strpos($real . '/', $root) !== 0 || is_link($dir)) { return; }
        foreach (new \FilesystemIterator($dir) as $file) {
            if ($file->isDir() && !$file->isLink()) { self::removeTree($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($dir);
    }
}
