import json
from copy import deepcopy
from datetime import datetime, timezone, timedelta
from pathlib import Path
from mma_future_scraper.fighter_profiles import parse_profile_snapshot
from mma_future_scraper.scoring_evidence import needs_refresh

FIXTURE=Path(__file__).resolve().parents[3]/'tests/fixtures/scoring-period'

def source():
    snapshot=json.loads((FIXTURE/'snapshot.json').read_text())
    profile=parse_profile_snapshot(snapshot,snapshot['url']);profile['scraped_at']=snapshot['scraped_at']
    return snapshot,profile

def test_shared_php_fixture_is_actual_parser_output():
    snapshot,profile=source()
    assert profile==json.loads((FIXTURE/'profile.json').read_text())
    assert profile['career_record_evidence']['verified'] is True
    assert profile['period_evidence']['boundary_crossed'] is True
    assert profile['period_evidence']['history_exhausted'] is False

def test_pro_record_without_sport_context_never_becomes_professional_mma_aggregate():
    snapshot,_=source();snapshot['lines']=['Synthetic A','Pro Record: 10-3-0'];snapshot['body_text']='Synthetic A Pro Record: 10-3-0'
    assert parse_profile_snapshot(snapshot,snapshot['url'])['career_record_evidence']['verified'] is False
    snapshot.update(record_scope='professional_mma',record_scope_verified=True)
    assert parse_profile_snapshot(snapshot,snapshot['url'])['career_record_evidence']['verified'] is True

def test_period_requires_first_page_order_and_reliable_boundary():
    snapshot,_=source();snapshot['history_first_page_verified']=False
    assert parse_profile_snapshot(snapshot,snapshot['url'])['period_evidence']['first_page_verified'] is False
    snapshot,_=source();snapshot['rows'].reverse()
    assert parse_profile_snapshot(snapshot,snapshot['url'])['period_evidence']['boundary_crossed'] is False
    snapshot,_=source();snapshot['history_scope_verified']='false'
    assert parse_profile_snapshot(snapshot,snapshot['url'])['period_evidence']['scope_verified'] is False
    snapshot,_=source();snapshot['history_load_complete']=False
    profile=parse_profile_snapshot(snapshot,snapshot['url']);profile['scraped_at']=snapshot['scraped_at']
    assert needs_refresh(profile,now=datetime(2026,10,10,10,tzinfo=timezone.utc))

def test_cache_age_and_uncovered_correction():
    _,profile=source();checked=datetime(2026,10,10,10,tzinfo=timezone.utc)
    assert not needs_refresh(profile,now=checked+timedelta(days=7))
    assert needs_refresh(profile,now=checked+timedelta(days=7,seconds=1))
    row=deepcopy(profile['fight_history'][0]);row['result']='no_contest'
    assert needs_refresh(profile,now=checked,known_results=[row])
    assert not needs_refresh(profile,now=checked,known_results=[profile['fight_history'][0]])
    row.update(source_bout_id='tapology_bout_999999',fight_date='2026-10-10',result='win')
    assert needs_refresh(profile,now=checked,known_results=[row])

def test_old_missing_details_do_not_block_a_confirmed_period():
    from mma_future_scraper.scoring_evidence import period_evidence
    snapshot,profile=source()
    old=profile['fight_history'][-1]
    old.update(source_bout_id=None,source_event_id=None,is_professional=False)
    old.pop('classification_evidence')
    evidence=period_evidence(snapshot,profile)
    assert evidence['boundary_crossed'] is True and evidence['chronological_descending'] is True


def test_current_ufc_signal_requires_its_own_widget_evidence():
    from mma_future_scraper.scoring_evidence import current_roster_verified
    proof={'verified':True,'promotion':'ufc','evidence':'current_ufc_ranking_widget','widget_boundary_verified':True,'logo_alt':'UFC','heading':'UFC Ranking','ranking_url':'https://www.tapology.com/rankings/ufc/ultimate-fighting-championship-mens-lightweight-155-pounds'}
    snapshot,_=source();snapshot['current_roster']=proof
    assert current_roster_verified(proof)
    assert parse_profile_snapshot(snapshot,snapshot['url'])['current_roster']==proof
    for key in ('widget_boundary_verified','verified','ranking_url'):
        bad=deepcopy(proof);bad[key]=False
        assert not current_roster_verified(bad)


def test_cache_refreshes_inconsistent_period_dates():
    _,profile=source();profile['period_evidence']['checked_through']='2026-10-11'
    assert needs_refresh(profile,now=datetime(2026,10,10,12,tzinfo=timezone.utc))
