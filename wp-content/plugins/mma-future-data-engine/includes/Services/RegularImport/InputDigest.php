<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Canonical JSON matching Python's content_hash, independent of observation timestamps. */
final class InputDigest {
    private const VOLATILE=['scraped_at','profile_scraped_at','fetched_at','diagnostics','profile_cache_hit','profile_cache_source','profile_cache_scraped_at','first_seen_at','last_seen_at','last_scraped_at','run_id'];
    private static function clean($value){
        if(is_float($value)){if(!is_finite($value)){throw new \InvalidArgumentException('Non-finite source number');}return ['__float64__'=>bin2hex(pack('E',$value))];}
        if(!is_array($value)){return $value;}
        if(!array_is_list($value)){foreach(self::VOLATILE as $key){unset($value[$key]);}ksort($value);}
        foreach($value as &$item){$item=self::clean($item);}unset($item);return $value;
    }
    public static function hash(array $value): string {
        return hash('sha256',json_encode(self::clean($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
}
