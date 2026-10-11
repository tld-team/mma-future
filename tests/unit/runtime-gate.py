"""Exercise the actual early PHP gate and OS locks, without WordPress or private data."""
import fcntl, json, os, shutil, subprocess, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP=os.environ.get('MMAF_TEST_PHP') or shutil.which('php')

@unittest.skipUnless(PHP,'PHP runtime required')
class GateTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.root=Path(self.temporary.name)
        self.script=self.root/'probe.php'
        self.script.write_text('<?php\ndefine("ABSPATH",__DIR__."/");\n$c=json_decode($argv[1],true);if($c["cli"]??false){define("WP_CLI",true);}\n$_SERVER=$c["server"]??[];$_GET=$c["get"]??[];\nrequire '+json.dumps(str(ROOT/'ops/runtime-gate.php'))+';\necho "READY";fflush(STDOUT);if($c["wait"]??false){fgets(STDIN);}\n')
        self.env=dict(os.environ,MMAF_OPERATIONS_DIR=str(self.root),MMAF_WRITE_LOCK=str(self.root/'worker.lock'))
    def tearDown(self):self.temporary.cleanup()
    def run_probe(self,c,**kwargs):
        return subprocess.run([PHP,str(self.script),json.dumps(c)],env=kwargs.pop('env',self.env),capture_output=True,text=True,**kwargs)
    def test_paused_writes_and_reads(self):
        (self.root/'writes-paused.flag').touch()
        read=self.run_probe({'server':{'REQUEST_METHOD':'GET'}});self.assertEqual(read.stdout,'READY')
        post=self.run_probe({'server':{'REQUEST_METHOD':'POST'}});self.assertNotIn('READY',post.stdout)
        admin=self.run_probe({'server':{'REQUEST_METHOD':'GET','REQUEST_URI':'/wp-admin/admin.php'},'get':{'action':'save'}});self.assertNotIn('READY',admin.stdout)
        cli=self.run_probe({'cli':True});self.assertEqual(cli.returncode,75)
    def test_maintenance_probe_needs_token_and_loopback(self):
        (self.root/'maintenance.flag').touch();token='a'*64;(self.root/'probe.token').write_text(token)
        for remote,header,allowed in [('127.0.0.1',token,True),('::1',token,True),('127.0.0.1','b'*64,False),('203.0.113.1',token,False)]:
            result=self.run_probe({'server':{'REQUEST_METHOD':'GET','REMOTE_ADDR':remote,'HTTP_X_MMAF_RELEASE_PROBE':header}})
            self.assertEqual(result.stdout=='READY',allowed)
    def test_runner_descriptor_requires_matching_locked_inode(self):
        (self.root/'maintenance.flag').touch()
        with (self.root/'worker.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX);env=dict(self.env,MMAF_DEPLOY_WRITE_FD=str(lock.fileno()))
            result=self.run_probe({'cli':True},env=env,pass_fds=(lock.fileno(),));self.assertEqual(result.stdout,'READY')
        with (self.root/'unrelated.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX);env=dict(self.env,MMAF_DEPLOY_WRITE_FD=str(lock.fileno()))
            result=self.run_probe({'cli':True},env=env,pass_fds=(lock.fileno(),));self.assertEqual(result.returncode,75)
    def test_request_and_writer_locks_last_until_exit(self):
        p=subprocess.Popen([PHP,str(self.script),json.dumps({'cli':True,'wait':True})],env=self.env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            self.assertEqual(p.stdout.read(5),b'READY')
            for name in ['writers.lock','requests.lock']:
                with (self.root/name).open('a') as lock:
                    with self.assertRaises(BlockingIOError):fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            p.stdin.write(b'finish\n');p.stdin.flush();self.assertEqual(p.wait(timeout=5),0)
            for name in ['writers.lock','requests.lock']:
                with (self.root/name).open('a') as lock:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        finally:
            if p.poll() is None:p.kill();p.wait()
            p.stdin.close();p.stdout.close();p.stderr.close()
    def test_readonly_fallback_serves_reads_and_blocks_writes(self):
        (self.root/'recovery-readonly.flag').touch()
        self.assertEqual(self.run_probe({'server':{'REQUEST_METHOD':'GET'}}).stdout,'READY')
        self.assertNotIn('READY',self.run_probe({'server':{'REQUEST_METHOD':'POST'}}).stdout)
        self.assertEqual(self.run_probe({'cli':True}).returncode,75)

if __name__=='__main__':unittest.main()
