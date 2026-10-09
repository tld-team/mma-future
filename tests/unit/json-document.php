<?php
require dirname(__DIR__,2).'/wp-content/plugins/mma-future-data-engine/vendor/autoload.php';
require dirname(__DIR__,2).'/wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/ImportException.php';
require dirname(__DIR__,2).'/wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/JsonDocument.php';
use MMAF\DataEngine\Services\RegularImport\{JsonDocument,ImportException};
use MMAFVendor\JsonMachine\RecursiveItems;
function consume_json(RecursiveItems $items): void { foreach($items as $value){if($value instanceof RecursiveItems){consume_json($value);}} }
$file=tempnam(sys_get_temp_dir(),'mmaf-json-');$cases=0;
try {
    $large=json_encode(['text'=>str_repeat('quoted " and escaped \\ braces {}[] ',5000),'nested'=>[[],['ok'=>true]]]);
    foreach(['{}',"\xEF\xBB\xBF{\"ok\":true}\n",'[]',"  {\"nested\":[{},[],\"}\\\"[\"]} \r\n",$large,'{"text":"'.str_repeat('a',65527).'\\"z"}'] as $text){
        file_put_contents($file,$text);consume_json(RecursiveItems::fromFile($file));JsonDocument::assertComplete($file);++$cases;
    }
    foreach(['{} trailing','{} {}','{"x":[]}false','[] true','{"x":"unterminated}','{"x":[1]} }','{','',str_repeat(' ',65535).'{}x'] as $text){
        file_put_contents($file,$text);
        try { JsonDocument::assertComplete($file);throw new RuntimeException('Invalid JSON framing accepted.'); }
        catch(ImportException $e){++$cases;}
    }
    echo "PASS: $cases streaming JSON structure and trailing-content cases\n";
} finally { unlink($file); }
