"""Explicit closed scraping cohorts and resumable, atomic offline publication."""
from __future__ import annotations
from contextlib import contextmanager
from pathlib import Path
from copy import deepcopy
import hashlib, json, os, re, shutil, tempfile
from .config import LATEST_DIR
from .storage import atomic_write_json
from .utils import utc_now
from .import_bundle import export_import_bundle, REQUIRED, OPTIONAL
from .import_readiness import format_readiness, content_hash

_held=set()

@contextmanager
def writer_lock(data_dir):
    root=Path(data_dir).resolve(); root.mkdir(parents=True,exist_ok=True)
    path=root/'state'/'import-pipeline.lock'; path.parent.mkdir(parents=True,exist_ok=True)
    if path in _held:
        yield; return
    with path.open('a+b') as handle:
        try:
            if os.name=='nt':
                import msvcrt
                handle.seek(0); handle.write(b'0'); handle.flush(); handle.seek(0)
                msvcrt.locking(handle.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl
                fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except OSError as exc:
            raise RuntimeError('Another scraper or finalizer owns the input writer lock.') from exc
        _held.add(path)
        try: yield
        finally:
            _held.remove(path)
            if os.name=='nt':
                handle.seek(0); msvcrt.locking(handle.fileno(),msvcrt.LK_UNLCK,1)
            else: fcntl.flock(handle,fcntl.LOCK_UN)


class ImportCycle:
    def __init__(self,cycle_id,latest_dir=LATEST_DIR):
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,79}',cycle_id): raise ValueError('Invalid cycle ID')
        self.id=cycle_id; self.latest=Path(latest_dir).resolve()
        self.path=self.latest.parent/'state'/'import-cycles'/(cycle_id+'.json')

    def read(self):
        if not self.path.is_file(): raise ValueError('Begin the import cycle before recording runs.')
        return json.loads(self.path.read_text())

    def begin(self,event_runs=1,profile_runs=1,**health):
        if event_runs<1 or profile_runs<0: raise ValueError('At least one event run and non-negative profile run count are required')
        if self.path.exists(): raise FileExistsError('Cycle already exists; use another cycle ID.')
        tasks={f'{kind}-{n}':{'kind':kind,'status':'pending'} for kind,count in [('events',event_runs),('profiles',profile_runs)] for n in range(1,count+1)}
        value={'format':'mmaf_import_cycle_v1','cycle_id':self.id,'status':'open','created_at':utc_now(),'tasks':tasks,'health':health}
        atomic_write_json(self.path,value); return value

    def start(self,task,kind):
        value=self.read(); row=value['tasks'].get(task)
        if value['status']!='open' or not row or row['kind']!=kind or row['status'] not in ('pending','failed'):
            raise ValueError('Task is unknown, already completed, or belongs to a sealed cycle.')
        row.update(status='running',started_at=utc_now()); atomic_write_json(self.path,value)

    def finish(self,task,exit_code):
        value=self.read(); row=value['tasks'][task]
        if row['status']!='running': raise ValueError('Only a running task can be finished.')
        row.update(status='completed' if exit_code==0 else 'failed',finished_at=utc_now(),completion_order=1+max((r.get('completion_order',0) for r in value['tasks'].values()),default=0))
        if exit_code==0:
            name='run_manifest.json' if row['kind']=='events' else 'fighter_profiles.json'
            document=json.loads((self.latest/name).read_text())
            row['run_id']=document['run_id']; row['output_sha256']=hashlib.sha256((self.latest/name).read_bytes()).hexdigest()
        atomic_write_json(self.path,value)

    def adopt(self,task,run_dir):
        """Register an existing completed run; inputs must be that exact latest run."""
        value=self.read(); row=value['tasks'].get(task)
        if not row or value['status']!='open': raise ValueError('Unknown task or sealed cycle.')
        name='run_manifest.json' if row['kind']=='events' else 'fighter_profiles.json'
        source=Path(run_dir)/name; current=self.latest/name
        if not source.is_file() or source.read_bytes()!=current.read_bytes(): raise ValueError('Adopted run does not match current latest input.')
        doc=json.loads(source.read_text())
        if row['kind']=='events' and doc.get('status')!='success': raise ValueError('The event run did not finish successfully.')
        self.start(task,row['kind']); self.finish(task,0)

    def finalize(self,output_dir,base_receipt=None):
        with writer_lock(self.latest.parent):
            value=self.read(); final=Path(output_dir).resolve()/self.id
            if base_receipt is not None:base_receipt=base_receipt.get('receipt',base_receipt)
            if value['status']!='open' and base_receipt is not None and base_receipt!=value.get('base_receipt'):
                raise ValueError('The sealed delta base cannot change. Begin another cycle.')
            if value['status']=='finalized':
                return self._verify_publication(value,final)
            if any(r['status']!='completed' for r in value['tasks'].values()): raise ValueError('All planned runs must finish before finalization.')
            if value['status']=='open':
                for kind,name in [('events','run_manifest.json'),('profiles','fighter_profiles.json')]:
                    records=[r for r in value['tasks'].values() if r['kind']==kind]
                    if records:
                        last=max(records,key=lambda r:(r.get('completion_order',0),r['finished_at']))
                        if hashlib.sha256((self.latest/name).read_bytes()).hexdigest()!=last['output_sha256']:
                            raise ValueError('Latest input changed outside the declared cycle.')
                paths=[self.latest/name for name in REQUIRED+OPTIONAL if (self.latest/name).is_file()]
                value['input_files']={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}
                incomplete=self.latest.parent/'state/incomplete_events.json'
                if incomplete.is_file():value['input_files']['../state/incomplete_events.json']=hashlib.sha256(incomplete.read_bytes()).hexdigest()
                value['base_receipt']=base_receipt
                value.update(status='sealed',sealed_at=utc_now()); atomic_write_json(self.path,value)
            self._verify_inputs(value)
            if final.exists():
                # Recover a crash after directory publication but before the cycle checkpoint.
                result=self._verify_publication(value,final)
            else:
                final.parent.mkdir(parents=True,exist_ok=True)
                with tempfile.TemporaryDirectory(prefix='.finalize-',dir=final.parent) as temp:
                    stage=Path(temp)/'publication'; stage.mkdir()
                    cohort={'cycle_id':self.id,'sealed_at':value['sealed_at'],'input_files':value['input_files'],'tasks':value['tasks']}
                    cohort['snapshot_id']=content_hash(value['input_files'])
                    result=export_import_bundle(stage/'package.zip',latest_dir=self.latest,base_receipt=value.get('base_receipt'),cycle=cohort,**value['health'])
                    import zipfile
                    with zipfile.ZipFile(stage/'package.zip') as z:
                        for name in ['readiness_report.json','source_enrichment_queue.json']:
                            (stage/name.replace('_','-')).write_bytes(z.read('latest/'+name))
                    report=json.loads((stage/'readiness-report.json').read_text())
                    (stage/'readiness-report.md').write_text(format_readiness(report))
                    (stage/'package.sha256').write_text(result['sha256']+'  package.zip\n')
                    public=deepcopy(value); public['package_sha256']=result['sha256']; public['status']='finalized'
                    public['publication_files']={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in stage.iterdir()}
                    atomic_write_json(stage/'cycle-manifest.json',public)
                    self._verify_inputs(value)
                    stage.rename(final)
            value.update(status='finalized',package_sha256=result['sha256'],publication_files=json.loads((final/'cycle-manifest.json').read_text()).get('publication_files',{}),publication=str(final),finalized_at=utc_now())
            atomic_write_json(self.path,value)
            return self._verify_publication(value,final)

    def _verify_inputs(self,value):
        expected=value['input_files'];incomplete=self.latest.parent/'state/incomplete_events.json'
        if incomplete.is_file()!=('../state/incomplete_events.json' in expected):raise ValueError('Sealed cycle input changed. Begin another cycle.')
        for name,digest in expected.items():
            path=self.latest/name
            if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest()!=digest:raise ValueError('Sealed cycle input changed. Begin another cycle.')

    def _verify_publication(self,value,final):
        marker=final/'cycle-manifest.json'; archive=final/'package.zip'
        if not marker.is_file() or not archive.is_file(): raise ValueError('Cycle publication is incomplete; preserve evidence and repair it.')
        manifest=json.loads(marker.read_text()); digest=hashlib.sha256(archive.read_bytes()).hexdigest()
        if value.get('publication_files') and value['publication_files']!=manifest.get('publication_files'):raise ValueError('Cycle report checksum manifest differs.')
        if manifest.get('base_receipt')!=value.get('base_receipt') or manifest['cycle_id']!=self.id or manifest.get('input_files')!=value.get('input_files') or manifest.get('package_sha256')!=digest or (value.get('package_sha256') and value['package_sha256']!=digest):
            raise ValueError('Cycle publication identity or checksum differs.')
        for name,digest in manifest.get('publication_files',{}).items():
            path=final/name
            if Path(name).name!=name or not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest()!=digest:raise ValueError('Cycle report checksum differs.')
        return {'cycle_id':self.id,'ready_for_import':True,'output':str(archive),'sha256':digest,'bytes':archive.stat().st_size}
