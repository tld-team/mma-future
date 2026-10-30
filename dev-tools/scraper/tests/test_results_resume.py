from copy import deepcopy
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path

import pytest

from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.event_coverage import PARSER_VERSION, document as coverage_document
from mma_future_scraper.event_recovery import reparse_event_snapshot
from mma_future_scraper.results_resume import load_results_checkpoints, recover_results_snapshot
from mma_future_scraper.storage import DataStore, atomic_write_json
from mma_future_scraper.tapology import TapologyScraper, FatalScrapeError


NOW = datetime.now(timezone.utc)
OBSERVED = (NOW - timedelta(hours=1)).isoformat()
FILTERS = {"promotion": "all", "schedule": "results", "sport": "mma", "region": "all", "exclude_promotions": ["ufc"]}
FIXTURES = Path(__file__).parent / "fixtures/event_cards"


def snapshot(name="pfl_new_york", *, partial=False, observed=OBSERVED):
    data = json.loads((FIXTURES / f"{name}.json").read_text())
    rows = deepcopy(data["rows"])
    if partial:
        rows[0].update(method_raw=None, result_badges=[None, None], raw_text="Professional MMA · Main Card")
    return {
        "parser_version": PARSER_VERSION, "source_url": data["url"], "observed_at": observed,
        "event": {"event_name": data["title"], "event_date": "2026-07-31", "source_mma_bouts_total": data["source_mma_bouts_total"]},
        "rows": rows,
    }


def checkpoint(folder, snapshots):
    events, proofs = [], []
    for saved in snapshots:
        event, proof = reparse_event_snapshot(saved)
        proofs.append(proof)
        if event is not None:
            events.append(event)
        atomic_write_json(folder / "event_snapshots" / f"{proof['source_event_id']}.json", saved)
    document = TapologyScraper(ScraperConfig())._empty_document("results", folder.name, OBSERVED)
    document["events"] = events
    document["run"].update(checkpoint_partial=True, checkpoint_at=OBSERVED)
    atomic_write_json(folder / "checkpoint_results.json", document)
    atomic_write_json(folder / "event_coverage.json", coverage_document(events, proofs, folder.name))
    atomic_write_json(folder / "run_manifest.json", {"run_id": folder.name, "source": "tapology", "status": "failed"})
    return folder / "checkpoint_results.json", events


def test_only_complete_checkpoints_are_reused_without_changing_source_files(tmp_path):
    path, events = checkpoint(tmp_path / "source", [snapshot(), snapshot("pfl_mena", partial=True)])
    originals = {p: p.read_bytes() for p in path.parent.rglob("*.json")}
    recovered = load_results_checkpoints([path], filters=FILTERS, now=NOW)
    assert recovered.events == [events[0]]
    assert recovered.report()["complete_events"] == 1
    assert recovered.deferred[0]["reason"] == "partial_requires_live_recheck"
    assert recovered.events[0]["event_coverage"]["observed_at"] == OBSERVED
    assert all(p.read_bytes() == raw for p, raw in originals.items())


def test_tampered_payload_and_wrong_scope_are_rejected(tmp_path):
    path, _ = checkpoint(tmp_path / "source", [snapshot()])
    with pytest.raises(ValueError, match="scope differs"):
        load_results_checkpoints([path], filters={**FILTERS, "sport": "boxing"}, now=NOW)
    document = json.loads(path.read_text())
    document["events"][0]["bouts"][0]["result"]["round"] = 99
    atomic_write_json(path, document)
    with pytest.raises(ValueError, match="hash mismatch"):
        load_results_checkpoints([path], filters=FILTERS, now=NOW)


def test_source_rows_must_still_confirm_the_saved_classification(tmp_path):
    path, _ = checkpoint(tmp_path / "source", [snapshot()])
    saved_path = next((path.parent / "event_snapshots").glob("*.json"))
    saved = json.loads(saved_path.read_text())
    saved["rows"][0].update(classification_text="", classification_evidence=None)
    atomic_write_json(saved_path, saved)
    result = load_results_checkpoints([path], filters=FILTERS, now=NOW)
    assert not result.events
    assert result.deferred[0]["reason"] == "snapshot_coverage_changed"


def test_expired_and_newer_partial_observations_are_not_reused(tmp_path):
    older, _ = checkpoint(tmp_path / "older", [snapshot()])
    result = load_results_checkpoints([older], filters=FILTERS, now=NOW + timedelta(hours=49))
    assert not result.events
    assert result.deferred[0]["reason"] == "expired_checkpoint_observation"
    newer, _ = checkpoint(tmp_path / "newer", [snapshot(partial=True, observed=NOW.isoformat())])
    result = load_results_checkpoints([older, newer], filters=FILTERS, now=NOW)
    assert not result.events
    assert result.deferred[0]["reason"] == "partial_requires_live_recheck"


class ListPage:
    url = "https://www.tapology.com/fightcenter"

    def wait_for_timeout(self, *_args):
        pass


def configure_listing(monkeypatch, scraper, items, live_events, snapshots):
    fetched = []
    monkeypatch.setattr(scraper, "_dismiss_overlays", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_extract_event_list_items", lambda *a: items)
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *a, **k: False)
    monkeypatch.setattr(scraper, "_pause", lambda *a, **k: None)

    def fetch(context, item, kind, run_dir, summary):
        sid = item["source_event_id"]
        fetched.append(sid)
        saved = snapshots[sid]
        atomic_write_json(run_dir / "event_snapshots" / f"{sid}.json", saved)
        event = deepcopy(live_events[sid])
        scraper._coverage_entries[sid] = event["event_coverage"]
        return event

    monkeypatch.setattr(scraper, "_scrape_event_page", fetch)
    return fetched


def item(event):
    return {key: event[key] for key in ("source_event_id", "event_url", "event_date", "event_name")}


def test_resume_does_not_consume_live_limit_and_partial_is_fetched(monkeypatch, tmp_path):
    path, saved_events = checkpoint(tmp_path / "source", [snapshot(), snapshot("pfl_mena", partial=True)])
    live_snapshot = snapshot("pfl_mena", observed=NOW.isoformat())
    live, _ = reparse_event_snapshot(live_snapshot)
    scraper = TapologyScraper(ScraperConfig(data_dir=tmp_path / "data", resume_results_checkpoints=(path,), max_events=1, max_event_fetches=1, empty_results_cooldown_hours=24))
    summary = scraper._empty_summary("new", NOW.isoformat())
    scraper._load_results_resume(summary, tmp_path / "new")
    fetched = configure_listing(monkeypatch, scraper, [item(e) for e in saved_events],
                                {live["source_event_id"]: live}, {live["source_event_id"]: live_snapshot})
    events = scraper._traverse_event_list(ListPage(), "results", object(), tmp_path / "new", summary)
    assert len(events) == 2
    assert fetched == [live["source_event_id"]]
    assert summary["results_events_skipped_from_checkpoint"] == 1
    assert summary["event_fetch_attempts"] == 1
    assert summary["pagination_stop_reason"] == "max_event_fetches"


def test_offline_snapshot_recovery_preserves_failed_source_and_original_timestamp(monkeypatch, tmp_path):
    path, events = checkpoint(tmp_path / "source", [snapshot()])
    before = {file: file.read_bytes() for file in path.parent.rglob("*.json")}
    def forbid_network(*args):
        raise AssertionError("Offline recovery must not launch a browser")
    monkeypatch.setattr("mma_future_scraper.tapology.launch_browser", forbid_network)
    source = path.parent / "event_snapshots" / f"{events[0]['source_event_id']}.json"
    output = recover_results_snapshot(source, tmp_path / "recovery", filters=FILTERS, now=NOW)
    recovered = load_results_checkpoints([output], filters=FILTERS, now=NOW)
    assert len(recovered.events) == 1 and len(recovered.events[0]["bouts"]) == 9
    assert recovered.events[0]["event_coverage"]["observed_at"] == OBSERVED
    assert recovered.events[0]["bouts"][0]["fighter_a"]["prefight_record"]["is_missing"]
    assert json.loads((output.parent / "run_manifest.json").read_text())["status"] == "offline_recovery"
    assert all(file.read_bytes() == raw for file, raw in before.items())


def test_offline_recovery_rejects_partial_cards(tmp_path):
    path, events = checkpoint(tmp_path / "source", [snapshot(partial=True)])
    source = path.parent / "event_snapshots" / f"{events[0]['source_event_id']}.json"
    with pytest.raises(ValueError, match="complete result card"):
        recover_results_snapshot(source, tmp_path / "recovery", filters=FILTERS, now=NOW)
    assert not (tmp_path / "recovery").exists()


@pytest.mark.parametrize("hours_since_observation,skipped", [(1, True), (25, False)])
def test_recent_empty_results_are_deferred_but_expired_cooldown_fetches_them(monkeypatch, tmp_path, hours_since_observation, skipped):
    observed = (NOW - timedelta(hours=hours_since_observation)).isoformat()
    empty = snapshot("pfl_mena", observed=observed)
    for row in empty["rows"]:
        row.update(method_raw=None, result_badges=[None, None])
    path, events = checkpoint(tmp_path / "source", [snapshot(), empty])
    empty_event = events[1]
    assert not empty_event["bouts"]
    scraper = TapologyScraper(ScraperConfig(data_dir=tmp_path / "data", resume_results_checkpoints=(path,), empty_results_cooldown_hours=24))
    summary = scraper._empty_summary("new", NOW.isoformat())
    scraper._load_results_resume(summary, tmp_path / "new")
    sid = empty_event["source_event_id"]
    fetched = configure_listing(monkeypatch, scraper, [item(empty_event)], {sid: empty_event}, {sid: empty})
    scraper._traverse_event_list(ListPage(), "results", object(), tmp_path / "new", summary)
    assert fetched == ([] if skipped else [sid])
    assert summary.get("results_empty_events_skipped", 0) == int(skipped)


def test_no_result_pages_also_consume_the_live_request_budget(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(max_event_fetches=1))
    events = [{"source_event_id": f"tapology_event_{i}", "event_url": f"https://www.tapology.com/fightcenter/events/{i}-test", "event_date": "2026-10-30", "event_name": "Amateur"} for i in (1, 2)]
    fetched = configure_listing(monkeypatch, scraper, events, {}, {})
    def no_results(*a):
        fetched.append(a[1]["source_event_id"])
        return {**a[1], "bouts": [], "warnings": ["bouts_skipped_amateur:1"]}
    monkeypatch.setattr(scraper, "_scrape_event_page", no_results)
    summary = scraper._empty_summary("new", NOW.isoformat())
    assert scraper._traverse_event_list(ListPage(), "results", object(), tmp_path, summary) == []
    assert fetched == ["tapology_event_1"]
    assert summary["pagination_stop_reason"] == "max_event_fetches"


def test_operator_interrupt_records_a_resumable_failure(monkeypatch, tmp_path):
    path, _ = checkpoint(tmp_path / "source", [snapshot()])
    data = tmp_path / "data"
    scraper = TapologyScraper(ScraperConfig(data_dir=data, resume_results_checkpoints=(path,)))
    def interrupt(*args):
        raise KeyboardInterrupt
    monkeypatch.setattr("mma_future_scraper.tapology.launch_browser", interrupt)
    with pytest.raises(KeyboardInterrupt):
        scraper.run(results=True, upcoming=False)
    new_path = next((data / "runs").glob("*/checkpoint_results.json"))
    manifest = json.loads((new_path.parent / "run_manifest.json").read_text())
    assert manifest["status"] == "failed"
    assert manifest["errors"] == ["operator_interrupted"]
    assert len(load_results_checkpoints([new_path], filters=FILTERS).events) == 1


@pytest.mark.parametrize("fail", [False, True])
def test_full_run_preserves_atomic_state_and_can_resume_again(monkeypatch, tmp_path, fail):
    path, saved_events = checkpoint(tmp_path / "source", [snapshot()])
    data = tmp_path / "data"
    store = DataStore(data)
    store.ensure_dirs()
    atomic_write_json(data / "latest/results.json", {"sentinel": "last success"})
    old_latest = (data / "latest/results.json").read_bytes()
    original_manifest = (path.parent / "run_manifest.json").read_bytes()
    config = ScraperConfig(data_dir=data, resume_results_checkpoints=(path,), max_event_fetches=1, max_events=1, cumulative_results=True)
    scraper = TapologyScraper(config)
    live_snapshot = snapshot("pfl_mena", observed=NOW.isoformat())
    live, _ = reparse_event_snapshot(live_snapshot)
    fetched = configure_listing(monkeypatch, scraper, [item(saved_events[0]), item(live)],
                                {live["source_event_id"]: live}, {live["source_event_id"]: live_snapshot})
    class Resource:
        def close(self): pass
        def stop(self): pass
    monkeypatch.setattr("mma_future_scraper.tapology.launch_browser", lambda *_a: (Resource(), None, Resource()))
    if fail:
        def blocked(*args):
            raise FatalScrapeError("Hard block detected: not a bot")
        monkeypatch.setattr(scraper, "_scrape_event_page", blocked)
    def scrape_kind(context, kind, run_id, started_at, run_dir, summary, **kwargs):
        document = scraper._empty_document(kind, run_id, started_at)
        try:
            document["events"] = scraper._traverse_event_list(ListPage(), kind, context, run_dir, summary)
        except FatalScrapeError as error:
            scraper._write_results_checkpoint(kind, run_id, started_at, run_dir, summary, getattr(error, "partial_events", []))
            raise
        scraper._refresh_run_counts(document)
        scraper._copy_summary_skip_counts(document, summary, kind)
        return document
    monkeypatch.setattr(scraper, "_scrape_kind", scrape_kind)
    summary = scraper.run(results=True, upcoming=False)
    assert summary["successful"] is not fail
    assert (path.parent / "run_manifest.json").read_bytes() == original_manifest
    new_checkpoint = next((data / "runs").glob("*/checkpoint_results.json"))
    recovered = load_results_checkpoints([new_checkpoint], filters=FILTERS)
    assert recovered.events[0]["event_coverage"]["observed_at"] == OBSERVED
    if fail:
        assert (data / "latest/results.json").read_bytes() == old_latest
        index_path = data / "state/events_index.json"
        assert not index_path.exists() or not json.loads(index_path.read_text()).get(saved_events[0]["source_event_id"])
    else:
        assert fetched == [live["source_event_id"]]
        manifest = json.loads((data / "latest/run_manifest.json").read_text())
        assert manifest["counts"]["events_from_checkpoint"] == 1
        assert manifest["counts"]["events_scraped_this_run"] == 1
        indexed = json.loads((data / "state/events_index.json").read_text())
        assert indexed[saved_events[0]["source_event_id"]]["last_scraped_at"] == OBSERVED
        assert len(json.loads((data / "latest/results.json").read_text())["events"]) == 2
