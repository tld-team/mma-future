#!/usr/bin/env python3
"""Independent monitor remains useful while WordPress is blocked or cannot bootstrap."""
import argparse, datetime, hashlib, json, re, subprocess, sys, time
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from runner import Runner, Busy
from storage import atomic, capacity

def notify(worker,key,reason):
    path=worker.root/'independent-alerts.json';old=json.loads(path.read_text()) if path.exists() else {}
    fingerprint=hashlib.sha256(reason.encode()).hexdigest();prior=old.get(key,{})
    if prior.get('fingerprint')==fingerprint and time.time()-prior.get('sent_at',0)<86400:return
    environment=worker.c.get('environment','staging')
    if environment=='local':return
    message={'subject':'[MMA Future]['+environment+'] Critical: '+key,'body':'Environment: '+environment+'\nReason: '+reason+'\nAdministration: '+worker.c['url'].rstrip('/')+'/wp-admin/admin.php?page=mmaf-health\nNo profile payload or credentials are included.'}
    # The mail body is stdin, never a shell command or process argument.
    code='$m=json_decode(stream_get_contents(STDIN),true);exit(mail("luka.m@tldteam.com",$m["subject"],$m["body"])?0:1);'
    result=subprocess.run([worker.c['php'],'-r',code],input=json.dumps(message),text=True,capture_output=True)
    if result.returncode==0:old[key]={'fingerprint':fingerprint,'sent_at':time.time()};atomic(path,old)

def run(config):
    worker=Runner(config)
    with worker.lock(worker.root/'monitor.lock'):
        quota_command=worker.c.get('quota_command')
        if quota_command:
            try:
                value=json.loads(subprocess.check_output(quota_command,timeout=60,stderr=subprocess.PIPE));atomic(worker.root/'capacity.json',value)
            except (subprocess.SubprocessError,ValueError):pass # previous evidence expires; never assume unlimited
        quota_path=worker.root/'capacity.json'
        quota=json.loads(quota_path.read_text()) if quota_path.exists() else {}
        atomic(worker.root/'capacity-status.json',capacity(worker.root,quota))
        paused=any((worker.root/name).exists() for name in ['writes-paused.flag','maintenance.flag'])
        if paused:
            journal=worker.root/'apply-state.json';value=json.loads(journal.read_text()) if journal.exists() else {}
            flags=[p.stat().st_mtime for p in [worker.root/'writes-paused.flag',worker.root/'maintenance.flag'] if p.exists()]
            # Retouching a pause flag during retries is not progress.
            progressing=value.get('state') in {'backed_up','applying','waiting_for_checkpoint'}
            last=value.get('updated_at') if progressing else None
            age=max(0,time.time()-(last if isinstance(last,(int,float)) else min(flags)))
            failed=value.get('state') in {'failed','rolled_back'}
            if failed or age>=600:notify(worker,'release','Release #'+str(value.get('sequence','unknown'))+' is '+('failed' if failed else 'paused without progress for ten minutes')+'. Inspect private release status.')
            atomic(worker.root/'monitor-status.json',{'checked_at':time.time(),'state':'protected_checkpoint','pause_age':age});return
        if (worker.root/'recovery-readonly.flag').exists():
            notify(worker,'recovery','A compatible fallback is serving public reads. Writes remain paused until a verified operational release is applied.')
            atomic(worker.root/'monitor-status.json',{'checked_at':time.time(),'state':'recovery_readonly'});return
        try:
            result=json.loads(worker.wp('mmaf','operations','monitor'));atomic(worker.root/'monitor-status.json',result)
            checked=worker.root/'diagnostics.checked'
            if not checked.exists() or time.time()-checked.stat().st_mtime>=3600:
                worker.wp('mmaf','operations','diagnostics');checked.touch()
        except Busy:return # a new protected checkpoint is expected
        except Exception:
            notify(worker,'bootstrap','Independent monitor could not load WordPress. Inspect release status and Operations.');raise

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);a=p.parse_args()
    try:run(a.config)
    except Busy:sys.exit(0)
