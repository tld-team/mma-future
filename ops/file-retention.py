#!/usr/bin/env python3
"""Private backup/log/release retention, guarded by saved acceptance and live references."""
import argparse, datetime, hashlib, json, shutil, sys, time
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from runner import Runner
from storage import atomic, digest

def run(config,apply=False):
    worker=Runner(config);accepted=worker.root/'retention-accepted.json';restore=worker.root/'restore-status.json'
    if apply:
        proof=json.loads(restore.read_text()) if restore.exists() else {}
        if not accepted.exists() or not proof.get('ok') or time.time()-datetime.datetime.fromisoformat(proof.get('checked_at','1970-01-01T00:00:00+00:00')).timestamp()>30*86400:
            raise ValueError('Current full restore proof and accepted retention are required')
    with worker.lock(worker.root/'deployment.lock'),worker.write_barrier():
        plan={'checked_at':time.time(),'apply':apply,'backups':worker.catalog.retention(),'releases':[],'logs':[]}
        current=json.loads((worker.root/'release.json').read_text()) if (worker.root/'release.json').exists() else {}
        verified=[]
        for path in (worker.root/'releases').iterdir():
            if path.is_dir() and not path.is_symlink() and (path/'verified.json').exists():
                manifest=json.loads((path/'release.json').read_text());verified.append((int(manifest['sequence']),path,manifest))
        verified.sort(reverse=True);keep={seq for seq,_,_ in verified[:5]};keep.add(current.get('sequence'))
        # Also preserve the most recent compatible fallback regardless of its age.
        schema=worker.wp('option','get','mmaf_db_version');from runner import version
        fallback=next((seq for seq,_,m in verified if seq!=current.get('sequence') and version(m['schema_min'])<=version(schema)<=version(m['schema_max'])),None)
        if fallback:keep.add(fallback)
        for seq,path,manifest in verified:
            eligible=seq not in keep;plan['releases'].append({'sequence':seq,'eligible':eligible})
            if apply and eligible:
                shutil.rmtree(path)
                runtime=worker.root/'runtimes'/str(seq)
                if runtime.is_dir() and not runtime.is_symlink() and runtime.resolve()!=(worker.root/'current').resolve():shutil.rmtree(runtime)
        by_path={m['path']:m for m in worker.catalog.read()}
        for candidate in plan['backups']:
            if not apply or not candidate['eligible']:continue
            path=Path(candidate['path']);record=by_path[str(path)]
            if path.is_symlink() or worker.root/'backups' not in path.resolve().parents:continue # import backups have independent holds
            if record.get('holds') or digest(path)!=record['sha256']:raise ValueError('Backup reference or checksum changed')
            path.unlink()
            identity=hashlib.sha256(str(path.resolve()).encode()).hexdigest();(worker.root/'catalog'/(identity+'.json')).unlink()
            (path.parent/'manifest.json').unlink(missing_ok=True)
            if not any(path.parent.iterdir()):path.parent.rmdir()
        logs=worker.root/'logs'
        if logs.exists():
            for path in logs.glob('*.log*'):
                if path.is_file() and not path.is_symlink() and time.time()-path.stat().st_mtime>30*86400:
                    plan['logs'].append(path.name)
                    if apply:path.unlink()
        atomic(worker.root/'file-retention-status.json',plan);return plan

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);p.add_argument('--apply',action='store_true');a=p.parse_args();print(json.dumps(run(a.config,a.apply)))
