"""Private loopback probes must never weaken public TLS or disclose their token."""
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'ops'))
from runner import Runner

class HttpTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();root=Path(self.tmp.name)
        config=root/'config.json'
        config.write_text(json.dumps({'private_root':str(root/'private'),'wp_root':str(root/'public'),'write_lock':str(root/'private/worker.lock'),'php':'php','wp_cli':'wp','url':'https://fixture.test','probe_host':'fixture.test','probe_address':'127.0.0.1'}))
        self.runner=Runner(config);self.css=b'fixture css';self.manifest={'files':{'wp-content/themes/mma-future/assets/dist/css/output.css':hashlib.sha256(self.css).hexdigest()}}
    def tearDown(self):self.tmp.cleanup()
    def run_checks(self,status='503',tls_exit=0):
        commands=[]
        def run(command,**kwargs):
            commands.append(command)
            return subprocess.CompletedProcess(command,0 if '--config' in command else tls_exit,stdout='200' if '--config' in command else status,stderr='')
        with patch('runner.subprocess.run',side_effect=run),patch('runner.subprocess.check_output',return_value=self.css) as output:
            self.runner.http_check(self.manifest)
            commands.extend(call.args[0] for call in output.call_args_list)
        return commands
    def test_https_is_loopback_only_and_public_maintenance_verifies_tls(self):
        (self.runner.root/'maintenance.flag').touch()
        commands=self.run_checks()
        private=[c for c in commands if '--config' in c];public=[c for c in commands if '--config' not in c]
        self.assertEqual(len(private),4);self.assertEqual(len(public),1)
        for command in private:
            self.assertIn('fixture.test:443:127.0.0.1',command);self.assertTrue(command[-1].startswith('https://fixture.test/'))
            self.assertEqual(command[command.index('--interface')+1],'127.0.0.1');self.assertIn('--noproxy',command)
        self.assertEqual(public[0][-1],'https://fixture.test/');self.assertNotIn('--config',public[0])
        self.assertFalse(any('--insecure' in c or '-k' in c or '--location' in c for c in commands))
        self.assertFalse((self.runner.root/'probe.token').exists());self.assertFalse((self.runner.root/'probe.curl').exists())
    def test_reject_public_private_probe_and_unsafe_urls_before_requests(self):
        for change in [{'probe_address':'203.0.113.1'},{'probe_destination':'0.0.0.0'},{'probe_destination':'224.0.0.1'},{'url':'ftp://fixture.test'},{'url':'https://other.test'},{'url':'https://user:password@fixture.test'}]:
            original=dict(self.runner.c);self.runner.c.update(change)
            with patch('runner.subprocess.run') as run,self.assertRaises(ValueError):self.runner.http_check(self.manifest)
            run.assert_not_called();self.runner.c=original
    def test_bad_public_tls_or_missing_maintenance_fails_and_cleans_token(self):
        (self.runner.root/'maintenance.flag').touch()
        for status,exit_code in [('200',0),('000',60)]:
            with self.assertRaisesRegex(ValueError,'Public TLS or maintenance'):self.run_checks(status,exit_code)
            self.assertFalse((self.runner.root/'probe.token').exists());self.assertFalse((self.runner.root/'probe.curl').exists())
    def test_shared_local_destination_keeps_loopback_source_and_tls(self):
        self.runner.c['probe_destination']='203.0.113.1'
        with patch('runner.socket.socket') as local_socket:
            commands=self.run_checks('200')
            local_socket.return_value.__enter__.return_value.bind.assert_called_once_with(('203.0.113.1',0))
        for command in commands:
            if '--config' in command:
                self.assertIn('fixture.test:443:203.0.113.1',command)
                self.assertEqual(command[command.index('--interface')+1],'127.0.0.1')
    def test_nonlocal_destination_fails_before_private_token_or_request(self):
        self.runner.c['probe_destination']='203.0.113.1'
        with patch('runner.socket.socket') as local_socket,patch('runner.subprocess.run') as run:
            local_socket.return_value.__enter__.return_value.bind.side_effect=OSError('not local')
            with self.assertRaisesRegex(ValueError,'locally assigned'):self.runner.http_check(self.manifest)
            run.assert_not_called()
        self.assertFalse((self.runner.root/'probe.token').exists())

if __name__=='__main__':unittest.main()
