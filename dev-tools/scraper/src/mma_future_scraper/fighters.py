from __future__ import annotations

from collections import Counter
from copy import deepcopy
import json
import re
from pathlib import Path
from typing import Any

from .config import DATA_DIR, LATEST_DIR
from .storage import atomic_write_json
from .utils import canonical_url, normalize_name, source_fighter_id_from_url, stable_hash, utc_now


FIGHTERS_INDEX_SCHEMA_VERSION = "derived_fighters_index_v0_1"
FIGHTER_FIGHT_LOGS_SCHEMA_VERSION = "derived_fighter_fight_logs_v0_1"
PROCESSOR_REPORT_SCHEMA_VERSION = "derived_fighter_processor_report_v0_1"

DERIVED_DIR = DATA_DIR / "derived"

FINISH_METHODS = {"ko_tko", "submission"}
VALID_RESULTS_FOR_FIGHTER = {"win", "loss", "draw", "no_contest", "unknown"}


def build_fighter_index_from_latest(
    *,
    results_path: Path = LATEST_DIR / "results.json",
    profiles_path: Path = LATEST_DIR / "fighter_profiles.json",
    derived_dir: Path = DERIVED_DIR,
) -> dict[str, Any]:
    results_doc = _read_json(results_path)
    profiles_doc = _read_json(profiles_path) if profiles_path.exists() else {}
    derived = build_fighter_documents(results_doc, profiles_doc=profiles_doc)
    validation_errors = validate_derived_outputs(derived)
    derived["processor_report"]["validation_errors"] = validation_errors
    derived["processor_report"]["validation_errors_count"] = len(validation_errors)

    derived_dir.mkdir(parents=True, exist_ok=True)
    atomic_write_json(derived_dir / "fighters_index.json", derived["fighters_index"])
    atomic_write_json(derived_dir / "fighter_fight_logs.json", derived["fighter_fight_logs"])
    atomic_write_json(derived_dir / "processor_report.json", derived["processor_report"])
    return {
        **derived,
        "paths": {
            "fighters_index": str(derived_dir / "fighters_index.json"),
            "fighter_fight_logs": str(derived_dir / "fighter_fight_logs.json"),
            "processor_report": str(derived_dir / "processor_report.json"),
        },
    }


def build_fighter_documents(results_doc: dict[str, Any], *, profiles_doc: dict[str, Any] | None = None) -> dict[str, Any]:
    generated_at = utc_now()
    source_run_id = results_doc.get("run", {}).get("run_id")
    source_results_schema_version = results_doc.get("schema_version")
    source_events = results_doc.get("events", [])
    known_event_ids = {event.get("source_event_id") for event in source_events if event.get("source_event_id")}
    known_bout_ids: set[str] = set()
    fighters: dict[str, dict[str, Any]] = {}
    fight_logs: dict[str, list[dict[str, Any]]] = {}
    seen_log_keys: set[tuple[str, str]] = set()
    report_counters: Counter[str] = Counter()
    missing_method_bouts: set[str] = set()
    missing_prefight_bouts: set[str] = set()
    missing_winner_loser_bouts: set[str] = set()
    missing_opponent_diff_rows = 0
    profile_index = _profile_index(profiles_doc or {})

    for event in source_events:
        for bout in event.get("bouts", []):
            if bout.get("status") != "completed" or bout.get("pro_am") != "professional":
                continue
            source_bout_id = bout.get("source_bout_id")
            if source_bout_id:
                known_bout_ids.add(source_bout_id)
            result = bout.get("result") or {}
            if result.get("method_category") in {None, "", "unknown"}:
                missing_method_bouts.add(source_bout_id or bout.get("bout_url") or "<missing_source_bout_id>")
            if bout.get("result_type") == "win_loss" and (
                bout.get("winner") in {None, "", "unknown"} or bout.get("loser") in {None, "", "unknown"}
            ):
                missing_winner_loser_bouts.add(source_bout_id or bout.get("bout_url") or "<missing_source_bout_id>")

            for role, opponent_role in [("fighter_a", "fighter_b"), ("fighter_b", "fighter_a")]:
                fighter = bout.get(role) or {}
                opponent = bout.get(opponent_role) or {}
                fighter_key = fighter_identity_key(fighter)
                if not fighter_key:
                    continue
                fighter_record = fighters.setdefault(fighter_key, _empty_fighter_record(fighter_key, fighter))
                _merge_fighter_identity(fighter_record, fighter)
                log_key = (fighter_key, source_bout_id or bout.get("bout_url") or "")
                if log_key in seen_log_keys:
                    continue
                seen_log_keys.add(log_key)

                result_for_fighter = result_for_role(bout, role)
                fighter_prefight = fighter.get("prefight_record")
                opponent_prefight = opponent.get("prefight_record")
                if _missing_prefight_record(fighter_prefight) or _missing_prefight_record(opponent_prefight):
                    missing_prefight_bouts.add(source_bout_id or bout.get("bout_url") or "<missing_source_bout_id>")
                opponent_prefight_diff = prefight_diff(opponent_prefight)
                if opponent_prefight_diff is None:
                    missing_opponent_diff_rows += 1

                log_item = {
                    "fighter_key": fighter_key,
                    "internal_event_id": event.get("internal_event_id"),
                    "source_event_id": event.get("source_event_id"),
                    "event_name": event.get("event_name"),
                    "event_date": event.get("event_date"),
                    "internal_bout_id": bout.get("internal_bout_id"),
                    "source_bout_id": source_bout_id,
                    "bout_url": bout.get("bout_url"),
                    "opponent_name": opponent.get("name"),
                    "opponent_url": canonical_url(opponent.get("url")),
                    "result_for_fighter": result_for_fighter,
                    "method_category": result.get("method_category"),
                    "method_detail": result.get("method_detail"),
                    "round": result.get("round"),
                    "time": result.get("time"),
                    "weight_lbs": bout.get("weight_lbs"),
                    "weight_class": bout.get("weight_class"),
                    "card_position": bout.get("card_position"),
                    "fighter_prefight_record": deepcopy(fighter_prefight),
                    "opponent_prefight_record": deepcopy(opponent_prefight),
                    "opponent_prefight_diff": opponent_prefight_diff,
                }
                fight_logs.setdefault(fighter_key, []).append(_prune_none(log_item))
                _update_fighter_counts(fighter_record, role, result_for_fighter, result.get("method_category"), event, log_item)

    for fighter_key, fighter_logs in fight_logs.items():
        fighter_logs.sort(key=lambda item: ((item.get("event_date") or ""), (item.get("source_bout_id") or "")), reverse=True)
        fighters[fighter_key]["name_variants"] = sorted(fighters[fighter_key]["name_variants"])
        _attach_record_sources(fighters[fighter_key], fighter_logs, profile_index.get(fighter_key))

    fighters = dict(sorted(fighters.items(), key=lambda item: (item[1].get("display_name") or "", item[0])))
    fight_logs = {fighter_key: fight_logs[fighter_key] for fighter_key in sorted(fight_logs)}
    total_log_rows = sum(len(items) for items in fight_logs.values())
    record_mismatches = [
        fighter_key
        for fighter_key, fighter in fighters.items()
        if (fighter.get("record_sources") or {}).get("record_mismatch")
    ]
    processor_report = {
        "schema_version": PROCESSOR_REPORT_SCHEMA_VERSION,
        "generated_at": generated_at,
        "source_run_id": source_run_id,
        "source_results_schema_version": source_results_schema_version,
        "total_unique_fighters": len(fighters),
        "total_fighter_fight_log_rows": total_log_rows,
        "fighters_with_at_least_1_win": sum(1 for fighter in fighters.values() if fighter.get("wins_in_dataset", 0) >= 1),
        "fighters_with_missing_fighter_url": sum(1 for fighter in fighters.values() if not fighter.get("fighter_source_url")),
        "bouts_missing_method_category": len(missing_method_bouts),
        "bouts_missing_prefight_records": len(missing_prefight_bouts),
        "bouts_missing_winner_loser_for_win_loss": len(missing_winner_loser_bouts),
        "fights_where_opponent_prefight_diff_cannot_be_calculated": missing_opponent_diff_rows,
        "fighters_with_tapology_profile_record": sum(
            1 for fighter in fighters.values() if (fighter.get("record_sources") or {}).get("tapology_profile_record")
        ),
        "fighters_with_record_mismatch": len(record_mismatches),
        "record_mismatch_fighter_keys": sorted(record_mismatches),
        "top_20_fighters_by_dataset_wins": _top_fighters(fighters, "wins_in_dataset"),
        "top_20_fighters_by_dataset_finishes": _top_fighters(fighters, "finishes_in_dataset"),
        "known_event_ids_count": len(known_event_ids),
        "known_bout_ids_count": len(known_bout_ids),
        "known_event_ids": sorted(known_event_ids),
        "known_bout_ids": sorted(known_bout_ids),
    }
    return {
        "fighters_index": {
            "schema_version": FIGHTERS_INDEX_SCHEMA_VERSION,
            "generated_at": generated_at,
            "source_run_id": source_run_id,
            "fighters": fighters,
        },
        "fighter_fight_logs": {
            "schema_version": FIGHTER_FIGHT_LOGS_SCHEMA_VERSION,
            "generated_at": generated_at,
            "source_run_id": source_run_id,
            "fight_logs": fight_logs,
        },
        "processor_report": processor_report,
    }


def validate_derived_outputs(derived: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    fighters = derived.get("fighters_index", {}).get("fighters", {})
    fight_logs = derived.get("fighter_fight_logs", {}).get("fight_logs", {})
    report = derived.get("processor_report", {})
    known_events: set[str] = set(report.get("known_event_ids") or [])
    known_bouts: set[str] = set(report.get("known_bout_ids") or [])
    if not known_events or not known_bouts:
        for items in fight_logs.values():
            for item in items:
                if item.get("source_event_id"):
                    known_events.add(item["source_event_id"])
                if item.get("source_bout_id"):
                    known_bouts.add(item["source_bout_id"])

    for fighter_key, fighter in fighters.items():
        if not fighter_key:
            errors.append("missing_fighter_key")
        if fighter.get("fighter_key") != fighter_key:
            errors.append(f"fighter_key_mismatch:{fighter_key}")
        if fighter_key not in fight_logs:
            errors.append(f"fighter_without_fight_log:{fighter_key}")

    seen_rows: set[tuple[str, str]] = set()
    rows_by_bout: dict[str, list[dict[str, Any]]] = {}
    for fighter_key, items in fight_logs.items():
        if fighter_key not in fighters:
            errors.append(f"fight_log_unknown_fighter:{fighter_key}")
        for index, item in enumerate(items):
            path = f"{fighter_key}[{index}]"
            if item.get("fighter_key") != fighter_key:
                errors.append(f"fight_log_fighter_key_mismatch:{path}")
            source_bout_id = item.get("source_bout_id")
            source_event_id = item.get("source_event_id")
            if not source_bout_id:
                errors.append(f"fight_log_missing_source_bout_id:{path}")
            if not source_event_id:
                errors.append(f"fight_log_missing_source_event_id:{path}")
            if source_event_id and source_event_id not in known_events:
                errors.append(f"fight_log_unknown_event:{path}:{source_event_id}")
            if source_bout_id and source_bout_id not in known_bouts:
                errors.append(f"fight_log_unknown_bout:{path}:{source_bout_id}")
            row_key = (fighter_key, source_bout_id or "")
            if row_key in seen_rows:
                errors.append(f"duplicate_fight_log_row:{fighter_key}:{source_bout_id}")
            seen_rows.add(row_key)
            if item.get("result_for_fighter") not in VALID_RESULTS_FOR_FIGHTER:
                errors.append(f"bad_result_for_fighter:{path}:{item.get('result_for_fighter')}")
            if source_bout_id:
                rows_by_bout.setdefault(source_bout_id, []).append(item)
    for source_bout_id, rows in rows_by_bout.items():
        results = sorted(item.get("result_for_fighter") for item in rows)
        if len(rows) != 2:
            errors.append(f"bad_fight_log_row_count_for_bout:{source_bout_id}:{len(rows)}")
        elif results not in [["draw", "draw"], ["loss", "win"], ["no_contest", "no_contest"], ["unknown", "unknown"]]:
            errors.append(f"inconsistent_result_for_fighter:{source_bout_id}:{','.join(results)}")
    return errors


def fighter_identity_key(fighter: dict[str, Any]) -> str | None:
    url = canonical_url(fighter.get("url"))
    source_id = _numeric_fighter_source_id(fighter.get("source_fighter_id")) or fighter_source_id_from_url(url)
    if source_id:
        return f"tapology_fighter_{source_id}"
    if url:
        return f"tapology_fighter_url_{stable_hash(url, 16)}"
    name = normalize_name(fighter.get("name"))
    if name:
        return f"missing_url_{stable_hash(name, 16)}"
    return None


def fighter_source_id_from_url(url: str | None) -> str | None:
    return _numeric_fighter_source_id(source_fighter_id_from_url(url))


def result_for_role(bout: dict[str, Any], role: str) -> str:
    result_type = bout.get("result_type")
    if result_type == "draw":
        return "draw"
    if result_type == "no_contest":
        return "no_contest"
    if result_type == "win_loss":
        if bout.get("winner") == role:
            return "win"
        if bout.get("loser") == role:
            return "loss"
    return "unknown"


def prefight_diff(record: dict[str, Any] | None) -> int | None:
    if not isinstance(record, dict) or record.get("is_missing"):
        return None
    wins = record.get("wins")
    losses = record.get("losses")
    if not isinstance(wins, int) or not isinstance(losses, int):
        return None
    return wins - losses


def format_processor_summary(report: dict[str, Any], paths: dict[str, str] | None = None) -> str:
    paths = paths or {}
    lines = [
        "Fighter index build",
        f"  Source run: {report.get('source_run_id')}",
        f"  Unique fighters: {report.get('total_unique_fighters')}",
        f"  Fight log rows: {report.get('total_fighter_fight_log_rows')}",
        f"  Fighters with wins: {report.get('fighters_with_at_least_1_win')}",
        f"  Fighters missing URL: {report.get('fighters_with_missing_fighter_url')}",
        f"  Bouts missing method_category: {report.get('bouts_missing_method_category')}",
        f"  Bouts missing prefight records: {report.get('bouts_missing_prefight_records')}",
        f"  Bouts missing winner/loser: {report.get('bouts_missing_winner_loser_for_win_loss')}",
        f"  Opponent prefight diff unavailable: {report.get('fights_where_opponent_prefight_diff_cannot_be_calculated')}",
        f"  Validation errors: {report.get('validation_errors_count', 0)}",
    ]
    if paths:
        lines.extend(
            [
                f"  fighters_index: {paths.get('fighters_index')}",
                f"  fighter_fight_logs: {paths.get('fighter_fight_logs')}",
                f"  processor_report: {paths.get('processor_report')}",
            ]
        )
    return "\n".join(lines)


def _empty_fighter_record(fighter_key: str, fighter: dict[str, Any]) -> dict[str, Any]:
    url = canonical_url(fighter.get("url"))
    name = fighter.get("name")
    return {
        "fighter_key": fighter_key,
        "fighter_source_url": url,
        "fighter_source_id": fighter_source_id_from_url(url),
        "display_name": name,
        "name_variants": set([name] if name else []),
        "bouts_count_in_dataset": 0,
        "wins_in_dataset": 0,
        "losses_in_dataset": 0,
        "draws_in_dataset": 0,
        "nc_in_dataset": 0,
        "finishes_in_dataset": 0,
        "ko_tko_wins_in_dataset": 0,
        "submission_wins_in_dataset": 0,
        "decision_wins_in_dataset": 0,
        "last_fight_date_in_dataset": None,
        "last_fight_event": None,
        "last_fight_result": None,
        "seen_as_fighter_a_count": 0,
        "seen_as_fighter_b_count": 0,
    }


def _merge_fighter_identity(record: dict[str, Any], fighter: dict[str, Any]) -> None:
    url = canonical_url(fighter.get("url"))
    name = fighter.get("name")
    if url and not record.get("fighter_source_url"):
        record["fighter_source_url"] = url
        record["fighter_source_id"] = _numeric_fighter_source_id(fighter.get("source_fighter_id")) or fighter_source_id_from_url(url)
    if name:
        record["name_variants"].add(name)
        if not record.get("display_name"):
            record["display_name"] = name


def _update_fighter_counts(
    fighter: dict[str, Any],
    role: str,
    result_for_fighter: str,
    method_category: str | None,
    event: dict[str, Any],
    log_item: dict[str, Any],
) -> None:
    fighter["bouts_count_in_dataset"] += 1
    if role == "fighter_a":
        fighter["seen_as_fighter_a_count"] += 1
    elif role == "fighter_b":
        fighter["seen_as_fighter_b_count"] += 1
    if result_for_fighter == "win":
        fighter["wins_in_dataset"] += 1
        if method_category == "ko_tko":
            fighter["ko_tko_wins_in_dataset"] += 1
        elif method_category == "submission":
            fighter["submission_wins_in_dataset"] += 1
        elif method_category == "decision":
            fighter["decision_wins_in_dataset"] += 1
        if method_category in FINISH_METHODS:
            fighter["finishes_in_dataset"] += 1
    elif result_for_fighter == "loss":
        fighter["losses_in_dataset"] += 1
    elif result_for_fighter == "draw":
        fighter["draws_in_dataset"] += 1
    elif result_for_fighter == "no_contest":
        fighter["nc_in_dataset"] += 1
    event_date = event.get("event_date")
    if event_date and (fighter.get("last_fight_date_in_dataset") is None or event_date >= fighter["last_fight_date_in_dataset"]):
        fighter["last_fight_date_in_dataset"] = event_date
        fighter["last_fight_event"] = event.get("event_name")
        fighter["last_fight_result"] = result_for_fighter


def _attach_record_sources(
    fighter: dict[str, Any],
    fighter_logs: list[dict[str, Any]],
    profile: dict[str, Any] | None,
) -> None:
    tapology_profile_record = _tapology_profile_record(profile)
    latest_prefight_record = _latest_prefight_record(fighter_logs)
    computed_record = _computed_record_from_fighter(fighter)
    mismatch = _record_mismatch(tapology_profile_record, computed_record)
    warnings: list[str] = []
    if tapology_profile_record is None:
        warnings.append("missing_tapology_profile_record")
    if latest_prefight_record is None:
        warnings.append("missing_prefight_record_source")
    if mismatch:
        warnings.append("tapology_profile_record_mismatch")
    fighter["tapology_profile_record"] = deepcopy(tapology_profile_record)
    fighter["prefight_record_latest"] = deepcopy(latest_prefight_record)
    fighter["computed_record_from_imported_bouts"] = deepcopy(computed_record)
    fighter["record_sources"] = {
        "tapology_profile_record": deepcopy(tapology_profile_record),
        "prefight_record_latest": deepcopy(latest_prefight_record),
        "computed_record_from_imported_bouts": deepcopy(computed_record),
        "record_mismatch": mismatch,
        "warnings": warnings,
    }


def _tapology_profile_record(profile: dict[str, Any] | None) -> dict[str, Any] | None:
    if not isinstance(profile, dict) or profile.get("status") != "success":
        return None
    record = profile.get("record_summary")
    if not isinstance(record, dict):
        return None
    normalized = _normalize_record(
        raw=record.get("pro_record_raw"),
        wins=record.get("wins"),
        losses=record.get("losses"),
        draws=record.get("draws"),
        no_contests=record.get("no_contests"),
    )
    if normalized is None:
        return None
    normalized.update(
        {
            "source": "tapology_profile_record",
            "source_fighter_id": profile.get("source_fighter_id"),
            "source_url": canonical_url(profile.get("source_url")),
            "scraped_at": profile.get("scraped_at"),
        }
    )
    return _prune_none(normalized)


def _latest_prefight_record(fighter_logs: list[dict[str, Any]]) -> dict[str, Any] | None:
    for row in fighter_logs:
        record = row.get("fighter_prefight_record")
        if not isinstance(record, dict) or record.get("is_missing"):
            continue
        normalized = _normalize_record(
            raw=record.get("raw"),
            wins=record.get("wins"),
            losses=record.get("losses"),
            draws=record.get("draws"),
            no_contests=record.get("nc"),
        )
        if normalized is None:
            continue
        normalized.update(
            {
                "source": "prefight_record_from_bout",
                "source_event_id": row.get("source_event_id"),
                "source_bout_id": row.get("source_bout_id"),
                "event_date": row.get("event_date"),
            }
        )
        return _prune_none(normalized)
    return None


def _computed_record_from_fighter(fighter: dict[str, Any]) -> dict[str, Any]:
    wins = int(fighter.get("wins_in_dataset") or 0)
    losses = int(fighter.get("losses_in_dataset") or 0)
    draws = int(fighter.get("draws_in_dataset") or 0)
    no_contests = int(fighter.get("nc_in_dataset") or 0)
    return {
        "source": "computed_record_from_imported_bouts",
        "wins": wins,
        "losses": losses,
        "draws": draws,
        "no_contests": no_contests,
        "total": wins + losses + draws + no_contests,
    }


def _normalize_record(
    *,
    raw: Any,
    wins: Any,
    losses: Any,
    draws: Any,
    no_contests: Any,
) -> dict[str, Any] | None:
    if not isinstance(wins, int) or not isinstance(losses, int):
        return None
    draws = draws if isinstance(draws, int) else 0
    no_contests = no_contests if isinstance(no_contests, int) else 0
    return {
        "raw": raw,
        "wins": wins,
        "losses": losses,
        "draws": draws,
        "no_contests": no_contests,
        "total": wins + losses + draws + no_contests,
    }


def _record_mismatch(profile_record: dict[str, Any] | None, computed_record: dict[str, Any]) -> bool:
    if profile_record is None:
        return False
    for key in ("wins", "losses", "draws", "no_contests"):
        if profile_record.get(key) != computed_record.get(key):
            return True
    return False


def _profile_index(profiles_doc: dict[str, Any]) -> dict[str, dict[str, Any]]:
    profiles: dict[str, dict[str, Any]] = {}
    for profile in profiles_doc.get("profiles", []) if isinstance(profiles_doc, dict) else []:
        if not isinstance(profile, dict):
            continue
        source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(profile.get("source_url"))
        if source_fighter_id:
            profiles[str(source_fighter_id)] = profile
    return profiles


def _missing_prefight_record(record: dict[str, Any] | None) -> bool:
    return not isinstance(record, dict) or bool(record.get("is_missing")) or not isinstance(record.get("wins"), int) or not isinstance(record.get("losses"), int)


def _numeric_fighter_source_id(source_fighter_id: str | None) -> str | None:
    if not source_fighter_id:
        return None
    match = re.fullmatch(r"tapology_fighter_(\d+)", source_fighter_id)
    return match.group(1) if match else None


def _top_fighters(fighters: dict[str, dict[str, Any]], field: str) -> list[dict[str, Any]]:
    rows = [
        {
            "fighter_key": fighter_key,
            "display_name": fighter.get("display_name"),
            field: fighter.get(field, 0),
            "bouts_count_in_dataset": fighter.get("bouts_count_in_dataset", 0),
            "wins_in_dataset": fighter.get("wins_in_dataset", 0),
        }
        for fighter_key, fighter in fighters.items()
        if fighter.get(field, 0) > 0
    ]
    rows.sort(key=lambda item: (-int(item[field]), item.get("display_name") or "", item["fighter_key"]))
    return rows[:20]


def _prune_none(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: _prune_none(item) for key, item in value.items() if item is not None}
    if isinstance(value, list):
        return [_prune_none(item) for item in value]
    return value


def _read_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)
