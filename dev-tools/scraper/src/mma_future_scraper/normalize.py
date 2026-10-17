from __future__ import annotations

import re
from typing import Any

from .utils import normalize_space


MISSING_VALUES = {"", "n/a", "na", "unknown", "-", "--", "tbd", "none"}
BOUT_FLAG_KEYS = (
    "is_completed",
    "is_professional",
    "is_amateur",
    "is_cancelled",
    "is_overturned",
    "is_upcoming",
    "is_scoring_candidate",
    "is_bout_scoring_candidate",
)

KNOWN_VALID_MMA_EVENT_PATTERNS = (
    r"\bpancrase\s+hybrid\s+wrestling\b",
    r"\bpancrase\b",
    r"\bpfl\b",
    r"\bprofessional\s+fighters\s+league\b",
    r"\bbrave\s+(?:combat\s+federation|cf)\b",
    r"\bshooto\b",
    r"\bshamrock\s+(?:fighting\s+championships|fc)\b",
    r"\btough\s+n\s+xtreme\b",
    r"\btnx\b",
)


def parse_prefight_record(raw: str | None) -> dict[str, Any]:
    raw_text = normalize_space(raw) or "N/A"
    normalized = raw_text.lower()
    if normalized in MISSING_VALUES:
        return {
            "raw": raw_text,
            "wins": None,
            "losses": None,
            "draws": None,
            "nc": None,
            "is_missing": True,
        }

    record_match = re.search(r"\b(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?\b", raw_text)
    if not record_match:
        return {
            "raw": raw_text,
            "wins": None,
            "losses": None,
            "draws": None,
            "nc": None,
            "is_missing": True,
        }

    nc_match = re.search(r"\b(\d+)\s*NC\b", raw_text, re.I)
    return {
        "raw": raw_text,
        "wins": int(record_match.group(1)),
        "losses": int(record_match.group(2)),
        "draws": int(record_match.group(3) or 0),
        "nc": int(nc_match.group(1)) if nc_match else 0,
        "is_missing": False,
    }


def prefight_record_warnings(record: dict[str, Any]) -> list[str]:
    return ["missing_prefight_record"] if record.get("is_missing") else []


def normalize_method(raw: str | None) -> dict[str, Any]:
    method_raw = normalize_space(raw)
    normalized = method_raw.lower()
    warnings: list[str] = []

    if normalized in MISSING_VALUES:
        warnings.append("unknown_method")
        return {
            "method_category": "unknown",
            "method_detail": None,
            "warnings": warnings,
        }

    detail = _method_detail(method_raw)
    if any(token in normalized for token in ["ko/tko", "tko", "ko", "knockout"]):
        category = "ko_tko"
    elif any(
        token in normalized
        for token in [
            "submission",
            "sub ",
            "choke",
            "armbar",
            "kimura",
            "triangle",
            "guillotine",
            "heel hook",
            "kneebar",
            "toe hold",
        ]
    ):
        category = "submission"
    elif any(token in normalized for token in ["punch", "elbow", "knee", "kick", "strikes", "slam"]):
        category = "ko_tko"
    elif "decision" in normalized or re.search(r"\bdec\b", normalized):
        category = "decision"
    elif normalized in {"dq", "disqualification"} or "disqual" in normalized:
        category = "dq"
    elif normalized in {"nc", "no contest"} or "no contest" in normalized:
        category = "no_contest"
    elif "draw" in normalized:
        category = "draw"
    elif "cancel" in normalized or "scrapped" in normalized:
        category = "unknown"
    else:
        category = "unknown"
        warnings.append("unknown_method")

    return {
        "method_category": category,
        "method_detail": detail,
        "warnings": warnings,
    }


def _method_detail(method_raw: str) -> str | None:
    text = normalize_space(method_raw)
    lowered = text.lower()
    decision_match = re.search(r"\b(unanimous|split|majority)\b", lowered)
    if decision_match:
        return decision_match.group(1)
    if "," in text:
        detail = normalize_space(text.split(",", 1)[1])
    else:
        detail = re.sub(r"\b(KO/TKO|TKO|KO|Submission|Decision|DQ|Disqualification|No Contest|NC|Draw)\b", "", text, flags=re.I)
        detail = normalize_space(detail)
    if not detail or detail.lower() in MISSING_VALUES:
        return None
    return detail.title()


def parse_round(raw_text: str | None) -> int | None:
    text = normalize_space(raw_text)
    match = re.search(r"\b(?:round|rd|r)\.?\s*(\d{1,2})\b", text, re.I)
    if not match:
        return None
    return int(match.group(1))


def parse_time(raw_text: str | None) -> str | None:
    text = normalize_space(raw_text)
    match = re.search(r"\b(\d{1,2}:\d{2})\b", text)
    return match.group(1) if match else None


def result_time_semantics(
    method_category: str | None,
    round_number: int | None,
    time_value: str | None,
    raw_text: str | None = None,
) -> str:
    if not time_value:
        return "unknown"
    method = normalize_space(method_category).lower()
    context = normalize_space(raw_text).lower()
    if "total" in context:
        return "total_duration"
    if method == "decision":
        minutes_match = re.fullmatch(r"(\d{1,2}):\d{2}", time_value)
        minutes = int(minutes_match.group(1)) if minutes_match else None
        if minutes is not None and minutes >= 10:
            return "total_duration"
        return "unknown"
    if method in {"ko_tko", "submission", "dq"} and round_number is not None:
        return "round_clock"
    return "unknown"


def has_completed_result(raw_text: str | None) -> bool:
    text = normalize_space(raw_text).lower()
    if not text:
        return False
    if any(token in text for token in ["cancelled", "canceled", "scrapped", "postponed"]):
        return False
    if re.search(r"\bw\b.+\bl\b", text) or re.search(r"\bl\b.+\bw\b", text):
        return True
    result_patterns = [
        r"\bdef\.?\b",
        r"\bdefeated\b",
        r"\bwin\b",
        r"\bloss\b",
        r"\bko/tko\b",
        r"\btko\b",
        r"\bko\b",
        r"\bsubmission\b",
        r"\bdecision\b",
        r"\bdq\b",
        r"\bdisqualification\b",
        r"\bno contest\b",
        r"\bnc\b",
        r"\bdraw\b",
    ]
    return any(re.search(pattern, text) for pattern in result_patterns)


def professional_status(raw_text: str | None) -> str:
    text = normalize_space(raw_text).lower()
    if re.search(r"\bamateur\b|\bamat\b", text):
        return "amateur"
    if re.search(r"\bpro\b|\bprofessional\b", text):
        return "pro"
    return "unknown"


def explicit_non_mma_event_reason(
    event_name: str | None = None,
    promotion_name: str | None = None,
    promotion_url: str | None = None,
    enclosure: str | None = None,
    venue: str | None = None,
    raw_text: str | None = None,
) -> str | None:
    metadata_text = _join_normalized(event_name, promotion_name, _promotion_slug_text(promotion_url), enclosure)
    metadata_lower = metadata_text.lower()
    raw_lower = normalize_space(raw_text).lower()
    venue_lower = normalize_space(venue).lower()
    context_text = _join_normalized(metadata_lower, venue_lower, raw_lower).lower()

    if _is_known_valid_mma_event(metadata_lower):
        return None

    if re.search(r"\breal\s+american\s+freestyle\b|\braf\b", metadata_lower):
        return "non_mma_event_freestyle"
    if re.search(r"\bfreestyle\s+wrestling\b", metadata_lower):
        return "non_mma_event_freestyle"
    if re.search(r"\bopen\s+mat\b", metadata_lower):
        return "non_mma_event_open_mat"
    freestyle_metadata_context = _join_normalized(metadata_lower, venue_lower).lower()
    if re.search(r"\b(?:tech\s+fall|pinfall)\b", raw_lower) and re.search(
        r"\b(?:real\s+american\s+freestyle|raf|open\s+mat|freestyle(?:\s+wrestling)?)\b",
        freestyle_metadata_context,
    ):
        return "non_mma_event_freestyle"

    if re.search(r"\b(?:boxeo|de\s+box)\b", metadata_lower):
        return "non_mma_event_boxeo"
    if re.search(r"\b(?:boxing|of\s+boxing)\b", metadata_lower):
        return "non_mma_event_boxing"
    if re.search(r"\bboxing\s+bouts?\s*:\s*\d+\b", raw_lower):
        return "non_mma_event_boxing"
    if re.search(r"\b(?:sport|bout\s+billing|rules?)\s*:\s*boxing\b", raw_lower):
        return "non_mma_event_boxing"
    return None


def is_explicit_non_mma_bout(raw_text: str | None) -> bool:
    text = normalize_space(raw_text).lower()
    if re.search(r"\bmma\b", text):
        return False
    non_mma_patterns = [
        r"\bboxeo\b",
        r"\bboxing\b",
        r"\bboxing\s+bouts?\b",
        r"\bmodified\s+boxing\b",
        r"\bcustom\s+rules\b",
        r"\bkick\s*boxing\b",
        r"\bkickboxing\b",
        r"\bmuay\s+thai\b",
        r"\bgrappl(?:e|ing)\b",
        r"\bsubmission\s+grappling\b",
        r"\bjiu[-\s]?jitsu\b",
        r"\bbjj\b",
        r"\bno[-\s]?gi\b",
        r"\bcombat\s+jiu[-\s]?jitsu\b",
        r"\bbare[-\s]?knuckle\b",
        r"\bbkfc\b",
        r"\bbkb\b",
        r"\bopen\s+mat\b",
        r"\btech\s+fall\b",
        r"\bpinfall\b",
        r"\bfreestyle\s+wrestling\b",
    ]
    return bool(re.search("|".join(non_mma_patterns), text))


def _join_normalized(*values: str | None) -> str:
    return normalize_space(" ".join(normalize_space(value) for value in values if normalize_space(value)))


def _promotion_slug_text(promotion_url: str | None) -> str:
    url = normalize_space(promotion_url).lower()
    match = re.search(r"/(?:fightcenter/)?promotions/\d+-([^/?#]+)", url)
    if not match:
        return ""
    return normalize_space(match.group(1).replace("-", " "))


def _is_known_valid_mma_event(metadata_lower: str) -> bool:
    return any(re.search(pattern, metadata_lower) for pattern in KNOWN_VALID_MMA_EVENT_PATTERNS)


def is_ufc_promotion(
    promotion_name: str | None,
    promotion_url: str | None = None,
    event_name: str | None = None,
) -> bool:
    name = normalize_space(promotion_name).lower()
    name_normalized = re.sub(r"[^a-z0-9]+", " ", name).strip()
    if name_normalized in {"ufc", "ultimate fighting championship"}:
        return True

    url = normalize_space(promotion_url).lower()
    slug_match = re.search(r"/(?:fightcenter/)?promotions/\d+-([^/?#]+)", url)
    slug = slug_match.group(1) if slug_match else ""
    slug_tokens = {token for token in re.split(r"[-_]+", slug) if token}
    if "ufc" in slug_tokens:
        return True
    if {"ultimate", "fighting", "championship"}.issubset(slug_tokens):
        return True

    event = normalize_space(event_name)
    return bool(re.search(r"^UFC(?:\s+(?:\d+|Fight Night|on\b|Vegas\b|[A-Z]{2,4}\b)|\s*:)", event, re.I))


def build_bout_flags(bout: dict[str, Any]) -> dict[str, bool]:
    status = normalize_space(bout.get("status")).lower()
    pro_am = normalize_space(bout.get("pro_am")).lower()
    result_type = normalize_space(bout.get("result_type")).lower()
    result = bout.get("result") if isinstance(bout.get("result"), dict) else {}
    method_category = normalize_space(result.get("method_category")).lower()

    is_amateur = pro_am == "amateur"
    is_professional = pro_am in {"professional", "pro"}
    is_cancelled = status in {"cancelled", "canceled", "scrapped", "postponed"} or result_type == "cancelled"
    is_upcoming = status == "upcoming"
    is_overturned = bool(bout.get("is_overturned")) or status == "overturned" or result_type == "overturned"
    is_completed = status == "completed" and not is_cancelled and not is_upcoming
    is_scoring_candidate = (
        is_completed
        and is_professional
        and not is_amateur
        and not is_cancelled
        and not is_overturned
        and not is_upcoming
        and result_type == "win_loss"
        and method_category not in {"draw", "no_contest"}
    )

    return {
        "is_completed": is_completed,
        "is_professional": is_professional,
        "is_amateur": is_amateur,
        "is_cancelled": is_cancelled,
        "is_overturned": is_overturned,
        "is_upcoming": is_upcoming,
        "is_scoring_candidate": is_scoring_candidate,
        "is_bout_scoring_candidate": is_scoring_candidate,
    }


def infer_status_flags(raw_text: str | None, *, expected_upcoming: bool = False) -> dict[str, Any]:
    text = normalize_space(raw_text).lower()
    is_cancelled = any(token in text for token in ["cancelled", "canceled", "scrapped"])
    is_overturned = any(token in text for token in ["overturned", "no contest", " nc "])
    status = professional_status(text)
    is_amateur = True if status == "amateur" else (False if status == "pro" else None)
    is_professional = True if status == "pro" else (False if status == "amateur" else None)
    is_upcoming = expected_upcoming or any(token in text for token in ["upcoming", "scheduled", "vs."])
    is_completed = not is_upcoming and not is_cancelled
    warnings: list[str] = []

    if is_professional is None and is_amateur is None:
        warnings.append("unknown_professional_status")

    is_scoring_candidate = (
        is_professional is True
        and is_amateur is False
        and is_completed
        and not is_cancelled
        and not is_overturned
    )

    return {
        "is_professional": is_professional,
        "is_amateur": is_amateur,
        "is_cancelled": is_cancelled,
        "is_overturned": is_overturned,
        "is_upcoming": is_upcoming,
        "is_completed": is_completed,
        "is_scoring_candidate": is_scoring_candidate,
        "fight_status": "cancelled" if is_cancelled else ("upcoming" if is_upcoming else "completed"),
        "warnings": warnings,
    }
