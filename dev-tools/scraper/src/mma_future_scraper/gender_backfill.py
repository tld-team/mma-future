"""Gender evidence for profiles cached before the parser read it. No network activity.

`backfill_gender_evidence` reads it from page captures already saved on disk.
`build_gender_evidence_queue` lists the profiles that still need a fresh fetch.
"""
from __future__ import annotations

import json
import shutil
from collections import Counter
from pathlib import Path
from typing import Any

from .fighter_profile_queue import (
    load_tracking_decisions,
    normalize_tapology_fighter_url,
    tracking_allows_profile,
    tracking_decision_for,
)
from .fighter_profiles import _extract_gender_evidence
from .storage import atomic_write_json
from .utils import normalize_space, source_fighter_id_from_url, utc_now

QUEUE_SCHEMA_VERSION = "fighter_profile_queue_v0_1"
STALE_REASON = "gender_evidence_missing"
# How little the cached weight class says about gender decides the order.
_FIRST = {"strawweight", "straw", "atomweight", "atom"}
_AMBIGUOUS = {"flyweight", "bantamweight", "featherweight", "lightweight"}
_MAX_CAPTURE_BYTES = 4 * 1024 * 1024


def _needs_evidence(profile: dict[str, Any]) -> bool:
    summary = profile.get("profile_summary")
    return profile.get("status") == "success" and isinstance(summary, dict) and not isinstance(summary.get("gender_evidence"), dict)


def _priority(profile: dict[str, Any]) -> int:
    weight_class = " ".join(str((profile.get("profile_summary") or {}).get("weight_class") or "").lower().split())
    if weight_class in _FIRST:
        return 95
    if not weight_class:
        return 90
    if weight_class in _AMBIGUOUS:
        return 70
    return 0


def _latest_captures(snapshots_dir: Path) -> dict[str, Path]:
    """Newest capture per fighter; run directories sort chronologically by name."""
    captures: dict[str, Path] = {}
    for path in sorted(Path(snapshots_dir).rglob("profile_snapshots/*.json")):
        captures[path.stem] = path
    return captures


def _capture_lines(path: Path, source_fighter_id: str) -> list[str] | None:
    if path.stat().st_size > _MAX_CAPTURE_BYTES:
        return None
    try:
        snapshot = json.loads(path.read_text(encoding="utf-8-sig"))
    except (ValueError, UnicodeError):
        return None
    if not isinstance(snapshot, dict) or not isinstance(snapshot.get("lines"), list):
        return None
    # A capture of another page (Tapology redirects removed profiles) proves nothing about this fighter.
    if source_fighter_id_from_url(normalize_tapology_fighter_url(snapshot.get("url"))) != source_fighter_id:
        return None
    return [normalize_space(line) for line in snapshot["lines"] if normalize_space(line)]


def backfill_gender_evidence(profiles_path: Path, snapshots_dir: Path, *, apply: bool = False) -> dict[str, Any]:
    profiles_path = Path(profiles_path)
    document = json.loads(profiles_path.read_text(encoding="utf-8"))
    captures = _latest_captures(Path(snapshots_dir))
    counts: Counter[str] = Counter()
    for profile in document.get("profiles", []):
        if not isinstance(profile, dict) or not _needs_evidence(profile):
            continue
        counts["profiles_without_evidence"] += 1
        source_fighter_id = str(profile.get("source_fighter_id") or "")
        path = captures.get(source_fighter_id)
        lines = _capture_lines(path, source_fighter_id) if path else None
        if lines is None:
            counts["no_usable_capture"] += 1
            continue
        warnings: list[str] = []
        gender, evidence = _extract_gender_evidence(lines, warnings)
        summary = profile["profile_summary"]
        summary["gender"] = gender
        summary["gender_evidence"] = evidence
        if warnings:
            profile["warnings"] = sorted(set(list(profile.get("warnings") or []) + warnings))
        counts["evidence_added"] += 1
        counts[f"gender_{gender or 'none'}"] += 1
        counts[f"status_{evidence['status']}"] += 1

    report: dict[str, Any] = {
        "mode": "apply" if apply else "dry_run",
        "profiles_file": str(profiles_path),
        "captures_found": len(captures),
        **dict(sorted(counts.items())),
        "backup": None,
    }
    if apply and counts["evidence_added"]:
        backup = profiles_path.with_name(f"{profiles_path.name}.bak-gender-{utc_now().replace(':', '').replace('-', '')[:15]}")
        shutil.copy2(profiles_path, backup)
        atomic_write_json(profiles_path, document)
        report["backup"] = str(backup)
    return report


def _last_fight_date(profile: dict[str, Any]) -> str:
    dates = [str(row.get("fight_date") or "") for row in profile.get("fight_history") or [] if isinstance(row, dict)]
    return max(dates, default="")


def build_gender_evidence_queue(profiles_path: Path, output_path: Path, *, tracking_decisions_path: Path | None = None) -> dict[str, Any]:
    output_path = Path(output_path)
    if output_path.exists():
        raise FileExistsError("Queue already exists; use it without regenerating it.")
    document = json.loads(Path(profiles_path).read_text(encoding="utf-8"))
    tracking = load_tracking_decisions(tracking_decisions_path)
    items: list[dict[str, Any]] = []
    seen: set[str] = set()
    skipped_tracking = 0
    for profile in document.get("profiles", []):
        if not isinstance(profile, dict) or not _needs_evidence(profile):
            continue
        priority = _priority(profile)
        url = normalize_tapology_fighter_url(profile.get("source_url"))
        if not priority or not url or url in seen:
            continue
        source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(url)
        if not tracking_allows_profile(tracking_decision_for(tracking, source_fighter_id, url)):
            skipped_tracking += 1
            continue
        seen.add(url)
        items.append(
            {
                "source_fighter_id": source_fighter_id,
                "source_url": url,
                "display_name": profile.get("display_name"),
                "seen_in": [],
                "already_enriched": True,
                "existing_profile_status": "success",
                "last_scraped_at": profile.get("scraped_at"),
                "latest_seen_at": _last_fight_date(profile) or None,
                "refresh_ttl_days": 0,
                "should_refresh": True,
                "is_stale": True,
                "stale_reason": STALE_REASON,
                "priority": priority,
                "warnings": [],
                # A narrower request would replace a stored whole-career history with a record-only profile.
                "fetch_scope": "career_record" if profile.get("fetch_scope") == "career_record" else "professional_mma_career",
            }
        )
    # Least certain class first; within a class the most recently active fighters.
    items.sort(key=lambda item: item["latest_seen_at"] or "", reverse=True)
    items.sort(key=lambda item: -item["priority"])
    queue = {
        "schema_version": QUEUE_SCHEMA_VERSION,
        "created_at": utc_now(),
        "queue_mode": "gender-evidence",
        "queue": items,
        "unique_profile_urls": len(items),
        "refreshable_count": len(items),
        "fresh_count": 0,
        "tracking_skipped_count": skipped_tracking,
        "tracking_decisions_revision": tracking["revision"],
        "count_by_priority": {str(key): value for key, value in sorted(Counter(item["priority"] for item in items).items(), reverse=True)},
    }
    atomic_write_json(output_path, queue)
    return {key: value for key, value in queue.items() if key != "queue"} | {"output": str(output_path)}
