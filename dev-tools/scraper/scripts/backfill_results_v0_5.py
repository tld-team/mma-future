from __future__ import annotations

import argparse
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

from mma_future_scraper.config import DATA_DIR, LATEST_DIR, RUNS_DIR
from mma_future_scraper.contract import populate_results_contract_fields
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
from mma_future_scraper.storage import atomic_write_json
from mma_future_scraper.validation import validate_results_document


def main() -> int:
    parser = argparse.ArgumentParser(description="Backfill results.json files to the v0.5 import contract.")
    parser.add_argument("--path", type=Path, default=LATEST_DIR / "results.json")
    parser.add_argument("--backup-dir", type=Path, default=None)
    args = parser.parse_args()

    paths = _target_paths(args.path)
    payloads = {path: _backfilled_payload(path) for path in paths}
    for path, payload in payloads.items():
        errors = validate_results_document(payload)
        if errors:
            for error in errors:
                print(f"{path}:{error}")
            return 1

    backup_dir = args.backup_dir or DATA_DIR / "backups" / f"results_v0_5_backfill_{_timestamp()}"
    backup_dir.mkdir(parents=True, exist_ok=True)
    for path, payload in payloads.items():
        backup_path = backup_dir / _backup_name(path)
        shutil.copy2(path, backup_path)
        atomic_write_json(path, payload)
        print(f"backfilled:{path}")
        print(f"backup:{backup_path}")
    return 0


def _target_paths(path: Path) -> list[Path]:
    paths = [path]
    if path.resolve() == (LATEST_DIR / "results.json").resolve():
        payload = json.loads(path.read_text(encoding="utf-8"))
        run_id = (payload.get("run") or {}).get("run_id")
        run_results = RUNS_DIR / str(run_id) / "results.json" if run_id else None
        if run_results and run_results.exists():
            paths.append(run_results)
    unique_paths: list[Path] = []
    seen: set[Path] = set()
    for item in paths:
        resolved = item.resolve()
        if resolved not in seen:
            seen.add(resolved)
            unique_paths.append(item)
    return unique_paths


def _backfilled_payload(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding="utf-8"))
    payload["schema_version"] = RESULTS_SCHEMA_VERSION
    populate_results_contract_fields(payload)
    return payload


def _backup_name(path: Path) -> str:
    if path.resolve() == (LATEST_DIR / "results.json").resolve():
        return "latest_results.json"
    return "_".join(path.parts[-3:])


def _timestamp() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


if __name__ == "__main__":
    raise SystemExit(main())
