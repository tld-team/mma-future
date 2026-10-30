"""Offline evidence checks shared by packaging, recovery and the importer fixtures."""
from __future__ import annotations
from collections import Counter
from copy import deepcopy
from datetime import date, datetime, timezone
from urllib.parse import urlsplit
import hashlib, json, re, struct, math
from .fighter_profile_queue import normalize_tapology_fighter_url
from .utils import source_fighter_id_from_url, source_bout_id_from_url, source_event_id_from_url, canonical_url

CONTRACT_VERSION = 1
POLICY_VERSION = 'import_hybrid_evidence_v2'
CLASSIFICATION_EVIDENCE = {'profile_dom_classification', 'profile_section_classification', 'bout_dom_classification'}
RESULTS = {'win':'wins','loss':'losses','draw':'draws','no_contest':'no_contests'}
VOLATILE = {'scraped_at','profile_scraped_at','fetched_at','diagnostics','profile_cache_hit','profile_cache_source','profile_cache_scraped_at','first_seen_at','last_seen_at','last_scraped_at','run_id'}


def content_hash(value):
    digest=hashlib.sha256()
    def token(value):digest.update(value.encode('utf-8'))
    def emit(v):
        if isinstance(v, dict):
            keys=sorted(k for k in v if k not in VOLATILE)
            if not keys:token('[]');return
            token('{')
            for i,key in enumerate(keys):
                if i:token(',')
                token(json.dumps(key,ensure_ascii=False));token(':');emit(v[key])
            token('}')
        elif isinstance(v,float):
            if not math.isfinite(v):raise ValueError('Non-finite source number')
            emit({'__float64__':struct.pack('>d',v).hex()})
        elif isinstance(v,list):
            token('[')
            for i,item in enumerate(v):
                if i:token(',')
                emit(item)
            token(']')
        else:token(json.dumps(v,ensure_ascii=False,separators=(',',':'),allow_nan=False))
    emit(value)
    return digest.hexdigest()


def normalize_profile(profile):
    p=deepcopy(profile)
    p['source_url']=normalize_tapology_fighter_url(p.get('source_url'))
    identity=source_fighter_id_from_url(p.get('source_url'))
    if not identity or p.get('source_fighter_id') not in (None, '', identity):
        raise ValueError('Profile source identity conflicts with its URL')
    p['source_fighter_id']=identity
    for r in p.get('fight_history', []):
        for url,key,parse in [('opponent_url','opponent_source_id',source_fighter_id_from_url),('bout_url','source_bout_id',source_bout_id_from_url),('event_url','source_event_id',source_event_id_from_url)]:
            if r.get(url):
                parts=urlsplit(r[url])
                kind={'opponent_url':'fighters','bout_url':'bouts','event_url':'events'}[url]
                if parts.scheme not in ('http','https') or parts.netloc.lower() not in ('tapology.com','www.tapology.com') or not re.fullmatch(r'/fightcenter/'+kind+r'/[^/?#]+/*',parts.path):
                    raise ValueError('Career row has an invalid source URL: '+url)
                if url=='opponent_url':r[url]=normalize_tapology_fighter_url(r[url])
                value=parse(r[url])
                if not value or r.get(key) not in (None, '', value):
                    raise ValueError('Career row URL and source identity disagree: '+key)
                r[key]=value
    return p


def row_issues(row,profile=None):
    reasons=[]
    if row.get('classification_evidence')=='profile_section_classification':
        proof=row.get('classification_proof') or {}; profile=profile or {}
        if profile.get('history_scope_verified') is not True or profile.get('history_scope')!='professional_mma' or proof.get('section')!='professional_mma' or proof.get('row_boundary_verified') is not True:
            reasons.append('invalid_section_evidence')
    if reasons or row.get('classification_evidence') not in CLASSIFICATION_EVIDENCE or not row.get('source_sport'):
        reasons.append('missing_classification_evidence')
    elif row.get('source_sport')!='mma' or row.get('source_division') in ('am','amateur') or any(row.get(k) for k in ('is_amateur','is_exhibition','is_cancelled','is_upcoming')):
        return ['excluded']
    if row.get('source_conflict_reason'):
        reasons.append('source_conflict')
    if row.get('is_professional') is not True or row.get('discipline')!='mma':
        reasons.append('professional_mma_not_confirmed')
    for k,prefix in [('source_bout_id','tapology_bout_'),('source_event_id','tapology_event_')]:
        if row.get(k) and not re.fullmatch(prefix+r'[0-9]+',row[k]):reasons.append('invalid_'+k)
    for k in ('source_bout_id','source_event_id','bout_url','event_url','opponent_url'):
        if not row.get(k): reasons.append('missing_'+k)
    if row.get('result') not in RESULTS or (row.get('is_overturned') and row.get('result')!='no_contest' and not row.get('final_result_verified')):
        reasons.append('unconfirmed_final_result')
    try:
        if date.fromisoformat(row.get('fight_date',''))>date.today(): reasons.append('future_fight')
    except (ValueError,TypeError): reasons.append('missing_fight_date')
    return reasons


def build_readiness(document, *, verification_now=None):
    profiles=document.get('profiles',[]); totals=Counter(); row_counts=Counter(); queue=[]; seen=set(); reasons=Counter(); parsers=Counter(); actions=Counter()
    shared={};conflicts={};profile_conflicts={}
    inverse={'win':'loss','loss':'win','draw':'draw','no_contest':'no_contest'}
    for raw in profiles:
        p=normalize_profile(raw)
        owner=p['source_fighter_id']
        for row in p.get('fight_history',[]):
            if row_issues(row,p):continue
            key=row['source_bout_id'];opponent=row.get('opponent_source_id')
            signature=(tuple(sorted([owner,opponent])),row['source_event_id'],row['fight_date'])
            previous=shared.get(key)
            if previous and (previous['signature']!=signature or (previous['owner']==owner and previous['result']!=row['result']) or (previous['owner']!=owner and inverse[previous['result']]!=row['result'])):
                group=conflicts.setdefault(key,set());group.update([owner,previous['owner']])
            else:shared[key]={'signature':signature,'owner':owner,'result':row['result']}
    for bout,owners in conflicts.items():
        for owner in owners:profile_conflicts.setdefault(owner,[]).append(bout)
    for raw in profiles:
        p=normalize_profile(raw)
        key=p['source_fighter_id']
        if key in seen: raise ValueError('Duplicate profile identity: '+key)
        seen.add(key); issues=Counter(); records=Counter(); bouts={}
        if key in profile_conflicts:issues['source_conflict']+=len(profile_conflicts[key])
        parsers[p.get('parser_version') or 'legacy_unknown']+=1
        if p.get('status')!='success': issues['source_fetch_failed']+=1
        else: totals['facts_ready']+=1
        if not p.get('parser_version'): totals['legacy_profiles']+=1
        for r in p.get('fight_history',[]):
            row_counts['total']+=1; why=row_issues(r,p)
            if why==['excluded']:
                row_counts['excluded']+=1; continue
            if why:
                issues.update(why); row_counts['needs_enrichment']+=1; continue
            signature=(r.get('source_event_id'),r.get('opponent_source_id'),r.get('fight_date'),r.get('result'))
            if r['source_bout_id'] in bouts:
                if bouts[r['source_bout_id']]!=signature: issues['source_conflict']+=1
                continue
            bouts[r['source_bout_id']]=signature; row_counts['ready_candidates']+=1
            if r.get('record_eligible') is not False: records[RESULTS[r['result']]]+=1
        if p.get('history_scope_verified') is not True or p.get('history_scope')!='professional_mma': issues['scope_unverified']+=1
        if p.get('history_exhausted') is not True: issues['history_not_exhausted']+=1
        expected=p.get('record_summary',{})
        if any(not isinstance(expected.get(k),int) or isinstance(expected.get(k),bool) or expected[k]<0 for k in RESULTS.values()):
            issues['aggregate_record_missing']+=1
        elif any(expected[k]!=records[k] for k in RESULTS.values()): issues['record_mismatch']+=1
        period_issues=Counter();outside_issues=Counter();detail_warnings=Counter()
        from .scoring_evidence import PERIOD_START, needs_refresh, career_record_verified
        for row in p.get('fight_history',[]):
            why=row_issues(row,p)
            if why==['excluded']:continue
            day=row.get('fight_date')
            target=outside_issues if day and day<PERIOD_START else period_issues
            target.update(why)
            if day and day>=PERIOD_START and row.get('record_eligible') is not False and not why and row.get('result') in ('win','loss'):
                if row.get('result')=='win' and row.get('method_category') in (None,'','unknown'):
                    period_issues['missing_recent_win_method']+=1
                    detail_warnings['missing_recent_win_method']+=1
                if not row.get('opponent_record_raw'):
                    period_issues['missing_recent_prefight_record']+=1
                    detail_warnings['missing_recent_prefight_record']+=1
        evidence=p.get('period_evidence') or {}
        proof=evidence.get('scope_verified') is True and evidence.get('first_page_verified') is True and evidence.get('load_complete') is True and evidence.get('chronological_descending') is True and (evidence.get('boundary_crossed') is True or evidence.get('history_exhausted') is True)
        if not proof:period_issues['period_coverage_unconfirmed']+=1
        if not career_record_verified(p):period_issues['career_record_scope_unconfirmed']+=1
        fresh=not needs_refresh(p,now=verification_now or datetime.now(timezone.utc))
        if not fresh:period_issues['source_refresh_required']+=1
        period_conflicts=[r.get('source_bout_id') for r in p.get('fight_history',[]) if r.get('fight_date') and r['fight_date']>=PERIOD_START and r.get('source_bout_id') in profile_conflicts.get(key,[])]
        if period_conflicts:period_issues['source_conflict']+=len(period_conflicts)
        totals['period_needing_enrichment' if period_issues else 'period_ready_candidates']+=1
        totals['history_outside_period_gaps']+=bool(outside_issues or issues['record_mismatch'] or issues['history_not_exhausted'])
        if issues:
            totals['careers_needing_enrichment']+=1
            action='review_conflict' if period_issues['source_conflict'] else ('refresh_source_evidence' if period_issues else 'history_outside_period')
            actions[action]+=1;reasons.update(issues.keys())
            queue.append({'source_fighter_id':key,'source_url':p['source_url'],'content_hash':content_hash(p),'reasons':dict(issues),'period_reasons':dict(period_issues),'outside_period_reasons':dict(outside_issues),'formula_warnings':dict(detail_warnings),'conflicting_bouts':profile_conflicts.get(key,[]),'action':action})
        elif period_issues:
            action='review_conflict' if period_issues['source_conflict'] else 'refresh_source_evidence'
            actions[action]+=1
            queue.append({'source_fighter_id':key,'source_url':p['source_url'],'content_hash':content_hash(p),'reasons':dict(period_issues),'period_reasons':dict(period_issues),'formula_warnings':dict(detail_warnings),'conflicting_bouts':period_conflicts,'action':action})
            totals['career_ready_candidates']+=1
        else: totals['career_ready_candidates']+=1
        attempt=p.get('last_attempt') or {}
        if not attempt:totals['last_attempt_unknown']+=1
        if attempt.get('status') and attempt['status']!='success': totals['last_attempt_failed']+=1
    return {'schema_version':'mmaf_import_readiness_v1','contract_version':CONTRACT_VERSION,'policy_version':POLICY_VERSION,'profile_content_hash':content_hash(document.get('profiles',[])), 'profiles_total':len(profiles),'profiles':dict(totals),'history_rows':dict(row_counts),'parser_versions':dict(parsers),'enrichment_reasons':dict(reasons),'enrichment_actions':dict(actions),'shared_bout_conflicts':{bout:sorted(owners) for bout,owners in conflicts.items()},'last_batch':document.get('merge_stats',{}),'source_enrichment':queue,'note':'Candidate evidence is checked offline; canonical coverage is verified against the destination database during import.'}


def format_readiness(report):
    p=report['profiles']; r=report['history_rows']
    return ('# Import readiness\n\n'
            f"Profiles: {report['profiles_total']}\n\n"
            f"Safe profile candidates: {p.get('facts_ready',0)}\n\n"
            f"Career candidates with complete source evidence: {p.get('career_ready_candidates',0)}\n\n"
            f"Period candidates with current source evidence from 9 May 2026: {p.get('period_ready_candidates',0)}\n\n"
            f"Period candidates needing refresh/review: {p.get('period_needing_enrichment',0)}; older history gaps (separate): {p.get('history_outside_period_gaps',0)}\n\n"
            f"Careers needing source evidence or conflict review: {p.get('careers_needing_enrichment',0)}\n\n"
            f"Legacy profiles: {p.get('legacy_profiles',0)}; recorded failed latest attempts: {p.get('last_attempt_failed',0)}; latest attempt not recorded: {p.get('last_attempt_unknown',0)}\n\n"
            f"Last merged batch: {json.dumps(report.get('last_batch',{}),ensure_ascii=False)}\n\n"
            f"Enrichment groups (profiles per reason): {json.dumps(report.get('enrichment_reasons',{}),ensure_ascii=False)}\n\n"
            f"History rows: {r.get('total',0)}; ready candidates: {r.get('ready_candidates',0)}; excluded: {r.get('excluded',0)}\n\n"
            'This report describes source evidence. The importer checks canonical identity, manual protection and final coverage separately.\n')
