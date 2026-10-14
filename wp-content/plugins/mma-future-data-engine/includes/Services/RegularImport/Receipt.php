<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Delta bases are committed destination receipts, never a local scraper changes file. */
final class Receipt {
    public static function destination(): string {global $wpdb;return hash('sha256',home_url('/').'|'.DB_NAME.'|'.$wpdb->prefix);}
    public static function validate(array $base): array {
        if(($base['format']??'')!=='mmaf_import_receipt_v1' || ($base['destination_id']??'')!==self::destination() || ($base['site_url']??'')!==home_url('/')){throw new ImportException('Delta package belongs to another destination. Export a full package.','delta_base');}
        if(($base['contract_version']??0)!==1 || ($base['schema_version']??'')!==MMAF_DB_VERSION || ($base['policy_version']??'')!==\MMAF\DataEngine\Services\DataPipeline\PreparationFingerprint::VERSION || ($base['calculation_policy']??'')!==DatabaseState::calculationPolicy()){throw new ImportException('Import rules changed since the delta base. Export a full package.','delta_base');}
        try{$job=(new Repository())->get((int)($base['job_id']??0));}catch(ImportException $e){throw new ImportException('The committed delta base is unavailable. Export a full package.','delta_base');}$stored=$job['meta']['receipt']??[];
        if(!in_array($job['status'],['completed','completed_with_review'],true) || ($job['bundle_hash']??'')!==($base['bundle_hash']??'') || ($stored['canonical_revision']??'')!==($base['canonical_revision']??'') || !hash_equals((string)($base['canonical_revision']??''),DatabaseState::revision())){throw new ImportException('The delta base changed or is unfinished. Download a fresh full package.','delta_base');}
        $path=Storage::job((int)$job['id']).'/receipt.json';if(!is_file($path)){throw new ImportException('The retained delta receipt expired. Export a full package.','delta_base');}$receipt=Storage::read($path);
        if(($receipt['destination_id']??'')!==self::destination()){throw new ImportException('Stored delta destination differs.','delta_base');}
        return $receipt;
    }
    public static function create(array $job): array {
        $indices=['events'=>[],'profiles'=>[]];
        if(!empty($job['meta']['package']['delta_base'])){
            // The base was verified before the first write. Own writes now change the revision.
            $base=Storage::read(Storage::job((int)$job['meta']['package']['delta_base']['job_id']).'/receipt.json');$indices=$base['input_indices'];
        }
        $repo=new Repository();
        foreach($repo->batches((int)$job['id']) as $batch){
            if(!in_array($batch['kind'],['results','profiles'],true)){continue;}
            $data=Package::payload($job,$batch);$kind=$batch['kind']==='results'?'events':'profiles';$key=$kind==='events'?'source_event_id':'source_fighter_id';
            foreach($data[$kind] as $row){
                $identity=$row[$key]??null;
                if(!$identity && $kind==='profiles'){$parsed=\MMAF\DataEngine\Support\TapologyFighterUrl::parse((string)($row['source_url']??''));$identity=$parsed?($parsed['source_fighter_id']?:'tapology_fighter_url_'.substr($parsed['source_url_hash'],0,24)):null;}
                if($identity){$indices[$kind][$identity]=InputDigest::hash($row);}
            }
        }
        $receipt=['format'=>'mmaf_import_receipt_v1','contract_version'=>1,'schema_version'=>MMAF_DB_VERSION,'policy_version'=>\MMAF\DataEngine\Services\DataPipeline\PreparationFingerprint::VERSION,'calculation_policy'=>DatabaseState::calculationPolicy(),'job_id'=>(int)$job['id'],'destination_id'=>self::destination(),'site_url'=>home_url('/'),'bundle_hash'=>$job['bundle_hash'],'canonical_revision'=>DatabaseState::revision(),'input_indices'=>$indices];
        Storage::write(Storage::job((int)$job['id']).'/receipt.json',$receipt);return $receipt;
    }
    public static function pointer(array $receipt): array {unset($receipt['input_indices']);return $receipt;}
}
