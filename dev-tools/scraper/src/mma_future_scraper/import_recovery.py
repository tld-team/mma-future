"""Reparse exact stored DOM snapshots; preserve missing evidence as a bounded fetch queue."""
from __future__ import annotations
from pathlib import Path
from copy import deepcopy
import hashlib,json,tempfile
from .fighter_profiles import parse_profile_snapshot
from .import_readiness import build_readiness,content_hash
from .storage import atomic_write_json
from .fighter_profile_queue import normalize_tapology_fighter_url
from .utils import source_fighter_id_from_url
from .utils import utc_now


def prepare_recovery(source_profiles,snapshots_dir,output_dir):
    source=Path(source_profiles);document=json.loads(source.read_text());output=Path(output_dir)
    if output.exists():raise FileExistsError('Recovery output already exists; choose a new directory.')
    if not Path(snapshots_dir).is_dir():raise ValueError('Snapshot directory does not exist')
    snapshots={};malformed=[]
    for path in sorted(Path(snapshots_dir).rglob('*.json')):
        if path.stat().st_size>4*1024*1024:continue
        try:snapshot=json.loads(path.read_text(encoding='utf-8-sig'))
        except (ValueError,UnicodeError):
            malformed.append(str(path.relative_to(snapshots_dir)));continue
        if not isinstance(snapshot,dict) or not isinstance(snapshot.get('rows'),list) or not snapshot.get('scraped_at'):continue
        key=source_fighter_id_from_url(normalize_tapology_fighter_url(snapshot.get('url')))
        if not key:continue
        if key in snapshots:raise ValueError('Multiple snapshots for one source identity; select a coherent snapshot cohort.')
        snapshots[key]=(snapshot,path)
    ready=build_readiness(document);needs={p['source_fighter_id']:p for p in ready['source_enrichment']}
    candidates=[];remaining=[];ignored=0
    for profile in document['profiles']:
        key=profile.get('source_fighter_id')
        if key not in needs:continue
        evidence=snapshots.get(key)
        if not evidence:
            remaining.append(needs[key]);continue
        snapshot,path=evidence
        if source_fighter_id_from_url(profile.get('source_url'))!=source_fighter_id_from_url(snapshot['url']):raise ValueError('Snapshot identity mismatch')
        parsed=parse_profile_snapshot(snapshot,profile['source_url']);parsed['scraped_at']=snapshot['scraped_at']
        if parsed['status']!='success':
            ignored+=1;remaining.append(needs[key]);continue
        parsed['recovery_provenance']={'original_content_hash':content_hash(profile),'snapshot_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'observed_at':snapshot['scraped_at']}
        candidates.append(parsed)
        report=build_readiness({'profiles':[parsed]})
        remaining.extend(report['source_enrichment'])
    recovered={'schema_version':'tapology_fighter_profiles_v0_1','source':'tapology','run_id':'offline_recovery','profiles_total':len(candidates),'profiles_success':len(candidates),'profiles_failed':0,'profiles':candidates}
    summary={'source_file_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'profiles_needing_evidence':len(needs),'snapshots_available':len(snapshots),'reparsed_candidates':len(candidates),'failed_snapshots_ignored':ignored,'malformed_json_ignored':malformed,'remaining_enrichment':len(remaining),'automatic_canonical_writes':0,'note':'Observation timestamps are retained. Reparsed content with the same observation time as a different saved snapshot requires importer review.'}
    output.parent.mkdir(parents=True,exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.recovery-',dir=output.parent) as temp:
        stage=Path(temp)/'publication';stage.mkdir()
        atomic_write_json(stage/'reparsed_profiles.json',recovered)
        atomic_write_json(stage/'source_enrichment_queue.json',{'schema_version':'mmaf_source_enrichment_v1','generated_at':utc_now(),'source_file_sha256':summary['source_file_sha256'],'items':remaining})
        atomic_write_json(stage/'recovery_report.json',summary)
        stage.rename(output)
    return summary
