import json
from pathlib import Path

from mma_future_scraper.contract import populate_results_contract_fields
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
from mma_future_scraper.pre_import import build_pre_import_artifacts
from mma_future_scraper.storage import atomic_write_json


def test_pre_import_health_gate_ready_when_canonical_outputs_are_complete(tmp_path):
    latest_dir = tmp_path / "latest"
    state_dir = tmp_path / "state"
    _write_complete_latest(latest_dir, state_dir)

    daily_summary, manual_review = build_pre_import_artifacts(
        latest_dir=latest_dir,
        state_dir=state_dir,
        expected_pages=15,
        max_event_fetch_failures=0,
    )

    assert daily_summary["ready_for_import"] is True
    assert daily_summary["events_total"] == 1
    assert daily_summary["bouts_total"] == 1
    assert daily_summary["profiles_success"] == 2
    assert daily_summary["blocking_issues"] == []
    assert manual_review["manual_review_count"] == 0
    assert (latest_dir / "daily_summary.json").exists()
    assert (latest_dir / "manual_review.json").exists()


def test_pre_import_health_gate_allows_missing_fighter_profile_as_review_backlog(tmp_path):
    latest_dir = tmp_path / "latest"
    state_dir = tmp_path / "state"
    _write_complete_latest(latest_dir, state_dir, profile_ids=["tapology_fighter_101"])

    daily_summary, manual_review = build_pre_import_artifacts(
        latest_dir=latest_dir,
        state_dir=state_dir,
        expected_pages=15,
        max_event_fetch_failures=0,
    )

    assert daily_summary["ready_for_import"] is True
    assert daily_summary["review_required"] is True
    assert daily_summary["blocking_issues"] == []
    assert daily_summary["missing_fighter_profiles_for_results"] == 1
    assert daily_summary["non_blocking_review_count"] == 1
    assert manual_review["counts_by_type"]["missing_fighter_profile"] == 1


def test_pre_import_manual_review_captures_event_fetch_timeout(tmp_path):
    latest_dir = tmp_path / "latest"
    state_dir = tmp_path / "state"
    _write_complete_latest(latest_dir, state_dir)
    manifest = json.loads((latest_dir / "run_manifest.json").read_text(encoding="utf-8"))
    manifest["counts"]["event_fetch_failures"] = 1
    manifest["counts"]["failed_event_examples"] = [
        {
            "source_event_id": "tapology_event_900",
            "event_url": "https://www.tapology.com/fightcenter/events/900-timeout-fc",
            "event_name": "Timeout FC",
            "reason": "Timeout 30000ms exceeded",
        }
    ]
    atomic_write_json(latest_dir / "run_manifest.json", manifest)

    daily_summary, manual_review = build_pre_import_artifacts(
        latest_dir=latest_dir,
        state_dir=state_dir,
        expected_pages=15,
        max_event_fetch_failures=0,
    )

    assert daily_summary["ready_for_import"] is False
    assert "event_fetch_failures:1:max:0" in daily_summary["blocking_issues"]
    assert manual_review["counts_by_type"]["event_fetch_timeout"] == 1


def _write_complete_latest(latest_dir: Path, state_dir: Path, profile_ids=None) -> None:
    latest_dir.mkdir(parents=True, exist_ok=True)
    state_dir.mkdir(parents=True, exist_ok=True)
    profile_ids = profile_ids or ["tapology_fighter_101", "tapology_fighter_102"]
    results = _results_doc()
    results['run']={'run_id':'run_1'}
    atomic_write_json(latest_dir / "results.json", results)
    atomic_write_json(
        latest_dir / "changes.json",
        {
            "schema_version": "scraper_changes_v0_1",
            "run_id": "run_1",
            "new_events": ["mf_event_000001"],
            "updated_events": [],
            "unchanged_events": [],
            "new_bouts": ["mf_bout_000001"],
            "updated_bouts": [],
            "unchanged_bouts": [],
            "incomplete_events": [],
            "new_fighter_urls": [],
            "fighters_to_refresh": [],
            "manual_review_events": [],
            "warnings": [],
        },
    )
    atomic_write_json(
        latest_dir / "run_manifest.json",
        {
            "schema_version": "scraper_run_manifest_v0_1",
            "run_id": "run_1",
            "started_at": "2026-06-10T00:00:00+00:00",
            "finished_at": "2026-06-10T00:01:00+00:00",
            "status": "success",
            "source": "tapology",
            "mode": "incremental",
            "run_mode": "daily",
            "options": {},
            "counts": {
                "pages_scanned": 15,
                "errors_count": 0,
                "event_fetch_failures": 0,
                "missing_cached_event_payloads": 0,
                "failed_event_examples": [],
            },
        },
    )
    profiles = [_profile(source_id) for source_id in profile_ids]
    atomic_write_json(
        latest_dir / "fighter_profiles.json",
        {
            "schema_version": "tapology_fighter_profiles_v0_1",
            "run_id": "profiles_1",
            "scraped_at": "2026-06-10T00:02:00+00:00",
            "profiles": profiles,
        },
    )
    atomic_write_json(
        latest_dir / "fighter_profiles_report.json",
        {
            "schema_version": "tapology_fighter_profiles_report_v0_1",
            "run_id": "profiles_1",
            "profiles_total": len(profiles),
            "profiles_success": len(profiles),
            "profiles_failed": 0,
            "success_rate": 1.0,
        },
    )
    atomic_write_json(state_dir / "incomplete_events.json", {})


def _results_doc():
    doc = {
        "schema_version": RESULTS_SCHEMA_VERSION,
        "source": "tapology",
        "scraped_at": "2026-06-10T00:01:00+00:00",
        "events": [
            {
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_100",
                "event_url": "https://www.tapology.com/fightcenter/events/100-fixture-fc",
                "event_name": "Fixture FC",
                "promotion_name": "Fixture FC",
                "promotion_url": "https://www.tapology.com/fightcenter/promotions/100-fixture-fc",
                "event_date": "2026-06-01",
                "location": "Zagreb, Croatia",
                "venue": "Fixture Arena",
                "bouts": [
                    {
                        "internal_bout_id": "mf_bout_000001",
                        "source_bout_id": "tapology_bout_100",
                        "bout_url": "https://www.tapology.com/fightcenter/bouts/100-a-vs-b",
                        "status": "completed",
                        "pro_am": "professional",
                        "card_position": "main_card",
                        "weight_class": "Lightweight",
                        "weight_lbs": 155,
                        "winner": "fighter_a",
                        "loser": "fighter_b",
                        "result_type": "win_loss",
                        "fighter_a": {
                            "name": "Fighter A",
                            "url": "https://www.tapology.com/fightcenter/fighters/101-fighter-a",
                            "source_fighter_id": "tapology_fighter_101",
                            "prefight_record": {"raw": "1-0", "wins": 1, "losses": 0, "draws": 0, "nc": 0},
                        },
                        "fighter_b": {
                            "name": "Fighter B",
                            "url": "https://www.tapology.com/fightcenter/fighters/102-fighter-b",
                            "source_fighter_id": "tapology_fighter_102",
                            "prefight_record": {"raw": "0-1", "wins": 0, "losses": 1, "draws": 0, "nc": 0},
                        },
                        "result": {"method_category": "decision", "method_detail": "unanimous", "round": 3, "time": "5:00"},
                    }
                ],
            }
        ],
    }
    populate_results_contract_fields(doc)
    return doc


def _profile(source_fighter_id: str):
    numeric_id = source_fighter_id.rsplit("_", 1)[-1]
    return {
        "source_fighter_id": source_fighter_id,
        "source_url": f"https://www.tapology.com/fightcenter/fighters/{numeric_id}-fighter",
        "display_name": f"Fighter {numeric_id}",
        "status": "success",
        "scraped_at": "2026-06-10T00:02:00+00:00",
        "profile_summary": {},
        "record_summary": {"pro_record_raw": "1-0", "wins": 1, "losses": 0, "draws": 0, "no_contests": 0},
        "fight_history": [{"result": "W"}],
        "warnings": [],
    }
