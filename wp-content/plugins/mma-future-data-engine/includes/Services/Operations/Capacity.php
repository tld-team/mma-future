<?php
namespace MMAF\DataEngine\Services\Operations;

/** Risky filesystem work requires fresh account bytes AND inode evidence. */
final class Capacity {
    public static function requireSpace(int $additional,int $inodes=100): void {
        if(!defined('MMAF_OPERATIONS_DIR')){throw new \RuntimeException('Verified private storage capacity is required.');}
        $root=MMAF_OPERATIONS_DIR;$path=$root.'/capacity.json';$q=is_file($path)?json_decode(file_get_contents($path),true):[];
        if(!$q || time()-(int)($q['checked_at']??0)>3600 || (int)($q['checked_at']??0)>time()+5){throw new \RuntimeException('Hosting account capacity is unknown or stale.');}
        $free=disk_free_space($root);$reserve=max(1073741824,(int)ceil($additional*.2));
        $bytes=!empty($q['bytes_unlimited'])?$free:(isset($q['limit_bytes'],$q['used_bytes'])?max(0,$q['limit_bytes']-$q['used_bytes']):null);
        $availableInodes=!empty($q['inodes_unlimited'])?PHP_INT_MAX:(isset($q['limit_inodes'],$q['used_inodes'])?max(0,$q['limit_inodes']-$q['used_inodes']):null);
        if($free===false || $bytes===null || $availableInodes===null || min($free,$bytes)<$additional+$reserve || $availableInodes<$inodes){throw new \RuntimeException('Insufficient verified account space or inodes.');}
    }
}
