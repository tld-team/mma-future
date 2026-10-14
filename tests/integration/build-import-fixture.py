"""Offline synthetic source -> actual parser -> cycle -> ZIP for PHP integration."""
import sys,json
from datetime import datetime, timezone
from pathlib import Path
root=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(root/'dev-tools/scraper/src'),str(root/'dev-tools/scraper/tests')]
from mma_future_scraper.fighter_profiles import parse_profile_snapshot
from test_pre_import import _write_complete_latest
from mma_future_scraper.import_cycle import ImportCycle
base=Path(sys.argv[1]);latest=base/'data/latest';_write_complete_latest(latest,latest.parent/'state')
profiles={'schema_version':'tapology_fighter_profiles_v0_1','source':'tapology','run_id':'profiles_1','profiles_total':2,'profiles_success':2,'profiles_failed':0,'profiles':[]};now=datetime.now(timezone.utc).isoformat()
for snapshot in json.loads((root/'tests/fixtures/import-pipeline/snapshots.json').read_text()):
    snapshot['scraped_at']=now
    profile=parse_profile_snapshot(snapshot,snapshot['url']);profile['scraped_at']=snapshot['scraped_at'];profiles['profiles'].append(profile)
(latest/'fighter_profiles.json').write_text(json.dumps(profiles))
cycle=ImportCycle('fixture',latest);cycle.begin(expected_pages=15);cycle.adopt('events-1',latest);cycle.adopt('profiles-1',latest)
result=cycle.finalize(base/'ready')
print(json.dumps(result))
