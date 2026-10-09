"""Read-only Fight History Staging Dry-Run.

Classifies each row in ``data/latest/fighter_profiles.json`` against rules for:

  * sport / type filtering
  * staging-candidate confidence
  * canonical dedup status (against an optional canonical dump)
  * prefight record availability / Formula v1.2 usability

The output is a JSON + optional Markdown report. The dry-run does not touch the
WordPress database, the canonical scraper state, or any flag/eligibility table.
"""

from __future__ import annotations

import json
import re
import statistics
from collections import Counter
from pathlib import Path
from typing import Any, Iterable

from .config import LATEST_DIR
from .fighter_profiles import extract_event_name_from_raw_text, scope_fight_row_text
from .storage import atomic_write_json
from .utils import utc_now


DRY_RUN_SCHEMA_VERSION = "fight_history_staging_dry_run_v0_1"

SUPPORTED_PROFILE_SCHEMAS = {
    "tapology_fighter_profiles_v0_1",
}


# ---------------------------------------------------------------------------
# Sport / type classification
# ---------------------------------------------------------------------------

SPORT_BOXING_CUES = (
    "boxing",
    "wba ",
    "wbc ",
    "wbo ",
    "ibf ",
    "boxrec",
)
SPORT_BARE_KNUCKLE_CUES = (
    "bkfc",
    "bare knuckle",
    "bare-knuckle",
    "bareknuckle",
    "bkb ",
)
SPORT_KICKBOXING_CUES = (
    "kickboxing",
    "k-1",
    "k1 ",
    "glory",
    "enfusion",
)
SPORT_MUAY_THAI_CUES = (
    "muay thai",
    "muaythai",
    "lumpinee",
    "rajadamnern",
    "one championship muay",
)
SPORT_GRAPPLING_CUES = (
    "grappling",
    "submission only",
    "polaris",
    "f2w",
    "fight2win",
    "adcc",
    "bjj",
    "jiu jitsu",
    "jiu-jitsu",
    "jiujitsu",
    "kasai",
    "nitro grappling",
    "no gi",
    "no-gi",
    "ebi ",
    "ibjjf",
)


def _row_signal_text(row: dict[str, Any]) -> str:
    parts: list[str] = []
    for key in ("event_name", "raw_text", "method", "method_category", "opponent_name"):
        value = row.get(key)
        if isinstance(value, str) and value:
            parts.append(value)
    return " ".join(parts).lower()


def classify_sport(row: dict[str, Any]) -> str:
    """Classify a single profile fight-history row into a sport/type bucket.

    Returns one of:
        mma_candidate, boxing, bare_knuckle, kickboxing, muay_thai, grappling,
        amateur, cancelled, overturned, unknown_sport
    """

    if not isinstance(row, dict):
        return "unknown_sport"

    if bool(row.get("is_cancelled")):
        return "cancelled"
    if bool(row.get("is_overturned")):
        return "overturned"
    if bool(row.get("is_amateur")):
        return "amateur"

    text = _row_signal_text(row)
    if not text:
        return "unknown_sport"

    # Bare-knuckle must be checked before "boxing" because BKFC events also
    # match the generic boxing cues.
    if any(cue in text for cue in SPORT_BARE_KNUCKLE_CUES):
        return "bare_knuckle"
    if any(cue in text for cue in SPORT_MUAY_THAI_CUES):
        return "muay_thai"
    if any(cue in text for cue in SPORT_KICKBOXING_CUES):
        return "kickboxing"
    if any(cue in text for cue in SPORT_GRAPPLING_CUES):
        return "grappling"
    if any(cue in text for cue in SPORT_BOXING_CUES):
        return "boxing"

    # No explicit non-MMA cue, no exclusion flag — treat as MMA candidate.
    return "mma_candidate"


# ---------------------------------------------------------------------------
# Staging-candidate confidence
# ---------------------------------------------------------------------------


_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def _has_text(value: Any) -> bool:
    return isinstance(value, str) and bool(value.strip())


def _has_known_method(row: dict[str, Any]) -> bool:
    method = row.get("method_category") or row.get("method")
    if not _has_text(method):
        return False
    method_str = str(method).strip().lower()
    if method_str in {"unknown", "n/a", ""}:
        # Tapology uses the literal string "unknown" for missing methods. We
        # still accept this as an explicit unknown marker because the task
        # spec lists "method or explicit unknown/decision marker" as the
        # required minimum.
        return True
    return True


def _has_valid_date(row: dict[str, Any]) -> bool:
    raw = row.get("fight_date")
    return isinstance(raw, str) and bool(_DATE_RE.match(raw))


def _has_event_reference(row: dict[str, Any]) -> bool:
    return _has_text(row.get("event_name")) or _has_text(row.get("event_url"))


def _has_url_evidence(row: dict[str, Any]) -> bool:
    return _has_text(row.get("event_url")) or _has_text(row.get("bout_url"))


def _has_both_prefight(row: dict[str, Any]) -> bool:
    return _has_text(row.get("prefight_record_raw")) and _has_text(row.get("opponent_record_raw"))


def classify_staging(row: dict[str, Any], *, sport_type: str, profile_fighter_id: str | None) -> str:
    """Return ``high``/``medium``/``low``/``not_staging_candidate``."""

    if sport_type not in {"mma_candidate"}:
        return "not_staging_candidate"

    required_present = (
        _has_valid_date(row)
        and _has_text(row.get("opponent_name"))
        and _has_text(row.get("result"))
        and _has_event_reference(row)
        and _has_known_method(row)
    )
    if not required_present:
        return "not_staging_candidate"
    if not _has_text(profile_fighter_id):
        # Without a fighter source identity we cannot stage safely.
        return "not_staging_candidate"

    has_url = _has_url_evidence(row)
    has_both_pre = _has_both_prefight(row)

    if has_url and has_both_pre:
        return "high"
    if has_both_pre:
        return "medium"
    return "low"


# ---------------------------------------------------------------------------
# Prefight record parsing
# ---------------------------------------------------------------------------


_RECORD_RE = re.compile(r"^\s*(\d{1,3})-(\d{1,3})(?:-(\d{1,3}))?(?:\s*[,(]\s*(\d{1,3})\s*NC\)?)?\s*$")


def parse_record(raw: Any) -> dict[str, Any]:
    """Parse a W-L-D[-NC] style record string into a dict.

    Returns ``{"is_missing": True}`` when the input cannot be parsed.
    """

    if not isinstance(raw, str):
        return {"raw": None, "is_missing": True}
    text = raw.strip()
    if not text or text.upper() == "N/A":
        return {"raw": raw, "is_missing": True}
    match = _RECORD_RE.match(text)
    if not match:
        return {"raw": raw, "is_missing": True}
    wins = int(match.group(1))
    losses = int(match.group(2))
    draws = int(match.group(3)) if match.group(3) is not None else 0
    nc = int(match.group(4)) if match.group(4) is not None else 0
    return {
        "raw": raw,
        "wins": wins,
        "losses": losses,
        "draws": draws,
        "nc": nc,
        "is_missing": False,
    }


def _record_total(record: dict[str, Any]) -> int | None:
    if record.get("is_missing"):
        return None
    return int(record["wins"]) + int(record["losses"]) + int(record.get("draws", 0)) + int(record.get("nc", 0))


def analyze_prefight(row: dict[str, Any]) -> dict[str, Any]:
    fighter_raw = row.get("prefight_record_raw")
    opponent_raw = row.get("opponent_record_raw")

    fighter_record = parse_record(fighter_raw)
    opponent_record = parse_record(opponent_raw)

    fighter_total = _record_total(fighter_record)
    opponent_total = _record_total(opponent_record)
    diff = None
    if fighter_total is not None and opponent_total is not None:
        diff = opponent_total - fighter_total

    formula_v12_ready = (
        not fighter_record.get("is_missing")
        and not opponent_record.get("is_missing")
    )

    return {
        "fighter_prefight_present": _has_text(fighter_raw),
        "opponent_prefight_present": _has_text(opponent_raw),
        "fighter_record_parsed": not fighter_record.get("is_missing"),
        "opponent_record_parsed": not opponent_record.get("is_missing"),
        "fighter_record": fighter_record,
        "opponent_record": opponent_record,
        "opponent_diff_prefight_total": diff,
        "formula_v12_ready": formula_v12_ready,
        "warning_missing_opponent_prefight": not _has_text(opponent_raw),
        "needs_bout_detail_fetch": not _has_text(opponent_raw) and not _has_text(row.get("bout_url")),
    }


# ---------------------------------------------------------------------------
# Canonical dedup comparison
# ---------------------------------------------------------------------------


def _normalize_name(value: Any) -> str:
    if not isinstance(value, str):
        return ""
    text = value.lower().strip()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def build_canonical_index(canonical_dump: dict[str, Any] | None) -> dict[str, Any]:
    """Build lookup indexes from a canonical-bouts dump.

    Dump shape (see :mod:`scripts.dump_canonical_bouts`)::

        {
            "schema_version": "canonical_bouts_dump_v0_1",
            "bouts": [
                {
                    "bout_id": int,
                    "event_id": int,
                    "source_bout_ids": [str],
                    "source_event_ids": [str],
                    "fighter_source_ids": [str],
                    "event_name": str,
                    "event_date": "YYYY-MM-DD",
                    "fighter_names": [str],
                    ...
                },
                ...
            ],
        }
    """

    by_source_bout_id: dict[str, dict[str, Any]] = {}
    by_event_date_fighter: dict[tuple[str, str, str], list[dict[str, Any]]] = {}
    by_event_name_date: dict[tuple[str, str], list[dict[str, Any]]] = {}

    if not canonical_dump:
        return {
            "available": False,
            "by_source_bout_id": by_source_bout_id,
            "by_event_date_fighter": by_event_date_fighter,
            "by_event_name_date": by_event_name_date,
            "total": 0,
        }

    bouts = canonical_dump.get("bouts") or []
    for bout in bouts:
        for src_id in bout.get("source_bout_ids") or []:
            if isinstance(src_id, str) and src_id:
                by_source_bout_id[src_id] = bout

        event_date = bout.get("event_date") or ""
        for fighter_src_id in bout.get("fighter_source_ids") or []:
            for opp_src_id in bout.get("fighter_source_ids") or []:
                if fighter_src_id == opp_src_id:
                    continue
                key = (event_date, fighter_src_id, opp_src_id)
                by_event_date_fighter.setdefault(key, []).append(bout)

        event_name_norm = _normalize_name(bout.get("event_name"))
        if event_name_norm and event_date:
            by_event_name_date.setdefault((event_date, event_name_norm), []).append(bout)

    return {
        "available": True,
        "by_source_bout_id": by_source_bout_id,
        "by_event_date_fighter": by_event_date_fighter,
        "by_event_name_date": by_event_name_date,
        "total": len(bouts),
    }


def compare_against_canonical(
    row: dict[str, Any],
    *,
    profile_source_fighter_id: str | None,
    canonical_index: dict[str, Any],
) -> dict[str, Any]:
    """Conservative read-only comparison.

    Returns ``{"status": <status>, "evidence": <reason>}`` where status is one
    of: ``already_canonical``, ``possible_duplicate``, ``missing_from_canonical``,
    ``ambiguous_duplicate``, ``insufficient_data``, ``canonical_dump_missing``.
    """

    if not canonical_index.get("available"):
        return {"status": "canonical_dump_missing", "evidence": "no canonical dump provided"}

    source_bout_id = row.get("source_bout_id")
    if isinstance(source_bout_id, str) and source_bout_id:
        hit = canonical_index["by_source_bout_id"].get(source_bout_id)
        if hit is not None:
            return {"status": "already_canonical", "evidence": f"source_bout_id={source_bout_id}"}

    event_date = row.get("fight_date") if isinstance(row.get("fight_date"), str) else ""
    opp_src_id = row.get("opponent_source_fighter_id")
    if not opp_src_id:
        opp_url = row.get("opponent_url")
        if isinstance(opp_url, str):
            # Tapology fighter URL ends with /fighters/{id}-...
            m = re.search(r"/fighters/(\d+)-", opp_url)
            if m:
                opp_src_id = f"tapology_fighter_{m.group(1)}"

    if event_date and profile_source_fighter_id and opp_src_id:
        key = (event_date, profile_source_fighter_id, opp_src_id)
        hits = canonical_index["by_event_date_fighter"].get(key) or []
        if len(hits) == 1:
            return {"status": "already_canonical", "evidence": "date+fighter+opponent source IDs"}
        if len(hits) > 1:
            return {"status": "ambiguous_duplicate", "evidence": "multiple canonical bouts share date+fighters"}

    event_name_norm = _normalize_name(row.get("event_name"))
    if event_date and event_name_norm:
        weak_hits = canonical_index["by_event_name_date"].get((event_date, event_name_norm)) or []
        if weak_hits:
            return {
                "status": "possible_duplicate",
                "evidence": "matched on event name+date only (weak signal)",
            }

    if not event_date or not _has_text(row.get("opponent_name")):
        return {"status": "insufficient_data", "evidence": "missing date or opponent"}

    return {"status": "missing_from_canonical", "evidence": "no canonical match found"}


# ---------------------------------------------------------------------------
# Recommended actions
# ---------------------------------------------------------------------------


def recommend_action(
    *,
    sport_type: str,
    staging_confidence: str,
    canonical_status: str,
    prefight: dict[str, Any],
    opponent_polluted: bool = False,
) -> str:
    if sport_type in {"boxing", "bare_knuckle", "kickboxing", "muay_thai", "grappling"}:
        return "ignore_non_mma"
    if sport_type == "cancelled":
        return "ignore_cancelled"
    if sport_type in {"amateur", "overturned"}:
        return "ignore_non_mma"
    if sport_type == "unknown_sport":
        return "needs_identity_review"
    if canonical_status == "already_canonical":
        return "already_canonical"
    if canonical_status == "ambiguous_duplicate":
        return "needs_identity_review"
    # A polluted opponent_name means the persisted parser output mixed in
    # neighbor-row fragments (method text, rules text, section heading,
    # date or record numbers). The row is NEVER a clean stage_for_review
    # candidate — admin review needs the per-bout detail page first.
    if opponent_polluted and sport_type == "mma_candidate":
        return "needs_bout_detail_fetch"
    if staging_confidence == "not_staging_candidate":
        return "insufficient_data"
    if prefight.get("needs_bout_detail_fetch") and staging_confidence == "low":
        return "needs_bout_detail_fetch"
    return "stage_for_review"


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------


def _extract_opponent_source_id(row: dict[str, Any]) -> str | None:
    url = row.get("opponent_url")
    if not isinstance(url, str):
        return None
    match = re.search(r"/fighters/(\d+)-", url)
    if match:
        return f"tapology_fighter_{match.group(1)}"
    return None


def _backfill_event_name_from_raw_text(row: dict[str, Any]) -> dict[str, Any]:
    """Populate ``event_name`` from ``raw_text`` when it is missing.

    The body-text parser in earlier scraper runs sometimes stored a row's
    raw_text without lifting the event label out of it (e.g. it required an
    ``R<n>`` round marker). This helper recovers the label conservatively for
    historical data without re-scraping. It is a no-op when ``event_name`` is
    already populated, when ``raw_text`` is missing, or when the extractor
    cannot find a plausible event name.
    """

    if not isinstance(row, dict):
        return row
    if _has_text(row.get("event_name")):
        return row
    raw_text = row.get("raw_text")
    if not isinstance(raw_text, str) or not raw_text:
        return row
    extracted = extract_event_name_from_raw_text(raw_text)
    if not extracted:
        return row
    updated = dict(row)
    updated["event_name"] = extracted
    return updated


_OPPONENT_POLLUTION_RE = re.compile(
    r"\b(?:"
    r"Modified\s+Rules"
    r"|Custom\s+Rules"
    r"|Decision"
    r"|Submission"
    r"|KO/TKO"
    r"|\bTKO\b"
    r"|Cancelled\s+Bout"
    r"|No\s+Contest"
    r"|AMATEUR\s+BOUTS"
    r"|PROFESSIONAL\s+BOUTS"
    r"|CANCELLED\s+BOUTS"
    r"|COMBAT\s+SPORTS\s+RECORD"
    r"|MMA\s+FIGHT\s+RECORD"
    r"|Sport\s+Filters"
    r"|Basic\s+Odds"
    r"|VIEW\s+MODE"
    r"|Grappling"
    r"|Bare\s+Knuckle"
    r"|Shootboxing"
    r"|Muay\s+Thai"
    r"|Referee\s+Stoppage"
    r"|Rear\s+Naked\s+Choke"
    r"|Front\s+Choke"
    r"|Armbar"
    r"|Ground\s+(?:&|and|Pound|Punch)"
    r")\b",
    re.IGNORECASE,
)

# Tapology row dates like "2007 May 19" — never belong inside an opponent name.
_OPPONENT_DATE_RE = re.compile(
    r"\b\d{4}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\b",
    re.IGNORECASE,
)
_OPPONENT_RECORD_RE = re.compile(r"\b\d{1,2}-\d{1,2}(?:-\d{1,2})?\b")
_OPPONENT_TIME_RE = re.compile(r"\b\d{1,2}:\d{2}\b")
_OPPONENT_ROUND_RE = re.compile(r"\bR\d+\b")


def _opponent_appears_polluted(opponent_name: str | None) -> bool:
    """Return True when the opponent_name contains stray bout fragments.

    A clean Tapology opponent is one or more proper-name tokens (sometimes
    separated by hyphens or apostrophes). Anything that includes method
    text, rules text, sport label, section heading, record numbers, date
    or round/time markers is row-boundary or column-boundary pollution
    from a neighbor row. The staging report should never act on such a
    row without per-bout detail re-fetch.
    """

    if not opponent_name:
        return False
    if _OPPONENT_POLLUTION_RE.search(opponent_name):
        return True
    if _OPPONENT_DATE_RE.search(opponent_name):
        return True
    if _OPPONENT_RECORD_RE.search(opponent_name):
        return True
    if _OPPONENT_TIME_RE.search(opponent_name):
        return True
    if _OPPONENT_ROUND_RE.search(opponent_name):
        return True
    return False


def _reclassify_exclusion_flags(
    row: dict[str, Any], scoped_text: str
) -> tuple[dict[str, Any], list[str]]:
    """Re-derive ``is_cancelled``/``is_amateur``/``is_overturned`` from
    the scoped row text only.

    Persisted flags on profile fight_history rows produced by older
    parser runs can be polluted by neighbor-row text (a completed pro
    bout inheriting ``is_amateur=True`` because the next AMATEUR BOUTS
    section heading was inside the container element's innerText). The
    staging dry-run trusts the SCOPED text — anything it does not see
    cannot mark the row as excluded.

    Returns ``(updated_row, warnings)`` where warnings describe each
    persisted flag that was overridden so admins can audit the change.
    """

    flags_text = (scoped_text or "").lower()
    result = (row.get("result") or "").lower() if isinstance(row.get("result"), str) else ""
    new_is_cancelled = (
        bool(re.search(r"\bcancel(?:l?ed)?\b|\bscrapped\b", flags_text))
        or result == "cancelled"
    )
    new_is_amateur = bool(re.search(r"\bamateur\b", flags_text))
    new_is_overturned = bool(re.search(r"\boverturned\b|\bno\s+contest\b|\bresult\s+changed\b", flags_text))

    warnings: list[str] = []
    if row.get("is_cancelled") and not new_is_cancelled:
        warnings.append("exclusion_flag_from_neighbor_suspected:cancelled")
    if row.get("is_amateur") and not new_is_amateur:
        warnings.append("exclusion_flag_from_neighbor_suspected:amateur")
    if row.get("is_overturned") and not new_is_overturned:
        warnings.append("exclusion_flag_from_neighbor_suspected:overturned")

    updated = dict(row)
    updated["is_cancelled"] = new_is_cancelled
    updated["is_amateur"] = new_is_amateur
    updated["is_overturned"] = new_is_overturned
    return updated, warnings


def _scope_and_reclassify_row(row: dict[str, Any]) -> tuple[dict[str, Any], list[str]]:
    """Scope raw_text to one fight, then re-derive exclusion flags.

    Returns the updated row plus row-level staging warnings describing
    every override. The helper is idempotent on fresh (already-scoped)
    parser output: when scoping changes nothing and no flag needs to
    flip, the original row + an empty warning list are returned.
    """

    if not isinstance(row, dict):
        return row, []
    raw_text = row.get("raw_text")
    original_text = raw_text if isinstance(raw_text, str) else ""
    scoped_text = scope_fight_row_text(original_text) if original_text else ""

    warnings: list[str] = []
    if scoped_text and scoped_text != original_text:
        warnings.append("row_boundary_suspected")

    updated, flag_warnings = _reclassify_exclusion_flags(row, scoped_text or original_text)
    warnings.extend(flag_warnings)

    if scoped_text and scoped_text != original_text:
        updated["raw_text"] = scoped_text

    if _opponent_appears_polluted(updated.get("opponent_name")):
        warnings.append("polluted_opponent_suspected")

    return updated, warnings


def _profile_source_id(profile: dict[str, Any]) -> str | None:
    """Return a Tapology source-identity string for the profile.

    Prefers an explicit numeric source_fighter_id when present. Falls back to
    the numeric ID parsed from source_url, then to the source_url itself
    (which is a valid Tapology slug-based source identity even when no
    numeric ID is available).
    """
    source_id = profile.get("source_fighter_id")
    if isinstance(source_id, str) and source_id:
        return source_id
    url = profile.get("source_url")
    if isinstance(url, str) and url:
        match = re.search(r"/fighters/(\d+)-", url)
        if match:
            return f"tapology_fighter_{match.group(1)}"
        # Slug-only Tapology profile URLs are a legitimate (weaker) source
        # identity. Return the URL itself so staging downstream can still
        # reason about the fighter while flagging the row as lower confidence.
        return url
    return None


def classify_rows(profiles_document: dict[str, Any], canonical_index: dict[str, Any]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    profiles = profiles_document.get("profiles") or []
    for profile in profiles:
        if not isinstance(profile, dict):
            continue
        if profile.get("status") != "success":
            continue
        history = profile.get("fight_history") or []
        if not history:
            continue
        profile_fighter_id = _profile_source_id(profile)
        for row in history:
            if not isinstance(row, dict):
                continue
            row, row_warnings = _scope_and_reclassify_row(row)
            row = _backfill_event_name_from_raw_text(row)
            sport = classify_sport(row)
            staging = classify_staging(row, sport_type=sport, profile_fighter_id=profile_fighter_id)
            prefight = analyze_prefight(row)
            canonical = compare_against_canonical(
                row,
                profile_source_fighter_id=profile_fighter_id,
                canonical_index=canonical_index,
            )
            opponent_polluted = "polluted_opponent_suspected" in row_warnings
            action = recommend_action(
                sport_type=sport,
                staging_confidence=staging,
                canonical_status=canonical["status"],
                prefight=prefight,
                opponent_polluted=opponent_polluted,
            )
            rows.append(
                {
                    "profile": {
                        "display_name": profile.get("display_name"),
                        "source_fighter_id": profile_fighter_id,
                        "source_url": profile.get("source_url"),
                    },
                    "row": {
                        "fight_date": row.get("fight_date"),
                        "opponent_name": row.get("opponent_name"),
                        "opponent_url": row.get("opponent_url"),
                        "opponent_source_fighter_id": _extract_opponent_source_id(row),
                        "result": row.get("result"),
                        "method": row.get("method"),
                        "method_category": row.get("method_category"),
                        "round": row.get("round"),
                        "time": row.get("time"),
                        "event_name": row.get("event_name"),
                        "event_url": row.get("event_url"),
                        "source_event_id": row.get("source_event_id"),
                        "bout_url": row.get("bout_url"),
                        "source_bout_id": row.get("source_bout_id"),
                        "fighter_prefight_record_raw": row.get("prefight_record_raw"),
                        "opponent_prefight_record_raw": row.get("opponent_record_raw"),
                    },
                    "classification": {
                        "sport_type": sport,
                        "staging_confidence": staging,
                        "canonical_match_status": canonical["status"],
                        "canonical_match_evidence": canonical["evidence"],
                        "recommended_action": action,
                    },
                    "prefight": prefight,
                    "warnings": row_warnings,
                }
            )
    return rows


def _summary_counts(rows: list[dict[str, Any]], profiles_document: dict[str, Any]) -> dict[str, Any]:
    sport_counter: Counter[str] = Counter()
    staging_counter: Counter[str] = Counter()
    canonical_counter: Counter[str] = Counter()
    action_counter: Counter[str] = Counter()
    warning_counter: Counter[str] = Counter()
    rows_with_opponent_prefight = 0
    rows_missing_opponent_prefight = 0
    rows_with_both_prefight = 0
    rows_formula_v12_ready = 0
    rows_needing_bout_detail = 0
    rows_with_warnings = 0
    rows_with_polluted_opponent = 0
    rows_with_row_boundary_suspect = 0
    rows_with_exclusion_neighbor_suspect = 0

    for entry in rows:
        sport_counter[entry["classification"]["sport_type"]] += 1
        staging_counter[entry["classification"]["staging_confidence"]] += 1
        canonical_counter[entry["classification"]["canonical_match_status"]] += 1
        action_counter[entry["classification"]["recommended_action"]] += 1
        pf = entry["prefight"]
        if pf["opponent_prefight_present"]:
            rows_with_opponent_prefight += 1
        else:
            rows_missing_opponent_prefight += 1
        if pf["fighter_prefight_present"] and pf["opponent_prefight_present"]:
            rows_with_both_prefight += 1
        if pf["formula_v12_ready"]:
            rows_formula_v12_ready += 1
        if pf["needs_bout_detail_fetch"]:
            rows_needing_bout_detail += 1
        warnings = entry.get("warnings") or []
        if warnings:
            rows_with_warnings += 1
        warning_bases: set[str] = set()
        for w in warnings:
            warning_counter[w] += 1
            warning_bases.add(w.split(":", 1)[0])
        if "polluted_opponent_suspected" in warning_bases:
            rows_with_polluted_opponent += 1
        if "row_boundary_suspected" in warning_bases:
            rows_with_row_boundary_suspect += 1
        if "exclusion_flag_from_neighbor_suspected" in warning_bases:
            rows_with_exclusion_neighbor_suspect += 1

    profiles = profiles_document.get("profiles") or []
    profiles_total = len(profiles)
    profiles_success = sum(1 for p in profiles if isinstance(p, dict) and p.get("status") == "success")

    non_mma_buckets = {"boxing", "bare_knuckle", "kickboxing", "muay_thai", "grappling"}
    exclusion_buckets = {"amateur", "cancelled", "overturned"}

    return {
        "profiles_total": profiles_total,
        "profiles_success": profiles_success,
        "profile_history_rows_total": len(rows),
        "rows_mma_candidate": sport_counter.get("mma_candidate", 0),
        "rows_non_mma_filtered": sum(sport_counter.get(b, 0) for b in non_mma_buckets),
        "rows_amateur_cancelled_overturned": sum(sport_counter.get(b, 0) for b in exclusion_buckets),
        "rows_unknown_sport": sport_counter.get("unknown_sport", 0),
        "staging_high_confidence": staging_counter.get("high", 0),
        "staging_medium_confidence": staging_counter.get("medium", 0),
        "staging_low_confidence": staging_counter.get("low", 0),
        "not_staging_candidate": staging_counter.get("not_staging_candidate", 0),
        "already_canonical": canonical_counter.get("already_canonical", 0),
        "candidate_new_historical_bouts": canonical_counter.get("missing_from_canonical", 0),
        "ambiguous_duplicates": canonical_counter.get("ambiguous_duplicate", 0),
        "possible_duplicates_weak": canonical_counter.get("possible_duplicate", 0),
        "canonical_insufficient_data": canonical_counter.get("insufficient_data", 0),
        "canonical_dump_missing": canonical_counter.get("canonical_dump_missing", 0),
        "rows_with_opponent_prefight_record": rows_with_opponent_prefight,
        "rows_missing_opponent_prefight_record": rows_missing_opponent_prefight,
        "rows_with_both_prefight_records": rows_with_both_prefight,
        "rows_formula_v12_ready": rows_formula_v12_ready,
        "rows_needing_bout_detail_fetch": rows_needing_bout_detail,
        "rows_with_warnings": rows_with_warnings,
        "rows_with_polluted_opponent": rows_with_polluted_opponent,
        "rows_with_row_boundary_suspect": rows_with_row_boundary_suspect,
        "rows_with_exclusion_neighbor_suspect": rows_with_exclusion_neighbor_suspect,
        "by_sport_type": dict(sport_counter),
        "by_staging_confidence": dict(staging_counter),
        "by_canonical_status": dict(canonical_counter),
        "by_recommended_action": dict(action_counter),
        "by_warning": dict(warning_counter),
    }


def build_dry_run_report(
    profiles_document: dict[str, Any],
    *,
    canonical_dump: dict[str, Any] | None = None,
    canonical_dump_path: Path | None = None,
    sample_rows: int = 25,
) -> dict[str, Any]:
    canonical_index = build_canonical_index(canonical_dump)
    rows = classify_rows(profiles_document, canonical_index)
    summary = _summary_counts(rows, profiles_document)

    sample = rows[:sample_rows]

    return {
        "schema_version": DRY_RUN_SCHEMA_VERSION,
        "generated_at": utc_now(),
        "input": {
            "profile_schema_version": profiles_document.get("schema_version"),
            "profile_run_id": profiles_document.get("run_id"),
            "profile_scraped_at": profiles_document.get("scraped_at"),
            "profiles_total": profiles_document.get("profiles_total"),
            "profiles_success": profiles_document.get("profiles_success"),
        },
        "canonical_dump": {
            "available": canonical_index.get("available", False),
            "total_bouts": canonical_index.get("total", 0),
            "source_path": str(canonical_dump_path) if canonical_dump_path else None,
            "schema_version": (canonical_dump or {}).get("schema_version"),
            "generated_at": (canonical_dump or {}).get("generated_at"),
        },
        "summary": summary,
        "sample_rows": sample,
        "rows": rows,
        "non_writable_phase": True,
        "writes_db": False,
    }


def write_dry_run_report(
    profiles_document: dict[str, Any],
    *,
    canonical_dump: dict[str, Any] | None = None,
    canonical_dump_path: Path | None = None,
    output_json: Path | None = None,
    output_markdown: Path | None = None,
    sample_rows: int = 25,
) -> dict[str, Any]:
    output_json = output_json or LATEST_DIR / "fight_history_staging_dry_run.json"
    output_markdown = output_markdown or LATEST_DIR / "fight_history_staging_dry_run.md"
    report = build_dry_run_report(
        profiles_document,
        canonical_dump=canonical_dump,
        canonical_dump_path=canonical_dump_path,
        sample_rows=sample_rows,
    )
    output_json.parent.mkdir(parents=True, exist_ok=True)
    atomic_write_json(output_json, report)
    if output_markdown is not None:
        output_markdown.write_text(_format_markdown(report), encoding="utf-8")
    return {
        "report": report,
        "paths": {
            "json": str(output_json),
            "markdown": str(output_markdown) if output_markdown else None,
        },
    }


def _format_markdown(report: dict[str, Any]) -> str:
    summary = report["summary"]
    canonical = report["canonical_dump"]
    lines: list[str] = []
    lines.append("# Fight History Staging Dry-Run")
    lines.append("")
    lines.append(f"- Generated at: `{report.get('generated_at')}`")
    lines.append(f"- Profile schema: `{report['input'].get('profile_schema_version')}`")
    lines.append(f"- Profile run id: `{report['input'].get('profile_run_id')}`")
    lines.append(f"- Profiles total: {report['input'].get('profiles_total')}")
    lines.append(f"- Profiles success: {report['input'].get('profiles_success')}")
    lines.append("")
    lines.append("## Canonical comparison input")
    lines.append("")
    if canonical.get("available"):
        lines.append(f"- Canonical dump: `{canonical.get('source_path')}`")
        lines.append(f"- Canonical bouts loaded: {canonical.get('total_bouts')}")
    else:
        lines.append("- Canonical dump: not provided. Dedup comparison reported as `canonical_dump_missing`.")
    lines.append("")
    lines.append("## Summary counts")
    lines.append("")
    keys = [
        ("profile_history_rows_total", "Profile history rows total"),
        ("rows_mma_candidate", "Rows classified as MMA candidates"),
        ("rows_non_mma_filtered", "Rows filtered as non-MMA (boxing/grappling/kickboxing/muay thai/bare knuckle)"),
        ("rows_amateur_cancelled_overturned", "Rows excluded as amateur/cancelled/overturned"),
        ("rows_unknown_sport", "Rows with unknown sport"),
        ("staging_high_confidence", "Staging candidates (high)"),
        ("staging_medium_confidence", "Staging candidates (medium)"),
        ("staging_low_confidence", "Staging candidates (low)"),
        ("not_staging_candidate", "Not staging candidates"),
        ("already_canonical", "Already canonical"),
        ("candidate_new_historical_bouts", "Candidate new historical bouts"),
        ("possible_duplicates_weak", "Possible duplicates (weak match)"),
        ("ambiguous_duplicates", "Ambiguous duplicates"),
        ("canonical_insufficient_data", "Canonical comparison: insufficient data"),
        ("canonical_dump_missing", "Canonical comparison: dump missing"),
        ("rows_with_opponent_prefight_record", "Rows with opponent prefight record"),
        ("rows_missing_opponent_prefight_record", "Rows missing opponent prefight record"),
        ("rows_with_both_prefight_records", "Rows with both prefight records"),
        ("rows_formula_v12_ready", "Rows usable for Formula v1.2"),
        ("rows_needing_bout_detail_fetch", "Rows needing per-bout detail fetch"),
        ("rows_with_warnings", "Rows with any staging warning"),
        ("rows_with_polluted_opponent", "Rows with polluted opponent name"),
        ("rows_with_row_boundary_suspect", "Rows with row-boundary pollution suspected"),
        ("rows_with_exclusion_neighbor_suspect", "Rows with exclusion flag from neighbor suspected"),
    ]
    for key, label in keys:
        lines.append(f"- {label}: {summary.get(key)}")
    lines.append("")
    lines.append("## Recommended actions")
    lines.append("")
    for action, count in sorted(summary.get("by_recommended_action", {}).items()):
        lines.append(f"- {action}: {count}")
    lines.append("")
    lines.append("## Sample rows")
    lines.append("")
    for sample in report.get("sample_rows", []):
        profile_name = sample["profile"].get("display_name")
        row = sample["row"]
        cls = sample["classification"]
        pre = sample["prefight"]
        lines.append(
            f"- {profile_name} | {row.get('fight_date')} | vs {row.get('opponent_name')} | {cls['sport_type']} | "
            f"{cls['staging_confidence']} | canonical={cls['canonical_match_status']} | "
            f"action={cls['recommended_action']} | formula_v12={pre['formula_v12_ready']}"
        )
    lines.append("")
    lines.append("This dry-run does not write canonical events/bouts/participants/stats/rankings.")
    return "\n".join(lines) + "\n"


def load_profiles_document(path: Path) -> dict[str, Any]:
    document = json.loads(path.read_text(encoding="utf-8"))
    schema = document.get("schema_version")
    if schema not in SUPPORTED_PROFILE_SCHEMAS:
        raise ValueError(f"Unsupported profile schema version: {schema!r}")
    return document


def load_canonical_dump(path: Path | None) -> dict[str, Any] | None:
    if path is None:
        return None
    if not path.exists():
        return None
    return json.loads(path.read_text(encoding="utf-8"))
