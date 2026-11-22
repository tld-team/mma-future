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


TARGETS_SCHEMA_VERSION = "mmaf_gender_targets_v1"
_TARGET_PRIORITY = {"inferred_female": 95, "missing": 90}


def _load_targets(path: Path | None) -> tuple[dict[str, dict[str, Any]] | None, int]:
    """WordPress export of the fighters whose gender is still open, keyed by source fighter id."""
    if path is None:
        return None, 0
    payload = json.loads(Path(path).read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict) or payload.get("schema_version") != TARGETS_SCHEMA_VERSION or not isinstance(payload.get("fighters"), list):
        raise ValueError("invalid_gender_targets_document")
    targets: dict[str, dict[str, Any]] = {}
    unidentified = 0
    for row in payload["fighters"]:
        if not isinstance(row, dict):
            continue
        url = normalize_tapology_fighter_url(row.get("source_url"))
        source_fighter_id = row.get("source_fighter_id") or source_fighter_id_from_url(url)
        if source_fighter_id:
            targets.setdefault(str(source_fighter_id), {**row, "source_url": url})
        else:
            # No Tapology identity: nothing to fetch, the gender has to be entered by hand.
            unidentified += 1
    return targets, unidentified


def _queue_item(source_fighter_id: str, url: str, profile: dict[str, Any] | None, priority: int, display_name: Any) -> dict[str, Any]:
    cached = profile or {}
    return {
        "source_fighter_id": source_fighter_id,
        "source_url": url,
        "display_name": display_name,
        "seen_in": [],
        "already_enriched": profile is not None,
        "existing_profile_status": cached.get("status"),
        "last_scraped_at": cached.get("scraped_at"),
        "latest_seen_at": _last_fight_date(cached) or None,
        "refresh_ttl_days": 0,
        "should_refresh": True,
        "is_stale": True,
        "stale_reason": STALE_REASON if profile is not None else "never_scraped",
        "priority": priority,
        "warnings": [],
        # A narrower request would replace a stored whole-career history with a record-only profile.
        "fetch_scope": "career_record" if cached.get("fetch_scope") == "career_record" else "professional_mma_career",
    }


def build_gender_evidence_queue(
    profiles_path: Path,
    output_path: Path,
    *,
    tracking_decisions_path: Path | None = None,
    targets_path: Path | None = None,
) -> dict[str, Any]:
    """Queue the cached profiles that still lack gender evidence.

    Without targets the cached weight class decides who is worth a fetch. With a WordPress
    targets export only the listed fighters are queued, whatever their weight class.
    """
    output_path = Path(output_path)
    if output_path.exists():
        raise FileExistsError("Queue already exists; use it without regenerating it.")
    document = json.loads(Path(profiles_path).read_text(encoding="utf-8"))
    tracking = load_tracking_decisions(tracking_decisions_path)
    targets, targets_without_identity = _load_targets(targets_path)
    items: list[dict[str, Any]] = []
    seen: set[str] = set()
    cached_ids: set[str] = set()
    skipped_tracking = 0
    resolved = 0
    needs_manual: list[dict[str, Any]] = []
    for profile in document.get("profiles", []):
        if not isinstance(profile, dict):
            continue
        url = normalize_tapology_fighter_url(profile.get("source_url"))
        source_fighter_id = str(profile.get("source_fighter_id") or source_fighter_id_from_url(url) or "")
        target = targets.get(source_fighter_id) if targets is not None else None
        if targets is not None and target is None:
            continue
        cached_ids.add(source_fighter_id)
        if not _needs_evidence(profile):
            summary = profile.get("profile_summary") if isinstance(profile.get("profile_summary"), dict) else {}
            if target is not None and profile.get("status") == "success":
                if summary.get("gender") in {"male", "female"}:
                    resolved += 1
                else:
                    # The page was read and states no gender; another fetch would say the same.
                    needs_manual.append({"source_fighter_id": source_fighter_id, "display_name": profile.get("display_name"), "source_url": url,
                                         "status": (summary.get("gender_evidence") or {}).get("status")})
            continue
        priority = _TARGET_PRIORITY.get(str(target.get("reason")), 90) if target is not None else _priority(profile)
        if not priority or not url or url in seen:
            continue
        if not tracking_allows_profile(tracking_decision_for(tracking, source_fighter_id, url)):
            skipped_tracking += 1
            continue
        seen.add(url)
        items.append(_queue_item(source_fighter_id, url, profile, priority, profile.get("display_name")))
    without_source = 0
    for source_fighter_id, target in (targets or {}).items():
        if source_fighter_id in cached_ids:
            continue
        url = target.get("source_url")
        if not url:
            without_source += 1
            continue
        if url in seen or not tracking_allows_profile(tracking_decision_for(tracking, source_fighter_id, url)):
            continue
        seen.add(url)
        items.append(_queue_item(source_fighter_id, url, None, 85, target.get("display_name")))
    # Least certain first; within a group the most recently active fighters.
    items.sort(key=lambda item: item["latest_seen_at"] or "", reverse=True)
    items.sort(key=lambda item: -item["priority"])
    queue = {
        "schema_version": QUEUE_SCHEMA_VERSION,
        "created_at": utc_now(),
        "queue_mode": "gender-evidence-targets" if targets is not None else "gender-evidence",
        "queue": items,
        "unique_profile_urls": len(items),
        "refreshable_count": len(items),
        "fresh_count": 0,
        "tracking_skipped_count": skipped_tracking,
        "tracking_decisions_revision": tracking["revision"],
        "count_by_priority": {str(key): value for key, value in sorted(Counter(item["priority"] for item in items).items(), reverse=True)},
    }
    if targets is not None:
        queue.update(
            targets_total=len(targets),
            targets_resolved_by_cached_evidence=resolved,
            targets_without_source_url=without_source,
            targets_without_tapology_identity=targets_without_identity,
            targets_needing_manual_gender=needs_manual,
        )
    atomic_write_json(output_path, queue)
    return {key: value for key, value in queue.items() if key != "queue"} | {"output": str(output_path)}
