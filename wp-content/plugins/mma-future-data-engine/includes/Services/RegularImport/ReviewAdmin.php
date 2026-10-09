<?php
namespace MMAF\DataEngine\Services\RegularImport;
use MMAF\DataEngine\Migrations\Schema as CanonicalSchema;
final class ReviewAdmin {
    public static function render(int $job): void {
        global $wpdb;$repo=new Repository();$t=CanonicalSchema::table_names()['review_items'];
        if(!current_user_can('manage_options')){return;}
        if(isset($_POST['regular_review_id'])){
            check_admin_referer('mmaf_regular_review');WriteGuard::assertAllowed();
            $id=absint($_POST['regular_review_id']);$status=sanitize_key($_POST['review_status']??'');
            $row=$wpdb->get_row($wpdb->prepare("SELECT r.* FROM $t r JOIN {$repo->tables['items']} i ON i.review_item_id=r.id WHERE i.job_id=%d AND r.id=%d LIMIT 1",$job,$id),ARRAY_A);
            if($row&&in_array($status,['resolved','dismissed','needs_research','open'],true)){
                $notes=json_decode($row['notes']??'',true);if(!is_array($notes)){$notes=['previous_notes'=>$row['notes']];}
                $notes['decisions'][]=['at'=>gmdate('c'),'by'=>get_current_user_id(),'status'=>$status,'note'=>sanitize_textarea_field(wp_unslash($_POST['review_note']??''))];
                Repository::check($wpdb->update($t,['status'=>$status,'notes'=>wp_json_encode($notes),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));
                echo '<div class="notice notice-success"><p>'.esc_html__('Review decision saved.','mma-future-data-engine').'</p></div>';
            }
        }
        $page=max(1,absint($_GET['review_page']??1));$offset=($page-1)*25;
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT review_item_id) FROM {$repo->tables['items']} WHERE job_id=%d AND review_item_id IS NOT NULL",$job));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT r.* FROM $t r JOIN {$repo->tables['items']} i ON i.review_item_id=r.id WHERE i.job_id=%d ORDER BY r.id LIMIT 25 OFFSET %d",$job,$offset),ARRAY_A);
        echo '<h2>'.esc_html(sprintf(__('Import #%d — review items','mma-future-data-engine'),$job)).'</h2><p><a href="'.esc_url(admin_url('admin.php?page=mmaf-regular-import#history')).'">'.esc_html__('Back to import history','mma-future-data-engine').'</a></p>';
        echo '<p>'.esc_html(sprintf(__('%d items. Decisions and notes are kept when the same package is imported again.','mma-future-data-engine'),$total)).'</p><table class="widefat striped"><thead><tr><th>Source / issue</th><th>Status</th><th>Details and decision</th></tr></thead><tbody>';
        foreach($rows as $row){
            $notes=json_decode($row['notes']??'',true);$notes=is_array($notes)?$notes:['notes'=>$row['notes']];
            echo '<tr><td><strong>'.esc_html($row['source_id']?:'Package').'</strong><br>'.esc_html(str_replace('_',' ',$row['action_taken'])).'</td><td>'.esc_html(str_replace('_',' ',$row['status'])).'</td><td><details><summary>'.esc_html__('View context and previous notes','mma-future-data-engine').'</summary><pre style="white-space:pre-wrap;max-width:700px">'.esc_html(wp_json_encode($notes,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details><form method="post">';
            wp_nonce_field('mmaf_regular_review');echo '<input type="hidden" name="regular_review_id" value="'.(int)$row['id'].'"><p><label>'.esc_html__('Decision','mma-future-data-engine').' <select name="review_status">';
            foreach(['open'=>'Open','needs_research'=>'Needs research','resolved'=>'Resolved','dismissed'=>'Dismissed'] as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected($row['status'],$key,false).'>'.esc_html($label).'</option>';}
            echo '</select></label> <label>'.esc_html__('Note','mma-future-data-engine').' <input name="review_note" maxlength="2000"></label> ';submit_button(__('Save decision','mma-future-data-engine'),'secondary small','submit',false);echo '</p></form></td></tr>';
        }
        if(!$rows){echo '<tr><td colspan="3">'.esc_html__('No review items for this import.','mma-future-data-engine').'</td></tr>';}
        echo '</tbody></table><p>';echo wp_kses_post(paginate_links(['base'=>add_query_arg('review_page','%#%'),'total'=>(int)ceil($total/25),'current'=>$page]));echo '</p>';
    }
}
