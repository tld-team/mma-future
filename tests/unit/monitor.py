"""Independent monitor fault tests; transport is stubbed, no messages are sent."""
import importlib.util,json,tempfile,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('independent_monitor',ROOT/'ops/monitor.py');monitor=importlib.util.module_from_spec(spec);spec.loader.exec_module(monitor)

class MonitorTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.config=self.root/'config.json'
  self.config.write_text(json.dumps({'private_root':str(self.root/'private'),'wp_root':str(self.root/'public'),'write_lock':str(self.root/'private/worker.lock'),'php':'php','wp_cli':'wp','url':'https://fixture.test','environment':'staging'}));self.worker=monitor.Runner(self.config)
 def tearDown(self):self.temp.cleanup()
 def test_expected_checkpoint_does_not_raise_bootstrap_alert(self):
  (self.worker.root/'writes-paused.flag').touch()
  with patch.object(monitor.Runner,'wp',side_effect=AssertionError('WP must not be loaded')),patch.object(monitor,'notify') as notice:monitor.run(self.config);notice.assert_not_called()
  self.assertEqual(json.loads((self.worker.root/'monitor-status.json').read_text())['state'],'protected_checkpoint')
 def test_stalled_and_failed_release_alert_without_wp(self):
  (self.worker.root/'maintenance.flag').touch();old=time.time()-601
  import os;os.utime(self.worker.root/'maintenance.flag',(old,old))
  with patch.object(monitor,'notify') as notice:monitor.run(self.config);self.assertEqual(notice.call_args.args[1],'release')
  (self.worker.root/'apply-state.json').write_text(json.dumps({'state':'failed','sequence':17,'updated_at':time.time()}))
  with patch.object(monitor,'notify') as notice:monitor.run(self.config);self.assertIn('#17',notice.call_args.args[2])
 def test_bootstrap_failure_reaches_independent_transport(self):
  with patch.object(monitor.Runner,'wp',side_effect=RuntimeError('bootstrap')),patch.object(monitor,'notify') as notice:
   with self.assertRaises(RuntimeError):monitor.run(self.config)
   self.assertEqual(notice.call_args.args[1],'bootstrap')
 def test_refreshed_pause_flag_does_not_mask_stalled_apply(self):
  (self.worker.root/'maintenance.flag').touch()
  (self.worker.root/'apply-state.json').write_text(json.dumps({'state':'applying','sequence':18,'updated_at':time.time()-601}))
  with patch.object(monitor.Runner,'wp',side_effect=AssertionError('No WP during maintenance')),patch.object(monitor,'notify') as notice:
   monitor.run(self.config);self.assertIn('without progress',notice.call_args.args[2])
 def test_readonly_recovery_is_visible_without_wp(self):
  (self.worker.root/'recovery-readonly.flag').touch()
  with patch.object(monitor.Runner,'wp',side_effect=AssertionError('No writes in recovery')),patch.object(monitor,'notify') as notice:
   monitor.run(self.config);self.assertEqual(notice.call_args.args[1],'recovery')
 def test_quota_unknown_is_not_assumed_unlimited(self):
  with patch.object(monitor.Runner,'wp',return_value='{}'):monitor.run(self.config)
  result=json.loads((self.worker.root/'capacity-status.json').read_text());self.assertFalse(result['ok']);self.assertIsNone(result['account_inode_free'])
 def test_alert_transport_deduplicates_and_retries_failure(self):
  with patch.object(monitor.subprocess,'run') as transport:
   transport.return_value.returncode=1;monitor.notify(self.worker,'test','Same reason');monitor.notify(self.worker,'test','Same reason');self.assertEqual(transport.call_count,2)
   transport.return_value.returncode=0;monitor.notify(self.worker,'test','Same reason');monitor.notify(self.worker,'test','Same reason');self.assertEqual(transport.call_count,3)
   monitor.notify(self.worker,'test','Changed reason');self.assertEqual(transport.call_count,4)
   self.worker.c['environment']='local';monitor.notify(self.worker,'local','No external email');self.assertEqual(transport.call_count,4)

if __name__=='__main__':unittest.main()
