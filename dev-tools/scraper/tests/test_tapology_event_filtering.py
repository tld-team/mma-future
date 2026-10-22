import json

import pytest

import mma_future_scraper.tapology as tapology_mod
from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.normalize import explicit_non_mma_event_reason
from mma_future_scraper.storage import DataStore
from mma_future_scraper.tapology import (
    FatalScrapeError,
    TapologyScraper,
    should_skip_results_event_no_usable_bouts,
    skipped_results_event_category,
    skipped_results_event_is_non_mma_or_no_results,
)


def test_results_event_with_no_usable_bouts_is_skipped():
    event = {
        "event_name": "Example Kickboxing Event",
        "warnings": ["bouts_skipped_non_mma:6"],
        "bouts": [],
    }

    assert should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_is_non_mma_or_no_results(event)


def test_event_level_non_mma_warning_is_recognized_as_skip_reason():
    event = {
        "event_name": "RAF 9",
        "warnings": ["event_skipped_non_mma:non_mma_event_freestyle"],
        "bouts": [],
    }

    assert should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_is_non_mma_or_no_results(event)


def test_results_event_with_usable_bout_is_not_skipped():
    event = {"event_name": "Example MMA Event", "warnings": [], "bouts": [{"source_bout_id": "tapology_bout_1"}]}

    assert not should_skip_results_event_no_usable_bouts(event)


def test_default_document_filters_are_all_mma_with_ufc_exclusion():
    scraper = TapologyScraper(ScraperConfig())
    document = scraper._empty_document("results", "run-1", "2026-05-12T19:40:00+00:00")

    assert document["filters"] == {
        "promotion": "all",
        "schedule": "results",
        "sport": "mma",
        "region": "all",
        "exclude_promotions": ["ufc"],
    }


def test_profile_enrichment_is_not_implicitly_enabled_by_force_recheck_events():
    scraper = TapologyScraper(ScraperConfig(force_recheck_events=("tapology_event_141836",)))

    assert scraper._effective_enrich_fighter_profiles() is False


def test_upcoming_resume_checkpoint_skips_completed_event(monkeypatch, tmp_path):
    checkpoint_path = tmp_path / "checkpoint_upcoming.json"
    checkpoint_path.write_text(
        json.dumps(
            {
                "schema_version": "scraper_upcoming_v0_1",
                "events": [
                    {
                        "source_event_id": "tapology_event_1",
                        "event_url": "https://www.tapology.com/fightcenter/events/1-done",
                        "event_name": "Already Done",
                        "event_date": "2026-10-23",
                        "bouts": [],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    scraper = TapologyScraper(
        ScraperConfig(
            from_date="2026-10-23",
            until_date="2026-10-24",
            resume_upcoming_checkpoint=checkpoint_path,
            event_delay_ms=0,
        )
    )
    summary = scraper._empty_summary("run-1", "2026-10-23T00:00:00+00:00")
    scraped = []

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_date": "2026-10-23",
                "event_url": "https://www.tapology.com/fightcenter/events/1-done",
            },
            {
                "source_event_id": "tapology_event_2",
                "event_date": "2026-10-24",
                "event_url": "https://www.tapology.com/fightcenter/events/2-new",
            },
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)

    def scrape_event(_context, item, _kind, _run_dir, _summary):
        scraped.append(item["source_event_id"])
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": "New Event",
            "event_date": item["event_date"],
            "warnings": [],
            "bouts": [],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", scrape_event)

    events = scraper._traverse_event_list(_ListPage(), "upcoming", object(), tmp_path, summary)

    assert [event["source_event_id"] for event in events] == ["tapology_event_1", "tapology_event_2"]
    assert scraped == ["tapology_event_2"]
    assert summary["upcoming_events_resumed"] == 1
    assert summary["upcoming_events_skipped_from_checkpoint"] == 1
    checkpoint = json.loads((tmp_path / "checkpoint_upcoming.json").read_text(encoding="utf-8"))
    assert len(checkpoint["events"]) == 2


class _ListPage:
    def wait_for_timeout(self, ms):
        return None


class _NoFightCenterContext:
    def new_page(self):
        raise AssertionError("FightCenter listing page should not be opened for direct forced event targets")


def test_daily_mode_counts_skipped_known_stable_events_and_early_stop(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(stop_after_known_complete=2, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
            "tapology_event_2": {"state": "complete", "event_date": "2020-01-02"},
        },
        "incomplete_events": {},
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda page, page_number: [
            {"source_event_id": "tapology_event_1", "event_date": "2020-01-01", "event_url": "https://e/1"},
            {"source_event_id": "tapology_event_2", "event_date": "2020-01-02", "event_url": "https://e/2"},
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda page, summary_arg, seen_event_urls=None, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda context, item, kind, run_dir, summary_arg: pytest.fail("known stable event should be skipped"),
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert events == []
    assert summary["events_skipped_known_stable"] == 2
    assert summary["early_stop_triggered"] is True
    assert summary["early_stop_reason"] == "2 consecutive known stable events outside recheck scope"


def test_backfill_mode_revisits_known_stable_events(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(run_mode="backfill", stop_after_known_complete=1, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
            "tapology_event_2": {"state": "complete", "event_date": "2020-01-02"},
        },
        "incomplete_events": {},
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda page, page_number: [
            {"source_event_id": "tapology_event_1", "event_date": "2020-01-01", "event_url": "https://e/1"},
            {"source_event_id": "tapology_event_2", "event_date": "2020-01-02", "event_url": "https://e/2"},
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda page, summary_arg, seen_event_urls=None, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda context, item, kind, run_dir, summary_arg: {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["source_event_id"],
            "event_date": item["event_date"],
            "warnings": [],
            "bouts": [{"source_bout_id": f"{item['source_event_id']}_bout"}],
        },
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert [event["source_event_id"] for event in events] == ["tapology_event_1", "tapology_event_2"]
    assert summary["events_skipped_known_stable"] == 0
    assert summary["early_stop_triggered"] is False


def test_backfill_skip_known_complete_uses_state_cache(monkeypatch, tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(run_mode="backfill", skip_known_complete=True, stop_after_known_complete=2, recheck_known_limit=0)
    )
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
            "tapology_event_2": {"state": "complete", "event_date": "2020-01-02"},
        },
        "incomplete_events": {},
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda page, page_number: [
            {"source_event_id": "tapology_event_1", "event_date": "2020-01-01", "event_url": "https://e/1"},
            {"source_event_id": "tapology_event_2", "event_date": "2020-01-02", "event_url": "https://e/2"},
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda page, summary_arg, seen_event_urls=None, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda context, item, kind, run_dir, summary_arg: pytest.fail("known complete event should use state cache"),
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert events == []
    assert summary["events_skipped_known_stable"] == 2
    assert summary["events_skipped_known_cache"] == 2
    assert scraper._runtime_diagnostic_counts()["events_skipped_known_cache"] == 2
    assert summary["early_stop_triggered"] is True


def test_cumulative_known_complete_with_payload_records_scope_without_scrape(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(cumulative_results=True, stop_after_known_complete=2, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
        },
        "incomplete_events": {},
        "event_payloads": {
            "tapology_event_1": {
                "source_event_id": "tapology_event_1",
                "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
                "event_name": "Fixture FC 1",
                "event_date": "2020-01-01",
                "warnings": ["missing_source_promotion_id"],
                "bouts": [{"source_bout_id": "tapology_bout_1"}],
            }
        },
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_date": "2020-01-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
            }
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda *_args, **_kwargs: pytest.fail("cached cumulative event should not be scraped"),
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert events == []
    assert summary["events_skipped_known_cache"] == 1
    assert summary["missing_cached_event_payloads"] == 0
    assert [item["source_event_id"] for item in summary["cumulative_event_scope"]] == ["tapology_event_1"]


def test_cumulative_known_complete_missing_payload_warns_and_scrapes(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(cumulative_results=True, stop_after_known_complete=2, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
        },
        "incomplete_events": {},
        "event_payloads": {},
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_date": "2020-01-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
            }
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda _context, item, _kind, _run_dir, _summary: {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": "Fixture FC 1",
            "event_date": item["event_date"],
            "warnings": ["missing_source_promotion_id"],
            "skipped_bouts": [],
            "bouts": [{"source_bout_id": "tapology_bout_1"}],
        },
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert [event["source_event_id"] for event in events] == ["tapology_event_1"]
    assert summary["events_skipped_known_cache"] == 0
    assert summary["missing_cached_event_payloads"] == 1
    assert "missing_cached_event_payload:tapology_event_1" in summary["warnings"]


def test_cumulative_known_no_relevant_event_without_payload_is_excluded_not_scraped(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(cumulative_results=True, recheck_days=0, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "no_relevant_pro_mma_bouts", "event_date": "2020-01-01"},
        },
        "incomplete_events": {},
        "event_payloads": {},
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_date": "2020-01-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
            }
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda *_args, **_kwargs: pytest.fail("known no-relevant event should stay excluded from cumulative payload output"),
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert events == []
    assert summary["missing_cached_event_payloads"] == 0
    assert summary["cumulative_excluded_event_ids"] == ["tapology_event_1"]


def test_fresh_results_revisits_known_stable_event(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(fresh_results=True, stop_after_known_complete=1, recheck_known_limit=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {
        "events_index": {
            "tapology_event_1": {"state": "complete", "event_date": "2020-01-01"},
        },
        "incomplete_events": {},
        "event_payloads": {
            "tapology_event_1": {
                "source_event_id": "tapology_event_1",
                "bouts": [{"source_bout_id": "tapology_bout_1"}],
            }
        },
    }
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_date": "2020-01-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
            }
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda _context, item, _kind, _run_dir, _summary: {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": "Fixture FC 1",
            "event_date": item["event_date"],
            "warnings": ["missing_source_promotion_id"],
            "skipped_bouts": [],
            "bouts": [{"source_bout_id": "tapology_bout_1"}],
        },
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert [event["source_event_id"] for event in events] == ["tapology_event_1"]
    assert summary["events_skipped_known_cache"] == 0


def test_direct_forced_event_targets_bypass_fightcenter_filters(monkeypatch, tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(
            force_recheck_events=("https://www.tapology.com/fightcenter/events/1-fixture-fc",),
            max_events=1,
        )
    )
    scraper._store = DataStore(tmp_path)
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    direct_items = [
        {
            "source_event_id": "tapology_event_1",
            "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
            "event_name": "Fixture FC",
            "recheck_reason": "forced_event",
        }
    ]

    def fake_scrape_event_page(context, item, kind, run_dir, summary_arg):
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["event_name"],
            "event_date": "2026-05-29",
            "warnings": [],
            "bouts": [{"source_bout_id": "tapology_bout_1"}],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", fake_scrape_event_page)

    document = scraper._scrape_kind(
        _NoFightCenterContext(),
        "results",
        "run-1",
        "2026-05-12T19:40:00+00:00",
        tmp_path,
        summary,
        direct_event_items=direct_items,
    )

    assert document["source_url"] == "https://www.tapology.com/fightcenter/events/1-fixture-fc"
    assert document["events"][0]["source_event_id"] == "tapology_event_1"
    assert summary["filters_used"] == {}


def test_forced_source_event_id_resolves_to_known_event_url(tmp_path):
    scraper = TapologyScraper(ScraperConfig(force_recheck_events=("tapology_event_142222",)))
    scraper._store = DataStore(tmp_path)

    items = scraper._resolve_direct_forced_event_items(
        {
            "events_index": {
                "tapology_event_142222": {
                    "source_event_id": "tapology_event_142222",
                    "event_url": "https://www.tapology.com/fightcenter/events/142222-lux-fight-league-61",
                    "event_name": "Lux Fight League 61",
                    "event_date": "2026-05-29",
                }
            }
        }
    )

    assert items == [
        {
            "event_name": "Lux Fight League 61",
            "event_url": "https://www.tapology.com/fightcenter/events/142222-lux-fight-league-61",
            "source_event_id": "tapology_event_142222",
            "promotion_name": None,
            "event_date": "2026-05-29",
            "location": None,
            "region": None,
            "visible_main_bout": None,
            "list_page_number": None,
            "list_position": 1,
            "recheck_reason": "forced_event",
        }
    ]


def test_unresolved_forced_source_id_fails_before_browser_launch(monkeypatch, tmp_path):
    def fail_if_browser_launch(config):
        raise AssertionError("browser should not launch when a forced source ID cannot resolve to a URL")

    store = DataStore(tmp_path)
    monkeypatch.setattr(tapology_mod, "launch_browser", fail_if_browser_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)

    scraper = TapologyScraper(ScraperConfig(force_recheck_events=("tapology_event_999999",)))
    scraper._store = store

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["latest_updated"] is False
    assert any("source ID cannot be resolved to URL" in error for error in summary["errors"])
    assert not (tmp_path / "latest" / "results.json").exists()


def test_forced_event_with_no_valid_event_is_failed_not_success(monkeypatch, tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(force_recheck_events=("https://www.tapology.com/fightcenter/events/1-fixture-fc",))
    )
    scraper._store = DataStore(tmp_path)
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    direct_items = [
        {
            "source_event_id": "tapology_event_1",
            "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
            "event_name": "Fixture FC",
            "recheck_reason": "forced_event",
        }
    ]

    def fake_scrape_event_page(context, item, kind, run_dir, summary_arg):
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["event_name"],
            "warnings": ["fight_card_missing"],
            "bouts": [],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", fake_scrape_event_page)

    with pytest.raises(FatalScrapeError, match="forced_event_no_usable_bouts"):
        scraper._scrape_kind(
            _NoFightCenterContext(),
            "results",
            "run-1",
            "2026-05-12T19:40:00+00:00",
            tmp_path,
            summary,
            direct_event_items=direct_items,
        )


class _FakeCard:
    def __init__(self, rows):
        self.rows = rows

    def evaluate(self, script, selectors):
        return self.rows


class _FakePage:
    url = "https://www.tapology.com/fightcenter/events/1-fixture-fc"


def _row(raw_text, *, bout_href="", fighter_a="Jane Doe", fighter_b="Ann Other"):
    return {
        "raw_text": raw_text,
        "bout_href": bout_href,
        "fighter_links": [
            {"name": fighter_a, "href": "/fightcenter/fighters/1-jane-doe"},
            {"name": fighter_b, "href": "/fightcenter/fighters/2-ann-other"},
        ],
    }


class _BodyLocator:
    def __init__(self, text):
        self.text = text

    def inner_text(self, timeout=5000):
        return self.text


class _EventPage:
    def title(self): return "Fixture event"

    def __init__(self, body_text):
        self.body_text = body_text
        self.url = None

    def goto(self, url, wait_until=None, timeout=None):
        self.url = url

    def locator(self, selector):
        if selector == "body":
            return _BodyLocator(self.body_text)
        raise AssertionError(f"unexpected locator: {selector}")

    def close(self):
        return None


class _EventContext:
    def __init__(self, body_text):
        self.body_text = body_text

    def new_page(self):
        return _EventPage(self.body_text)


def _patch_event_metadata(monkeypatch, scraper):
    monkeypatch.setattr(tapology_mod, "dismiss_blocking_ui", lambda page, log_func, summary: None)
    monkeypatch.setattr(scraper, "_raise_if_blocked", lambda page, run_dir, name: None)
    monkeypatch.setattr(scraper, "_first_visible_text", lambda page, selectors: None)
    monkeypatch.setattr(scraper, "_extract_event_promotion", lambda page, text: None)
    monkeypatch.setattr(scraper, "_extract_promotion_url", lambda page: None)


def test_sampson_boxing_metadata_excludes_missing_non_mma_card(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    _patch_event_metadata(monkeypatch, scraper)
    monkeypatch.setattr(scraper, "_find_fight_card", lambda page: (None,None,False))
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    list_item = {
        "source_event_id": "tapology_event_144087",
        "event_url": "https://www.tapology.com/fightcenter/events/144087-romero-vs-arguello",
        "event_name": "Romero vs. Arguello",
        "promotion_name": "Sampson Boxing",
    }

    event = scraper._scrape_event_page(_EventContext("Enclosure: Ring Boxing Bouts: 6"), list_item, "results", tmp_path, summary)

    assert event["bouts"] == []
    assert "event_skipped_non_mma:non_mma_event_boxing" in event["warnings"]
    assert any(warning.startswith("event_skipped_non_mma:non_mma_event_boxing:") for warning in summary["warnings"])


def test_real_american_freestyle_open_mat_event_is_skipped(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    _patch_event_metadata(monkeypatch, scraper)
    monkeypatch.setattr(scraper, "_find_fight_card", lambda page: (None,None,False))
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    list_item = {
        "source_event_id": "tapology_event_141792",
        "event_url": "https://www.tapology.com/fightcenter/events/141792-raf-9",
        "event_name": "RAF 9: Steveson vs. Romanov",
        "promotion_name": "Real American Freestyle",
    }

    event = scraper._scrape_event_page(_EventContext("Venue: College Park Center Enclosure: Open Mat"), list_item, "results", tmp_path, summary)

    assert event["bouts"] == []
    assert "event_skipped_non_mma:non_mma_event_freestyle" in event["warnings"]


def test_forced_event_mode_rejects_event_level_non_mma(monkeypatch, tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(force_recheck_events=("https://www.tapology.com/fightcenter/events/141792-raf-9",))
    )
    scraper._store = DataStore(tmp_path)
    _patch_event_metadata(monkeypatch, scraper)
    monkeypatch.setattr(scraper, "_find_fight_card", lambda page: (None,None,False))
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    direct_items = [
        {
            "source_event_id": "tapology_event_141792",
            "event_url": "https://www.tapology.com/fightcenter/events/141792-raf-9",
            "event_name": "RAF 9: Steveson vs. Romanov",
            "promotion_name": "Real American Freestyle",
            "recheck_reason": "forced_event",
        }
    ]

    with pytest.raises(FatalScrapeError, match="forced_event_no_usable_bouts"):
        scraper._scrape_kind(
            _EventContext("Venue: College Park Center Enclosure: Open Mat"),
            "results",
            "run-1",
            "2026-05-12T19:40:00+00:00",
            tmp_path,
            summary,
            direct_event_items=direct_items,
        )

    assert summary["skipped_events_no_usable_bouts"] == 1
    assert summary["skipped_events_non_mma_or_no_results"] == 1


def test_event_level_helper_allows_known_valid_mma_promotions():
    for promotion_name in [
        "Pancrase Hybrid Wrestling",
        "Shooto",
        "Brave Combat Federation",
        "Shamrock Fighting Championships",
        "Tough N Xtreme",
    ]:
        assert explicit_non_mma_event_reason(promotion_name=promotion_name) is None


def test_forced_mixed_event_still_applies_bout_level_filters(tmp_path):
    scraper = TapologyScraper(ScraperConfig(force_recheck_events=("https://www.tapology.com/fightcenter/events/1-fixture-fc",)))
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_name": "Fixture FC",
        "event_date": "2026-05-29",
        "warnings": [],
    }
    card = _FakeCard(
        [
            _row("MAIN CARD 145 Pro MMA Jane Doe 1-0 W def. Ann Other 0-1 L Decision"),
            _row("MAIN CARD 145 Amateur MMA Skip Amateur vs. Example"),
            _row("MAIN CARD Pro Boxing Skip Boxer vs. Example"),
        ]
    )

    bouts = scraper._parse_bouts(card, event, _FakePage(), None, "results", summary)

    assert len(bouts) == 1
    assert bouts[0]["fighter_a"]["source_fighter_id"] == "tapology_fighter_1"
    assert "bouts_skipped_amateur:1" in event["warnings"]
    assert "bouts_skipped_non_mma:1" in event["warnings"]


def test_amateur_bout_is_skipped_before_matchup_detail_scrape(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_name": "Fixture FC",
        "event_date": "2026-05-29",
        "warnings": [],
    }
    card = _FakeCard(
        [
            _row(
                "MAIN CARD 145 Amateur MMA Skip Amateur 0-0 vs. Example 0-0",
                bout_href="/fightcenter/bouts/999-amateur",
                fighter_a="Skip Amateur",
                fighter_b="Example",
            )
        ]
    )

    def fail_matchup(*_args, **_kwargs):
        raise AssertionError("amateur bout should not open matchup detail page")

    monkeypatch.setattr(scraper, "_scrape_matchup_page", fail_matchup)

    bouts = scraper._parse_bouts(card, event, _FakePage(), object(), "results", summary)

    assert bouts == []
    assert event["skipped_bouts"][0]["reason"] == "amateur"
    assert event["skipped_bouts"][0]["source_bout_id"] == "tapology_bout_999"
    assert "bouts_skipped_amateur:1" in event["warnings"]


def test_skipped_event_category_for_boxing_event_is_non_mma():
    event = {
        "event_name": "Example Boxing Event",
        "warnings": ["event_skipped_non_mma:non_mma_event_boxing"],
        "bouts": [],
        "skipped_bouts": [],
    }

    assert should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_category(event) == "non_mma"


def test_skipped_event_counters_for_only_amateur_mma_bouts(tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_name": "Fixture FC",
        "event_date": "2026-05-29",
        "warnings": [],
    }
    card = _FakeCard([_row("MAIN CARD 145 Amateur MMA Jane Doe vs. Ann Other")])

    event["bouts"] = scraper._parse_bouts(card, event, _FakePage(), None, "results", summary)
    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 1", "results", "Completed MMA Event")
    scraper._record_skipped_results_event(summary, event)

    assert should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_category(event) == "all_amateur"
    assert summary["events_skipped_all_amateur"] == 1
    assert summary["events_skipped_no_usable_pro_mma_bouts"] == 1
    assert summary["skipped_event_examples"][0]["skip_category"] == "all_amateur"


def test_pending_pro_mma_event_with_only_no_result_bouts_is_retained(tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_name": "Fixture FC",
        "event_date": "2026-05-29",
        "warnings": [],
    }
    card = _FakeCard([_row("MAIN CARD 145 Pro MMA Jane Doe vs. Ann Other")])

    event["bouts"] = scraper._parse_bouts(card, event, _FakePage(), None, "results", summary)
    scraper._finalize_event_completeness(event, "IN PROGRESS MMA EVENT MMA Bouts: 1", "results", "In Progress MMA Event")

    assert event["bouts"] == []
    assert event["bouts_skipped_no_result"] == 1
    assert event["needs_recheck"] is True
    assert not should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_category(event) == "no_results"


def test_completed_pro_mma_bout_is_retained_not_skipped(tmp_path):
    scraper = TapologyScraper(ScraperConfig())
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": "https://www.tapology.com/fightcenter/events/1-fixture-fc",
        "event_name": "Fixture FC",
        "event_date": "2026-05-29",
        "warnings": [],
    }
    card = _FakeCard([_row("MAIN CARD 145 Pro MMA Jane Doe 1-0 W def. Ann Other 0-1 L Decision")])

    event["bouts"] = scraper._parse_bouts(card, event, _FakePage(), None, "results", summary)
    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 1", "results", "Completed MMA Event")

    assert len(event["bouts"]) == 1
    assert not should_skip_results_event_no_usable_bouts(event)
    assert skipped_results_event_category(event) is None


def test_forced_ufc_event_is_rejected_with_count(monkeypatch, tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(force_recheck_events=("https://www.tapology.com/fightcenter/events/2-ufc-fight-night",))
    )
    scraper._store = DataStore(tmp_path)
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    direct_items = [
        {
            "source_event_id": "tapology_event_2",
            "event_url": "https://www.tapology.com/fightcenter/events/2-ufc-fight-night",
            "event_name": "UFC Fight Night: Fixture vs. Example",
            "recheck_reason": "forced_event",
        }
    ]

    def fake_scrape_event_page(context, item, kind, run_dir, summary_arg):
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["event_name"],
            "promotion_name": "Ultimate Fighting Championship",
            "promotion_url": "https://www.tapology.com/fightcenter/promotions/1-ultimate-fighting-championship-ufc",
            "warnings": ["event_skipped_ufc"],
            "bouts": [],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", fake_scrape_event_page)

    with pytest.raises(FatalScrapeError, match="forced_event_skipped_ufc"):
        scraper._scrape_kind(
            _NoFightCenterContext(),
            "results",
            "run-1",
            "2026-05-12T19:40:00+00:00",
            tmp_path,
            summary,
            direct_event_items=direct_items,
        )

    assert summary["events_skipped_ufc"] == 1


def test_listing_ufc_event_is_skipped_before_event_page_scrape(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(event_delay_ms=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {"events_index": {}, "incomplete_events": {}}
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    scraped_urls = []

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_1",
                "event_url": "https://www.tapology.com/fightcenter/events/1-ufc-fight-night",
                "event_name": "UFC Fight Night: Fixture vs. Example",
                "promotion_name": "UFC",
                "event_date": "2026-06-01",
            },
            {
                "source_event_id": "tapology_event_2",
                "event_url": "https://www.tapology.com/fightcenter/events/2-regional-fc",
                "event_name": "Regional FC 1",
                "promotion_name": "Regional FC",
                "event_date": "2026-06-01",
            },
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)

    def fake_scrape_event_page(_context, item, _kind, _run_dir, _summary):
        scraped_urls.append(item["event_url"])
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["event_name"],
            "event_date": item["event_date"],
            "warnings": [],
            "bouts": [{"source_bout_id": "tapology_bout_1"}],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", fake_scrape_event_page)

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert scraped_urls == ["https://www.tapology.com/fightcenter/events/2-regional-fc"]
    assert [event["source_event_id"] for event in events] == ["tapology_event_2"]
    assert summary["events_skipped_ufc"] == 1
    assert summary["skipped_event_examples"][0]["source_event_id"] == "tapology_event_1"


def test_max_pages_three_scans_at_most_three_batches(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(max_pages=3, run_mode="backfill", event_delay_ms=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {"events_index": {}, "incomplete_events": {}}
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    advance_calls = []

    def fake_items(_page, page_number):
        return [
            {
                "source_event_id": f"tapology_event_{page_number}",
                "event_date": "2026-06-01",
                "event_url": f"https://www.tapology.com/fightcenter/events/{page_number}-fixture",
            }
        ]

    def fake_advance(_page, _summary, _seen_event_urls=None, **_kwargs):
        advance_calls.append(True)
        return True

    monkeypatch.setattr(scraper, "_extract_event_list_items", fake_items)
    monkeypatch.setattr(scraper, "_advance_list_page", fake_advance)
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda context, item, kind, run_dir, summary_arg: {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["source_event_id"],
            "event_date": item["event_date"],
            "warnings": [],
            "bouts": [{"source_bout_id": f"{item['source_event_id']}_bout"}],
        },
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert len(events) == 3
    assert summary["pages_scanned"] == 3
    assert summary["pagination_stop_reason"] == "max_pages"
    assert len(advance_calls) == 2


def test_initial_visible_batch_counts_as_page_one(monkeypatch, tmp_path):
    scraper = TapologyScraper(ScraperConfig(max_pages=1, run_mode="backfill", event_delay_ms=0))
    scraper._store = DataStore(tmp_path)
    scraper._state_bundle = {"events_index": {}, "incomplete_events": {}}
    summary = scraper._empty_summary("run-1", "2026-05-12T19:40:00+00:00")
    advance_calls = []

    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, page_number: [
            {
                "source_event_id": f"tapology_event_{page_number}",
                "event_date": "2026-06-01",
                "event_url": f"https://www.tapology.com/fightcenter/events/{page_number}-fixture",
            }
        ],
    )
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: advance_calls.append(True))
    monkeypatch.setattr(
        scraper,
        "_scrape_event_page",
        lambda context, item, kind, run_dir, summary_arg: {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["source_event_id"],
            "event_date": item["event_date"],
            "warnings": [],
            "bouts": [{"source_bout_id": f"{item['source_event_id']}_bout"}],
        },
    )

    events = scraper._traverse_event_list(_ListPage(), "results", object(), tmp_path, summary)

    assert len(events) == 1
    assert summary["pages_scanned"] == 1
    assert summary["pagination_stop_reason"] == "max_pages"
    assert advance_calls == []


class _ManifestFakeContext:
    def new_page(self):
        raise AssertionError("direct forced event test should not open a listing page")

    def close(self):
        return None


class _ManifestFakeBrowser:
    def close(self):
        return None


class _ManifestFakePlaywright:
    def stop(self):
        return None


class _RunListingPage(_ListPage):
    url = "https://www.tapology.com/fightcenter"

    def goto(self, *_args, **_kwargs):
        return None

    def close(self):
        return None


def test_access_degradation_failed_run_does_not_advance_latest_and_writes_stop_reason(monkeypatch, tmp_path):
    store = DataStore(tmp_path)
    event_url = "https://www.tapology.com/fightcenter/events/1-fixture-fc"

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(
        ScraperConfig(
            force_recheck_events=(event_url,),
            stop_on_access_degradation=True,
            max_event_fetch_failures=1,
        )
    )
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)

    def fail_event_page(_context, _item, _kind, _run_dir, _summary):
        raise RuntimeError("HTTP 429")

    monkeypatch.setattr(scraper, "_scrape_event_page", fail_event_page)

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["latest_updated"] is False
    assert not (tmp_path / "latest" / "results.json").exists()
    manifest_path = next((tmp_path / "runs").glob("*/run_manifest.json"))
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    assert manifest["status"] == "failed"
    assert manifest["counts"]["access_stop_reason"] == "event_fetch_failures_exceeded:1"
    assert any("access_degraded:event_fetch_failures_exceeded:1" in error for error in summary["errors"])


def test_failed_run_preserves_completed_events_in_partial_results(monkeypatch, tmp_path):
    store = DataStore(tmp_path)

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(
        ScraperConfig(
            stop_on_access_degradation=True,
            max_event_fetch_failures=1,
            event_delay_ms=0,
        )
    )
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)
    monkeypatch.setattr(scraper, "_new_fightcenter_page", lambda _context: _RunListingPage())
    monkeypatch.setattr(scraper, "_ensure_fightcenter_ready", lambda *_args, **_kwargs: None)
    monkeypatch.setattr(scraper, "_apply_filters", lambda *_args, **_kwargs: {"schedule": "results", "sport": "mma"})
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_good",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-good",
            },
            {
                "source_event_id": "tapology_event_bad",
                "event_name": "Bad Event",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/2-bad",
                "list_page_number": 1,
                "list_position": 2,
            },
        ],
    )

    def scrape_event(_context, item, _kind, _run_dir, _summary):
        if item["source_event_id"] == "tapology_event_bad":
            raise RuntimeError("HTTP 429")
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": "Good Event",
            "event_date": item["event_date"],
            "warnings": [],
            "skipped_bouts": [],
            "bouts": [{"source_bout_id": "tapology_bout_good"}],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", scrape_event)

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["latest_updated"] is False
    partial = json.loads(next((tmp_path / "runs").glob("*/partial_results.json")).read_text(encoding="utf-8"))
    assert [event["source_event_id"] for event in partial["events"]] == ["tapology_event_good"]
    assert partial["run"]["events_written"] == 1
    assert partial["run"]["partial_failure_reason"] == "access_degraded:event_fetch_failures_exceeded:1"
    assert partial["run"]["failed_event_examples"] == [
        {
            "source_event_id": "tapology_event_bad",
            "event_name": "Bad Event",
            "event_url": "https://www.tapology.com/fightcenter/events/2-bad",
            "list_page_number": 1,
            "list_position": 2,
            "reason": "HTTP 429",
        }
    ]
    manifest = json.loads(next((tmp_path / "runs").glob("*/run_manifest.json")).read_text(encoding="utf-8"))
    assert manifest["counts"]["events_written"] == 1
    assert manifest["counts"]["failed_event_examples"][0]["event_name"] == "Bad Event"
    assert manifest["counts"]["failed_event_examples"][0]["reason"] == "HTTP 429"
    assert any("event_failed:https://www.tapology.com/fightcenter/events/2-bad:HTTP 429" in warning for warning in manifest["warnings"])
    assert manifest["errors"] == ["access_degraded:event_fetch_failures_exceeded:1"]


def test_results_checkpoint_survives_abrupt_interrupt(monkeypatch, tmp_path):
    store = DataStore(tmp_path)

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(ScraperConfig(event_delay_ms=0))
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)
    monkeypatch.setattr(scraper, "_new_fightcenter_page", lambda _context: _RunListingPage())
    monkeypatch.setattr(scraper, "_ensure_fightcenter_ready", lambda *_args, **_kwargs: None)
    monkeypatch.setattr(scraper, "_apply_filters", lambda *_args, **_kwargs: {"schedule": "results", "sport": "mma"})
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_good",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-good",
            },
            {
                "source_event_id": "tapology_event_interrupted",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/2-interrupted",
            },
        ],
    )

    def scrape_event(_context, item, _kind, _run_dir, _summary):
        if item["source_event_id"] == "tapology_event_interrupted":
            raise KeyboardInterrupt("operator/session interrupted")
        return {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": "Good Event",
            "event_date": item["event_date"],
            "warnings": [],
            "skipped_bouts": [],
            "bouts": [{"source_bout_id": "tapology_bout_good"}],
        }

    monkeypatch.setattr(scraper, "_scrape_event_page", scrape_event)

    with pytest.raises(KeyboardInterrupt):
        scraper.run(results=True, upcoming=False)

    checkpoint = json.loads(next((tmp_path / "runs").glob("*/checkpoint_results.json")).read_text(encoding="utf-8"))
    assert [event["source_event_id"] for event in checkpoint["events"]] == ["tapology_event_good"]
    assert checkpoint["run"]["events_written"] == 1
    assert checkpoint["run"]["checkpoint_partial"] is True
    assert checkpoint["run"]["checkpoint_event_count"] == 1
    assert not (tmp_path / "latest" / "results.json").exists()


def test_failed_profile_example_is_written_when_profile_context_closes(monkeypatch, tmp_path):
    store = DataStore(tmp_path)

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(
        ScraperConfig(
            stop_on_access_degradation=True,
            max_event_fetch_failures=10,
            event_delay_ms=0,
            enrich_fighter_profiles=True,
        )
    )
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)
    monkeypatch.setattr(scraper, "_new_fightcenter_page", lambda _context: _RunListingPage())
    monkeypatch.setattr(scraper, "_ensure_fightcenter_ready", lambda *_args, **_kwargs: None)
    monkeypatch.setattr(scraper, "_apply_filters", lambda *_args, **_kwargs: {"schedule": "results", "sport": "mma"})
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_profile_fail",
                "event_name": "Profile Fail FC",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-profile-fail",
                "list_page_number": 1,
                "list_position": 1,
            }
        ],
    )

    def scrape_event(_context, item, _kind, run_dir, summary):
        event = {
            "source_event_id": item["source_event_id"],
            "event_url": item["event_url"],
            "event_name": item["event_name"],
            "event_date": item["event_date"],
            "warnings": [],
            "skipped_bouts": [],
            "bouts": [
                {
                    "source_bout_id": "tapology_bout_profile_fail",
                    "fighter_a": {
                        "name": "Jane Failed",
                        "url": "https://www.tapology.com/fightcenter/fighters/123-jane-failed",
                        "source_fighter_id": "tapology_fighter_123",
                    },
                    "fighter_b": {"name": "Ann Other"},
                }
            ],
        }
        scraper._enrich_event_fighter_profiles(event, _context, run_dir, "2026-06-01T00:00:00+00:00", summary)
        return event

    monkeypatch.setattr(scraper, "_scrape_event_page", scrape_event)

    def fail_profile(*_args, **_kwargs):
        raise RuntimeError("BrowserContext.new_page: Target page, context or browser has been closed")

    monkeypatch.setattr(scraper, "_fetch_event_fighter_profile", fail_profile)

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["errors"] == ["access_degraded:browser_context_closed"]
    manifest = json.loads(next((tmp_path / "runs").glob("*/run_manifest.json")).read_text(encoding="utf-8"))
    assert manifest["counts"]["access_stop_reason"] == "browser_context_closed"
    assert manifest["counts"]["event_fetch_failures"] == 1
    assert manifest["counts"]["failed_profile_examples"] == [
        {
            "source_fighter_id": "tapology_fighter_123",
            "profile_url": "https://www.tapology.com/fightcenter/fighters/123-jane-failed",
            "fighter_name": "Jane Failed",
            "event_url": "https://www.tapology.com/fightcenter/events/1-profile-fail",
            "source_event_id": "tapology_event_profile_fail",
            "event_name": "Profile Fail FC",
            "reason": "BrowserContext.new_page: Target page, context or browser has been closed",
        }
    ]
    partial = json.loads(next((tmp_path / "runs").glob("*/partial_results.json")).read_text(encoding="utf-8"))
    assert partial["run"]["failed_profile_examples"][0]["fighter_name"] == "Jane Failed"


def test_browser_context_closed_fails_fast_before_event_failure_threshold(monkeypatch, tmp_path):
    store = DataStore(tmp_path)

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(
        ScraperConfig(
            stop_on_access_degradation=True,
            max_event_fetch_failures=10,
            event_delay_ms=0,
        )
    )
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)
    monkeypatch.setattr(scraper, "_new_fightcenter_page", lambda _context: _RunListingPage())
    monkeypatch.setattr(scraper, "_ensure_fightcenter_ready", lambda *_args, **_kwargs: None)
    monkeypatch.setattr(scraper, "_apply_filters", lambda *_args, **_kwargs: {"schedule": "results", "sport": "mma"})
    monkeypatch.setattr(scraper, "_advance_list_page", lambda *_args, **_kwargs: False)
    monkeypatch.setattr(
        scraper,
        "_extract_event_list_items",
        lambda _page, _page_number: [
            {
                "source_event_id": "tapology_event_browser_closed",
                "event_name": "Closed Browser FC",
                "event_date": "2026-06-01",
                "event_url": "https://www.tapology.com/fightcenter/events/1-closed-browser",
                "list_page_number": 1,
                "list_position": 1,
            }
        ],
    )

    def scrape_event(*_args, **_kwargs):
        raise RuntimeError("BrowserContext.new_page: Target page, context or browser has been closed")

    monkeypatch.setattr(scraper, "_scrape_event_page", scrape_event)

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["errors"] == ["access_degraded:browser_context_closed"]
    manifest = json.loads(next((tmp_path / "runs").glob("*/run_manifest.json")).read_text(encoding="utf-8"))
    assert manifest["counts"]["access_stop_reason"] == "browser_context_closed"
    assert manifest["counts"]["event_fetch_failures"] == 1
    assert manifest["errors"] == ["access_degraded:browser_context_closed"]


def test_failed_bout_examples_include_matchup_names():
    scraper = TapologyScraper(ScraperConfig())
    summary = scraper._empty_summary("run-1", "2026-06-01T00:00:00+00:00")

    scraper._record_failed_bout(
        summary,
        bout_url="https://www.tapology.com/fightcenter/bouts/123-jane-doe-vs-ann-other",
        event_url="https://www.tapology.com/fightcenter/events/1-fixture",
        row={
            "fighter_links": [
                {"name": "Jane Doe", "href": "/fightcenter/fighters/1-jane"},
                {"name": "Ann Other", "href": "/fightcenter/fighters/2-ann"},
            ]
        },
        reason="matchup timeout",
    )

    assert summary["failed_bout_examples"] == [
        {
            "source_bout_id": "tapology_bout_123",
            "bout_url": "https://www.tapology.com/fightcenter/bouts/123-jane-doe-vs-ann-other",
            "event_url": "https://www.tapology.com/fightcenter/events/1-fixture",
            "fighter_a_name": "Jane Doe",
            "fighter_b_name": "Ann Other",
            "reason": "matchup timeout",
        }
    ]


def test_multi_page_load_more_timeout_does_not_advance_latest(monkeypatch, tmp_path):
    store = DataStore(tmp_path)

    def fake_launch(_config):
        return _ManifestFakePlaywright(), _ManifestFakeBrowser(), _ManifestFakeContext()

    scraper = TapologyScraper(ScraperConfig(max_pages=3, run_mode="backfill", event_delay_ms=0))
    scraper._store = store

    monkeypatch.setattr(tapology_mod, "launch_browser", fake_launch)
    monkeypatch.setattr(tapology_mod, "ensure_data_dirs", store.ensure_dirs)
    monkeypatch.setattr(
        scraper,
        "_scrape_kind",
        lambda context, kind, run_id, started_at, run_dir, summary, direct_event_items=None: {
            "schema_version": "tapology_results_v0_2",
            "source": "tapology",
            "source_url": "https://www.tapology.com/fightcenter",
            "scraped_at": started_at,
            "filters": {},
            "run": {
                "run_id": run_id,
                "started_at": started_at,
                "finished_at": None,
                "events_seen": 1,
                "events_written": 1,
                "pages_scanned": 1,
                "pagination_stop_reason": "load_more_timeout",
                "load_more_clicks": 1,
                "load_more_successes": 0,
                "load_more_no_new_events": 0,
                "load_more_timeouts": 1,
                "unique_listing_event_urls_seen": 1,
                "duplicate_listing_event_urls_seen": 0,
            },
            "events": [],
        },
    )

    def fake_aggregate(_results_doc, _upcoming_doc):
        return {
            "events_seen": 1,
            "events_written": 1,
            "pages_scanned": 1,
            "pagination_stop_reason": "load_more_timeout",
            "load_more_clicks": 1,
            "load_more_successes": 0,
            "load_more_no_new_events": 0,
            "load_more_timeouts": 1,
            "unique_listing_event_urls_seen": 1,
            "duplicate_listing_event_urls_seen": 0,
        }

    monkeypatch.setattr(scraper, "_aggregate_counts", fake_aggregate)

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["latest_updated"] is False
    assert summary["pagination_stop_reason"] == "load_more_timeout"
    assert summary["early_stop_triggered"] is True
    assert summary["early_stop_reason"] == "load_more_timeout"
    assert not (tmp_path / "latest" / "results.json").exists()
    manifest_path = next((tmp_path / "runs").glob("*/run_manifest.json"))
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    assert manifest["status"] == "failed"
    assert manifest["counts"]["pagination_stop_reason"] == "load_more_timeout"
    assert manifest["counts"]["early_stop_triggered"] is True
