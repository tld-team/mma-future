from __future__ import annotations

import hashlib
import json
import re
from typing import Any

from .normalize import build_bout_flags
from .utils import (
    canonical_url,
    normalize_space,
    parse_location_parts,
    source_bout_id_from_url,
    source_event_id_from_url,
    source_fighter_id_from_url,
)


SOURCE = "tapology"


def numeric_id_from_source_id(source_id: str | None, prefix: str) -> str | None:
    if not source_id:
        return None
    match = re.fullmatch(rf"{re.escape(prefix)}_(\d+)", source_id)
    return match.group(1) if match else None


def source_event_numeric_id(event_url: str | None, source_event_id: str | None = None) -> str | None:
    return numeric_id_from_source_id(source_event_id, "tapology_event") or numeric_id_from_source_id(
        source_event_id_from_url(event_url), "tapology_event"
    )


def source_bout_numeric_id(bout_url: str | None, source_bout_id: str | None = None) -> str | None:
    return numeric_id_from_source_id(source_bout_id, "tapology_bout") or numeric_id_from_source_id(
        source_bout_id_from_url(bout_url), "tapology_bout"
    )


def source_fighter_numeric_id(fighter_url: str | None, source_fighter_id: str | None = None) -> str | None:
    return numeric_id_from_source_id(source_fighter_id, "tapology_fighter") or numeric_id_from_source_id(
        source_fighter_id_from_url(fighter_url), "tapology_fighter"
    )


def source_promotion_id_from_url(promotion_url: str | None) -> str | None:
    numeric_id = source_promotion_numeric_id_from_url(promotion_url)
    return f"tapology_promotion_{numeric_id}" if numeric_id else None


def source_promotion_numeric_id_from_url(promotion_url: str | None) -> str | None:
    promotion_url = canonical_url(promotion_url)
    if not promotion_url:
        return None
    match = re.search(r"/(?:fightcenter/)?promotions/(\d+)(?:-|$)", promotion_url)
    return match.group(1) if match else None


def populate_results_contract_fields(results_doc: dict[str, Any]) -> dict[str, Any]:
    for event in results_doc.get("events", []):
        populate_event_contract_fields(event)
    return results_doc


def populate_event_contract_fields(event: dict[str, Any]) -> dict[str, Any]:
    _ensure_event_contract_defaults(event)
    event["source_event_numeric_id"] = source_event_numeric_id(event.get("event_url"), event.get("source_event_id"))
    event["source_promotion_id"] = source_promotion_id_from_url(event.get("promotion_url"))
    event["source_promotion_numeric_id"] = source_promotion_numeric_id_from_url(event.get("promotion_url"))
    if not event.get("source_promotion_id"):
        _add_warning(event, "missing_source_promotion_id")
    for bout in event.get("bouts", []):
        populate_bout_contract_fields(bout, event)
    event["identity_hash"] = _event_identity_hash(event)
    event["content_hash"] = _event_content_hash(event)
    return event


def populate_bout_contract_fields(bout: dict[str, Any], event: dict[str, Any]) -> dict[str, Any]:
    bout["source_bout_numeric_id"] = source_bout_numeric_id(bout.get("bout_url"), bout.get("source_bout_id"))
    if str(bout.get("source_bout_id") or "").startswith("tapology_bout_fallback_"):
        _add_warning(bout, "fallback_source_bout_id")
    for fighter_key in ("fighter_a", "fighter_b"):
        fighter = bout.get(fighter_key)
        if isinstance(fighter, dict):
            fighter["source_fighter_numeric_id"] = source_fighter_numeric_id(fighter.get("url"), fighter.get("source_fighter_id"))
    bout["flags"] = build_bout_flags(bout)
    bout["identity_hash"] = _bout_identity_hash(bout, event)
    bout["content_hash"] = _bout_content_hash(bout)
    return bout


def stable_json_hash(value: Any) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def material_event_warnings(event: dict[str, Any]) -> list[str]:
    return sorted(
        warning
        for warning in event.get("warnings", [])
        if not str(warning).startswith(("bouts_skipped_amateur:", "bouts_skipped_non_mma:"))
    )


def material_bout_warnings(bout: dict[str, Any]) -> list[str]:
    return sorted(bout.get("warnings", []))


def _ensure_event_contract_defaults(event: dict[str, Any]) -> None:
    skipped_bouts = event.setdefault("skipped_bouts", [])
    event.setdefault("source_bouts_total", None)
    event.setdefault("source_mma_bouts_total", event.get("source_bouts_total"))
    event.setdefault("bouts_included", len(event.get("bouts", [])))
    event.setdefault("bouts_skipped_total", len(skipped_bouts))
    event.setdefault("source_rows_seen", len(event.get("bouts", [])) + len(skipped_bouts))
    event.setdefault(
        "source_mma_rows_seen",
        len(event.get("bouts", [])) + sum(1 for item in skipped_bouts if item.get("reason") != "non_mma"),
    )
    event.setdefault(
        "bouts_skipped_no_result",
        _warning_count(event, "bouts_skipped_no_result"),
    )
    event.setdefault("event_datetime_raw", None)
    event.setdefault("event_time_raw", None)
    event.setdefault("event_timezone_raw", event.get("timezone_raw"))
    event.setdefault("timezone_raw", event.get("event_timezone_raw"))
    location = parse_location_parts(event.get("location"))
    event.setdefault("city", location.get("city"))
    event.setdefault("region", location.get("region"))
    event.setdefault("country", location.get("country"))
    event.setdefault("fighter_profiles", {})
    event.setdefault("source_event_status", _infer_contract_event_status(event))
    event.setdefault("needs_recheck", _infer_contract_needs_recheck(event))


def _warning_count(event: dict[str, Any], prefix: str) -> int:
    total = 0
    for warning in event.get("warnings", []) or []:
        if not str(warning).startswith(f"{prefix}:"):
            continue
        try:
            total += int(str(warning).rsplit(":", 1)[1])
        except ValueError:
            pass
    return total


def _infer_contract_event_status(event: dict[str, Any]) -> str:
    warnings = event.get("warnings", []) or []
    if any("cancel" in str(warning).lower() for warning in warnings):
        return "cancelled"
    if event.get("bouts_skipped_no_result"):
        return "unknown"
    if event.get("bouts"):
        return "completed"
    return "unknown"


def _infer_contract_needs_recheck(event: dict[str, Any]) -> bool:
    if event.get("source_event_status") in {"in_progress", "upcoming"}:
        return True
    if int(event.get("bouts_skipped_no_result") or 0) > 0:
        return True
    total = event.get("source_mma_bouts_total")
    if total is not None and int(event.get("source_mma_rows_seen") or 0) < int(total):
        return True
    return False


def _event_identity_hash(event: dict[str, Any]) -> str:
    return stable_json_hash(
        {
            "hash_type": "event_identity_v1",
            "source": SOURCE,
            "source_event_id": event.get("source_event_id"),
            "source_event_numeric_id": event.get("source_event_numeric_id"),
            "event_url": canonical_url(event.get("event_url")),
            "event_date": event.get("event_date"),
            "event_name": normalize_space(event.get("event_name")),
        }
    )


def _event_content_hash(event: dict[str, Any]) -> str:
    return stable_json_hash(
        {
            "hash_type": "event_content_v1",
            "event_name": normalize_space(event.get("event_name")),
            "event_date": event.get("event_date"),
            "source_promotion_id": event.get("source_promotion_id"),
            "promotion_name": normalize_space(event.get("promotion_name")),
            "promotion_url": canonical_url(event.get("promotion_url")),
            "location": normalize_space(event.get("location")),
            "city": normalize_space(event.get("city")),
            "region": normalize_space(event.get("region")),
            "country": normalize_space(event.get("country")),
            "venue": normalize_space(event.get("venue")),
            "enclosure": normalize_space(event.get("enclosure")),
            "source_event_status": event.get("source_event_status"),
            "source_bouts_total": event.get("source_bouts_total"),
            "source_mma_bouts_total": event.get("source_mma_bouts_total"),
            "bouts_included": event.get("bouts_included"),
            "bouts_skipped_total": event.get("bouts_skipped_total"),
            "bouts_skipped_no_result": event.get("bouts_skipped_no_result"),
            "source_rows_seen": event.get("source_rows_seen"),
            "source_mma_rows_seen": event.get("source_mma_rows_seen"),
            "needs_recheck": event.get("needs_recheck"),
            "retained_bout_source_ids": [bout.get("source_bout_id") for bout in event.get("bouts", [])],
            "skipped_bout_source_ids": [
                {
                    "reason": skipped.get("reason"),
                    "source_bout_id": skipped.get("source_bout_id"),
                    "bout_url": canonical_url(skipped.get("bout_url")),
                    "fighter_a_source_fighter_id": skipped.get("fighter_a_source_fighter_id"),
                    "fighter_b_source_fighter_id": skipped.get("fighter_b_source_fighter_id"),
                    "card_position": skipped.get("card_position"),
                    "weight_lbs": skipped.get("weight_lbs"),
                    "weight_class": normalize_space(skipped.get("weight_class")),
                }
                for skipped in event.get("skipped_bouts", [])
            ],
            "fighter_profiles": _stable_profile_content(event.get("fighter_profiles")),
            "warnings": material_event_warnings(event),
        }
    )


def _stable_profile_content(profiles: Any) -> Any:
    if not isinstance(profiles, dict):
        return {}
    cleaned: dict[str, Any] = {}
    for key, profile in sorted(profiles.items()):
        if not isinstance(profile, dict):
            continue
        cleaned[key] = {
            field: value
            for field, value in sorted(profile.items())
            if field not in {"profile_fetched", "profile_fetched_at"}
        }
    return cleaned


def _bout_identity_hash(bout: dict[str, Any], event: dict[str, Any]) -> str:
    return stable_json_hash(
        {
            "hash_type": "bout_identity_v1",
            "source": SOURCE,
            "source_event_id": event.get("source_event_id") or bout.get("source_event_id"),
            "source_bout_id": bout.get("source_bout_id"),
            "source_bout_numeric_id": bout.get("source_bout_numeric_id"),
            "bout_url": canonical_url(bout.get("bout_url")),
            "fighter_a_source_fighter_id": (bout.get("fighter_a") or {}).get("source_fighter_id"),
            "fighter_b_source_fighter_id": (bout.get("fighter_b") or {}).get("source_fighter_id"),
            "card_position": bout.get("card_position"),
        }
    )


def _bout_content_hash(bout: dict[str, Any]) -> str:
    result = bout.get("result") or {}
    return stable_json_hash(
        {
            "hash_type": "bout_content_v1",
            "result_type": bout.get("result_type"),
            "winner": bout.get("winner"),
            "loser": bout.get("loser"),
            "method_category": result.get("method_category"),
            "method_detail": result.get("method_detail"),
            "round": result.get("round"),
            "time": result.get("time"),
            "time_semantics": result.get("time_semantics"),
            "fighter_a_prefight_record": (bout.get("fighter_a") or {}).get("prefight_record"),
            "fighter_b_prefight_record": (bout.get("fighter_b") or {}).get("prefight_record"),
            "weight_class": bout.get("weight_class"),
            "weight_lbs": bout.get("weight_lbs"),
            "card_position": bout.get("card_position"),
            "title_fight": bout.get("title_fight"),
            "flags": bout.get("flags"),
            "warnings": material_bout_warnings(bout),
        }
    )


def _add_warning(value: dict[str, Any], warning: str) -> None:
    warnings = sorted(set(value.get("warnings") or []) | {warning})
    value["warnings"] = warnings
