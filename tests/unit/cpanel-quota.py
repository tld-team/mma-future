"""cPanel quota evidence mapping; uapi is stubbed, nothing touches a real account."""
import importlib.util,json,os,stat,subprocess,sys,tempfile,time,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'ops'))
spec=importlib.util.spec_from_file_location('cpanel_quota',ROOT/'ops/cpanel-quota.py');quota=importlib.util.module_from_spec(spec);spec.loader.exec_module(quota)
from storage import capacity

# Shape returned by the staging account on 2026-11-16 (disk shown as ∞ in cPanel Statistics).
STAGING={'apiversion':3,'module':'Quota','func':'get_quota_info','result':{'status':1,'messages':None,'metadata':{},'errors':None,'warnings':None,
 'data':{'under_quota_overall':'1','inodes_remain':326209,'inodes_used':473791,'megabyte_limit':'0.00','inode_limit':800000,'megabytes_remain':'0.00','under_inode_limit':'1','megabytes_used':31320.99}}}

def response(**data):
 value=json.loads(json.dumps(STAGING));value['result']['data'].update(data);return value

class CpanelQuotaTests(unittest.TestCase):
 def test_staging_shape_maps_unlimited_disk_and_counted_inodes(self):
  out=quota.evidence(STAGING,now=123.0)
  self.assertEqual(out,{'source':'cpanel_uapi','checked_at':123.0,'bytes_unlimited':True,'limit_inodes':800000,'used_inodes':473791})
 def test_numeric_limits_become_bytes_and_inodes(self):
  out=quota.evidence(response(megabyte_limit='51200.00',megabytes_used=1024.5,inode_limit='0'))
  self.assertEqual((out['limit_bytes'],out['used_bytes']),(51200*1024**2,int(1024.5*1024**2)));self.assertTrue(out['inodes_unlimited'])
  self.assertNotIn('bytes_unlimited',out)
 def test_missing_or_invalid_fields_fail_closed(self):
  broken=response();del broken['result']['data']['inode_limit']
  for value in (broken,response(megabytes_used=None),response(inode_limit='-1'),response(megabyte_limit='nan'),response(megabyte_limit=True)):
   with self.assertRaises(ValueError):quota.evidence(value)
 def test_failed_request_fails_closed(self):
  for value in ({'result':{'status':0,'data':STAGING['result']['data']}},{'result':{'status':1,'errors':['denied'],'data':{}}},[],{}):
   with self.assertRaises(ValueError):quota.evidence(value)
 def test_evidence_is_fresh_and_accepted_by_capacity_check(self):
  with tempfile.TemporaryDirectory() as root:
   result=capacity(root,quota.evidence(STAGING),additional=1024,additional_inodes=10)
   self.assertTrue(result['ok']);self.assertEqual(result['account_inode_free'],800000-473791)
 def test_command_prints_json_or_nothing(self):
  with tempfile.TemporaryDirectory() as folder:
   stub=Path(folder)/'uapi';stub.write_text('#!/bin/sh\nprintf %s '+"'"+json.dumps(STAGING)+"'"+'\n');stub.chmod(stub.stat().st_mode|stat.S_IXUSR)
   ok=subprocess.run([sys.executable,str(ROOT/'ops/cpanel-quota.py'),str(stub)],capture_output=True,text=True)
   self.assertEqual(ok.returncode,0);self.assertTrue(json.loads(ok.stdout)['bytes_unlimited'])
   stub.write_text('#!/bin/sh\nexit 3\n')
   failed=subprocess.run([sys.executable,str(ROOT/'ops/cpanel-quota.py'),str(stub)],capture_output=True,text=True)
   self.assertEqual((failed.returncode,failed.stdout),(1,''))
   missing=subprocess.run([sys.executable,str(ROOT/'ops/cpanel-quota.py'),str(Path(folder)/'absent')],capture_output=True,text=True)
   self.assertEqual((missing.returncode,missing.stdout),(1,''))

if __name__=='__main__':unittest.main()
