"""Real OS-lock contention: human POST waits, workers yield, timeouts stay bounded."""
import fcntl,json,os,shutil,subprocess,tempfile,time,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP=os.environ.get('MMAF_TEST_PHP') or shutil.which('php')

@unittest.skipUnless(PHP,'PHP runtime required')
class MutexTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.script=self.root/'probe.php'
        source=ROOT/'wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport'
        self.script.write_text('<?php $c=json_decode($argv[1],true);$_SERVER["REQUEST_METHOD"]=$c["method"];if($c["cli"]){define("WP_CLI",true);}require '+json.dumps(str(source/'ImportException.php'))+';require '+json.dumps(str(source/'Mutex.php'))+';try{\\MMAF\\DataEngine\\Services\\RegularImport\\Mutex::run(static function(){echo "LOCKED";});}catch(\\MMAF\\DataEngine\\Services\\RegularImport\\ImportException $e){echo "BUSY";}')
        self.env=dict(os.environ,MMAF_WRITE_LOCK=str(self.root/'worker.lock'))
    def tearDown(self):self.tmp.cleanup()
    def test_post_waits_for_current_unit(self):
        with (self.root/'worker.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            p=subprocess.Popen([PHP,str(self.script),json.dumps({'method':'POST','cli':False})],env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            time.sleep(.15);self.assertIsNone(p.poll());fcntl.flock(lock,fcntl.LOCK_UN)
            out,error=p.communicate(timeout=2);self.assertEqual(p.returncode,0,error);self.assertEqual(out,b'LOCKED')
    def test_system_worker_does_not_wait(self):
        with (self.root/'worker.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX);start=time.monotonic()
            p=subprocess.run([PHP,str(self.script),json.dumps({'method':'POST','cli':True})],env=self.env,capture_output=True,timeout=2)
            self.assertEqual(p.stdout,b'BUSY');self.assertLess(time.monotonic()-start,2)
    def test_get_does_not_wait(self):
        with (self.root/'worker.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            p=subprocess.run([PHP,str(self.script),json.dumps({'method':'GET','cli':False})],env=self.env,capture_output=True,timeout=2)
            self.assertEqual(p.stdout,b'BUSY')
    def test_post_timeout_preserves_other_lock(self):
        with (self.root/'worker.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX);start=time.monotonic()
            p=subprocess.run([PHP,str(self.script),json.dumps({'method':'POST','cli':False})],env=self.env,capture_output=True,timeout=20)
            self.assertEqual(p.stdout,b'BUSY');self.assertGreaterEqual(time.monotonic()-start,15);self.assertLess(time.monotonic()-start,17)
    def test_system_worker_yields_to_waiting_post(self):
        with (self.root/'worker.lock.interactive').open('a') as intent:
            fcntl.flock(intent,fcntl.LOCK_SH)
            p=subprocess.run([PHP,str(self.script),json.dumps({'method':'GET','cli':True})],env=self.env,capture_output=True,timeout=2)
            self.assertEqual(p.stdout,b'BUSY')
        p=subprocess.run([PHP,str(self.script),json.dumps({'method':'GET','cli':True})],env=self.env,capture_output=True,timeout=2)
        self.assertEqual(p.stdout,b'LOCKED')
    def test_post_wins_against_backlogged_worker(self):
        # A worker with a backlog releases and re-takes the lock back to back.
        source=ROOT/'wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport'
        worker=self.root/'worker.php'
        worker.write_text('<?php define("WP_CLI",true);require '+json.dumps(str(source/'ImportException.php'))+';require '+json.dumps(str(source/'Mutex.php'))+';$end=microtime(true)+8;$units=0;try{while(microtime(true)<$end){\\MMAF\\DataEngine\\Services\\RegularImport\\Mutex::run(static function(){usleep(300000);});++$units;}echo "DRAINED";}catch(\\MMAF\\DataEngine\\Services\\RegularImport\\ImportException $e){echo "YIELDED";}')
        backlog=subprocess.Popen([PHP,str(worker)],env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            time.sleep(.5);start=time.monotonic()
            p=subprocess.run([PHP,str(self.script),json.dumps({'method':'POST','cli':False})],env=self.env,capture_output=True,timeout=20)
            self.assertEqual(p.stdout,b'LOCKED',p.stderr);self.assertLess(time.monotonic()-start,2)
            out,error=backlog.communicate(timeout=10);self.assertEqual(out,b'YIELDED',error)
        finally:
            if backlog.poll() is None:backlog.kill()
if __name__=='__main__':unittest.main()
