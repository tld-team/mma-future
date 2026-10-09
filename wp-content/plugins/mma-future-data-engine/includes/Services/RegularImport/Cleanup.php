<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Cleanup {
    public static function run(): void {
        Mutex::run(static function(){
            global $wpdb;$repo=new Repository();$table=$repo->tables['jobs'];
            $keep=array_map('intval',$wpdb->get_col("SELECT id FROM $table WHERE status IN ('completed','completed_with_review') ORDER BY id DESC LIMIT 3"));
            $jobs=$wpdb->get_results("SELECT * FROM $table WHERE status IN ('completed','completed_with_review','rejected','discarded','uploading')",ARRAY_A);
            foreach($jobs as $raw){$job=$repo->decode($raw);$id=(int)$job['id'];
                if(in_array($id,$keep,true)||!empty($job['meta']['artifact_expired'])){continue;}
                $upload=$job['status']==='uploading';$expiry=$upload?86400:30*86400;
                if(strtotime(($upload?$job['updated_at']:($job['finished_at']?:$job['updated_at'])).' UTC')>time()-$expiry){continue;}
                $dir=Storage::root().'/job-'.$id;if(is_dir($dir)){Storage::removeTree($dir);}
                $meta=$job['meta'];$meta['artifact_expired']=true;$fields=['meta'=>$meta];
                if($upload){$fields+=['status'=>'discarded','finished_at'=>gmdate('Y-m-d H:i:s')];}
                $repo->update($id,$fields);
            }
        });
    }
}
