<?php
/** WP-CLI eval-file; writes private temporary MySQL credentials, never prints them. */
$path=getenv('MMAF_DB_CLIENT_FILE');
if(!$path || !is_dir(dirname($path)) || is_link($path)){throw new RuntimeException('Private credential path required.');}
$quote=static fn($v)=>'"'.addcslashes($v,"\\\"\n\r").'"';
$host=DB_HOST;$socket=null;$port=null;
if(strpos($host,':/')!==false){[$host,$socket]=explode(':',$host,2);}elseif(preg_match('/^([^:]+):(\d+)$/',$host,$m)){$host=$m[1];$port=$m[2];}
$data="[client]\nuser=".$quote(DB_USER)."\npassword=".$quote(DB_PASSWORD)."\nhost=".$quote($host)."\n";
if($socket){$data.='socket='.$quote($socket)."\n";}if($port){$data.='port='.$port."\n";}
$mask=umask(0077);if(file_put_contents($path,$data)!==strlen($data)){throw new RuntimeException('Cannot save private credentials.');}chmod($path,0600);umask($mask);
echo DB_NAME;
