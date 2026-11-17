import json
from datetime import datetime, timedelta, timezone

import pytest

from mma_future_scraper.cli import main
from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.listing_plan import (
    SETTLED,
    SKIP,
    STALE,
    WAITING,
    WORK,
    ListingClassifier,
    ListingPlanner,
    format_plan_summary,
    load_coverage_history,
)
from mma_future_scraper.tapology import FatalScrapeError, TapologyScraper

NOW = datetime(2026, 11, 17, 12, 0, tzinfo=timezone.utc)


def day(offset):
    return (NOW.date() - timedelta(days=offset)).isoformat()


def url(n):
    return f"https://www.tapology.com/fightcenter/events/{n}-event-{n}"


def sid(n):
    return f"tapology_event_{n}"


def item(n, offset, **extra):
    return {"source_event_id": sid(n), "event_url": url(n), "event_date": day(offset), "event_name": f"Event {n}", **extra}


def known(n, offset, state="complete", **extra):
    return {"source_event_id": sid(n), "event_url": url(n), "event_date": day(offset), "state": state, **extra}


def payload(n):
    return {"source_event_id": sid(n), "event_url": url(n), "bouts": [{"source_bout_id": f"b{n}"}]}


def bundle(index=(), incomplete=(), payloads=None):
    index = {record["source_event_id"]: record for record in index}
    return {
        "events_index": index,
        "incomplete_events": {record["source_event_id"]: record for record in incomplete},
        "event_payloads": payloads if payloads is not None else {key: payload(key.split("_")[-1]) for key in index},
    }


def queued(n, offset, reason, due_in_hours=-1, rechecks=1):
    return {
        "source_event_id": sid(n),
        "event_date": day(offset),
        "recheck_reason": reason,
        "recheck_count": rechecks,
        "next_recheck_after": (NOW + timedelta(hours=due_in_hours)).isoformat(),
    }


def classifier(state, **kwargs):
    return ListingClassifier(bundle=state, now=NOW, **kwargs)


# --- classification --------------------------------------------------------

def test_unknown_listing_event_is_work():
    decision = classifier(bundle([known(1, 20)])).classify(item(2, 3))
    assert (decision.action, decision.reason) == (WORK, "new_visible_event")


def test_listing_item_without_source_id_is_still_fetched():
    assert classifier(bundle()).classify({"event_url": "https://e/x"}).action == WORK


def test_complete_known_event_is_settled_but_missing_payload_is_work():
    state = bundle([known(1, 5), known(2, 5)], payloads={sid(1): payload(1)})
    c = classifier(state)
    assert c.classify(item(1, 5)).reason == "known_complete"
    assert (c.classify(item(2, 5)).action, c.classify(item(2, 5)).reason) == (WORK, "missing_cached_payload")


def test_recheck_complete_days_keeps_recent_complete_events_in_scope():
    c = classifier(bundle([known(1, 2)]), recheck_complete_days=3)
    assert c.classify(item(1, 2)).reason == "recent_recheck_window"


def test_incomplete_event_filled_later_is_work_not_a_stop_signal():
    # The edge case: scraped while results were missing, Tapology filled it since.
    record = known(1, 6, state="partial", needs_recheck=True, last_warning_codes=["bouts_skipped_no_result:4"])
    c = classifier(bundle([record], [queued(1, 6, "in_progress")], payloads={}))
    assert (c.classify(item(1, 6)).action, c.classify(item(1, 6)).reason) == (WORK, "in_progress")


def test_incomplete_event_not_yet_due_waits():
    record = known(1, 1, state="partial", needs_recheck=True)
    c = classifier(bundle([record], [queued(1, 1, "in_progress", due_in_hours=5)]))
    assert c.classify(item(1, 1)).action == WAITING


@pytest.mark.parametrize(
    "codes,matchups,expected",
    [
        (["missing_prefight_record:12", "bouts_skipped_amateur:3"], False, (SETTLED, "structural_parser_warnings")),
        (["missing_prefight_record:12"], True, (WORK, "parser_warnings_fillable")),
        (["bouts_skipped_non_mma:2", "unknown_method:1"], False, (WORK, "parser_warnings_fillable")),
        (["source_mma_bouts_total_mismatch"], False, (WORK, "parser_warnings_fillable")),
        (["something_new_from_the_parser"], False, (WORK, "parser_warnings_fillable")),
    ],
)
def test_parser_warnings_split_into_structural_and_fillable(codes, matchups, expected):
    record = known(1, 10, state="parser_attention", needs_recheck=True, recheck_reason="parser_warnings",
                   last_warning_codes=codes)
    c = classifier(bundle([record], [queued(1, 10, "parser_warnings")]), scrape_matchup_pages=matchups)
    decision = c.classify(item(1, 10))
    assert (decision.action, decision.reason) == expected


def test_structural_warning_event_without_cached_card_is_still_fetched():
    record = known(1, 10, state="parser_attention", needs_recheck=True, last_warning_codes=["bouts_skipped_amateur:1"])
    c = classifier(bundle([record], [queued(1, 10, "parser_warnings")], payloads={}))
    assert c.classify(item(1, 10)).action == WORK


def test_long_incomplete_events_become_stale_after_repeated_rechecks():
    record = known(1, 50, state="partial", needs_recheck=True)
    c = classifier(bundle([record], [queued(1, 50, "in_progress", rechecks=3)]))
    assert c.classify(item(1, 50)).action == STALE
    young = classifier(bundle([record], [queued(1, 50, "in_progress", rechecks=2)]))
    assert young.classify(item(1, 50)).action == WORK


def test_coverage_history_remembers_exclusions_and_failures_across_runs(tmp_path):
    def write(run, observed, entries):
        path = tmp_path / "runs" / run / "event_coverage.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"events": [{"observed_at": observed, "parser_version": "v3", **e} for e in entries]}))

    write("20261001T000000Z", "2026-10-01T00:00:00+00:00", [{"source_event_id": sid(1), "status": "excluded"},
                                                            {"source_event_id": sid(2), "status": "unavailable"}])
    write("20261005T000000Z", "2026-10-05T00:00:00+00:00", [{"source_event_id": sid(3), "status": "excluded"}])
    write("20261010T000000Z", "2026-10-10T00:00:00+00:00", [{"source_event_id": sid(3), "status": "unavailable"}])
    history = load_coverage_history(tmp_path)
    c = classifier(bundle(), coverage_history=history)
    assert c.classify(item(1, 40)).reason == "known_excluded"
    assert c.classify(item(2, 40)).reason == "previous_fetch_failed"
    assert c.classify(item(3, 40)).reason == "previous_fetch_failed"  # newest observation wins


def test_newer_partial_coverage_reopens_a_complete_event():
    record = known(1, 4, last_successfully_scraped_at=(NOW - timedelta(days=3)).isoformat())
    history = {sid(1): {"status": "partial", "reasons": ["unresolved_bouts"], "observed_at": (NOW - timedelta(days=1)).isoformat()}}
    assert classifier(bundle([record]), coverage_history=history).classify(item(1, 4)).reason == "coverage_partial"
    legacy = {sid(1): {"status": "partial", "reasons": ["legacy_unverified"], "observed_at": NOW.isoformat()}}
    assert classifier(bundle([record]), coverage_history=legacy).classify(item(1, 4)).reason == "known_complete"


def test_unknown_events_before_coverage_start_are_skipped_unless_backfilling():
    state = bundle([known(1, 30)])
    assert classifier(state).classify(item(2, 45)).action == SKIP
    assert classifier(state, include_backfill=True).classify(item(2, 45)).action == WORK


def test_ufc_forced_checkpoint_and_cooldown_overrides():
    state = bundle([known(1, 5)])
    c = classifier(
        state,
        force_ids={sid(1)},
        checkpoint_complete_ids={sid(2)},
        empty_results_until={sid(3): NOW + timedelta(hours=2)},
        is_excluded_promotion=lambda listed: "UFC" in (listed.get("event_name") or ""),
    )
    assert c.classify(item(1, 5)).reason == "forced_event"
    assert c.classify(item(2, 5)).reason == "checkpoint_complete"
    assert c.classify(item(3, 5)).reason == "empty_results_cooldown"
    assert c.classify(item(4, 5, event_name="UFC Fight Night")).action == SKIP


# --- planning ----------------------------------------------------------------

def run_planner(planner, pages):
    for number, items in enumerate(pages, start=1):
        planner.add_page(number, items)
        if planner.should_stop():
            return planner
    planner.mark_exhausted()
    return planner


def test_plan_continues_past_known_events_to_the_filled_incomplete_one():
    index = [known(n, 1 + n) for n in range(1, 13)]
    index[6] = known(7, 8, state="partial", needs_recheck=True)
    state = bundle(index, [queued(7, 8, "in_progress")])
    pages = [
        [item(100, 0), item(1, 2), item(2, 3)],  # new event on top
        [item(3, 4), item(4, 5), item(5, 6)],  # all known complete
        [item(6, 7), item(7, 8), item(8, 9)],  # filled-later event on page 3
        [item(9, 10), item(10, 11)],
        [item(11, 12), item(12, 13)],
        [item(13, 14)],
    ]
    planner = run_planner(ListingPlanner(classifier(state), max_pages=10, lookback_days=8, date_margin_days=1), pages)
    assert planner.stop_reason == "settled_beyond_horizon"
    assert planner.last_work_page == 3
    assert planner.recommended_max_pages == 4
    report = planner.report(plan_id="p", generated_at=NOW.isoformat(), event_pause_seconds=10, page_pause_seconds=20)
    assert [entry["source_event_id"] for entry in report["work_events"]] == [sid(100), sid(7)]
    assert report["complete"] and not report["nothing_to_do"]
    assert report["counts_by_reason"][WORK] == {"new_visible_event": 1, "in_progress": 1}
    assert "recommended --max-pages: 4" in format_plan_summary(report)


def test_unknown_gap_events_past_the_horizon_keep_the_walk_going():
    state = bundle([known(n, n) for n in range(1, 41)])
    pages = [[item(1, 1)], [item(6, 6)], [item(50, 7)], [item(8, 8)], [item(9, 9)], [item(10, 10)]]
    planner = ListingPlanner(classifier(state), max_pages=20, settle_pages=3, lookback_days=5, date_margin_days=0)
    run_planner(planner, pages)
    assert planner.last_work_page == 3  # gap event added to Tapology after our scrape
    assert planner.stop_reason == "settled_beyond_horizon"
    assert len(planner.pages) == 6


def test_page_cap_marks_plan_incomplete_and_widens_recommendation():
    planner = run_planner(ListingPlanner(classifier(bundle()), max_pages=2), [[item(1, 1)], [item(2, 2)], [item(3, 3)]])
    assert planner.stop_reason == "max_pages_cap"
    assert not planner.complete
    assert planner.recommended_max_pages == 3


def test_first_run_without_state_walks_until_cap_or_end():
    planner = run_planner(ListingPlanner(classifier(bundle()), max_pages=10), [[item(1, 1)], [item(2, 2)]])
    assert planner.stop_reason == "listing_exhausted"
    assert planner.recommended_max_pages == 2


def test_duplicates_from_load_more_are_ignored_and_undated_pages_never_pass_horizon():
    state = bundle([known(1, 1), known(2, 2)])
    undated = {"source_event_id": sid(2), "event_url": url(2), "event_date": None}
    planner = ListingPlanner(classifier(state), max_pages=5, settle_pages=1, date_margin_days=0)
    planner.add_page(1, [item(1, 1), item(1, 1)])
    planner.add_page(2, [undated])
    assert len(planner.entries) == 2
    assert not planner.passed_horizon
    assert not planner.should_stop()


def test_nothing_to_do_and_targets_missing_from_listing_are_reported():
    open_event = known(9, 3, state="partial", needs_recheck=True)
    state = bundle([known(1, 1), known(2, 2), open_event], [queued(9, 3, "in_progress")])
    planner = run_planner(
        ListingPlanner(classifier(state), max_pages=5, settle_pages=1, date_margin_days=0),
        [[item(1, 1)], [item(2, 2)], [item(3, 10, event_name="old")]],
    )
    report = planner.report(plan_id="p", generated_at=NOW.isoformat())
    assert report["work_events_count"] == 0 and report["recommended_max_pages"] == 0
    assert [entry["source_event_id"] for entry in report["open_targets_not_in_listing"]] == [sid(9)]
    assert not report["nothing_to_do"]


def test_plan_with_only_settled_events_has_nothing_to_do():
    planner = run_planner(
        ListingPlanner(classifier(bundle([known(1, 1), known(2, 2)])), max_pages=5, settle_pages=1, date_margin_days=0),
        [[item(1, 1)], [item(2, 2)], [item(2, 2)]],
    )
    assert planner.report(plan_id="p", generated_at=NOW.isoformat())["nothing_to_do"]


# --- scraper integration ----------------------------------------------------

class ListPage:
    url = "https://www.tapology.com/fightcenter"

    def wait_for_timeout(self, *_args):
        pass


def scraper_with_state(tmp_path, state, **config):
    options = {"cumulative_results": True, "plan_listing": True, "skip_settled_events": True, "plan_confirm": False}
    scraper = TapologyScraper(ScraperConfig(data_dir=tmp_path, **{**options, **config}))
    scraper._state_bundle = state
    scraper._listing_classifier = classifier(state, is_excluded_promotion=scraper._list_item_matches_excluded_ufc)
    return scraper


def test_plan_walk_then_single_pass_fetches_only_planned_work(monkeypatch, tmp_path):
    index = [known(n, n + 1) for n in range(1, 9)]
    index[2] = known(3, 4, state="partial", needs_recheck=True)
    state = bundle(index, [queued(3, 4, "in_progress")])
    scraper = scraper_with_state(tmp_path, state, plan_settle_pages=1, plan_lookback_days=3)
    pages = [[item(100, 0), item(1, 2)], [item(2, 3), item(3, 4)], [item(4, 5), item(5, 6)], [item(6, 7), item(7, 8)]]
    dom = []
    loaded = {"pages": 1}

    def extract(page, page_number):
        dom[:] = [listed for chunk in pages[: loaded["pages"]] for listed in chunk]
        return [dict(listed, list_page_number=page_number) for listed in dom]

    def advance(*_args, **_kwargs):
        if loaded["pages"] >= len(pages):
            return False
        loaded["pages"] += 1
        return True

    fetched = []

    def fetch(context, listed, kind, run_dir, summary):
        fetched.append((listed["source_event_id"], listed["list_page_number"], listed["recheck_reason"]))
        return {**listed, "bouts": [{"source_bout_id": "x"}], "warnings": []}

    monkeypatch.setattr(scraper, "_extract_event_list_items", extract)
    monkeypatch.setattr(scraper, "_advance_list_page", advance)
    monkeypatch.setattr(scraper, "_pause", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_dismiss_overlays", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_raise_if_blocked", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_scrape_event_page", fetch)
    monkeypatch.setattr(scraper, "_write_results_checkpoint", lambda *a, **k: None)
    summary = scraper._empty_summary("run-1", NOW.isoformat())

    plan = scraper._walk_listing_plan(ListPage(), tmp_path, summary)
    events = scraper._traverse_event_list(ListPage(), "results", object(), tmp_path, summary)

    assert plan["recommended_max_pages"] == 3 and plan["stop_reason"] == "settled_beyond_horizon"
    assert fetched == [(sid(100), 1, "new_visible_event"), (sid(3), 2, "in_progress")]
    assert len(events) == 2
    assert summary["events_skipped_settled"] == len(dom) - 2
    assert summary["pagination_stop_reason"] == "listing_plan_settled"
    assert summary["pages_scanned"] == plan["pages_walked"]
    assert summary["listing_plan"]["work_events_count"] == 2
    saved = json.loads((tmp_path / "plans" / "latest_listing_plan.json").read_text())
    assert saved["plan_id"] == "run-1" and (tmp_path / "listing_plan.json").exists()


def test_blocked_plan_walk_saves_partial_plan_and_raises(monkeypatch, tmp_path):
    scraper = scraper_with_state(tmp_path, bundle([known(1, 1)]))
    monkeypatch.setattr(scraper, "_extract_event_list_items", lambda page, n: [item(n + 10, n)])
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *a, **k: True)
    monkeypatch.setattr(scraper, "_pause", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_dismiss_overlays", lambda *a, **k: None)

    def blocked(page, run_dir, name):
        raise FatalScrapeError("cloudflare_challenge_detected")

    monkeypatch.setattr(scraper, "_raise_if_blocked", blocked)
    summary = scraper._empty_summary("run-2", NOW.isoformat())
    with pytest.raises(FatalScrapeError):
        scraper._walk_listing_plan(ListPage(), tmp_path, summary)
    saved = json.loads((tmp_path / "plans" / "listing_plan_run-2.json").read_text())
    assert saved["stop_reason"] == "interrupted" and not saved["complete"]
    assert saved["interrupted_reason"] == "cloudflare_challenge_detected"
    assert saved["recommended_max_pages"] == 2


def test_operator_can_stop_after_reading_the_plan(monkeypatch, tmp_path):
    scraper = scraper_with_state(tmp_path, bundle(), plan_confirm=True)
    scraper._listing_plan = {"work_events_count": 3}
    monkeypatch.setattr("sys.stdin.isatty", lambda: True, raising=False)
    monkeypatch.setattr("builtins.input", lambda prompt: "q")
    with pytest.raises(FatalScrapeError, match="operator_stopped_after_listing_plan"):
        scraper._confirm_listing_plan()


def test_skip_settled_without_plan_still_uses_listing_pages(monkeypatch, tmp_path):
    state = bundle([known(1, 1), known(2, 2)])
    scraper = scraper_with_state(tmp_path, state, max_pages=2)
    scraper.config = ScraperConfig(data_dir=tmp_path, cumulative_results=True, skip_settled_events=True, max_pages=2)
    pages = {1: [item(1, 1), item(5, 1)], 2: [item(2, 2)]}
    monkeypatch.setattr(scraper, "_extract_event_list_items", lambda page, n: pages[n])
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *a, **k: True)
    monkeypatch.setattr(scraper, "_pause", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_dismiss_overlays", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_raise_if_blocked", lambda *a, **k: None)
    monkeypatch.setattr(scraper, "_write_results_checkpoint", lambda *a, **k: None)
    fetched = []
    monkeypatch.setattr(scraper, "_scrape_event_page",
                        lambda c, listed, k, r, s: fetched.append(listed["source_event_id"]) or {**listed, "bouts": [{"source_bout_id": "x"}]})
    summary = scraper._empty_summary("run-3", NOW.isoformat())
    scraper._traverse_event_list(ListPage(), "results", object(), tmp_path, summary)
    assert fetched == [sid(5)]
    assert summary["pagination_stop_reason"] == "max_pages"


# --- CLI -------------------------------------------------------------------

@pytest.mark.parametrize("flags", [["--plan-listing"], ["--plan-only"], ["--skip-settled-events"]])
@pytest.mark.parametrize("extra", [[], ["--results-only", "--fresh-results"], ["--results-only", "--force-full-scan"],
                                   ["--results-only", "--mode", "backfill"]])
def test_planning_flags_reject_incompatible_modes(flags, extra, capsys):
    assert main(["run", *flags, *extra]) == 2
    assert "require --results-only" in capsys.readouterr().err


def test_plan_only_runs_the_plan_and_never_the_scrape(monkeypatch):
    observed = {}

    class Scraper:
        def __init__(self, config):
            observed["config"] = config

        def plan_listing(self):
            return {"complete": True}

        def run(self, **kwargs):
            pytest.fail("plan-only must not scrape")

    monkeypatch.setattr("mma_future_scraper.cli.TapologyScraper", Scraper)
    assert main(["run", "--results-only", "--headed", "--plan-only", "--plan-max-pages", "12", "--no-plan-confirm"]) == 0
    config = observed["config"]
    assert config.skip_settled_events and not config.plan_listing and config.plan_max_pages == 12
    assert config.plan_confirm is False
