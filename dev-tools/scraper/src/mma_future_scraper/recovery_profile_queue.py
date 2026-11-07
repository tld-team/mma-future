from __future__ import annotations

import json
from collections import OrderedDict
from pathlib import Path
from typing import Any

from .fighter_profile_queue import normalize_tapology_fighter_url
from .storage import atomic_write_json
from .utils import source_fighter_id_from_url, utc_now


def build_recovery_profile_queue(
    recovered_results_path: Path,
    output_path: Path,
    *,
    only_source_ids: set[str] | None = None,
    confirmed_source_ids: set[str] | None = None,
) -> dict[str, Any]:
    """Turn verified recovered bouts into identity candidates, never name matches."""
    confirmed_source_ids = confirmed_source_ids or set()
    payload = json.loads(recovered_results_path.read_text(encoding="utf-8"))
    if not isinstance(payload.get("events"), list):
        raise ValueError("Recovery input must contain an events list.")
    candidates: OrderedDict[str, dict[str, Any]] = OrderedDict()
    for event in payload["events"]:
        if not isinstance(event, dict):
            continue
        event_id = event.get("source_event_id")
        for bout in event.get("bouts", []):
            if not isinstance(bout, dict):
                continue
            flags = bout.get("flags") or {}
            if bout.get("pro_am") != "professional" or flags.get("is_completed") is False:
                continue
            for corner in ("fighter_a", "fighter_b"):
                fighter = bout.get(corner) or {}
                url = normalize_tapology_fighter_url(fighter.get("url"))
                source_id = fighter.get("source_fighter_id") or source_fighter_id_from_url(url)
                if not source_id or not url:
                    continue
                source_id = str(source_id)
                if only_source_ids and source_id not in only_source_ids:
                    continue
                numeric_identity = source_id.startswith("tapology_fighter_") and source_id.removeprefix("tapology_fighter_").isdigit()
                confirmed_for_fetch = numeric_identity and source_id in confirmed_source_ids
                item = candidates.setdefault(source_id, {
                    "source_fighter_id": source_id,
                    "source_url": url,
                    "display_name": fighter.get("name"),
                    "identity_status": "confirmed_numeric" if numeric_identity else "waiting_numeric_identity",
                    "action": "refresh_source_evidence" if confirmed_for_fetch else ("review_canonical_coverage" if numeric_identity else "review_source_identity"),
                    "fetch_scope": "professional_mma_career" if confirmed_for_fetch else None,
                    "force_refresh": confirmed_for_fetch,
                    "recovery_evidence": {"source_event_ids": [], "source_bout_ids": []},
                })
                evidence = item["recovery_evidence"]
                if event_id and event_id not in evidence["source_event_ids"]:
                    evidence["source_event_ids"].append(event_id)
                bout_id = bout.get("source_bout_id")
                if bout_id and bout_id not in evidence["source_bout_ids"]:
                    evidence["source_bout_ids"].append(bout_id)
    document = {
        "schema_version": "mmaf_source_enrichment_v1",
        "contract_version": 1,
        "generated_at": utc_now(),
        "source": {
            "type": "recovered_event_candidates",
            "path": str(recovered_results_path),
            "events_seen": len(payload["events"]),
            "note": "Candidates require canonical/profile comparison; only explicitly confirmed source IDs are fetchable.",
        },
        "items": list(candidates.values()),
        "candidate_count": len(candidates),
        "fetchable_count": sum(1 for item in candidates.values() if item["action"] == "refresh_source_evidence"),
        "coverage_review_count": sum(1 for item in candidates.values() if item["action"] == "review_canonical_coverage"),
        "identity_review_count": sum(1 for item in candidates.values() if item["action"] == "review_source_identity"),
    }
    atomic_write_json(output_path, document)
    return document
