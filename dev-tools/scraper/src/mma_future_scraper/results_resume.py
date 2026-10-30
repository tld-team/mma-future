"""Validate local result checkpoints without advancing successful scraper state."""
from __future__ import annotations

from contextlib import redirect_stdout
from copy import deepcopy
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
from pathlib import Path
from urllib.parse import urlsplit

from .contract import populate_event_contract_fields
from .event_coverage import PARSER_VERSION, validate as validate_coverage
from .models import RESULTS_SCHEMA_VERSION
from .utils import source_bout_id_from_url, source_event_id_from_url
from .validation import validate_results_document


def timestamp(value: str) -> datetime:
    result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if result.tzinfo is None:
        raise ValueError("Resume timestamps must include a timezone")
    return result


@dataclass
class ResultsResume:
    events: list[dict] = field(default_factory=list)
    excluded: list[dict] = field(default_factory=list)
    deferred: list[dict] = field(default_factory=list)
    sources: list[dict] = field(default_factory=list)
    snapshots: dict[str, Path] = field(default_factory=dict)

    def report(self) -> dict:
        return {
            "schema_version": "mmaf_results_resume_v1",
            "sources": self.sources,
            "complete_events": len(self.events),
            "complete_bouts": sum(len(event["bouts"]) for event in self.events),
            "verified_exclusions": len(self.excluded),
            "deferred_events": len(self.deferred),
            "deferred": self.deferred,
            "reusable_event_ids": [event["source_event_id"] for event in self.events],
            "excluded_event_ids": [proof["source_event_id"] for proof in self.excluded],
        }


def _source_identity(url: str, source_id: str) -> None:
    parsed = urlsplit(url)
    if (
        parsed.scheme != "https"
        or parsed.hostname not in {"tapology.com", "www.tapology.com"}
        or source_event_id_from_url(url) != source_id
    ):
        raise ValueError(f"Resume source identity mismatch: {source_id}")


def _validate_events(document: dict) -> None:
    # Temporary IDs are for schema validation only. Real IDs are allocated by the
    # normal successful commit, using the existing operational state.
    candidate = deepcopy(document)
    bout_number = 0
    for number, event in enumerate(candidate["events"], start=1):
        _source_identity(event["event_url"], event["source_event_id"])
        normalized = populate_event_contract_fields(deepcopy(event))
        if any(event.get(key) != normalized[key] for key in ("identity_hash", "content_hash")):
            raise ValueError(f"Resume event hash mismatch: {event['source_event_id']}")
        event["internal_event_id"] = f"mf_event_{number:06d}"
        for bout in event.get("bouts", []):
            if source_bout_id_from_url(bout.get("bout_url")) != bout.get("source_bout_id"):
                raise ValueError("Resume bout URL/identity mismatch")
            original = next(b for b in normalized["bouts"] if b["source_bout_id"] == bout["source_bout_id"])
            if any(bout.get(key) != original[key] for key in ("identity_hash", "content_hash")):
                raise ValueError(f"Resume bout hash mismatch: {bout['source_bout_id']}")
            bout_number += 1
            bout["internal_bout_id"] = f"mf_bout_{bout_number:06d}"
            if bout.get("source_event_id") not in {None, event["source_event_id"]}:
                raise ValueError("Resume bout parent mismatch")
            bout["internal_event_id"] = event["internal_event_id"]
    errors = validate_results_document(candidate)
    if errors:
        raise ValueError("Invalid resume results: " + "; ".join(errors[:5]))


def load_results_checkpoints(
    paths: tuple[Path, ...] | list[Path],
    *,
    filters: dict,
    now: datetime | None = None,
    max_age_hours: int = 48,
) -> ResultsResume:
    now = now or datetime.now(timezone.utc)
    result = ResultsResume()
    latest: dict[str, tuple[dict | None, dict, Path, str | None]] = {}
    for path in dict.fromkeys(Path(item).expanduser().resolve() for item in paths):
        raw = path.read_bytes()
        document = json.loads(raw)
        if document.get("schema_version") != RESULTS_SCHEMA_VERSION or not isinstance(document.get("events"), list):
            raise ValueError(f"Invalid results checkpoint: {path}")
        if document.get("source") != "tapology" or document.get("filters") != filters:
            raise ValueError(f"Results checkpoint scope differs from this run: {path}")
        run = document.get("run") or {}
        if run.get("checkpoint_partial") is not True:
            raise ValueError(f"Not a results checkpoint: {path}")
        manifest = json.loads((path.parent / "run_manifest.json").read_text(encoding="utf-8"))
        if run.get("run_id") != manifest.get("run_id") or manifest.get("source") != "tapology":
            raise ValueError(f"Checkpoint manifest identity mismatch: {path}")
        coverage = json.loads((path.parent / "event_coverage.json").read_text(encoding="utf-8"))
        if coverage.get("run_id") != run["run_id"]:
            raise ValueError(f"Checkpoint coverage run mismatch: {path}")
        validate_coverage(coverage, document["events"])
        _validate_events(document)
        result.sources.append({"path": str(path), "run_id": run["run_id"], "sha256": hashlib.sha256(raw).hexdigest()})
        by_id = {event["source_event_id"]: event for event in document["events"]}
        for proof in coverage["events"]:
            sid = proof["source_event_id"]
            _source_identity(proof["event_url"], sid)
            observed = timestamp(proof["observed_at"]) if proof.get("observed_at") else None
            if observed is None:
                continue
            if observed > now + timedelta(minutes=1):
                raise ValueError(f"Resume observation is in the future: {sid}")
            event = by_id.get(sid)
            reason = None
            if now - observed > timedelta(hours=max_age_hours):
                reason = "expired_checkpoint_observation"
            elif proof.get("parser_version") != PARSER_VERSION:
                reason = "checkpoint_parser_changed"
            elif proof["status"] not in {"complete", "excluded"}:
                reason = "partial_requires_live_recheck"
            elif event is not None and (event.get("needs_recheck") or event.get("source_event_status") != "completed"):
                reason = "unfinished_requires_live_recheck"
            snapshot_path = path.parent / "event_snapshots" / f"{sid}.json"
            if reason is None:
                snapshot = json.loads(snapshot_path.read_text(encoding="utf-8"))
                _source_identity(snapshot.get("source_url", ""), sid)
                if snapshot.get("parser_version") != PARSER_VERSION:
                    reason = "snapshot_parser_changed"
                elif snapshot.get("status") in {"blocked", "unavailable"} or (snapshot.get("http_status") or 0) >= 400:
                    reason = "snapshot_unavailable"
                else:
                    # Recheck classification against the saved DOM rows, keeping
                    # the original, richer result payload (records, weight, etc.).
                    from .event_recovery import reparse_event_snapshot
                    with redirect_stdout(io.StringIO()):
                        _, reparsed = reparse_event_snapshot(snapshot)
                    if {k: v for k, v in reparsed.items() if k != "observed_at"} != {
                        k: v for k, v in proof.items() if k != "observed_at"
                    }:
                        reason = "snapshot_coverage_changed"
            previous = latest.get(sid)
            if previous is None or observed >= timestamp(previous[1]["observed_at"]):
                latest[sid] = (event, proof, snapshot_path, reason)
    for sid, (event, proof, snapshot, reason) in latest.items():
        if reason:
            unresolved = [bout for bout in proof.get("bouts", []) if bout.get("decision") == "unresolved"]
            result.deferred.append({
                "source_event_id": sid, "event_url": proof["event_url"], "reason": reason,
                "observed_at": proof.get("observed_at"),
                "verified_empty_results": (
                    reason == "partial_requires_live_recheck" and proof.get("counts", {}).get("accepted", 0) == 0
                    and bool(unresolved) and all(bout.get("reason") == "no_result" for bout in unresolved)
                ),
            })
        elif proof["status"] == "excluded":
            result.excluded.append(deepcopy(proof))
            result.snapshots[sid] = snapshot
        elif event:
            result.events.append(deepcopy(event))
            result.snapshots[sid] = snapshot
    return result


def recover_results_snapshot(snapshot_path: Path, output_root: Path, *, filters: dict, now: datetime | None = None) -> Path:
    """Prepare a separate validated checkpoint from a captured, uncommitted card.

    This writes recovery artifacts only. It never marks the failed source run
    successful, modifies operational state, or opens source/bout pages.
    """
    from .config import ScraperConfig
    from .event_coverage import document as coverage_document
    from .event_recovery import reparse_event_snapshot
    from .storage import atomic_write_json
    from .tapology import TapologyScraper

    now = now or datetime.now(timezone.utc)
    snapshot_path = Path(snapshot_path).resolve()
    raw = snapshot_path.read_bytes()
    snapshot = json.loads(raw)
    source_dir = snapshot_path.parent.parent
    source_document = json.loads((source_dir / "checkpoint_results.json").read_text())
    source_manifest = json.loads((source_dir / "run_manifest.json").read_text())
    if source_document.get("source") != "tapology" or source_document.get("filters") != filters:
        raise ValueError("Recovery snapshot source scope mismatch")
    if source_document.get("run", {}).get("run_id") != source_manifest.get("run_id"):
        raise ValueError("Recovery source manifest mismatch")
    if snapshot.get("parser_version") != PARSER_VERSION:
        raise ValueError("Recovery snapshot parser changed")
    if snapshot.get("status") in {"blocked", "unavailable"} or (snapshot.get("http_status") or 0) >= 400:
        raise ValueError("Recovery snapshot is unavailable")
    observed = timestamp(snapshot["observed_at"])
    if observed > now or now - observed > timedelta(hours=48):
        raise ValueError("Recovery snapshot must be a recent, original observation")
    with redirect_stdout(io.StringIO()):
        event, proof = reparse_event_snapshot(snapshot)
    if event is None or proof["status"] != "complete" or event.get("needs_recheck"):
        raise ValueError("Recovery snapshot does not contain a complete result card")
    digest = hashlib.sha256(raw).hexdigest()
    run_id = f"{observed.astimezone(timezone.utc):%Y%m%dT%H%M%SZ}-{event['source_event_id']}-{digest[:8]}"
    target = Path(output_root) / run_id
    document = TapologyScraper(ScraperConfig())._empty_document("results", run_id, snapshot["observed_at"])
    document["filters"] = deepcopy(filters)
    document["events"] = [event]
    document["scraped_at"] = snapshot["observed_at"]
    document["run"].update(checkpoint_partial=True, checkpoint_at=now.isoformat(), offline_recovery=True)
    _validate_events(document)
    coverage = coverage_document([event], run_id=run_id)
    validate_coverage(coverage, [event])
    atomic_write_json(target / "event_snapshots" / f"{event['source_event_id']}.json", snapshot)
    atomic_write_json(target / "event_coverage.json", coverage)
    atomic_write_json(target / "run_manifest.json", {
        "run_id": run_id, "source": "tapology", "status": "offline_recovery", "prepared_at": now.isoformat(),
        "source_run_id": source_manifest["run_id"], "source_snapshot": str(snapshot_path), "source_snapshot_sha256": digest,
        "counts": {"events_recovered": 1, "bouts_recovered": len(event["bouts"]), "network_requests": 0},
    })
    checkpoint = target / "checkpoint_results.json"
    atomic_write_json(checkpoint, document)
    load_results_checkpoints([checkpoint], filters=filters, now=now)
    return checkpoint
