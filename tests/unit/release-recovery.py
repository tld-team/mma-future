"""Fault injection against the actual file/lock/journal runner, without a live site."""
import contextlib, datetime, gzip, importlib.util, json, os, tempfile, unittest, zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('runner',root/'ops/runner.py');runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
from storage import BackupCatalog, MediaStore, capacity, check_sql

class SimulatedRunner(runner.Runner):
    fail=None
    events=None
    def wp(self,*args,**kwargs):
        if args[:3]==('option','get','mmaf_db_version'): return '1.8.0'
        if args[:1]==('eval-file',) and args[1].endswith('release-health.php'): return json.dumps({'ok':True,'active':18})
        return json.dumps({'active':18,'ranking_hash':'protected'})
    def require_space(self,*args): return {'ok':True}
    def _backup(self,*args,**kwargs):
        assert (self.root/'writes-paused.flag').exists()
        assert not (self.root/'maintenance.flag').exists()
        self.events.append('backup');folder=self.root/'backups'/'fixture';folder.mkdir(exist_ok=True);return folder
    def _migration_health(self,*args):
        self.events.append('migrate')
        if self.fail=='migration': raise RuntimeError('Injected migration failure')
        if self.fail=='interrupt': raise KeyboardInterrupt('Interrupted after code copy')
    def http_check(self,manifest):
        assert (self.root/'maintenance.flag').exists()
        assert (self.wp_root/'.mmaf-maintenance').exists()
        self.events.append('probe:'+str(manifest['sequence']))
        if self.fail=='rollback_interrupt' and manifest['sequence']==1:raise KeyboardInterrupt('Interrupted rollback probe')
        if self.fail=='probe' and manifest['sequence']==2: raise RuntimeError('Injected public probe failure')

class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.wp=self.root/'public';self.wp.mkdir()
        self.config=self.root/'config.json';self.config.write_text(json.dumps({'wp_root':str(self.wp),'private_root':str(self.root/'private'),'write_lock':str(self.root/'private/worker.lock'),'php':'php','wp_cli':'wp','url':'https://fixture.test'}))
        self.runner=SimulatedRunner(self.config);self.runner.events=[]
    def tearDown(self): self.temp.cleanup()
    def package(self,seq,extra=None):
        files={'wp-content/plugins/mma-future-data-engine/mma-future-data-engine.php':b'<?php // fixture',
               'wp-content/themes/mma-future/asset-manifest.json':b'{}','ops/runtime-gate.php':b'<?php // trusted gate',
               'ops/runner.py':b'# trusted fixture runtime','wp-content/themes/mma-future/assets/dist/css/output.css':str(seq).encode()}
        files.update(extra or {})
        m={'format':1,'sequence':seq,'commit':str(seq)*40,'schema_min':'1.7.0','schema_max':'1.9.0','schema_target':'1.8.0','files':{k:runner.hashlib.sha256(v).hexdigest() for k,v in files.items()}}
        path=self.runner.root/'incoming'/f'{seq}.zip'
        with zipfile.ZipFile(path,'w') as z:
            for name,body in files.items(): z.writestr(zipfile.ZipInfo(name,(2020,1,1,0,0,0)),body)
            z.writestr(zipfile.ZipInfo('release.json',(2020,1,1,0,0,0)),json.dumps(m))
        ready=path.with_suffix('.ready');ready.write_text(json.dumps({'sequence':seq,'commit':m['commit'],'package':path.name,'sha256':runner.digest(path)}));return ready
    def test_backup_before_maintenance_probe_before_open(self):
        self.runner.release() # no packages is a no-op
        self.package(1);self.runner.release()
        self.assertEqual(self.runner.events,['backup','migrate','probe:1'])
        self.assertFalse((self.runner.root/'maintenance.flag').exists())
        self.assertFalse((self.runner.root/'writes-paused.flag').exists())
        self.assertEqual((self.runner.root/'current').resolve(),self.runner.root/'runtimes/1')
        self.assertEqual(json.loads((self.wp/'.well-known/mmaf-release.json').read_text())['state'],'verified')
    def test_probe_failure_rolls_back_added_and_removed_files(self):
        old='wp-content/themes/mma-future/old.php';new='wp-content/themes/mma-future/new.php'
        self.package(1,{old:b'old'});self.runner.release();self.package(2,{new:b'new'});self.runner.fail='probe'
        with self.assertRaises(RuntimeError): self.runner.release()
        self.assertEqual((self.wp/old).read_bytes(),b'old');self.assertFalse((self.wp/new).exists())
        self.assertFalse((self.runner.root/'maintenance.flag').exists())
        self.assertEqual(json.loads((self.runner.root/'watermark.json').read_text())['sequence'],2)
        self.assertEqual(json.loads((self.runner.root/'release.json').read_text())['sequence'],1)
        self.assertFalse(list((self.runner.root/'incoming').glob('*.ready')))
        self.assertEqual(len(list((self.runner.root/'quarantine').glob('*.failed'))),1)
    def test_incompatible_fallback_keeps_site_closed(self):
        self.package(1);self.runner.release()
        old=json.loads((self.runner.root/'release.json').read_text());old['schema_max']='1.7.0';runner.atomic(self.runner.root/'release.json',old)
        self.package(2);self.runner.fail='migration'
        with self.assertRaises(RuntimeError): self.runner.release()
        self.assertTrue((self.runner.root/'maintenance.flag').exists());self.assertTrue((self.runner.root/'writes-paused.flag').exists())
        self.assertEqual(json.loads((self.runner.root/'release-status.json').read_text())['state'],'failed')
    def test_interruption_resumes_same_backup_and_baseline(self):
        self.package(1);self.runner.fail='interrupt'
        with self.assertRaises(KeyboardInterrupt): self.runner.release()
        self.assertTrue((self.runner.root/'maintenance.flag').exists())
        baseline=(self.runner.root/'releases/1/baseline.json').read_bytes()
        self.runner.fail=None;self.runner.release()
        self.assertEqual(self.runner.events.count('backup'),1)
        self.assertEqual((self.runner.root/'releases/1/baseline.json').read_bytes(),baseline)
        self.assertFalse((self.runner.root/'maintenance.flag').exists())
    def test_repeat_same_package_and_stale_sequence(self):
        self.package(1);self.runner.release();self.package(1);self.runner.release()
        self.assertEqual(self.runner.events.count('backup'),1)
        self.package(2);self.runner.release();ready=self.package(1)
        with self.assertRaises(ValueError): self.runner.release()
        self.assertFalse(ready.exists());self.assertEqual(json.loads((self.runner.root/'release.json').read_text())['sequence'],2)
    def test_wrong_hash_never_closes_or_changes_site(self):
        ready=self.package(1);data=json.loads(ready.read_text());data['sha256']='0'*64;ready.write_text(json.dumps(data))
        with self.assertRaises(ValueError):self.runner.release()
        self.assertFalse((self.runner.root/'maintenance.flag').exists());self.assertFalse((self.wp/'wp-content').exists())
    def test_partial_upload_has_no_ready_marker(self):
        (self.runner.root/'incoming/partial.zip').write_bytes(b'partial');self.runner.release()
        self.assertEqual(self.runner.events,[])
    def test_explicit_compatible_rollback_preserves_watermark(self):
        old='wp-content/themes/mma-future/old.php';new='wp-content/themes/mma-future/new.php'
        self.package(1,{old:b'old'});self.runner.release();self.package(2,{new:b'new'});self.runner.release()
        result=self.runner.rollback_to(1)
        self.assertTrue(result['ok']);self.assertFalse(result['database_restored'])
        self.assertEqual((self.wp/old).read_bytes(),b'old');self.assertFalse((self.wp/new).exists())
        self.assertEqual(json.loads((self.runner.root/'watermark.json').read_text())['sequence'],2)
        self.assertTrue(self.runner.rollback_to(1)['noop'])
        self.assertEqual(json.loads((self.wp/'.well-known/mmaf-release.json').read_text())['package_sha256'],json.loads((self.runner.root/'releases/1/verified.json').read_text())['package_sha256'])
    def test_corrupt_saved_fallback_is_not_applied(self):
        self.package(1);self.runner.release();self.package(2);self.runner.release()
        (self.runner.root/'releases/1/ops/runtime-gate.php').write_text('changed')
        with self.assertRaises(ValueError):self.runner.rollback_to(1)
        self.assertFalse((self.runner.root/'maintenance.flag').exists())
        self.assertEqual(json.loads((self.runner.root/'release.json').read_text())['sequence'],2)
    def test_interrupted_manual_rollback_resumes_without_new_backup(self):
        self.package(1);self.runner.release();self.package(2);self.runner.release();self.runner.fail='rollback_interrupt'
        with self.assertRaises(KeyboardInterrupt):self.runner.rollback_to(1)
        backups=self.runner.events.count('backup');baseline=(self.runner.root/'rollback-baseline.json').read_bytes()
        self.assertTrue((self.runner.root/'maintenance.flag').exists())
        self.runner.fail=None;self.assertTrue(self.runner.rollback_to(1)['ok'])
        self.assertEqual(self.runner.events.count('backup'),backups)
        self.assertEqual((self.runner.root/'rollback-baseline.json').read_bytes(),baseline)
        self.assertFalse((self.runner.root/'maintenance.flag').exists())
    def test_older_business_code_uses_readonly_recovery(self):
        self.runner.recovery_mode({'schema_max':'1.9.0','write_schema_max':'1.7.0'},'1.9.0')
        self.assertTrue((self.runner.root/'recovery-readonly.flag').exists())
        self.runner.recovery_mode({'schema_max':'1.9.0'},'1.9.0')
        self.assertFalse((self.runner.root/'recovery-readonly.flag').exists())

class StorageTests(unittest.TestCase):
    def test_empty_and_damaged_backup(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'dump.gz'
            with gzip.open(p,'wb') as f:f.write(b'')
            with self.assertRaises(ValueError):check_sql(p)
            with gzip.open(p,'wb') as f:f.write(b'CREATE TABLE fixture (id int);\n'+b'-- fixture\n'*200)
            self.assertEqual(check_sql(p)['tables'],1)
            p.write_bytes(p.read_bytes()[:-5])
            with self.assertRaises((EOFError,OSError)):check_sql(p)
    def test_media_roundtrip_and_corruption(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d);uploads=p/'uploads';uploads.mkdir();(uploads/'hero.jpg').write_bytes(b'fixture-photo')
            store=MediaStore(p/'objects');manifest=store.capture(uploads);store.restore(manifest,p/'restored')
            self.assertEqual((p/'restored/hero.jpg').read_bytes(),b'fixture-photo')
            h=manifest['hero.jpg']['sha256'];(p/'objects'/h[:2]/h).write_bytes(b'damaged')
            with self.assertRaises(ValueError):store.restore(manifest,p/'second')
    def test_unknown_and_stale_quota_fail_closed(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertFalse(capacity(d)['ok'])
            q={'checked_at':runner.time.time(),'bytes_unlimited':True,'inodes_unlimited':True}
            self.assertTrue(capacity(d,q)['ok']);q['checked_at']-=3601;self.assertFalse(capacity(d,q)['ok'])
    def test_backup_retention_holds_and_restore_proof(self):
        with tempfile.TemporaryDirectory() as d:
            d=str(Path(d).resolve());c=BackupCatalog(d);now=datetime.datetime.now(datetime.timezone.utc)
            for n in range(60):
                c.register(Path(d)/f'{n}.gz',{'created_at':(now-datetime.timedelta(days=n)).isoformat(),'verified':True,'schema':'1.8.0','holds':['risk'] if n==59 else [],'restore_verified_at':None})
            self.assertFalse(any(r['eligible'] for r in c.retention()))
            file=next(p for p in Path(d).glob('*.json') if json.loads(p.read_text())['path']==str(Path(d)/'0.gz'))
            item=json.loads(file.read_text());item['restore_verified_at']=now.isoformat();runner.atomic(file,item)
            candidates=c.retention();self.assertTrue(any(r['eligible'] for r in candidates))
            self.assertFalse(next(r for r in candidates if r['path'].endswith('/59.gz'))['eligible'])

if __name__=='__main__':unittest.main()
