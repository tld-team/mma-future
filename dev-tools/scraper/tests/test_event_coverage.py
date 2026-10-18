import copy,json
from pathlib import Path
import pytest
from mma_future_scraper.tapology import TapologyScraper,FatalScrapeError,should_skip_results_event_no_usable_bouts,skipped_results_event_category
from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.event_coverage import document,validate

FIXTURES=Path(__file__).parent/'fixtures/event_cards'
class Card:
    def __init__(self,rows):self.rows=rows
    def evaluate(self,*args):return self.rows
class Page:
    def __init__(self,url):self.url=url
class NoNetwork:
    def new_page(self):raise AssertionError('Fixture must not fetch source pages')
def parse(data,rows=None):
    s=TapologyScraper(ScraperConfig())
    s._scrape_matchup_page=lambda *args:None
    sid='tapology_event_'+data['url'].split('/events/')[1].split('-')[0]
    e={'source_event_id':sid,'event_url':data['url'],'event_date':'2026-10-02','event_name':data['title'],'source_mma_bouts_total':data['source_mma_bouts_total'],'warnings':[],'skipped_bouts':[]}
    e['bouts']=s._parse_bouts(Card(rows if rows is not None else data['rows']),e,Page(data['url']),NoNetwork(),'results',{'warnings':[]})
    return e

@pytest.mark.parametrize('name,total,excluded',[('pfl_new_york',9,0),('pfl_mena',6,2),('rizin_landmark',10,3),('ksw_121',8,0)])
def test_real_cards_keep_professional_results(name,total,excluded):
    data=json.loads((FIXTURES/(name+'.json')).read_text())
    e=parse(data)
    assert len(e['bouts'])==total
    assert len(e['skipped_bouts'])==excluded
    assert e['event_coverage']['status']=='complete'
    assert len({b['source_bout_id'] for b in e['bouts']})==total
    assert validate(document([e]),[e])=={'complete':1}
    for b in e['bouts']:
        assert b['fighter_a']['source_fighter_id']!=b['fighter_b']['source_fighter_id']
        assert b['result_type'] in {'win_loss','draw','no_contest'}

def test_postfight_records_never_become_prefight():
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());e=parse(d)
    main=e['bouts'][0]
    assert main['winner']=='fighter_a'
    assert main['fighter_a']['name']=='Usman Nurmagomedov'
    assert main['fighter_a']['prefight_record']['is_missing']
    assert main['fighter_b']['prefight_record']['is_missing']
    assert main['result']['time']=='3:42' and main['result']['round']==1

def test_unresolved_rows_remain_partial_not_amateur_or_non_mma():
    d=json.loads((FIXTURES/'pfl_mena.json').read_text());rows=copy.deepcopy(d['rows'])
    rows[0]['classification_text']='';rows[0]['classification_evidence']=None
    e=parse(d,rows)
    assert len(e['bouts'])==5
    assert e['event_coverage']['status']=='partial'
    assert not should_skip_results_event_no_usable_bouts(e)
    empty=dict(e,bouts=[])
    assert skipped_results_event_category(empty)=='no_usable_pro_mma_bouts'
    assert not should_skip_results_event_no_usable_bouts(empty)

def test_duplicates_and_tampered_coverage():
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());e=parse(d,d['rows']*2)
    assert len(e['bouts'])==9
    proof=document([e]);proof['events'][0]['bouts'][0]['decision']='excluded'
    with pytest.raises(ValueError,match='does not match|disagrees|reason|status'):validate(proof,[e])
    with pytest.raises(ValueError,match='missing coverage'):validate(document([]),[e])

def test_verified_professionals_with_zero_results_fail_run():
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());d['rows'][0]['fighter_links']=[]
    s=TapologyScraper(ScraperConfig());s._normalize_bout_row=lambda *args:(_ for _ in ()).throw(ValueError('Broken layout'))
    with pytest.raises(FatalScrapeError,match='zero_professional'):
        s._parse_bouts(Card(d['rows']),{'event_url':d['url'],'source_event_id':'tapology_event_144072','warnings':[]},Page(d['url']),NoNetwork(),'results',{'warnings':[]})

def test_legacy_is_never_confirmed():
    e={'source_event_id':'tapology_event_1','bouts':[]}
    proof=document([e]);assert proof['events'][0]['status']=='partial'
    validate(proof,[e]);proof['events'][0]['status']='complete'
    with pytest.raises(ValueError,match='Legacy'):validate(proof,[e])

def test_cancelled_bout_is_documented_without_losing_other_results():
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());rows=copy.deepcopy(d['rows'])
    rows[0].update(method_raw='Cancelled',result_badges=[None,None],raw_text='Cancelled · Professional MMA')
    e=parse(d,rows)
    assert len(e['bouts'])==8 and e['event_coverage']['status']=='complete'
    assert e['event_coverage']['bouts'][0]['reason']=='cancelled'
    validate(document([e]),[e])

def test_missing_result_is_partial_even_with_other_successful_results():
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());rows=copy.deepcopy(d['rows'])
    rows[0].update(method_raw=None,result_badges=[None,None],raw_text='Professional MMA · Main Card')
    e=parse(d,rows)
    assert len(e['bouts'])==8 and e['event_coverage']['status']=='partial'
    assert e['event_coverage']['bouts'][0]['decision']=='unresolved'

def test_corrected_nc_does_not_become_a_win_loss():
    e=parse(json.loads((FIXTURES/'ksw_121.json').read_text()))
    nc=[b for b in e['bouts'] if b['result_type']=='no_contest']
    assert len(nc)==1 and nc[0]['winner'] is None and nc[0]['loser'] is None

def test_coverage_cannot_hide_an_accepted_event_or_forge_counters():
    e=parse(json.loads((FIXTURES/'pfl_new_york.json').read_text()))
    with pytest.raises(ValueError,match='no source results'):validate(document([e]),[])
    proof=copy.deepcopy(document([e]));proof['events'][0]['unique_bouts']=10
    with pytest.raises(ValueError,match='counters'):validate(proof,[e])

def test_mixed_event_metadata_cannot_skip_professional_mma(monkeypatch,tmp_path):
    from test_tapology_event_filtering import _patch_event_metadata,_EventContext
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text());scraper=TapologyScraper(ScraperConfig(enrich_fighter_profiles=False))
    _patch_event_metadata(monkeypatch,scraper)
    monkeypatch.setattr(scraper,'_find_fight_card',lambda page:(Card(d['rows']),'fixture',False))
    monkeypatch.setattr(scraper,'_scrape_matchup_page',lambda *args:None)
    event=scraper._scrape_event_page(_EventContext('MMA Bouts: 9 Boxing Bouts: 2 Date/Time: Friday 07.31.2026'),{'event_url':d['url'],'event_name':'Boxing and MMA Showcase','promotion_name':'Boxing Showcase'},'results',tmp_path,scraper._empty_summary('test','2026-10-17T00:00:00Z'))
    assert len(event['bouts'])==9 and event['event_coverage']['status']=='complete'

def test_pruning_event_keeps_transport_proof_identical():
    e=parse(json.loads((FIXTURES/'pfl_new_york.json').read_text()))
    pruned=TapologyScraper(ScraperConfig())._prune_none(e)
    assert pruned['event_coverage']==e['event_coverage']
    validate(document([e]),[pruned])

def test_optional_matchup_cannot_erase_card_method_or_corrected_nc():
    s=TapologyScraper(ScraperConfig())
    e=parse(json.loads((FIXTURES/'ksw_121.json').read_text()));b=next(b for b in e['bouts'] if b['result_type']=='no_contest')
    before=copy.deepcopy(b['result'])
    s._apply_matchup_details(b,{'result':{'method_category':'unknown','round':None,'time':None}})
    assert b['result']==before
    s._apply_matchup_details(b,{'result':{'method_category':'ko_tko'}})
    assert b['result']['method_category']==before['method_category']


def test_offline_reparse_preserves_actual_observation_and_source_url():
    from mma_future_scraper.event_recovery import reparse_event_snapshot
    d=json.loads((FIXTURES/'pfl_new_york.json').read_text())
    snapshot={'source_url':d['url'],'observed_at':'2026-10-10T10:00:00Z','event':{'event_name':d['title'],'event_date':'2026-07-31','source_mma_bouts_total':9},'rows':d['rows']}
    e,proof=reparse_event_snapshot(snapshot)
    assert len(e['bouts'])==9 and proof['observed_at']==snapshot['observed_at']
    validate(document([e]),[e])

def test_failed_offline_snapshot_retains_named_unavailable_event():
    from mma_future_scraper.event_recovery import reparse_event_snapshot
    event,proof=reparse_event_snapshot({'source_url':'https://www.tapology.com/fightcenter/events/1-test','observed_at':'2026-10-10T10:00:00Z','status':'blocked','http_status':429})
    assert event is None and proof['status']=='unavailable' and proof['reasons']==['source_http_429']

@pytest.mark.parametrize('name,total,excluded',[('nec_juniors',5,1),('ethio_sanda',3,5),('fusion_bare_knuckle',1,9)])
def test_explicit_other_divisions_and_sports_are_explained(name,total,excluded):
    e=parse(json.loads((FIXTURES/(name+'.json')).read_text()))
    assert len(e['bouts'])==total and len(e['skipped_bouts'])==excluded
    assert e['event_coverage']['status']=='complete'
    validate(document([e]),[e])

def test_belt_abbreviation_and_fighter_name_are_not_result_evidence():
    e=parse(json.loads((FIXTURES/'krossover_no_results.json').read_text()))
    assert not e['bouts'] and e['event_coverage']['status']=='partial'
    assert all(b['reason']=='no_result' for b in e['event_coverage']['bouts'] if b['division']=='pro' and b['sport']=='mma')
    assert not should_skip_results_event_no_usable_bouts(e)
