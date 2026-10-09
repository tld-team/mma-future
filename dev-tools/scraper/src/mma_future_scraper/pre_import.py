from __future__ import annotations

import json
from collections import Counter
from pathlib import Path
from typing import Any

from .config import DATA_DIR, LATEST_DIR
from .fighter_profile_queue import normalize_tapology_fighter_url
from .storage import atomic_write_json
from .utils import source_fighter_id_from_url, utc_now
from .validation import validate_results_document


DAILY_SUMMARY_SCHEMA_VERSION = "scraper_daily_summary_v0_1"
MANUAL_REVIEW_SCHEMA_VERSION = "scraper_manual_review_v0_1"


def build_pre_import_artifacts(
    *,
    latest_dir: Path = LATEST_DIR,
    state_dir: Path | None = None,
    expected_pages: int | None = None,
    max_event_fetch_failures: int = 0,
    allow_missing_cached_event_payloads: bool = False,
    min_profile_success_rate: float = 0.99,
    write: bool = True,
) -> tuple[dict[str, Any], dict[str, Any]]:
    state_dir = state_dir or latest_dir.parent / "state"
    results_path = latest_dir / "results.json"
    manifest_path = latest_dir / "run_manifest.json"
    changes_path = latest_dir / "changes.json"
    profiles_path = latest_dir / "fighter_profiles.json"
    profile_report_path = latest_dir / "fighter_profiles_report.json"
    state_incomplete_path = state_dir / "incomplete_events.json"

    results = _read_json(results_path)
    manifest = _read_json(manifest_path)
    changes = _read_json(changes_path)
    profiles = _read_json(profiles_path)
    profile_report = _read_json(profile_report_path)
    incomplete_events = _read_json(state_incomplete_path)

    validation_errors: list[str] = []
    for path in [results_path, manifest_path, changes_path]:
        if not path.exists():
            validation_errors.append(f"missing_canonical_file:{path}")
    if results:
        validation_errors.extend(validate_results_document(results))

    manual_review = build_manual_review_document(
        results=results,
        manifest=manifest,
        changes=changes,
        profiles=profiles,
        profile_report=profile_report,
        incomplete_events=incomplete_events,
        validation_errors=validation_errors,
    )
    daily_summary = build_daily_summary_document(
        results=results,
        manifest=manifest,
        profiles=profiles,
        profile_report=profile_report,
        manual_review=manual_review,
        validation_errors=validation_errors,
        expected_pages=expected_pages,
        max_event_fetch_failures=max_event_fetch_failures,
        allow_missing_cached_event_payloads=allow_missing_cached_event_payloads,
        min_profile_success_rate=min_profile_success_rate,
        latest_dir=latest_dir,
    )
    if write:
        atomic_write_json(latest_dir / "manual_review.json", manual_review)
        atomic_write_json(latest_dir / "daily_summary.json", daily_summary)
    return daily_summary, manual_review


def build_manual_review_document(
    *,
    results: dict[str, Any],
    manifest: dict[str, Any],
    changes: dict[str, Any],
    profiles: dict[str, Any],
    profile_report: dict[str, Any],
    incomplete_events: dict[str, Any],
    validation_errors: list[str] | None = None,
) -> dict[str, Any]:
    items: list[dict[str, Any]] = []
    for error in validation_errors or []:
        items.append(_review_item("blocking_validation_error", "validation", "blocker", {"error": error}))

    for failed in (manifest.get("counts") or {}).get("failed_event_examples", []) or []:
        if not isinstance(failed, dict):
            continue
        reason = _failed_event_type(failed.get("reason"))
        items.append(
            _review_item(
                reason,
                "event",
                "review",
                {
                    "source_event_id": failed.get("source_event_id"),
                    "event_url": failed.get("event_url"),
                    "event_name": failed.get("event_name"),
                    "list_page_number": failed.get("list_page_number"),
                    "list_position": failed.get("list_position"),
                    "reason": failed.get("reason"),
                },
            )
        )

    for source_event_id, record in incomplete_events.items() if isinstance(incomplete_events, dict) else []:
        if not isinstance(record, dict):
            continue
        items.append(
            _review_item(
                record.get("recheck_reason") or "incomplete_event",
                "event",
                "review",
                {
                    "source_event_id": source_event_id,
                    "internal_event_id": record.get("internal_event_id"),
                    "event_url": record.get("event_url"),
                    "event_name": record.get("event_name"),
                    "event_date": record.get("event_date"),
                    "next_recheck_after": record.get("next_recheck_after"),
                },
            )
        )

    profile_by_id, profile_by_url = _profile_indexes(profiles)
    results_fighters = _fighter_refs_from_results(results)
    for fighter in results_fighters:
        profile = _profile_for_ref(fighter, profile_by_id, profile_by_url)
        if profile is None:
            items.append(_review_item("missing_fighter_profile", "fighter", "review", fighter))
            continue
        if profile.get("status") != "success":
            items.append(
                _review_item(
                    "fighter_profile_failed",
                    "fighter",
                    "review",
                    {**fighter, "status": profile.get("status"), "warnings": profile.get("warnings") or []},
                )
            )
        record = profile.get("record_summary") if isinstance(profile.get("record_summary"), dict) else {}
        if profile.get("status") == "success" and not record.get("pro_record_raw"):
            items.append(_review_item("fighter_without_record", "fighter", "review", fighter))

    for event in results.get("events", []) if isinstance(results, dict) else []:
        if not isinstance(event, dict):
            continue
        if event.get("warnings"):
            items.append(_review_item("event_parser_warning", "event", "review", _event_context(event)))
        if not event.get("country") or not event.get("source_promotion_id"):
            items.append(_review_item("event_missing_location_or_promotion_id", "event", "review", _event_context(event)))
        if event.get("needs_recheck"):
            reason = "no_result_pending_event" if int(event.get("bouts_skipped_no_result") or 0) else "event_needs_recheck"
            items.append(_review_item(reason, "event", "review", _event_context(event)))
        for bout in event.get("bouts", []):
            if not isinstance(bout, dict):
                continue
            result = bout.get("result") or {}
            if bout.get("status") == "completed" and result.get("method_category") in {None, "", "unknown"}:
                items.append(
                    _review_item(
                        "completed_bout_missing_method",
                        "bout",
                        "review",
                        {
                            **_event_context(event),
                            "source_bout_id": bout.get("source_bout_id"),
                            "internal_bout_id": bout.get("internal_bout_id"),
                            "bout_url": bout.get("bout_url"),
                        },
                    )
                )

    items = _dedupe_review_items(items)
    counts = Counter(item["type"] for item in items)
    return {
        "schema_version": MANUAL_REVIEW_SCHEMA_VERSION,
        "generated_at": utc_now(),
        "run_id": manifest.get("run_id"),
        "manual_review_count": len(items),
        "blocking_review_count": sum(1 for item in items if item.get("severity") == "blocker"),
        "counts_by_type": dict(sorted(counts.items())),
        "changes_manual_review_events": list(changes.get("manual_review_events") or []),
        "profile_report_success_rate": profile_report.get("success_rate"),
        "items": items,
    }


def build_daily_summary_document(
    *,
    results: dict[str, Any],
    manifest: dict[str, Any],
    profiles: dict[str, Any],
    profile_report: dict[str, Any],
    manual_review: dict[str, Any],
    validation_errors: list[str],
    expected_pages: int | None,
    max_event_fetch_failures: int,
    allow_missing_cached_event_payloads: bool,
    min_profile_success_rate: float,
    latest_dir: Path,
) -> dict[str, Any]:
    counts = manifest.get("counts") if isinstance(manifest.get("counts"), dict) else {}
    events = results.get("events", []) if isinstance(results, dict) else []
    bouts_total = sum(len(event.get("bouts", [])) for event in events if isinstance(event, dict))
    profile_counts = _profile_counts(profiles, profile_report)
    blocking_issues = list(validation_errors)

    if manifest.get("status") != "success":
        blocking_issues.append(f"event_run_status:{manifest.get('status')}")
    if expected_pages is not None and int(counts.get("pages_scanned") or 0) != expected_pages:
        blocking_issues.append(f"pages_scanned:{counts.get('pages_scanned')}:expected:{expected_pages}")
    if int(counts.get("errors_count") or 0) != 0:
        blocking_issues.append(f"errors_count:{counts.get('errors_count')}")
    if int(counts.get("event_fetch_failures") or 0) > max_event_fetch_failures:
        blocking_issues.append(f"event_fetch_failures:{counts.get('event_fetch_failures')}:max:{max_event_fetch_failures}")
    if not allow_missing_cached_event_payloads and int(counts.get("missing_cached_event_payloads") or 0) != 0:
        blocking_issues.append(f"missing_cached_event_payloads:{counts.get('missing_cached_event_payloads')}")
    if profile_counts["profiles_total"] and profile_counts["success_rate"] < min_profile_success_rate:
        blocking_issues.append(
            f"fighter_profile_success_rate:{profile_counts['success_rate']}:min:{min_profile_success_rate}"
        )
    if profile_counts["profiles_total"] == 0:
        blocking_issues.append("missing_fighter_profiles")
    missing_profiles = [
        item for item in manual_review.get("items", []) if item.get("type") == "missing_fighter_profile"
    ]
    if manual_review.get("blocking_review_count"):
        blocking_issues.append(f"blocking_manual_review_items:{manual_review.get('blocking_review_count')}")

    blocking_issues = sorted(set(str(issue) for issue in blocking_issues if issue))
    review_count = int(manual_review.get("manual_review_count") or 0)
    blocking_review_count = int(manual_review.get("blocking_review_count") or 0)
    non_blocking_review_count = max(0, review_count - blocking_review_count)
    return {
        "schema_version": DAILY_SUMMARY_SCHEMA_VERSION,
        "generated_at": utc_now(),
        "ready_for_import": not blocking_issues,
        "review_required": review_count > 0,
        "event_run_status": manifest.get("status"),
        "profile_run_status": "success" if profile_counts["profiles_total"] and profile_counts["profiles_failed"] == 0 else "partial",
        "run_id": manifest.get("run_id"),
        "events_total": len(events),
        "bouts_total": bouts_total,
        "profiles_total": profile_counts["profiles_total"],
        "profiles_success": profile_counts["profiles_success"],
        "profiles_failed": profile_counts["profiles_failed"],
        "profile_success_rate": profile_counts["success_rate"],
        "manual_review_count": int(manual_review.get("manual_review_count") or 0),
        "blocking_review_count": blocking_review_count,
        "non_blocking_review_count": non_blocking_review_count,
        "missing_fighter_profiles_for_results": len(missing_profiles),
        "blocking_issues": blocking_issues,
        "health_gate": {
            "expected_pages": expected_pages,
            "pages_scanned": counts.get("pages_scanned"),
            "errors_count": counts.get("errors_count"),
            "event_fetch_failures": counts.get("event_fetch_failures"),
            "max_event_fetch_failures": max_event_fetch_failures,
            "missing_cached_event_payloads": counts.get("missing_cached_event_payloads"),
            "allow_missing_cached_event_payloads": allow_missing_cached_event_payloads,
            "min_profile_success_rate": min_profile_success_rate,
        },
        "canonical_outputs": {
            "results": str(latest_dir / "results.json"),
            "fighter_profiles": str(latest_dir / "fighter_profiles.json"),
            "run_manifest": str(latest_dir / "run_manifest.json"),
            "fighter_profiles_report": str(latest_dir / "fighter_profiles_report.json"),
            "state_report": str(latest_dir / "state_report.json"),
            "changes": str(latest_dir / "changes.json"),
            "manual_review": str(latest_dir / "manual_review.json"),
        },
        "dashboard_import_contract": {
            "event_dedupe_key": "source_event_id",
            "bout_dedupe_key": "source_bout_id",
            "fighter_dedupe_key": "source_fighter_id",
            "stable_local_event_key": "internal_event_id",
            "stable_local_bout_key": "internal_bout_id",
        },
    }


def _review_item(item_type: str, entity: str, severity: str, context: dict[str, Any]) -> dict[str, Any]:
    return {
        "type": item_type,
        "entity": entity,
        "severity": severity,
        "context": {key: value for key, value in context.items() if value not in (None, "", [])},
    }


def _dedupe_review_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[str] = set()
    deduped: list[dict[str, Any]] = []
    for item in items:
        context = item.get("context") or {}
        key = "|".join(
            [
                str(item.get("type")),
                str(context.get("source_event_id") or ""),
                str(context.get("source_bout_id") or ""),
                str(context.get("source_fighter_id") or context.get("source_url") or ""),
            ]
        )
        if key in seen:
            continue
        seen.add(key)
        deduped.append(item)
    return deduped


def _event_context(event: dict[str, Any]) -> dict[str, Any]:
    return {
        "source_event_id": event.get("source_event_id"),
        "internal_event_id": event.get("internal_event_id"),
        "event_url": event.get("event_url"),
        "event_name": event.get("event_name"),
        "event_date": event.get("event_date"),
        "warnings": event.get("warnings") or [],
    }


def _fighter_refs_from_results(results: dict[str, Any]) -> list[dict[str, Any]]:
    refs: dict[str, dict[str, Any]] = {}
    for event in results.get("events", []) if isinstance(results, dict) else []:
        if not isinstance(event, dict):
            continue
        for bout in event.get("bouts", []):
            if not isinstance(bout, dict):
                continue
            for fighter_key in ("fighter_a", "fighter_b"):
                fighter = bout.get(fighter_key) or {}
                url = normalize_tapology_fighter_url(fighter.get("url"))
                source_fighter_id = fighter.get("source_fighter_id") or source_fighter_id_from_url(url)
                key = source_fighter_id or url
                if not key:
                    continue
                refs.setdefault(
                    str(key),
                    {
                        "source_fighter_id": source_fighter_id,
                        "source_url": url,
                        "display_name": fighter.get("name"),
                    },
                )
    return list(refs.values())


def _profile_indexes(profiles: dict[str, Any]) -> tuple[dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    by_id: dict[str, dict[str, Any]] = {}
    by_url: dict[str, dict[str, Any]] = {}
    for profile in profiles.get("profiles", []) if isinstance(profiles, dict) else []:
        if not isinstance(profile, dict):
            continue
        source_url = normalize_tapology_fighter_url(profile.get("source_url"))
        source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(source_url)
        if source_fighter_id:
            by_id[str(source_fighter_id)] = profile
        if source_url:
            by_url[source_url] = profile
    return by_id, by_url


def _profile_for_ref(
    fighter: dict[str, Any],
    by_id: dict[str, dict[str, Any]],
    by_url: dict[str, dict[str, Any]],
) -> dict[str, Any] | None:
    source_fighter_id = fighter.get("source_fighter_id")
    if source_fighter_id and str(source_fighter_id) in by_id:
        return by_id[str(source_fighter_id)]
    source_url = normalize_tapology_fighter_url(fighter.get("source_url"))
    if source_url:
        return by_url.get(source_url)
    return None


def _profile_counts(profiles: dict[str, Any], profile_report: dict[str, Any]) -> dict[str, Any]:
    if profile_report:
        total = int(profile_report.get("profiles_total") or 0)
        success = int(profile_report.get("profiles_success") or 0)
        failed = int(profile_report.get("profiles_failed") or max(total - success, 0))
        return {"profiles_total": total, "profiles_success": success, "profiles_failed": failed, "success_rate": _ratio(success, total)}
    profile_rows = [profile for profile in profiles.get("profiles", []) if isinstance(profile, dict)] if isinstance(profiles, dict) else []
    total = len(profile_rows)
    success = sum(1 for profile in profile_rows if profile.get("status") == "success")
    return {"profiles_total": total, "profiles_success": success, "profiles_failed": total - success, "success_rate": _ratio(success, total)}


def _failed_event_type(reason: Any) -> str:
    text = str(reason or "").lower()
    if "timeout" in text or "timed out" in text:
        return "event_fetch_timeout"
    return "event_fetch_failed"


def _ratio(numerator: int, denominator: int) -> float:
    if denominator <= 0:
        return 0.0
    return round(numerator / denominator, 4)


def _read_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return payload if isinstance(payload, dict) else {}
