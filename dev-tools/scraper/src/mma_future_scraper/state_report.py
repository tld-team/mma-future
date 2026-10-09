from __future__ import annotations

from collections import Counter
from copy import deepcopy
from datetime import datetime, timezone
import json
from pathlib import Path
from typing import Any

from .config import DATA_DIR
from .storage import atomic_write_json
from .utils import utc_now


STATE_REPORT_SCHEMA_VERSION = "scraper_state_report_v0_1"


def build_state_report(data_dir: Path = DATA_DIR, *, now: datetime | None = None) -> dict[str, Any]:
    now = now or datetime.now(timezone.utc)
    state_dir = data_dir / "state"
    latest_dir = data_dir / "latest"

    scraper_state = _read_json(state_dir / "scraper_state.json", {})
    events_index = _read_json(state_dir / "events_index.json", {})
    bouts_index = _read_json(state_dir / "bouts_index.json", {})
    incomplete_events = _read_json(state_dir / "incomplete_events.json", {})
    latest_manifest = _read_json(latest_dir / "run_manifest.json", {})
    latest_changes = _read_json(latest_dir / "changes.json", {})
    latest_results = _read_json(latest_dir / "results.json", {})
    latest_events_by_source = {
        event.get("source_event_id"): event
        for event in latest_results.get("events", [])
        if isinstance(event, dict) and event.get("source_event_id")
    }

    event_source_duplicates, event_internal_duplicates = _duplicate_ids(
        events_index,
        key_name="source_event_id",
        source_field="source_event_id",
        internal_field="internal_event_id",
    )
    bout_source_duplicates, bout_internal_duplicates = _duplicate_ids(
        bouts_index,
        key_name="source_bout_id",
        source_field="source_bout_id",
        internal_field="internal_bout_id",
    )

    event_source_ids = set(events_index.keys())
    event_internal_ids = {
        record.get("internal_event_id")
        for record in events_index.values()
        if isinstance(record, dict) and record.get("internal_event_id")
    }
    orphan_bouts = sorted(
        source_bout_id
        for source_bout_id, record in bouts_index.items()
        if not isinstance(record, dict)
        or record.get("source_event_id") not in event_source_ids
        or record.get("internal_event_id") not in event_internal_ids
    )

    incomplete_due: list[str] = []
    incomplete_future: list[str] = []
    incomplete_by_reason: Counter[str] = Counter()
    for source_event_id, record in incomplete_events.items():
        if not isinstance(record, dict):
            incomplete_by_reason["invalid_record"] += 1
            incomplete_due.append(source_event_id)
            continue
        incomplete_by_reason[record.get("recheck_reason") or "unknown"] += 1
        if _is_due(record.get("next_recheck_after"), now):
            incomplete_due.append(source_event_id)
        else:
            incomplete_future.append(source_event_id)

    semantic_events = {
        source_event_id: _semantic_event_record(
            source_event_id=source_event_id,
            event_record=record,
            incomplete_record=incomplete_events.get(source_event_id),
            latest_event=latest_events_by_source.get(source_event_id),
        )
        for source_event_id, record in events_index.items()
    }
    recheck_complete_events = sorted(
        source_event_id
        for source_event_id, record in semantic_events.items()
        if record["state"] == "complete_recent_recheck"
    )
    true_problem_events = sorted(
        source_event_id
        for source_event_id, record in semantic_events.items()
        if record["state"] in {"partial", "parser_attention", "failed"}
    )
    no_relevant_bout_events = sorted(
        source_event_id
        for source_event_id, record in semantic_events.items()
        if record["state"] in {"pending_results", "no_relevant_pro_mma_bouts"}
    )
    critical_missing_data = {
        source_event_id: record["critical_missing_data"]
        for source_event_id, record in semantic_events.items()
        if record["critical_missing_data"]
    }

    report = {
        "schema_version": STATE_REPORT_SCHEMA_VERSION,
        "generated_at": utc_now(),
        "source_files": {
            "scraper_state": str(state_dir / "scraper_state.json"),
            "events_index": str(state_dir / "events_index.json"),
            "bouts_index": str(state_dir / "bouts_index.json"),
            "incomplete_events": str(state_dir / "incomplete_events.json"),
            "latest_run_manifest": str(latest_dir / "run_manifest.json"),
            "latest_changes": str(latest_dir / "changes.json"),
            "latest_results": str(latest_dir / "results.json"),
        },
        "last_successful_run_id": scraper_state.get("last_successful_run_id"),
        "last_attempted_run_id": scraper_state.get("last_attempted_run_id"),
        "total_known_events": len(events_index),
        "total_known_bouts": len(bouts_index),
        "incomplete_events_count": len(incomplete_events),
        "incomplete_events_by_reason": dict(sorted(incomplete_by_reason.items())),
        "incomplete_events_due_count": len(incomplete_due),
        "incomplete_events_due": sorted(incomplete_due),
        "incomplete_events_future_count": len(incomplete_future),
        "incomplete_events_future": sorted(incomplete_future),
        "event_states": dict(sorted(Counter(record["state"] for record in semantic_events.values()).items())),
        "raw_event_states": dict(sorted(Counter(_value(record, "state", "unknown") for record in events_index.values()).items())),
        "recheck_complete_events_count": len(recheck_complete_events),
        "recheck_complete_events": recheck_complete_events,
        "true_problem_events_count": len(true_problem_events),
        "true_problem_events": true_problem_events,
        "no_relevant_bout_events_count": len(no_relevant_bout_events),
        "no_relevant_bout_events": no_relevant_bout_events,
        "critical_missing_data_count": len(critical_missing_data),
        "critical_missing_data": critical_missing_data,
        "bouts_by_result_type": dict(
            sorted(Counter(_value(record, "result_type", "unknown") for record in bouts_index.values()).items())
        ),
        "bouts_by_status": dict(sorted(Counter(_value(record, "status", "unknown") for record in bouts_index.values()).items())),
        "events_missing_internal_event_id": sorted(
            source_event_id for source_event_id, record in events_index.items() if not _value(record, "internal_event_id")
        ),
        "bouts_missing_internal_bout_id": sorted(
            source_bout_id for source_bout_id, record in bouts_index.items() if not _value(record, "internal_bout_id")
        ),
        "orphan_bouts": orphan_bouts,
        "duplicate_source_event_ids": event_source_duplicates,
        "duplicate_internal_event_ids": event_internal_duplicates,
        "duplicate_source_bout_ids": bout_source_duplicates,
        "duplicate_internal_bout_ids": bout_internal_duplicates,
        "latest_run_summary": {
            "run_id": latest_manifest.get("run_id"),
            "status": latest_manifest.get("status"),
            "started_at": latest_manifest.get("started_at"),
            "finished_at": latest_manifest.get("finished_at"),
            "counts": latest_manifest.get("counts", {}),
            "changes": _changes_summary(latest_changes),
            "results_events_count": len(latest_results.get("events", [])) if isinstance(latest_results, dict) else 0,
        },
    }
    return report


def write_state_report(data_dir: Path = DATA_DIR, report: dict[str, Any] | None = None) -> Path:
    report = report or build_state_report(data_dir)
    path = data_dir / "latest" / "state_report.json"
    atomic_write_json(path, report)
    return path


def format_state_report(report: dict[str, Any]) -> str:
    latest = report.get("latest_run_summary", {})
    lines = [
        "State report",
        f"  Last successful run: {report.get('last_successful_run_id')}",
        f"  Last attempted run: {report.get('last_attempted_run_id')}",
        f"  Known events: {report.get('total_known_events')}",
        f"  Known bouts: {report.get('total_known_bouts')}",
        f"  Incomplete events: {report.get('incomplete_events_count')}",
        f"  Incomplete by reason: {_format_counter(report.get('incomplete_events_by_reason', {}))}",
        f"  Incomplete due now: {report.get('incomplete_events_due_count')}",
        f"  Incomplete not yet due: {report.get('incomplete_events_future_count')}",
        f"  Event states: {_format_counter(report.get('event_states', {}))}",
        f"  Recheck-needed but otherwise complete events: {report.get('recheck_complete_events_count')}",
        f"  True data/problem events: {report.get('true_problem_events_count')}",
        f"  No relevant bout events: {report.get('no_relevant_bout_events_count')}",
        f"  Critical missing data events: {report.get('critical_missing_data_count')}",
        f"  Bouts by result_type: {_format_counter(report.get('bouts_by_result_type', {}))}",
        f"  Bouts by status: {_format_counter(report.get('bouts_by_status', {}))}",
        f"  Events missing internal_event_id: {len(report.get('events_missing_internal_event_id', []))}",
        f"  Bouts missing internal_bout_id: {len(report.get('bouts_missing_internal_bout_id', []))}",
        f"  Bouts with missing parent event: {len(report.get('orphan_bouts', []))}",
        f"  Duplicate source event IDs: {len(report.get('duplicate_source_event_ids', {}))}",
        f"  Duplicate internal event IDs: {len(report.get('duplicate_internal_event_ids', {}))}",
        f"  Duplicate source bout IDs: {len(report.get('duplicate_source_bout_ids', {}))}",
        f"  Duplicate internal bout IDs: {len(report.get('duplicate_internal_bout_ids', {}))}",
        "Latest run",
        f"  Run ID: {latest.get('run_id')}",
        f"  Status: {latest.get('status')}",
        f"  Finished: {latest.get('finished_at')}",
        f"  Counts: {_format_counter(latest.get('counts', {}))}",
        f"  Changes: {_format_counter(latest.get('changes', {}))}",
        f"  Results events: {latest.get('results_events_count')}",
    ]
    return "\n".join(lines)


def _read_json(path: Path, default: Any) -> Any:
    if not path.exists():
        return deepcopy(default)
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def _semantic_event_record(
    *,
    source_event_id: str,
    event_record: dict[str, Any],
    incomplete_record: dict[str, Any] | None,
    latest_event: dict[str, Any] | None,
) -> dict[str, Any]:
    latest_event = latest_event or {}
    completed_bouts = [bout for bout in latest_event.get("bouts", []) if isinstance(bout, dict) and bout.get("status") == "completed"]
    critical_missing = _critical_missing_data(latest_event, completed_bouts)
    reason = (incomplete_record or {}).get("recheck_reason") or event_record.get("recheck_reason")
    raw_state = event_record.get("state") or "unknown"
    if incomplete_record:
        if reason == "recent_update_window" and completed_bouts and not critical_missing:
            state = "complete_recent_recheck"
        elif reason == "no_completed_professional_mma_bouts" or not completed_bouts:
            state = "pending_results"
        elif reason == "parser_warnings":
            state = "parser_attention"
        elif critical_missing:
            state = "parser_attention"
        elif reason == "missing_or_partial_results":
            state = "partial"
        else:
            state = raw_state if raw_state != "partial" else "partial"
    elif raw_state in {"complete", "complete_recent_recheck", "pending_results", "no_relevant_pro_mma_bouts", "parser_attention", "partial", "failed", "ignored"}:
        state = raw_state
    elif completed_bouts and not critical_missing:
        state = "complete"
    elif not completed_bouts:
        state = "no_relevant_pro_mma_bouts"
    elif critical_missing:
        state = "parser_attention"
    else:
        state = raw_state
    return {
        "source_event_id": source_event_id,
        "state": state,
        "raw_state": raw_state,
        "recheck_reason": reason,
        "completed_bouts_count": len(completed_bouts),
        "critical_missing_data": critical_missing,
    }


def _critical_missing_data(latest_event: dict[str, Any], completed_bouts: list[dict[str, Any]]) -> list[str]:
    if not latest_event:
        return []
    missing: list[str] = []
    for bout in completed_bouts:
        source_bout_id = bout.get("source_bout_id") or "<missing_bout_id>"
        result = bout.get("result") or {}
        for field, value in [
            ("source_bout_id", bout.get("source_bout_id")),
            ("internal_bout_id", bout.get("internal_bout_id")),
            ("source_event_id", bout.get("source_event_id")),
            ("internal_event_id", bout.get("internal_event_id")),
            ("status", bout.get("status")),
            ("pro_am", bout.get("pro_am")),
            ("result_type", bout.get("result_type")),
            ("fighter_a.name", bout.get("fighter_a", {}).get("name")),
            ("fighter_b.name", bout.get("fighter_b", {}).get("name")),
            ("result.method_category", result.get("method_category")),
        ]:
            if value in {None, "", "unknown"}:
                missing.append(f"{source_bout_id}.{field}")
        if bout.get("result_type") == "win_loss":
            if bout.get("winner") in {None, "", "unknown"}:
                missing.append(f"{source_bout_id}.winner")
            if bout.get("loser") in {None, "", "unknown"}:
                missing.append(f"{source_bout_id}.loser")
    return sorted(missing)


def _duplicate_ids(
    index: dict[str, Any],
    *,
    key_name: str,
    source_field: str,
    internal_field: str,
) -> tuple[dict[str, list[str]], dict[str, list[str]]]:
    source_seen: dict[str, list[str]] = {}
    internal_seen: dict[str, list[str]] = {}
    for key, record in index.items():
        if not isinstance(record, dict):
            continue
        source_seen.setdefault(record.get(source_field) or key, []).append(key)
        internal_seen.setdefault(record.get(internal_field) or "", []).append(key)

    source_duplicates = {value: keys for value, keys in source_seen.items() if value and len(keys) > 1}
    internal_duplicates = {value: keys for value, keys in internal_seen.items() if value and len(keys) > 1}
    key_mismatches = {
        key: [record.get(source_field)]
        for key, record in index.items()
        if isinstance(record, dict) and record.get(source_field) and record.get(source_field) != key
    }
    if key_mismatches:
        source_duplicates[f"{key_name}_key_mismatch"] = sorted(key_mismatches)
    return source_duplicates, internal_duplicates


def _changes_summary(changes: dict[str, Any]) -> dict[str, int]:
    if not isinstance(changes, dict):
        return {}
    return {
        key: len(value)
        for key, value in changes.items()
        if isinstance(value, list)
    }


def _is_due(value: str | None, now: datetime) -> bool:
    if not value:
        return True
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return True
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed <= now.astimezone(timezone.utc)


def _value(record: Any, key: str, default: Any = None) -> Any:
    if not isinstance(record, dict):
        return default
    return record.get(key) or default


def _format_counter(counter: dict[str, Any]) -> str:
    if not counter:
        return "none"
    return ", ".join(f"{key}={value}" for key, value in counter.items())
