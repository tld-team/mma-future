"""Export one immutable, health-checked package for the WordPress regular importer."""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import tempfile
import zipfile
from pathlib import Path

from .config import LATEST_DIR
from .pre_import import build_pre_import_artifacts
from .fighter_profile_report import build_profile_enrichment_report
from .import_readiness import build_readiness, content_hash, CONTRACT_VERSION
from .storage import atomic_write_json
from .utils import utc_now

REQUIRED = (
    'results.json', 'daily_summary.json', 'manual_review.json', 'run_manifest.json',
    'changes.json', 'fighter_profiles.json', 'fighter_profiles_report.json',
)
OPTIONAL = ('state_report.json',)
DERIVED = ('readiness_report.json', 'source_enrichment_queue.json')


def _sha256(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def export_import_bundle(
    output: Path,
    *,
    latest_dir: Path = LATEST_DIR,
    expected_pages: int | None = None,
    max_event_fetch_failures: int = 0,
    min_profile_success_rate: float = 0.99,
    base_receipt: dict | None = None,
    cycle: dict | None = None,
) -> dict:
    output = Path(output).expanduser().resolve()
    latest_dir = Path(latest_dir).resolve()
    if output.suffix.lower() != '.zip':
        raise ValueError('Output must end in .zip')
    if output.exists():
        raise FileExistsError(f'Output already exists: {output}')
    output.parent.mkdir(parents=True, exist_ok=True)
    # Never modify latest or a running scraper's files. Check a frozen copy instead.
    inputs = [name for name in REQUIRED if name not in ('daily_summary.json', 'manual_review.json', 'fighter_profiles_report.json')]
    missing = [name for name in inputs if not (latest_dir / name).is_file()]
    if missing:
        raise ValueError('Missing canonical files: ' + ', '.join(missing))
    with tempfile.TemporaryDirectory(prefix='mmaf-bundle-', dir=output.parent) as temporary:
        work = Path(temporary)
        snapshot = work / 'latest'
        snapshot.mkdir()
        paths = [latest_dir / name for name in inputs]
        paths += [latest_dir / name for name in OPTIONAL if (latest_dir / name).is_file()]
        incomplete = latest_dir.parent / 'state' / 'incomplete_events.json'
        if incomplete.is_file():
            paths.append(incomplete)
        before = {path: _sha256(path) for path in paths}
        for path in paths:
            target = work / 'state' / path.name if path == incomplete else snapshot / path.name
            target.parent.mkdir(exist_ok=True)
            shutil.copyfile(path, target)
            if _sha256(target) != before[path]:
                raise ValueError('Scraper data changed during export. Try again after scraping finishes.')
        if any(_sha256(path) != digest for path, digest in before.items()):
            raise ValueError('Scraper data changed during export. Try again after scraping finishes.')
        profiles_document = json.loads((snapshot/'fighter_profiles.json').read_text())
        events_document = json.loads((snapshot/'results.json').read_text())
        run_manifest = json.loads((snapshot/'run_manifest.json').read_text())
        if not run_manifest.get('run_id') or events_document.get('run',{}).get('run_id') != run_manifest['run_id']:
            raise ValueError('Event results and manifest belong to different runs')
        del events_document
        atomic_write_json(snapshot/'fighter_profiles_report.json', build_profile_enrichment_report(profiles_document))
        readiness = build_readiness(profiles_document)
        if cycle:readiness['cycle']={'cycle_id':cycle['cycle_id'],'snapshot_id':cycle['snapshot_id']}
        enrichment = readiness.pop('source_enrichment')
        atomic_write_json(snapshot/'readiness_report.json', readiness)
        atomic_write_json(snapshot/'source_enrichment_queue.json', {'schema_version':'mmaf_source_enrichment_v1','contract_version':CONTRACT_VERSION,'generated_at':utc_now(),'source_profile_content_hash':readiness['profile_content_hash'],'cycle':readiness.get('cycle'),'items':enrichment})
        # The health gate reads its own frozen documents. Do not keep a second
        # materialized copy of the large profile and event snapshots alive.
        del profiles_document
        daily, _ = build_pre_import_artifacts(
            latest_dir=snapshot, state_dir=work / 'state', expected_pages=expected_pages,
            max_event_fetch_failures=max_event_fetch_failures,
            min_profile_success_rate=min_profile_success_rate,
        )
        if not daily.get('ready_for_import') or daily.get('blocking_issues'):
            raise ValueError('Import health check failed: ' + '; '.join(daily.get('blocking_issues') or ['not ready']))
        readiness['lanes']={'transport':{'ready':True},'results':{'ready':True,'events':daily['events_total'],'bouts':daily['bouts_total']},'profile_facts':{'candidates':readiness['profiles'].get('facts_ready',0)},'careers':{'candidates':readiness['profiles'].get('career_ready_candidates',0),'need_enrichment':readiness['profiles'].get('careers_needing_enrichment',0)}}
        atomic_write_json(snapshot/'readiness_report.json',readiness)
        full_readiness=readiness
        if base_receipt is not None:
            profiles_document=json.loads((snapshot/'fighter_profiles.json').read_text())
            events_document=json.loads((snapshot/'results.json').read_text())
            base_receipt=base_receipt.get('receipt',base_receipt)
            if base_receipt.get('format')!='mmaf_import_receipt_v1' or base_receipt.get('contract_version')!=1 or not all(base_receipt.get(k) for k in ['job_id','destination_id','site_url','bundle_hash','canonical_revision','input_indices']):
                raise ValueError('A completed destination import receipt is required for delta export')
            indices=base_receipt['input_indices']
            events_document['events']=[row for row in events_document['events'] if content_hash(row)!=indices.get('events',{}).get(row.get('source_event_id'))]
            from .import_readiness import normalize_profile
            profiles_document['profiles']=[row for row in profiles_document['profiles'] if content_hash(row)!=indices.get('profiles',{}).get(normalize_profile(row)['source_fighter_id'])]
            profiles_document['profiles_total']=len(profiles_document['profiles'])
            profiles_document['profiles_success']=sum(p.get('status')=='success' for p in profiles_document['profiles'])
            profiles_document['profiles_failed']=profiles_document['profiles_total']-profiles_document['profiles_success']
            atomic_write_json(snapshot/'results.json',events_document)
            atomic_write_json(snapshot/'fighter_profiles.json',profiles_document)
            atomic_write_json(snapshot/'fighter_profiles_report.json',build_profile_enrichment_report(profiles_document))
            readiness=build_readiness(profiles_document);readiness.pop('source_enrichment')
            if cycle:readiness['cycle']={'cycle_id':cycle['cycle_id'],'snapshot_id':cycle['snapshot_id']}
            readiness['full_snapshot_readiness']=full_readiness
            atomic_write_json(snapshot/'readiness_report.json',readiness)
            daily.update(events_total=len(events_document['events']),bouts_total=sum(len(e.get('bouts',[])) for e in events_document['events']),profiles_total=profiles_document['profiles_total'],profiles_success=profiles_document['profiles_success'],profiles_failed=profiles_document['profiles_failed'])
            daily['validation_scope']='full_snapshot_before_delta_selection'
        daily['readiness'] = readiness

        atomic_write_json(snapshot/'daily_summary.json', daily)
        files = {name: {'bytes': (snapshot/name).stat().st_size, 'sha256': _sha256(snapshot/name)}
                 for name in REQUIRED + OPTIONAL + DERIVED if (snapshot/name).is_file()}
        profiles_report = json.loads((snapshot/'fighter_profiles_report.json').read_text())
        manifest = {
            'format': 'mmaf-import-bundle', 'format_version': 1, 'exported_at': utc_now(),
            'contract_version': CONTRACT_VERSION,
            'source_runs': {'events': daily.get('run_id'), 'profiles': profiles_report.get('run_id')},
            'counts': {'events': daily.get('events_total'), 'bouts': daily.get('bouts_total'),
                       'profiles': profiles_report.get('profiles_total')},
            'files': files,
        }
        if base_receipt is not None:
            manifest['mode']='delta'
            manifest['delta_base']={k:v for k,v in base_receipt.items() if k!='input_indices'}
        if cycle:manifest['cycle']=cycle
        atomic_write_json(snapshot/'package_manifest.json', manifest)
        archive = work/'bundle.zip'
        with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as bundle:
            for path in sorted(snapshot.iterdir()):
                bundle.write(path, 'latest/' + path.name)
        # Same-filesystem hard link publishes the complete archive atomically and exclusively.
        os.link(archive, output)
    return {'ready_for_import': True, 'output': str(output), 'bytes': output.stat().st_size,
            'sha256': _sha256(output), 'counts': manifest['counts']}
