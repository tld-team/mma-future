"""Exercise supported import operations without a live server or database."""
import contextlib, importlib.util, json, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch

root = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / 'ops'))
import supervisor
spec = importlib.util.spec_from_file_location('verify_import_backup', root / 'ops/verify-import-backup.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class FakeRunner:
    schema = '1.10.0'
    calls = []
    root = None

    def __init__(self, config):
        self.root = Path(config).parent

    @contextlib.contextmanager
    def lock(self, path):
        yield

    def wp(self, *args, **kwargs):
        self.calls.append(args)
        if args[:3] == ('option', 'get', 'mmaf_db_version'):
            return self.schema
        if args[:2] == ('mmaf', 'background'):
            return json.dumps({'worked': True, 'units': 47, 'elapsed_seconds': 12, 'peak_memory_mb': 62, 'db_queries': 1250, 'stop_reason': 'idle'})
        return json.dumps({'worked': False})


class ImportOperationsTests(unittest.TestCase):
    def test_supervisor_selects_one_bounded_worker_and_keeps_metrics(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(supervisor, 'Runner', FakeRunner):
            FakeRunner.schema = '1.10.0'; FakeRunner.calls = []
            supervisor.run(Path(temp) / 'config.json')
            work = [c for c in FakeRunner.calls if c[0] == 'mmaf']
            self.assertEqual(work, [('mmaf', 'background', 'work', '--seconds=35', '--memory-mb=256')])
            heartbeat = json.loads((Path(temp) / 'supervisor-heartbeat.json').read_text())
            self.assertEqual(heartbeat['units'], 47)
            self.assertEqual(heartbeat['db_queries'], 1250)
            self.assertEqual(heartbeat['state'], 'idle')

    def test_old_schema_keeps_compatible_workers_and_pause_stops_dispatch(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(supervisor, 'Runner', FakeRunner):
            FakeRunner.schema = '1.9.0'; FakeRunner.calls = []
            supervisor.run(Path(temp) / 'config.json')
            self.assertEqual([c[:3] for c in FakeRunner.calls if c[0] == 'mmaf'], [('mmaf', 'import-job', 'work'), ('mmaf', 'operations', 'work'), ('mmaf', 'data-process', 'work')])
            (Path(temp) / 'writes-paused.flag').write_text('fixture')
            FakeRunner.calls = []
            supervisor.run(Path(temp) / 'config.json')
            self.assertEqual(FakeRunner.calls, [])

    def test_backup_wrapper_binds_identity_and_delegates_actual_restore(self):
        with tempfile.TemporaryDirectory() as temp:
            private = Path(temp); folder = private / 'backups/import-7'; folder.mkdir(parents=True)
            manifest = folder / 'manifest.json'
            data = {'import_job_id': 7, 'reason': 'import', 'path': str(folder / 'database.sql.gz')}
            manifest.write_text(json.dumps(data))
            class FakeRestore:
                def __init__(self, config):
                    self.runner = FakeRunner(config)
                def verify(self, path, destination):
                    self.assertion = (path, destination)
                    return {'ok': True, 'tables': 56}
            with patch.object(backup, 'Restore', FakeRestore):
                self.assertTrue(backup.verify(private / 'config.json', 7, private / 'media')['ok'])
                data['path'] = str(private / 'foreign/database.sql.gz'); manifest.write_text(json.dumps(data))
                with self.assertRaisesRegex(ValueError, 'private import'):
                    backup.verify(private / 'config.json', 7, private / 'media')


if __name__ == '__main__':
    unittest.main()
