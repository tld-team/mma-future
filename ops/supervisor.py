#!/usr/bin/env python3
"""One bounded server supervisor; WordPress core cron remains a separate command."""
import argparse, json, sys, time
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from runner import Runner, Busy
from storage import atomic

def run(config):
    worker=Runner(config);end=time.monotonic()+50
    paused=lambda:any((worker.root/name).exists() for name in ['writes-paused.flag','maintenance.flag','recovery-readonly.flag'])
    with worker.lock(worker.root/'supervisor.lock'):
        if paused(): return
        worker.wp('eval','update_option("mmaf_operations_heartbeat",gmdate("Y-m-d H:i:s"),false);')
        atomic(worker.root/'supervisor-heartbeat.json',{'at':time.time(),'state':'running'})
        from runner import version
        schema=worker.wp('option','get','mmaf_db_version')
        commands=[('mmaf','import-job','work','--once')]
        if version(schema)>=version('1.8.0'):commands.append(('mmaf','operations','work'))
        commands.append(('mmaf','data-process','work','--limit=10'))
        while time.monotonic()<end and not paused():
            worked=False
            for command in commands:
                if time.monotonic()>=end or paused():break
                result=json.loads(worker.wp(*command,timeout=120))
                worked=worked or bool(result.get('worked'))
                atomic(worker.root/'supervisor-heartbeat.json',{'at':time.time(),'state':'running','command':' '.join(command[:3])})
            if not worked:break
        if not paused():worker.wp('eval','update_option("mmaf_operations_heartbeat",gmdate("Y-m-d H:i:s"),false);')
        atomic(worker.root/'supervisor-heartbeat.json',{'at':time.time(),'state':'idle' if not paused() else 'checkpoint'})

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);a=p.parse_args()
    try:run(a.config)
    except Busy:sys.exit(0)
