#!/usr/bin/env python3
"""Private staging snapshot transfer; a reviewed preview and local backup precede replacement."""
import argparse,contextlib,gzip,hashlib,json,os,re,shutil,subprocess,sys,tarfile,tempfile,time
from pathlib import Path
from urllib.parse import urlparse
sys.path.insert(0,str(Path(__file__).resolve().parent))
from runner import Runner,version
from storage import atomic,check_sql,digest

class Sync:
 def __init__(self,config):self.worker=Runner(config)
 def export(self,output):
  w=self.worker
  if w.c.get('environment')!='staging' or w.wp('eval','echo wp_get_environment_type();')!='staging':raise ValueError('A correctly configured staging environment is required')
  output=Path(output).resolve()
  if w.wp_root==output or w.wp_root in output.parents:raise ValueError('Transfer packages must remain private')
  with w.lock(w.root/'deployment.lock'),w.write_barrier():
   backup=w._backup('local_sync',hold='local-sync:'+str(int(time.time())))
   m=json.loads((backup/'manifest.json').read_text());objects={item['sha256']:item['bytes'] for item in m['media'].values()}
   w.require_space(m['compressed_bytes']+sum(objects.values())+1024*1024,len(objects)+10)
   transfer={'format':1,'environment':'staging','source_url':w.c['url'],'backup':m}
   atomic(backup/'transfer.json',transfer);output.parent.mkdir(parents=True,exist_ok=True);partial=output.with_suffix('.pending')
   with tarfile.open(partial,'w') as archive:
    archive.add(backup/'transfer.json',arcname='transfer.json');archive.add(m['path'],arcname='database.sql.gz')
    for value in objects:archive.add(w.root/'media-objects'/value[:2]/value,arcname='media-objects/'+value[:2]+'/'+value)
   partial.chmod(0o600);partial.replace(output)
   result={'sha256':digest(output),'bytes':output.stat().st_size,'commit':m['commit'],'schema':m['schema']};atomic(output.with_suffix('.manifest.json'),result);return result
 def local(self):
  w=self.worker;host=urlparse(w.c['url']).hostname
  if w.c.get('environment')!='local' or not host or not (host in {'localhost','127.0.0.1','::1'} or host.endswith(('.local','.test'))):raise ValueError('Only an explicitly configured local target is allowed')
  if w.wp('eval','echo wp_get_environment_type();')!='local':raise ValueError('WordPress must also be local')
  return json.loads(w.wp('eval-file',w.helper('database-info.php')))
 def unpack(self,package,expected):
  w=self.worker;package=Path(package).resolve()
  if not re.fullmatch(r'[a-f0-9]{64}',expected) or digest(package)!=expected:raise ValueError('Transfer checksum differs')
  stage=w.root/'local-sync'/expected
  if stage.exists():
   m=json.loads((stage/'transfer.json').read_text())
   if digest(stage/'database.sql.gz')!=m['backup']['sha256']:raise ValueError('Previously unpacked SQL changed')
  else:
   pending=stage.with_suffix('.pending')
   if pending.exists():shutil.rmtree(pending)
   with tarfile.open(package,'r') as archive:
    members=archive.getmembers();total=sum(x.size for x in members);w.require_space(total,len(members)+100)
    seen=set()
    for member in members:
     name=member.name
     if name in seen or not member.isfile() or not (name in {'transfer.json','database.sql.gz'} or re.fullmatch(r'media-objects/[a-f0-9]{2}/[a-f0-9]{64}',name)):raise ValueError('Unsafe transfer path or duplicate')
     seen.add(name)
     if name=='transfer.json' and member.size>32*1024*1024:raise ValueError('Transfer manifest exceeds the byte budget')
    pending.mkdir(parents=True,mode=0o700)
    for member in members:
     path=pending/member.name;path.parent.mkdir(parents=True,exist_ok=True)
     with archive.extractfile(member) as src,path.open('wb') as dst:shutil.copyfileobj(src,dst,1024*1024)
     path.chmod(0o600)
   pending.replace(stage);m=json.loads((stage/'transfer.json').read_text())
  if m.get('format')!=1 or m.get('environment')!='staging' or not m.get('source_url'):raise ValueError('Not a staging transfer manifest')
  b=m['backup'];check_sql(stage/'database.sql.gz',b['sha256'])
  with gzip.open(stage/'database.sql.gz','rb') as source:
   for line in iter(lambda:source.readline(1024*1024),b''):
    if re.match(br'(?i)^\s*(USE\s|CREATE\s+DATABASE\s)',line):raise ValueError('Cross-database SQL is not allowed')
  for item in b['media'].values():
   value=item['sha256'];path=stage/'media-objects'/value[:2]/value
   if digest(path)!=value or path.stat().st_size!=item['bytes']:raise ValueError('Transferred media differs')
  return stage,m
 def preview(self,package,expected):
  w=self.worker;info=self.local();stage,m=self.unpack(package,expected)
  if info['database']==m['backup']['source_database'] or info['prefix']!=m['backup']['prefix']:raise ValueError('Source/target database or prefix conflict')
  out={'package_sha256':expected,'checked_at':time.time(),'target_database':info['database'],'source_commit':m['backup']['commit'],'source_schema':m['backup']['schema'],'source_url':m['source_url'],'target_url':w.c['url'],'media_files':len(m['backup']['media']),'source_tables':m['backup']['tables'],'current_baseline':json.loads(w.wp('eval-file',w.helper('baseline.php'))),'drop_tables':info['tables'],'guid_unchanged':True,'copied_jobs':'Historical / paused; new local jobs required'}
  out['preview_sha256']=hashlib.sha256(json.dumps(out,sort_keys=True).encode()).hexdigest();atomic(stage/'preview.json',out);return out
 def apply(self,package,expected,preview_hash):
  w=self.worker;info=self.local();stage,m=self.unpack(package,expected);preview=json.loads((stage/'preview.json').read_text())
  prior=json.loads((stage/'apply.json').read_text()) if (stage/'apply.json').exists() else {}
  if prior.get('state')=='verified' and prior.get('preview_sha256')==preview_hash:return {'ok':True,'noop':True,'active':prior['active'],'ranking_hash':prior['ranking_hash']}
  if preview['preview_sha256']!=preview_hash or time.time()-preview['checked_at']>86400 or preview['target_database']!=info['database']:raise ValueError('A fresh explicit preview is required')
  if json.loads(w.wp('eval-file',w.helper('baseline.php')))!=preview['current_baseline']:raise ValueError('Local data changed after preview; preview again')
  credential=stage/'client.cnf';verification=stage/'verification.json'
  with w.lock(w.root/'deployment.lock'),w.write_barrier(keep_on_failure=True):
   backup=w._backup('local_sync',hold='local-sync-before:'+expected);atomic(stage/'apply.json',{'state':'backed_up','backup':str(backup),'checked_at':time.time()});w.maintenance(True)
   try:
    with w.lock(w.root/'requests.lock',wait=30):
     db=w.wp('eval-file',w.helper('database-client.php'),extra_env={'MMAF_DB_CLIENT_FILE':str(credential)})
     if db!=info['database']:raise ValueError('Local target changed')
     w.wp('eval-file',w.helper('local-target.php'),extra_env={'MMAF_LOCAL_VERIFY_PATH':str(verification)})
     command=[w.c.get('mysql','mariadb'),'--defaults-extra-file='+str(credential),'--batch','--binary-mode=1',db]
     tables=subprocess.check_output(command+['--skip-column-names','--execute=SHOW TABLES'],stderr=subprocess.PIPE).decode().splitlines()
     if sorted(tables)!=sorted(preview['drop_tables']):raise ValueError('Local table scope changed after preview')
     sql='SET FOREIGN_KEY_CHECKS=0;'+''.join('DROP TABLE `'+name.replace('`','``')+'`;' for name in tables)+'SET FOREIGN_KEY_CHECKS=1;'
     subprocess.run(command,input=sql.encode(),stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
     with gzip.open(stage/'database.sql.gz','rb') as source:
      process=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
      try:
       for chunk in iter(lambda:source.read(1024*1024),b''):process.stdin.write(chunk)
       process.stdin.close();errors=process.stderr.read();process.stderr.close()
       if process.wait():raise RuntimeError('Local SQL restore failed; retain protected backup for recovery')
      finally:
       if process.poll() is None:process.kill();process.wait()
     # Verify SQL before loading any restored WordPress content.
     b=dict(m['backup']);b['path']=str(stage/'database.sql.gz');atomic(stage/'restore-manifest.json',b)
     subprocess.run([w.c['php'],w.helper('verify-restored.php'),str(verification),str(stage/'restore-manifest.json')],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
     from storage import MediaStore
     MediaStore(stage/'media-objects').restore(b['media'],info['uploads'])
     w.wp('eval-file',w.helper('local-quarantine.php'))
     w.wp('mmaf','system','migrate',timeout=300)
     names=[info['prefix']+name for name in ['options','posts','postmeta','terms','termmeta','term_taxonomy','term_relationships','users','usermeta','comments','commentmeta']]
     args=('search-replace',m['source_url'].rstrip('/'),w.c['url'].rstrip('/'),*names,'--precise','--skip-columns=guid','--format=table')
     report=w.wp(*args,'--dry-run');atomic(stage/'url-dry-run.json',{'format':'table','report':report,'skip_columns':['guid']});w.wp(*args)
     w.wp('option','update','home',w.c['url']);w.wp('option','update','siteurl',w.c['url']);w.wp('eval-file',w.helper('local-quarantine.php'))
     baseline=json.loads(w.wp('eval-file',w.helper('baseline.php')))
     if baseline['active']!=b['baseline']['active'] or baseline['ranking_hash']!=b['baseline']['ranking_hash']:raise ValueError('Local transfer changed active ranking')
     atomic(stage/'apply.json',{'state':'verified','checked_at':time.time(),'backup':str(backup),'ranking_hash':baseline['ranking_hash'],'active':baseline['active'],'preview_sha256':preview_hash,'media_files':len(b['media'])});w.maintenance(False)
     return {'ok':True,'media_files':len(b['media']),'active':baseline['active'],'ranking_hash':baseline['ranking_hash']}
   except BaseException:
    atomic(stage/'apply.json',{'state':'failed','backup':str(backup),'checked_at':time.time(),'recovery':'Explicit local restore required; maintenance remains enabled'});raise
   finally:credential.unlink(missing_ok=True);verification.unlink(missing_ok=True)

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('command',choices=['export','preview','apply']);p.add_argument('--config',required=True);p.add_argument('--output');p.add_argument('--package');p.add_argument('--sha256');p.add_argument('--preview-sha256');a=p.parse_args();s=Sync(a.config)
 print(json.dumps(s.export(a.output) if a.command=='export' else s.preview(a.package,a.sha256) if a.command=='preview' else s.apply(a.package,a.sha256,a.preview_sha256)))
