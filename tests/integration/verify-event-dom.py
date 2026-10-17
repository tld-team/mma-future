"""Real saved event DOM -> JS extraction -> Python parser; entirely offline."""
import json,sys,io
from contextlib import redirect_stdout
from pathlib import Path
root=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(root/'dev-tools/scraper/src'),str(root/'dev-tools/scraper/tests')]
from playwright.sync_api import sync_playwright
from mma_future_scraper.event_coverage import ROWS_JS
from test_event_coverage import parse,FIXTURES
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True)
 try:
  for dom in FIXTURES.glob('*.dom.json'):
   saved=json.loads(dom.read_text());name=dom.name.removesuffix('.dom.json');expected=json.loads((FIXTURES/(name+'.json')).read_text())
   for variant in ['all','fullsize','compact']:
    page=browser.new_page();page.set_content(saved['html'])
    if variant!='all':page.evaluate("variant=>document.querySelectorAll('[data-bout-wrapper]').forEach(e=>{if(!e.id.startsWith(variant==='fullsize'?'boutFullsize':'boutCompact'))e.remove();})",variant)
    rows=page.locator('#sectionFightCard').evaluate(ROWS_JS,{'bout':'a[href*="/fightcenter/bouts/"]','fighter':'a[href*="/fightcenter/fighters/"]'})
    with redirect_stdout(io.StringIO()): event=parse(expected,rows)
    assert len(event['bouts'])==10,(name,variant,len(event['bouts']))
    assert event['event_coverage']['status']=='complete',(name,variant,event['event_coverage'])
    page.close();print('PASS: actual saved '+name+' '+variant+' DOM yields 10 professional MMA bouts, 3 explained exclusions')
 finally:browser.close()
