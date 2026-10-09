<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** JsonMachine ends at the root closing token; reject any trailing non-whitespace. */
final class JsonDocument {
    public static function assertComplete(string $file): void {
        $handle=fopen($file,'rb');
        if (!$handle) { throw new ImportException('Cannot verify the source document.'); }
        $started=false; $ended=false; $quoted=false; $escaped=false; $stack=[]; $first=true;
        try {
            while (!feof($handle)) {
                $chunk=fread($handle,65536);
                if ($chunk===false) { throw new ImportException('Cannot read the source document.'); }
                if ($first && substr($chunk,0,3)==="\xEF\xBB\xBF") { $chunk=substr($chunk,3); }
                $first=false;
                $length=strlen($chunk);
                for ($i=0;$i<$length;++$i) {
                    if ($ended) {
                        if (strspn($chunk," \t\r\n",$i)!==$length-$i) { throw new ImportException('Unexpected content after the JSON document.'); }
                        break;
                    }
                    if (!$started) {
                        $i+=strspn($chunk," \t\r\n",$i);
                        if ($i===$length) { break; }
                        if ($chunk[$i]!=='{' && $chunk[$i]!=='[') { throw new ImportException('Expected a JSON object or array.'); }
                        $started=true;
                    }
                    if ($quoted) {
                        if ($escaped) { $escaped=false; continue; }
                        $i+=strcspn($chunk,"\"\\",$i);
                        if ($i===$length) { break; }
                        if ($chunk[$i]==='\\') { $escaped=true; }
                        else { $quoted=false; }
                        continue;
                    }
                    $i+=strcspn($chunk,'{}[]"',$i);
                    if ($i===$length) { break; }
                    $token=$chunk[$i];
                    if ($token==='"') { $quoted=true; }
                    elseif ($token==='{' || $token==='[') {
                        $stack[]=$token;
                        if (count($stack)>512) { throw new ImportException('The JSON document is nested too deeply.'); }
                    } else {
                        $open=array_pop($stack);
                        if (($token==='}' && $open!=='{') || ($token===']' && $open!=='[')) { throw new ImportException('Invalid JSON document structure.'); }
                        if (!$stack) { $ended=true; }
                    }
                }
            }
            if (!$ended || $quoted) { throw new ImportException('The JSON document is incomplete.'); }
        } finally { fclose($handle); }
    }
}
