#!/usr/bin/env python3
"""CI success requires the public receipt for this exact applied package."""
import argparse,json,time,urllib.error,urllib.parse,urllib.request

def evaluate(receipt,expected):
    if receipt.get('environment')!='staging':return 'pending'
    sequence=receipt.get('sequence')
    if type(sequence) is not int:return 'pending'
    if sequence>expected['sequence']:return 'superseded'
    if sequence!=expected['sequence']:return 'pending'
    if receipt.get('commit')!=expected['commit'] or receipt.get('package_sha256')!=expected['sha256']:return 'identity_mismatch'
    if receipt.get('state')=='verified':return 'verified'
    if receipt.get('state') in {'failed','rolled_back'}:return 'failed'
    return 'pending'

def wait(expected,url,timeout=1800,interval=10):
    parsed=urllib.parse.urlparse(url)
    if parsed.scheme!='https' or parsed.username or parsed.password:raise ValueError('A public HTTPS receipt is required')
    deadline=time.monotonic()+timeout;last=None
    while time.monotonic()<deadline:
        try:
            separator='&' if parsed.query else '?'
            request=urllib.request.Request(url+separator+'check='+str(int(time.time())),headers={'Cache-Control':'no-cache','Accept':'application/json'})
            with urllib.request.urlopen(request,timeout=20) as response:
                raw=response.read(65537)
                if len(raw)>65536:raise ValueError('Receipt exceeds byte limit')
                result=evaluate(json.loads(raw),expected)
        except (urllib.error.URLError,TimeoutError,ValueError):result='pending'
        if result!=last:print('Staging receipt: '+result,flush=True);last=result
        if result=='verified':return
        if result in {'failed','superseded','identity_mismatch'}:raise RuntimeError('Staging did not verify this package: '+result)
        time.sleep(min(interval,max(0,deadline-time.monotonic())))
    raise TimeoutError('Package uploaded/queued; verified application was not confirmed within the deadline')

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--receipt',required=True);p.add_argument('--url',required=True);p.add_argument('--timeout',type=int,default=1800);a=p.parse_args()
    with open(a.receipt) as source:expected=json.load(source)
    wait(expected,a.url,a.timeout)
