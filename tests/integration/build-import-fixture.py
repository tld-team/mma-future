"""Offline synthetic source -> actual parser -> cycle -> ZIP for PHP integration."""
import sys,json
from pathlib import Path
root=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(root/'dev-tools/scraper/src'),str(root/'dev-tools/scraper/tests')]
from test_import_pipeline import cycle
result=cycle(Path(sys.argv[1])).finalize(Path(sys.argv[1])/'ready')
print(json.dumps(result))
