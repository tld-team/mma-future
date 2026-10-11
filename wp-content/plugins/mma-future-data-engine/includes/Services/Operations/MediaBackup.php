<?php
namespace MMAF\DataEngine\Services\Operations;

/** Same private content-addressed media format as the native release runner. */
final class MediaBackup {
    public static function estimate(): array {
        $root=realpath(wp_upload_dir()['basedir']);$bytes=0;$count=0;
        if($root){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink()){throw new \RuntimeException('Media symlinks need individual review.');}
            if($file->isFile()){$bytes+=$file->getSize();$count++;}
        }}return ['bytes'=>$bytes,'files'=>$count];
    }
    public static function capture(): array {
        if(!defined('MMAF_OPERATIONS_DIR')){throw new \RuntimeException('Private media storage required.');}
        $uploads=wp_upload_dir()['basedir'];$root=realpath($uploads);$files=[];
        if(!$root){return $files;}
        $objects=MMAF_OPERATIONS_DIR.'/media-objects';
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink()){throw new \RuntimeException('Media symlinks need individual review.');}
            if(!$file->isFile()){continue;}$hash=hash_file('sha256',$file->getPathname());$folder=$objects.'/'.substr($hash,0,2);
            if(!wp_mkdir_p($folder)){throw new \RuntimeException('Cannot create private media backup.');}chmod($folder,0700);
            $target=$folder.'/'.$hash;
            if(!is_file($target)){
                $temp=$target.'.pending';if(!copy($file->getPathname(),$temp) || hash_file('sha256',$temp)!==$hash){throw new \RuntimeException('Media changed during backup.');}chmod($temp,0600);rename($temp,$target);
            }elseif(hash_file('sha256',$target)!==$hash){throw new \RuntimeException('Private media object is damaged.');}
            $name=substr($file->getPathname(),strlen($root)+1);$files[str_replace(DIRECTORY_SEPARATOR,'/',$name)]=['sha256'=>$hash,'bytes'=>$file->getSize()];
        }
        ksort($files);return $files;
    }
}
