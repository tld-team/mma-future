<?php
// Write verification credentials directly to a private file; never print them.
if(wp_get_environment_type()!=='local' || !defined('MMAF_OPERATIONS_DIR')){throw new RuntimeException('Local private runtime required.');}
$host=parse_url(home_url('/'),PHP_URL_HOST);
if(!in_array($host,['localhost','127.0.0.1','::1'],true) && !preg_match('/\.(?:local|test)$/D',$host)){throw new RuntimeException('Local site address required.');}
$path=getenv('MMAF_LOCAL_VERIFY_PATH');$root=realpath(MMAF_OPERATIONS_DIR);
if(!$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || is_link($path)){throw new RuntimeException('Private verification output required.');}
$raw=DB_HOST;$socket=null;$port=3306;
if(str_contains($raw,':/')){[$raw,$socket]=explode(':',$raw,2);}elseif(preg_match('/^(.+):(\d+)$/D',$raw,$match)){$raw=$match[1];$port=(int)$match[2];}
$value=['verification'=>['database'=>DB_NAME,'user'=>DB_USER,'password'=>DB_PASSWORD,'host'=>$raw,'port'=>$port]];
if($socket){$value['verification']['socket']=$socket;}
if(file_put_contents($path,wp_json_encode($value))===false){throw new RuntimeException('Cannot write local verification config.');}chmod($path,0600);
echo 'Local verification configuration prepared.';
