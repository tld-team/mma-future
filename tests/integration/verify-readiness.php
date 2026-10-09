<?php
require __DIR__.'/bootstrap.php';
$service=new MMAF\DataEngine\Services\FighterReadinessService();
$full=$service->report('2026-10-09');$compact=$service->report('2026-10-09',false);
ksort($full['blockers']);ksort($compact['blockers']);
foreach(['counts','buckets','blockers','closest'] as $key){if($full[$key]!==$compact[$key]){throw new RuntimeException('Readiness report mismatch: '.$key);}}
if($compact['items'] || $compact['items_included']){throw new RuntimeException('Compact report retains the full population.');}
echo 'PASS: batched administrator readiness report preserves all counts, blockers and top candidates'.PHP_EOL;
