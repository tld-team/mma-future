from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from typing import Any


def log(level: str, event: str, **fields: Any) -> None:
    payload = {
        "ts": datetime.now(timezone.utc).isoformat(),
        "level": level.upper(),
        "event": event,
        **fields,
    }
    line = json.dumps(payload, ensure_ascii=True, default=str) + "\n"
    try:
        sys.stdout.write(line)
        sys.stdout.flush()
    except OSError:
        return
