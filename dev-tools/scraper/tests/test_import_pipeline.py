import json,hashlib,zipfile
from copy import deepcopy
from pathlib import Path
import pytest
from mma_future_scraper.fighter_profiles import parse_profile_snapshot, merge_fighter_profiles_documents
from mma_future_scraper.import_readiness import build_readiness, normalize_profile,content_hash
from mma_future_scraper.import_cycle import ImportCycle
from test_pre_import import _write_complete_latest

SNAPSHOTS=Path(__file__).resolve().parents[3]/'tests/fixtures/import-pipeline/snapshots.json'

def document():
    profiles=[]
    for snapshot in json.loads(SNAPSHOTS.read_text()):
        profile=parse_profile_snapshot(snapshot,snapshot['url']);profile['scraped_at']=snapshot['scraped_at'];profiles.append(profile)
    return {'schema_version':'tapology_fighter_profiles_v0_1','source':'tapology','run_id':'profiles_1','profiles_total':2,'profiles_success':2,'profiles_failed':0,'profiles':profiles}

def cycle(tmp_path):
    latest=tmp_path/'data/latest';_write_complete_latest(latest,latest.parent/'state')
    (latest/'fighter_profiles.json').write_text(json.dumps(document()))
    c=ImportCycle('fixture',latest);c.begin(expected_pages=15)
    c.adopt('events-1',latest);c.adopt('profiles-1',latest)
    return c

def test_explicit_section_evidence_and_legacy_readiness():
    d=document();r=build_readiness(d)
    assert r['profiles']['career_ready_candidates']==2
    row=d['profiles'][0]['fight_history'][0]
    assert row['classification_evidence']=='profile_section_classification' and row['source_sport']=='mma'
    legacy=deepcopy(d)
    for p in legacy['profiles']:
        p.pop('history_scope_verified');p.pop('history_exhausted');p.pop('parser_version')
        for row in p['fight_history']:row.pop('classification_evidence')
    r=build_readiness(legacy)
    assert r['profiles']['careers_needing_enrichment']==2 and r['profiles']['legacy_profiles']==2
    assert r['source_enrichment'][0]['reasons']['missing_classification_evidence']

def test_boundary_or_global_heading_cannot_manufacture_evidence():
    s=json.loads(SNAPSHOTS.read_text())[0];s['rows'][0].pop('row_boundary_verified')
    p=parse_profile_snapshot(s,s['url'])
    assert not p['fight_history'][0]['is_professional']
    assert not p['fight_history'][0].get('classification_evidence')

def test_last_failed_attempt_retains_previous_success_separately():
    d=document();failed=deepcopy(d);failed['profiles'][0].update(status='failed_parse',scraped_at='2026-10-11T10:00:00Z',error_reason='fixture timeout')
    merged=merge_fighter_profiles_documents(d,failed)
    p=merged['profiles'][0]
    assert p['status']=='success' and p['scraped_at']=='2026-10-10T10:00:00Z'
    assert p['last_attempt']['status']=='failed_parse'
    assert build_readiness(merged)['profiles']['last_attempt_failed']==1

def test_identity_conflicts_are_blocked_offline():
    p=document()['profiles'][0];p['source_fighter_id']='tapology_fighter_1'
    with pytest.raises(ValueError,match='identity'):normalize_profile(p)
    p['source_url']='https://other.example/fightcenter/fighters/1-fixture'
    with pytest.raises(ValueError,match='identity'):normalize_profile(p)

def test_finalization_publishes_once_and_binds_reports_to_current_input(tmp_path):
    c=cycle(tmp_path);out=tmp_path/'ready';result=c.finalize(out)
    assert result['sha256']==hashlib.sha256(Path(result['output']).read_bytes()).hexdigest()
    assert c.finalize(out)==result
    with zipfile.ZipFile(result['output']) as z:
        manifest=json.loads(z.read('latest/package_manifest.json'))
        assert manifest['contract_version']==1
        assert json.loads(z.read('latest/readiness_report.json'))['profiles']['career_ready_candidates']==2
        for name,item in manifest['files'].items():assert hashlib.sha256(z.read('latest/'+name)).hexdigest()==item['sha256']
    marker=json.loads((out/'fixture/cycle-manifest.json').read_text())
    assert marker['input_files']['fighter_profiles.json']==hashlib.sha256((c.latest/'fighter_profiles.json').read_bytes()).hexdigest()
    assert c.read()['status']=='finalized'

def test_pending_interrupted_or_external_run_never_finalizes(tmp_path):
    c=cycle(tmp_path);v=c.read();v['tasks']['profiles-1']['status']='running';c.path.write_text(json.dumps(v))
    with pytest.raises(ValueError,match='All planned'):c.finalize(tmp_path/'ready')
    v['tasks']['profiles-1']['status']='completed';c.path.write_text(json.dumps(v))
    (c.latest/'fighter_profiles.json').write_text('{}')
    with pytest.raises(ValueError,match='outside'):c.finalize(tmp_path/'ready')

def test_crash_after_publication_recovers_without_another_zip(tmp_path):
    c=cycle(tmp_path);out=tmp_path/'ready';result=c.finalize(out);value=c.read()
    marker=out/'fixture/cycle-manifest.json';manifest=json.loads(marker.read_text())
    manifest['publication_files']=dict(sorted(manifest['publication_files'].items()))
    marker.write_text(json.dumps(manifest))
    value['status']='sealed';value.pop('package_sha256');c.path.write_text(json.dumps(value))
    assert c.finalize(out)==result
    (out/'fixture/package.zip').write_bytes(b'corrupt')
    with pytest.raises(ValueError,match='checksum'):c.finalize(out)

def test_shared_contract_digests_and_url_variants():
    from mma_future_scraper.fighter_profile_queue import normalize_tapology_fighter_url
    from mma_future_scraper.utils import source_fighter_id_from_url
    contracts=json.loads((SNAPSHOTS.parent/'contracts.json').read_text())
    for case in contracts['digest_cases']:assert content_hash(case['input'])==case['sha256']
    for case in contracts['url_cases']:
        assert normalize_tapology_fighter_url(case['url'])==case['normalized']
        assert source_fighter_id_from_url(case['url'])==case['source_fighter_id']

def test_forged_section_proof_and_conflicting_opponent_perspectives():
    d=document();d['profiles'][0]['fight_history'][0]['classification_proof']['row_boundary_verified']=False
    assert build_readiness(d)['source_enrichment'][0]['reasons']['invalid_section_evidence']
    d=document();d['profiles'][1]['fight_history'][0]['result']='win'
    report=build_readiness(d)
    assert report['enrichment_actions']['review_conflict']==2
    assert all(p['conflicting_bouts']==['tapology_bout_94981301'] for p in report['source_enrichment'])

def test_publication_reports_and_sealed_state_cannot_change(tmp_path):
    c=cycle(tmp_path);out=tmp_path/'ready';c.finalize(out)
    (out/'fixture/readiness-report.md').write_text('corrupt')
    with pytest.raises(ValueError,match='report checksum'):c.finalize(out)
    other=cycle(tmp_path/'other');value=other.read();value.update(status='sealed',base_receipt=None,input_files={'fighter_profiles.json':hashlib.sha256((other.latest/'fighter_profiles.json').read_bytes()).hexdigest()});other.path.write_text(json.dumps(value))
    with pytest.raises(ValueError,match='delta base'):other.finalize(out,{'job_id':99})
    (other.latest.parent/'state/incomplete_events.json').write_text('{}')
    with pytest.raises(ValueError,match='Sealed cycle input'):other.finalize(out)

def test_cli_complete_cycle_publishes_and_reuses_one_package(tmp_path,capsys):
    from mma_future_scraper.cli import main
    latest=tmp_path/'data/latest';_write_complete_latest(latest,latest.parent/'state')
    (latest/'fighter_profiles.json').write_text(json.dumps(document()))
    common=['--cycle-id','operator','--latest-dir',str(latest)]
    assert main(['begin-import-cycle',*common,'--expected-pages','15'])==0
    assert main(['finalize-import',*common,'--output-dir',str(tmp_path/'ready')])==2
    for task in ['events-1','profiles-1']:
        assert main(['record-import-run',*common,'--cycle-task',task,'--run-dir',str(latest)])==0
    assert main(['finalize-import',*common,'--output-dir',str(tmp_path/'ready')])==0
    checksum=hashlib.sha256((tmp_path/'ready/operator/package.zip').read_bytes()).hexdigest()
    assert main(['finalize-import',*common,'--output-dir',str(tmp_path/'ready')])==0
    assert hashlib.sha256((tmp_path/'ready/operator/package.zip').read_bytes()).hexdigest()==checksum

def test_offline_recovery_preserves_observation_and_reduces_evidence_queue(tmp_path):
    from mma_future_scraper.import_recovery import prepare_recovery
    d=document()
    for p in d['profiles']:
        p.pop('parser_version');p.pop('history_scope_verified');p.pop('history_exhausted')
        for r in p['fight_history']:r.pop('classification_evidence')
    source=tmp_path/'legacy.json';source.write_text(json.dumps(d));before=source.read_bytes()
    snapshots=tmp_path/'snapshots';snapshots.mkdir()
    for i,s in enumerate(json.loads(SNAPSHOTS.read_text())):(snapshots/str(i)).with_suffix('.json').write_text(json.dumps(s))
    report=prepare_recovery(source,snapshots,tmp_path/'recovery')
    assert report['reparsed_candidates']==2 and report['remaining_enrichment']==0
    assert source.read_bytes()==before
    parsed=json.loads((tmp_path/'recovery/reparsed_profiles.json').read_text())
    assert all(p['scraped_at']=='2026-10-10T10:00:00Z' and p['recovery_provenance']['snapshot_sha256'] for p in parsed['profiles'])

def test_source_queue_does_not_auto_fetch_human_conflicts(tmp_path):
    from mma_future_scraper.fighter_profile_queue import collect_fighter_profile_urls_from_queue
    queue=tmp_path/'queue.json';queue.write_text(json.dumps({'schema_version':'mmaf_source_enrichment_v1','items':[{'source_url':document()['profiles'][0]['source_url'],'action':'refresh_source_evidence'},{'source_url':document()['profiles'][1]['source_url'],'action':'review_conflict'}]}))
    urls,_=collect_fighter_profile_urls_from_queue(queue,limit=10)
    assert urls==[document()['profiles'][0]['source_url']]

def test_cross_process_writer_and_mid_export_change_block_publication(tmp_path,monkeypatch):
    import subprocess,sys,os
    from mma_future_scraper.import_cycle import writer_lock
    import mma_future_scraper.import_cycle as module
    c=cycle(tmp_path)
    script="from pathlib import Path\nfrom mma_future_scraper.import_cycle import writer_lock\nwith writer_lock(Path("+repr(str(c.latest.parent))+")):\n    pass\n"
    with writer_lock(c.latest.parent):
        process=subprocess.run([sys.executable,'-c',script],capture_output=True,text=True,env=dict(os.environ,PYTHONPATH=str(Path(__file__).resolve().parents[1]/'src')))
        assert process.returncode!=0 and 'writer lock' in process.stderr
    exporter=module.export_import_bundle
    def changed(*args,**kwargs):
        result=exporter(*args,**kwargs)
        (c.latest/'fighter_profiles.json').write_text('{}')
        return result
    monkeypatch.setattr(module,'export_import_bundle',changed)
    with pytest.raises(ValueError,match='Sealed cycle input'):c.finalize(tmp_path/'ready')
    assert not (tmp_path/'ready/fixture').exists() and c.read()['status']=='sealed'

def test_bounded_source_batches_advance_after_failed_attempt(tmp_path,monkeypatch):
    import mma_future_scraper.fighter_profile_queue as module
    latest=tmp_path/'latest';latest.mkdir();monkeypatch.setattr(module,'LATEST_DIR',latest)
    d=document();d['profiles'][0]['last_attempt']={'status':'blocked','at':'2026-10-12T10:00:00Z'}
    (latest/'fighter_profiles.json').write_text(json.dumps(d))
    queue=tmp_path/'queue.json'
    queue.write_text(json.dumps({'schema_version':'mmaf_source_enrichment_v1','generated_at':'2026-10-11T10:00:00Z','items':[{'source_url':p['source_url'],'action':'refresh_source_evidence'} for p in d['profiles']]}))
    urls,meta=module.collect_fighter_profile_urls_from_queue(queue,limit=1)
    assert urls==[d['profiles'][1]['source_url']] and meta['skipped_attempted_since_queue']==1

def test_redirect_to_another_fighter_never_supplies_requested_identity():
    snapshot=json.loads(SNAPSHOTS.read_text())[0]
    result=parse_profile_snapshot(snapshot,document()['profiles'][1]['source_url'])
    assert result['status']=='failed_parse' and result['error_reason']=='redirected_to_different_fighter'
