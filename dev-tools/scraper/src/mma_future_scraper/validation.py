from __future__ import annotations

import re
from typing import Any

from .contract import numeric_id_from_source_id
from .models import RESULTS_SCHEMA_VERSION
from .normalize import BOUT_FLAG_KEYS

BANNED_KEYS = {
    "raw_text",
    "raw_text_excerpt",
    "matchup_details",
    "finish_points_candidate",
}

ALLOWED_OUTPUT_HASH_KEYS = {"identity_hash", "content_hash"}
METHOD_CATEGORIES = {"ko_tko", "submission", "decision", "dq", "no_contest", "draw", "unknown"}
EVENT_STATUSES = {"completed", "in_progress", "upcoming", "cancelled", "unknown"}
SKIPPED_BOUT_REASONS = {"no_result", "amateur", "junior", "cancelled", "overturned", "non_mma", "missing_required_data", "unknown"}
REQUIRED_EVENT_KEYS = (
    "source_event_status",
    "bouts_included",
    "bouts_skipped_total",
    "bouts_skipped_no_result",
    "needs_recheck",
    "skipped_bouts",
    "fighter_profiles",
)
REQUIRED_PROFILE_KEYS = (
    "source_fighter_id",
    "source_fighter_numeric_id",
    "profile_url",
    "profile_fetched",
    "profile_fetched_at",
    "name",
    "profile_warnings",
)


def validate_results_document(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    _collect_banned_key_errors(payload, "$", errors)
    if payload.get("schema_version") != RESULTS_SCHEMA_VERSION:
        errors.append(f"bad_schema:{payload.get('schema_version')}")
    source_event_ids: set[str] = set()
    internal_event_ids: set[str] = set()
    source_bout_ids: set[str] = set()
    internal_bout_ids: set[str] = set()
    for event_index, event in enumerate(payload.get("events", [])):
        path = f"$.events[{event_index}]"
        _require(event, "internal_event_id", path, errors)
        _require(event, "source_event_id", path, errors)
        _require_key(event, "source_event_numeric_id", path, errors)
        _require_key(event, "source_promotion_id", path, errors)
        _require_key(event, "source_promotion_numeric_id", path, errors)
        _require_hashes(event, path, errors)
        _validate_event_completeness(event, path, errors)
        _validate_numeric_source_id(
            event.get("source_event_id"),
            event.get("source_event_numeric_id"),
            "tapology_event",
            f"{path}.source_event_numeric_id",
            errors,
        )
        _validate_promotion_source(event, path, errors)
        _add_unique(event.get("source_event_id"), source_event_ids, f"duplicate_source_event_id:{path}", errors)
        _add_unique(event.get("internal_event_id"), internal_event_ids, f"duplicate_internal_event_id:{path}", errors)
        if event.get("warnings") == []:
            errors.append(f"empty_warnings:$.events[{event_index}]")
        for bout_index, bout in enumerate(event.get("bouts", [])):
            path = f"$.events[{event_index}].bouts[{bout_index}]"
            _require(bout, "internal_bout_id", path, errors)
            _require(bout, "source_bout_id", path, errors)
            _require_key(bout, "source_bout_numeric_id", path, errors)
            _require_hashes(bout, path, errors)
            _validate_numeric_source_id(
                bout.get("source_bout_id"),
                bout.get("source_bout_numeric_id"),
                "tapology_bout",
                f"{path}.source_bout_numeric_id",
                errors,
            )
            _add_unique(bout.get("source_bout_id"), source_bout_ids, f"duplicate_source_bout_id:{path}", errors)
            _add_unique(bout.get("internal_bout_id"), internal_bout_ids, f"duplicate_internal_bout_id:{path}", errors)
            if bout.get("internal_event_id") and bout.get("internal_event_id") != event.get("internal_event_id"):
                errors.append(f"bad_parent_event:{path}.internal_event_id")
            if bout.get("source_event_id") and bout.get("source_event_id") != event.get("source_event_id"):
                errors.append(f"bad_parent_event:{path}.source_event_id")
            _require(bout, "bout_url", path, errors)
            _require(bout.get("fighter_a", {}), "name", f"{path}.fighter_a", errors)
            _require(bout.get("fighter_b", {}), "name", f"{path}.fighter_b", errors)
            _require_key(bout.get("fighter_a", {}), "source_fighter_id", f"{path}.fighter_a", errors)
            _require_key(bout.get("fighter_b", {}), "source_fighter_id", f"{path}.fighter_b", errors)
            _require_key(bout.get("fighter_a", {}), "source_fighter_numeric_id", f"{path}.fighter_a", errors)
            _require_key(bout.get("fighter_b", {}), "source_fighter_numeric_id", f"{path}.fighter_b", errors)
            _validate_numeric_source_id(
                bout.get("fighter_a", {}).get("source_fighter_id"),
                bout.get("fighter_a", {}).get("source_fighter_numeric_id"),
                "tapology_fighter",
                f"{path}.fighter_a.source_fighter_numeric_id",
                errors,
            )
            _validate_numeric_source_id(
                bout.get("fighter_b", {}).get("source_fighter_id"),
                bout.get("fighter_b", {}).get("source_fighter_numeric_id"),
                "tapology_fighter",
                f"{path}.fighter_b.source_fighter_numeric_id",
                errors,
            )
            _require(bout.get("fighter_a", {}), "prefight_record", f"{path}.fighter_a", errors)
            _require(bout.get("fighter_b", {}), "prefight_record", f"{path}.fighter_b", errors)
            _require(bout.get("result", {}), "method_category", f"{path}.result", errors)
            method_category = bout.get("result", {}).get("method_category")
            if method_category and method_category not in METHOD_CATEGORIES:
                errors.append(f"bad_method_category:{path}.result.method_category:{method_category}")
            _require(bout, "result_type", path, errors)
            _validate_bout_flags(bout, path, errors)
            if "winner" not in bout:
                errors.append(f"missing:{path}.winner")
            if bout.get("warnings") == []:
                errors.append(f"empty_warnings:{path}")
    return errors


def _validate_event_completeness(event: dict[str, Any], path: str, errors: list[str]) -> None:
    for key in REQUIRED_EVENT_KEYS:
        _require_key(event, key, path, errors)
    if event.get("source_event_status") not in EVENT_STATUSES:
        errors.append(f"bad_event_status:{path}.source_event_status:{event.get('source_event_status')}")
    for key in ("bouts_included", "bouts_skipped_total", "bouts_skipped_no_result", "source_rows_seen", "source_mma_rows_seen"):
        if key in event and (not isinstance(event.get(key), int) or event.get(key) < 0):
            errors.append(f"bad_count:{path}.{key}:{event.get(key)}")
    for key in ("source_bouts_total", "source_mma_bouts_total"):
        if key in event and event.get(key) is not None:
            if not isinstance(event.get(key), int) or event.get(key) < 0:
                errors.append(f"bad_count:{path}.{key}:{event.get(key)}")
    if "needs_recheck" in event and not isinstance(event.get("needs_recheck"), bool):
        errors.append(f"bad_bool:{path}.needs_recheck:{event.get('needs_recheck')}")
    skipped_bouts = event.get("skipped_bouts")
    if not isinstance(skipped_bouts, list):
        errors.append(f"bad_skipped_bouts:{path}.skipped_bouts")
    else:
        for index, skipped in enumerate(skipped_bouts):
            _validate_skipped_bout(skipped, f"{path}.skipped_bouts[{index}]", errors)
    profiles = event.get("fighter_profiles")
    if not isinstance(profiles, dict):
        errors.append(f"bad_fighter_profiles:{path}.fighter_profiles")
    else:
        for key, profile in profiles.items():
            if not isinstance(profile, dict):
                errors.append(f"bad_profile:{path}.fighter_profiles.{key}")
                continue
            for required_key in REQUIRED_PROFILE_KEYS:
                _require_key(profile, required_key, f"{path}.fighter_profiles.{key}", errors)
            if "profile_fetched" in profile and not isinstance(profile.get("profile_fetched"), bool):
                errors.append(f"bad_bool:{path}.fighter_profiles.{key}.profile_fetched:{profile.get('profile_fetched')}")
            if "profile_warnings" in profile and not isinstance(profile.get("profile_warnings"), list):
                errors.append(f"bad_profile_warnings:{path}.fighter_profiles.{key}.profile_warnings")


def _validate_skipped_bout(skipped: Any, path: str, errors: list[str]) -> None:
    if not isinstance(skipped, dict):
        errors.append(f"bad_skipped_bout:{path}")
        return
    for key in (
        "reason",
        "source_bout_id",
        "source_bout_numeric_id",
        "bout_url",
        "fighter_a_name",
        "fighter_a_url",
        "fighter_a_source_fighter_id",
        "fighter_a_source_fighter_numeric_id",
        "fighter_b_name",
        "fighter_b_url",
        "fighter_b_source_fighter_id",
        "fighter_b_source_fighter_numeric_id",
        "card_position",
        "weight_lbs",
        "weight_class",
    ):
        _require_key(skipped, key, path, errors)
    if skipped.get("reason") not in SKIPPED_BOUT_REASONS:
        errors.append(f"bad_skipped_reason:{path}.reason:{skipped.get('reason')}")


def validate_state_bundle(bundle: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    scraper_state = bundle.get("scraper_state", {})
    events_index = bundle.get("events_index", {})
    bouts_index = bundle.get("bouts_index", {})
    incomplete_events = bundle.get("incomplete_events", {})

    if scraper_state.get("schema_version") != "scraper_state_v0_1":
        errors.append(f"bad_state_schema:{scraper_state.get('schema_version')}")
    _require(scraper_state, "next_internal_event_number", "$.scraper_state", errors)
    _require(scraper_state, "next_internal_bout_number", "$.scraper_state", errors)

    event_internal_to_source: dict[str, str] = {}
    max_event_number = 0
    for source_event_id, record in events_index.items():
        path = f"$.events_index.{source_event_id}"
        if record.get("source_event_id") != source_event_id:
            errors.append(f"event_source_key_mismatch:{path}")
        _require(record, "internal_event_id", path, errors)
        internal_event_id = record.get("internal_event_id")
        if internal_event_id in event_internal_to_source and event_internal_to_source[internal_event_id] != source_event_id:
            errors.append(f"duplicate_internal_event_id:{internal_event_id}")
        event_internal_to_source[internal_event_id] = source_event_id
        max_event_number = max(max_event_number, _internal_number(internal_event_id, "mf_event"))

    bout_internal_to_source: dict[str, str] = {}
    max_bout_number = 0
    for source_bout_id, record in bouts_index.items():
        path = f"$.bouts_index.{source_bout_id}"
        if record.get("source_bout_id") != source_bout_id:
            errors.append(f"bout_source_key_mismatch:{path}")
        _require(record, "internal_bout_id", path, errors)
        _require(record, "source_event_id", path, errors)
        if record.get("source_event_id") not in events_index:
            errors.append(f"unknown_bout_event:{path}.source_event_id")
        internal_bout_id = record.get("internal_bout_id")
        if internal_bout_id in bout_internal_to_source and bout_internal_to_source[internal_bout_id] != source_bout_id:
            errors.append(f"duplicate_internal_bout_id:{internal_bout_id}")
        bout_internal_to_source[internal_bout_id] = source_bout_id
        max_bout_number = max(max_bout_number, _internal_number(internal_bout_id, "mf_bout"))

    if int(scraper_state.get("next_internal_event_number") or 0) <= max_event_number:
        errors.append("event_counter_not_greater_than_assigned_ids")
    if int(scraper_state.get("next_internal_bout_number") or 0) <= max_bout_number:
        errors.append("bout_counter_not_greater_than_assigned_ids")

    for source_event_id, record in incomplete_events.items():
        if record.get("source_event_id") != source_event_id:
            errors.append(f"incomplete_source_key_mismatch:{source_event_id}")
        if source_event_id not in events_index:
            errors.append(f"incomplete_unknown_event:{source_event_id}")

    return errors


def _collect_banned_key_errors(value: Any, path: str, errors: list[str]) -> None:
    if isinstance(value, dict):
        for key, item in value.items():
            child_path = f"{path}.{key}"
            if key in BANNED_KEYS or (key.endswith("_hash") and key not in ALLOWED_OUTPUT_HASH_KEYS):
                errors.append(f"banned_key:{child_path}")
            _collect_banned_key_errors(item, child_path, errors)
    elif isinstance(value, list):
        for index, item in enumerate(value):
            _collect_banned_key_errors(item, f"{path}[{index}]", errors)


def _require(value: dict[str, Any], key: str, path: str, errors: list[str]) -> None:
    if key not in value or value.get(key) in (None, ""):
        errors.append(f"missing:{path}.{key}")


def _require_key(value: dict[str, Any], key: str, path: str, errors: list[str]) -> None:
    if key not in value:
        errors.append(f"missing:{path}.{key}")


def _add_unique(value: Any, seen: set[str], error: str, errors: list[str]) -> None:
    if not value:
        return
    if value in seen:
        errors.append(error)
    seen.add(value)


def _require_hashes(value: dict[str, Any], path: str, errors: list[str]) -> None:
    for key in ("identity_hash", "content_hash"):
        if not isinstance(value.get(key), str) or not value.get(key):
            errors.append(f"missing:{path}.{key}")
    if value.get("identity_hash") and value.get("identity_hash") == value.get("content_hash"):
        errors.append(f"identical_hashes:{path}")


def _validate_numeric_source_id(
    source_id: str | None,
    numeric_id: str | None,
    prefix: str,
    path: str,
    errors: list[str],
) -> None:
    expected = numeric_id_from_source_id(source_id, prefix)
    if expected and numeric_id != expected:
        errors.append(f"bad_numeric_id:{path}:{numeric_id}:{expected}")
    if numeric_id is not None and not re.fullmatch(r"\d+", str(numeric_id)):
        errors.append(f"bad_numeric_id:{path}:{numeric_id}")


def _validate_promotion_source(event: dict[str, Any], path: str, errors: list[str]) -> None:
    promotion_id = event.get("source_promotion_id")
    promotion_numeric_id = event.get("source_promotion_numeric_id")
    warnings = set(event.get("warnings") or [])
    if promotion_id is None:
        if promotion_numeric_id is not None:
            errors.append(f"bad_promotion_source:{path}.source_promotion_numeric_id")
        if "missing_source_promotion_id" not in warnings:
            errors.append(f"missing_warning:{path}.missing_source_promotion_id")
        return
    expected = numeric_id_from_source_id(promotion_id, "tapology_promotion")
    if not expected:
        errors.append(f"bad_promotion_source:{path}.source_promotion_id:{promotion_id}")
        return
    if promotion_numeric_id != expected:
        errors.append(f"bad_promotion_source:{path}.source_promotion_numeric_id:{promotion_numeric_id}:{expected}")


def _validate_bout_flags(bout: dict[str, Any], path: str, errors: list[str]) -> None:
    flags = bout.get("flags")
    if not isinstance(flags, dict):
        errors.append(f"missing:{path}.flags")
        return
    for key in BOUT_FLAG_KEYS:
        if key not in flags:
            errors.append(f"missing:{path}.flags.{key}")
            continue
        if not isinstance(flags.get(key), bool):
            errors.append(f"bad_flag:{path}.flags.{key}:{flags.get(key)}")
    if flags.get("is_scoring_candidate") is not True:
        if flags.get("is_bout_scoring_candidate") != flags.get("is_scoring_candidate"):
            errors.append(f"bad_scoring_candidate_alias:{path}.flags")
        return
    if flags.get("is_bout_scoring_candidate") != flags.get("is_scoring_candidate"):
        errors.append(f"bad_scoring_candidate_alias:{path}.flags")
    blockers = [
        flags.get("is_professional") is not True,
        flags.get("is_amateur") is True,
        flags.get("is_completed") is not True,
        flags.get("is_cancelled") is True,
        flags.get("is_overturned") is True,
        flags.get("is_upcoming") is True,
        bout.get("result_type") != "win_loss",
    ]
    if any(blockers):
        errors.append(f"bad_scoring_candidate:{path}.flags")


def _internal_number(internal_id: str | None, prefix: str) -> int:
    if not internal_id:
        return 0
    match = re.fullmatch(rf"{prefix}_(\d+)", internal_id)
    return int(match.group(1)) if match else 0
