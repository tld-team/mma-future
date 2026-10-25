"""Build an offline roster.watch-format fixture through the real scraper exporter."""
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "dev-tools/scraper/src"))
from mma_future_scraper.import_bundle import export_ufc_roster_bundle
from mma_future_scraper.ufc_roster import build_document

target = Path(sys.argv[1]).resolve()
latest = target / "latest"
latest.mkdir(parents=True, exist_ok=True)
document = build_document(
    [{"name": "Synthetic UFC Import Fighter", "url": "/fighters/synthetic-import", "cells": ["Synthetic UFC Import Fighter"]}],
    [{"name": "Former Synthetic Fighter", "url": "/fighters/former-synthetic", "cells": ["Former Synthetic Fighter"]}],
    [{"name": "Synthetic UFC Import Fighter", "url": "/fighters/synthetic-import", "date": datetime.now(timezone.utc).strftime("%Y-%m-%d"), "text": "✅ Fighter added: Synthetic UFC Import Fighter"}],
    run_id="isolated-ufc-import",
    scraped_at=datetime.now(timezone.utc).isoformat(),
)
(latest / "ufc_roster.json").write_text(json.dumps(document, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
print(export_ufc_roster_bundle(target / "standalone.zip", latest_dir=latest)["output"])
