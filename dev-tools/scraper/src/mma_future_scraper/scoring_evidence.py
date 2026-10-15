"""Evidence for lifetime professional MMA aggregates and the shared scoring period."""
from __future__ import annotations
from datetime import date, datetime, timezone, timedelta
import re

PERIOD_START = "2026-05-09"
MAX_VERIFICATION_AGE_DAYS = 7

def current_roster_verified(proof) -> bool:
    return isinstance(proof,dict) and proof.get('verified') is True and proof.get('promotion')=='ufc' and proof.get('evidence')=='current_ufc_ranking_widget' and proof.get('widget_boundary_verified') is True and proof.get('logo_alt')=='UFC' and str(proof.get('heading','')).lower()=='ufc ranking' and bool(re.fullmatch(r'https://(?:www\.)?tapology\.com/rankings/ufc/[^/?#]+',str(proof.get('ranking_url',''))))

def career_record_verified(profile: dict) -> bool:
    record=profile.get('record_summary') or {};proof=profile.get('career_record_evidence') or {}
    if proof.get('verified') is not True or proof.get('scope')!='professional_mma' or not proof.get('record_raw') or proof.get('record_raw')!=record.get('pro_record_raw'):
        return False
    keys=('wins','losses','draws','no_contests')
    if any(not isinstance(record.get(key),int) or isinstance(record.get(key),bool) or not 0<=record[key]<=1000 for key in keys):
        return False
    match=re.fullmatch(r'\s*(\d{1,4})\s*-\s*(\d{1,4})\s*-\s*(\d{1,4})(?:\s*(?:,|\()\s*(\d{1,4})\s*NC\)?)?\s*',proof['record_raw'],re.I)
    if not match or [record[key] for key in keys]!=[int(match.group(n) or 0) for n in range(1,5)]:
        return False
    return (proof.get('evidence')=='explicit_mma_record_label' and proof.get('label') in ('Pro MMA Record','Professional MMA Record')) or (proof.get('evidence')=='profile_record_mma_context' and proof.get('label')=='Pro Record' and proof.get('context')=='professional_mma' and proof.get('record_context_verified') is True)


def career_record_evidence(snapshot: dict, record: dict) -> dict:
    raw = record.get("pro_record_raw")
    text = "\n".join(snapshot.get("lines", [])) + "\n" + snapshot.get("body_text", "")
    label = next((label for label in ("Professional MMA Record", "Pro MMA Record")
                  if re.search(r"\b" + re.escape(label) + r"\s*:?", text, re.I)), None)
    verified = bool(raw and label)
    evidence = "explicit_mma_record_label" if verified else None
    context_verified = snapshot.get("record_scope_verified") is True and snapshot.get("record_scope") == "professional_mma"
    if not verified and raw and context_verified and re.search(r"\bPro Record\s*:?", text, re.I):
        label, verified, evidence = "Pro Record", True, "profile_record_mma_context"
    return {"scope": "professional_mma" if verified else "unknown", "verified": verified,
            "label": label, "record_raw": raw, "evidence": evidence,
            "context": "professional_mma" if context_verified else None,
            "record_context_verified": context_verified}


def period_evidence(snapshot: dict, profile: dict) -> dict:
    dates = []
    trustworthy = True
    for row in profile.get("fight_history", []):
        if any(row.get(flag) is True for flag in ("is_upcoming", "is_cancelled", "is_amateur", "is_exhibition")):
            continue
        if row.get("discipline") and row["discipline"] != "mma":
            continue
        try:
            value = date.fromisoformat(row.get("fight_date", ""))
        except (ValueError, TypeError):
            trustworthy = False
            continue
        if value.isoformat()>=PERIOD_START and (row.get("is_professional") is not True or row.get("classification_evidence") not in {
            "profile_dom_classification", "profile_section_classification", "bout_dom_classification"
        }):
            trustworthy = False
        dates.append(value.isoformat())
    chronological = trustworthy and dates == sorted(dates, reverse=True)
    observed = snapshot.get("scraped_at")
    try:
        value=datetime.fromisoformat(observed.replace("Z", "+00:00"))
        if value.tzinfo is None:value=value.replace(tzinfo=timezone.utc)
        checked = value.astimezone(timezone.utc).date().isoformat()
    except (ValueError, TypeError, AttributeError):
        checked = None
    return {"start_date": PERIOD_START, "checked_through": checked,
            "scope_verified": profile.get("history_scope_verified") is True and profile.get("history_scope") == "professional_mma",
            "first_page_verified": snapshot.get("history_first_page_verified") is True,
            "load_complete": snapshot.get("history_load_complete") is True,
            "chronological_descending": chronological,
            "boundary_crossed": chronological and any(day < PERIOD_START for day in dates),
            "history_exhausted": snapshot.get("history_exhausted") is True,
            "parser_version": "scoring_period_v1"}


def needs_refresh(profile: dict, *, now: datetime | None = None, known_results: list[dict] | None = None) -> bool:
    """A failed attempt never replaces the successful observation used for this check."""
    now = now or datetime.now(timezone.utc)
    if profile.get('status')!='success':
        return True
    if re.search(r'\b(?:Tapology|advertis(?:ing|ement)|ad[- ]free|no ads)\b',str(profile.get('display_name') or ''),re.I):
        return True
    try:
        checked = datetime.fromisoformat(str(profile.get("scraped_at") or profile.get("profile_scraped_at")).replace("Z", "+00:00"))
        if checked.tzinfo is None:
            checked = checked.replace(tzinfo=timezone.utc)
    except (ValueError, TypeError):
        return True
    if not now - timedelta(days=MAX_VERIFICATION_AGE_DAYS) <= checked <= now:
        return True
    if not career_record_verified(profile):
        return True
    evidence = profile.get("period_evidence", {})
    if not (evidence.get("start_date")==PERIOD_START and evidence.get("checked_through")==checked.astimezone(timezone.utc).date().isoformat()
            and evidence.get("scope_verified") is True and evidence.get("first_page_verified") is True and evidence.get("load_complete") is True
            and evidence.get("chronological_descending") is True
            and (evidence.get("boundary_crossed") is True or evidence.get("history_exhausted") is True)):
        return True
    rows = profile.get("fight_history", [])
    for known in known_results or []:
        if not known.get("fight_date") or known["fight_date"] < PERIOD_START:
            continue
        matches = [row for row in rows if known.get("source_bout_id") and row.get("source_bout_id") == known["source_bout_id"]]
        if len(matches) != 1 or any(matches[0].get(key) != known.get(key) for key in ("fight_date", "result") if known.get(key) is not None):
            return True
    return False
