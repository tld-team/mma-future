from mma_future_scraper.career_history import compile_history
from mma_future_scraper.fighter_profiles import parse_profile_snapshot

URL = 'https://www.tapology.com/fightcenter/fighters/100-test-fighter'

def snapshot(rows):
    return {'url': URL, 'title': 'Test Fighter | Tapology', 'headings': ['Test Fighter'],
            'body_text': 'Test Fighter Pro MMA Record: 1-0-0', 'lines': [], 'rows': rows,
            'history_exhausted': True, 'scraped_at': '2026-10-08T10:00:00Z'}

def row(text, status='win', bout_id='300'):
    return {'raw_text': text, 'data_bout_id': bout_id, 'data_division': 'pro',
            'data_sport': 'mma', 'data_status': status,
            'links': [{'href': 'https://www.tapology.com/fightcenter/fighters/200-opponent', 'text': 'Opponent'},
                      {'href': f'https://www.tapology.com/fightcenter/bouts/{bout_id}-test', 'text': 'Armbar · 1:22 · R1'},
                      {'href': 'https://www.tapology.com/fightcenter/events/400-test-event', 'text': 'Test Event'}]}

def test_explicit_row_method_badge_and_bout_detail_are_preserved():
    parsed = parse_profile_snapshot(snapshot([row('W SUB Opponent 0-0 0-0 Armbar · 1:22 · R1 Test Event 2020 Jan 1')]), URL)
    fight = parsed['fight_history'][0]
    assert fight['method_category'] == 'submission'
    assert fight['method'] == 'Armbar'
    assert fight['source_bout_id'] == 'tapology_bout_300'

def test_explicit_upcoming_status_survives_empty_scoped_text():
    parsed = parse_profile_snapshot(snapshot([row('Opponent Confirmed Upcoming Bout Test Event 2026 Nov 1', 'upcoming')]), URL)
    assert parsed['fight_history'][0]['is_upcoming'] is True

def test_record_ineligible_and_exhibition_do_not_inflate_source_record():
    manifest = {'targets': [{'fighter_id': 1, 'source_fighter_id': 'tapology_fighter_100', 'source_url': URL, 'display_name': 'Test Fighter'}]}
    rows = [row('W SUB Opponent 0-0 0-0 Armbar · 1:22 · R1 Test Event 2020 Jan 1'),
            row('W SUB Opponent Record Ineligible MMA Armbar · 1:22 · R1 Test Event 2020 Jan 1', bout_id='301'),
            row('L SUB Opponent Exhibition MMA Armbar · 1:22 · R1 Test Event 2020 Jan 1', 'loss', '302')]
    document, report = compile_history(manifest, [snapshot(rows)])
    fights = {r['source_bout_id']: r for r in document['profiles'][0]['rows']}
    assert fights['tapology_bout_301']['record_eligible'] is False
    assert fights['tapology_bout_302']['is_exhibition'] is True
    assert report['profiles'][0]['source_record'] == {'wins': 1, 'losses': 0, 'draws': 0, 'nc': 0}
    assert not fights['tapology_bout_301'].get('prefight_record_raw')


def test_bout_detail_enrichment_requires_exact_participant_identity():
    manifest = {'targets': [{'fighter_id': 1, 'source_fighter_id': 'tapology_fighter_100', 'source_url': URL, 'display_name': 'Test Fighter'}]}
    evidence = {'url': 'https://www.tapology.com/fightcenter/bouts/300-test', 'header':'COMPLETED MMA BOUT', 'result':'W\nTest defeats Opponent\nL',
                'matchup':[{'url':URL,'text':'Test Fighter'},{'url':'https://www.tapology.com/fightcenter/fighters/200-opponent','text':'Opponent'}],
                'event_links':[{'url':'https://www.tapology.com/fightcenter/events/400-test-event','text':'Test Event'}]}
    document, _ = compile_history(manifest, [snapshot([row('W SUB Opponent Armbar · 1:22 · R1 Test Event 2020 Jan 1')])], [evidence])
    fight = document['profiles'][0]['rows'][0]
    assert fight['final_result_verified'] is True
    assert fight['final_result_evidence'] == 'exact_source_bout_page'
    evidence['matchup'][1]['url'] = 'https://www.tapology.com/fightcenter/fighters/999-different-opponent'
    document, _ = compile_history(manifest, [snapshot([row('W SUB Opponent Armbar · 1:22 · R1 Test Event 2020 Jan 1')])], [evidence])
    assert document['profiles'][0]['rows'][0]['source_conflict_reason']


def test_pending_bout_details_do_not_invent_a_final_result():
    manifest = {'targets': [{'fighter_id': 1, 'source_fighter_id': 'tapology_fighter_100', 'source_url': URL, 'display_name': 'Test Fighter'}]}
    evidence = {'url':'https://www.tapology.com/fightcenter/bouts/300-test','header':'PAST MMA BOUT','body':'Awaiting Results',
                'matchup':[{'url':URL,'text':'Test Fighter'},{'url':'https://www.tapology.com/fightcenter/fighters/200-opponent','text':'Opponent'}], 'event_links':[]}
    document, _ = compile_history(manifest, [snapshot([row('Opponent Test Event 2020 Jan 1','unknown')])], [evidence])
    fight = document['profiles'][0]['rows'][0]
    assert fight.get('result') not in {'win','loss','draw','no_contest'}
    assert 'awaiting' in fight['bout_detail_limitation']
    assert not fight.get('final_result_verified')


def test_profile_facts_need_explicit_labels_and_never_infer_nationality():
    from mma_future_scraper.career_history import explicit_profile_facts
    facts = explicit_profile_facts({'lines':['Born:','Brazil','Fighting out of:','Rio de Janeiro','Height: N/A | Reach:','N/A']},
                                   {'born_location':'Brazil','fighting_out_of':'Rio de Janeiro','height_cm':180,'country':'Brazil','reach_cm':180})
    assert facts['birthplace']['value'] == 'Brazil'
    assert facts['fighting_out_of']['value'] == 'Rio de Janeiro'
    assert 'nationality' not in facts and 'height_cm' not in facts and 'reach_cm' not in facts


def test_regular_profile_json_keeps_career_classification_and_explicit_fact_evidence():
    source = snapshot([row('W SUB Opponent Record Ineligible MMA 0-0 0-0 Armbar · 1:22 · R1 Test Event 2020 Jan 1', 'no_contest')])
    source['lines'] = ['Height: 180 cm', 'Reach: 185 cm', 'Affiliation: Example Gym']
    source['body_text'] += '\n' + '\n'.join(source['lines'])
    parsed = parse_profile_snapshot(source, URL)
    fight = parsed['fight_history'][0]
    assert fight['classification_evidence'] == 'profile_dom_classification'
    assert fight['source_sport'] == 'mma'
    assert fight['record_eligible'] is False
    assert fight['record_eligibility_evidence'] == 'source_profile_record_ineligible_mma'
    assert fight['final_result_verified'] is True
    assert parsed['verified_facts']['height_cm']['value'] == 180
    assert parsed['verified_facts']['reach_cm']['value'] == 185
    assert parsed['verified_facts']['team_name']['value'] == 'Example Gym'
