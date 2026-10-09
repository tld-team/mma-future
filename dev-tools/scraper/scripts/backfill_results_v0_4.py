from __future__ import annotations

import argparse
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

from mma_future_scraper.config import DATA_DIR, LATEST_DIR
from mma_future_scraper.contract import populate_results_contract_fields
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
from mma_future_scraper.storage import atomic_write_json
from mma_future_scraper.validation import validate_results_document


def main() -> int:
    parser = argparse.ArgumentParser(description="Backfill a results.json file to the v0.4 import contract.")
    parser.add_argument("--path", type=Path, default=LATEST_DIR / "results.json")
    parser.add_argument("--backup-dir", type=Path, default=None)
    args = parser.parse_args()

    path = args.path
    payload = json.loads(path.read_text(encoding="utf-8"))
    payload["schema_version"] = RESULTS_SCHEMA_VERSION
    populate_results_contract_fields(payload)

    errors = validate_results_document(payload)
    if errors:
        for error in errors:
            print(error)
        return 1

    backup_dir = args.backup_dir or DATA_DIR / "backups" / f"results_v0_4_backfill_{_timestamp()}"
    backup_dir.mkdir(parents=True, exist_ok=True)
    backup_path = backup_dir / path.name
    shutil.copy2(path, backup_path)
    atomic_write_json(path, payload)
    print(f"backfilled:{path}")
    print(f"backup:{backup_path}")
    return 0


def _timestamp() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


if __name__ == "__main__":
    raise SystemExit(main())
