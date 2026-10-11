import importlib.util,pathlib,unittest
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('release_receipt',root/'ops/wait-release.py');receipt=importlib.util.module_from_spec(spec);spec.loader.exec_module(receipt)
class ReceiptTests(unittest.TestCase):
 def setUp(self):
  self.expected={'sequence':42,'commit':'a'*40,'sha256':'b'*64}
  self.current={'environment':'staging','sequence':42,'commit':'a'*40,'package_sha256':'b'*64,'state':'verified'}
 def test_exact_package_required(self):
  self.assertEqual(receipt.evaluate(self.current,self.expected),'verified')
  self.current['package_sha256']='c'*64;self.assertEqual(receipt.evaluate(self.current,self.expected),'identity_mismatch')
 def test_queued_and_failed_are_not_success(self):
  self.current['state']='waiting_for_checkpoint';self.assertEqual(receipt.evaluate(self.current,self.expected),'pending')
  for state in ['failed','rolled_back']:
   self.current['state']=state;self.assertEqual(receipt.evaluate(self.current,self.expected),'failed')
 def test_stale_superseded_and_wrong_environment(self):
  self.current['sequence']=41;self.assertEqual(receipt.evaluate(self.current,self.expected),'pending')
  self.current['sequence']=43;self.assertEqual(receipt.evaluate(self.current,self.expected),'superseded')
  self.current['environment']='production';self.assertEqual(receipt.evaluate(self.current,self.expected),'pending')
if __name__=='__main__':unittest.main()
