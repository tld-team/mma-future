<?php
namespace MMAF\DataEngine\Services\Operations;
use MMAF\DataEngine\Migrations\Schema as Canonical;

/** A small transaction-local input marker, including writes from existing import services. */
final class Revision {
    private static bool $writing=false;
    public static function current(): string {
        global $wpdb;$table=Canonical::table_names()['system_state'];
        return (string)($wpdb->get_var("SELECT state_value FROM $table WHERE state_key='canonical_revision'")?:'initial');
    }
    public static function register(): void {
        add_filter('query',static function($sql){
            if(self::$writing){return $sql;}
            $t=Canonical::table_names();$tracked=[];
            foreach(['fighters','fighter_sources','fighter_aliases','events','event_sources','bouts','bout_sources','bout_participants','fighter_stats_overrides'] as $key){$tracked[]=$t[$key];}
            $tracked[]=\MMAF\DataEngine\Services\DataPipeline\Schema::tables()['profiles'];
            $tracked[]=\MMAF\DataEngine\Services\Scoring\Schema::tables()['career'];
            if(!preg_match('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?('.implode('|',array_map(static fn($v)=>preg_quote($v,'/'),$tracked)).')`?(?:\s|\()/i',$sql)){return $sql;}
            // Check options only after matching a canonical write. Reading an
            // uncached option also runs this query filter and must not recurse.
            if(version_compare((string)get_option('mmaf_db_version','0'),'1.7.0','<')){return $sql;}
            global $wpdb;self::$writing=true;
            try{
                $table=$t['system_state'];$token=bin2hex(random_bytes(16));
                if(false===$wpdb->query($wpdb->prepare("INSERT INTO $table (state_key,state_value,autoload,updated_at) VALUES ('canonical_revision',%s,'no',%s) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),updated_at=VALUES(updated_at)",$token,gmdate('Y-m-d H:i:s')))){throw new \RuntimeException('Could not record canonical input revision.');}
            }finally{self::$writing=false;}
            return $sql;
        });
    }
}
