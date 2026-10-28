<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\UfcSigningService;

if(!defined('ABSPATH')){exit;}

final class UfcCommand {
    public static function register(): void {\WP_CLI::add_command('mmaf ufc',self::class);}

    /** Compare saved UFC evidence with current canonical fighters. Default is read-only. */
    public function compare(array $args,array $assoc_args): void {
        if(isset($assoc_args['format']) && $assoc_args['format']!=='json'){\WP_CLI::error('Only --format=json is supported.');}
        if(isset($assoc_args['apply'],$assoc_args['dry-run'])){\WP_CLI::error('Choose --apply or --dry-run.');}
        if(isset($assoc_args['fighter-id']) && (!ctype_digit((string)$assoc_args['fighter-id']) || (int)$assoc_args['fighter-id']<1)){\WP_CLI::error('--fighter-id must be a positive integer.');}
        $service=new UfcSigningService();$fighter_id=(int)($assoc_args['fighter-id']??0);
        if(isset($assoc_args['apply'])){
            \MMAF\DataEngine\Services\RegularImport\WriteGuard::assertAllowed(true);
            $result=['job_id'=>$service->enqueue_compare('wp_cli',$fighter_id),'fighter_id'=>$fighter_id,'status'=>'queued'];
        }else{$result=$service->compare_saved($fighter_id);}
        \WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    /** Display the destination, last imported roster and comparison progress. */
    public function status(array $args,array $assoc_args): void {
        if(isset($assoc_args['format']) && $assoc_args['format']!=='json'){\WP_CLI::error('Only --format=json is supported.');}
        \WP_CLI::line(wp_json_encode((new UfcSigningService())->status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
