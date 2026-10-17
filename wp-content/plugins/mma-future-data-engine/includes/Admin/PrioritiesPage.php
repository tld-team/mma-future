<?php
namespace MMAF\DataEngine\Admin;
use MMAF\DataEngine\Services\Priorities\{Registry,Schema,Report};
use MMAF\DataEngine\Support\Capabilities;

final class PrioritiesPage {
    public static function register(): void {add_action('admin_post_mmaf_priorities',[self::class,'handle']);}
    public static function allowed(): bool {return current_user_can(Capabilities::MANAGE);}
    private static function url(array $args=[]): string {return add_query_arg(array_merge(['page'=>'mmaf-fighters','action'=>'priorities'],$args),admin_url('admin.php'));}
    private static function form(string $operation): void {
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mmaf_priorities');echo '<input type="hidden" name="action" value="mmaf_priorities"><input type="hidden" name="operation" value="'.esc_attr($operation).'">';
    }
    public static function handle(): void {
        if(!self::allowed()){wp_die('Access denied.','',['response'=>403]);}
        check_admin_referer('mmaf_priorities');
        try {
            $op=sanitize_key(wp_unslash($_POST['operation']??''));$actor=get_current_user_id();$message='Saved.';
            if($op==='preview'){
                $file=$_FILES['csv']??[];
                if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)>8*1024*1024 || !is_uploaded_file($file['tmp_name']??'')){throw new \InvalidArgumentException('Upload a UTF-8 CSV smaller than 8 MiB.');}
                $csv=file_get_contents($file['tmp_name']);Registry::parseCsv($csv);$token=bin2hex(random_bytes(16));
                set_transient('mmaf_priority_'.$actor.'_'.$token,$csv,30*MINUTE_IN_SECONDS);
                wp_safe_redirect(self::url(['preview'=>$token]));exit;
            }elseif($op==='confirm'){
                $token=sanitize_key(wp_unslash($_POST['preview']??''));$key='mmaf_priority_'.$actor.'_'.$token;$csv=get_transient($key);
                if(!is_string($csv)){throw new \InvalidArgumentException('Preview expired; upload the CSV again.');}
                $result=Registry::importCsv($csv,$actor);delete_transient($key);$message=sprintf('%d rows: %d created, %d already present.',$result['rows'],$result['created'],$result['existing']);
            }elseif($op==='resolve'){
                Registry::resolve(absint($_POST['target_id']??0),absint($_POST['fighter_id']??0),esc_url_raw(wp_unslash($_POST['source_url']??'')),$actor);
            }elseif($op==='toggle'){
                Registry::active(absint($_POST['target_id']??0),!empty($_POST['active']),$actor);
            }elseif($op==='queue' || $op==='report'){
                nocache_headers();
                if($op==='queue'){header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="priority-queue.json"');echo wp_json_encode(Registry::export(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
                else{
                    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="priority-status.csv"');$out=fopen('php://output','w');
                    $fields=['id','client_name','source_fighter_id','source_url','fighter_id','identity_status','is_active','source_fetched_at','last_attempt_status','period_status','ranking_run_id','ranking_status','reasons','next_action'];fputcsv($out,$fields,',','"','');
                    foreach(Report::rows() as $row){$values=[];foreach($fields as $f){$v=$row[$f]??'';if(is_array($v)){$v=implode('; ',$v);}$v=(string)$v;if(preg_match('/^[=+@\-]/',$v)){$v="'".$v;}$values[]=$v;}fputcsv($out,$values,',','"','');}fclose($out);
                }exit;
            }else{throw new \InvalidArgumentException('Unknown priority operation.');}
            wp_safe_redirect(self::url(['notice'=>$message]));exit;
        }catch(\Throwable $error){wp_die(esc_html($error->getMessage()),'Priority operation failed',['response'=>409,'back_link'=>true]);}
    }
    public static function render(): void {
        if(!self::allowed()){wp_die('Access denied.','',['response'=>403]);}
        echo '<div class="wrap"><h1>Fighters · Priorities</h1><p><a href="'.esc_url(admin_url('admin.php?page=mmaf-fighters')).'">All fighters</a></p><p>Private editorial list. Names and CSV scores are review hints. Confirm a Tapology identity before exporting a fetch queue. Publication and ranking require the existing approval process.</p>';
        if(isset($_GET['notice'])){echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['notice'])).'</p></div>';}
        $token=sanitize_key(wp_unslash($_GET['preview']??''));$csv=$token?get_transient('mmaf_priority_'.get_current_user_id().'_'.$token):false;
        if(is_string($csv)){
            $rows=Registry::parseCsv($csv);echo '<h2>CSV preview · '.count($rows).' rows</h2><p>Original rows are retained. Reimporting identical rows does not create new targets.</p><div style="max-height:450px;overflow:auto"><table class="widefat striped"><thead><tr><th>CSV row</th><th>Name</th><th>Original data</th></tr></thead><tbody>';
            foreach($rows as $row){echo '<tr><td>'.(int)$row['source_row']['row'].'</td><td>'.esc_html($row['client_name']).'</td><td>'.esc_html(wp_json_encode($row['source_row']['values'],JSON_UNESCAPED_UNICODE)).'</td></tr>';}
            echo '</tbody></table></div>';self::form('confirm');echo '<input type="hidden" name="preview" value="'.esc_attr($token).'">';submit_button('Confirm CSV import');echo '</form>';
        }
        echo '<h2>Import priorities</h2>';self::form('preview');echo '<input type="file" name="csv" accept=".csv,text/csv" required>';submit_button('Preview CSV','secondary');echo '</form>';
        foreach(['queue'=>'Export confirmed fetch queue','report'=>'Export status of every priority'] as $op=>$label){self::form($op);submit_button($label,'secondary');echo '</form>';}
        global $wpdb;$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table());$page=max(1,absint($_GET['paged']??1));$limit=20;
        echo '<h2>Registered targets · '.$total.'</h2><table class="widefat striped"><thead><tr><th>Priority / original row</th><th>Confirmed identity</th><th>Source and ranking status</th><th>Membership</th></tr></thead><tbody>';
        foreach(Report::rows($limit,($page-1)*$limit) as $row){
            echo '<tr><td><strong>#'.(int)$row['id'].' '.esc_html($row['client_name']).'</strong><details><summary>Original data</summary><pre style="white-space:pre-wrap">'.esc_html($row['source_row_json']).'</pre></details></td><td>';
            if($row['fighter_id']){echo '<a href="'.esc_url(add_query_arg(['page'=>'mmaf-fighters','action'=>'edit','fighter_id'=>$row['fighter_id']],admin_url('admin.php'))).'">Canonical #'.(int)$row['fighter_id'].'</a><br>';}
            self::form('resolve');echo '<input type="hidden" name="target_id" value="'.(int)$row['id'].'"><label>Tapology URL <input type="url" name="source_url" value="'.esc_attr($row['source_url']??'').'" style="width:100%"></label><br><label>Existing fighter ID (optional) <input type="number" min="1" name="fighter_id" value="'.esc_attr($row['fighter_id']??'').'"></label>';submit_button('Confirm identity','secondary small');echo '</form>';
            if($row['identity_status']!=='confirmed'){
                echo '<details><summary>Name suggestions · review required</summary>';
                foreach(Registry::candidates($row['client_name']) as $c){echo '<p>#'.(int)$c['id'].' '.esc_html(implode(' · ',array_filter([$c['display_name'],$c['date_of_birth'],$c['nationality'],$c['source_url']]))).'</p>';}
                echo '</details>';
            }
            echo '</td><td>Identity: '.esc_html($row['identity_status']).'<br>Last success: '.esc_html($row['source_fetched_at']?:'never').'<br>Last attempt: '.esc_html(($row['last_attempt_at']??'').' '.($row['last_attempt_status']??'not fetched')).'<br>Period: '.esc_html($row['period_status']).'<br>Draft #'.(int)$row['ranking_run_id'].': '.esc_html($row['ranking_status']).'<br>'.esc_html(implode('; ',$row['reasons'])).'<br><strong>Next: '.esc_html($row['next_action']).'</strong></td><td>';
            self::form('toggle');echo '<input type="hidden" name="target_id" value="'.(int)$row['id'].'"><input type="hidden" name="active" value="'.($row['is_active']?'0':'1').'">';submit_button($row['is_active']?'Disable priority':'Enable priority','secondary small');echo '</form></td></tr>';
        }
        echo '</tbody></table><p>'.wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%',self::url()),'format'=>'','current'=>$page,'total'=>max(1,(int)ceil($total/$limit))])).'</p></div>';
    }
}
