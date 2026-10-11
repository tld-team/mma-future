#!/usr/bin/env python3
"""Install once outside the web root. Never imports incoming package code."""
import json, os, subprocess, sys
from pathlib import Path
config=Path(sys.argv[1]).resolve()
settings=json.loads(config.read_text());root=Path(settings['private_root']).resolve()
runtime=(root/'current').resolve()
if runtime.parent != root/'runtimes' or not runtime.is_dir():
    raise SystemExit('A verified private runtime is required')
command=sys.argv[2] if len(sys.argv)>2 else 'release'
if command not in {'release','backup','status','verify','rollback','restore','local-sync','retention','supervise','monitor','core-cron'}: raise SystemExit('Unsupported launcher command')
if command=='core-cron':
    if any((root/name).exists() for name in ['maintenance.flag','writes-paused.flag']):raise SystemExit(0)
    args=[settings['php'],settings['wp_cli'],'--path='+settings['wp_root'],'cron','event','run','--due-now','--exclude=mmaf_operations_supervisor,mmaf_process_stored_profiles']
elif command in {'supervise','monitor'}:
    args=[sys.executable,str(runtime/('supervisor.py' if command=='supervise' else 'monitor.py')),'--config',str(config)]
elif command in {'restore','local-sync','retention'}:
    script={'restore':'restore.py','local-sync':'local-sync.py','retention':'file-retention.py'}[command]
    args=[sys.executable,str(runtime/script),'--config',str(config),*sys.argv[3:]]
else:args=[sys.executable,str(runtime/'runner.py'),command,'--config',str(config)]
if command in {'verify','rollback'}:args+=sys.argv[3:]
raise SystemExit(subprocess.call(args,env=dict(os.environ,MMAF_OPERATIONS_DIR=str(root),MMAF_WRITE_LOCK=settings['write_lock'])))
