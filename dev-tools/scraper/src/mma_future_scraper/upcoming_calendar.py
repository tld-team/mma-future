from __future__ import annotations

import hashlib
import json
import os
import shutil
import tempfile
import zipfile
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from dateutil import parser as date_parser

from .config import DATA_DIR, ScraperConfig
from .storage import atomic_write_json
from .tapology import TapologyScraper
from .utils import stable_hash, utc_now


SCHEMA_VERSION = "mmaf_upcoming_v1"
RUN_MANIFEST_VERSION = "mmaf_upcoming_run_v1"
DEFAULT_TIMEZONE = "Europe/Belgrade"
MAX_DAYS = 30
TIMEZONE_ALIASES = {
    "ET": "America/New_York",
    "EST": "America/New_York",
    "EDT": "America/New_York",
    "CT": "America/Chicago",
    "CST": "America/Chicago",
    "CDT": "America/Chicago",
    "MT": "America/Denver",
    "MST": "America/Denver",
    "MDT": "America/Denver",
    "PT": "America/Los_Angeles",
    "PST": "America/Los_Angeles",
    "PDT": "America/Los_Angeles",
    "GMT": "UTC",
    "UTC": "UTC",
}


def upcoming_data_dir(base: Path | None = None) -> Path:
    return Path(base or DATA_DIR / "upcoming").expanduser().resolve()


def scrape_upcoming(
    *,
    days: int = 10,
    timezone_name: str = DEFAULT_TIMEZONE,
    data_dir: Path | None = None,
    config_overrides: dict[str, Any] | None = None,
    today: date | None = None,
) -> dict[str, Any]:
    days = validate_days(days)
    zone = ZoneInfo(timezone_name)
    start = today or datetime.now(zone).date()
    end = start + timedelta(days=days - 1)
    target = upcoming_data_dir(data_dir)
    overrides = dict(config_overrides or {})
    overrides.setdefault("scrape_matchup_pages", False)
    config = ScraperConfig(
        data_dir=target,
        from_date=start.isoformat(),
        until_date=end.isoformat(),
        promotion_scope="all",
        sport="mma",
        force_full_scan=True,
        enrich_fighter_profiles=False,
        **overrides,
    )
    summary = TapologyScraper(config).run(results=False, upcoming=True)
    if not summary.get("successful"):
        raise RuntimeError("Upcoming scrape did not complete; the last successful export was preserved.")

    raw_path = target / "latest" / "upcoming.json"
    if not raw_path.is_file():
        raise RuntimeError("Upcoming scraper completed without a latest/upcoming.json document.")
    raw = json.loads(raw_path.read_text(encoding="utf-8"))
    document = build_document(raw, summary, start=start, end=end, timezone_name=timezone_name)
    validate_document(document)
    manifest = build_run_manifest(document, summary)
    run_dir = target / "runs" / str(document["run_id"])
    atomic_write_json(run_dir / "upcoming_calendar.json", document)
    atomic_write_json(run_dir / "upcoming_run_manifest.json", manifest)
    if not document["coverage"]["complete"]:
        raise RuntimeError(
            "Upcoming scrape was incomplete; diagnostics were saved to the run directory "
            "and the last successful export was preserved."
        )
    atomic_write_json(target / "latest" / "upcoming_calendar.json", document)
    atomic_write_json(target / "latest" / "upcoming_run_manifest.json", manifest)
    return {
        "successful": True,
        "run_id": document["run_id"],
        "events": len(document["events"]),
        "bouts": sum(len(event["bouts"]) for event in document["events"]),
        "coverage": document["coverage"],
        "output": str(target / "latest" / "upcoming_calendar.json"),
    }


def build_document(
    raw: dict[str, Any],
    summary: dict[str, Any],
    *,
    start: date,
    end: date,
    timezone_name: str,
) -> dict[str, Any]:
    events: list[dict[str, Any]] = []
    incomplete = 0
    for raw_event in raw.get("events", []):
        event = normalize_event(raw_event)
        event_date = parse_date(event.get("date"))
        if not event_date or event_date < start or event_date > end:
            continue
        if not event["full_card_read"]:
            incomplete += 1
        if event["bouts"]:
            events.append(event)

    pagination_complete = bool(
        summary.get("date_window_stop_confirmed")
        or summary.get("pagination_stop_reason") in {
            None,
            "load_more_no_new_events",
            "natural_end_no_load_more_button",
        }
    ) and summary.get("pagination_stop_reason") not in {"max_pages", "max_events"}
    event_failures = int(summary.get("event_fetch_failures") or 0)
    complete = pagination_complete and event_failures == 0 and incomplete == 0
    return {
        "schema_version": SCHEMA_VERSION,
        "source": "tapology",
        "run_id": str(raw.get("run", {}).get("run_id") or summary.get("run_id") or stable_hash(utc_now(), 16)),
        "collected_at": raw.get("scraped_at") or summary.get("finished_at") or utc_now(),
        "coverage": {
            "from": start.isoformat(),
            "to": end.isoformat(),
            "timezone": timezone_name,
            "requested_days": (end - start).days + 1,
            "pagination_complete": pagination_complete,
            "events_complete": incomplete == 0 and event_failures == 0,
            "complete": complete,
            "incomplete_events": incomplete,
            "event_fetch_failures": event_failures,
        },
        "events": events,
        "counts": {
            "events": len(events),
            "bouts": sum(len(event["bouts"]) for event in events),
        },
    }


def normalize_event(raw: dict[str, Any]) -> dict[str, Any]:
    source_event_id = str(raw.get("source_event_id") or "").strip()
    source_url = str(raw.get("event_url") or "").strip()
    stable_id = source_event_id or "local_" + stable_hash(source_url or json.dumps(raw, sort_keys=True), 24)
    event_date = str(raw.get("event_date") or "").strip()
    start_utc, time_note = event_start_utc(event_date, raw.get("event_time_raw"), raw.get("event_timezone_raw"))
    bouts = [normalize_bout(item, stable_id, index) for index, item in enumerate(raw.get("bouts", []), start=1)]
    bouts = [item for item in bouts if item is not None]
    warnings = [str(item) for item in raw.get("warnings", [])]
    coverage = raw.get("event_coverage") if isinstance(raw.get("event_coverage"), dict) else {}
    full_card = coverage.get("status") == "complete" or not any(
        token in warning
        for warning in warnings
        for token in ("fight_card_missing", "unknown_professional_status", "event_failed")
    )
    return {
        "stable_id": stable_id,
        "source_event_id": source_event_id or None,
        "source_url": source_url or None,
        "title": str(raw.get("event_name") or "").strip(),
        "promotion": str(raw.get("promotion_name") or "").strip() or None,
        "date": event_date or None,
        "start_utc": start_utc,
        "source_time_text": str(raw.get("event_time_raw") or "").strip() or None,
        "source_timezone": str(raw.get("event_timezone_raw") or raw.get("timezone_raw") or "").strip() or None,
        "time_note": time_note,
        "venue": str(raw.get("venue") or "").strip() or None,
        "location": str(raw.get("location") or "").strip() or None,
        "status": normalize_status(raw.get("source_event_status")),
        "source_observed_at": raw.get("scraped_at") or utc_now(),
        "full_card_read": bool(full_card),
        "bouts": bouts,
    }


def normalize_bout(raw: dict[str, Any], event_stable_id: str, order: int) -> dict[str, Any] | None:
    flags = raw.get("flags") if isinstance(raw.get("flags"), dict) else {}
    is_professional = raw.get("pro_am") == "professional" or flags.get("is_professional") is True
    if not is_professional:
        return None
    fighter_a = raw.get("fighter_a") if isinstance(raw.get("fighter_a"), dict) else {}
    fighter_b = raw.get("fighter_b") if isinstance(raw.get("fighter_b"), dict) else {}
    if not fighter_a.get("name") or not fighter_b.get("name"):
        return None
    source_bout_id = str(raw.get("source_bout_id") or "").strip()
    fingerprint = "|".join(
        [event_stable_id, str(fighter_a.get("source_fighter_id") or fighter_a.get("name")), str(fighter_b.get("source_fighter_id") or fighter_b.get("name"))]
    )
    return {
        "stable_id": source_bout_id or "local_" + stable_hash(fingerprint, 24),
        "source_bout_id": source_bout_id or None,
        "source_url": str(raw.get("bout_url") or "").strip() or None,
        "order": order,
        "weight_class": str(raw.get("weight_class") or "").strip() or None,
        "status": "cancelled" if flags.get("is_cancelled") else normalize_status(raw.get("status")),
        "scope_status": "confirmed",
        "scope_evidence": "shared_professional_mma_classifier",
        "fighter_a": normalize_fighter(fighter_a),
        "fighter_b": normalize_fighter(fighter_b),
    }


def normalize_fighter(raw: dict[str, Any]) -> dict[str, Any]:
    return {
        "name": str(raw.get("name") or "").strip(),
        "source_fighter_id": str(raw.get("source_fighter_id") or "").strip() or None,
        "source_url": str(raw.get("url") or "").strip() or None,
    }


def normalize_status(value: Any) -> str:
    status = str(value or "scheduled").strip().lower()
    if "cancel" in status:
        return "cancelled"
    if "postpon" in status:
        return "postponed"
    return "scheduled"


def event_start_utc(event_date: str, time_text: Any, timezone_text: Any) -> tuple[str | None, str | None]:
    if not event_date or not time_text:
        return None, "Vreme početka nije potvrđeno."
    zone_name = TIMEZONE_ALIASES.get(str(timezone_text or "").strip().upper())
    if not zone_name:
        return None, "Izvorna vremenska zona nije dovoljno precizna za preračunavanje."
    try:
        parsed_time = date_parser.parse(str(time_text), fuzzy=True).time().replace(tzinfo=None)
        local = datetime.combine(date.fromisoformat(event_date), parsed_time, ZoneInfo(zone_name))
        return local.astimezone(timezone.utc).isoformat().replace("+00:00", "Z"), None
    except (ValueError, TypeError):
        return None, "Vreme početka nije moguće pouzdano preračunati."


def validate_days(days: int) -> int:
    value = int(days)
    if value < 1 or value > MAX_DAYS:
        raise ValueError(f"--days must be between 1 and {MAX_DAYS}")
    return value


def validate_document(document: dict[str, Any]) -> None:
    if document.get("schema_version") != SCHEMA_VERSION:
        raise ValueError("Unsupported upcoming schema version")
    coverage = document.get("coverage") or {}
    start = parse_date(coverage.get("from"))
    end = parse_date(coverage.get("to"))
    if not start or not end or end < start or (end - start).days + 1 > MAX_DAYS:
        raise ValueError("Invalid upcoming coverage window")
    ZoneInfo(str(coverage.get("timezone") or DEFAULT_TIMEZONE))
    seen_events: set[str] = set()
    seen_bouts: set[str] = set()
    for event in document.get("events", []):
        stable_id = str(event.get("stable_id") or "")
        if not stable_id or stable_id in seen_events:
            raise ValueError("Upcoming event IDs must be present and unique")
        seen_events.add(stable_id)
        if not event.get("title") or not parse_date(event.get("date")):
            raise ValueError(f"Upcoming event {stable_id} is missing title/date")
        for bout in event.get("bouts", []):
            bout_id = str(bout.get("stable_id") or "")
            if not bout_id or bout_id in seen_bouts:
                raise ValueError("Upcoming bout IDs must be present and unique")
            seen_bouts.add(bout_id)
            if bout.get("scope_status") != "confirmed" or not bout.get("fighter_a", {}).get("name") or not bout.get("fighter_b", {}).get("name"):
                raise ValueError(f"Upcoming bout {bout_id} lacks confirmed professional MMA participants")


def build_run_manifest(document: dict[str, Any], summary: dict[str, Any]) -> dict[str, Any]:
    return {
        "schema_version": RUN_MANIFEST_VERSION,
        "run_id": document["run_id"],
        "status": "success" if document["coverage"]["complete"] else "incomplete",
        "collected_at": document["collected_at"],
        "coverage": document["coverage"],
        "counts": document["counts"],
        "diagnostics": {
            "pages_scanned": int(summary.get("pages_scanned") or 0),
            "pagination_stop_reason": summary.get("pagination_stop_reason"),
            "date_window_stop_confirmed": bool(summary.get("date_window_stop_confirmed")),
            "event_fetch_failures": int(summary.get("event_fetch_failures") or 0),
            "warnings_count": int(summary.get("warnings_count") or 0),
        },
    }


def export_upcoming_bundle(output: Path, *, data_dir: Path | None = None) -> dict[str, Any]:
    output = Path(output).expanduser().resolve()
    if output.suffix.lower() != ".zip":
        raise ValueError("Output must end in .zip")
    if output.exists():
        raise FileExistsError(f"Output already exists: {output}")
    latest = upcoming_data_dir(data_dir) / "latest"
    document_path = latest / "upcoming_calendar.json"
    run_path = latest / "upcoming_run_manifest.json"
    if not document_path.is_file() or not run_path.is_file():
        raise ValueError("Run scrape-upcoming successfully before exporting")
    document = json.loads(document_path.read_text(encoding="utf-8"))
    validate_document(document)
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="mmaf-upcoming-bundle-", dir=output.parent) as temporary:
        work = Path(temporary)
        snapshot = work / "latest"
        snapshot.mkdir()
        shutil.copyfile(document_path, snapshot / "upcoming.json")
        shutil.copyfile(run_path, snapshot / "run_manifest.json")
        files = {
            name: {"bytes": (snapshot / name).stat().st_size, "sha256": sha256(snapshot / name)}
            for name in ("upcoming.json", "run_manifest.json")
        }
        manifest = {
            "format": "mmaf-upcoming-bundle",
            "format_version": 1,
            "mode": "upcoming",
            "contract": SCHEMA_VERSION,
            "exported_at": utc_now(),
            "run_id": document["run_id"],
            "coverage": document["coverage"],
            "counts": document["counts"],
            "files": files,
        }
        atomic_write_json(snapshot / "package_manifest.json", manifest)
        archive = work / "bundle.zip"
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as bundle:
            for path in sorted(snapshot.iterdir()):
                bundle.write(path, "latest/" + path.name)
        os.link(archive, output)
    return {
        "ready_for_import": True,
        "mode": "upcoming",
        "output": str(output),
        "bytes": output.stat().st_size,
        "sha256": sha256(output),
        "counts": document["counts"],
        "coverage": document["coverage"],
    }


def parse_date(value: Any) -> date | None:
    try:
        return date.fromisoformat(str(value))
    except (TypeError, ValueError):
        return None


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()
