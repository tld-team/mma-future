"""Read-only canonical bouts dump for the Fight History Staging Dry-Run.

This script issues SELECT-only queries against the WordPress MySQL database
via the ``mysql`` CLI client (no extra Python dependency required) and writes
a normalized JSON snapshot to ``scraper/data/latest/canonical_bouts_dump.json``.

It NEVER issues writes. The query script is generated inline with strict
``SELECT`` statements only and the script aborts with a hard error if any
unexpected output is produced.

Usage::

    python scripts/dump_canonical_bouts.py \
        --mysql-binary "C:/Users/.../mysql.exe" \
        --host 127.0.0.1 --port 10030 \
        --user root --password root --database local
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUTPUT = REPO_ROOT / "data" / "latest" / "canonical_bouts_dump.json"

DEFAULT_MYSQL_BINARY = Path(
    "C:/Users/lukam/AppData/Roaming/Local/lightning-services/mysql-8.0.35+2/bin/win64/bin/mysql.exe"
)


SCHEMA_VERSION = "canonical_bouts_dump_v0_1"


SELECT_BOUTS_SQL = """
SELECT
  b.id AS bout_id,
  b.event_id AS event_id,
  e.event_name AS event_name,
  e.event_date AS event_date,
  e.status AS event_status,
  b.status AS bout_status,
  b.result_type AS result_type,
  b.method_category AS method_category
FROM wp_mmaf_bouts b
LEFT JOIN wp_mmaf_events e ON e.id = b.event_id
WHERE b.deleted_soft = 0
;
""".strip()

SELECT_BOUT_SOURCES_SQL = """
SELECT
  bs.bout_id AS bout_id,
  bs.source_type AS source_type,
  bs.source_bout_id AS source_bout_id
FROM wp_mmaf_bout_sources bs
;
""".strip()

SELECT_EVENT_SOURCES_SQL = """
SELECT
  es.event_id AS event_id,
  es.source_type AS source_type,
  es.source_event_id AS source_event_id
FROM wp_mmaf_event_sources es
;
""".strip()

SELECT_BOUT_PARTICIPANTS_SQL = """
SELECT
  bp.bout_id AS bout_id,
  bp.fighter_id AS fighter_id,
  bp.participant_role AS participant_role,
  bp.source_fighter_id AS participant_source_fighter_id,
  f.display_name AS fighter_name
FROM wp_mmaf_bout_participants bp
LEFT JOIN wp_mmaf_fighters f ON f.id = bp.fighter_id
;
""".strip()

SELECT_FIGHTER_SOURCES_SQL = """
SELECT
  fs.fighter_id AS fighter_id,
  fs.source_type AS source_type,
  fs.source_fighter_id AS source_fighter_id
FROM wp_mmaf_fighter_sources fs
;
""".strip()


def _run_mysql(binary: Path, args: list[str], sql: str) -> str:
    cmd = [str(binary), *args, "--batch", "--raw", "--default-character-set=utf8mb4", "-e", sql]
    result = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    if result.returncode != 0:
        sys.stderr.write(result.stderr or "")
        raise SystemExit(f"mysql failed (exit {result.returncode})")
    return result.stdout


def _parse_tsv(output: str) -> list[dict[str, str]]:
    lines = [line for line in output.splitlines() if line]
    if not lines:
        return []
    headers = lines[0].split("\t")
    rows: list[dict[str, str]] = []
    for raw in lines[1:]:
        fields = raw.split("\t")
        row = {headers[i]: (fields[i] if i < len(fields) else "") for i in range(len(headers))}
        rows.append(row)
    return rows


def _value(row: dict[str, str], key: str) -> str | None:
    value = row.get(key)
    if value is None:
        return None
    if value == "NULL":
        return None
    return value


def dump_canonical_bouts(
    *,
    mysql_binary: Path,
    host: str,
    port: int,
    user: str,
    password: str,
    database: str,
) -> dict[str, Any]:
    args = [
        "-h",
        host,
        "-P",
        str(port),
        "-u",
        user,
        f"-p{password}",
        database,
    ]

    bouts_rows = _parse_tsv(_run_mysql(mysql_binary, args, SELECT_BOUTS_SQL))
    bout_sources_rows = _parse_tsv(_run_mysql(mysql_binary, args, SELECT_BOUT_SOURCES_SQL))
    event_sources_rows = _parse_tsv(_run_mysql(mysql_binary, args, SELECT_EVENT_SOURCES_SQL))
    participants_rows = _parse_tsv(_run_mysql(mysql_binary, args, SELECT_BOUT_PARTICIPANTS_SQL))
    fighter_sources_rows = _parse_tsv(_run_mysql(mysql_binary, args, SELECT_FIGHTER_SOURCES_SQL))

    bout_source_ids: dict[str, list[str]] = defaultdict(list)
    for row in bout_sources_rows:
        bout_id = _value(row, "bout_id")
        if not bout_id:
            continue
        src_bout = _value(row, "source_bout_id")
        if src_bout:
            bout_source_ids[bout_id].append(src_bout)

    event_source_ids: dict[str, list[str]] = defaultdict(list)
    for row in event_sources_rows:
        event_id = _value(row, "event_id")
        if not event_id:
            continue
        src_event = _value(row, "source_event_id")
        if src_event:
            event_source_ids[event_id].append(src_event)

    fighter_source_ids: dict[str, list[str]] = defaultdict(list)
    for row in fighter_sources_rows:
        fighter_id = _value(row, "fighter_id")
        if not fighter_id:
            continue
        src = _value(row, "source_fighter_id")
        if src:
            fighter_source_ids[fighter_id].append(src)

    bout_fighters: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in participants_rows:
        bout_id = _value(row, "bout_id")
        if not bout_id:
            continue
        fighter_id = _value(row, "fighter_id")
        participant_source_id = _value(row, "participant_source_fighter_id")
        canonical_source_ids = fighter_source_ids.get(fighter_id, []) if fighter_id else []
        merged_source_ids = list(dict.fromkeys((canonical_source_ids or []) + ([participant_source_id] if participant_source_id else [])))
        bout_fighters[bout_id].append(
            {
                "fighter_id": int(fighter_id) if fighter_id else None,
                "fighter_name": _value(row, "fighter_name"),
                "participant_role": _value(row, "participant_role"),
                "source_fighter_ids": merged_source_ids,
            }
        )

    bouts: list[dict[str, Any]] = []
    for row in bouts_rows:
        bout_id = _value(row, "bout_id")
        event_id = _value(row, "event_id") or ""
        participants = bout_fighters.get(bout_id, [])
        all_fighter_source_ids: list[str] = []
        fighter_names: list[str] = []
        for p in participants:
            for sfid in p["source_fighter_ids"]:
                if sfid and sfid not in all_fighter_source_ids:
                    all_fighter_source_ids.append(sfid)
            if p.get("fighter_name"):
                fighter_names.append(p["fighter_name"])
        bouts.append(
            {
                "bout_id": int(bout_id) if bout_id else None,
                "event_id": int(event_id) if event_id else None,
                "event_name": _value(row, "event_name"),
                "event_date": _value(row, "event_date"),
                "event_status": _value(row, "event_status"),
                "bout_status": _value(row, "bout_status"),
                "result_type": _value(row, "result_type"),
                "method_category": _value(row, "method_category"),
                "source_bout_ids": list(dict.fromkeys(bout_source_ids.get(bout_id, []))),
                "source_event_ids": list(dict.fromkeys(event_source_ids.get(event_id, []))),
                "fighter_source_ids": all_fighter_source_ids,
                "fighter_names": fighter_names,
                "participants": participants,
            }
        )

    return {
        "schema_version": SCHEMA_VERSION,
        "generated_at": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat(),
        "totals": {
            "bouts": len(bouts),
            "bout_sources": len(bout_sources_rows),
            "event_sources": len(event_sources_rows),
            "participants": len(participants_rows),
            "fighter_sources": len(fighter_sources_rows),
        },
        "bouts": bouts,
        "read_only": True,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mysql-binary", type=Path, default=DEFAULT_MYSQL_BINARY)
    parser.add_argument("--host", default=os.environ.get("MMAF_DB_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("MMAF_DB_PORT", "10030")))
    parser.add_argument("--user", default=os.environ.get("MMAF_DB_USER", "root"))
    parser.add_argument("--password", default=os.environ.get("MMAF_DB_PASSWORD", "root"))
    parser.add_argument("--database", default=os.environ.get("MMAF_DB_NAME", "local"))
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args(argv)

    if not args.mysql_binary.exists():
        sys.stderr.write(f"mysql binary not found at {args.mysql_binary}\n")
        return 2

    dump = dump_canonical_bouts(
        mysql_binary=args.mysql_binary,
        host=args.host,
        port=args.port,
        user=args.user,
        password=args.password,
        database=args.database,
    )
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(dump, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(
        json.dumps(
            {
                "schema_version": dump["schema_version"],
                "totals": dump["totals"],
                "output": str(args.output),
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
