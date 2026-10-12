import importlib.util, json, tempfile, unittest, zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('runner',root/'ops/runner.py');runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
class PackageTests(unittest.TestCase):
 def test_integrity_and_traversal(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d)/'code.zip'
   with zipfile.ZipFile(p,'w') as z:z.writestr('../escape','bad');z.writestr('release.json',json.dumps({'format':1,'files':{'../escape':'bad'}}))
   with self.assertRaises(ValueError):runner.verify(p,runner.digest(p),Path(d)/'stage')
   with self.assertRaises(ValueError):runner.verify(p,'0'*64,Path(d)/'stage')
 def test_allowlist_complete_package(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d)/'code.zip';files={'wp-content/plugins/mma-future-data-engine/mma-future-data-engine.php':b'<?php','wp-content/themes/mma-future/asset-manifest.json':b'{}','ops/runtime-gate.php':b'<?php'}
   m={'format':1,'sequence':1,'commit':'a'*40,'files':{n:runner.hashlib.sha256(v).hexdigest() for n,v in files.items()}}
   with zipfile.ZipFile(p,'w') as z:
    for n,v in files.items():z.writestr(n,v)
    z.writestr('release.json',json.dumps(m))
   self.assertEqual(runner.verify(p,runner.digest(p),Path(d)/'stage'),m)
   self.assertTrue((Path(d)/'stage/ops/runtime-gate.php').is_file())
 def test_duplicate_path(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d)/'code.zip'
   with zipfile.ZipFile(p,'w') as z:z.writestr('release.json','{}');z.writestr('release.json','{}')
   with self.assertRaises(ValueError):runner.verify(p,runner.digest(p),Path(d)/'stage')
if __name__=='__main__':unittest.main()
