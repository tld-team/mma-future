"""Versioned, conservative whole-career and participant-record evidence.

Text-only snapshots retain candidates but cannot acquire missing DOM evidence.
Aggregate method counts are independent controls, never per-bout scoring inputs.
"""
from collections import Counter
import re

RESULTS = {'win': 'wins', 'loss': 'losses', 'draw': 'draws', 'no_contest': 'no_contests'}


def prefight_evidence(row, candidates, source_url):
    evidence = {}
    for side in ('fighter', 'opponent'):
        values = [str(item.get('value', '')).strip() for item in row.get('record_labels', [])
                  if str(item.get('label', '')).lower() == f'{side} record before fight']
        records = []
        for value in values:
            match = re.fullmatch(r'(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?(?:\s*[, (]\s*(\d+)\s*NC\)?)?', value, re.I)
            if match:
                records.append((value, [int(v or 0) for v in match.groups()]))
        unique = {tuple(r[1]) for r in records}
        status = 'conflict' if len(unique) > 1 else ('known' if records else 'unknown')
        evidence[side] = {'status': status, 'reason': None if status == 'known' else ('conflicting_labelled_records' if status == 'conflict' else 'prefight_semantics_unproven'),
                          'source': source_url, 'parser_version': 'profile_dom_v4',
                          'evidence': 'labelled_record_before_fight' if records else None,
                          'raw': records[0][0] if status == 'known' else None,
                          'record': dict(zip(('wins', 'losses', 'draws', 'nc'), records[0][1])) if status == 'known' else None,
                          'candidates': candidates}
    return evidence


def method_statistics(stats):
    if not isinstance(stats, dict) or stats.get('id') != 'fighterRecordStats' or stats.get('professional_mma') is not True:
        return None
    result = {}
    text = '\n'.join(stats.get('lines', [])) or stats.get('text', '')
    for label, key in [('KO/TKO', 'ko_tko'), ('SUB', 'submission'), ('DEC', 'decision')]:
        match = re.search(re.escape(label) + r'(?:MISSION|ISION)?\s*(\d+)\s*(?:wins?)?\s*(\d+)\s*(?:loss(?:es)?)?', text, re.I)
        if match:
            result[key] = {'wins': int(match[1]), 'losses': int(match[2])}
    return {'scope': 'professional_mma', 'source_element': 'fighterRecordStats', 'counts': result, 'raw': stats}


def career_history_evidence(snapshot, profile):
    loading = snapshot.get('history_loading_evidence') or {}
    counts = Counter(); seen = set(); valid = True
    for row in profile.get('fight_history', []):
        if any(row.get(k) is True for k in ('is_upcoming', 'is_cancelled', 'is_exhibition', 'is_amateur')) or row.get('discipline') == 'other':
            continue
        if row.get('record_eligible') is False and row.get('record_eligibility_evidence') == 'source_profile_record_ineligible_mma':
            continue
        key = row.get('source_bout_id')
        if not key or row.get('is_professional') is not True or row.get('discipline') != 'mma' or not row.get('classification_evidence') or row.get('result') not in RESULTS or not row.get('fight_date'):
            valid = False
            continue
        if key in seen:
            valid = False
            continue
        seen.add(key); counts[RESULTS[row['result']]] += 1
    record = profile.get('record_summary') or {}
    matches = all(isinstance(record.get(k), int) and not isinstance(record[k], bool) and record[k] == counts[k] for k in RESULTS.values())
    # No-button alone is insufficient: require a canonical DOM, every reachable
    # page/load-more result, no narrowing filter, every row's scope, and counts.
    pagination_complete = (loading.get('pagination_present') is False
                           or loading.get('all_pages_loaded') is True)
    filters_complete = (loading.get('filters_present') is False
                        or loading.get('filters_active') is False)
    loaded = (loading.get('contract_version') == 1 and loading.get('canonical_card_dom') is True
              and loading.get('load_complete') is True and loading.get('first_page_verified') is True
              and loading.get('navigation_exhausted') is True and loading.get('hidden_cards') == 0
              and pagination_complete and filters_complete)
    from .scoring_evidence import career_record_verified
    return {'contract_version': 1, 'scope': 'professional_mma_career_v1',
            'complete': bool(loaded and valid and matches and career_record_verified(profile)),
            'loading': loading, 'component_counts': dict(counts), 'aggregate_matches': matches,
            'row_scope_verified': valid, 'checked_through': str(snapshot.get('scraped_at') or '')[:10] or None,
            'parser_version': 'profile_dom_v4'}
