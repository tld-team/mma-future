import json
from copy import deepcopy
from datetime import datetime, timezone, timedelta
from pathlib import Path
import pytest
import mma_future_scraper.fighter_profiles as fighter_profiles
from mma_future_scraper.fighter_profiles import parse_profile_snapshot
from mma_future_scraper.scoring_evidence import needs_refresh
from mma_future_scraper.import_readiness import build_readiness

FIXTURE = Path(__file__).resolve().parents[3] / 'tests/fixtures/scoring-period/snapshot.json'

def complete_snapshot():
    s=json.loads(FIXTURE.read_text())
    s['body_text']='Synthetic A Pro MMA Record: 2-1-0';s['lines']=['Synthetic A','Pro MMA Record: 2-1-0']
    s['history_exhausted']=True
    s['history_loading_evidence']={'contract_version':1,'canonical_card_dom':True,'cards_count':3,'hidden_cards':0,'controls':[],'first_page_verified':True,'load_complete':True,'navigation_exhausted':True,'pagination_present':False,'filters_present':False}
    for row in s['rows']:
        row.update(data_sport='mma',data_division='pro')
        row['record_labels']=[{'label':'Opponent Record Before Fight','value':'0-0'},{'label':'Fighter Record Before Fight','value':'2-0'}]
    return s

def parse(s):
    p=parse_profile_snapshot(s,s['url']);p['scraped_at']=s['scraped_at'];return p

def test_semantic_labels_ignore_text_order_and_preserve_one_side():
    s=complete_snapshot();p=parse(s);r=p['fight_history'][0]
    assert r['opponent_record_raw']=='0-0' and r['prefight_record_raw']=='2-0'
    assert r['record_evidence']['opponent']['record']['wins']==0
    s['rows'][0]['record_labels']=[{'label':'Fighter Record Before Fight','value':'2-0'}]
    r=parse(s)['fight_history'][0]
    assert r['record_evidence']['fighter']['status']=='known' and r['record_evidence']['opponent']['status']=='unknown'

@pytest.mark.parametrize('field,value',[('hidden_cards',1),('filters_present',True),('pagination_present',True),('load_complete',False),('navigation_exhausted',False),('first_page_verified',False)])
def test_no_complete_career_without_loading_and_scope_proof(field,value):
    s=complete_snapshot();assert parse(s)['career_history_evidence']['complete']
    s['history_loading_evidence'][field]=value
    assert not parse(s)['career_history_evidence']['complete']

def test_completed_pagination_and_inactive_controls_can_prove_full_career():
    s=complete_snapshot();loading=s['history_loading_evidence']
    loading.update(pagination_present=True,all_pages_loaded=True,loaded_pages=3,
                   filters_present=True,filters_active=False)
    evidence=parse(s)['career_history_evidence']
    assert evidence['complete'] and evidence['loading']['loaded_pages']==3

def test_active_narrowing_filter_or_unfinished_pages_block_full_career():
    s=complete_snapshot();loading=s['history_loading_evidence']
    loading.update(pagination_present=True,all_pages_loaded=False)
    assert not parse(s)['career_history_evidence']['complete']
    loading.update(all_pages_loaded=True,filters_present=True,filters_active=True)
    assert not parse(s)['career_history_evidence']['complete']

def test_complete_profile_snapshot_merges_and_deduplicates_pagination(monkeypatch):
    class Response:
        status=200
    class Page:
        url='https://www.tapology.com/fightcenter/fighters/123-test?page=1'
        def evaluate(self, script):
            assert 'control.click' in script
            return {'clicked':False,'rows':2}
        def goto(self,url,**_kwargs):
            self.url=url
            return Response()
    def snapshot(page):
        second='page=2' in page.url
        return {'url':page.url,'rows':[{'data_bout_id':'2'},{'data_bout_id':'3'}] if second else [{'data_bout_id':'1'},{'data_bout_id':'2'}],
                'history_loading_evidence':{'load_complete':True,'hidden_cards':0,'next_page_url':None if second else 'https://www.tapology.com/fightcenter/fighters/123-test?page=2'}}
    monkeypatch.setattr(fighter_profiles,'snapshot_profile_page',snapshot)
    result=fighter_profiles.snapshot_complete_profile_page(Page(),1000)
    assert [row['data_bout_id'] for row in result['rows']]==['1','2','3']
    assert result['history_loading_evidence']['all_pages_loaded'] is True
    assert result['history_loading_evidence']['loaded_pages']==2

def test_text_only_snapshot_cannot_acquire_missing_dom_proof():
    s=complete_snapshot();s.pop('history_loading_evidence')
    for row in s['rows']:row.pop('record_labels')
    p=parse(s)
    assert not p['career_history_evidence']['complete']
    assert all(r['record_evidence']['opponent']['status']=='unknown' for r in p['fight_history'])

def test_neutral_details_do_not_schedule_refetch_but_conflicts_do():
    s=complete_snapshot()
    for row in s['rows']:
        row['record_labels']=[{'label':'Opponent Record Before Fight','value':'N/a'}]
    p=parse(s);now=datetime(2026,10,10,10,tzinfo=timezone.utc)
    for row in p['fight_history']:row['method_category']='unknown'
    assert not needs_refresh(p,now=now)
    report=build_readiness({'profiles':[p]},verification_now=now)
    assert report['source_enrichment']==[] and report['profiles']['neutral_unknown_records']==3
    assert needs_refresh(p,now=now+timedelta(days=7,seconds=1))
    p['fight_history'][0]['record_evidence']['opponent']['status']='conflict'
    assert needs_refresh(p,now=now)

def test_equal_total_different_components_and_missing_identity_block():
    s=complete_snapshot();s['rows'][0]['data_status']='loss'
    assert not parse(s)['career_history_evidence']['complete']
    s=complete_snapshot();s['rows'][0]['links']=[l for l in s['rows'][0]['links'] if '/bouts/' not in l['href']]
    assert not parse(s)['career_history_evidence']['complete']

def test_mixed_scope_requires_each_rows_own_evidence():
    s=complete_snapshot();s['rows'][1].update(data_sport='boxing')
    assert not parse(s)['career_history_evidence']['complete']
    s=complete_snapshot();s['rows'][1].update(data_division='am')
    assert not parse(s)['career_history_evidence']['complete']

def test_postfight_text_is_only_a_candidate_and_conflicting_labels_are_review():
    s=complete_snapshot();row=s['rows'][0];row['record_labels']=[];row['raw_text']+=' Up to 9-1-0 Down to 3-8-0'
    assert parse(s)['fight_history'][0]['record_evidence']['opponent']['status']=='unknown'
    row['record_labels']=[{'label':'Opponent Record Before Fight','value':v} for v in ['1-0','2-0']]
    assert parse(s)['fight_history'][0]['record_evidence']['opponent']['status']=='conflict'

def test_method_stats_are_independent_control():
    s=complete_snapshot();s['record_stats']={'id':'fighterRecordStats','professional_mma':True,'lines':['KO/TKO','1 wins','0 losses','SUB','0 wins','0 losses','DEC','1 wins','1 losses']}
    p=parse(s);assert p['record_summary']['method_statistics']['counts']['ko_tko']['wins']==1
    assert p['fight_history'][0]['method_category']=='decision'
