import hashlib
import json
import zipfile
import pytest
from test_pre_import import _write_complete_latest
from mma_future_scraper.import_bundle import export_import_bundle
from mma_future_scraper.ufc_roster import build_document


def test_export_freezes_inputs_and_verifies_every_file(tmp_path):
    latest = tmp_path/'data'/'latest'
    _write_complete_latest(latest, latest.parent/'state')
    before = {p.name: p.read_bytes() for p in latest.iterdir()}
    output = tmp_path/'import.zip'
    result = export_import_bundle(output, latest_dir=latest, expected_pages=15)
    assert result['ready_for_import']
    assert {p.name: p.read_bytes() for p in latest.iterdir()} == before
    with zipfile.ZipFile(output) as archive:
        manifest = json.loads(archive.read('latest/package_manifest.json'))
        assert manifest['source_runs'] == {'events':'run_1','profiles':'profiles_1'}
        for name, info in manifest['files'].items():
            data=archive.read('latest/'+name)
            assert info=={'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}
    with pytest.raises(FileExistsError):
        export_import_bundle(output, latest_dir=latest)


def test_unhealthy_export_never_publishes_zip(tmp_path):
    latest=tmp_path/'latest'
    _write_complete_latest(latest,tmp_path/'state')
    with pytest.raises(ValueError,match='health check failed'):
        export_import_bundle(tmp_path/'bad.zip',latest_dir=latest,expected_pages=99)
    assert not (tmp_path/'bad.zip').exists()


def test_regular_bundle_includes_optional_ufc_roster(tmp_path):
    latest = tmp_path/'data'/'latest'
    _write_complete_latest(latest, latest.parent/'state')
    roster = build_document(
        [{"name": "Current Fighter", "url": "/fighters/current"}],
        [{"name": "Former Fighter", "url": "/fighters/former"}],
        [{"name": "Current Fighter", "url": "/fighters/current", "text": "added"}],
        run_id="ufc-regular-test",
        scraped_at="2026-10-20T10:00:00Z",
    )
    (latest/'ufc_roster.json').write_text(json.dumps(roster), encoding='utf-8')

    output = tmp_path/'regular-with-ufc.zip'
    export_import_bundle(output, latest_dir=latest, expected_pages=15)

    with zipfile.ZipFile(output) as archive:
        manifest = json.loads(archive.read('latest/package_manifest.json'))
        payload = archive.read('latest/ufc_roster.json')
        assert manifest['files']['ufc_roster.json'] == {
            'bytes': len(payload),
            'sha256': hashlib.sha256(payload).hexdigest(),
        }
