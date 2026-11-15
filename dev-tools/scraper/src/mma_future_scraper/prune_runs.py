"""Opt-in cleanup of redundant per-run artifacts under ``data/runs/``.

Only ``data/runs/<run_id>/`` is ever modified. The cumulative state the next
scraper run reads (``state/``, ``latest/``, ``derived/`` and the isolated
``upcoming/`` lane) is never touched. Dry-run is the default; ``apply=True``
is required to delete anything.

Kept untouched (whole run folder):
- runs referenced by an import cycle that is not finalized (``state/import-cycles/*.json``),
- the newest ``keep_event_runs`` event runs (folders with ``run_manifest.json``),
- runs younger than ``min_age_hours`` (checkpoint reuse window).

In every other run only redundant artifacts are removed:
``fighter_profiles_report.*``, ``raw_debug/``, ``debug.json`` and
``profile_snapshots/``. Before any snapshot folder is removed, the newest
snapshot per fighter among the pruned runs is written to a verified tar.gz.
Primary run outputs (``results.json``, ``fighter_profiles.json``,
``run_manifest.json``, ...) stay in place.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
import io
import json
import os
from pathlib import Path
import re
import shutil
import tarfile
from typing import Any

PRUNE_SCHEMA_VERSION = "scraper_prune_runs_v0_1"
PROTECTED_TOP_LEVEL = ("state", "latest", "derived", "upcoming")
RUN_TIMESTAMP_RE = re.compile(r"(\d{8}T\d{6}Z)")
REDUNDANT_FILE_GLOBS = ("fighter_profiles_report.*", "debug.json")
REDUNDANT_DIRS = ("raw_debug",)
SNAPSHOT_DIR = "profile_snapshots"


class PruneError(RuntimeError):
    pass


def prune_runs(
    data_dir: Path,
    *,
    apply: bool = False,
    keep_event_runs: int = 5,
    min_age_hours: float = 48,
    archive_dir: Path | None = None,
    now: datetime | None = None,
    details: bool = False,
) -> dict[str, Any]:
    data_dir = Path(data_dir).expanduser().resolve()
    runs_dir = data_dir / "runs"
    now = now or datetime.now(timezone.utc)
    archive_dir = Path(archive_dir).expanduser().resolve() if archive_dir else data_dir / "archive"
    _assert_not_protected(data_dir, archive_dir, what="archive directory")

    runs = _scan_runs(runs_dir)
    referenced = _cycle_referenced_runs(data_dir / "state" / "import-cycles")
    event_runs = sorted((r for r in runs if r["kind"] == "events"), key=lambda r: r["sort_key"], reverse=True)
    newest_events = {r["run_id"] for r in event_runs[: max(keep_event_runs, 0)]}
    cutoff = now - timedelta(hours=min_age_hours)

    kept: list[dict[str, Any]] = []
    pruned: list[dict[str, Any]] = []
    for run in runs:
        reasons = []
        if run["run_id"] in referenced:
            reasons.append("open_import_cycle:" + ",".join(sorted(referenced[run["run_id"]])))
        if run["run_id"] in newest_events:
            reasons.append(f"newest_{keep_event_runs}_event_runs")
        if run["started_at"] > cutoff:
            reasons.append(f"younger_than_{min_age_hours:g}h")
        if reasons:
            kept.append({"run_id": run["run_id"], "kind": run["kind"], "reasons": reasons})
            continue
        actions = _redundant_artifacts(run["path"])
        if actions:
            pruned.append({**run, "actions": actions})

    for entry in pruned:
        for action in entry["actions"]:
            _assert_inside_runs(runs_dir, action["path"])
            _assert_not_protected(data_dir, action["path"], what="prune target")

    snapshots = _newest_snapshots(pruned)
    archive_path = archive_dir / f"profile_snapshots_{now.strftime('%Y%m%dT%H%M%SZ')}.tar.gz" if snapshots else None
    summary: dict[str, Any] = {
        "schema_version": PRUNE_SCHEMA_VERSION,
        "mode": "apply" if apply else "dry_run",
        "data_dir": str(data_dir),
        "runs_total": len(runs),
        "runs_kept": len(kept),
        "runs_pruned": len(pruned),
        "kept": kept,
        "bytes_reclaimable": sum(a["bytes"] for e in pruned for a in e["actions"]),
        "items_by_type": _count_by_type(pruned),
        "snapshot_archive": {
            "path": str(archive_path) if archive_path else None,
            "fighters": len(snapshots),
            "snapshot_files_in_pruned_runs": sum(
                a["files"] for e in pruned for a in e["actions"] if a["type"] == SNAPSHOT_DIR
            ),
        },
        "never_touched": [str(data_dir / name) for name in PROTECTED_TOP_LEVEL],
    }
    if details:
        summary["pruned"] = [
            {"run_id": e["run_id"], "kind": e["kind"], "remove": [a["name"] for a in e["actions"]]} for e in pruned
        ]
    if not apply:
        return summary

    if archive_path:
        _write_snapshot_archive(archive_path, snapshots)
    removed_bytes = 0
    for entry in pruned:
        for action in entry["actions"]:
            path: Path = action["path"]
            if path.is_symlink() or not path.exists():
                continue
            if path.is_dir():
                shutil.rmtree(path)
            else:
                path.unlink()
            removed_bytes += action["bytes"]
    summary["bytes_removed"] = removed_bytes
    return summary


def _scan_runs(runs_dir: Path) -> list[dict[str, Any]]:
    if not runs_dir.is_dir():
        return []
    runs = []
    for path in sorted(runs_dir.iterdir()):
        if not path.is_dir() or path.is_symlink() or path.name.startswith("."):
            continue
        if (path / "run_manifest.json").is_file():
            kind = "events"
        elif (path / "fighter_profiles.json").is_file():
            kind = "profiles"
        else:
            kind = "other"
        started_at = _run_timestamp(path)
        runs.append({"run_id": path.name, "path": path, "kind": kind, "started_at": started_at,
                     "sort_key": (started_at, path.name)})
    return runs


def _run_timestamp(path: Path) -> datetime:
    match = RUN_TIMESTAMP_RE.search(path.name)
    if match:
        return datetime.strptime(match.group(1), "%Y%m%dT%H%M%SZ").replace(tzinfo=timezone.utc)
    return datetime.fromtimestamp(path.stat().st_mtime, tz=timezone.utc)


def _cycle_referenced_runs(cycles_dir: Path) -> dict[str, set[str]]:
    """Run IDs used by any import cycle that has not been finalized yet.

    An unreadable cycle file aborts the prune: without it we cannot prove a
    run is unreferenced.
    """
    referenced: dict[str, set[str]] = {}
    if not cycles_dir.is_dir():
        return referenced
    for path in sorted(cycles_dir.glob("*.json")):
        try:
            cycle = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise PruneError(f"Cannot read import cycle {path.name}: {exc}") from exc
        if cycle.get("status") == "finalized":
            continue
        for task in (cycle.get("tasks") or {}).values():
            run_id = task.get("run_id") if isinstance(task, dict) else None
            if run_id:
                referenced.setdefault(str(run_id), set()).add(str(cycle.get("cycle_id") or path.stem))
    return referenced


def _redundant_artifacts(run_dir: Path) -> list[dict[str, Any]]:
    actions = []
    for pattern in REDUNDANT_FILE_GLOBS:
        for path in sorted(run_dir.glob(pattern)):
            if path.is_file() and not path.is_symlink():
                actions.append({"type": pattern, "name": path.name, "path": path, "bytes": path.stat().st_size, "files": 1})
    for name in (*REDUNDANT_DIRS, SNAPSHOT_DIR):
        path = run_dir / name
        if path.is_dir() and not path.is_symlink():
            size, files = _tree_size(path)
            actions.append({"type": name, "name": name + "/", "path": path, "bytes": size, "files": files})
    return actions


def _tree_size(path: Path) -> tuple[int, int]:
    size = files = 0
    for root, _dirs, names in os.walk(path):
        for name in names:
            child = Path(root) / name
            if not child.is_symlink():
                size += child.stat().st_size
                files += 1
    return size, files


def _newest_snapshots(pruned: list[dict[str, Any]]) -> dict[str, Path]:
    """Newest ``<fighter>.json`` per fighter across the snapshot folders being removed.

    Runs are ordered by their run timestamp (run IDs are UTC timestamps); a
    later run's snapshot of the same fighter supersedes an earlier one.
    """
    newest: dict[str, tuple[tuple[Any, ...], Path]] = {}
    for entry in pruned:
        if not any(a["type"] == SNAPSHOT_DIR for a in entry["actions"]):
            continue
        for path in (entry["path"] / SNAPSHOT_DIR).glob("*.json"):
            if path.is_symlink() or not path.is_file():
                continue
            key = (entry["sort_key"], path.stat().st_mtime)
            current = newest.get(path.stem)
            if current is None or key > current[0]:
                newest[path.stem] = (key, path)
    return {fighter: path for fighter, (_key, path) in sorted(newest.items())}


def _write_snapshot_archive(archive_path: Path, snapshots: dict[str, Path]) -> None:
    archive_path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = archive_path.with_name("." + archive_path.name + ".tmp")
    manifest = {
        "schema_version": PRUNE_SCHEMA_VERSION,
        "description": "Newest profile snapshot per fighter from pruned data/runs/<run_id>/profile_snapshots/",
        "fighters": {fighter: path.parent.parent.name for fighter, path in snapshots.items()},
    }
    with tarfile.open(temp_path, "w:gz") as archive:
        payload = json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8")
        info = tarfile.TarInfo("archive_manifest.json")
        info.size = len(payload)
        archive.addfile(info, io.BytesIO(payload))
        for fighter, path in snapshots.items():
            archive.add(path, arcname=f"{SNAPSHOT_DIR}/{fighter}.json", recursive=False)
    expected = {f"{SNAPSHOT_DIR}/{fighter}.json" for fighter in snapshots} | {"archive_manifest.json"}
    with tarfile.open(temp_path, "r:gz") as archive:
        names = set(archive.getnames())
    if names != expected:
        temp_path.unlink(missing_ok=True)
        raise PruneError("Snapshot archive verification failed; nothing was deleted.")
    temp_path.replace(archive_path)


def _count_by_type(pruned: list[dict[str, Any]]) -> dict[str, dict[str, int]]:
    counts: dict[str, dict[str, int]] = {}
    for entry in pruned:
        for action in entry["actions"]:
            row = counts.setdefault(action["type"], {"runs": 0, "bytes": 0})
            row["runs"] += 1
            row["bytes"] += action["bytes"]
    return counts


def _assert_inside_runs(runs_dir: Path, path: Path) -> None:
    resolved = path.resolve()
    if path.is_symlink() or runs_dir.resolve() not in resolved.parents or resolved.parent.parent != runs_dir.resolve():
        raise PruneError(f"Refusing to remove {path}: not a direct artifact of a run folder.")


def _assert_not_protected(data_dir: Path, path: Path, *, what: str) -> None:
    resolved = Path(path).resolve()
    for name in PROTECTED_TOP_LEVEL:
        protected = (data_dir / name).resolve()
        if resolved == protected or protected in resolved.parents:
            raise PruneError(f"Refusing to use {resolved} as {what}: inside protected {protected}.")
