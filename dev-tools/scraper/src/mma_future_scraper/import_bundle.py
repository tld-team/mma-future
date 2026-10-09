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
from .storage import atomic_write_json
from .utils import utc_now

REQUIRED = (
    'results.json', 'daily_summary.json', 'manual_review.json', 'run_manifest.json',
    'changes.json', 'fighter_profiles.json', 'fighter_profiles_report.json',
)
OPTIONAL = ('state_report.json',)


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
) -> dict:
    output = Path(output).expanduser().resolve()
    latest_dir = Path(latest_dir).resolve()
    if output.suffix.lower() != '.zip':
        raise ValueError('Output must end in .zip')
    if output.exists():
        raise FileExistsError(f'Output already exists: {output}')
    output.parent.mkdir(parents=True, exist_ok=True)
    # Never modify latest or a running scraper's files. Check a frozen copy instead.
    inputs = [name for name in REQUIRED if name not in ('daily_summary.json', 'manual_review.json')]
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
        daily, _ = build_pre_import_artifacts(
            latest_dir=snapshot, state_dir=work / 'state', expected_pages=expected_pages,
            max_event_fetch_failures=max_event_fetch_failures,
            min_profile_success_rate=min_profile_success_rate,
        )
        if not daily.get('ready_for_import') or daily.get('blocking_issues'):
            raise ValueError('Import health check failed: ' + '; '.join(daily.get('blocking_issues') or ['not ready']))
        files = {name: {'bytes': (snapshot/name).stat().st_size, 'sha256': _sha256(snapshot/name)}
                 for name in REQUIRED + OPTIONAL if (snapshot/name).is_file()}
        profiles_report = json.loads((snapshot/'fighter_profiles_report.json').read_text())
        manifest = {
            'format': 'mmaf-import-bundle', 'format_version': 1, 'exported_at': utc_now(),
            'source_runs': {'events': daily.get('run_id'), 'profiles': profiles_report.get('run_id')},
            'counts': {'events': daily.get('events_total'), 'bouts': daily.get('bouts_total'),
                       'profiles': profiles_report.get('profiles_total')},
            'files': files,
        }
        atomic_write_json(snapshot/'package_manifest.json', manifest)
        archive = work/'bundle.zip'
        with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as bundle:
            for path in sorted(snapshot.iterdir()):
                bundle.write(path, 'latest/' + path.name)
        # Same-filesystem hard link publishes the complete archive atomically and exclusively.
        os.link(archive, output)
    return {'ready_for_import': True, 'output': str(output), 'bytes': output.stat().st_size,
            'sha256': _sha256(output), 'counts': manifest['counts']}
