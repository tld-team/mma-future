import sys,json
from pathlib import Path
root=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'dev-tools/scraper/src'))
from mma_future_scraper.import_bundle import export_import_bundle
print(json.dumps(export_import_bundle(Path(sys.argv[3]),latest_dir=Path(sys.argv[1]),base_receipt=json.loads(Path(sys.argv[2]).read_text()),expected_pages=15)))
