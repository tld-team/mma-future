<?php
function remove_accents(string $value): string {return strtr($value,['Ž'=>'Z','ž'=>'z','ć'=>'c','č'=>'c','š'=>'s','đ'=>'d','Ć'=>'C']);}
function wp_json_encode($value,int $flags=0): string {return json_encode($value,$flags|JSON_THROW_ON_ERROR);}
function get_option(string $key,$default=null){return $GLOBALS['ufc_test_options'][$key]??$default;}
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/UfcMatcher.php';
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/UfcSigningService.php';
require __DIR__.'/../../wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/Package.php';
use MMAF\DataEngine\Services\UfcMatcher;
use MMAF\DataEngine\Services\UfcSigningService;
function expect_match(bool $condition,string $label): void {if(!$condition){throw new RuntimeException($label);}echo "PASS: $label\n";}
$fighters=[
    ['id'=>1,'display_name'=>'Aleksandar Jovanović','date_of_birth'=>'1995-01-02','gender'=>'male','weight_class'=>'Lightweight','in_ufc'=>0],
    ['id'=>2,'display_name'=>'Aleksandar Jovanović','date_of_birth'=>'1994-01-02','gender'=>'female','weight_class'=>'Welterweight','in_ufc'=>0],
    ['id'=>3,'display_name'=>'Marko Petrović','date_of_birth'=>'1991-02-03','gender'=>'male','in_ufc'=>0],
    ['id'=>4,'display_name'=>'Old Fighter','date_of_birth'=>'1991-02-03','deleted_soft'=>1],
];
$index=UfcMatcher::build_index($fighters,[['fighter_id'=>3,'alias'=>'Marcus Petrovic']]);
$same=UfcMatcher::match(['source_key'=>'one','display_name'=>'Aleksandar Jovanović','date_of_birth'=>'1995-01-02','gender'=>'male'],$index);
expect_match(count($same)===2 && $same[0]['fighter_id']===1 && $same[0]['reason']==='name_and_date_of_birth' && $same[1]['fighter_id']===2 && count($same[1]['conflicts'])>=2,'homonyms remain separate with DOB and gender conflicts');
$weight=UfcMatcher::match(['source_key'=>'weight','display_name'=>'Aleksandar Jovanović','weight_class'=>'Welterweight'],$index);
expect_match(($weight[0]['signals'][0]['type']??'')==='weight_class_changed','weight class change stays an auxiliary signal');
$initial=UfcMatcher::match(['source_key'=>'two','display_name'=>'A Jovanovic','date_of_birth'=>'1995-01-02'],$index);
expect_match(count($initial)>=1 && $initial[0]['fighter_id']===1,'controlled initial and accent variant matches');
if(function_exists('transliterator_transliterate')){$cyrillic=UfcMatcher::match(['source_key'=>'cyrillic','display_name'=>'Марко Петровић','date_of_birth'=>'1991-02-03'],$index);expect_match(count($cyrillic)===1 && $cyrillic[0]['fighter_id']===3,'Cyrillic transliteration matches Latin identity');}
$alias=UfcMatcher::match(['source_key'=>'three','display_name'=>'Marcus Petrovic','date_of_birth'=>'1991-02-03'],$index);
expect_match(count($alias)===1 && $alias[0]['fighter_id']===3,'alias matches');
$fuzzy=UfcMatcher::match(['source_key'=>'four','display_name'=>'Marko Petrovicc','date_of_birth'=>'1991-02-03'],$index);
expect_match(count($fuzzy)===1 && $fuzzy[0]['reason']==='similar_name_and_date_of_birth','small typo only matches with full DOB');
expect_match(!UfcMatcher::match(['source_key'=>'five','display_name'=>'Marko Petrovicc'],$index),'fuzzy name without DOB does not match');
$negative=UfcMatcher::build_index($fighters,[],[['source_key'=>'six','fighter_id'=>1,'status'=>'different']]);
expect_match(!array_filter(UfcMatcher::match(['source_key'=>'six','display_name'=>'Aleksandar Jovanović'],$negative),static fn($row)=>$row['fighter_id']===1),'negative identity link persists');
$row=['source_key'=>'one','display_name'=>'Test','roster_status'=>'log_only','events'=>[['date'=>'2026-10-25','action'=>'added','text'=>'Signed']]];
$hash=UfcSigningService::evidence_hash($row);$row['observed_at']='2026-10-26';$row['last_run_id']='next';
expect_match($hash===UfcSigningService::evidence_hash($row),'fetch time and run ID do not reopen a decision');
$row['events'][0]['text']='Signed officially';expect_match($hash!==UfcSigningService::evidence_hash($row),'material evidence change changes fingerprint');
$base=['schema_version'=>'mmaf_ufc_roster_v2','source'=>'roster.watch','run_id'=>'unit-v2','scraped_at'=>gmdate('c'),'source_urls'=>['current'=>'https://www.roster.watch/','former'=>'https://www.roster.watch/former.html','log'=>'https://www.roster.watch/log.html'],'counts'=>['current'=>1,'former'=>1,'log_events'=>1,'fighters'=>2],'fighters'=>[
    ['source_key'=>'current','display_name'=>'One Fighter','source_url'=>'https://www.roster.watch/','roster_status'=>'current','events'=>[]],
    ['source_key'=>'former','display_name'=>'Other Fighter','source_url'=>'https://www.roster.watch/former.html','roster_status'=>'former','events'=>[['date'=>'2026-10-25','action'=>'removed','text'=>'Removed','source_url'=>'https://www.roster.watch/log.html']]],
]];
$base['content_sha256']=hash('sha256',wp_json_encode($base,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$service=(new ReflectionClass(UfcSigningService::class))->newInstanceWithoutConstructor();
expect_match(count($service->validate_document($base)['fighters'])===2,'v2 document validates with checksum and event source');
$tampered=$base;$tampered['fighters'][0]['display_name']='Changed';$bad=false;try{$service->validate_document($tampered);}catch(RuntimeException $error){$bad=true;}expect_match($bad,'content checksum rejects tampering');
$old=$base;$old['schema_version']='mmaf_ufc_roster_v1';$old['fighters'][1]['source_cells']=[];unset($old['fighters'][1]['events'][0]['source_url']);unset($old['content_sha256']);$old['content_sha256']=hash('sha256',wp_json_encode($old,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
expect_match($service->validate_document($old)['fighters'][1]['roster_status']==='log_only','v1 log-only evidence is adapted after checksum verification');
$package=new MMAF\DataEngine\Services\RegularImport\Package();$freshness=new ReflectionMethod($package,'ufc_warning');$freshness->setAccessible(true);
expect_match($freshness->invoke($package,$base)===null,'fresh standalone UFC evidence passes');
$stale=$base;$stale['scraped_at']=gmdate('c',time()-49*3600);expect_match($freshness->invoke($package,$stale)!==null,'snapshot older than 48 hours is rejected');
$GLOBALS['ufc_test_options']['mmaf_ufc_roster_last_import']=['scraped_at'=>gmdate('c',time()+60)];expect_match($freshness->invoke($package,$base)!==null,'older snapshot cannot overwrite a newer import');
