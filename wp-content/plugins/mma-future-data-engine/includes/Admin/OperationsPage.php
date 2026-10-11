<?php
namespace MMAF\DataEngine\Admin;
use MMAF\DataEngine\Services\Operations\{Jobs,Schema,Presentation,Membership};
use MMAF\DataEngine\Support\Capabilities;
final class OperationsPage {
    public static function render(): void {
        if(!current_user_can(Capabilities::MANAGE)){wp_die('Access denied.','',['response'=>403]);}
        if(version_compare((string)get_option('mmaf_db_version'),'1.8.0','<')){echo '<div class="wrap"><h1>Operations</h1><p>A checked migration is required.</p></div>';return;}
        global $wpdb;$id=absint($_GET['operation_id']??0);$table=Schema::tables()['jobs'];
        echo '<div class="wrap"><h1>MMA Future Operations</h1><p><a href="'.esc_url(admin_url('admin.php?page=mmaf-fighters')).'">Fighters</a> · <a href="'.esc_url(admin_url('admin.php?page=mmaf-regular-import')).'">Imports</a> · <a href="'.esc_url(admin_url('admin.php?page=mmaf-review')).'">Review</a> · <a href="'.esc_url(admin_url('admin.php?page=mmaf-health')).'">Operations overview</a></p>';
        if(!$id){
            $page=max(1,absint($_GET['paged']??1));
            $rows=$wpdb->get_results($wpdb->prepare("SELECT id,kind,action,status,phase,total,updated_at,error_message FROM $table ORDER BY id DESC LIMIT 26 OFFSET %d",($page-1)*25),ARRAY_A);
            $more=count($rows)>25;
            echo '<table class="widefat striped"><thead><tr><th>Operation</th><th>Action</th><th>Status / phase</th><th>Frozen scope</th><th>Updated</th><th>Issue</th></tr></thead><tbody>';
            foreach(array_slice($rows,0,25) as $row){echo '<tr><td><a href="'.esc_url(admin_url('admin.php?page=mmaf-operations&operation_id='.$row['id'])).'">#'.(int)$row['id'].'</a></td><td>'.esc_html(Presentation::label($row['action'])).'</td><td>'.esc_html(Presentation::label($row['status']).' · '.Presentation::label($row['phase'])).'</td><td>'.(int)$row['total'].'</td><td>'.esc_html(self::date($row['updated_at'])).'</td><td>'.esc_html($row['error_message']??'').'</td></tr>';}
            if(!$rows){echo '<tr><td colspan="6">No operations yet. Select fighters and prepare a preview to begin.</td></tr>';}
            echo '</tbody></table>';
            if($page>1){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mmaf-operations&paged='.($page-1))).'">Previous</a> ';}
            if($more){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mmaf-operations&paged='.($page+1))).'">Next</a>';}
            echo '</div>';return;
        }
        $job=Jobs::get($id);if(!$job){echo '<p>Operation not found.</p></div>';return;}
        $page=max(1,absint($_GET['paged']??1));$result=Jobs::items($id,$page,25);
        $active=in_array($job['status'],['previewing','running'],true);
        echo '<div '.($active?'data-mmaf-operation data-endpoint="'.esc_url(add_query_arg(['page'=>$page,'html'=>1],rest_url('mma-future/v1/admin/operations/'.$id))).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"':'').'><div data-operation-progress>';
        self::progress($job,$result);echo '</div><p data-operation-notice role="status" aria-live="polite"></p></div>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mmaf-operations&operation_id='.$id.'&paged='.$page)).'">Refresh progress</a></p>';
        $pages=max(1,(int)ceil((int)$job['total']/25));for($p=max(1,$page-2);$p<=min($pages,$page+2);++$p){echo '<a style="margin-right:12px" href="'.esc_url(admin_url('admin.php?page=mmaf-operations&operation_id='.$id.'&paged='.$p)).'">'.(int)$p.'</a>';}
        echo '</div>';
    }
    public static function date(?string $value): string {
        return $value?wp_date('M j, Y H:i',strtotime($value.' UTC')):'Not recorded';
    }
    public static function progress(array $job,array $result): void {
        $view=Presentation::progress($job,$result['counts']);$commands=$view['allowed_commands'];
        echo '<h2>Operation #'.(int)$job['id'].' · '.esc_html(Presentation::label($job['action'])).'</h2>';
        echo '<p role="status"><strong>'.esc_html($view['status_label']).'</strong> · '.esc_html($view['phase']).'</p>';
        echo '<p>'.(int)$job['total'].' frozen fighters · '.(int)$view['processed'].' with a final result. Created '.esc_html(self::date($job['created_at'])).' · Last heartbeat '.esc_html(self::date($job['heartbeat_at'])).'.</p>';
        echo '<p class="description">Obuhvat je zamrznut. Novi borci ne ulaze u ovu operaciju. Priprema koristi sačuvani JSON; aktivacija rangiranja je zasebna odluka.</p>';
        echo '<ul style="display:flex;gap:18px;flex-wrap:wrap">';
        foreach($result['counts'] as $status=>$count){echo '<li><strong>'.(int)$count.'</strong> '.esc_html(Presentation::label($status)).'</li>';}
        echo '</ul><p>Processing time '.esc_html(number_format((int)$job['duration_ms']/1000,1)).' s · peak '.esc_html(size_format((int)$job['peak_memory'])).'</p>';
        if($job['error_message']){echo '<div class="notice notice-error inline"><p>'.esc_html($job['error_message']).'</p></div>';}
        if($job['status']==='ready'){
            echo '<div class="notice notice-info inline"><p>Saved-profile readiness: '.(int)($job['summary']['ready']??0).' ready for preparation; '.(int)($job['summary']['review']??0).' need review; '.(int)($job['summary']['failed']??0).' technical failures; '.(int)($job['summary']['already_public']??0).' already public; '.(int)($job['summary']['already_rankable']??0).' already rankable.</p>';
            if(isset($job['summary']['ranking_ready'],$job['summary']['ranking_blocked'])){echo '<p>Current ranking checks: '.(int)$job['summary']['ranking_ready'].' pass; '.(int)$job['summary']['ranking_blocked'].' have blockers. Checks run again after preparation; this preview does not publish fighters.</p>';}
            echo '</div>';
            foreach($job['summary']['reasons']??[] as $reason=>$count){echo '<p>'.(int)$count.' · '.esc_html(Presentation::label($reason)).'</p>';}
        }
        foreach($job['summary']['excluded']??[] as $excluded){echo '<p>Excluded #'.(int)$excluded['fighter_id'].' · '.esc_html(Presentation::label($excluded['reason'])).'</p>';}
        foreach($commands as $command=>$label){if($command!=='retry'){self::button($job,$command,$label);}}
        echo '<details style="margin:12px 0"><summary>Technical operation details</summary><pre style="white-space:pre-wrap">'.esc_html(wp_json_encode(['selection'=>$job['selection'],'summary'=>$job['summary'],'checkpoint'=>$job['checkpoint'],'manifest_hash'=>$job['manifest_hash']],JSON_PRETTY_PRINT)).'</pre></details>';
        $retry=isset($commands['retry']);
        if($retry){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mmaf_operation');echo '<input type="hidden" name="action" value="mmaf_operation"><input type="hidden" name="operation_id" value="'.(int)$job['id'].'"><input type="hidden" name="command" value="retry">';}
        $members=Membership::for_page(array_column($result['items'],'fighter_id'));
        echo '<table class="widefat striped"><thead><tr>'.($retry?'<th>Retry</th>':'').'<th>Fighter</th><th>Result</th><th>Reason / next step</th></tr></thead><tbody>';
        foreach($result['items'] as $row){
            echo '<tr>';
            if($retry){echo '<td>';if($row['status']==='failed'){echo '<input type="checkbox" name="fighter_ids[]" value="'.(int)$row['fighter_id'].'" aria-label="Retry fighter '.(int)$row['fighter_id'].'">';}echo '</td>';}
            echo '<td><a href="'.esc_url(admin_url('admin.php?page=mmaf-fighters&action=edit&fighter_id='.$row['fighter_id'])).'">#'.(int)$row['fighter_id'].' '.esc_html($row['display_name']??'').'</a>';
            if(!empty($members[(int)$row['fighter_id']])){echo '<br><a href="'.esc_url(admin_url('admin.php?page=mmaf-regular-import&job_id='.$members[(int)$row['fighter_id']])).'">Import #'.(int)$members[(int)$row['fighter_id']].'</a>';}
            echo '</td><td>'.esc_html(Presentation::label($row['status'])).'</td><td>'.esc_html($row['reason_code']?Presentation::label($row['reason_code']):($row['status']==='completed'?'Requested changes applied.':($row['status']==='noop'?'Saved facts and source are unchanged.':'')));
            if($row['status']==='pending' && !empty($row['result']['ranking_blockers'])){echo '<p>Current ranking blockers: '.esc_html($row['result']['ranking_blocker_summary']??implode('; ',array_map([Presentation::class,'label'],$row['result']['ranking_blockers']))).'</p>';}
            if($row['status']==='review'){echo '<br><a href="'.esc_url(admin_url('admin.php?page=mmaf-review&fighter_id='.$row['fighter_id'])).'">Review fighter evidence and allowed next steps</a>';}
            echo '<details><summary>Processing evidence</summary><pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($row['result'],JSON_PRETTY_PRINT)).'</pre></details></td></tr>';
        }
        if(!$result['items']){echo '<tr><td colspan="'.($retry?4:3).'">No fighter results on this page.</td></tr>';}
        echo '</tbody></table>';
        if($retry){echo '<p><button class="button">Retry selected technical failures</button></p></form>';}
    }
    private static function button(array $job,string $command,string $label): void {
        echo '<form style="display:inline-block;margin-right:8px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mmaf_operation');
        echo '<input type="hidden" name="action" value="mmaf_operation"><input type="hidden" name="operation_id" value="'.(int)$job['id'].'"><input type="hidden" name="command" value="'.esc_attr($command).'"><input type="hidden" name="manifest_hash" value="'.esc_attr($job['manifest_hash']??'').'"><button class="button">'.esc_html($label).'</button></form>';
    }
}
