import json
from pathlib import Path

from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
from mma_future_scraper.state_report import build_state_report, format_state_report, write_state_report


def test_state_report_works_with_normal_fixture_files(tmp_path):
    write_fixture_state(tmp_path)
    write_fixture_latest(tmp_path)

    report = build_state_report(tmp_path)
    text = format_state_report(report)

    assert report["last_successful_run_id"] == "run-1"
    assert report["last_attempted_run_id"] == "run-1"
    assert report["total_known_events"] == 1
    assert report["total_known_bouts"] == 1
    assert report["incomplete_events_count"] == 1
    assert report["incomplete_events_by_reason"] == {"recent_update_window": 1}
    assert report["event_states"] == {"complete_recent_recheck": 1}
    assert report["raw_event_states"] == {"partial": 1}
    assert report["recheck_complete_events_count"] == 1
    assert report["true_problem_events_count"] == 0
    assert report["no_relevant_bout_events_count"] == 0
    assert report["critical_missing_data_count"] == 0
    assert report["bouts_by_result_type"] == {"win_loss": 1}
    assert report["bouts_by_status"] == {"completed": 1}
    assert report["latest_run_summary"]["run_id"] == "run-1"
    assert "State report" in text


def test_state_report_handles_missing_optional_latest_files(tmp_path):
    write_fixture_state(tmp_path)

    report = build_state_report(tmp_path)

    assert report["latest_run_summary"]["run_id"] is None
    assert report["latest_run_summary"]["changes"] == {}
    assert report["latest_run_summary"]["results_events_count"] == 0


def test_state_report_detects_duplicate_ids_in_fixture_data(tmp_path):
    write_fixture_state(tmp_path)
    events = read_json(tmp_path / "state" / "events_index.json")
    events["tapology_event_2"] = {
        **events["tapology_event_1"],
        "source_event_id": "tapology_event_2",
    }
    events["tapology_event_2"]["internal_event_id"] = "mf_event_000001"
    write_json(tmp_path / "state" / "events_index.json", events)

    bouts = read_json(tmp_path / "state" / "bouts_index.json")
    bouts["tapology_bout_2"] = {
        **bouts["tapology_bout_1"],
        "source_bout_id": "tapology_bout_2",
    }
    bouts["tapology_bout_2"]["internal_bout_id"] = "mf_bout_000001"
    write_json(tmp_path / "state" / "bouts_index.json", bouts)

    report = build_state_report(tmp_path)

    assert report["duplicate_internal_event_ids"] == {
        "mf_event_000001": ["tapology_event_1", "tapology_event_2"]
    }
    assert report["duplicate_internal_bout_ids"] == {
        "mf_bout_000001": ["tapology_bout_1", "tapology_bout_2"]
    }


def test_state_report_detects_orphan_bouts(tmp_path):
    write_fixture_state(tmp_path)
    bouts = read_json(tmp_path / "state" / "bouts_index.json")
    bouts["tapology_bout_1"]["source_event_id"] = "tapology_event_missing"
    write_json(tmp_path / "state" / "bouts_index.json", bouts)

    report = build_state_report(tmp_path)

    assert report["orphan_bouts"] == ["tapology_bout_1"]


def test_state_report_writes_latest_report_json(tmp_path):
    write_fixture_state(tmp_path)

    report = build_state_report(tmp_path)
    path = write_state_report(tmp_path, report)

    assert path == tmp_path / "latest" / "state_report.json"
    written = read_json(path)
    assert written["schema_version"] == "scraper_state_report_v0_1"
    assert written["total_known_events"] == 1


def test_state_report_does_not_modify_state_files(tmp_path):
    write_fixture_state(tmp_path)
    before = snapshot_state_files(tmp_path)

    report = build_state_report(tmp_path)
    write_state_report(tmp_path, report)

    assert snapshot_state_files(tmp_path) == before


def write_fixture_state(root: Path) -> None:
    write_json(
        root / "state" / "scraper_state.json",
        {
            "schema_version": "scraper_state_v0_1",
            "source": "tapology",
            "last_successful_run_id": "run-1",
            "last_attempted_run_id": "run-1",
            "next_internal_event_number": 2,
            "next_internal_bout_number": 2,
            "total_known_events": 1,
            "total_known_bouts": 1,
        },
    )
    write_json(
        root / "state" / "events_index.json",
        {
            "tapology_event_1": {
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_1",
                "event_url": "https://www.tapology.com/fightcenter/events/1-test",
                "event_name": "Fixture FC",
                "event_date": "2026-05-10",
                "state": "partial",
                "needs_recheck": True,
                "recheck_reason": "recent_update_window",
                "known_bout_ids": ["mf_bout_000001"],
                "known_completed_bout_ids": ["mf_bout_000001"],
                "known_bouts_count": 1,
                "known_completed_bouts_count": 1,
            }
        },
    )
    write_json(
        root / "state" / "bouts_index.json",
        {
            "tapology_bout_1": {
                "internal_bout_id": "mf_bout_000001",
                "source_bout_id": "tapology_bout_1",
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_1",
                "status": "completed",
                "result_type": "win_loss",
            }
        },
    )
    write_json(
        root / "state" / "incomplete_events.json",
        {
            "tapology_event_1": {
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_1",
                "recheck_reason": "recent_update_window",
                "next_recheck_after": "2099-01-01T00:00:00+00:00",
            }
        },
    )


def write_fixture_latest(root: Path) -> None:
    write_json(
        root / "latest" / "run_manifest.json",
        {
            "schema_version": "scraper_run_manifest_v0_1",
            "run_id": "run-1",
            "status": "success",
            "finished_at": "2026-05-12T00:00:00+00:00",
            "counts": {"events_scraped": 1, "bouts_new": 1},
        },
    )
    write_json(
        root / "latest" / "changes.json",
        {
            "schema_version": "scraper_changes_v0_1",
            "run_id": "run-1",
            "new_events": ["mf_event_000001"],
            "new_bouts": ["mf_bout_000001"],
        },
    )
    write_json(
        root / "latest" / "results.json",
        {
            "schema_version": RESULTS_SCHEMA_VERSION,
            "events": [
                {
                    "internal_event_id": "mf_event_000001",
                    "source_event_id": "tapology_event_1",
                    "bouts": [
                        {
                            "internal_bout_id": "mf_bout_000001",
                            "internal_event_id": "mf_event_000001",
                            "source_bout_id": "tapology_bout_1",
                            "source_event_id": "tapology_event_1",
                            "status": "completed",
                            "pro_am": "professional",
                            "result_type": "win_loss",
                            "winner": "fighter_a",
                            "loser": "fighter_b",
                            "fighter_a": {"name": "Jane Doe"},
                            "fighter_b": {"name": "Ann Other"},
                            "result": {"method_category": "decision"},
                        }
                    ],
                }
            ],
        },
    )


def snapshot_state_files(root: Path) -> dict[str, str]:
    return {
        path.name: path.read_text(encoding="utf-8")
        for path in sorted((root / "state").glob("*.json"))
    }


def write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))
