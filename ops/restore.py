#!/usr/bin/env python3
"""Explicit restore into a named empty verification DB, without WP/email/cron."""
import argparse, datetime, gzip, json, os, re, subprocess, sys, tempfile
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from runner import Runner
from storage import atomic, check_sql

class Restore:
    def __init__(self, config):
        self.path=Path(config).resolve()
        if self.path.stat().st_mode & 0o077: raise ValueError('Private restore configuration must be mode 600')
        self.runner=Runner(config);self.c=self.runner.c
        self.target=self.c['verification'];name=self.target['database']
        if not re.fullmatch(r'(?:mmaf_restore_[a-z0-9_]+|lukamut1_mmaf_verify)',name):
            raise ValueError('Only a dedicated verification database is allowed')
    def _client(self,path):
        def quote(value): return '"'+str(value).replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r')+'"'
        values={key:self.target[key] for key in ['user','password','host','port','socket'] if key in self.target}
        path.write_text('[client]\n'+''.join(key+'='+quote(value)+'\n' for key,value in values.items()));path.chmod(0o600)
    def verify(self, manifest_path, destination):
        manifest_path=Path(manifest_path).resolve();m=json.loads(manifest_path.read_text());backup=Path(m['path']).resolve()
        if self.target['database']==m['source_database']: raise ValueError('Cannot restore over the source database')
        checked=check_sql(backup,m['sha256'])
        if checked['tables']!=m['tables']: raise ValueError('SQL table count differs')
        if not m.get('table_rows') or 'media' not in m: raise ValueError('Full row/media manifest required')
        destination=Path(destination).resolve()
        if destination.exists() and any(destination.iterdir()): raise ValueError('Restore media directory must be empty')
        self.runner.require_space(m['sql_bytes']*2+sum(item['bytes'] for item in m['media'].values()),len(m['media'])+m['tables']*3+100)
        with self.runner.lock(self.runner.root/'restore.lock'):
            with tempfile.TemporaryDirectory(prefix='restore-',dir=self.runner.root) as temporary:
                credential=Path(temporary)/'client.cnf';self._client(credential)
                command=[self.c.get('mysql','mariadb'),'--defaults-extra-file='+str(credential),'--batch','--skip-column-names',self.target['database']]
                existing=subprocess.check_output(command+['--execute=SHOW TABLES'],stderr=subprocess.PIPE)
                if existing.strip(): raise ValueError('Verification database must be empty; use a new isolated database')
                # Our dumps never select/create another database. Refuse any such statement.
                with gzip.open(backup,'rb') as source:
                    for line in iter(lambda: source.readline(1024*1024),b''):
                        if re.match(br'(?i)^\s*(USE\s|CREATE\s+DATABASE\s)',line): raise ValueError('Cross-database SQL is not allowed')
                with gzip.open(backup,'rb') as source,tempfile.TemporaryFile(dir=temporary) as errors:
                    process=subprocess.Popen(command+['--binary-mode=1'],stdin=subprocess.PIPE,stdout=subprocess.DEVNULL,stderr=errors)
                    try:
                        for chunk in iter(lambda: source.read(1024*1024),b''): process.stdin.write(chunk)
                        process.stdin.close()
                        if process.wait(): raise RuntimeError('Database restore failed; the isolated copy was left for diagnosis')
                    finally:
                        if process.poll() is None: process.kill();process.wait()
                result=json.loads(subprocess.check_output([self.c['php'],str(Path(__file__).with_name('verify-restored.php')),str(self.path),str(manifest_path)],stderr=subprocess.PIPE))
                self.runner.media.restore(m['media'],destination)
                now=datetime.datetime.now(datetime.timezone.utc).isoformat()
                m.update(restore_verified_at=now,restore_result=result)
                self.runner.catalog.register(backup,m);atomic(manifest_path,m)
                atomic(self.runner.root/'restore-status.json',dict(result,checked_at=now,backup_sha256=m['sha256'],media_files=len(m['media']),schema=m['schema'],format=m['format'],sql_format=m.get('sql_format','native-v1')))
                return result

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);p.add_argument('--manifest',required=True);p.add_argument('--media-destination',required=True);a=p.parse_args()
    print(json.dumps(Restore(a.config).verify(a.manifest,a.media_destination)))
