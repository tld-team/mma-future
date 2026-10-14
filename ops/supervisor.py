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
        commands=[('mmaf','background','work','--seconds=35','--memory-mb=256')] if version(schema)>=version('1.10.0') else [('mmaf','import-job','work','--once')]
        if version('1.8.0')<=version(schema)<version('1.10.0'):commands.append(('mmaf','operations','work'))
        if version(schema)<version('1.10.0'):commands.append(('mmaf','data-process','work','--limit=10'))
        while time.monotonic()<end and not paused():
            worked=False
            for command in commands:
                if time.monotonic()>=end or paused():break
                result=json.loads(worker.wp(*command,timeout=120))
                worked=worked or bool(result.get('worked'))
                metrics={'command':' '.join(command[:3]),'units':result.get('units'),'elapsed_seconds':result.get('elapsed_seconds'),'peak_memory_mb':result.get('peak_memory_mb'),'db_queries':result.get('db_queries'),'stop_reason':result.get('stop_reason')}
                atomic(worker.root/'supervisor-heartbeat.json',{'at':time.time(),'state':'running',**metrics})
            if not worked or version(schema)>=version('1.10.0'):break
        if not paused():worker.wp('eval','update_option("mmaf_operations_heartbeat",gmdate("Y-m-d H:i:s"),false);')
        atomic(worker.root/'supervisor-heartbeat.json',{'at':time.time(),'state':'idle' if not paused() else 'checkpoint',**locals().get('metrics',{})})

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);a=p.parse_args()
    try:run(a.config)
    except Busy:sys.exit(0)
