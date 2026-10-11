#!/usr/bin/env python3
"""Build a code-only, allowlisted release from a clean CI checkout."""
import argparse, hashlib, json, os, stat, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PREFIXES=('wp-content/themes/mma-future/','wp-content/plugins/mma-future-data-engine/','wp-content/mu-plugins/','ops/')
EXCLUDED={'node_modules','.git','private','logs','tests','__pycache__'}
def allowed(name):
    p=Path(name)
    if name.startswith('wp-content/mu-plugins/') and name!='wp-content/mu-plugins/mmaf-environment.php':return False
    return name.startswith(PREFIXES) and not p.is_absolute() and '..' not in p.parts and not (set(p.parts)&EXCLUDED) and p.suffix.lower() not in {'.sql','.gz','.zip','.log','.env','.pyc'} and p.name not in {'config.json','wp-config.php'}
def build(destination,commit,sequence):
    if len(commit)!=40 or any(c not in '0123456789abcdef' for c in commit):raise ValueError('A complete commit SHA is required')
    destination=Path(destination);destination.mkdir(parents=True,exist_ok=True)
    files={}
    for prefix in PREFIXES:
        for p in sorted((ROOT/prefix).rglob('*')):
            name=p.relative_to(ROOT).as_posix()
            if p.is_file() and allowed(name):
                if p.is_symlink():raise ValueError('Symlinks are excluded: '+name)
                files[name]=hashlib.sha256(p.read_bytes()).hexdigest()
    compatibility=json.loads((ROOT/'ops/compatibility.json').read_text())
    manifest={'format':1,'commit':commit,'sequence':int(sequence),**compatibility,'asset_manifest':json.loads((ROOT/'wp-content/themes/mma-future/asset-manifest.json').read_text()),'files':files}
    payload=json.dumps(manifest,sort_keys=True,separators=(',',':')).encode()
    path=destination/(str(sequence)+'-'+commit+'.zip')
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(files):
            info=zipfile.ZipInfo(name,(2020,1,1,0,0,0));info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,(ROOT/name).read_bytes())
        info=zipfile.ZipInfo('release.json',(2020,1,1,0,0,0));info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,payload)
    digest=hashlib.sha256(path.read_bytes()).hexdigest()
    path.with_suffix('.ready').write_text(json.dumps({'package':path.name,'sha256':digest,'commit':commit,'sequence':int(sequence)}))
    print(json.dumps({'package':str(path),'sha256':digest,'files':len(files)}))
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);parser.add_argument('--commit',required=True);parser.add_argument('--sequence',required=True,type=int);a=parser.parse_args();build(a.output,a.commit,a.sequence)
