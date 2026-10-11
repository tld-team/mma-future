#!/usr/bin/env python3
"""Private runner; locks, journals and backups survive code/runtime replacement."""
import argparse, contextlib, datetime, fcntl, gzip, hashlib, json, os, re, secrets, shutil, stat, subprocess, sys, tempfile, time, zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
from storage import atomic, digest, capacity, check_sql, MediaStore, BackupCatalog
PREFIXES=('wp-content/themes/mma-future/','wp-content/plugins/mma-future-data-engine/','wp-content/mu-plugins/','ops/')
class Busy(Exception): pass

def version(value):
    if not isinstance(value,str) or not re.fullmatch(r'\d+\.\d+\.\d+',value): raise ValueError('Invalid schema version')
    return tuple(int(v) for v in value.split('.'))

def verify(path,expected,stage):
    if not re.fullmatch(r'[a-f0-9]{64}',expected) or digest(path)!=expected: raise ValueError('Package hash mismatch')
    with zipfile.ZipFile(path) as z:
        names=z.namelist()
        if len(names)!=len(set(names)): raise ValueError('Duplicate package paths')
        if sum(i.file_size for i in z.infolist())>512*1024*1024: raise ValueError('Expanded package is too large')
        if z.getinfo('release.json').file_size>2*1024*1024 or len(names)>20000: raise ValueError('Release manifest is too large')
        manifest=json.loads(z.read('release.json'))
        if manifest.get('format')!=1 or set(names)!=(set(manifest['files'])|{'release.json'}): raise ValueError('Invalid release manifest')
        if type(manifest.get('sequence')) is not int or manifest['sequence']<1 or not re.fullmatch(r'[a-f0-9]{40}',manifest.get('commit','')): raise ValueError('Invalid release identity')
        for info in z.infolist():
            name=info.filename;p=Path(name);mode=info.external_attr>>16
            if p.is_absolute() or '..' in p.parts or '\\' in name or stat.S_ISLNK(mode) or info.is_dir() or set(p.parts)&{'node_modules','private','tests','__pycache__','.git'} or p.suffix.lower() in {'.sql','.gz','.zip','.log','.env','.pyc'} or p.name in {'wp-config.php','config.json'} or (name.startswith('wp-content/mu-plugins/') and name!='wp-content/mu-plugins/mmaf-environment.php') or not (name=='release.json' or name.startswith(PREFIXES)): raise ValueError('Unsafe release path')
            if name!='release.json':
                checksum=hashlib.sha256()
                with z.open(info) as source:
                    for chunk in iter(lambda:source.read(1024*1024),b''):checksum.update(chunk)
                if checksum.hexdigest()!=manifest['files'][name]: raise ValueError('File checksum mismatch')
        for required in ['wp-content/plugins/mma-future-data-engine/mma-future-data-engine.php','wp-content/themes/mma-future/asset-manifest.json','ops/runtime-gate.php']:
            if required not in manifest['files']: raise ValueError('Incomplete release')
        stage=Path(stage)
        if stage.is_symlink(): raise ValueError('Unsafe extraction directory')
        stage.mkdir(parents=True,exist_ok=True)
        if any(stage.iterdir()): raise ValueError('Extract into an empty directory')
        z.extractall(stage)
    return manifest

class Runner:
    def __init__(self,config):
        self.c=json.loads(Path(config).read_text());self.root=Path(self.c['private_root']).resolve();self.wp_root=Path(self.c['wp_root']).resolve()
        if self.root==self.wp_root or self.wp_root in self.root.parents: raise ValueError('Runtime must be private')
        self.root.mkdir(parents=True,exist_ok=True);self.root.chmod(0o700)
        for name in ['incoming','releases','quarantine','runtimes','backups','catalog']:
            folder=self.root/name;folder.mkdir(exist_ok=True);folder.chmod(0o700)
        self.env=dict(os.environ,MMAF_OPERATIONS_DIR=str(self.root),MMAF_WRITE_LOCK=str(Path(self.c['write_lock']).resolve()))
        self.passed_fds=();self.catalog=BackupCatalog(self.root/'catalog');self.media=MediaStore(self.root/'media-objects')
    def wp(self,*args,timeout=120,extra_env=None):
        command=[self.c['php'],'-d','memory_limit=512M',self.c['wp_cli'],'--path='+str(self.wp_root),*args]
        r=subprocess.run(command,env=dict(self.env,**(extra_env or {})),pass_fds=self.passed_fds,capture_output=True,text=True,timeout=timeout)
        if r.returncode==75: raise Busy('WordPress is at a protected checkpoint')
        if r.returncode: raise RuntimeError('WP command failed: '+args[0])
        return r.stdout.strip()
    def helper(self,name):
        path=Path(__file__).resolve().parent/name
        if not path.is_file(): raise ValueError('Missing trusted helper: '+name)
        return str(path)
    @contextlib.contextmanager
    def lock(self,path,wait=0):
        path=Path(path);path.parent.mkdir(parents=True,exist_ok=True);handle=path.open('a');os.chmod(path,0o600);deadline=time.monotonic()+wait
        try:
            while True:
                try: fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB);break
                except BlockingIOError:
                    if time.monotonic()>=deadline: raise Busy(path.name)
                    time.sleep(.1)
            yield handle
        finally: handle.close()
    def status(self,state,data=None):
        data=data or {};result={'environment':self.c.get('environment','staging'),'state':state,'commit':data.get('commit'),'sequence':data.get('sequence'),'package_sha256':data.get('sha256'),'checked_at':time.time()}
        atomic(self.root/'release-status.json',result);atomic(self.wp_root/'.well-known/mmaf-release.json',result,mode=0o644);return result
    def journal(self,data):
        data['updated_at']=time.time();atomic(self.root/'apply-state.json',data)
    def require_space(self,additional,inodes=0):
        path=self.root/'capacity.json';quota=json.loads(path.read_text()) if path.exists() else {}
        result=capacity(self.root,quota,additional,inodes);atomic(self.root/'capacity-status.json',result)
        if not result['ok']: raise RuntimeError(result['reason'] or 'Insufficient verified account space or inodes')
        return result
    @contextlib.contextmanager
    def write_barrier(self,keep_on_failure=False):
        flag=self.root/'writes-paused.flag';flag.write_text(str(time.time()));flag.chmod(0o600)
        try:
            # Requests may already own the canonical lock: drain writer requests first.
            with self.lock(self.root/'writers.lock',wait=30),self.lock(Path(self.c['write_lock']),wait=30) as worker:
                fd=worker.fileno();os.set_inheritable(fd,True);self.passed_fds=(fd,);self.env['MMAF_DEPLOY_WRITE_FD']=str(fd)
                yield
        except Exception:
            if not keep_on_failure or not (self.root/'maintenance.flag').exists(): flag.unlink(missing_ok=True)
            raise
        else: flag.unlink(missing_ok=True)
        finally: self.passed_fds=();self.env.pop('MMAF_DEPLOY_WRITE_FD',None)
    def _backup(self,reason='daily',hold=None):
        if not re.fullmatch(r'[a-z_]+',reason): raise ValueError('Invalid backup reason')
        info=json.loads(self.wp('eval-file',self.helper('database-info.php')));uploads=Path(info['uploads']);media_bytes=0;media_count=0
        if uploads.exists():
            for p in uploads.rglob('*'):
                if p.is_file() and not p.is_symlink(): media_bytes+=p.stat().st_size;media_count+=1
        self.require_space(2*int(info['bytes'])+media_bytes+128*1024**2,media_count+100)
        stamp=datetime.datetime.now(datetime.timezone.utc);folder=self.root/'backups'/(stamp.strftime('%Y%m%dT%H%M%S.%fZ')+'-'+reason);folder.mkdir(mode=0o700)
        credential=folder/'client.cnf';temporary=folder/'database.sql.gz.pending'
        try:
            database=self.wp('eval-file',self.helper('database-client.php'),extra_env={'MMAF_DB_CLIENT_FILE':str(credential)})
            if database!=info['database']: raise ValueError('Backup database changed')
            with temporary.open('wb') as output,gzip.GzipFile(fileobj=output,mode='wb') as compressed,tempfile.TemporaryFile(dir=folder) as errors:
                process=subprocess.Popen([self.c.get('mysqldump','mariadb-dump'),'--defaults-extra-file='+str(credential),'--single-transaction','--skip-lock-tables','--no-tablespaces',database],stdout=subprocess.PIPE,stderr=errors)
                try:
                    shutil.copyfileobj(process.stdout,compressed,length=1024*1024)
                    if process.wait(): raise RuntimeError('Native database export failed')
                finally:
                    process.stdout.close()
                    if process.poll() is None: process.kill();process.wait()
            temporary.chmod(0o600);checked=check_sql(temporary)
            if checked['tables']!=len(info['tables']): raise ValueError('Backup table count mismatch')
            path=folder/'database.sql.gz';temporary.replace(path)
            current=json.loads((self.root/'release.json').read_text()) if (self.root/'release.json').exists() else {}
            manifest=dict(checked,created_at=stamp.isoformat(),reason=reason,sql_format='native-v1',verified=True,environment=info['environment'],schema=info['schema'],source_database=info['database'],prefix=info['prefix'],table_rows=info['table_rows'],commit=current.get('commit'),baseline=json.loads(self.wp('eval-file',self.helper('baseline.php'))),media=self.media.capture(uploads),holds=[hold] if hold else [],restore_verified_at=None)
            manifest=self.catalog.register(path,manifest);atomic(folder/'manifest.json',manifest)
            self.wp('option','update','mmaf_backup_status',json.dumps({k:v for k,v in manifest.items() if k!='media'}),'--format=json')
            return folder
        finally: credential.unlink(missing_ok=True)
    def backup(self,reason='daily'):
        with self.lock(self.root/'deployment.lock'),self.write_barrier(): return self._backup(reason)
    def maintenance(self,enabled):
        private=self.root/'maintenance.flag';public=self.wp_root/'.mmaf-maintenance'
        if enabled: private.write_text(str(time.time()));private.chmod(0o600);public.touch(mode=0o644)
        else: private.unlink(missing_ok=True);public.unlink(missing_ok=True)
    def copy(self,stage,manifest,replaced=None):
        for name in manifest['files']:
            if name.startswith('ops/'): continue
            target=self.wp_root/name
            if target.resolve()!=target or target.is_symlink(): raise ValueError('Managed destination contains a symlink')
            target.parent.mkdir(parents=True,exist_ok=True);temporary=target.with_name(target.name+'.release-tmp');shutil.copyfile(stage/name,temporary);temporary.chmod(0o644);temporary.replace(target)
        for name in set((replaced or {}).get('files',{}))-set(manifest['files']):
            if name.startswith(PREFIXES) and not name.startswith('ops/'):
                target=self.wp_root/name
                if target.resolve()!=target or target.is_symlink(): raise ValueError('Unsafe removed path')
                target.unlink(missing_ok=True)
    def activate_runtime(self,stage,manifest):
        runtime=self.root/'runtimes'/str(manifest['sequence'])
        if not runtime.exists():
            temporary=runtime.with_name(runtime.name+'.pending')
            if temporary.exists(): shutil.rmtree(temporary)
            shutil.copytree(stage/'ops',temporary);temporary.chmod(0o700);temporary.replace(runtime)
        for name,checksum in manifest['files'].items():
            if name.startswith('ops/') and digest(runtime/name[4:])!=checksum: raise ValueError('Private runtime checksum mismatch')
        pointer=self.root/'current.next';pointer.unlink(missing_ok=True);pointer.symlink_to(runtime);pointer.replace(self.root/'current')
    def http_check(self,manifest):
        host=self.c.get('probe_host');address=self.c.get('probe_address','127.0.0.1');base=self.c['url'].rstrip('/')
        if not host or not re.fullmatch(r'[a-zA-Z0-9.-]+',host) or address not in {'127.0.0.1','::1'}: raise ValueError('Explicit loopback probe host required')
        token=self.root/'probe.token';token.write_text(secrets.token_hex(32));token.chmod(0o600)
        port=443 if base.startswith('https://') else 80
        # Header secret goes through a private curl config, never argv/logs.
        config=self.root/'probe.curl';config.write_text('header = "X-MMAF-Release-Probe: '+token.read_text()+'"\n');config.chmod(0o600)
        common=['curl','--config',str(config),'--fail','--silent','--show-error','--max-time','20','--resolve',host+':'+str(port)+':'+address]
        try:
            for route in ['/','/all-rankings/','/wp-json/mma-future/v1/health']:
                subprocess.run(common+[base+route],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
            name='wp-content/themes/mma-future/assets/dist/css/output.css'
            response=subprocess.check_output(common+[base+'/'+name],stderr=subprocess.PIPE)
            if hashlib.sha256(response).hexdigest()!=manifest['files'][name]: raise ValueError('Public asset checksum mismatch')
        finally: token.unlink(missing_ok=True);config.unlink(missing_ok=True)
    def _migration_health(self,stage,manifest,baseline):
        env={'MMAF_RELEASE_MANIFEST':str(stage/'release.json'),'MMAF_RELEASE_BASELINE':str(baseline)}
        if version(self.wp('option','get','mmaf_db_version'))<=version(manifest['schema_target']):
            self.wp('mmaf','system','migrate',timeout=300,extra_env=env)
        result=json.loads(self.wp('eval-file',str(stage/'ops/release-health.php'),extra_env=env))
        if not result.get('ok'): raise ValueError('Private release health failed')
        for name,checksum in manifest['files'].items():
            if digest(stage/name if name.startswith('ops/') else self.wp_root/name)!=checksum: raise ValueError('Applied checksum mismatch')
    def rollback(self,current,attempted):
        schema=self.wp('option','get','mmaf_db_version','--skip-plugins','--skip-themes')
        if not current or not version(current['schema_min'])<=version(schema)<=version(current['schema_max']): return False
        prior=self.root/'releases'/str(current['sequence'])
        if not (prior/'verified.json').exists(): return False
        baseline=self.root/'releases'/str(attempted['sequence'])/'baseline.json'
        self.restore_release(prior,current,attempted,baseline,schema)
        return True
    def restore_release(self,prior,manifest,replaced,baseline,schema):
        for name,checksum in manifest['files'].items():
            if digest(prior/name)!=checksum:raise ValueError('Fallback release checksum differs')
        self.copy(prior,manifest,replaced=replaced);self.activate_runtime(prior,manifest);self.recovery_mode(manifest,schema)
        result=json.loads(self.wp('eval-file',str(prior/'ops/release-health.php'),extra_env={'MMAF_RELEASE_MANIFEST':str(prior/'release.json'),'MMAF_RELEASE_BASELINE':str(baseline)}))
        if not result.get('ok'):raise ValueError('Fallback integrity check failed')
        self.http_check(manifest);atomic(self.root/'release.json',manifest)
    def rollback_to(self,sequence):
        if type(sequence) is not int or sequence<1:raise ValueError('A verified target sequence is required')
        with self.lock(self.root/'deployment.lock'):
            current=json.loads((self.root/'release.json').read_text())
            prior=self.root/'releases'/str(sequence)
            if not (prior/'verified.json').is_file():raise ValueError('Target release has never passed verification')
            manifest=json.loads((prior/'release.json').read_text())
            proof=json.loads((prior/'verified.json').read_text())
            if proof.get('commit')!=manifest['commit']:raise ValueError('Target verification identity differs')
            manifest['package_sha256']=proof.get('package_sha256')
            journal=self.root/'rollback-state.json';checkpoint=json.loads(journal.read_text()) if journal.exists() else {}
            resume=(self.root/'maintenance.flag').exists() and checkpoint.get('sequence')==sequence and checkpoint.get('state') in {'applying','failed','verified'}
            if current.get('sequence')==sequence and not resume:return {'ok':True,'noop':True,'sequence':sequence}
            schema=self.wp('option','get','mmaf_db_version','--skip-plugins','--skip-themes')
            if not version(manifest['schema_min'])<=version(schema)<=version(manifest['schema_max']):raise ValueError('Target release is incompatible with the installed schema')
            # Validate all saved code before taking the site offline.
            for name,checksum in manifest['files'].items():
                if digest(prior/name)!=checksum:raise ValueError('Saved rollback code differs')
            with self.write_barrier(keep_on_failure=True):
                baseline=self.root/'rollback-baseline.json'
                if resume:
                    if not baseline.is_file() or not Path(checkpoint['backup']).is_dir():raise ValueError('Interrupted rollback evidence is missing')
                else:
                    atomic(baseline,json.loads(self.wp('eval-file',self.helper('baseline.php'))))
                    backup=self._backup('rollback',hold='rollback:'+str(sequence))
                    checkpoint={'sequence':sequence,'previous_sequence':current['sequence'],'previous':current,'backup':str(backup)}
                self.maintenance(True);checkpoint.update(state='applying',updated_at=time.time());atomic(journal,checkpoint)
                with self.lock(self.root/'requests.lock',wait=30):
                    try:
                        self.restore_release(prior,manifest,checkpoint['previous'],baseline,schema)
                        atomic(self.root/'release.json',manifest)
                        checkpoint.update(state='verified',updated_at=time.time());atomic(self.root/'rollback-state.json',checkpoint)
                        self.status('verified',dict(manifest,sha256=manifest.get('package_sha256')));self.maintenance(False)
                        return {'ok':True,'sequence':sequence,'database_restored':False}
                    except BaseException:
                        checkpoint.update(state='failed',updated_at=time.time());atomic(self.root/'rollback-state.json',checkpoint)
                        self.status('failed',manifest);raise
    def recovery_mode(self,manifest,schema):
        flag=self.root/'recovery-readonly.flag';business=manifest.get('write_schema_max',manifest['schema_max'])
        if version(schema)>version(business):flag.touch(mode=0o600)
        else:flag.unlink(missing_ok=True)
    def apply(self,ready):
        ready=Path(ready);data=json.loads(ready.read_text())
        if not isinstance(data.get('sequence'),int) or data['sequence']<=0 or not re.fullmatch(r'[a-f0-9]{40}',data.get('commit','')): raise ValueError('Invalid incoming identity')
        package=ready.parent/data['package']
        if package.parent.resolve()!=ready.parent.resolve() or package.is_symlink(): raise ValueError('Invalid package name')
        current_path=self.root/'release.json';current=json.loads(current_path.read_text()) if current_path.exists() else {}
        journal_path=self.root/'apply-state.json';journal=json.loads(journal_path.read_text()) if journal_path.exists() else {}
        resume=journal.get('sequence')==data['sequence'] and journal.get('sha256')==data['sha256'] and journal.get('state') not in {'verified','failed','rolled_back'}
        watermark=json.loads((self.root/'watermark.json').read_text()).get('sequence',0) if (self.root/'watermark.json').exists() else 0
        if data['sequence']<=watermark and not resume:
            if current.get('sequence')==data['sequence'] and current.get('commit')==data['commit'] and current.get('package_sha256')==data['sha256']:
                if (self.root/'maintenance.flag').exists():
                    with self.write_barrier(keep_on_failure=True),self.lock(self.root/'requests.lock',wait=30):
                        if not (self.root/'releases'/str(data['sequence'])/'verified.json').exists(): raise ValueError('Verified release evidence missing')
                        self.http_check(current);self.maintenance(False)
                self.status('verified',data);ready.replace(ready.with_suffix('.applied'));return
            raise ValueError('Stale or previously failed release')
        stage=self.root/'releases'/str(data['sequence'])
        if stage.exists():
            manifest=json.loads((stage/'release.json').read_text())
            if digest(package)!=data['sha256']: raise ValueError('Staged package changed')
            for name,checksum in manifest['files'].items():
                if digest(stage/name)!=checksum: raise ValueError('Staged release changed')
            if resume: current=journal.get('previous',{})
        else:
            with zipfile.ZipFile(package) as candidate:
                self.require_space(sum(i.file_size for i in candidate.infolist())*2,len(candidate.infolist())*2+100)
            temporary=stage.with_name(stage.name+'.pending')
            if temporary.exists(): shutil.rmtree(temporary)
            manifest=verify(package,data['sha256'],temporary);temporary.replace(stage)
        if manifest.get('commit')!=data['commit'] or manifest.get('sequence')!=data['sequence']: raise ValueError('Incoming identity mismatch')
        self.status('waiting_for_checkpoint',data)
        if not resume: self.require_space(sum((stage/n).stat().st_size for n in manifest['files'])*2,len(manifest['files'])*2+100)
        with self.write_barrier(keep_on_failure=True):
            schema=self.wp('option','get','mmaf_db_version')
            if not version(manifest['schema_min'])<=version(schema)<=version(manifest['schema_max']): raise ValueError('Incompatible database schema')
            baseline=stage/'baseline.json'
            if not resume:
                baseline.write_text(self.wp('eval-file',self.helper('baseline.php')));baseline.chmod(0o600)
                backup=self._backup('release',hold='release:'+str(data['sequence']))
                journal=dict(data,state='backed_up',previous=current,backup=str(backup));self.journal(journal);atomic(self.root/'watermark.json',{'sequence':data['sequence']})
            self.maintenance(True)
            with self.lock(self.root/'requests.lock',wait=30):
                try:
                    journal['state']='applying';self.journal(journal);self.status('applying',data)
                    self.copy(stage,manifest,replaced=current);self._migration_health(stage,manifest,baseline);self.activate_runtime(stage,manifest);self.recovery_mode(manifest,self.wp('option','get','mmaf_db_version'));self.http_check(manifest)
                    manifest['package_sha256']=data['sha256'];atomic(stage/'verified.json',{'commit':manifest['commit'],'package_sha256':data['sha256'],'checked_at':time.time(),'backup':journal['backup']});atomic(current_path,manifest)
                    journal['state']='verified';self.journal(journal);self.status('verified',data);ready.replace(ready.with_suffix('.applied'));self.maintenance(False)
                except Exception as error:
                    recovered=False
                    try: recovered=self.rollback(current,manifest)
                    except Exception: pass
                    journal['state']='rolled_back' if recovered else 'failed';self.journal(journal)
                    atomic(stage/'failed.json',{'reason':str(error),'at':time.time(),'database_automatically_restored':False});self.status(journal['state'],data)
                    ready.replace(self.root/'quarantine'/(ready.stem+'.failed'))
                    if recovered: self.maintenance(False);(self.root/'writes-paused.flag').unlink(missing_ok=True)
                    raise
    def release(self):
        with self.lock(self.root/'deployment.lock'):
            ready=[]
            for path in (self.root/'incoming').glob('*.ready'):
                try: ready.append((int(json.loads(path.read_text())['sequence']),path))
                except Exception: path.replace(self.root/'quarantine'/(path.stem+'.invalid'))
            if not ready: return
            path=self.root/'apply-state.json';journal=json.loads(path.read_text()) if path.exists() else {}
            pending=next((p for seq,p in ready if seq==journal.get('sequence')),None)
            selected=pending if pending and journal.get('state') not in {'verified','failed','rolled_back'} else max(ready)[1]
            try: self.apply(selected)
            except Busy: raise
            except Exception:
                status=json.loads((self.root/'release-status.json').read_text()) if (self.root/'release-status.json').exists() else {}
                if status.get('state') not in {'failed','rolled_back'}:
                    failed=json.loads(selected.read_text()) if selected.exists() else {};self.status('failed',failed)
                if selected.exists(): selected.replace(self.root/'quarantine'/(selected.stem+'.failed'))
                raise

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('command',choices=['release','backup','status','verify','rollback']);p.add_argument('--config',required=True);p.add_argument('--package');p.add_argument('--sha256');p.add_argument('--stage');p.add_argument('--sequence',type=int);args=p.parse_args()
    try:
        runner=Runner(args.config)
        if args.command=='release': runner.release()
        elif args.command=='backup': print(runner.backup())
        elif args.command=='rollback':print(json.dumps(runner.rollback_to(args.sequence)))
        elif args.command=='status':
            path=runner.root/'release-status.json';print(path.read_text() if path.exists() else json.dumps({'state':'not_installed'}))
        else: print(json.dumps(verify(Path(args.package),args.sha256,Path(args.stage))))
    except Busy: sys.exit(75)
