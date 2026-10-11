<?php
namespace MMAF\DataEngine\CLI;

use MMAF\DataEngine\Services\Operations\{Calculation,Jobs};

/** CLI and administration share the same durable calculation and checkpoints. */
final class CalculationCommand {
    public static function run(string $kind,array $options): array {
        $id=isset($options['operation-id'])?absint($options['operation-id']):0;
        if($id){
            $job=Jobs::get($id);
            if(!$job || $job['kind']!==$kind){throw new \InvalidArgumentException('Choose an operation of the requested calculation type.');}
        }else{
            $id=Calculation::create($kind,0,get_current_user_id(),(string)($options['key']??''),$options['reference-date']??null);
            $job=Jobs::get($id);
        }
        if(isset($options['wait'])){
            $deadline=microtime(true)+min(50,max(1,(int)($options['time-budget']??50)));
            while($job['status']==='running' && microtime(true)<$deadline){
                if($job['retry_at'] && $job['retry_at']>gmdate('Y-m-d H:i:s')){break;}
                $job=Calculation::step($id);
                if($job['error_message']){break;}
            }
        }
        \WP_CLI::line('Operation ID: '.$id);
        \WP_CLI::line('Status: '.$job['status'].'; phase: '.$job['phase']);
        if($job['status']==='failed'){throw new \RuntimeException($job['error_message']?:'Calculation failed.');}
        if($job['status']!=='completed'){
            \WP_CLI::success('Calculation saved. The worker continues it; inspect with wp mmaf operations status --id='.$id.'.');
        }
        return $job;
    }
}
