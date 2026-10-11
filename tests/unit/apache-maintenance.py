"""Run the actual pre-cache rules on isolated Apache; PHP token validation is tested separately."""
import os,pwd,grp,socket,subprocess,tempfile,time,unittest,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
HTTPD=os.environ.get('MMAF_TEST_HTTPD','/usr/sbin/httpd');MODULES=Path(os.environ.get('MMAF_TEST_APACHE_MODULES','/usr/libexec/apache2'))
@unittest.skipUnless(Path(HTTPD).is_file() and (MODULES/'mod_mpm_prefork.so').is_file(),'Standalone Apache module runtime required')
class ApacheTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.public=self.root/'public';self.public.mkdir();(self.public/'index.php').write_text('APPLICATION');(self.public/'cached.html').write_text('PAGE CACHE');(self.public/'.well-known').mkdir();(self.public/'.well-known/mmaf-release.json').write_text('{"state":"verified"}')
  (self.public/'.htaccess').write_text((ROOT/'ops/apache-maintenance.conf').read_text()+'\nRewriteRule ^all-rankings/?$ cached.html [END]\n')
  with socket.socket() as s:s.bind(('127.0.0.1',0));self.port=s.getsockname()[1]
  self.config=self.root/'httpd.conf';self.config.write_text('\n'.join(['ServerRoot "'+str(self.root)+'"','Listen 127.0.0.1:'+str(self.port),'ServerName localhost','PidFile "'+str(self.root/'pid')+'"','ErrorLog "'+str(self.root/'error.log')+'"',*[f'LoadModule {name}_module "{MODULES}/mod_{name}.so"' for name in ['mpm_prefork','unixd','authz_core','dir','rewrite','headers']], 'User '+pwd.getpwuid(os.getuid()).pw_name,'Group '+grp.getgrgid(os.getgid()).gr_name,'DocumentRoot "'+str(self.public)+'"','DirectoryIndex index.php','<Directory "'+str(self.public)+'">','AllowOverride All','Require all granted','</Directory>']))
  self.process=subprocess.Popen([HTTPD,'-f',str(self.config),'-DFOREGROUND'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True)
  for n in range(50):
   if self.process.poll() is not None:raise RuntimeError(self.process.stderr.read().decode())
   try:self.get('/');break
   except OSError:time.sleep(.02)
 def tearDown(self):
  self.process.terminate();self.process.wait(timeout=5);self.process.stdout.close();self.process.stderr.close();self.temp.cleanup()
 def get(self,path,token=None):
  request=urllib.request.Request('http://127.0.0.1:'+str(self.port)+path,headers={'X-MMAF-Release-Probe':token} if token else {})
  try:response=urllib.request.urlopen(request,timeout=2)
  except urllib.error.HTTPError as error:response=error
  with response:return response.status,dict(response.headers),response.read().decode()
 def test_maintenance_precedes_cache_and_receipt_remains_available(self):
  self.assertEqual(self.get('/all-rankings/')[2],'PAGE CACHE');(self.public/'.mmaf-maintenance').touch()
  code,headers,body=self.get('/all-rankings/');self.assertEqual(code,503);self.assertEqual(headers.get('Retry-After'),'60');self.assertEqual(headers.get('Cache-Control'),'no-store');self.assertNotIn('PAGE CACHE',body)
  self.assertEqual(self.get('/.well-known/mmaf-release.json')[0],200)
  self.assertEqual(self.get('/.mmaf-maintenance')[0],403)
 def test_loopback_probe_bypasses_page_cache(self):
  (self.public/'.mmaf-maintenance').touch();code,_,body=self.get('/all-rankings/','a'*64);self.assertEqual(code,200);self.assertEqual(body,'APPLICATION')
  self.assertEqual(self.get('/all-rankings/','invalid')[0],503)

if __name__=='__main__':unittest.main()
