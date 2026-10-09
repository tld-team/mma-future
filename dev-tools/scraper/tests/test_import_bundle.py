import hashlib
import json
import zipfile
import pytest
from test_pre_import import _write_complete_latest
from mma_future_scraper.import_bundle import export_import_bundle


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
