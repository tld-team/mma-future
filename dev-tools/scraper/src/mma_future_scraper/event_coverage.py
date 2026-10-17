"""Event evidence, independent from successful HTTP requests and package validity."""
from __future__ import annotations
from collections import Counter
from pathlib import Path
import re
from datetime import datetime
from .utils import utc_now

PARSER_VERSION = 'tapology_event_card_v3'
ROWS_JS = Path(__file__).with_name('event_card.js').read_text(encoding='utf-8')
SCHEMA = 'mmaf_event_coverage_v1'

def classification(row):
    import re
    from .normalize import professional_status, is_explicit_non_mma_bout
    text = row.get('classification_text') or row.get('raw_text') or ''
    division = professional_status(text)
    if re.search(r'\bjuniors?\b', row.get('classification_text') or '', re.I): division = 'junior'
    sport = 'mma' if re.search(r'\bmma\b|mixed martial arts', text, re.I) else ('non_mma' if is_explicit_non_mma_bout(text) else 'unknown')
    if re.search(r'\bbare[\s-]*knuckle\b', row.get('classification_text') or '', re.I): sport = 'non_mma'
    return division, sport

def completed_result(row):
    if row.get('source_row_version'):
        # Structured cards provide independent outcome badges. A fighter called
        # Ko or a "KO Vacant ... Championship" belt is not result evidence.
        return row.get('result_badges') in [['W','L'],['L','W'],['D','D'],['N','N'],['NC','NC']]
    from .normalize import has_completed_result
    return has_completed_result(row.get('raw_text'))

def assess(event, rows, decisions, observed_at=None):
    expected = event.get('source_mma_bouts_total')
    reasons = []
    mma = sum(d['sport']=='mma' for d in decisions)
    unknown = sum(d['decision']=='unresolved' for d in decisions)
    if unknown: reasons.append('unresolved_bouts')
    if not decisions: reasons.append('fight_card_empty_or_missing')
    if expected is not None and mma != int(expected): reasons.append('mma_count_mismatch')
    if any(d['sport']=='unknown' and d['decision']=='unresolved' for d in decisions): reasons.append('sport_unconfirmed')
    statuses = Counter(d['decision'] for d in decisions)
    accepted = statuses['accepted']
    status = 'partial' if reasons else ('complete' if accepted else 'excluded')
    return {'source_event_id':event.get('source_event_id'), 'event_url':event.get('event_url'),
            'observed_at':observed_at or utc_now(), 'parser_version':PARSER_VERSION,
            'source_mma_bouts_total':expected, 'unique_bouts':len(decisions),
            'status':status, 'reasons':reasons, 'counts':dict(statuses), 'bouts':decisions}

def document(events, observed=(), run_id=None):
    entries = {e['source_event_id']:e for e in observed if e.get('source_event_id')}
    for event in events:
        sid=event['source_event_id']
        proof=event.get('event_coverage')
        if sid not in entries:
            entries[sid]=proof or {'source_event_id':sid,'event_url':event.get('event_url'),'status':'partial','parser_version':'legacy','observed_at':None,'reasons':['legacy_unverified'],'bouts':[],'counts':{},'unique_bouts':0,'source_mma_bouts_total':event.get('source_mma_bouts_total')}
    return {'schema_version':SCHEMA,'run_id':run_id,'generated_at':utc_now(),
            'counts':dict(Counter(e['status'] for e in entries.values())), 'events':list(entries.values())}

def validate(document, events):
    if document.get('schema_version')!=SCHEMA or not isinstance(document.get('events'),list): raise ValueError('Unsupported event coverage schema')
    by={}
    for e in document['events']:
        sid=e.get('source_event_id')
        if not isinstance(sid,str) or not re.fullmatch(r'tapology_event_\d+',sid) or sid in by or e.get('status') not in {'complete','partial','unavailable','excluded'}: raise ValueError('Invalid or duplicate event coverage identity/status')
        by[sid]=e
        bouts=e.get('bouts',[]);ids=[b.get('source_bout_id') for b in bouts]
        if len(ids)!=len(set(ids)) or any(not i for i in ids): raise ValueError('Duplicate or missing covered bout identity')
        if e.get('parser_version')=='legacy':
            if e['status']!='partial': raise ValueError('Legacy event cannot claim confirmed coverage')
            continue
        actual=Counter()
        for b in bouts:
            decision=b.get('decision');sport=b.get('sport');actual[decision]+=1
            if decision not in {'accepted','excluded','unresolved'} or sport not in {'mma','non_mma','unknown'}: raise ValueError('Invalid covered bout decision or sport')
            if decision=='accepted' and (b.get('division')!='pro' or sport!='mma'): raise ValueError('Accepted coverage lacks professional MMA classification')
            if decision=='excluded' and b.get('reason') not in {'amateur','junior','non_mma','cancelled'}: raise ValueError('Excluded bout needs explicit reason')
        if e.get('unique_bouts')!=len(bouts) or dict(actual)!=e.get('counts'): raise ValueError('Coverage counters disagree with bout decisions')
        if e['status'] in {'complete','excluded'}:
            scope_exclusion=e['status']=='excluded' and not bouts and e.get('event_exclusion') in {'excluded_promotion_ufc','non_mma_metadata'} and bool(e.get('exclusion_evidence'))
            if e.get('reasons') or actual['unresolved'] or (not bouts and not scope_exclusion): raise ValueError('Incomplete event claims confirmed coverage')
            if (e['status']=='complete') != bool(actual['accepted']): raise ValueError('Coverage status contradicts accepted results')
            try: datetime.fromisoformat(e['observed_at'].replace('Z','+00:00'))
            except (KeyError,ValueError,TypeError,AttributeError): raise ValueError('Coverage observation timestamp is missing')
            expected=e.get('source_mma_bouts_total')
            if not scope_exclusion and expected is not None and (type(expected) is not int or sum(b['sport']=='mma' for b in bouts)!=expected): raise ValueError('Coverage MMA total mismatch')
    indexed={e['source_event_id']:e for e in events}
    for sid,e in by.items():
        if e.get('parser_version')!='legacy' and any(b['decision']=='accepted' for b in e['bouts']) and sid not in indexed: raise ValueError('Accepted coverage has no source results')
    for event in events:
        e=by.get(event['source_event_id'])
        if not e: raise ValueError('Event is missing coverage: '+event['source_event_id'])
        if e.get('parser_version')=='legacy':continue
        accepted={b['source_bout_id'] for b in e['bouts'] if b['decision']=='accepted'}
        if accepted!={b['source_bout_id'] for b in event.get('bouts',[])}: raise ValueError('Event coverage does not match accepted results')
        if event.get('event_coverage')!=e: raise ValueError('Event coverage disagrees with source event proof')
        if e.get('event_url')!=event.get('event_url'): raise ValueError('Event coverage URL disagrees with source event')
    return dict(Counter(e['status'] for e in by.values()))
