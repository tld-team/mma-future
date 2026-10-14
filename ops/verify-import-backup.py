#!/usr/bin/env python3
"""Verify a private import backup using the existing empty-DB restore engine."""
import argparse,json
from pathlib import Path
from restore import Restore

def verify(config,job_id,media_dir):
    if job_id<1:raise ValueError('A positive import job ID is required')
    restore=Restore(config);root=restore.runner.root
    manifest=root/'backups'/('import-'+str(job_id))/'manifest.json'
    data=json.loads(manifest.read_text())
    if data.get('import_job_id')!=job_id or data.get('reason')!='import' or Path(data['path']).resolve().parent!=manifest.parent.resolve():
        raise ValueError('Backup does not belong to this private import')
    with restore.runner.lock(root/'supervisor.lock'):
        return restore.verify(manifest,media_dir)

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--config',required=True);p.add_argument('--job-id',type=int,required=True);p.add_argument('--media-dir',required=True);a=p.parse_args()
    print(json.dumps(verify(a.config,a.job_id,a.media_dir)))
