import json
from pathlib import Path

import pytest

from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
import mma_future_scraper.storage as storage
from mma_future_scraper.storage import DataStore, should_recheck_event


STARTED = "2026-05-12T19:40:00+00:00"
FINISHED = "2026-05-12T19:41:00+00:00"
OPTIONS = {
    "run_mode": "daily",
    "limit_events": 5,
    "recheck_days": 14,
    "recheck_known_limit": 50,
    "stop_after_known_complete": 20,
    "force_recheck_event": [],
    "force_full_scan": False,
    "effective_force_full_scan": False,
    "cumulative_results": False,
    "fresh_results": False,
    "debug": False,
}


def test_successful_run_creates_artifacts_latest_and_state(tmp_path):
    store = DataStore(tmp_path)
    results, changes, manifest = commit_fixture(store, "20260512T194000Z", fixture_results())

    run_dir = tmp_path / "runs" / "20260512T194000Z"
    assert (run_dir / "run_manifest.json").exists()
    assert (run_dir / "results.json").exists()
    assert (run_dir / "changes.json").exists()
    assert json_at(tmp_path / "latest" / "results.json") == results
    assert json_at(tmp_path / "latest" / "changes.json") == changes
    assert json_at(tmp_path / "latest" / "run_manifest.json") == manifest

    state = json_at(tmp_path / "state" / "scraper_state.json")
    assert state["last_attempted_run_id"] == "20260512T194000Z"
    assert state["last_successful_run_id"] == "20260512T194000Z"
    assert state["next_internal_event_number"] == 6
    assert state["next_internal_bout_number"] == 6


def test_commit_latest_mirrors_upcoming_when_run_artifact_exists(tmp_path):
    store = DataStore(tmp_path)
    results, changes, manifest = commit_fixture(store, "20260512T194000Z", fixture_results())
    run_dir = tmp_path / "runs" / "20260512T194000Z"
    upcoming = {
        "schema_version": "scraper_upcoming_v0_1",
        "events": [
            {
                "source_event_id": "tapology_event_99",
                "event_date": "2026-06-20",
                "bouts": [],
            }
        ],
    }
    storage.atomic_write_json(run_dir / "upcoming.json", upcoming)

    store._commit_latest(run_dir)

    assert json_at(tmp_path / "latest" / "results.json") == results
    assert json_at(tmp_path / "latest" / "changes.json") == changes
    assert json_at(tmp_path / "latest" / "run_manifest.json") == manifest
    assert json_at(tmp_path / "latest" / "upcoming.json") == upcoming


def test_run_and_manifest_warning_counts_are_consistent(tmp_path):
    store = DataStore(tmp_path)
    payload = fixture_results(count=1)
    payload["events"][0]["warnings"] = ["bouts_skipped_amateur:1"]
    results, _, manifest = commit_fixture(
        store,
        "20260512T194000Z",
        payload,
        summary_warnings=[
            "bouts_skipped_amateur:https://www.tapology.com/fightcenter/events/1-fixture-fc:1",
            "event_skipped_non_mma:https://www.tapology.com/fightcenter/events/2-boxing",
        ],
    )

    assert results["run"]["warnings_count"] == 2
    assert results["run"]["retained_event_warnings_count"] == 1
    assert results["run"]["skipped_event_warnings_count"] == 1
    assert manifest["counts"]["warnings_count"] == 2
    assert manifest["counts"]["retained_event_warnings_count"] == 1
    assert manifest["counts"]["skipped_event_warnings_count"] == 1


def test_same_fixture_twice_reuses_internal_ids_and_creates_no_duplicates(tmp_path):
    store = DataStore(tmp_path)
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results())
    second_results, second_changes, _ = commit_fixture(store, "20260512T194100Z", fixture_results())

    assert len(list((tmp_path / "runs").iterdir())) == 2
    assert len(json_at(tmp_path / "state" / "events_index.json")) == 5
    assert len(json_at(tmp_path / "state" / "bouts_index.json")) == 5
    assert [event["internal_event_id"] for event in second_results["events"]] == [
        event["internal_event_id"] for event in first_results["events"]
    ]
    assert second_changes["new_events"] == []
    assert second_changes["new_bouts"] == []
    assert len(second_changes["unchanged_events"]) == 5
    assert len(second_changes["unchanged_bouts"]) == 5


def test_successful_run_persists_event_payloads(tmp_path):
    store = DataStore(tmp_path)
    results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1))

    payloads = json_at(tmp_path / "state" / "event_payloads.json")

    assert list(payloads) == ["tapology_event_1"]
    assert payloads["tapology_event_1"] == results["events"][0]


def test_cumulative_results_include_cached_known_event_and_new_event(tmp_path):
    store = DataStore(tmp_path)
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1))

    new_doc = fixture_results(start=2, count=1)
    cumulative_options = {**OPTIONS, "cumulative_results": True}
    second_results, _, manifest = commit_fixture(
        store,
        "20260512T194100Z",
        new_doc,
        options=cumulative_options,
        cumulative_scope=[
            {"source_event_id": "tapology_event_1", "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc"},
            {"source_event_id": "tapology_event_2", "event_url": "https://www.tapology.com/fightcenter/events/2-fixture-fc"},
        ],
    )

    assert [event["source_event_id"] for event in second_results["events"]] == [
        "tapology_event_1",
        "tapology_event_2",
    ]
    assert second_results["events"][0] == first_results["events"][0]
    assert second_results["run"]["events_scraped_this_run"] == 1
    assert second_results["run"]["events_from_payload_cache"] == 1
    assert second_results["run"]["events_seen"] == 2
    assert second_results["run"]["events_written"] == 2
    assert manifest["counts"]["events_seen"] == second_results["run"]["events_seen"]
    assert manifest["counts"]["events_written"] == second_results["run"]["events_written"]
    assert manifest["counts"]["events_scraped_this_run"] == 1
    assert manifest["counts"]["events_from_payload_cache"] == 1
    assert json_at(tmp_path / "latest" / "results.json") == second_results
    from mma_future_scraper.event_coverage import validate as validate_coverage
    coverage = json_at(tmp_path / "latest" / "event_coverage.json")
    assert {event["source_event_id"] for event in coverage["events"]} == {"tapology_event_1", "tapology_event_2"}
    validate_coverage(coverage, second_results["events"])
    assert coverage == json_at(tmp_path / "runs" / "20260512T194100Z" / "event_coverage.json")


def test_cumulative_results_include_cached_events_outside_current_scope(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=2))

    second_results, _, manifest = commit_fixture(
        store,
        "20260512T194100Z",
        fixture_results(start=3, count=1),
        options={**OPTIONS, "cumulative_results": True},
        cumulative_scope=[
            {"source_event_id": "tapology_event_3", "event_url": "https://www.tapology.com/fightcenter/events/3-fixture-fc"},
        ],
    )

    assert [event["source_event_id"] for event in second_results["events"]] == [
        "tapology_event_1",
        "tapology_event_2",
        "tapology_event_3",
    ]
    assert second_results["run"]["events_scraped_this_run"] == 1
    assert second_results["run"]["events_from_payload_cache"] == 2
    assert second_results["run"]["events_written"] == 3
    assert manifest["counts"]["events_from_payload_cache"] == 2
    assert manifest["counts"]["events_written"] == 3


def test_cumulative_results_keep_cached_payload_even_if_current_listing_excludes_it(tmp_path):
    store = DataStore(tmp_path)
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1))

    second_results, _, manifest = commit_fixture(
        store,
        "20260512T194100Z",
        fixture_results(count=0),
        options={**OPTIONS, "cumulative_results": True},
        cumulative_excluded=[
            "tapology_event_1",
        ],
    )

    assert [event["source_event_id"] for event in second_results["events"]] == ["tapology_event_1"]
    assert second_results["events"][0] == first_results["events"][0]
    assert second_results["run"]["events_from_payload_cache"] == 1
    assert second_results["run"]["events_written"] == 1
    assert manifest["counts"]["events_from_payload_cache"] == 1


def test_cumulative_results_include_cached_pending_no_result_event(tmp_path):
    store = DataStore(tmp_path)
    pending = fixture_results(count=1, event_date="2026-05-12")
    pending_event = pending["events"][0]
    pending_event.update(
        {
            "source_event_status": "in_progress",
            "source_bouts_total": 1,
            "source_mma_bouts_total": 1,
            "bouts": [],
            "bouts_included": 0,
            "bouts_skipped_total": 1,
            "bouts_skipped_no_result": 1,
            "needs_recheck": True,
            "warnings": ["bouts_skipped_no_result:1"],
            "skipped_bouts": [
                {
                    "reason": "no_result",
                    "source_bout_id": "tapology_bout_pending",
                    "source_bout_numeric_id": "999",
                    "bout_url": "https://www.tapology.com/fightcenter/bouts/pending",
                    "fighter_a_name": "Pending A",
                    "fighter_a_url": "https://www.tapology.com/fightcenter/fighters/9991-pending-a",
                    "fighter_a_source_fighter_id": "tapology_fighter_9991",
                    "fighter_a_source_fighter_numeric_id": "9991",
                    "fighter_b_name": "Pending B",
                    "fighter_b_url": "https://www.tapology.com/fightcenter/fighters/9992-pending-b",
                    "fighter_b_source_fighter_id": "tapology_fighter_9992",
                    "fighter_b_source_fighter_numeric_id": "9992",
                    "card_position": "main_card",
                    "weight_lbs": 155,
                    "weight_class": "Lightweight",
                }
            ],
        }
    )
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", pending)

    payloads = json_at(tmp_path / "state" / "event_payloads.json")
    assert payloads["tapology_event_1"]["needs_recheck"] is True
    assert payloads["tapology_event_1"]["bouts"] == []

    second_results, _, manifest = commit_fixture(
        store,
        "20260512T194100Z",
        fixture_results(start=2, count=1),
        options={**OPTIONS, "cumulative_results": True},
        cumulative_scope=[
            {"source_event_id": "tapology_event_1", "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc"},
            {"source_event_id": "tapology_event_2", "event_url": "https://www.tapology.com/fightcenter/events/2-fixture-fc"},
        ],
    )

    assert [event["source_event_id"] for event in second_results["events"]] == [
        "tapology_event_1",
        "tapology_event_2",
    ]
    assert second_results["events"][0] == first_results["events"][0]
    assert second_results["events"][0]["needs_recheck"] is True
    assert second_results["run"]["events_from_payload_cache"] == 1
    assert manifest["counts"]["events_from_payload_cache"] == 1


def test_rechecked_event_replaces_cached_payload_in_cumulative_results(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1))
    changed = fixture_results(count=1)
    changed["events"][0]["event_name"] = "Fixture FC 1 Rechecked"

    results, _, _ = commit_fixture(
        store,
        "20260512T194100Z",
        changed,
        options={**OPTIONS, "cumulative_results": True},
        cumulative_scope=[
            {"source_event_id": "tapology_event_1", "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc"},
        ],
    )

    payloads = json_at(tmp_path / "state" / "event_payloads.json")
    assert results["events"][0]["event_name"] == "Fixture FC 1 Rechecked"
    assert payloads["tapology_event_1"]["event_name"] == "Fixture FC 1 Rechecked"


def test_source_mma_total_overcount_from_skipped_amateur_does_not_force_recheck(tmp_path):
    store = DataStore(tmp_path)
    payload = fixture_results(count=1)
    event = payload["events"][0]
    event["source_mma_bouts_total"] = 1
    event["source_mma_rows_seen"] = 2
    event["source_rows_seen"] = 2
    event["bouts_included"] = 1
    event["bouts_skipped_total"] = 1
    event["bouts_skipped_amateur"] = 1
    event["warnings"] = [
        "bouts_skipped_amateur:1",
        "source_mma_bouts_total_mismatch:1:2",
    ]
    event["skipped_bouts"] = [
        {
            "reason": "amateur",
            "source_bout_id": "tapology_bout_amateur",
            "source_bout_numeric_id": "999",
            "bout_url": "https://www.tapology.com/fightcenter/bouts/999-amateur-a-vs-amateur-b",
            "fighter_a_name": "Skip A",
            "fighter_a_url": "https://www.tapology.com/fightcenter/fighters/9991-skip-a",
            "fighter_a_source_fighter_id": "tapology_fighter_9991",
            "fighter_a_source_fighter_numeric_id": "9991",
            "fighter_b_name": "Skip B",
            "fighter_b_url": "https://www.tapology.com/fightcenter/fighters/9992-skip-b",
            "fighter_b_source_fighter_id": "tapology_fighter_9992",
            "fighter_b_source_fighter_numeric_id": "9992",
            "card_position": "prelim",
            "weight_lbs": 155,
            "weight_class": "Lightweight",
        }
    ]
    event["source_mma_bouts_total_mismatch"] = {
        "source_mma_bouts_total": 1,
        "source_rows_seen": 2,
        "source_mma_rows_seen": 2,
        "retained_bouts": 1,
        "skipped_bouts_by_reason": {"amateur": 1},
        "non_mma_rows_involved": False,
        "amateur_rows_involved": True,
    }

    _, changes, _ = commit_fixture(store, "20260512T194000Z", payload)

    events_index = json_at(tmp_path / "state" / "events_index.json")
    assert changes["incomplete_events"] == []
    assert events_index["tapology_event_1"]["state"] == "complete"
    assert events_index["tapology_event_1"]["needs_recheck"] is False


def test_fresh_results_do_not_include_cached_payloads(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1))

    results, _, manifest = commit_fixture(
        store,
        "20260512T194100Z",
        fixture_results(start=2, count=1),
        options={**OPTIONS, "fresh_results": True},
        cumulative_scope=[
            {"source_event_id": "tapology_event_1", "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc"},
            {"source_event_id": "tapology_event_2", "event_url": "https://www.tapology.com/fightcenter/events/2-fixture-fc"},
        ],
    )

    assert [event["source_event_id"] for event in results["events"]] == ["tapology_event_2"]
    assert results["run"]["events_from_payload_cache"] == 0
    assert manifest["counts"]["events_from_payload_cache"] == 0


def test_changed_bout_and_event_are_updated_not_new_and_keep_ids(tmp_path):
    store = DataStore(tmp_path)
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1))
    changed = fixture_results(count=1)
    changed["events"][0]["event_name"] = "Fixture FC 1 Revised"
    changed["events"][0]["bouts"][0]["result"]["method_category"] = "submission"
    changed["events"][0]["bouts"][0]["result"]["method_detail"] = "Rear Naked Choke"

    second_results, changes, _ = commit_fixture(store, "20260512T194100Z", changed)

    assert changes["new_events"] == []
    assert changes["new_bouts"] == []
    assert changes["updated_events"] == [first_results["events"][0]["internal_event_id"]]
    assert changes["updated_bouts"] == [first_results["events"][0]["bouts"][0]["internal_bout_id"]]
    assert second_results["events"][0]["internal_event_id"] == first_results["events"][0]["internal_event_id"]
    assert second_results["events"][0]["bouts"][0]["internal_bout_id"] == first_results["events"][0]["bouts"][0]["internal_bout_id"]


def test_incomplete_and_partial_events_are_queued_for_recheck(tmp_path):
    store = DataStore(tmp_path)
    empty_event = fixture_results(count=1, event_date="2026-05-10")
    empty_event["events"][0]["bouts"] = []
    _, changes, _ = commit_fixture(store, "20260512T194000Z", empty_event)
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")
    assert changes["incomplete_events"] == ["mf_event_000001"]
    assert events_index["tapology_event_1"]["state"] == "pending_results"
    assert queue["tapology_event_1"]["recheck_reason"] == "no_completed_professional_mma_bouts"

    partial = fixture_results(start=2, count=1)
    partial["events"][0]["bouts"][0]["result"]["method_category"] = "unknown"
    partial["events"][0]["bouts"][0]["result_type"] = "unknown"
    commit_fixture(store, "20260512T194100Z", partial)
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")
    assert queue["tapology_event_2"]["recheck_reason"] == "missing_or_partial_results"
    assert events_index["tapology_event_2"]["state"] == "parser_attention"


def test_failed_listing_event_is_queued_for_recheck(tmp_path):
    store = DataStore(tmp_path)
    _, changes, manifest = commit_fixture(
        store,
        "20260512T194000Z",
        fixture_results(count=0),
        failed_event_examples=[
            {
                "source_event_id": "tapology_event_900",
                "event_name": "Timeout FC",
                "event_url": "https://www.tapology.com/fightcenter/events/900-timeout-fc",
                "list_page_number": 3,
                "list_position": 4,
                "reason": "Timeout 30000ms exceeded",
            }
        ],
    )

    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")
    assert changes["incomplete_events"] == ["mf_event_000001"]
    assert changes["manual_review_events"] == ["tapology_event_900"]
    assert manifest["counts"]["events_marked_incomplete"] == 1
    assert events_index["tapology_event_900"]["state"] == "failed_fetch"
    assert events_index["tapology_event_900"]["recheck_reason"] == "event_fetch_timeout"
    assert queue["tapology_event_900"]["recheck_reason"] == "event_fetch_timeout"
    assert queue["tapology_event_900"]["next_recheck_after"] == FINISHED


def test_benign_skipped_bout_warnings_do_not_mark_old_complete_event_incomplete(tmp_path):
    store = DataStore(tmp_path)
    complete = fixture_results(count=1, event_date="2020-01-01")
    complete["events"][0]["warnings"] = ["bouts_skipped_amateur:7", "bouts_skipped_non_mma:2"]

    results, changes, _ = commit_fixture(store, "20260512T194000Z", complete)
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")

    assert results["events"][0]["internal_event_id"] == "mf_event_000001"
    assert changes["incomplete_events"] == []
    assert queue == {}
    assert events_index["tapology_event_1"]["state"] == "complete"
    assert events_index["tapology_event_1"]["needs_recheck"] is False


def test_recent_complete_event_is_complete_without_event_level_recheck(tmp_path):
    store = DataStore(tmp_path)
    results, changes, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2026-05-10"))
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")

    assert results["events"][0]["internal_event_id"] == "mf_event_000001"
    assert changes["incomplete_events"] == []
    assert events_index["tapology_event_1"]["state"] == "complete"
    assert events_index["tapology_event_1"]["needs_recheck"] is False
    assert queue == {}


def test_old_valid_event_is_complete(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2020-01-01"))
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")

    assert events_index["tapology_event_1"]["state"] == "complete"
    assert events_index["tapology_event_1"]["needs_recheck"] is False
    assert queue == {}


def test_explicit_needs_recheck_queue_dedupes_and_resolves(tmp_path):
    store = DataStore(tmp_path)
    incomplete = fixture_results(count=1, event_date="2026-06-01")
    event = incomplete["events"][0]
    event.update(
        {
            "source_event_status": "in_progress",
            "source_bouts_total": 2,
            "bouts_included": 1,
            "bouts_skipped_total": 1,
            "bouts_skipped_no_result": 1,
            "needs_recheck": True,
            "skipped_bouts": [
                {
                    "reason": "no_result",
                    "source_bout_id": "tapology_bout_999",
                    "source_bout_numeric_id": "999",
                    "bout_url": "https://www.tapology.com/fightcenter/bouts/999-pending",
                    "fighter_a_name": "Pending A",
                    "fighter_a_url": None,
                    "fighter_a_source_fighter_id": None,
                    "fighter_a_source_fighter_numeric_id": None,
                    "fighter_b_name": "Pending B",
                    "fighter_b_url": None,
                    "fighter_b_source_fighter_id": None,
                    "fighter_b_source_fighter_numeric_id": None,
                    "card_position": "unknown",
                    "weight_lbs": None,
                    "weight_class": None,
                }
            ],
        }
    )

    commit_fixture(store, "20260512T194000Z", incomplete)
    commit_fixture(store, "20260512T194100Z", incomplete)
    queue = json_at(tmp_path / "state" / "incomplete_events.json")

    assert list(queue) == ["tapology_event_1"]
    assert queue["tapology_event_1"]["source_event_status"] == "in_progress"
    assert queue["tapology_event_1"]["bouts_skipped_no_result"] == 1

    complete = fixture_results(count=1, event_date="2020-01-01")
    complete["events"][0].update(
        {
            "source_event_status": "completed",
            "source_bouts_total": 1,
            "bouts_included": 1,
            "bouts_skipped_total": 0,
            "bouts_skipped_no_result": 0,
            "needs_recheck": False,
            "skipped_bouts": [],
        }
    )
    commit_fixture(store, "20260512T194200Z", complete)

    assert json_at(tmp_path / "state" / "incomplete_events.json") == {}


def test_old_event_with_no_completed_pro_mma_bouts_is_no_relevant(tmp_path):
    store = DataStore(tmp_path)
    no_bouts = fixture_results(count=1, event_date="2020-01-01")
    no_bouts["events"][0]["bouts"] = []
    _, changes, _ = commit_fixture(store, "20260512T194000Z", no_bouts)
    events_index = json_at(tmp_path / "state" / "events_index.json")
    queue = json_at(tmp_path / "state" / "incomplete_events.json")

    assert changes["incomplete_events"] == []
    assert events_index["tapology_event_1"]["state"] == "no_relevant_pro_mma_bouts"
    assert events_index["tapology_event_1"]["needs_recheck"] is False
    assert queue == {}


def test_internal_ids_remain_unchanged_when_state_label_changes_on_recheck(tmp_path):
    store = DataStore(tmp_path)
    first_results, _, _ = commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2026-05-10"))
    changed = fixture_results(count=1, event_date="2020-01-01")
    second_results, _, _ = commit_fixture(store, "20260512T194100Z", changed)
    events_index = json_at(tmp_path / "state" / "events_index.json")

    assert second_results["events"][0]["internal_event_id"] == first_results["events"][0]["internal_event_id"]
    assert second_results["events"][0]["bouts"][0]["internal_bout_id"] == first_results["events"][0]["bouts"][0]["internal_bout_id"]
    assert events_index["tapology_event_1"]["state"] == "complete"


def test_recheck_decision_uses_index_not_single_cursor(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2020-01-01"))
    bundle = store.load_state_bundle()
    old_item = {"source_event_id": "tapology_event_1", "event_date": "2020-01-01"}
    recent_item = {"source_event_id": "tapology_event_1", "event_date": "2026-05-10"}

    should_scrape, reason = should_recheck_event(
        list_item=old_item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events=set(),
        force_full_scan=False,
    )
    assert should_scrape is False
    assert reason == "known_complete_outside_recheck_window"

    bundle["events_index"]["tapology_event_1"]["event_date"] = "2026-05-10"
    should_scrape, reason = should_recheck_event(
        list_item=recent_item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        recheck_complete_days=14,
        force_recheck_events=set(),
        force_full_scan=False,
    )
    assert should_scrape is True
    assert reason == "recent_recheck_window"

    should_scrape, reason = should_recheck_event(
        list_item=recent_item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events=set(),
        force_full_scan=False,
    )
    assert should_scrape is False
    assert reason == "known_complete_outside_recheck_window"


def test_force_recheck_accepts_source_event_id_and_event_url(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2020-01-01"))
    bundle = store.load_state_bundle()
    item = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_date": "2020-01-01",
    }

    by_id = should_recheck_event(
        list_item=item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events={"tapology_event_1"},
        force_full_scan=False,
    )
    by_url = should_recheck_event(
        list_item=item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events={"https://www.tapology.com/fightcenter/events/1-fixture-fc"},
        force_full_scan=False,
    )

    assert by_id == (True, "forced_event")
    assert by_url == (True, "forced_event")


def test_force_full_scan_rechecks_known_stable_event(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1, event_date="2020-01-01"))
    bundle = store.load_state_bundle()
    item = {"source_event_id": "tapology_event_1", "event_date": "2020-01-01"}

    decision = should_recheck_event(
        list_item=item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-12T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events=set(),
        force_full_scan=True,
    )

    assert decision == (True, "force_full_scan")


def test_incomplete_event_waits_until_next_recheck_after(tmp_path):
    store = DataStore(tmp_path)
    empty_event = fixture_results(count=1, event_date="2026-05-10")
    empty_event["events"][0]["bouts"] = []
    commit_fixture(store, "20260512T194000Z", empty_event)
    bundle = store.load_state_bundle()
    bundle["incomplete_events"]["tapology_event_1"]["next_recheck_after"] = "2026-05-14T00:00:00+00:00"
    item = {"source_event_id": "tapology_event_1", "event_date": "2026-05-10"}

    not_due = should_recheck_event(
        list_item=item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-13T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events=set(),
        force_full_scan=False,
    )
    due = should_recheck_event(
        list_item=item,
        bundle=bundle,
        now=storage.datetime.fromisoformat("2026-05-14T00:00:00+00:00"),
        recheck_days=14,
        recheck_known_limit=0,
        force_recheck_events=set(),
        force_full_scan=False,
    )

    assert not_due == (False, "incomplete_recheck_not_due")
    assert due == (True, "no_completed_professional_mma_bouts")


def test_failed_run_does_not_advance_success_or_internal_counters(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1))
    before = json_at(tmp_path / "state" / "scraper_state.json")

    bundle = store.begin_attempt("20260512T194100Z", STARTED)
    store.record_failed_attempt(
        run_dir=store.create_run_dir("20260512T194100Z"),
        bundle=bundle,
        run_id="20260512T194100Z",
        started_at=STARTED,
        finished_at=FINISHED,
        options=OPTIONS,
        counts={},
        warnings=[],
        errors=["fixture_failure"],
        partial_results=fixture_results(start=2, count=1),
    )
    after = json_at(tmp_path / "state" / "scraper_state.json")
    assert after["last_attempted_run_id"] == "20260512T194100Z"
    assert after["last_successful_run_id"] == before["last_successful_run_id"]
    assert after["next_internal_event_number"] == before["next_internal_event_number"]
    assert after["next_internal_bout_number"] == before["next_internal_bout_number"]


def test_simulated_state_write_failure_does_not_corrupt_previous_state(tmp_path, monkeypatch):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=1))
    before_state = json_at(tmp_path / "state" / "scraper_state.json")
    before_events = json_at(tmp_path / "state" / "events_index.json")

    real_atomic_write = storage.atomic_write_json

    def fail_bouts_index(path: Path, payload):
        if path.name == "bouts_index.json" and ".pending_" in str(path):
            raise OSError("simulated write failure")
        return real_atomic_write(path, payload)

    monkeypatch.setattr(storage, "atomic_write_json", fail_bouts_index)
    with pytest.raises(OSError):
        commit_fixture(store, "20260512T194100Z", fixture_results(start=2, count=1))

    after_state = json_at(tmp_path / "state" / "scraper_state.json")
    assert after_state["last_successful_run_id"] == before_state["last_successful_run_id"]
    assert after_state["next_internal_event_number"] == before_state["next_internal_event_number"]
    assert after_state["next_internal_bout_number"] == before_state["next_internal_bout_number"]
    assert json_at(tmp_path / "state" / "events_index.json") == before_events
    assert store.load_state_bundle()["events_index"] == before_events


def test_duplicate_internal_ids_fail_state_validation(tmp_path):
    store = DataStore(tmp_path)
    commit_fixture(store, "20260512T194000Z", fixture_results(count=2))
    bundle = store.load_state_bundle()
    bundle["events_index"]["tapology_event_2"]["internal_event_id"] = "mf_event_000001"

    errors = storage.validate_state_bundle(bundle)
    assert "duplicate_internal_event_id:mf_event_000001" in errors


def commit_fixture(
    store: DataStore,
    run_id: str,
    results_doc: dict,
    summary_warnings=None,
    options=None,
    cumulative_scope=None,
    cumulative_excluded=None,
    failed_event_examples=None,
):
    bundle = store.begin_attempt(run_id, STARTED)
    run_dir = store.create_run_dir(run_id)
    return store.commit_success(
        run_dir=run_dir,
        bundle=bundle,
        run_id=run_id,
        started_at=STARTED,
        finished_at=FINISHED,
        results_doc=results_doc,
        summary={
            "events_seen": len(results_doc["events"]),
            "bouts_seen": sum(len(event["bouts"]) for event in results_doc["events"]),
            "bouts_skipped_amateur": 0,
            "bouts_skipped_non_mma": 0,
            "cumulative_event_scope": cumulative_scope or [],
            "cumulative_excluded_event_ids": cumulative_excluded or [],
            "failed_event_examples": failed_event_examples or [],
            "event_fetch_failures": len(failed_event_examples or []),
            "warnings": summary_warnings or [],
            "errors": [],
        },
        options=options or OPTIONS,
    )


def fixture_results(count=5, start=1, event_date="2020-01-01"):
    return {
        "schema_version": RESULTS_SCHEMA_VERSION,
        "source": "tapology",
        "source_url": "https://www.tapology.com/fightcenter",
        "scraped_at": STARTED,
        "filters": {
            "promotion": "all",
            "schedule": "results",
            "sport": "mma",
            "region": "all",
            "exclude_promotions": ["ufc"],
        },
        "run": {"run_id": "fixture", "started_at": STARTED, "finished_at": None},
        "events": [fixture_event(index, event_date=event_date) for index in range(start, start + count)],
    }


def fixture_event(index: int, event_date: str):
    return {
        "source_event_id": f"tapology_event_{index}",
        "event_url": f"https://www.tapology.com/fightcenter/events/{index}-fixture-fc",
        "event_name": f"Fixture FC {index}",
        "promotion_name": "Fixture FC",
        "promotion_url": "https://www.tapology.com/fightcenter/promotions/100-fixture-fc",
        "event_date": event_date,
        "location": "Zagreb, Croatia",
        "bouts": [
            {
                "source_bout_id": f"tapology_bout_{index}",
                "bout_url": f"https://www.tapology.com/fightcenter/bouts/{index}-fighter-a-vs-fighter-b",
                "status": "completed",
                "pro_am": "professional",
                "card_position": "main_card",
                "weight_class": "Lightweight",
                "weight_lbs": 155,
                "winner": "fighter_a",
                "loser": "fighter_b",
                "result_type": "win_loss",
                "fighter_a": {
                    "name": f"Fighter A {index}",
                    "url": f"https://www.tapology.com/fightcenter/fighters/{index}01-fighter-a",
                    "source_fighter_id": f"tapology_fighter_{index}01",
                    "prefight_record": {"raw": "1-0"},
                },
                "fighter_b": {
                    "name": f"Fighter B {index}",
                    "url": f"https://www.tapology.com/fightcenter/fighters/{index}02-fighter-b",
                    "source_fighter_id": f"tapology_fighter_{index}02",
                    "prefight_record": {"raw": "0-1"},
                },
                "result": {"method_category": "decision", "method_detail": "unanimous", "round": 3, "time": "5:00"},
            }
        ],
    }


def json_at(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))
