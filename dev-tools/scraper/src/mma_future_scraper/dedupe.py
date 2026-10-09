from __future__ import annotations

from copy import deepcopy
from typing import Any


STATE_SCHEMA_VERSION = "scraper_state_v0_1"


def empty_state() -> dict[str, Any]:
    return {
        "schema_version": STATE_SCHEMA_VERSION,
        "last_successful_run_id": None,
        "last_successful_run_at": None,
        "known_event_ids": [],
        "known_bout_ids": [],
        "known_event_url_hashes": [],
        "known_bout_url_hashes": [],
    }


def event_identity(event: dict[str, Any]) -> str | None:
    return event.get("source_event_id") or event.get("event_url")


def bout_identity(bout: dict[str, Any]) -> str | None:
    return bout.get("source_bout_id") or bout.get("bout_url")


def dedupe_bouts(bouts: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[str] = set()
    unique: list[dict[str, Any]] = []
    for bout in bouts:
        identity = bout_identity(bout)
        if not identity:
            identity = f"fallback:{bout.get('fighter_a', {}).get('name', '')}:{bout.get('fighter_b', {}).get('name', '')}"
        if identity in seen:
            continue
        seen.add(identity)
        unique.append(bout)
    return unique


def dedupe_events(events: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[str] = set()
    unique: list[dict[str, Any]] = []
    for event in events:
        identity = event_identity(event)
        if not identity:
            identity = f"raw:{event.get('event_name', '')}:{event.get('event_date', '')}"
        if identity in seen:
            continue
        seen.add(identity)
        copy = deepcopy(event)
        copy["bouts"] = dedupe_bouts(copy.get("bouts", []))
        unique.append(copy)
    return unique


def mark_new_flags(events: list[dict[str, Any]], state: dict[str, Any]) -> list[dict[str, Any]]:
    known_event_ids = set(state.get("known_event_ids", []))
    known_event_url_hashes = set(state.get("known_event_url_hashes", []))
    known_bout_ids = set(state.get("known_bout_ids", []))
    known_bout_url_hashes = set(state.get("known_bout_url_hashes", []))

    marked = deepcopy(events)
    for event in marked:
        event["is_new_since_last_successful_run"] = not (
            event.get("source_event_id") in known_event_ids
            or event.get("event_url_hash") in known_event_url_hashes
        )
        for bout in event.get("bouts", []):
            bout["is_new_since_last_successful_run"] = not (
                bout.get("source_bout_id") in known_bout_ids
                or bout.get("bout_url_hash") in known_bout_url_hashes
            )
    return marked


def known_events_count(events: list[dict[str, Any]], state: dict[str, Any]) -> int:
    known_event_ids = set(state.get("known_event_ids", []))
    known_event_url_hashes = set(state.get("known_event_url_hashes", []))
    return sum(
        1
        for event in events
        if event.get("source_event_id") in known_event_ids or event.get("event_url_hash") in known_event_url_hashes
    )


def build_success_state(
    *,
    prior_state: dict[str, Any],
    run_id: str,
    finished_at: str,
    output_documents: list[dict[str, Any]],
) -> dict[str, Any]:
    state = deepcopy(prior_state) if prior_state else empty_state()
    event_ids = set(state.get("known_event_ids", []))
    bout_ids = set(state.get("known_bout_ids", []))
    event_url_hashes = set(state.get("known_event_url_hashes", []))
    bout_url_hashes = set(state.get("known_bout_url_hashes", []))

    for document in output_documents:
        for event in document.get("events", []):
            if event.get("source_event_id"):
                event_ids.add(event["source_event_id"])
            for bout in event.get("bouts", []):
                if bout.get("source_bout_id"):
                    bout_ids.add(bout["source_bout_id"])

    state.update(
        {
            "schema_version": STATE_SCHEMA_VERSION,
            "last_successful_run_id": run_id,
            "last_successful_run_at": finished_at,
            "known_event_ids": sorted(event_ids),
            "known_bout_ids": sorted(bout_ids),
            "known_event_url_hashes": sorted(event_url_hashes),
            "known_bout_url_hashes": sorted(bout_url_hashes),
        }
    )
    return state
