from __future__ import annotations

import json
import re
from collections import Counter
from copy import deepcopy
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

from .config import DATA_DIR, LATEST_DIR, RUNS_DIR
from .storage import atomic_write_json
from .utils import canonical_url, source_fighter_id_from_url, utc_now


FIGHTER_PROFILE_QUEUE_SCHEMA_VERSION = "fighter_profile_queue_v0_1"
FIGHTER_PROFILE_QUEUE_REPORT_SCHEMA_VERSION = "fighter_profile_queue_report_v0_1"

DEFAULT_QUEUE_PATH = LATEST_DIR / "fighter_profile_queue.json"
DEFAULT_QUEUE_REPORT_PATH = LATEST_DIR / "fighter_profile_queue_report.json"
DEFAULT_DERIVED_INDEX_PATH = DATA_DIR / "derived" / "fighters_index.json"
DEFAULT_EXISTING_PROFILES_PATH = LATEST_DIR / "fighter_profiles.json"
DEFAULT_LATEST_UPCOMING_PATH = LATEST_DIR / "upcoming.json"
DEFAULT_LATEST_CHANGES_PATH = LATEST_DIR / "changes.json"


SOURCE_LATEST_RESULTS = "latest_results"
SOURCE_LATEST_UPCOMING = "latest_upcoming"
SOURCE_RUN_HISTORY = "run_history"
SOURCE_DERIVED_INDEX = "derived_index"
SOURCE_INPUT_FILE = "input_file"


def build_fighter_profile_queue(
    *,
    latest_results_path: Path = LATEST_DIR / "results.json",
    latest_upcoming_path: Path = DEFAULT_LATEST_UPCOMING_PATH,
    runs_dir: Path = RUNS_DIR,
    derived_index_path: Path = DEFAULT_DERIVED_INDEX_PATH,
    existing_profiles_path: Path = DEFAULT_EXISTING_PROFILES_PATH,
    latest_changes_path: Path = DEFAULT_LATEST_CHANGES_PATH,
    input_file: Path | None = None,
    include_run_history: bool = False,
    skip_already_enriched: bool = False,
    queue_mode: str = "default",
    output_path: Path = DEFAULT_QUEUE_PATH,
    report_path: Path = DEFAULT_QUEUE_REPORT_PATH,
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Path]]:
    builder = _QueueBuilder(existing_profiles_path=existing_profiles_path)
    builder.collect_results(latest_results_path, SOURCE_LATEST_RESULTS)
    builder.collect_results(latest_upcoming_path, SOURCE_LATEST_UPCOMING, kind="upcoming")
    if include_run_history:
        for results_path in sorted(runs_dir.glob("*/results.json")):
            builder.collect_results(results_path, SOURCE_RUN_HISTORY)
    builder.collect_derived_index(derived_index_path)
    if input_file is not None:
        builder.collect_input_file(input_file)

    changed_event_ids = _changed_event_ids(latest_changes_path) if queue_mode == "strict-daily" else set()
    queue = builder.build_queue(
        skip_already_enriched=skip_already_enriched,
        queue_mode=queue_mode,
        changed_event_ids=changed_event_ids,
    )
    queue.update(
        {
            "schema_version": FIGHTER_PROFILE_QUEUE_SCHEMA_VERSION,
            "created_at": utc_now(),
            "queue_mode": queue_mode,
            "sources": builder.source_summary(),
            "total_candidates_seen": builder.total_candidates_seen,
            "unique_profile_urls": len(queue["queue"]),
            "already_enriched_count": queue["already_enriched_count"],
            "missing_url_count": builder.missing_url_count,
            "duplicate_url_count": max(0, builder.valid_url_candidates_seen - len(builder.entries)),
            "refreshable_count": queue["refreshable_count"],
            "fresh_count": queue["fresh_count"],
            "warnings": sorted(set(builder.warnings)),
        }
    )
    report = build_fighter_profile_queue_report(queue, builder=builder)
    atomic_write_json(output_path, queue)
    atomic_write_json(report_path, report)
    return queue, report, {"queue": output_path, "report": report_path}


def build_fighter_profile_queue_report(
    queue_document: dict[str, Any],
    *,
    builder: "_QueueBuilder | None" = None,
    selected_for_enrichment: int | None = None,
) -> dict[str, Any]:
    sources = queue_document.get("sources") or {}
    queue = [item for item in queue_document.get("queue", []) if isinstance(item, dict)]
    top_source_types = Counter(
        source
        for item in queue
        for source in item.get("seen_in", [])
        if source
    )
    if builder is not None:
        duplicate_url_count = max(0, builder.valid_url_candidates_seen - len(builder.entries))
        source_unique_counts = {
            SOURCE_LATEST_RESULTS: len(builder.source_url_sets.get(SOURCE_LATEST_RESULTS, set())),
            SOURCE_LATEST_UPCOMING: len(builder.source_url_sets.get(SOURCE_LATEST_UPCOMING, set())),
            SOURCE_RUN_HISTORY: len(builder.source_url_sets.get(SOURCE_RUN_HISTORY, set())),
            SOURCE_DERIVED_INDEX: len(builder.source_url_sets.get(SOURCE_DERIVED_INDEX, set())),
            SOURCE_INPUT_FILE: len(builder.source_url_sets.get(SOURCE_INPUT_FILE, set())),
        }
    else:
        duplicate_url_count = int(queue_document.get("duplicate_url_count") or 0)
        source_unique_counts = {
            SOURCE_LATEST_RESULTS: int((sources.get(SOURCE_LATEST_RESULTS) or {}).get("unique_profile_urls") or 0),
            SOURCE_LATEST_UPCOMING: int((sources.get(SOURCE_LATEST_UPCOMING) or {}).get("unique_profile_urls") or 0),
            SOURCE_RUN_HISTORY: int((sources.get(SOURCE_RUN_HISTORY) or {}).get("unique_profile_urls") or 0),
            SOURCE_DERIVED_INDEX: int((sources.get(SOURCE_DERIVED_INDEX) or {}).get("unique_profile_urls") or 0),
            SOURCE_INPUT_FILE: int((sources.get(SOURCE_INPUT_FILE) or {}).get("unique_profile_urls") or 0),
        }
    return {
        "schema_version": FIGHTER_PROFILE_QUEUE_REPORT_SCHEMA_VERSION,
        "created_at": queue_document.get("created_at") or utc_now(),
        "generated_at": utc_now(),
        "queue_mode": queue_document.get("queue_mode") or "default",
        "unique_urls_from_latest_results": source_unique_counts[SOURCE_LATEST_RESULTS],
        "unique_urls_from_latest_upcoming": source_unique_counts[SOURCE_LATEST_UPCOMING],
        "unique_urls_from_run_history": source_unique_counts[SOURCE_RUN_HISTORY],
        "unique_urls_from_derived_index": source_unique_counts[SOURCE_DERIVED_INDEX],
        "unique_urls_from_input_file": source_unique_counts[SOURCE_INPUT_FILE],
        "total_unique_queue_urls": len(queue),
        "already_enriched_count": int(queue_document.get("already_enriched_count") or 0),
        "refreshable_count": int(queue_document.get("refreshable_count") or 0),
        "fresh_count": int(queue_document.get("fresh_count") or 0),
        "mode_filtered_count": int(queue_document.get("mode_filtered_count") or 0),
        "missing_url_count": int(queue_document.get("missing_url_count") or 0),
        "duplicate_url_count": duplicate_url_count,
        "count_by_stale_reason": dict(
            sorted(
                Counter(str(item.get("stale_reason") or "unknown") for item in queue).items()
            )
        ),
        "top_source_types": [
            {"source": source, "count": count}
            for source, count in top_source_types.most_common()
        ],
        "number_selected_for_current_enrichment_run": selected_for_enrichment,
        "sources": deepcopy(sources),
        "warnings": list(queue_document.get("warnings") or []),
    }


def collect_fighter_profile_urls_from_queue(
    queue_path: Path,
    *,
    limit: int,
    skip_already_enriched: bool = False,
) -> tuple[list[str], dict[str, Any]]:
    if limit <= 0 or not queue_path.exists():
        return [], {
            "mode": "queue",
            "queue_file": str(queue_path),
            "available_queue_urls": 0,
            "selected_for_enrichment": 0,
            "skip_already_enriched": skip_already_enriched,
        }
    document = json.loads(queue_path.read_text(encoding="utf-8"))
    queue=document.get('queue',[])
    existing={};generated=None
    if document.get('schema_version')=='mmaf_source_enrichment_v1':
        queue=[item for item in document.get('items',[]) if isinstance(item,dict) and item.get('action')=='refresh_source_evidence']
        generated=_attempt_time(document.get('generated_at'))
        if generated:existing=_existing_profiles(LATEST_DIR/'fighter_profiles.json')['by_id']
    urls: list[str] = []
    skipped_already_enriched = 0
    skipped_fresh = 0
    skipped_attempted=0;seen=set()
    for item in queue:
        if not isinstance(item, dict):
            continue
        if skip_already_enriched and item.get("already_enriched"):
            skipped_already_enriched += 1
            continue
        if item.get("should_refresh") is False:
            skipped_fresh += 1
            continue
        url = normalize_tapology_fighter_url(item.get("source_url"))
        if not url:
            continue
        source=source_fighter_id_from_url(url)
        attempt=existing.get(source,{})
        attempted=_attempt_time((attempt.get('last_attempt') or {}).get('at') or attempt.get('scraped_at'))
        if generated and attempted and attempted>=generated:
            skipped_attempted+=1;continue
        if source in seen:continue
        seen.add(source)
        urls.append(url)
        if len(urls) >= limit:
            break
    metadata = {
        "mode": "queue",
        "queue_file": str(queue_path),
        "queue_schema_version": document.get("schema_version"),
        "available_queue_urls": len([item for item in queue if isinstance(item, dict)]),
        "selected_for_enrichment": len(urls),
        "skip_already_enriched": skip_already_enriched,
        "skipped_already_enriched": skipped_already_enriched,
        "skipped_fresh": skipped_fresh,
        "skipped_attempted_since_queue": skipped_attempted,
    }
    return urls, metadata


def normalize_tapology_fighter_url(url: str | None) -> str | None:
    url = canonical_url(url)
    if not url:
        return None
    if not re.match(r"^https?://(?:www\.)?tapology\.com/fightcenter/fighters/[^/?#]+$", url, re.I):
        return None
    return 'https://www.tapology.com'+urlsplit(url).path.lower()


class _QueueBuilder:
    def __init__(self, *, existing_profiles_path: Path):
        self.existing_profiles = _existing_profiles(existing_profiles_path)
        self.existing_profiles_by_url = {
            url: profile for url, profile in self.existing_profiles["by_url"].items()
        }
        self.existing_profiles_by_id = {
            source_fighter_id: profile for source_fighter_id, profile in self.existing_profiles["by_id"].items()
        }
        self.entries: dict[str, dict[str, Any]] = {}
        self.total_candidates_seen = 0
        self.valid_url_candidates_seen = 0
        self.missing_url_count = 0
        self.source_counts: Counter[str] = Counter()
        self.source_missing_counts: Counter[str] = Counter()
        self.source_url_sets: dict[str, set[str]] = {
            SOURCE_LATEST_RESULTS: set(),
            SOURCE_LATEST_UPCOMING: set(),
            SOURCE_RUN_HISTORY: set(),
            SOURCE_DERIVED_INDEX: set(),
            SOURCE_INPUT_FILE: set(),
        }
        self.warnings: list[str] = []

    def collect_results(self, path: Path, source_type: str, *, kind: str = "results") -> None:
        payload = _read_json(path)
        if not payload:
            if source_type == SOURCE_LATEST_RESULTS:
                self.warnings.append(f"missing_or_invalid_source:{path}")
            return
        latest_seen_at = payload.get("scraped_at") or ((payload.get("run") or {}).get("finished_at"))
        for event in payload.get("events", []):
            event_seen_at = event.get("event_date") or latest_seen_at
            event_internal_id = event.get("internal_event_id")
            event_source_id = event.get("source_event_id")
            for bout in event.get("bouts", []):
                for fighter_key in ("fighter_a", "fighter_b"):
                    fighter = bout.get(fighter_key) or {}
                    self.add_candidate(
                        source_type=source_type,
                        source_url=fighter.get("url"),
                        display_name=fighter.get("name"),
                        source_fighter_id=fighter.get("source_fighter_id"),
                        source_fighter_numeric_id=fighter.get("source_fighter_numeric_id"),
                        latest_seen_at=event_seen_at,
                        upcoming_event_date=event_seen_at if kind == "upcoming" else None,
                        event_internal_id=event_internal_id,
                        event_source_id=event_source_id,
                    )
                    url=normalize_tapology_fighter_url(fighter.get("url"))
                    entry=self.entries.get(url)
                    result_type=bout.get("result_type")
                    result=("win" if bout.get("winner")==fighter_key else "loss") if result_type=="win_loss" else {"draw":"draw","no_contest":"no_contest"}.get(result_type)
                    if entry is not None and result and kind!="upcoming":
                        known={"source_bout_id":bout.get("source_bout_id"),"fight_date":event.get("event_date"),"result":result}
                        if known not in entry.setdefault("known_results",[]):entry["known_results"].append(known)
                        self._apply_existing_profile_metadata(entry)

    def collect_derived_index(self, path: Path) -> None:
        payload = _read_json(path)
        if not payload:
            return
        generated_at = payload.get("generated_at")
        for fighter_key, fighter in (payload.get("fighters") or {}).items():
            if not isinstance(fighter, dict):
                continue
            self.add_candidate(
                source_type=SOURCE_DERIVED_INDEX,
                source_url=fighter.get("fighter_source_url"),
                display_name=fighter.get("display_name"),
                source_fighter_id=fighter_key if str(fighter_key).startswith("tapology_fighter_") else None,
                source_fighter_numeric_id=fighter.get("fighter_source_id"),
                latest_seen_at=fighter.get("last_fight_date_in_dataset") or generated_at,
            )

    def collect_input_file(self, path: Path) -> None:
        if not path.exists():
            self.warnings.append(f"missing_input_file:{path}")
            return
        if path.suffix.lower() == ".json":
            payload = _read_json(path)
            for item in _input_json_items(payload):
                if isinstance(item, str):
                    self.add_candidate(source_type=SOURCE_INPUT_FILE, source_url=item)
                elif isinstance(item, dict):
                    self.add_candidate(
                        source_type=SOURCE_INPUT_FILE,
                        source_url=item.get("source_url") or item.get("url") or item.get("fighter_source_url") or item.get("fighter_url"),
                        display_name=item.get("display_name") or item.get("name"),
                        source_fighter_id=item.get("source_fighter_id") or item.get("fighter_key"),
                        source_fighter_numeric_id=item.get("source_fighter_numeric_id") or item.get("fighter_source_id"),
                        latest_seen_at=item.get("latest_seen_at") or item.get("last_seen_at"),
                    )
            return
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            self.add_candidate(source_type=SOURCE_INPUT_FILE, source_url=line)

    def add_candidate(
        self,
        *,
        source_type: str,
        source_url: str | None,
        display_name: str | None = None,
        source_fighter_id: str | None = None,
        source_fighter_numeric_id: str | None = None,
        latest_seen_at: str | None = None,
        upcoming_event_date: str | None = None,
        event_internal_id: str | None = None,
        event_source_id: str | None = None,
    ) -> None:
        self.total_candidates_seen += 1
        self.source_counts[source_type] += 1
        normalized_url = normalize_tapology_fighter_url(source_url)
        if not normalized_url:
            self.missing_url_count += 1
            self.source_missing_counts[source_type] += 1
            return
        self.valid_url_candidates_seen += 1
        self.source_url_sets.setdefault(source_type, set()).add(normalized_url)
        source_fighter_id = source_fighter_id or source_fighter_id_from_url(normalized_url)
        source_fighter_numeric_id = source_fighter_numeric_id or _numeric_source_fighter_id(source_fighter_id)
        entry = self.entries.setdefault(
            normalized_url,
            {
                "source_fighter_id": source_fighter_id,
                "source_fighter_numeric_id": source_fighter_numeric_id,
                "source_url": normalized_url,
                "display_name": display_name,
                "seen_in": [],
                "seen_event_internal_ids": [],
                "seen_event_source_ids": [],
                "latest_seen_at": latest_seen_at,
                "latest_upcoming_at": upcoming_event_date,
                "already_enriched": False,
                "existing_profile_status": None,
                "last_scraped_at": None,
                "refresh_ttl_days": None,
                "should_refresh": True,
                "is_stale": True,
                "stale_reason": "never_scraped",
                "priority": 0,
                "warnings": [],
            },
        )
        if source_type not in entry["seen_in"]:
            entry["seen_in"].append(source_type)
        if event_internal_id and event_internal_id not in entry["seen_event_internal_ids"]:
            entry["seen_event_internal_ids"].append(event_internal_id)
        if event_source_id and event_source_id not in entry["seen_event_source_ids"]:
            entry["seen_event_source_ids"].append(event_source_id)
        if not entry.get("display_name") and display_name:
            entry["display_name"] = display_name
        if not entry.get("source_fighter_id") and source_fighter_id:
            entry["source_fighter_id"] = source_fighter_id
        if not entry.get("source_fighter_numeric_id") and source_fighter_numeric_id:
            entry["source_fighter_numeric_id"] = source_fighter_numeric_id
        if latest_seen_at and (not entry.get("latest_seen_at") or str(latest_seen_at) > str(entry["latest_seen_at"])):
            entry["latest_seen_at"] = latest_seen_at
        if upcoming_event_date and (not entry.get("latest_upcoming_at") or str(upcoming_event_date) > str(entry["latest_upcoming_at"])):
            entry["latest_upcoming_at"] = upcoming_event_date
        self._apply_existing_profile_metadata(entry)

    def build_queue(
        self,
        *,
        skip_already_enriched: bool,
        queue_mode: str,
        changed_event_ids: set[str],
    ) -> dict[str, Any]:
        entries = []
        already_enriched_count = 0
        refreshable_count = 0
        fresh_count = 0
        mode_filtered_count = 0
        for entry in self.entries.values():
            item = deepcopy(entry)
            item["seen_in"] = sorted(item["seen_in"])
            item["seen_event_internal_ids"] = sorted(item.get("seen_event_internal_ids") or [])
            item["seen_event_source_ids"] = sorted(item.get("seen_event_source_ids") or [])
            if not item.get("display_name"):
                item["warnings"].append("missing_display_name")
            if item.get("already_enriched"):
                already_enriched_count += 1
                if skip_already_enriched:
                    continue
            if item.get("should_refresh") is False:
                fresh_count += 1
            else:
                refreshable_count += 1
            if not _entry_in_queue_mode(item, queue_mode=queue_mode, changed_event_ids=changed_event_ids):
                mode_filtered_count += 1
                continue
            entries.append(item)
        entries.sort(
            key=lambda item: (
                item.get("should_refresh") is False,
                -int(item.get("priority") or 0),
                bool(item.get("already_enriched")),
                str(item.get("latest_seen_at") or ""),
                str(item.get("display_name") or "").lower(),
                str(item.get("source_url") or "").lower(),
            )
        )
        return {
            "already_enriched_count": already_enriched_count,
            "refreshable_count": refreshable_count,
            "fresh_count": fresh_count,
            "mode_filtered_count": mode_filtered_count,
            "queue": entries,
        }

    def source_summary(self) -> dict[str, Any]:
        summary = {}
        for source_type in [SOURCE_LATEST_RESULTS, SOURCE_LATEST_UPCOMING, SOURCE_RUN_HISTORY, SOURCE_DERIVED_INDEX, SOURCE_INPUT_FILE]:
            summary[source_type] = {
                "candidates_seen": int(self.source_counts.get(source_type, 0)),
                "unique_profile_urls": len(self.source_url_sets.get(source_type, set())),
                "missing_url_count": int(self.source_missing_counts.get(source_type, 0)),
            }
        return summary

    def _apply_existing_profile_metadata(self, entry: dict[str, Any]) -> None:
        existing = None
        source_url = entry.get("source_url")
        source_fighter_id = entry.get("source_fighter_id")
        if source_url:
            existing = self.existing_profiles_by_url.get(source_url)
        if existing is None and source_fighter_id:
            existing = self.existing_profiles_by_id.get(source_fighter_id)

        if existing is not None:
            entry["already_enriched"] = existing.get("status") == "success"
            entry["existing_profile_status"] = existing.get("status")
            entry["last_scraped_at"] = existing.get("scraped_at")
            if existing.get("status") != "success":
                entry["priority"] = max(int(entry.get("priority") or 0), 90)
                entry["is_stale"] = True
                entry["should_refresh"] = True
                entry["stale_reason"] = "retry_failed_profile"
                entry["refresh_ttl_days"] = 0
                return
        else:
            entry["priority"] = max(int(entry.get("priority") or 0), 100)
            entry["is_stale"] = True
            entry["should_refresh"] = True
            entry["stale_reason"] = "never_scraped"
            entry["refresh_ttl_days"] = 0
            return

        latest_seen_date = _parse_iso_date(entry.get("latest_seen_at"))
        last_scraped_date = _parse_iso_date(existing.get("scraped_at"))
        warnings = existing.get("warnings") if isinstance(existing.get("warnings"), list) else []
        warning_retry = bool(warnings)
        ttl_days, stale_reason, priority = _refresh_policy(
            latest_seen_date=latest_seen_date,
            upcoming_event_date=_parse_iso_date(entry.get("latest_upcoming_at")),
            last_scraped_date=last_scraped_date,
            warning_retry=warning_retry,
        )
        entry["refresh_ttl_days"] = ttl_days
        entry["priority"] = max(int(entry.get("priority") or 0), priority)
        entry["stale_reason"] = stale_reason
        if last_scraped_date is None or ttl_days == 0:
            entry["is_stale"] = True
            entry["should_refresh"] = True
            return
        age_days = max(0, (_utc_today() - last_scraped_date).days)
        entry["days_since_scraped"] = age_days
        entry["is_stale"] = age_days >= ttl_days
        entry["should_refresh"] = entry["is_stale"]
        from .scoring_evidence import needs_refresh
        if not entry["is_stale"] and needs_refresh(existing,now=_utc_now(),known_results=entry.get("known_results")):
            entry.update(is_stale=True,should_refresh=True,stale_reason="career_or_period_evidence_refresh_required",refresh_ttl_days=7)
            entry["priority"]=max(int(entry.get("priority") or 0),90)
        if not entry["is_stale"]:
            entry["priority"] = 0
            entry["stale_reason"] = "fresh"


def _entry_in_queue_mode(item: dict[str, Any], *, queue_mode: str, changed_event_ids: set[str]) -> bool:
    if queue_mode in {"default", "full-maintenance"}:
        return True
    if queue_mode == "strict-daily":
        if item.get("should_refresh") is False:
            return False
        seen_ids = set(item.get("seen_event_internal_ids") or []) | set(item.get("seen_event_source_ids") or [])
        return bool(seen_ids & changed_event_ids)
    if queue_mode == "recovery":
        if item.get("should_refresh") is False:
            return False
        if item.get("existing_profile_status") and item.get("existing_profile_status") != "success":
            return True
        return item.get("stale_reason") in {
            "retry_failed_profile",
            "profile_warning_retry",
            "missing_last_scraped_at",
        }
    raise ValueError(f"unknown_queue_mode:{queue_mode}")


def _changed_event_ids(path: Path) -> set[str]:
    payload = _read_json(path)
    ids: set[str] = set()
    for key in ("new_events", "updated_events", "incomplete_events", "manual_review_events"):
        value = payload.get(key)
        if isinstance(value, list):
            ids.update(str(item) for item in value if item)
    return ids


def _existing_profiles(path: Path) -> dict[str, dict[str, dict[str, Any]]]:
    payload = _read_json(path)
    by_url: dict[str, dict[str, Any]] = {}
    by_id: dict[str, dict[str, Any]] = {}
    for profile in payload.get("profiles", []) if isinstance(payload, dict) else []:
        if not isinstance(profile, dict):
            continue
        url = normalize_tapology_fighter_url(profile.get("source_url"))
        source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(url)
        normalized = {
            "source_url": url,
            "source_fighter_id": source_fighter_id,
            "status": profile.get("status"),
            "scraped_at": profile.get("scraped_at"),
            "warnings": list(profile.get("warnings") or []),
            "last_attempt": profile.get('last_attempt') or {},
            "record_summary": profile.get('record_summary') or {},
            "career_record_evidence": profile.get('career_record_evidence') or {},
            "period_evidence": profile.get('period_evidence') or {},
            "fight_history": profile.get('fight_history') or [],
        }
        if url:
            by_url[url] = normalized
        if source_fighter_id:
            by_id[str(source_fighter_id)] = normalized
    return {"by_url": by_url, "by_id": by_id}

def _attempt_time(value):
    if not isinstance(value,str):return None
    try:
        parsed=datetime.fromisoformat(value.replace('Z','+00:00'))
        return parsed.replace(tzinfo=timezone.utc) if parsed.tzinfo is None else parsed.astimezone(timezone.utc)
    except ValueError:return None


def _input_json_items(payload: Any) -> list[Any]:
    if isinstance(payload, list):
        return payload
    if not isinstance(payload, dict):
        return []
    for key in ["queue", "profiles", "fighters", "urls", "fighter_urls"]:
        value = payload.get(key)
        if isinstance(value, list):
            return value
        if isinstance(value, dict):
            return list(value.values())
    return [payload]


def _numeric_source_fighter_id(source_fighter_id: str | None) -> str | None:
    if not source_fighter_id:
        return None
    match = re.fullmatch(r"tapology_fighter_(\d+)", str(source_fighter_id))
    return match.group(1) if match else None


def _read_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def _refresh_policy(
    *,
    latest_seen_date: date | None,
    upcoming_event_date: date | None,
    last_scraped_date: date | None,
    warning_retry: bool,
) -> tuple[int, str, int]:
    if last_scraped_date is None:
        return 0, "missing_last_scraped_at", 85
    if upcoming_event_date is not None:
        days_until_upcoming = (upcoming_event_date - _utc_today()).days
        if days_until_upcoming >= 0:
            return 3, "upcoming_event_next_30_days", 75
        if days_until_upcoming >= -7:
            return 3, "upcoming_or_recent_event_last_7_days", 65
    if latest_seen_date is None:
        return 7, "dataset_background_refresh", 20
    if latest_seen_date > last_scraped_date:
        return 0, "newer_dataset_fight_after_profile_scrape", 95
    if warning_retry:
        return 3, "profile_warning_retry", 80
    recency_days = max(0, (_utc_today() - latest_seen_date).days)
    if recency_days <= 1:
        return 1, "recent_event_last_1_day", 70
    if recency_days <= 7:
        return 3, "recent_event_last_7_days", 60
    if recency_days <= 30:
        return 7, "active_dataset_last_30_days", 40
    return 7, "inactive_dataset_over_30_days", 10


def _parse_iso_date(value: Any) -> date | None:
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None
    try:
        if "T" in text:
            return datetime.fromisoformat(text.replace("Z", "+00:00")).date()
        return date.fromisoformat(text[:10])
    except ValueError:
        return None


def _utc_today() -> date:
    return datetime.now(timezone.utc).date()


def _utc_now():
    return datetime.now(timezone.utc)
