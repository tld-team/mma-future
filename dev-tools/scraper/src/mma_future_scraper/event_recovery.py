"""Reparse captured event rows with their actual source observation timestamp."""
from types import SimpleNamespace
import re
from .utils import source_event_id_from_url,normalize_space,parse_date_to_iso,parse_location_parts
from .contract import populate_event_contract_fields
from .event_coverage import PARSER_VERSION,assess


def reparse_event_snapshot(snapshot):
    from .tapology import TapologyScraper
    from .config import ScraperConfig
    url=snapshot.get('source_url') or snapshot.get('event_url')
    sid=source_event_id_from_url(url)
    if not sid or not snapshot.get('observed_at'):raise ValueError('Snapshot needs source identity and original observation timestamp')
    s=TapologyScraper(ScraperConfig(enrich_fighter_profiles=False))
    s._scrape_matchup_page=lambda *args:None
    saved=snapshot.get('event') or {};body=normalize_space(snapshot.get('body_text'))
    event={'source_event_id':sid,'event_url':url,'event_name':saved.get('event_name') or normalize_space(snapshot.get('heading')) or (snapshot.get('title') or '').split(' | ')[0],
           'event_date':saved.get('event_date') or parse_date_to_iso(s._extract_date_raw(body)),
           'source_mma_bouts_total':saved.get('source_mma_bouts_total') if saved.get('source_mma_bouts_total') is not None else s._extract_source_bouts_total(body),
           'warnings':[],'skipped_bouts':[],'bouts':[],'fighter_profiles':{}}
    labels={}
    for line in (snapshot.get('body_text') or '').splitlines():
        match=re.match(r'^(Promotion|Venue|Location|Enclosure):\s*(.*)$',line.strip())
        if match:labels[match[1]]=match[2].strip() or None
    promotion=labels.get('Promotion');links=snapshot.get('promotion_links') or []
    urls={l.get('url') for l in links if l.get('url')}
    exact={l['url'] for l in links if l.get('url') and normalize_space(l.get('name'))==promotion}
    event.update(promotion_name=promotion,promotion_url=next(iter(exact)) if len(exact)==1 else (next(iter(urls)) if len(urls)==1 else None),venue=labels.get('Venue'),location=labels.get('Location'),enclosure=labels.get('Enclosure'))
    event.update(parse_location_parts(labels.get('Location')))
    if s._event_matches_excluded_promotion(event):snapshot={**snapshot,'event_exclusion':'excluded_promotion_ufc'}
    if snapshot.get('event_exclusion')=='excluded_promotion_ufc':
        proof=assess(event,[],[],snapshot['observed_at']);proof.update(status='excluded',reasons=[],event_exclusion='excluded_promotion_ufc',exclusion_evidence=snapshot.get('exclusion_evidence') or 'Verified FightCenter listing of excluded UFC event');return None,proof
    if snapshot.get('status') in {'unavailable','blocked'} or (snapshot.get('http_status') or 0)>=400:
        proof=assess(event,[],[],snapshot['observed_at']);proof.update(status='unavailable',reasons=[snapshot.get('error') or 'source_http_'+str(snapshot.get('http_status') or snapshot.get('status'))]);return None,proof
    rows=snapshot.get('rows') or []
    event['bouts']=s._parse_bouts(SimpleNamespace(evaluate=lambda *args:rows),event,SimpleNamespace(url=url),None,'results',{'warnings':[]})
    event['event_coverage']['observed_at']=snapshot['observed_at']
    s._finalize_event_completeness(event,body,'results')
    populate_event_contract_fields(event)
    return s._prune_none(event),event['event_coverage']
