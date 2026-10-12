<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Cleanup {
    public static function run(): void {
        if(version_compare((string)get_option('mmaf_db_version'),'1.9.0','>=')){
            \MMAF\DataEngine\Services\Operations\Retention::expireImports();
        }
    }
}
