<?php
namespace MMAF\DataEngine\Services\Operations;

use MMAF\DataEngine\Services\RegularImport\Repository;

/** Bounded multi-row writes inside the caller's checkpoint transaction. */
final class BulkWriter {
    public static function insert(string $table,iterable $rows,bool $replace=false): void {
        global $wpdb;
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table)){throw new \InvalidArgumentException('Invalid batch table.');}
        $columns=null;$values=[];$bytes=0;
        $flush=static function()use(&$values,&$bytes,&$columns,$table,$replace,$wpdb){
            if(!$values){return;}
            Repository::check($wpdb->query(($replace?'REPLACE':'INSERT')." INTO `$table` (`".implode('`,`',$columns)."`) VALUES ".implode(',',$values)));
            $values=[];$bytes=0;
        };
        foreach($rows as $row){
            if($columns===null){$columns=array_keys($row);foreach($columns as $column){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$column)){throw new \InvalidArgumentException('Invalid batch column.');}}}
            if(array_keys($row)!==$columns){throw new \InvalidArgumentException('Batch columns differ.');}
            $encoded=[];foreach($row as $value){$encoded[]=$value===null?'NULL':$wpdb->prepare('%s',(string)$value);}
            $value='('.implode(',',$encoded).')';
            if(strlen($value)>1024*1024){throw new \RuntimeException('A calculation row exceeds the bounded write size.');}
            if(count($values)>=250 || $bytes+strlen($value)>1024*1024){$flush();}
            $values[]=$value;$bytes+=strlen($value)+1;
        }
        $flush();
    }
}
