"""Actual private export/import on two disposable synthetic WordPress databases."""
import importlib.util,json,os,pathlib,shutil,subprocess,tempfile,time
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('local_sync',ROOT/'ops/local-sync.py');sync=importlib.util.module_from_spec(spec);spec.loader.exec_module(sync)
CORE=pathlib.Path(os.environ['MMAF_SYNC_WP_CORE']);PHP=os.environ['MMAF_TEST_PHP'];WP=os.environ['MMAF_TEST_WP_CLI'];MYSQL=os.environ['MMAF_TEST_MYSQL'];DUMP=os.environ['MMAF_TEST_MYSQLDUMP'];SOCKET=os.environ.get('MMAF_SYNC_SOCKET','/private/tmp/mmaf-import-test.sock')
DBUSER=os.environ.get('MMAF_SYNC_DB_USER','root');DBPASS=os.environ.get('MMAF_SYNC_DB_PASSWORD','');DBHOST=os.environ.get('MMAF_SYNC_DB_HOST')
MYSQL_ARGS=['--host='+DBHOST.split(':')[0],'--port='+DBHOST.split(':')[-1]] if DBHOST else ['--socket='+SOCKET]
base=pathlib.Path(tempfile.mkdtemp(prefix='mmaf-local-sync-proof-'));base.chmod(0o700);suffix=os.urandom(4).hex()
for kind in ['source','target']:
 database='mmaf_local_sync_'+kind+'_'+suffix
 subprocess.run([MYSQL,*MYSQL_ARGS,'-u'+DBUSER,'-e','CREATE DATABASE '+database+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],env=dict(os.environ,MYSQL_PWD=DBPASS),check=True)
 site=base/kind/'public';site.parent.mkdir(mode=0o700);shutil.copytree(CORE,site,ignore=shutil.ignore_patterns('wp-config.php','wp-content'))
 for directory,child in [('plugins','mma-future-data-engine'),('themes','mma-future')]:
  folder=site/'wp-content'/directory;folder.mkdir(parents=True);(folder/child).symlink_to(ROOT/'wp-content'/directory/child,target_is_directory=True)
 (site/'wp-content/mu-plugins').mkdir();shutil.copyfile(ROOT/'wp-content/mu-plugins/mmaf-environment.php',site/'wp-content/mu-plugins/mmaf-environment.php')
 private=base/kind/'private';private.mkdir(mode=0o700);environment='staging' if kind=='source' else 'local';url='https://source.example.test' if kind=='source' else 'http://target.example.test'
 config={'wp_root':str(site),'private_root':str(private),'write_lock':str(private/'worker.lock'),'environment':environment,'php':PHP,'wp_cli':WP,'mysql':MYSQL,'mysqldump':DUMP,'url':url}
 p=private/'config.json';p.write_text(json.dumps(config));p.chmod(0o600);p=private/'capacity.json';p.write_text(json.dumps({'checked_at':time.time(),'bytes_unlimited':True,'inodes_unlimited':True}));p.chmod(0o600)
 s='<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in {'DB_NAME':database,'DB_HOST':DBHOST or 'localhost:'+SOCKET,'DB_USER':DBUSER,'DB_PASSWORD':DBPASS,'DB_CHARSET':'utf8mb4','DB_COLLATE':'','WP_ENVIRONMENT_TYPE':environment,'WP_HOME':url,'WP_SITEURL':url,'MMAF_IMPORT_STORAGE_DIR':str(private/'imports')}.items())+'define("DISABLE_WP_CRON",true);\ndefine("MMAF_SERVER_SUPERVISOR",true);\n$table_prefix="wp_";\nif(!defined("ABSPATH")){define("ABSPATH",__DIR__."/");}\nrequire '+json.dumps(str(ROOT/'ops/runtime-gate.php'))+';\nrequire ABSPATH."wp-settings.php";\n'
 p=site/'wp-config.php';p.write_text(s);p.chmod(0o600)
 worker=sync.Runner(private/'config.json');worker.wp('core','install','--url='+url,'--title=Synthetic transfer','--admin_user=fixture','--admin_password=fixture-test-only','--admin_email=fixture@example.invalid','--skip-email');worker.wp('plugin','activate','mma-future-data-engine');worker.wp('theme','activate','mma-future');worker.wp('mmaf','system','migrate')
fixture=base/'fixture.php';fixture.write_text('''<?php
wp_set_current_user(1);global $wpdb;$t=MMAF\\DataEngine\\Migrations\\Schema::table_names();
$html='<p>It\'s “safe” & <strong>clear</strong>.</p>';
$post=wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'publish','post_title'=>'Transfer fixture','post_content'=>$html.' <a href="https://source.example.test/contact/">Contact</a>','guid'=>'https://source.example.test/original-guid']));
update_post_meta($post,'integration_html',wp_slash($html));update_option('integration_empty','',false);update_option('integration_fixture_post',$post,false);
$dir=wp_upload_dir()['basedir'];wp_mkdir_p($dir);file_put_contents($dir.'/hero.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII='));
$attachment=wp_insert_attachment(['post_title'=>'Hero fixture','post_status'=>'inherit','post_mime_type'=>'image/png'],$dir.'/hero.png');update_post_meta($attachment,'_wp_attached_file','hero.png');update_option('integration_hero',$attachment,false);
$wpdb->insert($t['fighters'],['display_name'=>'Transfer fixture','normalized_name'=>'transfer fixture','created_at'=>gmdate('Y-m-d H:i:s')]);$id=(int)$wpdb->insert_id;
$wpdb->insert($t['fighter_sources'],['fighter_id'=>$id,'source_type'=>'tapology','source_fighter_id'=>'tapology_fighter_999999991','source_url'=>'https://www.tapology.com/fightcenter/fighters/999999991-fixture','created_at'=>gmdate('Y-m-d H:i:s')]);
$job=MMAF\\DataEngine\\Services\\Operations\\Jobs::create('validate_selected_for_ranking',['mode'=>'ids','ids'=>[$id]],1,'transfer-fixture');update_option('integration_operation',$job['id'],false);update_option('mmaf_retention_enabled',true,false);
'''.replace("$html='<p>It's", "$html='<p>It\\'s"))
source=sync.Sync(base/'source/private/config.json');target=sync.Sync(base/'target/private/config.json');source.worker.wp('eval-file',str(fixture));package=base/'transfer.tar';export=source.export(package);preview=target.preview(package,export['sha256']);assert not (target.worker.root/'maintenance.flag').exists();print('PASS: export hashes and dry-run leave the local database untouched',flush=True)
result=target.apply(package,export['sha256'],preview['preview_sha256']);assert result['ok'] and result['media_files']==1
check=base/'check.php';check.write_text('''<?php
$post=get_post((int)get_option('integration_fixture_post'));$job=MMAF\\DataEngine\\Services\\Operations\\Jobs::get((int)get_option('integration_operation'));$t=MMAF\\DataEngine\\Migrations\\Schema::table_names();global $wpdb;
$checks=['html'=>str_contains($post->post_content,'It\'s “safe” & <strong>clear</strong>.'),'local_url'=>str_contains($post->post_content,'http://target.example.test/contact/'),'guid'=>$post->guid==='https://source.example.test/original-guid','empty'=>get_option('integration_empty')==='','media'=>get_post_meta((int)get_option('integration_hero'),'_wp_attached_file',true)==='hero.png','source_url'=>$wpdb->get_var("SELECT source_url FROM {$t['fighter_sources']} LIMIT 1")==='https://www.tapology.com/fightcenter/fighters/999999991-fixture','paused'=>$job['status']==='paused' && !empty($job['checkpoint']['local_copy_paused']),'retention'=>!get_option('mmaf_retention_enabled'),'mail'=>wp_mail('fixture@example.invalid','TEST','Not transmitted')===false];
echo wp_json_encode($checks);
'''.replace("'It's", "'It\\'s"))
checks=json.loads(target.worker.wp('eval-file',str(check)));assert all(checks.values()),checks;print('PASS: HTML, special characters, empty fields, GUID, media, source identity and paused jobs survive transfer',flush=True)
assert target.apply(package,export['sha256'],preview['preview_sha256'])['noop'];print('PASS: repeated confirmed transfer is a no-op',flush=True)
print(json.dumps({'ok':True,'checks':checks,'evidence':str(base),'active':result['active'],'ranking_hash':result['ranking_hash']}))
