<?php
namespace MMAF\DataEngine\Admin;
use MMAF\DataEngine\Services\Operations\{Monitor,Presentation};
use MMAF\DataEngine\Support\Capabilities;

final class OperationsDashboard {
    public static function render(): void {
        if(!current_user_can(Capabilities::MANAGE)){wp_die('Access denied.','',['response'=>403]);}
        if(version_compare((string)get_option('mmaf_db_version'),'1.8.0','<')){
            echo '<div class="wrap"><h1>MMA Future Operations</h1><p>A checked schema migration is required. Run <code>wp mmaf system migrate</code> from the release runner.</p></div>';return;
        }
        $data=Monitor::snapshot();$deployment=$data['deployment'];$release=$data['release'];$diagnostics=$data['diagnostics'];$capacity=$data['capacity'];
        echo '<div class="wrap"><h1>MMA Future Operations</h1><p>Pregled obrade, zaštite podataka i staging izdanja. Aktiviranje rang-liste ostaje posebna odluka.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1200px;margin:20px 0">';
        self::card('Environment & release',[
            'Environment'=>$data['environment'],'Schema'=>$data['schema'],
            'Release'=>isset($release['sequence'])?'#'.$release['sequence'].' · '.substr($release['commit']??'',0,12):'Not recorded',
            'Deployment'=>Presentation::label($deployment['state']??'not_recorded'),
            'Checked'=>self::date($release['checked_at']??null),
        ],'Kod i šema moraju biti kompatibilni pre otvaranja sajta.');
        self::card('Ranking & statistics',[
            'Active ranking'=>$data['active_ranking']?'#'.$data['active_ranking']:'None',
            'Latest run'=>isset($data['latest_run']['id'])?'#'.$data['latest_run']['id'].' · '.Presentation::label($data['latest_run']['status']??'unknown'):'None',
            'Statistics'=>self::date($data['statistics']['rebuilt_at']??null),
            'Worker heartbeat'=>self::date($data['heartbeat']),
        ],'Novi draft ne menja javnu rang-listu.');
        self::card('Backup & recovery',[
            'Database backup'=>self::date($data['backup']['created_at']??null),
            'Verified SQL'=>isset($data['backup']['tables'])?(is_array($data['backup']['tables'])?count($data['backup']['tables']):$data['backup']['tables']).' tables':'Not recorded',
            'Full restore'=>self::date($data['restore']['checked_at']??null),
            'Restore result'=>!empty($data['restore']['ok'])?'Verified':'Not yet verified',
            'Retention'=>!empty($data['retention']['enabled'])?'Enabled': 'Paused until verification',
        ],'Postojanje backup fajla nije dokaz uspešne obnove.');
        self::card('Capacity',[
            'Database'=>self::bytes($diagnostics['database_bytes']??null),
            'Private files'=>self::bytes($diagnostics['private_bytes']??null),
            'Filesystem free'=>self::bytes($capacity['filesystem_free']??$diagnostics['disk_free']??null),
            'Account free'=>self::bytes($capacity['account_free']??null),
            'Account inodes free'=>isset($capacity['account_inode_free'])?number_format($capacity['account_inode_free']):'Unknown',
            'Measured'=>self::date($capacity['checked_at']??$diagnostics['checked_at']??null),
        ],'Neproveren kapacitet zaustavlja backup, restore i izdanje.');
        echo '</div><h2>Needs attention</h2>';
        foreach($data['alerts'] as $row){echo '<div class="notice notice-'.($row['severity']==='critical'?'error':'warning').' inline"><p>'.esc_html($row['message']).' <small>Last checked: '.esc_html(self::date($row['last_seen_at'])).'</small></p></div>';}
        if(!$data['alerts']){echo '<p>No open operational alerts.</p>';}
        echo '<h2>Current operations</h2><table class="widefat striped"><thead><tr><th>Operation</th><th>Status</th><th>Current phase</th><th>Scope</th><th>Heartbeat</th><th>Time / peak memory</th><th>Next action</th></tr></thead><tbody>';
        foreach($data['jobs'] as $job){
            $url=admin_url('admin.php?page=mmaf-operations&operation_id='.$job['id']);
            echo '<tr><td><a href="'.esc_url($url).'">#'.(int)$job['id'].' '.esc_html(Presentation::label($job['kind'])).'</a></td><td>'.esc_html(Presentation::label($job['status'])).'</td><td>'.esc_html(Presentation::label($job['phase'])).'</td><td>'.(int)$job['total'].'</td><td>'.esc_html(self::date($job['heartbeat_at'])).'</td><td>'.esc_html(round($job['duration_ms']/1000,1).' s / '.round($job['peak_memory']/1048576,1).' MiB').'</td><td><a href="'.esc_url($url).'">'.esc_html($job['error_message']?'Review technical failure':'Open progress and results').'</a></td></tr>';
        }
        if(!$data['jobs']){echo '<tr><td colspan="7">No active operations.</td></tr>';}
        echo '</tbody></table><p>Skupe provere rade u pozadini ili kroz <code>wp mmaf operations diagnostics</code>; datum merenja pokazuje svežinu.</p><p><a href="'.esc_url(admin_url('admin.php?page=mmaf-fighters&preparation=review')).'">Fighters needing review</a> · <a href="'.esc_url(admin_url('admin.php?page=mmaf-regular-import')).'">Imports</a> · <a href="'.esc_url(admin_url('admin.php?page=mmaf-system-check')).'">Detailed system check</a></p>';
        echo '<details><summary>Technical details</summary><pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details></div>';
    }
    private static function card(string $title,array $rows,string $help): void {
        echo '<section style="background:white;border:1px solid #c3c4c7;padding:16px"><h2 style="margin-top:0">'.esc_html($title).'</h2><dl>';
        foreach($rows as $label=>$value){echo '<dt style="margin-top:10px;color:#50575e">'.esc_html($label).'</dt><dd style="margin:2px 0;font-weight:600">'.esc_html((string)$value).'</dd>';}
        echo '</dl><p style="color:#50575e">'.esc_html($help).'</p></section>';
    }
    private static function bytes($value): string {return $value===null?'Unknown':size_format((float)$value,1);}
    private static function date($value): string {
        if(!$value){return 'Not recorded';}$timestamp=is_numeric($value)?(int)$value:strtotime($value.(preg_match('/(?:Z|[+-]\d\d:\d\d)$/',$value)?'':' UTC'));
        return $timestamp?wp_date('M j, Y H:i:s',$timestamp):'Not recorded';
    }
}
