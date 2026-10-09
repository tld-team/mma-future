from __future__ import annotations

import json
import statistics
from collections import Counter
from copy import deepcopy
from pathlib import Path
from typing import Any

from .config import LATEST_DIR
from .storage import DataStore, atomic_write_json
from .utils import utc_now


FIGHTER_PROFILES_REPORT_SCHEMA_VERSION = "tapology_fighter_profiles_report_v0_1"

KEY_PROFILE_FIELDS = [
    ("date_of_birth", "exact DOB"),
    ("pro_record_raw", "pro record"),
    ("fight_history", "fight history"),
    ("image_url", "image"),
    ("height", "height"),
    ("reach", "reach"),
    ("weight_or_weight_class", "weight/weight class"),
    ("association", "association/team"),
    ("location", "born/fighting-out location"),
]


def add_profile_completeness_scores(document: dict[str, Any]) -> dict[str, Any]:
    document = deepcopy(document)
    for profile in document.get("profiles", []):
        if isinstance(profile, dict):
            profile["profile_completeness_score"] = profile_completeness_score(profile)
    return document


def profile_completeness_score(profile: dict[str, Any]) -> int:
    summary = profile.get("profile_summary") or {}
    record = profile.get("record_summary") or {}
    history = profile.get("fight_history") or []
    parsed_record = _has_parsed_pro_record(record)

    score = 0
    if summary.get("date_of_birth"):
        score += 3
    if parsed_record:
        score += 2
    if history:
        score += 2
    if len(history) >= 10:
        score += 2
    if summary.get("image_url"):
        score += 1
    if summary.get("height"):
        score += 1
    if summary.get("reach"):
        score += 1
    if summary.get("weight") or summary.get("weight_class"):
        score += 1
    if summary.get("association"):
        score += 1
    if summary.get("born_location") or summary.get("fighting_out_of"):
        score += 1
    if profile.get("status") != "success":
        score -= 3
    if not history:
        score -= 2
    if not parsed_record:
        score -= 2
    return score


def build_profile_enrichment_report(document: dict[str, Any]) -> dict[str, Any]:
    scored_document = add_profile_completeness_scores(document)
    profiles = [profile for profile in scored_document.get("profiles", []) if isinstance(profile, dict)]
    successful_profiles = [profile for profile in profiles if profile.get("status") == "success"]
    failed_profiles = [profile for profile in profiles if profile.get("status") != "success"]
    status_counts = Counter(str(profile.get("status") or "unknown") for profile in profiles)
    warning_counts = Counter(
        warning
        for profile in profiles
        for warning in _profile_warnings(profile)
    )
    history_rows = [
        row
        for profile in successful_profiles
        for row in profile.get("fight_history", [])
        if isinstance(row, dict)
    ]
    history_lengths = [
        len([row for row in profile.get("fight_history", []) if isinstance(row, dict)])
        for profile in successful_profiles
    ]

    report = {
        "schema_version": FIGHTER_PROFILES_REPORT_SCHEMA_VERSION,
        "source": scored_document.get("source") or "tapology",
        "run_id": scored_document.get("run_id"),
        "scraped_at": scored_document.get("scraped_at") or utc_now(),
        "generated_at": utc_now(),
        "profiles_total": len(profiles),
        "profiles_success": len(successful_profiles),
        "profiles_failed": len(failed_profiles),
        "profiles_blocked": status_counts.get("blocked", 0),
        "profiles_not_found": status_counts.get("not_found", 0),
        "profiles_failed_parse": status_counts.get("failed_parse", 0),
        "success_rate": _ratio(len(successful_profiles), len(profiles)),
        "input": deepcopy(scored_document.get("input") or {}),
        "merge_stats": deepcopy(scored_document.get("merge_stats") or {}),
        "status_counts": dict(sorted(status_counts.items())),
        "coverage": {
            "identity": _identity_coverage(profiles),
            "profile": _profile_coverage(successful_profiles),
            "record": _record_coverage(successful_profiles),
            "fight_history": _fight_history_coverage(successful_profiles, history_lengths),
            "fight_history_rows": _fight_history_row_coverage(history_rows),
            "warnings": _warning_coverage(profiles, warning_counts),
        },
        "examples": {
            "high_completeness": _example_profiles(sorted(successful_profiles, key=_score_sort_key, reverse=True)[:5]),
            "low_completeness": _example_profiles(sorted(profiles, key=_score_sort_key)[:5]),
            "warnings_or_failures": _example_profiles(_warning_failure_examples(profiles)[:5]),
        },
        "profiles": [
            {
                "source_fighter_id": profile.get("source_fighter_id"),
                "display_name": profile.get("display_name"),
                "source_url": profile.get("source_url"),
                "status": profile.get("status"),
                "profile_completeness_score": profile.get("profile_completeness_score"),
                "missing_key_fields": missing_key_fields(profile),
                "warnings": _profile_warnings(profile),
                "expected_total_fights_from_profile_record": _expected_total_fights_from_profile_record(profile),
                "history_rows_total": len([row for row in profile.get("fight_history") or [] if isinstance(row, dict)]),
                "history_completeness_ratio": _history_completeness_ratio(profile),
            }
            for profile in profiles
        ],
    }
    return report


def _expected_total_fights_from_profile_record(profile: dict[str, Any]) -> int | None:
    record = profile.get("record_summary") or {}
    parts = [record.get(field) for field in ("wins", "losses", "draws", "no_contests")]
    if not all(isinstance(part, int) for part in parts):
        return None
    return sum(parts)  # type: ignore[arg-type]


def _history_completeness_ratio(profile: dict[str, Any]) -> float | None:
    expected = _expected_total_fights_from_profile_record(profile)
    if not expected:
        return None
    history_rows = [row for row in profile.get("fight_history") or [] if isinstance(row, dict)]
    extracted = sum(1 for row in history_rows if not row.get("is_cancelled") and not row.get("is_upcoming"))
    return round(extracted / expected, 4)


def write_profile_enrichment_report(
    document: dict[str, Any],
    *,
    run_dir: Path | None = None,
    latest_dir: Path = LATEST_DIR,
    write_markdown: bool = True,
) -> tuple[dict[str, Any], dict[str, Path]]:
    report = build_profile_enrichment_report(document)
    store = DataStore()
    if run_dir is None and report.get("run_id"):
        run_dir = store.runs_dir / str(report["run_id"])

    paths: dict[str, Path] = {}
    if run_dir is not None:
        atomic_write_json(run_dir / "fighter_profiles_report.json", report)
        paths["run_json"] = run_dir / "fighter_profiles_report.json"
    atomic_write_json(latest_dir / "fighter_profiles_report.json", report)
    paths["latest_json"] = latest_dir / "fighter_profiles_report.json"
    if write_markdown:
        markdown = format_profile_enrichment_report_markdown(report)
        _atomic_write_text(latest_dir / "fighter_profiles_report.md", markdown)
        paths["latest_markdown"] = latest_dir / "fighter_profiles_report.md"
    return report, paths


def build_profile_enrichment_report_from_path(path: Path) -> dict[str, Any]:
    return build_profile_enrichment_report(json.loads(path.read_text(encoding="utf-8")))


def missing_key_fields(profile: dict[str, Any]) -> list[str]:
    summary = profile.get("profile_summary") or {}
    record = profile.get("record_summary") or {}
    history = profile.get("fight_history") or []
    checks = {
        "date_of_birth": bool(summary.get("date_of_birth")),
        "pro_record_raw": bool(record.get("pro_record_raw")),
        "parsed_pro_record": _has_parsed_pro_record(record),
        "fight_history": bool(history),
        "image_url": bool(summary.get("image_url")),
        "height": bool(summary.get("height")),
        "reach": bool(summary.get("reach")),
        "weight_or_weight_class": bool(summary.get("weight") or summary.get("weight_class")),
        "association": bool(summary.get("association")),
        "location": bool(summary.get("born_location") or summary.get("fighting_out_of")),
    }
    return [name for name, present in checks.items() if not present]


def format_profile_enrichment_report_markdown(report: dict[str, Any]) -> str:
    coverage = report.get("coverage") or {}
    profile = coverage.get("profile") or {}
    record = coverage.get("record") or {}
    history = coverage.get("fight_history") or {}
    rows = coverage.get("fight_history_rows") or {}
    warnings = coverage.get("warnings") or {}
    lines = [
        "# Tapology Fighter Profile Enrichment Coverage",
        "",
        f"- Run ID: `{report.get('run_id')}`",
        f"- Scraped at: `{report.get('scraped_at')}`",
        f"- Profiles: {report.get('profiles_success')}/{report.get('profiles_total')} successful ({_pct(report.get('success_rate'))})",
        f"- Blocked: {report.get('profiles_blocked')} | Not found: {report.get('profiles_not_found')} | Failed parse: {report.get('profiles_failed_parse')}",
        f"- Selected for enrichment: {(report.get('input') or {}).get('selected_for_enrichment')}",
    ]
    merge_stats = report.get("merge_stats") or {}
    if merge_stats:
        lines.extend(
            [
                f"- Updated cache entries: {merge_stats.get('updated', 0)}",
                f"- Inserted cache entries: {merge_stats.get('inserted', 0)}",
                f"- Retained untouched cache entries: {merge_stats.get('retained_untouched', 0)}",
                f"- Retained previous success after failed refresh: {merge_stats.get('retained_previous_success_on_failure', 0)}",
                f"- Failed without previous success: {merge_stats.get('failed_without_previous_success', 0)}",
            ]
        )
    lines.extend(
        [
            "",
            "## Key Profile Coverage",
            "",
            f"- Exact DOB: {_metric_line(profile.get('with_exact_dob'))}",
            f"- Birth year: {_metric_line(profile.get('with_birth_year'))}",
            f"- Born location: {_metric_line(profile.get('with_born_location'))}",
            f"- Fighting out of: {_metric_line(profile.get('with_fighting_out_of'))}",
            f"- Association/team: {_metric_line(profile.get('with_association_team'))}",
            f"- Height: {_metric_line(profile.get('with_height'))}",
            f"- Reach: {_metric_line(profile.get('with_reach'))}",
            f"- Weight class: {_metric_line(profile.get('with_weight_class'))}",
            "",
            "## Record And History Coverage",
            "",
            f"- Pro record raw: {_metric_line(record.get('with_pro_record_raw'))}",
            f"- Parsed W/L/D/NC: {_metric_line(record.get('with_parsed_wins_losses_draws_no_contests'))}",
            f"- Fight history present: {_metric_line(history.get('with_fight_history_rows'))}",
            f"- Total history rows: {history.get('total_fight_history_rows', 0)}",
            f"- Average rows per successful profile: {history.get('average_history_rows_per_successful_profile', 0)}",
            f"- Median rows: {history.get('median_history_rows', 0)}",
            f"- Max rows: {history.get('max_history_rows', 0)}",
            "",
            "## Row Field Coverage",
            "",
            f"- Opponent URL: {_metric_line(rows.get('rows_with_opponent_url'))}",
            f"- Event URL: {_metric_line(rows.get('rows_with_event_url'))}",
            f"- Bout URL: {_metric_line(rows.get('rows_with_bout_url'))}",
            f"- Opponent/prefight record: {_metric_line(rows.get('rows_with_opponent_or_prefight_current_record'))}",
            f"- Method: {_metric_line(rows.get('rows_with_method'))}",
            f"- Weight class: {_metric_line(rows.get('rows_with_weight_class'))}",
            "",
            "## Warnings",
            "",
            f"- Profiles with warnings: {_metric_line(warnings.get('profiles_with_warnings'))}",
            f"- Profiles without warnings: {_metric_line(warnings.get('profiles_without_warnings'))}",
        ]
    )
    for warning in warnings.get("top_warning_types", [])[:10]:
        lines.append(f"- {warning.get('type')}: {warning.get('count')}")
    lines.extend(["", "## Examples", ""])
    for title, key in [
        ("High completeness", "high_completeness"),
        ("Low completeness", "low_completeness"),
        ("Warnings or failures", "warnings_or_failures"),
    ]:
        lines.append(f"### {title}")
        for example in (report.get("examples") or {}).get(key, []):
            lines.append(
                "- "
                f"{example.get('display_name') or example.get('source_fighter_id')}: "
                f"score {example.get('profile_completeness_score')}, "
                f"missing {', '.join(example.get('missing_key_fields') or []) or 'none'}, "
                f"warnings {', '.join(example.get('warnings') or []) or 'none'}"
            )
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def _identity_coverage(profiles: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "with_source_fighter_id": _metric(sum(1 for profile in profiles if profile.get("source_fighter_id")), len(profiles)),
        "with_source_url": _metric(sum(1 for profile in profiles if profile.get("source_url")), len(profiles)),
        "with_display_name": _metric(sum(1 for profile in profiles if profile.get("display_name")), len(profiles)),
        "with_nickname": _metric(sum(1 for profile in profiles if (profile.get("profile_summary") or {}).get("nickname")), len(profiles)),
        "with_image_url": _metric(sum(1 for profile in profiles if (profile.get("profile_summary") or {}).get("image_url")), len(profiles)),
    }


def _profile_coverage(profiles: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "with_exact_dob": _summary_metric(profiles, "date_of_birth"),
        "with_age": _summary_metric(profiles, "age"),
        "with_birth_year": _summary_metric(profiles, "birth_year"),
        "with_born_location": _summary_metric(profiles, "born_location"),
        "with_fighting_out_of": _summary_metric(profiles, "fighting_out_of"),
        "with_association_team": _summary_metric(profiles, "association"),
        "with_height": _summary_metric(profiles, "height"),
        "with_reach": _summary_metric(profiles, "reach"),
        "with_weight": _summary_metric(profiles, "weight"),
        "with_last_weigh_in": _summary_metric(profiles, "last_weigh_in"),
        "with_weight_class": _summary_metric(profiles, "weight_class"),
    }


def _record_coverage(profiles: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "with_pro_record_raw": _record_metric(profiles, "pro_record_raw"),
        "with_parsed_wins_losses_draws_no_contests": _metric(
            sum(1 for profile in profiles if _has_parsed_pro_record(profile.get("record_summary") or {})),
            len(profiles),
        ),
        "with_amateur_record_raw": _record_metric(profiles, "amateur_record_raw"),
        "with_finish_breakdown": _record_metric(profiles, "finish_breakdown"),
    }


def _fight_history_coverage(profiles: list[dict[str, Any]], history_lengths: list[int]) -> dict[str, Any]:
    total_rows = sum(history_lengths)
    return {
        "with_fight_history_rows": _metric(sum(1 for count in history_lengths if count > 0), len(profiles)),
        "total_fight_history_rows": total_rows,
        "average_history_rows_per_successful_profile": round(total_rows / len(profiles), 2) if profiles else 0,
        "median_history_rows": statistics.median(history_lengths) if history_lengths else 0,
        "max_history_rows": max(history_lengths) if history_lengths else 0,
        "with_at_least_1_history_row": _metric(sum(1 for count in history_lengths if count >= 1), len(profiles)),
        "with_at_least_5_history_rows": _metric(sum(1 for count in history_lengths if count >= 5), len(profiles)),
        "with_at_least_10_history_rows": _metric(sum(1 for count in history_lengths if count >= 10), len(profiles)),
    }


def _fight_history_row_coverage(rows: list[dict[str, Any]]) -> dict[str, Any]:
    total = len(rows)
    return {
        "total_rows": total,
        "rows_with_date": _row_metric(rows, "fight_date"),
        "rows_with_event_name": _row_metric(rows, "event_name"),
        "rows_with_event_url": _row_metric(rows, "event_url"),
        "rows_with_bout_url": _row_metric(rows, "bout_url"),
        "rows_with_opponent_name": _row_metric(rows, "opponent_name"),
        "rows_with_opponent_url": _row_metric(rows, "opponent_url"),
        "rows_with_result": _row_metric(rows, "result"),
        "rows_with_method": _row_metric(rows, "method"),
        "rows_with_round": _row_metric(rows, "round"),
        "rows_with_time": _row_metric(rows, "time"),
        "rows_with_weight_class": _row_metric(rows, "weight_class"),
        "rows_with_amateur_cancelled_upcoming_indicators": _metric(
            sum(
                1
                for row in rows
                if any(key in row for key in ["is_amateur", "is_cancelled", "is_upcoming"])
            ),
            total,
        ),
        "rows_with_professional_or_overturned_indicators": _metric(
            sum(
                1
                for row in rows
                if any(key in row for key in ["is_professional", "is_overturned"])
            ),
            total,
        ),
        "rows_with_opponent_or_prefight_current_record": _metric(
            sum(
                1
                for row in rows
                if row.get("opponent_record_raw") or row.get("prefight_record_raw") or row.get("current_record_raw")
            ),
            total,
        ),
    }


def _warning_coverage(profiles: list[dict[str, Any]], warning_counts: Counter[str]) -> dict[str, Any]:
    profiles_with_warnings = sum(1 for profile in profiles if _profile_warnings(profile))
    return {
        "profiles_with_warnings": _metric(profiles_with_warnings, len(profiles)),
        "profiles_without_warnings": _metric(len(profiles) - profiles_with_warnings, len(profiles)),
        "count_by_warning_type": dict(sorted(warning_counts.items())),
        "top_warning_types": [
            {"type": warning, "count": count}
            for warning, count in warning_counts.most_common(20)
        ],
    }


def _summary_metric(profiles: list[dict[str, Any]], field: str) -> dict[str, Any]:
    return _metric(sum(1 for profile in profiles if (profile.get("profile_summary") or {}).get(field) is not None), len(profiles))


def _record_metric(profiles: list[dict[str, Any]], field: str) -> dict[str, Any]:
    return _metric(sum(1 for profile in profiles if (profile.get("record_summary") or {}).get(field)), len(profiles))


def _row_metric(rows: list[dict[str, Any]], field: str) -> dict[str, Any]:
    return _metric(sum(1 for row in rows if row.get(field) is not None), len(rows))


def _metric(count: int, denominator: int) -> dict[str, Any]:
    return {"count": int(count), "denominator": int(denominator), "percent": _ratio(count, denominator)}


def _ratio(count: int, denominator: int) -> float:
    return round(count / denominator, 4) if denominator else 0.0


def _pct(value: Any) -> str:
    try:
        return f"{float(value) * 100:.1f}%"
    except (TypeError, ValueError):
        return "0.0%"


def _metric_line(metric: Any) -> str:
    if not isinstance(metric, dict):
        return "0/0 (0.0%)"
    return f"{metric.get('count', 0)}/{metric.get('denominator', 0)} ({_pct(metric.get('percent'))})"


def _has_parsed_pro_record(record: dict[str, Any]) -> bool:
    return all(record.get(field) is not None for field in ["wins", "losses", "draws", "no_contests"])


def _profile_warnings(profile: dict[str, Any]) -> list[str]:
    return [str(warning) for warning in profile.get("warnings", []) if warning is not None]


def _score_sort_key(profile: dict[str, Any]) -> tuple[int, str]:
    return (int(profile.get("profile_completeness_score") or 0), str(profile.get("display_name") or ""))


def _warning_failure_examples(profiles: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return sorted(
        [
            profile
            for profile in profiles
            if profile.get("status") != "success" or _profile_warnings(profile)
        ],
        key=lambda profile: (
            profile.get("status") == "success",
            -len(_profile_warnings(profile)),
            int(profile.get("profile_completeness_score") or 0),
        ),
    )


def _example_profiles(profiles: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {
            "source_fighter_id": profile.get("source_fighter_id"),
            "display_name": profile.get("display_name"),
            "source_url": profile.get("source_url"),
            "profile_completeness_score": profile.get("profile_completeness_score"),
            "missing_key_fields": missing_key_fields(profile),
            "warnings": _profile_warnings(profile),
        }
        for profile in profiles
    ]


def _atomic_write_text(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_name(f".{path.name}.tmp")
    temp_path.write_text(value, encoding="utf-8")
    temp_path.replace(path)
