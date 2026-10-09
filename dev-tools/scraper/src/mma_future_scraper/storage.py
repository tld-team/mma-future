from __future__ import annotations

from copy import deepcopy
from datetime import date, datetime, timedelta, timezone
import json
import re
from pathlib import Path
from typing import Any

from .config import DATA_DIR, LATEST_DIR, RUNS_DIR, STATE_DIR
from .contract import populate_results_contract_fields
from .models import RESULTS_SCHEMA_VERSION
from .utils import source_event_id_from_url, stable_hash, utc_now
from .validation import validate_results_document, validate_state_bundle


RUN_MANIFEST_SCHEMA_VERSION = "scraper_run_manifest_v0_1"
CHANGES_SCHEMA_VERSION = "scraper_changes_v0_1"
STATE_SCHEMA_VERSION = "scraper_state_v0_1"

SCRAPER_STATE_FILENAME = "scraper_state.json"
EVENTS_INDEX_FILENAME = "events_index.json"
BOUTS_INDEX_FILENAME = "bouts_index.json"
INCOMPLETE_EVENTS_FILENAME = "incomplete_events.json"
EVENT_PAYLOADS_FILENAME = "event_payloads.json"

FINGERPRINT_IGNORED_KEYS = {
    "internal_event_id",
    "internal_bout_id",
    "run",
    "run_id",
    "scraped_at",
    "first_seen_at",
    "last_seen_at",
    "last_scraped_at",
    "last_successfully_scraped_at",
    "first_marked_incomplete_at",
    "last_rechecked_at",
    "next_recheck_after",
}


def ensure_data_dirs() -> None:
    DataStore().ensure_dirs()


def atomic_write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_name(f".{path.name}.{stable_hash(utc_now(), 10)}.tmp")
    with temp_path.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=False)
        handle.write("\n")
    temp_path.replace(path)


def write_json(path: Path, payload: Any) -> None:
    atomic_write_json(path, payload)


def create_run_dir(run_id: str) -> Path:
    return DataStore().create_run_dir(run_id)


def load_state() -> dict[str, Any]:
    return DataStore().load_state_bundle()["scraper_state"]


def commit_latest(run_dir: Path, state: dict[str, Any]) -> None:
    store = DataStore()
    for filename in ["results.json", "changes.json", "run_manifest.json"]:
        source = run_dir / filename
        if source.exists():
            atomic_write_json(store.latest_dir / filename, _read_json(source, {}))
    atomic_write_json(store.scraper_state_file, state)


def normalized_fingerprint(value: Any) -> str:
    cleaned = _fingerprint_value(value)
    payload = json.dumps(cleaned, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return stable_hash(payload, 32)


def internal_number(internal_id: str | None, prefix: str) -> int:
    if not internal_id:
        return 0
    match = re.fullmatch(rf"{re.escape(prefix)}_(\d+)", internal_id)
    return int(match.group(1)) if match else 0


class DataStore:
    def __init__(self, data_dir: Path = DATA_DIR):
        self.data_dir = data_dir
        self.latest_dir = data_dir / "latest"
        self.runs_dir = data_dir / "runs"
        self.state_dir = data_dir / "state"
        self.scraper_state_file = self.state_dir / SCRAPER_STATE_FILENAME
        self.events_index_file = self.state_dir / EVENTS_INDEX_FILENAME
        self.bouts_index_file = self.state_dir / BOUTS_INDEX_FILENAME
        self.incomplete_events_file = self.state_dir / INCOMPLETE_EVENTS_FILENAME
        self.event_payloads_file = self.state_dir / EVENT_PAYLOADS_FILENAME

    def ensure_dirs(self) -> None:
        for path in [self.latest_dir, self.runs_dir, self.state_dir]:
            path.mkdir(parents=True, exist_ok=True)

    def create_run_dir(self, run_id: str) -> Path:
        run_dir = self.runs_dir / run_id
        run_dir.mkdir(parents=True, exist_ok=True)
        return run_dir

    def load_state_bundle(self) -> dict[str, Any]:
        self.ensure_dirs()
        scraper_state = _read_json(self.scraper_state_file, _empty_scraper_state())
        events_index = _read_json(self.events_index_file, {})
        bouts_index = _read_json(self.bouts_index_file, {})
        incomplete_events = _read_json(self.incomplete_events_file, {})
        event_payloads = _read_json(self.event_payloads_file, {})

        scraper_state = _normalize_scraper_state(scraper_state, events_index, bouts_index)
        return {
            "scraper_state": scraper_state,
            "events_index": events_index,
            "bouts_index": bouts_index,
            "incomplete_events": incomplete_events,
            "event_payloads": event_payloads if isinstance(event_payloads, dict) else {},
        }

    def begin_attempt(self, run_id: str, started_at: str) -> dict[str, Any]:
        bundle = self.load_state_bundle()
        state = deepcopy(bundle["scraper_state"])
        state.update(
            {
                "schema_version": STATE_SCHEMA_VERSION,
                "source": "tapology",
                "last_attempted_run_id": run_id,
                "last_attempted_started_at": started_at,
                "last_attempted_finished_at": None,
            }
        )
        bundle["scraper_state"] = state
        atomic_write_json(self.scraper_state_file, state)
        return bundle

    def record_failed_attempt(
        self,
        *,
        run_dir: Path,
        bundle: dict[str, Any],
        run_id: str,
        started_at: str,
        finished_at: str,
        options: dict[str, Any],
        counts: dict[str, Any],
        warnings: list[str],
        errors: list[str],
        partial_results: dict[str, Any] | None = None,
        debug_doc: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        manifest = self.build_manifest(
            run_id=run_id,
            started_at=started_at,
            finished_at=finished_at,
            status="failed",
            mode=_manifest_mode(options),
            options=options,
            counts={**counts, "warnings_count": len(warnings), "errors_count": len(errors)},
        )
        manifest["warnings"] = list(warnings)
        manifest["errors"] = list(errors)
        if partial_results is not None:
            atomic_write_json(run_dir / "partial_results.json", partial_results)
        if debug_doc is not None:
            atomic_write_json(run_dir / "debug.json", debug_doc)
        atomic_write_json(run_dir / "run_manifest.json", manifest)

        state = deepcopy(bundle["scraper_state"])
        state["last_attempted_finished_at"] = finished_at
        atomic_write_json(self.scraper_state_file, state)
        return manifest

    def commit_success(
        self,
        *,
        run_dir: Path,
        bundle: dict[str, Any],
        run_id: str,
        started_at: str,
        finished_at: str,
        results_doc: dict[str, Any],
        summary: dict[str, Any],
        options: dict[str, Any],
        debug_doc: dict[str, Any] | None = None,
        upcoming_doc: dict[str, Any] | None = None,
    ) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
        working_bundle = deepcopy(bundle)
        results_doc = deepcopy(results_doc)
        results_doc["schema_version"] = RESULTS_SCHEMA_VERSION
        results_doc["scraped_at"] = finished_at
        results_doc.setdefault("run", {})["run_id"] = run_id
        results_doc["run"]["started_at"] = started_at
        results_doc["run"]["finished_at"] = finished_at
        populate_results_contract_fields(results_doc)

        changes = _empty_changes(run_id)
        counts = _apply_results_to_state(
            bundle=working_bundle,
            results_doc=results_doc,
            changes=changes,
            started_at=started_at,
            finished_at=finished_at,
            recheck_days=int(options.get("recheck_days") or 14),
        )
        _apply_failed_listing_events_to_state(
            bundle=working_bundle,
            changes=changes,
            failed_event_examples=summary.get("failed_event_examples", []),
            started_at=started_at,
            finished_at=finished_at,
        )
        event_payloads = working_bundle.setdefault("event_payloads", {})
        _update_event_payload_store(event_payloads, results_doc.get("events", []))
        output_results_doc, output_counts = _build_output_results_doc(
            results_doc=results_doc,
            event_payloads=event_payloads,
            summary=summary,
            options=options,
            scraped_events_count=counts["events_scraped"],
        )
        changes["warnings"] = sorted(set(summary.get("warnings", [])))
        _enrich_changes_for_dashboard(changes, output_results_doc)
        _refresh_document_counts(results_doc, counts)
        _refresh_output_document_counts(output_results_doc, output_counts, summary)
        _sync_results_warning_counts(output_results_doc, summary)
        manifest_counts = _manifest_counts(summary, counts, changes)
        manifest_counts.update(output_counts)
        _sync_manifest_counts_with_output(manifest_counts, output_results_doc)
        manifest = self.build_manifest(
            run_id=run_id,
            started_at=started_at,
            finished_at=finished_at,
            status="success",
            mode=_manifest_mode(options),
            options=options,
            counts=manifest_counts,
        )

        result_errors = validate_results_document(output_results_doc)
        state_errors = validate_state_bundle(working_bundle)
        if result_errors or state_errors:
            raise ValueError(f"validation_failed: results={result_errors}; state={state_errors}")

        _commit_success_state(
            working_bundle,
            run_id=run_id,
            started_at=started_at,
            finished_at=finished_at,
            results_doc=results_doc,
        )

        atomic_write_json(run_dir / "results.json", output_results_doc)
        atomic_write_json(run_dir / "changes.json", changes)
        if upcoming_doc is not None:
            atomic_write_json(run_dir / "upcoming.json", upcoming_doc)
        if debug_doc is not None:
            atomic_write_json(run_dir / "debug.json", debug_doc)

        self._commit_state_files(working_bundle)
        atomic_write_json(run_dir / "run_manifest.json", manifest)
        self._commit_latest(run_dir)
        return output_results_doc, changes, manifest

    def build_manifest(
        self,
        *,
        run_id: str,
        started_at: str,
        finished_at: str | None,
        status: str,
        mode: str,
        options: dict[str, Any],
        counts: dict[str, Any],
    ) -> dict[str, Any]:
        return {
            "schema_version": RUN_MANIFEST_SCHEMA_VERSION,
            "run_id": run_id,
            "started_at": started_at,
            "finished_at": finished_at,
            "status": status,
            "source": "tapology",
            "mode": mode,
            "run_mode": options.get("run_mode") or ("backfill" if mode == "full_scan" else "daily"),
            "options": options,
            "counts": counts,
        }

    def _commit_state_files(self, bundle: dict[str, Any]) -> None:
        pending_dir = self.state_dir / f".pending_{stable_hash(utc_now(), 10)}"
        pending_dir.mkdir(parents=True, exist_ok=False)
        pending_files = {
            EVENTS_INDEX_FILENAME: bundle["events_index"],
            BOUTS_INDEX_FILENAME: bundle["bouts_index"],
            INCOMPLETE_EVENTS_FILENAME: bundle["incomplete_events"],
            EVENT_PAYLOADS_FILENAME: bundle.get("event_payloads", {}),
            SCRAPER_STATE_FILENAME: bundle["scraper_state"],
        }
        try:
            for filename, payload in pending_files.items():
                atomic_write_json(pending_dir / filename, payload)
            for filename in [
                EVENTS_INDEX_FILENAME,
                BOUTS_INDEX_FILENAME,
                INCOMPLETE_EVENTS_FILENAME,
                EVENT_PAYLOADS_FILENAME,
                SCRAPER_STATE_FILENAME,
            ]:
                (pending_dir / filename).replace(self.state_dir / filename)
        finally:
            for child in pending_dir.glob("*"):
                try:
                    child.unlink()
                except OSError:
                    pass
            try:
                pending_dir.rmdir()
            except OSError:
                pass

    def _commit_latest(self, run_dir: Path) -> None:
        for filename in ["results.json", "changes.json", "run_manifest.json"]:
            atomic_write_json(self.latest_dir / filename, _read_json(run_dir / filename, {}))
        upcoming_path = run_dir / "upcoming.json"
        if upcoming_path.exists():
            atomic_write_json(self.latest_dir / "upcoming.json", _read_json(upcoming_path, {}))


def should_recheck_event(
    *,
    list_item: dict[str, Any],
    bundle: dict[str, Any],
    now: datetime,
    recheck_days: int,
    recheck_known_limit: int,
    force_recheck_events: set[str],
    force_full_scan: bool,
    recheck_complete_days: int = 0,
) -> tuple[bool, str]:
    source_event_id = list_item.get("source_event_id") or source_event_id_from_url(list_item.get("event_url"))
    if force_full_scan:
        return True, "force_full_scan"
    if source_event_id in force_recheck_events or list_item.get("event_url") in force_recheck_events:
        return True, "forced_event"
    events_index = bundle.get("events_index", {})
    incomplete_events = bundle.get("incomplete_events", {})
    indexed = events_index.get(source_event_id or "")
    queued = incomplete_events.get(source_event_id or "")
    if queued:
        if _iso_due(queued.get("next_recheck_after"), now):
            return True, queued.get("recheck_reason") or "incomplete_due"
        return False, "incomplete_recheck_not_due"
    if not indexed:
        return True, "new_visible_event"
    if indexed.get("needs_recheck"):
        return True, indexed.get("recheck_reason") or "needs_recheck"
    event_date = _parse_iso_date(indexed.get("event_date") or list_item.get("event_date"))
    if recheck_complete_days > 0 and event_date and event_date >= now.date() - timedelta(days=recheck_complete_days):
        return True, "recent_recheck_window"
    if recheck_known_limit > 0 and source_event_id in _recent_known_event_ids(events_index, recheck_known_limit):
        return True, "recent_known_limit"
    if indexed.get("state") in {"complete", "ignored", "no_relevant_pro_mma_bouts"}:
        return False, "known_complete_outside_recheck_window"
    return True, indexed.get("state") or "known_not_complete"


def _manifest_mode(options: dict[str, Any]) -> str:
    if options.get("effective_force_full_scan") or options.get("force_full_scan") or options.get("run_mode") == "backfill":
        return "full_scan"
    return "incremental"


def _apply_results_to_state(
    *,
    bundle: dict[str, Any],
    results_doc: dict[str, Any],
    changes: dict[str, Any],
    started_at: str,
    finished_at: str,
    recheck_days: int,
) -> dict[str, int]:
    scraper_state = bundle["scraper_state"]
    events_index = bundle["events_index"]
    bouts_index = bundle["bouts_index"]
    incomplete_events = bundle["incomplete_events"]

    skipped_events_no_usable = int((results_doc.get("run") or {}).get("skipped_events_no_usable_bouts") or 0)
    events_skipped_ufc = int((results_doc.get("run") or {}).get("events_skipped_ufc") or 0)
    skipped_events_non_mma_or_no_results = int(
        (results_doc.get("run") or {}).get("skipped_events_non_mma_or_no_results") or 0
    )

    counts = {
        "events_seen": len(results_doc.get("events", [])) + skipped_events_no_usable + events_skipped_ufc,
        "events_scraped": len(results_doc.get("events", [])),
        "skipped_events_no_usable_bouts": skipped_events_no_usable,
        "skipped_events_non_mma_or_no_results": skipped_events_non_mma_or_no_results,
        "events_skipped_ufc": events_skipped_ufc,
        "events_new": 0,
        "events_updated": 0,
        "events_unchanged": 0,
        "events_rechecked": 0,
        "events_marked_incomplete": 0,
        "bouts_seen": 0,
        "bouts_source_rows_seen": 0,
        "bouts_written": 0,
        "bouts_new": 0,
        "bouts_updated": 0,
        "bouts_unchanged": 0,
    }

    for event in results_doc.get("events", []):
        source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
        if not source_event_id:
            continue
        event["source_event_id"] = source_event_id
        existing_event = events_index.get(source_event_id)
        if existing_event:
            internal_event_id = existing_event["internal_event_id"]
            counts["events_rechecked"] += 1
        else:
            internal_event_id = _next_internal_id(scraper_state, "event")
        event["internal_event_id"] = internal_event_id
        bouts = event.get("bouts", [])
        skipped_bouts = event.get("skipped_bouts", [])
        source_rows_seen = int(
            event.get("source_rows_seen")
            or int(event.get("bouts_included") or len(bouts)) + int(event.get("bouts_skipped_total") or len(skipped_bouts))
        )
        counts["bouts_seen"] += source_rows_seen
        counts["bouts_source_rows_seen"] += source_rows_seen
        counts["bouts_written"] += len(bouts)

        known_bout_ids: list[str] = []
        known_completed_bout_ids: list[str] = []
        for bout in bouts:
            source_bout_id = bout.get("source_bout_id")
            if not source_bout_id:
                continue
            existing_bout = bouts_index.get(source_bout_id)
            if existing_bout:
                internal_bout_id = existing_bout["internal_bout_id"]
            else:
                internal_bout_id = _next_internal_id(scraper_state, "bout")
            bout["internal_bout_id"] = internal_bout_id
            bout["internal_event_id"] = internal_event_id
            bout["source_event_id"] = source_event_id
            known_bout_ids.append(internal_bout_id)
            if bout.get("status") == "completed":
                known_completed_bout_ids.append(internal_bout_id)

            bout_fingerprint = normalized_fingerprint(bout)
            if not existing_bout:
                changes["new_bouts"].append(internal_bout_id)
                counts["bouts_new"] += 1
            elif existing_bout.get("normalized_fingerprint") != bout_fingerprint:
                changes["updated_bouts"].append(internal_bout_id)
                counts["bouts_updated"] += 1
            else:
                changes["unchanged_bouts"].append(internal_bout_id)
                counts["bouts_unchanged"] += 1
            bouts_index[source_bout_id] = _bout_index_record(
                bout=bout,
                event=event,
                existing=existing_bout,
                fingerprint=bout_fingerprint,
                started_at=started_at,
                finished_at=finished_at,
            )

        event_fingerprint = normalized_fingerprint(event)
        if not existing_event:
            changes["new_events"].append(internal_event_id)
            counts["events_new"] += 1
        elif existing_event.get("last_normalized_event_fingerprint") != event_fingerprint:
            changes["updated_events"].append(internal_event_id)
            counts["events_updated"] += 1
        else:
            changes["unchanged_events"].append(internal_event_id)
            counts["events_unchanged"] += 1

        recheck = _event_recheck_status(event, recheck_days=recheck_days, finished_at=finished_at)
        if recheck["needs_recheck"]:
            counts["events_marked_incomplete"] += 1
            changes["incomplete_events"].append(internal_event_id)
            incomplete_events[source_event_id] = _incomplete_event_record(
                event=event,
                existing=incomplete_events.get(source_event_id),
                reason=recheck["reason"],
                priority=recheck["priority"],
                finished_at=finished_at,
            )
        else:
            incomplete_events.pop(source_event_id, None)

        events_index[source_event_id] = _event_index_record(
            event=event,
            existing=existing_event,
            fingerprint=event_fingerprint,
            known_bout_ids=known_bout_ids,
            known_completed_bout_ids=known_completed_bout_ids,
            recheck=recheck,
            started_at=started_at,
            finished_at=finished_at,
        )

    for key in [
        "new_events",
        "updated_events",
        "unchanged_events",
        "new_bouts",
        "updated_bouts",
        "unchanged_bouts",
        "incomplete_events",
    ]:
        changes[key] = sorted(set(changes[key]))
    changes["warnings"] = sorted(set(changes.get("warnings", [])))
    return counts


def _apply_failed_listing_events_to_state(
    *,
    bundle: dict[str, Any],
    changes: dict[str, Any],
    failed_event_examples: Any,
    started_at: str,
    finished_at: str,
) -> None:
    if not isinstance(failed_event_examples, list):
        return
    scraper_state = bundle["scraper_state"]
    events_index = bundle["events_index"]
    incomplete_events = bundle["incomplete_events"]
    for item in failed_event_examples:
        if not isinstance(item, dict):
            continue
        event_url = item.get("event_url")
        source_event_id = item.get("source_event_id") or source_event_id_from_url(event_url)
        if not source_event_id:
            continue
        existing_event = events_index.get(source_event_id)
        internal_event_id = (
            existing_event.get("internal_event_id")
            if isinstance(existing_event, dict) and existing_event.get("internal_event_id")
            else _next_internal_id(scraper_state, "event")
        )
        reason = _failed_event_recheck_reason(item.get("reason"))
        changes.setdefault("incomplete_events", []).append(internal_event_id)
        changes.setdefault("manual_review_events", []).append(source_event_id)
        incomplete_events[source_event_id] = _failed_listing_incomplete_record(
            item=item,
            source_event_id=source_event_id,
            internal_event_id=internal_event_id,
            existing=incomplete_events.get(source_event_id),
            reason=reason,
            finished_at=finished_at,
        )
        events_index[source_event_id] = _failed_listing_event_index_record(
            item=item,
            source_event_id=source_event_id,
            internal_event_id=internal_event_id,
            existing=existing_event if isinstance(existing_event, dict) else None,
            reason=reason,
            started_at=started_at,
            finished_at=finished_at,
        )


def _failed_event_recheck_reason(reason: Any) -> str:
    text = str(reason or "").lower()
    if "timeout" in text or "timed out" in text:
        return "event_fetch_timeout"
    return "event_fetch_failed"


def _commit_success_state(
    bundle: dict[str, Any],
    *,
    run_id: str,
    started_at: str,
    finished_at: str,
    results_doc: dict[str, Any],
) -> None:
    state = bundle["scraper_state"]
    dates = sorted(
        event.get("event_date")
        for event in results_doc.get("events", [])
        if event.get("event_date")
    )
    state.update(
        {
            "schema_version": STATE_SCHEMA_VERSION,
            "source": "tapology",
            "last_successful_run_id": run_id,
            "last_successful_started_at": started_at,
            "last_successful_finished_at": finished_at,
            "last_attempted_run_id": run_id,
            "last_attempted_started_at": started_at,
            "last_attempted_finished_at": finished_at,
            "total_known_events": len(bundle["events_index"]),
            "total_known_bouts": len(bundle["bouts_index"]),
            "last_successful_newest_event_date": dates[-1] if dates else None,
            "last_successful_oldest_event_date": dates[0] if dates else None,
        }
    )
    state["next_internal_event_number"] = max(
        int(state.get("next_internal_event_number") or 1),
        _max_internal_number(bundle["events_index"], "internal_event_id", "mf_event") + 1,
    )
    state["next_internal_bout_number"] = max(
        int(state.get("next_internal_bout_number") or 1),
        _max_internal_number(bundle["bouts_index"], "internal_bout_id", "mf_bout") + 1,
    )


def _event_index_record(
    *,
    event: dict[str, Any],
    existing: dict[str, Any] | None,
    fingerprint: str,
    known_bout_ids: list[str],
    known_completed_bout_ids: list[str],
    recheck: dict[str, Any],
    started_at: str,
    finished_at: str,
) -> dict[str, Any]:
    warnings = sorted(set(event.get("warnings", [])) | {w for bout in event.get("bouts", []) for w in bout.get("warnings", [])})
    return {
        "internal_event_id": event["internal_event_id"],
        "source_event_id": event["source_event_id"],
        "event_url": event.get("event_url"),
        "event_name": event.get("event_name"),
        "event_date": event.get("event_date"),
        "first_seen_at": (existing or {}).get("first_seen_at") or started_at,
        "last_seen_at": finished_at,
        "last_scraped_at": finished_at,
        "last_successfully_scraped_at": finished_at,
        "scrape_count": int((existing or {}).get("scrape_count") or 0) + 1,
        "known_bout_ids": sorted(known_bout_ids),
        "known_completed_bout_ids": sorted(known_completed_bout_ids),
        "known_bouts_count": len(known_bout_ids),
        "known_completed_bouts_count": len(known_completed_bout_ids),
        "source_event_status": event.get("source_event_status"),
        "source_bouts_total": event.get("source_bouts_total"),
        "source_mma_bouts_total": event.get("source_mma_bouts_total"),
        "bouts_included": event.get("bouts_included"),
        "bouts_skipped_total": event.get("bouts_skipped_total"),
        "bouts_skipped_no_result": event.get("bouts_skipped_no_result"),
        "source_rows_seen": event.get("source_rows_seen"),
        "source_mma_rows_seen": event.get("source_mma_rows_seen"),
        "last_normalized_event_fingerprint": fingerprint,
        "state": recheck["state"],
        "needs_recheck": bool(recheck["needs_recheck"]),
        "recheck_reason": recheck["reason"] if recheck["needs_recheck"] else None,
        "last_warning_codes": warnings,
    }


def _bout_index_record(
    *,
    bout: dict[str, Any],
    event: dict[str, Any],
    existing: dict[str, Any] | None,
    fingerprint: str,
    started_at: str,
    finished_at: str,
) -> dict[str, Any]:
    return {
        "internal_bout_id": bout["internal_bout_id"],
        "source_bout_id": bout["source_bout_id"],
        "internal_event_id": event["internal_event_id"],
        "source_event_id": event["source_event_id"],
        "bout_url": bout.get("bout_url"),
        "first_seen_at": (existing or {}).get("first_seen_at") or started_at,
        "last_seen_at": finished_at,
        "last_scraped_at": finished_at,
        "last_successfully_scraped_at": finished_at,
        "normalized_fingerprint": fingerprint,
        "status": bout.get("status"),
        "pro_am": bout.get("pro_am"),
        "result_type": bout.get("result_type"),
        "winner": bout.get("winner"),
        "fighter_a_name": bout.get("fighter_a", {}).get("name"),
        "fighter_b_name": bout.get("fighter_b", {}).get("name"),
    }


def _incomplete_event_record(
    *,
    event: dict[str, Any],
    existing: dict[str, Any] | None,
    reason: str,
    priority: str,
    finished_at: str,
) -> dict[str, Any]:
    recheck_count = int((existing or {}).get("recheck_count") or 0) + (1 if existing else 0)
    delay_days = 1 if priority == "high" else 3
    return {
        "internal_event_id": event["internal_event_id"],
        "source_event_id": event["source_event_id"],
        "event_url": event.get("event_url"),
        "event_name": event.get("event_name"),
        "event_date": event.get("event_date"),
        "source_event_numeric_id": event.get("source_event_numeric_id"),
        "source_event_status": event.get("source_event_status"),
        "source_bouts_total": event.get("source_bouts_total"),
        "source_mma_bouts_total": event.get("source_mma_bouts_total"),
        "bouts_included": event.get("bouts_included"),
        "bouts_skipped_total": event.get("bouts_skipped_total"),
        "bouts_skipped_no_result": event.get("bouts_skipped_no_result"),
        "source_rows_seen": event.get("source_rows_seen"),
        "source_mma_rows_seen": event.get("source_mma_rows_seen"),
        "needs_recheck": bool(event.get("needs_recheck")),
        "first_marked_incomplete_at": (existing or {}).get("first_marked_incomplete_at") or finished_at,
        "last_seen_at": finished_at,
        "last_rechecked_at": finished_at,
        "recheck_count": recheck_count,
        "recheck_reason": reason,
        "next_recheck_after": _add_days_iso(finished_at, delay_days),
        "priority": priority,
    }


def _failed_listing_incomplete_record(
    *,
    item: dict[str, Any],
    source_event_id: str,
    internal_event_id: str,
    existing: dict[str, Any] | None,
    reason: str,
    finished_at: str,
) -> dict[str, Any]:
    recheck_count = int((existing or {}).get("recheck_count") or 0) + (1 if existing else 0)
    return {
        "internal_event_id": internal_event_id,
        "source_event_id": source_event_id,
        "event_url": item.get("event_url"),
        "event_name": item.get("event_name"),
        "event_date": item.get("event_date"),
        "source_event_numeric_id": _source_event_numeric_id(source_event_id),
        "source_event_status": "unknown",
        "source_bouts_total": None,
        "source_mma_bouts_total": None,
        "bouts_included": 0,
        "bouts_skipped_total": 0,
        "bouts_skipped_no_result": 0,
        "source_rows_seen": 0,
        "source_mma_rows_seen": 0,
        "needs_recheck": True,
        "first_marked_incomplete_at": (existing or {}).get("first_marked_incomplete_at") or finished_at,
        "last_seen_at": finished_at,
        "last_rechecked_at": finished_at,
        "recheck_count": recheck_count,
        "recheck_reason": reason,
        "failure_reason": item.get("reason"),
        "next_recheck_after": finished_at,
        "priority": "high",
        "list_page_number": item.get("list_page_number"),
        "list_position": item.get("list_position"),
    }


def _failed_listing_event_index_record(
    *,
    item: dict[str, Any],
    source_event_id: str,
    internal_event_id: str,
    existing: dict[str, Any] | None,
    reason: str,
    started_at: str,
    finished_at: str,
) -> dict[str, Any]:
    return {
        "internal_event_id": internal_event_id,
        "source_event_id": source_event_id,
        "event_url": item.get("event_url"),
        "event_name": item.get("event_name"),
        "event_date": item.get("event_date"),
        "first_seen_at": (existing or {}).get("first_seen_at") or started_at,
        "last_seen_at": finished_at,
        "last_scraped_at": (existing or {}).get("last_scraped_at"),
        "last_successfully_scraped_at": (existing or {}).get("last_successfully_scraped_at"),
        "scrape_count": int((existing or {}).get("scrape_count") or 0),
        "known_bout_ids": list((existing or {}).get("known_bout_ids") or []),
        "known_completed_bout_ids": list((existing or {}).get("known_completed_bout_ids") or []),
        "known_bouts_count": int((existing or {}).get("known_bouts_count") or 0),
        "known_completed_bouts_count": int((existing or {}).get("known_completed_bouts_count") or 0),
        "source_event_status": "unknown",
        "source_bouts_total": None,
        "source_mma_bouts_total": None,
        "bouts_included": 0,
        "bouts_skipped_total": 0,
        "bouts_skipped_no_result": 0,
        "source_rows_seen": 0,
        "source_mma_rows_seen": 0,
        "last_normalized_event_fingerprint": (existing or {}).get("last_normalized_event_fingerprint"),
        "state": "failed_fetch",
        "needs_recheck": True,
        "recheck_reason": reason,
        "last_warning_codes": sorted(set((existing or {}).get("last_warning_codes") or []) | {reason}),
    }


def _source_event_numeric_id(source_event_id: str | None) -> str | None:
    if not source_event_id:
        return None
    match = re.fullmatch(r"tapology_event_(\d+)", str(source_event_id))
    return match.group(1) if match else None


def _enrich_changes_for_dashboard(changes: dict[str, Any], results_doc: dict[str, Any]) -> None:
    changed_event_ids = set(changes.get("new_events", [])) | set(changes.get("updated_events", [])) | set(
        changes.get("incomplete_events", [])
    )
    new_fighter_urls: set[str] = set()
    fighters_to_refresh: set[str] = set()
    manual_review_events: set[str] = set(changes.get("manual_review_events", []))
    for event in results_doc.get("events", []):
        internal_event_id = event.get("internal_event_id")
        source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
        event_fighter_urls = _fighter_urls_from_event(event)
        if internal_event_id in set(changes.get("new_events", [])):
            new_fighter_urls.update(event_fighter_urls)
        if internal_event_id in changed_event_ids:
            fighters_to_refresh.update(event_fighter_urls)
        if _event_needs_manual_review(event):
            if source_event_id:
                manual_review_events.add(source_event_id)
    changes["new_fighter_urls"] = sorted(new_fighter_urls)
    changes["fighters_to_refresh"] = sorted(fighters_to_refresh)
    changes["manual_review_events"] = sorted(manual_review_events)


def _fighter_urls_from_event(event: dict[str, Any]) -> set[str]:
    urls: set[str] = set()
    for bout in event.get("bouts", []):
        for fighter_key in ("fighter_a", "fighter_b"):
            url = ((bout.get(fighter_key) or {}).get("url"))
            if url:
                urls.add(str(url))
    return urls


def _event_needs_manual_review(event: dict[str, Any]) -> bool:
    if event.get("needs_recheck"):
        return True
    if event.get("warnings"):
        return True
    if not event.get("country") or not event.get("source_promotion_id"):
        return True
    for bout in event.get("bouts", []):
        result = bout.get("result") or {}
        if bout.get("status") == "completed" and result.get("method_category") in {None, "", "unknown"}:
            return True
        if bout.get("warnings"):
            return True
    return False


def _event_recheck_status(event: dict[str, Any], *, recheck_days: int, finished_at: str) -> dict[str, Any]:
    bouts = event.get("bouts", [])
    event_date = _parse_iso_date(event.get("event_date"))
    reference_date = _parse_iso_datetime(finished_at).date()
    is_recent = bool(event_date and event_date >= reference_date - timedelta(days=recheck_days))
    if event.get("needs_recheck") is True:
        status = event.get("source_event_status")
        reason = "missing_or_partial_results"
        if status in {"in_progress", "upcoming"}:
            reason = status
        elif int(event.get("bouts_skipped_no_result") or 0) > 0:
            reason = "missing_or_partial_results"
        return {"state": "partial", "needs_recheck": True, "reason": reason, "priority": "high"}
    if not bouts:
        if is_recent:
            return {
                "state": "pending_results",
                "needs_recheck": True,
                "reason": "no_completed_professional_mma_bouts",
                "priority": "high",
            }
        return {
            "state": "no_relevant_pro_mma_bouts",
            "needs_recheck": False,
            "reason": None,
            "priority": None,
        }
    material_event_warnings = [
        warning
        for warning in event.get("warnings", [])
        if not warning.startswith(("bouts_skipped_amateur:", "bouts_skipped_non_mma:"))
        and not _source_total_overcount_is_explained_by_skipped_rows(event, warning)
    ]
    if any(warning.startswith("bouts_skipped_no_result:") for warning in material_event_warnings):
        return {"state": "partial", "needs_recheck": True, "reason": "missing_or_partial_results", "priority": "high"}
    if material_event_warnings:
        return {"state": "parser_attention", "needs_recheck": True, "reason": "parser_warnings", "priority": "high"}
    for bout in bouts:
        result = bout.get("result", {})
        if bout.get("result_type") in {None, "", "unknown"} or result.get("method_category") in {None, "", "unknown"}:
            return {"state": "parser_attention", "needs_recheck": True, "reason": "missing_or_partial_results", "priority": "high"}
        if bout.get("result_type") == "win_loss" and bout.get("winner") in {None, "", "unknown"}:
            return {"state": "parser_attention", "needs_recheck": True, "reason": "missing_or_partial_results", "priority": "high"}
        if bout.get("result_type") == "win_loss" and bout.get("loser") in {None, "", "unknown"}:
            return {"state": "parser_attention", "needs_recheck": True, "reason": "missing_or_partial_results", "priority": "high"}
        if not bout.get("source_bout_id") or not bout.get("fighter_a", {}).get("name") or not bout.get("fighter_b", {}).get("name"):
            return {"state": "parser_attention", "needs_recheck": True, "reason": "missing_or_partial_results", "priority": "high"}
        if any(warning in {"unknown_method"} or warning.startswith("missing_") for warning in bout.get("warnings", [])):
            return {"state": "parser_attention", "needs_recheck": True, "reason": "parser_warnings", "priority": "high"}
    return {"state": "complete", "needs_recheck": False, "reason": None, "priority": None}


def _source_total_overcount_is_explained_by_skipped_rows(event: dict[str, Any], warning: Any) -> bool:
    warning_text = str(warning)
    if not warning_text.startswith("source_mma_bouts_total_mismatch:"):
        return False
    mismatch = event.get("source_mma_bouts_total_mismatch")
    if not isinstance(mismatch, dict):
        return False
    try:
        expected = int(mismatch.get("source_mma_bouts_total") or 0)
        seen = int(mismatch.get("source_mma_rows_seen") or 0)
        retained = int(mismatch.get("retained_bouts") or 0)
    except (TypeError, ValueError):
        return False
    skipped_reasons = mismatch.get("skipped_bouts_by_reason") or {}
    if not isinstance(skipped_reasons, dict):
        skipped_reasons = {}
    explained_skips = int(skipped_reasons.get("amateur") or 0) + int(skipped_reasons.get("non_mma") or 0)
    return retained >= expected and seen == retained + explained_skips


def _empty_scraper_state() -> dict[str, Any]:
    return {
        "schema_version": STATE_SCHEMA_VERSION,
        "source": "tapology",
        "last_successful_run_id": None,
        "last_successful_started_at": None,
        "last_successful_finished_at": None,
        "last_attempted_run_id": None,
        "last_attempted_started_at": None,
        "last_attempted_finished_at": None,
        "next_internal_event_number": 1,
        "next_internal_bout_number": 1,
        "total_known_events": 0,
        "total_known_bouts": 0,
        "last_successful_newest_event_date": None,
        "last_successful_oldest_event_date": None,
    }


def _normalize_scraper_state(
    scraper_state: dict[str, Any],
    events_index: dict[str, Any],
    bouts_index: dict[str, Any],
) -> dict[str, Any]:
    state = {**_empty_scraper_state(), **(scraper_state or {})}
    state["schema_version"] = STATE_SCHEMA_VERSION
    state["source"] = "tapology"
    state["next_internal_event_number"] = max(
        int(state.get("next_internal_event_number") or 1),
        _max_internal_number(events_index, "internal_event_id", "mf_event") + 1,
    )
    state["next_internal_bout_number"] = max(
        int(state.get("next_internal_bout_number") or 1),
        _max_internal_number(bouts_index, "internal_bout_id", "mf_bout") + 1,
    )
    state["total_known_events"] = len(events_index)
    state["total_known_bouts"] = len(bouts_index)
    return state


def _empty_changes(run_id: str) -> dict[str, Any]:
    return {
        "schema_version": CHANGES_SCHEMA_VERSION,
        "run_id": run_id,
        "new_events": [],
        "updated_events": [],
        "unchanged_events": [],
        "new_bouts": [],
        "updated_bouts": [],
        "unchanged_bouts": [],
        "incomplete_events": [],
        "new_fighter_urls": [],
        "fighters_to_refresh": [],
        "manual_review_events": [],
        "warnings": [],
    }


def _update_event_payload_store(event_payloads: dict[str, Any], events: list[dict[str, Any]]) -> None:
    for event in events:
        source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
        if not source_event_id or not _cacheable_event_payload(event):
            continue
        payload = deepcopy(event)
        payload["source_event_id"] = source_event_id
        event_payloads[source_event_id] = payload


def _build_output_results_doc(
    *,
    results_doc: dict[str, Any],
    event_payloads: dict[str, Any],
    summary: dict[str, Any],
    options: dict[str, Any],
    scraped_events_count: int,
) -> tuple[dict[str, Any], dict[str, int]]:
    output_doc = deepcopy(results_doc)
    current_events = output_doc.get("events", [])
    current_by_id: dict[str, dict[str, Any]] = {}
    for event in current_events:
        source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
        if source_event_id:
            event["source_event_id"] = source_event_id
            current_by_id[source_event_id] = event

    cached_inserted = 0
    if options.get("cumulative_results"):
        ordered_ids = _cumulative_payload_ids(event_payloads)
        for source_event_id in current_by_id:
            if source_event_id not in ordered_ids:
                ordered_ids.append(source_event_id)
        final_events: list[dict[str, Any]] = []
        included_ids: set[str] = set()
        for source_event_id in ordered_ids:
            if source_event_id in current_by_id:
                final_events.append(current_by_id[source_event_id])
                included_ids.add(source_event_id)
                continue
            cached = event_payloads.get(source_event_id)
            if _cacheable_event_payload(cached):
                final_events.append(deepcopy(cached))
                included_ids.add(source_event_id)
                cached_inserted += 1
        for event in current_events:
            source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
            if source_event_id and source_event_id in included_ids:
                continue
            final_events.append(event)
            if source_event_id:
                included_ids.add(source_event_id)
        output_doc["events"] = final_events

    populate_results_contract_fields(output_doc)
    missing_cached = int(summary.get("missing_cached_event_payloads") or 0)
    return output_doc, {
        "events_output_total": len(output_doc.get("events", [])),
        "events_scraped_this_run": scraped_events_count,
        "events_from_payload_cache": cached_inserted,
        "missing_cached_event_payloads": missing_cached,
    }


def _cumulative_payload_ids(event_payloads: Any) -> list[str]:
    ids: list[str] = []
    if not isinstance(event_payloads, dict):
        return ids
    for key, payload in event_payloads.items():
        source_event_id = key if isinstance(key, str) else None
        if isinstance(payload, dict):
            source_event_id = (
                payload.get("source_event_id")
                or source_event_id_from_url(payload.get("event_url"))
                or source_event_id
            )
        if source_event_id and source_event_id not in ids:
            ids.append(source_event_id)
    return ids


def _cumulative_scope_ids(scope: Any) -> list[str]:
    ids: list[str] = []
    if not isinstance(scope, list):
        return ids
    for item in scope:
        source_event_id = None
        if isinstance(item, dict):
            source_event_id = item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
        elif isinstance(item, str):
            source_event_id = source_event_id_from_url(item) or item
        if source_event_id and source_event_id not in ids:
            ids.append(source_event_id)
    return ids


def _cacheable_event_payload(event: Any) -> bool:
    if not isinstance(event, dict):
        return False
    if not event.get("source_event_id") and not source_event_id_from_url(event.get("event_url")):
        return False
    if "event_skipped_ufc" in set(event.get("warnings") or []):
        return False
    if event.get("bouts"):
        return True
    skipped_bouts = event.get("skipped_bouts") if isinstance(event.get("skipped_bouts"), list) else []
    has_pending_result = any(
        isinstance(skipped, dict) and skipped.get("reason") == "no_result"
        for skipped in skipped_bouts
    )
    has_pending_warning = any(
        str(warning).startswith("bouts_skipped_no_result:")
        for warning in event.get("warnings", [])
    )
    return bool(event.get("needs_recheck") and (has_pending_result or has_pending_warning))


def _manifest_counts(summary: dict[str, Any], counts: dict[str, int], changes: dict[str, Any]) -> dict[str, Any]:
    return {
        "events_seen": int(summary.get("events_seen") or counts["events_seen"]),
        "events_scraped": counts["events_scraped"],
        "events_scraped_this_run": counts["events_scraped"],
        "events_skipped_known_stable": int(summary.get("events_skipped_known_stable") or 0),
        "events_skipped_known_cache": int(summary.get("events_skipped_known_cache") or 0),
        "events_rechecked_lookback": int(summary.get("events_rechecked_lookback") or 0),
        "events_rechecked_incomplete": int(summary.get("events_rechecked_incomplete") or 0),
        "early_stop_triggered": bool(summary.get("early_stop_triggered") or False),
        "early_stop_reason": summary.get("early_stop_reason"),
        "pages_scanned": int(summary.get("pages_scanned") or 0),
        "first_listing_url": summary.get("first_listing_url"),
        "last_listing_url": summary.get("last_listing_url"),
        "listing_urls_visited": summary.get("listing_urls_visited", []),
        "pagination_next_found": bool(summary.get("pagination_next_found") or False),
        "pagination_stop_reason": summary.get("pagination_stop_reason"),
        "load_more_clicks": int(summary.get("load_more_clicks") or 0),
        "load_more_successes": int(summary.get("load_more_successes") or 0),
        "load_more_no_new_events": int(summary.get("load_more_no_new_events") or 0),
        "load_more_timeouts": int(summary.get("load_more_timeouts") or 0),
        "load_more_fallback_attempts": int(summary.get("load_more_fallback_attempts") or 0),
        "load_more_fallback_successes": int(summary.get("load_more_fallback_successes") or 0),
        "load_more_fallback_failures": int(summary.get("load_more_fallback_failures") or 0),
        "load_more_popup_pages_closed": int(summary.get("load_more_popup_pages_closed") or 0),
        "load_more_overlay_dismissals": int(summary.get("load_more_overlay_dismissals") or 0),
        "unique_listing_event_urls_seen": int(summary.get("unique_listing_event_urls_seen") or 0),
        "duplicate_listing_event_urls_seen": int(summary.get("duplicate_listing_event_urls_seen") or 0),
        "popup_dismissals_count": int(summary.get("popup_dismissals_count") or 0),
        "page_pauses_count": int(summary.get("page_pauses_count") or 0),
        "event_pauses_count": int(summary.get("event_pauses_count") or 0),
        "profile_pauses_count": int(summary.get("profile_pauses_count") or 0),
        "profile_batch_pauses_count": int(summary.get("profile_batch_pauses_count") or 0),
        "total_pause_seconds": float(summary.get("total_pause_seconds") or 0.0),
        "profile_fetches_before_cap": int(summary.get("profile_fetches_before_cap") or 0),
        "profile_enrichment_partial": bool(summary.get("profile_enrichment_partial") or False),
        "access_stop_reason": summary.get("access_stop_reason"),
        "consecutive_access_failures": int(summary.get("consecutive_access_failures") or 0),
        "event_fetch_failures": int(summary.get("event_fetch_failures") or 0),
        "failed_event_examples": summary.get("failed_event_examples", []),
        "failed_bout_examples": summary.get("failed_bout_examples", []),
        "skipped_events_no_usable_bouts": int(
            summary.get("skipped_events_no_usable_bouts") or counts.get("skipped_events_no_usable_bouts", 0)
        ),
        "skipped_events_non_mma_or_no_results": int(
            summary.get("skipped_events_non_mma_or_no_results")
            or counts.get("skipped_events_non_mma_or_no_results", 0)
        ),
        "events_skipped_ufc": int(summary.get("events_skipped_ufc") or counts.get("events_skipped_ufc", 0)),
        "events_new": counts["events_new"],
        "events_updated": counts["events_updated"],
        "events_unchanged": counts["events_unchanged"],
        "events_rechecked": counts["events_rechecked"],
        "events_marked_incomplete": len(changes.get("incomplete_events", [])),
        "bouts_seen": int(summary.get("bouts_seen") or counts["bouts_seen"]),
        "bouts_source_rows_seen": int(
            summary.get("bouts_source_rows_seen") or counts.get("bouts_source_rows_seen", counts["bouts_seen"])
        ),
        "bouts_written": int(summary.get("bouts_written") or counts.get("bouts_written", counts["bouts_seen"])),
        "bouts_new": counts["bouts_new"],
        "bouts_updated": counts["bouts_updated"],
        "bouts_unchanged": counts["bouts_unchanged"],
        "bouts_skipped_amateur": int(summary.get("bouts_skipped_amateur") or 0),
        "bouts_skipped_non_mma": int(summary.get("bouts_skipped_non_mma") or 0),
        "bouts_skipped_no_result": int(summary.get("bouts_skipped_no_result") or 0),
        "bouts_skipped_unknown_professional_status": int(summary.get("bouts_skipped_unknown_professional_status") or 0),
        "source_mma_bouts_total_mismatches": int(summary.get("source_mma_bouts_total_mismatches") or 0),
        "profile_enrichment_status": summary.get("profile_enrichment_status", "not_requested"),
        "profile_cache_hits": int(summary.get("profile_cache_hits") or 0),
        "profile_cache_misses": int(summary.get("profile_cache_misses") or 0),
        "profile_cache_expired": int(summary.get("profile_cache_expired") or 0),
        "profile_cache_disabled": int(summary.get("profile_cache_disabled") or 0),
        "profile_fetch_attempts": int(summary.get("profile_fetch_attempts") or 0),
        "profile_fetch_successes": int(summary.get("profile_fetch_successes") or 0),
        "profile_fetch_failures": int(summary.get("profile_fetch_failures") or 0),
        "profile_fetch_failure_reasons": summary.get("profile_fetch_failure_reasons", {}),
        "profile_parse_successes": int(summary.get("profile_parse_successes") or 0),
        "profile_parse_failures": int(summary.get("profile_parse_failures") or 0),
        "profile_context_counts": summary.get("profile_context_counts", {}),
        "profile_fields_coverage": summary.get("profile_fields_coverage", {}),
        "top_profile_warning_codes": summary.get("top_profile_warning_codes", []),
        "warnings_count": int(summary.get("warnings_count") or len(summary.get("warnings", []))),
        "retained_event_warnings_count": int(summary.get("retained_event_warnings_count") or 0),
        "skipped_event_warnings_count": int(summary.get("skipped_event_warnings_count") or 0),
        "errors_count": int(summary.get("errors_count") or len(summary.get("errors", []))),
    }


def _sync_manifest_counts_with_output(manifest_counts: dict[str, Any], results_doc: dict[str, Any]) -> None:
    run = results_doc.get("run") or {}
    for key in (
        "events_seen",
        "events_written",
        "bouts_seen",
        "bouts_source_rows_seen",
        "bouts_written",
        "warnings_count",
        "retained_event_warnings_count",
        "skipped_event_warnings_count",
        "errors_count",
    ):
        if key in run:
            manifest_counts[key] = run[key]


def _refresh_document_counts(results_doc: dict[str, Any], counts: dict[str, int]) -> None:
    results_doc.setdefault("run", {})
    results_doc["run"].update(
        {
            "events_seen": counts["events_seen"],
            "events_written": counts["events_scraped"],
            "skipped_events_no_usable_bouts": counts.get("skipped_events_no_usable_bouts", 0),
            "skipped_events_non_mma_or_no_results": counts.get("skipped_events_non_mma_or_no_results", 0),
            "events_skipped_ufc": counts.get("events_skipped_ufc", 0),
            "bouts_seen": counts["bouts_seen"],
            "bouts_source_rows_seen": counts.get("bouts_source_rows_seen", counts["bouts_seen"]),
            "bouts_written": counts.get("bouts_written", counts["bouts_seen"]),
        }
    )


def _refresh_output_document_counts(
    results_doc: dict[str, Any],
    output_counts: dict[str, int],
    summary: dict[str, Any],
) -> None:
    events = results_doc.get("events", [])
    bouts = [bout for event in events for bout in event.get("bouts", [])]
    skipped_bouts = [skipped for event in events for skipped in event.get("skipped_bouts", [])]
    run = results_doc.setdefault("run", {})
    skipped_events_no_usable = int(run.get("skipped_events_no_usable_bouts") or 0)
    skipped_ufc = int(run.get("events_skipped_ufc") or 0)
    run["events_seen"] = len(events) + skipped_events_no_usable + skipped_ufc
    run["events_written"] = len(events)
    run["events_scraped_this_run"] = output_counts["events_scraped_this_run"]
    run["events_from_payload_cache"] = output_counts["events_from_payload_cache"]
    run["missing_cached_event_payloads"] = output_counts["missing_cached_event_payloads"]
    run["bouts_written"] = len(bouts)
    run["bouts_seen"] = len(bouts) + len(skipped_bouts)
    run["bouts_source_rows_seen"] = len(bouts) + len(skipped_bouts)
    retained = sum(len(event.get("warnings", [])) for event in events) + sum(
        len(bout.get("warnings", []))
        for event in events
        for bout in event.get("bouts", [])
    )
    run["retained_event_warnings_count"] = retained
    summary["retained_event_warnings_count"] = retained


def _sync_results_warning_counts(results_doc: dict[str, Any], summary: dict[str, Any]) -> None:
    run = results_doc.setdefault("run", {})
    events = results_doc.get("events", [])
    retained = sum(len(event.get("warnings", [])) for event in events) + sum(
        len(bout.get("warnings", []))
        for event in events
        for bout in event.get("bouts", [])
    )
    total = max(int(summary.get("warnings_count") or len(summary.get("warnings", [])) or 0), retained)
    skipped = max(total - retained, 0)
    errors_count = int(summary.get("errors_count") or len(summary.get("errors", [])) or 0)
    run["warnings_count"] = total
    run["retained_event_warnings_count"] = retained
    run["skipped_event_warnings_count"] = skipped
    run["errors_count"] = errors_count
    summary["warnings_count"] = total
    summary["retained_event_warnings_count"] = retained
    summary["skipped_event_warnings_count"] = skipped
    summary["errors_count"] = errors_count


def _next_internal_id(state: dict[str, Any], kind: str) -> str:
    if kind == "event":
        number = int(state.get("next_internal_event_number") or 1)
        state["next_internal_event_number"] = number + 1
        return f"mf_event_{number:06d}"
    number = int(state.get("next_internal_bout_number") or 1)
    state["next_internal_bout_number"] = number + 1
    return f"mf_bout_{number:06d}"


def _max_internal_number(index: dict[str, Any], field: str, prefix: str) -> int:
    return max([0] + [internal_number(record.get(field), prefix) for record in index.values() if isinstance(record, dict)])


def _fingerprint_value(value: Any) -> Any:
    if isinstance(value, dict):
        return {
            key: _fingerprint_value(item)
            for key, item in sorted(value.items())
            if key not in FINGERPRINT_IGNORED_KEYS and not key.endswith("_at")
        }
    if isinstance(value, list):
        return [_fingerprint_value(item) for item in value]
    return value


def _read_json(path: Path, default: Any) -> Any:
    if not path.exists():
        return deepcopy(default)
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def _iso_due(value: str | None, now: datetime) -> bool:
    if not value:
        return True
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return True
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed <= now.astimezone(timezone.utc)


def _parse_iso_date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value)
    except ValueError:
        return None


def _parse_iso_datetime(value: str | None) -> datetime:
    if not value:
        return datetime.now(timezone.utc)
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return datetime.now(timezone.utc)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _add_days_iso(value: str, days: int) -> str:
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        parsed = datetime.now(timezone.utc)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return (parsed + timedelta(days=days)).replace(microsecond=0).isoformat()


def _recent_known_event_ids(events_index: dict[str, Any], limit: int) -> set[str]:
    if limit <= 0:
        return set()
    dated = [
        (record.get("event_date") or "", source_event_id)
        for source_event_id, record in events_index.items()
        if isinstance(record, dict)
    ]
    dated.sort(reverse=True)
    return {source_event_id for _, source_event_id in dated[:limit]}
