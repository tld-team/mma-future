import json
from datetime import datetime, timedelta, timezone

from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.fighter_profiles import failed_profile
from mma_future_scraper.storage import DataStore
from mma_future_scraper.tapology import TapologyScraper
from mma_future_scraper.tapology import FatalScrapeError
from mma_future_scraper.utils import source_fighter_id_from_url


class FakeCard:
    def __init__(self, rows):
        self.rows = rows

    def evaluate(self, *_args, **_kwargs):
        return self.rows


class FakePage:
    url = "https://www.tapology.com/fightcenter/events/141000-lemino-shooto-6"


class FakeLocator:
    def __init__(self, *, count=0, visible=False, on_click=None, href=None):
        self._count = count
        self._visible = visible
        self._on_click = on_click
        self._href = href
        self.first = self

    def count(self):
        return self._count

    def is_visible(self, timeout=None):
        return self._visible

    def is_enabled(self, timeout=None):
        return self._visible

    def nth(self, _index):
        return self

    def scroll_into_view_if_needed(self, timeout=None):
        return None

    def click(self, timeout=None):
        if self._on_click:
            self._on_click()

    def get_attribute(self, name, timeout=None):
        if name == "href":
            return self._href
        return None

    def evaluate_all(self, *_args, **_kwargs):
        return []


class FakeListingPage:
    url = "https://www.tapology.com/fightcenter?page=1"

    def __init__(self, *, next_visible=False, advances=False):
        self.next_visible = next_visible
        self.advances = advances
        self.waited = False

    def locator(self, selector):
        if "loadMoreButton" in selector:
            return FakeLocator(count=1 if self.next_visible else 0, visible=self.next_visible, on_click=self._click_next)
        return FakeLocator()

    def wait_for_timeout(self, *_args, **_kwargs):
        pass

    def wait_for_function(self, *_args, **_kwargs):
        self.waited = True
        if not self.advances:
            raise TimeoutError("no new events")

    def _click_next(self):
        if self.advances:
            self.url = "https://www.tapology.com/fightcenter?page=2"


def test_matchup_page_prefight_records_do_not_use_postfight_records():
    scraper = TapologyScraper(ScraperConfig())
    details = scraper._parse_matchup_page_details(
        "COMBATE GLOBAL VAZQUEZ VS. ORTIKOV COMPLETED MMA BOUT MAY 9, 2026 "
        "W Vazquez defeats Ortikov L KO/TKO, Punches 1:34 Round 2 of 3 "
        "Gianni Vazquez vs. Kamron Ortikov 13-5-1 PRO RECORD AT FIGHT 7-2-0 "
        "Climbed to 14-5-1 RECORD AFTER FIGHT Fell to 7-3 "
        "BOUT INFORMATION Gianni Vazquez vs. Kamron Ortikov MAIN EVENT | BANTAMWEIGHT · 135 LBS | PRO MMA "
        "Title on Line: N/A Event: Combate Global"
    )

    assert details["fighter_a_prefight_record"]["raw"] == "13-5-1"
    assert details["fighter_b_prefight_record"]["raw"] == "7-2-0"
    assert details["fighter_a_prefight_record"]["wins"] == 13
    assert details["fighter_b_prefight_record"]["losses"] == 2
    assert details["result"]["time"] == "1:34"
    assert details["result"]["time_semantics"] == "round_clock"


def test_matchup_page_result_time_does_not_use_event_start_time():
    scraper = TapologyScraper(ScraperConfig())
    details = scraper._parse_matchup_page_details(
        "COMPLETED MMA BOUT MAY 10, 2026 Rookie FC 9 Naples, Italy "
        "W Bellotta defeats Greco L Submission, Arm Triangle Choke Round 1 of 3 "
        "Salvatore Bellotta vs. Valentino Greco 2-1-0 PRO RECORD AT FIGHT 1-1-0 "
        "BOUT INFORMATION Salvatore Bellotta vs. Valentino Greco MAIN EVENT | SUPER LIGHTWEIGHT · 160 LBS | PRO MMA "
        "Date: Sunday 05.10.2026 at 12:00 PM ET"
    )

    assert "time" not in details["result"]
    assert details["result"]["time_semantics"] == "unknown"


def test_decision_result_time_semantics_marks_total_duration():
    scraper = TapologyScraper(ScraperConfig())
    row = {
        "raw_text": (
            "PRO MMA Lightweight W Jane Doe 3-0-0 def. Ann Other 2-1-0 L "
            "Decision, Unanimous 15:00"
        ),
        "bout_href": "/fightcenter/bouts/100-decision",
        "fighter_links": [
            {"name": "Jane Doe", "href": "/fightcenter/fighters/100-jane-doe"},
            {"name": "Ann Other", "href": "/fightcenter/fighters/200-ann-other"},
        ],
    }

    bout = scraper._normalize_bout_row(row, {"event_date": "2026-06-01"}, FakePage.url, "results")

    assert bout["result"]["method_category"] == "decision"
    assert bout["result"]["method_detail"] == "unanimous"
    assert bout["result"]["time"] == "15:00"
    assert bout["result"]["time_semantics"] == "total_duration"


def test_five_round_decision_result_time_semantics_marks_total_duration():
    scraper = TapologyScraper(ScraperConfig())
    details = scraper._parse_matchup_page_details(
        "COMPLETED MMA BOUT W Champion defeats Challenger L Decision, Unanimous 25:00 "
        "Champion vs. Challenger 10-0-0 PRO RECORD AT FIGHT 9-1-0 "
        "BOUT INFORMATION Champion vs. Challenger MAIN EVENT | LIGHTWEIGHT"
    )

    assert details["result"]["method_category"] == "decision"
    assert details["result"]["time"] == "25:00"
    assert details["result"]["time_semantics"] == "total_duration"


def test_draw_matchup_result_segment_does_not_leak_round_from_event_name_or_quick_results():
    scraper = TapologyScraper(ScraperConfig())
    details = scraper._parse_matchup_page_details(
        "Shooto 2026 Vol.4 Osaka, Japan "
        "Ryuki Yoshii vs. Masateru Takemura ends in a Draw "
        "Ends in a Draw, Unanimous 2 Rounds, 10:00 Total "
        "Ryuki Yoshii vs. Masateru Takemura 4-0-1 PRO RECORD AT FIGHT 0-0-0 "
        "BOUT INFORMATION Ryuki Yoshii vs. Masateru Takemura MAIN CARD | FLYWEIGHT | PRO MMA "
        "Quick Results W Koyuru Tanoue def Kazusa Kurobe L KO/TKO, 1:42 R4"
    )

    assert details["result"]["method_category"] == "draw"
    assert details["result"].get("round") is None
    assert details["result"]["time"] == "10:00"
    assert details["result"]["time_semantics"] == "total_duration"


def test_finish_result_time_semantics_marks_round_clock():
    scraper = TapologyScraper(ScraperConfig())
    row = {
        "raw_text": (
            "PRO MMA Bantamweight W Jane Doe 3-0-0 def. Ann Other 2-1-0 L "
            "KO/TKO, Punches 1:34 Round 2"
        ),
        "bout_href": "/fightcenter/bouts/101-finish",
        "fighter_links": [
            {"name": "Jane Doe", "href": "/fightcenter/fighters/100-jane-doe"},
            {"name": "Ann Other", "href": "/fightcenter/fighters/200-ann-other"},
        ],
    }

    bout = scraper._normalize_bout_row(row, {"event_date": "2026-06-01"}, FakePage.url, "results")

    assert bout["result"]["method_category"] == "ko_tko"
    assert bout["result"]["round"] == 2
    assert bout["result"]["time"] == "1:34"
    assert bout["result"]["time_semantics"] == "round_clock"


def test_missing_result_time_semantics_is_unknown():
    scraper = TapologyScraper(ScraperConfig())
    row = {
        "raw_text": "PRO MMA Lightweight W Jane Doe 3-0-0 def. Ann Other 2-1-0 L Decision",
        "bout_href": "/fightcenter/bouts/102-unknown-time",
        "fighter_links": [
            {"name": "Jane Doe", "href": "/fightcenter/fighters/100-jane-doe"},
            {"name": "Ann Other", "href": "/fightcenter/fighters/200-ann-other"},
        ],
    }

    bout = scraper._normalize_bout_row(row, {"event_date": "2026-06-01"}, FakePage.url, "results")

    assert bout["result"]["method_category"] == "decision"
    assert "time" not in bout["result"]
    assert bout["result"]["time_semantics"] == "unknown"


def test_event_fighter_references_ignore_skipped_bouts_for_profile_enrichment():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [
            {
                "fighter_a": {
                    "name": "Retained Fighter",
                    "url": "https://www.tapology.com/fightcenter/fighters/100-retained-fighter",
                    "source_fighter_id": "tapology_fighter_100",
                },
                "fighter_b": {
                    "name": "Shared Fighter",
                    "url": "https://www.tapology.com/fightcenter/fighters/200-shared-fighter",
                    "source_fighter_id": "tapology_fighter_200",
                },
            }
        ],
        "skipped_bouts": [
            {
                "reason": "no_result",
                "fighter_a_name": "Skipped MMA Fighter",
                "fighter_a_url": "https://www.tapology.com/fightcenter/fighters/300-skipped-mma-fighter",
                "fighter_a_source_fighter_id": "tapology_fighter_300",
                "fighter_b_name": "Shared Fighter",
                "fighter_b_url": "https://www.tapology.com/fightcenter/fighters/200-shared-fighter",
                "fighter_b_source_fighter_id": "tapology_fighter_200",
            },
            {
                "reason": "non_mma",
                "fighter_a_name": "Skipped Non MMA Fighter",
                "fighter_a_url": "https://www.tapology.com/fightcenter/fighters/400-skipped-non-mma-fighter",
                "fighter_a_source_fighter_id": "tapology_fighter_400",
                "fighter_b_name": None,
                "fighter_b_url": None,
                "fighter_b_source_fighter_id": None,
            },
        ],
    }

    refs = scraper._event_fighter_references(event)
    counts = scraper._profile_reference_context_counts(refs)

    assert {ref["source_fighter_id"] for ref in refs} == {"tapology_fighter_100", "tapology_fighter_200"}
    assert counts == {
        "retained_bout_profiles": 2,
        "skipped_mma_profiles": 0,
        "skipped_non_mma_profiles": 0,
    }


def test_profile_enrichment_does_not_fetch_amateur_skipped_bout_profiles(tmp_path):
    scraper = TapologyScraper(ScraperConfig(conservative_delay_ms=0, event_delay_ms=0))
    event = {
        "event_name": "Amateur Heavy Event",
        "event_date": "2026-06-01",
        "event_url": "https://www.tapology.com/fightcenter/events/1-amateur-heavy",
        "fighter_profiles": {},
        "bouts": [
            {
                "fighter_a": {
                    "name": "Pro Fighter",
                    "url": "https://www.tapology.com/fightcenter/fighters/100-pro-fighter",
                    "source_fighter_id": "tapology_fighter_100",
                },
                "fighter_b": {
                    "name": "Other Pro",
                    "url": "https://www.tapology.com/fightcenter/fighters/200-other-pro",
                    "source_fighter_id": "tapology_fighter_200",
                },
            }
        ],
        "skipped_bouts": [
            {
                "reason": "amateur",
                "fighter_a_name": "Skipped Amateur",
                "fighter_a_url": "https://www.tapology.com/fightcenter/fighters/300-skipped-amateur",
                "fighter_a_source_fighter_id": "tapology_fighter_300",
                "fighter_b_name": "Other Amateur",
                "fighter_b_url": "https://www.tapology.com/fightcenter/fighters/400-other-amateur",
                "fighter_b_source_fighter_id": "tapology_fighter_400",
            }
        ],
    }
    calls = []

    def fake_fetch(_context, profile_url, *, fallback_name, event_date, fetched_at, run_dir):
        calls.append(profile_url)
        return {
            "source_fighter_id": source_fighter_id_from_url(profile_url),
            "profile_url": profile_url,
            "profile_fetched": True,
            "profile_fetched_at": fetched_at,
            "name": fallback_name,
            "display_name": fallback_name,
            "profile_warnings": [],
        }

    scraper._fetch_event_fighter_profile = fake_fetch
    scraper._enrich_event_fighter_profiles(event, None, tmp_path, "2026-06-02T00:00:00+00:00")

    assert calls == [
        "https://www.tapology.com/fightcenter/fighters/100-pro-fighter",
        "https://www.tapology.com/fightcenter/fighters/200-other-pro",
    ]
    assert set(event["fighter_profiles"]) == {"tapology_fighter_100", "tapology_fighter_200"}
    assert event["profile_enrichment"]["profile_context_counts"] == {
        "retained_bout_profiles": 2,
        "skipped_mma_profiles": 0,
        "skipped_non_mma_profiles": 0,
    }


def test_card_and_title_info_from_matchup_text():
    scraper = TapologyScraper(ScraperConfig())
    details = scraper._parse_matchup_page_details(
        "Anthony Romero vs. Yuma Horiuchi 6-2-0 PRO RECORD AT FIGHT 11-6-0 "
        "BOUT INFORMATION Anthony Romero vs. Yuma Horiuchi MAIN EVENT | UNF BANTAMWEIGHT CHAMPIONSHIP | 135 LBS "
        "Event: UNF 33 Title on Line: UNF Bantamweight Championship Belt Status Before Fight: Held by Romero Event Links:"
    )

    assert details["card_position"] == "main_event"
    assert details["weight_lbs"] == 135
    assert details["title_fight"]["title_name"] == "UNF BANTAMWEIGHT CHAMPIONSHIP"


def test_from_date_accepts_inclusive_boundary():
    scraper = TapologyScraper(ScraperConfig(from_date="2026-05-01"))

    assert scraper._event_matches_from_date({"event_date": "2026-05-01"}) is True
    assert scraper._event_matches_from_date({"event_date": "2026-05-02"}) is True
    assert scraper._event_matches_from_date({"event_date": "2026-04-30"}) is False
    assert scraper._event_matches_from_date({"event_date": None}) is False


def test_in_progress_event_tracks_structured_skipped_no_result_bouts():
    scraper = TapologyScraper(ScraperConfig())
    completed = {
        "raw_text": (
            "PRO MMA Lightweight W Lowen Tynanes 12-2-0 def. Yuji Ephoeviga 13-3-0 L "
            "Decision Round 3 5:00"
        ),
        "bout_href": "",
        "fighter_links": [
            {"name": "Lowen Tynanes", "href": "/fightcenter/fighters/34077-lowen-tynanes"},
            {"name": "Yuji Ephoeviga", "href": "/fightcenter/fighters/123-yuji-ephoeviga"},
        ],
    }
    no_result_rows = [
        {
            "raw_text": f"PRO MMA Lightweight Fighter {index} vs. Opponent {index}",
            "bout_href": f"/fightcenter/bouts/{9000 + index}-pending",
            "fighter_links": [
                {"name": f"Fighter {index}", "href": f"/fightcenter/fighters/{5000 + index}-fighter"},
                {"name": f"Opponent {index}", "href": f"/fightcenter/fighters/{6000 + index}-opponent"},
            ],
        }
        for index in range(6)
    ]
    event = {
        "source_event_id": "tapology_event_141000",
        "event_url": FakePage.url,
        "event_date": "2026-06-01",
        "warnings": [],
        "skipped_bouts": [],
    }

    bouts = scraper._parse_bouts(FakeCard([completed, *no_result_rows]), event, FakePage(), None, "results", {"warnings": [], "failed_bout_urls": []})
    event["bouts"] = bouts
    scraper._finalize_event_completeness(event, "IN PROGRESS MMA EVENT MMA Bouts: 7", "results")
    document = scraper._empty_document("results", "test_run", "2026-06-02T00:00:00+00:00")
    document["events"] = [event]
    scraper._refresh_run_counts(document)

    assert event["source_event_status"] == "in_progress"
    assert event["source_bouts_total"] == 7
    assert event["bouts_included"] == 1
    assert event["bouts_skipped_no_result"] == 6
    assert event["bouts_skipped_total"] >= 6
    assert event["needs_recheck"] is True
    assert len(event["skipped_bouts"]) == 6
    assert "bouts_skipped_no_result:6" in event["warnings"]
    assert document["run"]["bouts_source_rows_seen"] == 7
    assert document["run"]["bouts_seen"] == 7
    assert document["run"]["bouts_written"] == 1
    assert document["run"]["bouts_skipped_no_result"] == 6
    assert bouts[0]["flags"]["is_scoring_candidate"] is True
    assert bouts[0]["flags"]["is_bout_scoring_candidate"] is True


def test_completed_event_page_with_cancelled_bout_text_is_not_cancelled():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": "tapology_bout_1"}],
        "warnings": [],
        "skipped_bouts": [],
    }

    scraper._finalize_event_completeness(
        event,
        "COMPLETED MMA EVENT MMA Bouts: 1 Notes: cancelled bouts and scrapped fights are listed below",
        "results",
        "Fixture FC 1 Completed MMA Event",
    )

    assert event["source_event_status"] == "completed"
    assert event["needs_recheck"] is False


def test_event_cancelled_header_remains_cancelled():
    scraper = TapologyScraper(ScraperConfig())
    event = {"bouts": [], "warnings": [], "skipped_bouts": []}

    scraper._finalize_event_completeness(event, "MMA Bouts: 0", "results", "Event Status: Cancelled")

    assert event["source_event_status"] == "cancelled"


def test_mma_total_ignores_non_mma_skipped_rows_for_recheck():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": f"tapology_bout_{index}"} for index in range(4)],
        "warnings": [],
        "skipped_bouts": [
            {"reason": "amateur"},
            {"reason": "amateur"},
            {"reason": "amateur"},
            {"reason": "non_mma"},
            {"reason": "non_mma"},
            {"reason": "non_mma"},
        ],
    }

    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 7", "results", "Completed MMA Event")

    assert event["source_bouts_total"] == 7
    assert event["source_mma_bouts_total"] == 7
    assert event["source_rows_seen"] == 10
    assert event["source_mma_rows_seen"] == 7
    assert event["needs_recheck"] is False
    assert not any(warning.startswith("source_mma_bouts_total_mismatch:") for warning in event["warnings"])


def test_explicit_non_mma_amateur_rows_do_not_count_as_mma_rows():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "source_event_id": "tapology_event_1",
        "event_url": FakePage.url,
        "event_date": "2026-06-01",
        "warnings": [],
        "skipped_bouts": [],
    }
    rows = [
        {
            "raw_text": "KICKBOXING MAIN CARD 145 3 x 2 | Amateur Fighter A vs. Fighter B",
            "bout_href": "/fightcenter/bouts/1-kickboxing",
            "fighter_links": [
                {"name": "Fighter A", "href": "/fightcenter/fighters/1-a"},
                {"name": "Fighter B", "href": "/fightcenter/fighters/2-b"},
            ],
        }
    ]

    scraper._parse_bouts(FakeCard(rows), event, FakePage(), None, "results", {"warnings": [], "failed_bout_urls": []})
    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 0", "results", "Completed MMA Event")

    assert event["skipped_bouts"][0]["reason"] == "non_mma"
    assert event["source_rows_seen"] == 1
    assert event["source_mma_rows_seen"] == 0
    assert not any(warning.startswith("source_mma_bouts_total_mismatch:") for warning in event["warnings"])


def test_mma_total_mismatch_warns_for_missing_or_duplicate_mma_rows():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": "tapology_bout_1"}],
        "warnings": [],
        "skipped_bouts": [{"reason": "amateur"}],
    }

    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 3", "results", "Completed MMA Event")

    assert event["source_mma_rows_seen"] == 2
    assert event["needs_recheck"] is True
    assert "source_mma_bouts_total_mismatch:3:2" in event["warnings"]


def test_completed_event_with_amateur_skip_does_not_need_recheck():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": "tapology_bout_1"}],
        "warnings": ["bouts_skipped_amateur:1"],
        "skipped_bouts": [{"reason": "amateur"}],
    }

    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 2", "results", "Completed MMA Event")

    assert event["source_event_status"] == "completed"
    assert event["needs_recheck"] is False


def test_completed_event_with_no_result_skip_needs_recheck():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": "tapology_bout_1"}],
        "warnings": ["bouts_skipped_no_result:1"],
        "skipped_bouts": [{"reason": "no_result"}],
    }

    scraper._finalize_event_completeness(event, "MMA Bouts: 2", "results", "Completed MMA Event")

    assert event["source_event_status"] == "in_progress"
    assert event["needs_recheck"] is True


def test_pagination_reports_natural_end_only_when_no_next_control():
    scraper = TapologyScraper(ScraperConfig())
    summary = {}

    assert scraper._advance_list_page(FakeListingPage(next_visible=False), summary) is False
    assert summary["pagination_next_found"] is False
    assert summary["pagination_stop_reason"] == "natural_end_no_load_more_button"

    summary = {}
    assert scraper._advance_list_page(FakeListingPage(next_visible=True, advances=False), summary) is False
    assert summary["pagination_next_found"] is True
    assert summary["pagination_stop_reason"] == "load_more_timeout"


class RichFakeListingPage:
    url = "https://www.tapology.com/fightcenter"

    def __init__(
        self,
        *,
        urls,
        added_urls=None,
        button_visible=True,
        timeout=False,
        load_more_href=None,
        fallback_urls=None,
    ):
        self.urls = list(urls)
        self.added_urls = list(added_urls or [])
        self.button_visible = button_visible
        self.timeout = timeout
        self.load_more_href = load_more_href
        self.fallback_urls = list(fallback_urls or [])
        self.clicks = 0
        self.goto_calls = []

    def locator(self, selector):
        if "a[href*='/fightcenter/events/']" in selector and "loadMoreButton" not in selector:
            return RichFakeEventLinks(self)
        if "loadMoreButton" in selector or "load-more-button" in selector or "More" in selector or "Next" in selector:
            return FakeLocator(
                count=1 if self.button_visible else 0,
                visible=self.button_visible,
                on_click=self._click_next,
                href=self.load_more_href,
            )
        return FakeLocator()

    def goto(self, url, **_kwargs):
        self.goto_calls.append(url)
        self.url = url
        if self.fallback_urls:
            self.urls = list(self.fallback_urls)

    def wait_for_selector(self, *_args, **_kwargs):
        return None

    def wait_for_timeout(self, *_args, **_kwargs):
        return None

    def wait_for_function(self, *_args, **_kwargs):
        if self.timeout:
            raise TimeoutError("load more timed out")
        return True

    def _click_next(self):
        self.clicks += 1
        self.urls.extend(self.added_urls)


class RichFakeEventLinks:
    def __init__(self, page):
        self.page = page

    def count(self):
        return len(self.page.urls)

    def evaluate_all(self, *_args, **_kwargs):
        return list(self.page.urls)


class _FakeOpenedPopup:
    url = "https://ads.example.test/popup"

    def __init__(self):
        self.closed = False

    def close(self):
        self.closed = True


class _FakeExpectPopup:
    def __init__(self, popup):
        self.value = popup

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        if exc_type:
            return False
        if self.value is None:
            raise TimeoutError('Timeout 1500ms exceeded while waiting for event "popup"')
        return False


class PopupOpeningListingPage(RichFakeListingPage):
    def __init__(self, *args, popup=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.popup = popup

    def expect_popup(self, timeout=None):
        return _FakeExpectPopup(self.popup)


class AdOverlayAfterClickListingPage(RichFakeListingPage):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.overlay_visible = False
        self.overlay_dismissed = False

    def locator(self, selector):
        if selector == "#ad_position_box #dismiss-button-element":
            return FakeLocator(
                count=1 if self.overlay_visible else 0,
                visible=self.overlay_visible,
                on_click=self._dismiss_overlay,
            )
        return super().locator(selector)

    def _click_next(self):
        self.clicks += 1
        self.overlay_visible = True

    def _dismiss_overlay(self):
        self.overlay_visible = False
        self.overlay_dismissed = True
        self.urls.extend(self.added_urls)


def test_load_more_click_adds_new_unique_events():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=["https://www.tapology.com/fightcenter/events/2-b"],
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is True

    assert page.clicks == 1
    assert summary["load_more_clicks"] == 1
    assert summary["load_more_successes"] == 1
    assert summary["unique_listing_event_urls_seen"] == 2


def test_load_more_click_closes_popup_page_and_continues():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0, load_more_popup_wait_ms=1500))
    popup = _FakeOpenedPopup()
    page = PopupOpeningListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=["https://www.tapology.com/fightcenter/events/2-b"],
        popup=popup,
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is True

    assert popup.closed is True
    assert summary["load_more_popup_pages_closed"] == 1
    assert summary["popup_dismissals_count"] == 1
    assert "load_more_popup_page_closed" in summary["selectors_used"]
    assert summary["load_more_successes"] == 1


def test_load_more_click_dismisses_in_page_ad_overlay_before_waiting():
    scraper = TapologyScraper(
        ScraperConfig(
            load_more_post_click_wait_ms=0,
            load_more_overlay_check_rounds=2,
            load_more_overlay_check_interval_ms=100,
        )
    )
    page = AdOverlayAfterClickListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=["https://www.tapology.com/fightcenter/events/2-b"],
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is True

    assert page.overlay_dismissed is True
    assert summary["load_more_overlay_dismissals"] == 1
    assert summary["popup_dismissals_count"] == 1
    assert "popup close:#ad_position_box #dismiss-button-element" in summary["selectors_used"]
    assert "load_more_overlay_check" in summary["selectors_used"]
    assert summary["load_more_successes"] == 1


def test_load_more_click_popup_wait_timeout_is_not_click_failure():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0, load_more_popup_wait_ms=1500))
    page = PopupOpeningListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=["https://www.tapology.com/fightcenter/events/2-b"],
        popup=None,
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is True

    assert summary["load_more_popup_pages_closed"] == 0
    assert summary["load_more_successes"] == 1


def test_load_more_timeout_falls_back_to_direct_href_navigation():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        timeout=True,
        load_more_href="/fightcenter?group=all&page=2&schedule=results&sport=mma",
        fallback_urls=["https://www.tapology.com/fightcenter/events/2-b"],
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is True

    assert page.clicks == 1
    assert page.goto_calls == ["https://www.tapology.com/fightcenter?group=all&page=2&schedule=results&sport=mma"]
    assert summary["load_more_clicks"] == 1
    assert summary["load_more_successes"] == 1
    assert summary["load_more_timeouts"] == 0
    assert summary["load_more_fallback_attempts"] == 1
    assert summary["load_more_fallback_successes"] == 1
    assert summary["load_more_fallback_failures"] == 0
    assert summary["unique_listing_event_urls_seen"] == 2
    assert "pagination_stop_reason" not in summary
    assert summary["diagnostics"][0]["kind"] == "fightcenter_load_more_href_fallback"
    assert summary["diagnostics"][0]["outcome"] == "success"


def test_load_more_timeout_fallback_without_new_events_remains_timeout():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        timeout=True,
        load_more_href="/fightcenter?group=all&page=2&schedule=results&sport=mma",
        fallback_urls=["https://www.tapology.com/fightcenter/events/1-a"],
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is False

    assert page.clicks == 1
    assert page.goto_calls == ["https://www.tapology.com/fightcenter?group=all&page=2&schedule=results&sport=mma"]
    assert summary["pagination_stop_reason"] == "load_more_timeout"
    assert summary["load_more_timeouts"] == 1
    assert summary["load_more_fallback_attempts"] == 1
    assert summary["load_more_fallback_successes"] == 0
    assert summary["load_more_fallback_failures"] == 1
    assert summary["diagnostics"][0]["outcome"] == "no_new_unique_events"


def test_load_more_timeout_is_not_overwritten_by_natural_end():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=[],
        timeout=True,
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is False
    assert scraper._advance_list_page(RichFakeListingPage(urls=[], button_visible=False), summary, set()) is False

    assert summary["pagination_stop_reason"] == "load_more_timeout"
    assert summary["load_more_timeouts"] == 1


def test_load_more_duplicate_events_are_no_new_events():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=["https://www.tapology.com/fightcenter/events/1-a"],
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is False

    assert summary["pagination_stop_reason"] == "load_more_no_new_events"
    assert summary["load_more_no_new_events"] == 1


def test_load_more_timeout_writes_debug_diagnostics(tmp_path):
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0, debug=True, max_pages=3, browser_engine="firefox"))
    scraper._debug_doc = scraper._empty_debug_document("run-1", "2026-06-02T00:00:00+00:00")
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=[],
        timeout=True,
    )
    page.title = lambda: "Tapology FightCenter"
    page.content = lambda: "<html><body>FightCenter</body></html>"
    page.screenshot = lambda **_kwargs: None
    summary = {"unique_listing_event_urls_seen": 1, "duplicate_listing_event_urls_seen": 2}

    assert scraper._advance_list_page(
        page,
        summary,
        {"https://www.tapology.com/fightcenter/events/1-a"},
        run_dir=tmp_path,
        page_number=1,
    ) is False

    diagnostic = summary["diagnostics"][0]
    assert diagnostic["page_url"] == "https://www.tapology.com/fightcenter"
    assert diagnostic["page_title"] == "Tapology FightCenter"
    assert diagnostic["load_more_button_found"] is True
    assert diagnostic["load_more_button_visible"] is True
    assert diagnostic["load_more_button_enabled"] is True
    assert diagnostic["event_url_count_before_click"] == 1
    assert diagnostic["event_url_count_after_timeout"] == 1
    assert diagnostic["unique_event_urls_seen"] == 1
    assert diagnostic["duplicate_event_urls_seen"] == 2
    assert diagnostic["browser_engine"] == "firefox"
    assert diagnostic["max_pages"] == 3
    assert diagnostic["current_page_batch_number"] == 1
    assert (tmp_path / "raw_debug" / "fightcenter_load_more_timeout.html").exists()


def test_missing_load_more_button_is_natural_end():
    scraper = TapologyScraper(ScraperConfig())
    summary = {}

    assert scraper._advance_list_page(RichFakeListingPage(urls=[], button_visible=False), summary, set()) is False

    assert summary["pagination_stop_reason"] == "natural_end_no_load_more_button"


def test_load_more_timeout_is_classified():
    scraper = TapologyScraper(ScraperConfig(load_more_post_click_wait_ms=0))
    page = RichFakeListingPage(
        urls=["https://www.tapology.com/fightcenter/events/1-a"],
        added_urls=[],
        timeout=True,
    )
    summary = {}

    assert scraper._advance_list_page(page, summary, {"https://www.tapology.com/fightcenter/events/1-a"}) is False

    assert summary["pagination_stop_reason"] == "load_more_timeout"
    assert summary["load_more_timeouts"] == 1


def test_event_location_parser_normalizes_known_country_values():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "event_url": FakePage.url,
        "location": "Tokyo, Japan",
        "bouts": [],
        "warnings": [],
        "skipped_bouts": [],
    }
    location = scraper._extract_location("Venue: Hall Location: Tokyo, Japan Bout Billing: Main")
    from mma_future_scraper.contract import populate_event_contract_fields

    populate_event_contract_fields(event)

    assert location == "Tokyo, Japan"
    assert event["city"] == "Tokyo"
    assert event["region"] is None
    assert event["country"] == "Japan"


def test_event_location_parser_handles_two_and_three_part_values_without_country_guessing():
    from mma_future_scraper.utils import parse_location_parts

    assert parse_location_parts("Honduras") == {"city": None, "region": None, "country": "Honduras"}
    assert parse_location_parts("Panama") == {"city": None, "region": None, "country": "Panama"}
    assert parse_location_parts("Cuba") == {"city": None, "region": None, "country": "Cuba"}
    assert parse_location_parts("Tegucigalpa, Honduras") == {
        "city": "Tegucigalpa",
        "region": None,
        "country": "Honduras",
    }
    assert parse_location_parts("Naples, Italy") == {"city": "Naples", "region": None, "country": "Italy"}
    assert parse_location_parts("San Jose, Costa Rica") == {
        "city": "San Jose",
        "region": None,
        "country": "Costa Rica",
    }
    assert parse_location_parts("Desamparados, San Jose, Costa Rica") == {
        "city": "Desamparados",
        "region": "San Jose",
        "country": "Costa Rica",
    }
    assert parse_location_parts("Gori, Georgia") == {"city": "Gori", "region": None, "country": "Georgia"}
    assert parse_location_parts("Almaty, Kazakhstan") == {
        "city": "Almaty",
        "region": None,
        "country": "Kazakhstan",
    }
    assert parse_location_parts("Nookat, Osh region, Kyrgyzstan") == {
        "city": "Nookat",
        "region": "Osh region",
        "country": "Kyrgyzstan",
    }
    assert parse_location_parts("Houston, Texas") == {"city": "Houston", "region": "Texas", "country": None}
    assert parse_location_parts("Honolulu, Hawaii, United States") == {
        "city": "Honolulu",
        "region": "Hawaii",
        "country": "United States",
    }
    assert parse_location_parts("Tachikawa, Tokyo, Japan") == {
        "city": "Tachikawa",
        "region": "Tokyo",
        "country": "Japan",
    }
    assert parse_location_parts("Johannesburg, Gauteng, South Africa") == {
        "city": "Johannesburg",
        "region": "Gauteng",
        "country": "South Africa",
    }
    assert parse_location_parts("Baku, Azerbaijan") == {
        "city": "Baku",
        "region": None,
        "country": "Azerbaijan",
    }
    assert parse_location_parts("Gokwe North, Zimbabwe") == {
        "city": "Gokwe North",
        "region": None,
        "country": "Zimbabwe",
    }
    assert parse_location_parts("Guam") == {"city": None, "region": None, "country": "Guam"}
    assert parse_location_parts("Hong Kong") == {"city": None, "region": None, "country": "Hong Kong"}
    assert parse_location_parts("Osaka, Japan") == {"city": "Osaka", "region": None, "country": "Japan"}
    assert parse_location_parts("Yoichi, Hokkaido") == {"city": "Yoichi", "region": "Hokkaido", "country": None}


def test_enclosure_parser_stops_before_metadata_labels():
    scraper = TapologyScraper(ScraperConfig())

    assert scraper._extract_enclosure("Enclosure: Cage Ring Announcer: X Matchmaker: Y") == "Cage"
    assert scraper._extract_enclosure("Enclosure: Cage TV Announcers: X") == "Cage"
    assert scraper._extract_enclosure("Enclosure: Ring") == "Ring"
    assert scraper._extract_enclosure("Enclosure: Open Mat") is None


def test_completed_event_without_unresolved_bouts_does_not_need_recheck():
    scraper = TapologyScraper(ScraperConfig())
    event = {
        "bouts": [{"source_bout_id": "tapology_bout_1"}],
        "warnings": [],
        "skipped_bouts": [],
        "source_bouts_total": 1,
    }

    scraper._finalize_event_completeness(event, "COMPLETED MMA EVENT MMA Bouts: 1", "results")

    assert event["source_event_status"] == "completed"
    assert event["needs_recheck"] is False
    assert event["bouts_skipped_no_result"] == 0


def test_raw_event_datetime_fields_are_extracted_without_conversion():
    scraper = TapologyScraper(ScraperConfig())
    text = "Monday, June 01, 2026 at 05:00 AM ET"

    assert scraper._extract_event_datetime_raw(text) == text
    assert scraper._extract_event_time(text) == "05:00 AM"
    assert scraper._extract_timezone(text) == "ET"


def test_slug_only_fighter_url_profile_ref_matches_enriched_profile_key(tmp_path):
    slug_url = "https://www.tapology.com/fightcenter/fighters/derek-campos"
    slug_id = source_fighter_id_from_url(slug_url)
    scraper = TapologyScraper(ScraperConfig(conservative_delay_ms=0, event_delay_ms=0))
    event = {
        "event_date": "2026-06-01",
        "fighter_profiles": {},
        "bouts": [
            {
                "fighter_a": {
                    "name": "Derek Campos",
                    "url": slug_url,
                    "source_fighter_id": slug_id,
                },
                "fighter_b": {
                    "name": "Numeric Fighter",
                    "url": "https://www.tapology.com/fightcenter/fighters/123-numeric-fighter",
                    "source_fighter_id": "tapology_fighter_123",
                },
            }
        ],
        "skipped_bouts": [],
    }

    def fake_fetch(_context, profile_url, *, fallback_name, event_date, fetched_at, run_dir):
        source_id = source_fighter_id_from_url(profile_url)
        return {
            "source_fighter_id": source_id,
            "source_fighter_numeric_id": None if source_id == slug_id else "123",
            "profile_url": profile_url,
            "profile_fetched": True,
            "profile_fetched_at": fetched_at,
            "name": fallback_name,
            "display_name": fallback_name,
            "nationality": {
                "raw": "Japan",
                "country_name": "Japan",
                "country_code": "JPN",
                "source": "profile_header_flag",
                "is_missing": False,
            },
            "country": "Japan",
            "profile_warnings": [],
        }

    scraper._fetch_event_fighter_profile = fake_fetch
    scraper._enrich_event_fighter_profiles(event, None, tmp_path, "2026-06-02T00:00:00+00:00")

    assert slug_id.startswith("tapology_fighter_url_")
    assert event["bouts"][0]["fighter_a"]["profile_ref"] == slug_id
    assert slug_id in event["fighter_profiles"]
    assert event["bouts"][0]["fighter_a"]["profile_summary"]["nationality"]["country_name"] == "Japan"
    assert "pro_mma_record" not in event["bouts"][0]["fighter_a"]


def test_configured_pacing_uses_injected_sleep_and_counts():
    scraper = TapologyScraper(ScraperConfig(page_pause_seconds=10, jitter_seconds=3))
    sleeps = []
    scraper._sleep = sleeps.append
    scraper._jitter_seconds = lambda: 2

    scraper._pause("page", scraper.config.page_pause_seconds, page_number=1)

    counts = scraper._runtime_diagnostic_counts()
    assert sleeps == [12]
    assert counts["page_pauses_count"] == 1
    assert counts["total_pause_seconds"] == 12


def test_profile_cache_fetches_same_fighter_once_across_events(tmp_path):
    url = "https://www.tapology.com/fightcenter/fighters/123-shared-fighter"
    scraper = TapologyScraper(
        ScraperConfig(conservative_delay_ms=0, event_delay_ms=0, use_profile_cache=False, profile_cache_ttl_days=None)
    )
    calls = []

    def fake_fetch(_context, profile_url, *, fallback_name, run_dir, cache_key):
        calls.append(profile_url)
        return {
            "source_fighter_id": source_fighter_id_from_url(profile_url),
            "status": "success",
            "display_name": fallback_name,
            "profile_summary": {},
            "record_summary": {},
            "fight_history": [],
            "warnings": [],
        }

    scraper._fetch_event_fighter_profile_with_retries = fake_fetch
    for event_name in ["Event One", "Event Two"]:
        event = {
            "event_name": event_name,
            "event_date": "2026-06-01",
            "event_url": f"https://www.tapology.com/fightcenter/events/{event_name}",
            "fighter_profiles": {},
            "bouts": [
                {
                    "fighter_a": {
                        "name": "Shared Fighter",
                        "url": url,
                        "source_fighter_id": source_fighter_id_from_url(url),
                    },
                    "fighter_b": {},
                }
            ],
            "skipped_bouts": [],
        }
        scraper._enrich_event_fighter_profiles(event, None, tmp_path, "2026-06-02T00:00:00+00:00")

    assert calls == [url]


def _write_latest_profile_cache(store, *, url, scraped_at, display_name="Cached Fighter"):
    store.ensure_dirs()
    profile = {
        "source_fighter_id": source_fighter_id_from_url(url),
        "source_url": url,
        "status": "success",
        "display_name": display_name,
        "scraped_at": scraped_at,
        "profile_summary": {
            "nationality": "Japan",
            "date_of_birth": "1990-01-01",
            "height_cm": 170,
        },
        "record_summary": {"pro_record_raw": "10-2-0"},
        "fight_history": [],
        "warnings": [],
    }
    (store.latest_dir / "fighter_profiles.json").write_text(
        json.dumps(
            {
                "schema_version": "tapology_fighter_profiles_v0_1",
                "scraped_at": scraped_at,
                "profiles": [profile],
            },
            indent=2,
        ),
        encoding="utf-8",
    )


def test_persistent_profile_cache_hit_skips_live_fetch(tmp_path):
    url = "https://www.tapology.com/fightcenter/fighters/321-cached-fighter"
    store = DataStore(tmp_path)
    scraped_at = datetime.now(timezone.utc).isoformat()
    _write_latest_profile_cache(store, url=url, scraped_at=scraped_at)
    scraper = TapologyScraper(ScraperConfig(conservative_delay_ms=0, event_delay_ms=0, profile_cache_ttl_days=60))
    scraper._store = store

    def fail_fetch(*_args, **_kwargs):
        raise AssertionError("live profile fetch should be skipped on persistent cache hit")

    scraper._fetch_event_fighter_profile_with_retries = fail_fetch

    profile = scraper._fetch_event_fighter_profile(
        None,
        url,
        fallback_name="Fallback",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
        run_dir=tmp_path,
    )

    assert profile["profile_fetched"] is True
    assert profile["profile_cache_hit"] is True
    assert profile["profile_cache_source"] == "latest_fighter_profiles"
    assert profile["profile_fetched_at"] == scraped_at
    assert scraper._profile_fetches_started == 0
    assert scraper._runtime_diagnostic_counts()["profile_cache_hits"] == 1


def test_expired_persistent_profile_cache_falls_back_to_live_fetch(tmp_path):
    url = "https://www.tapology.com/fightcenter/fighters/654-expired-fighter"
    store = DataStore(tmp_path)
    _write_latest_profile_cache(store, url=url, scraped_at="2000-01-01T00:00:00+00:00")
    scraper = TapologyScraper(ScraperConfig(conservative_delay_ms=0, event_delay_ms=0, profile_cache_ttl_days=1))
    scraper._store = store
    calls = []

    def fake_fetch(_context, profile_url, *, fallback_name, run_dir, cache_key):
        calls.append(profile_url)
        return {
            "source_fighter_id": source_fighter_id_from_url(profile_url),
            "status": "success",
            "display_name": fallback_name,
            "profile_summary": {},
            "record_summary": {},
            "fight_history": [],
            "warnings": [],
        }

    scraper._fetch_event_fighter_profile_with_retries = fake_fetch

    profile = scraper._fetch_event_fighter_profile(
        None,
        url,
        fallback_name="Fresh",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
        run_dir=tmp_path,
    )

    assert calls == [url]
    assert profile["profile_fetched"] is True
    assert "profile_cache_hit" not in profile
    assert scraper._profile_fetches_started == 1
    assert scraper._runtime_diagnostic_counts()["profile_cache_expired"] == 1


def test_disabled_persistent_profile_cache_fetches_live(tmp_path):
    url = "https://www.tapology.com/fightcenter/fighters/987-disabled-cache"
    store = DataStore(tmp_path)
    _write_latest_profile_cache(store, url=url, scraped_at=datetime.now(timezone.utc).isoformat())
    scraper = TapologyScraper(
        ScraperConfig(
            conservative_delay_ms=0,
            event_delay_ms=0,
            use_profile_cache=False,
            profile_cache_ttl_days=None,
        )
    )
    scraper._store = store
    calls = []

    def fake_fetch(_context, profile_url, *, fallback_name, run_dir, cache_key):
        calls.append(profile_url)
        return {
            "source_fighter_id": source_fighter_id_from_url(profile_url),
            "status": "success",
            "display_name": fallback_name,
            "profile_summary": {},
            "record_summary": {},
            "fight_history": [],
            "warnings": [],
        }

    scraper._fetch_event_fighter_profile_with_retries = fake_fetch

    scraper._fetch_event_fighter_profile(
        None,
        url,
        fallback_name="Live",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
        run_dir=tmp_path,
    )

    assert calls == [url]
    assert scraper._runtime_diagnostic_counts()["profile_cache_disabled"] == 1


def test_max_profile_fetches_marks_enrichment_partial_without_second_fetch(tmp_path):
    first = "https://www.tapology.com/fightcenter/fighters/101-first"
    second = "https://www.tapology.com/fightcenter/fighters/202-second"
    scraper = TapologyScraper(
        ScraperConfig(
            max_profile_fetches=1,
            conservative_delay_ms=0,
            event_delay_ms=0,
            use_profile_cache=False,
            profile_cache_ttl_days=None,
        )
    )
    calls = []

    def fake_fetch(_context, profile_url, *, fallback_name, run_dir, cache_key):
        calls.append(profile_url)
        return {
            "source_fighter_id": source_fighter_id_from_url(profile_url),
            "status": "success",
            "display_name": fallback_name,
            "profile_summary": {},
            "record_summary": {},
            "fight_history": [],
            "warnings": [],
        }

    scraper._fetch_event_fighter_profile_with_retries = fake_fetch
    event = {
        "event_date": "2026-06-01",
        "event_url": "https://www.tapology.com/fightcenter/events/1",
        "fighter_profiles": {},
        "bouts": [
            {
                "fighter_a": {"name": "First", "url": first, "source_fighter_id": source_fighter_id_from_url(first)},
                "fighter_b": {"name": "Second", "url": second, "source_fighter_id": source_fighter_id_from_url(second)},
            }
        ],
        "skipped_bouts": [],
    }

    scraper._enrich_event_fighter_profiles(event, None, tmp_path, "2026-06-02T00:00:00+00:00")

    assert calls == [first]
    assert event["profile_enrichment"]["partial"] is True
    assert scraper._runtime_diagnostic_counts()["profile_fetches_before_cap"] == 1
    assert event["fighter_profiles"][source_fighter_id_from_url(second)]["profile_fetched"] is False


def test_profile_failure_rate_threshold_stops_as_access_degraded(tmp_path):
    url = "https://www.tapology.com/fightcenter/fighters/999-blocked"
    scraper = TapologyScraper(
        ScraperConfig(
            max_profile_failure_rate=0.0,
            conservative_delay_ms=0,
            event_delay_ms=0,
            use_profile_cache=False,
            profile_cache_ttl_days=None,
        )
    )

    def fake_fetch(_context, profile_url, *, fallback_name, run_dir, cache_key):
        return failed_profile(profile_url, "blocked", "profile_fetch_failed:http_error")

    scraper._fetch_event_fighter_profile_with_retries = fake_fetch

    try:
        scraper._fetch_event_fighter_profile(
            None,
            url,
            fallback_name="Blocked",
            event_date="2026-06-01",
            fetched_at="2026-06-02T00:00:00+00:00",
            run_dir=tmp_path,
        )
    except FatalScrapeError as exc:
        assert "access_degraded:profile_failure_rate_exceeded" in str(exc)
    else:
        raise AssertionError("expected profile failure threshold to stop the run")


def test_new_completed_event_invalidates_profile_inside_generic_ttl(tmp_path):
    url = 'https://www.tapology.com/fightcenter/fighters/321-cached-fighter'
    store = DataStore(tmp_path)
    now = datetime.now(timezone.utc)
    _write_latest_profile_cache(store, url=url, scraped_at=(now - timedelta(days=7)).isoformat())
    scraper = TapologyScraper(ScraperConfig(profile_cache_ttl_days=60))
    scraper._store = store
    cached = scraper._cached_event_profile(url, fallback_name='Cached fighter',
        event_date=(now - timedelta(days=1)).date().isoformat(), fetched_at=now.isoformat())
    assert cached is None
    assert scraper._runtime_diagnostic_counts()['profile_cache_hits'] == 0
    assert scraper._runtime_diagnostic_counts()['profile_cache_expired'] == 1


def test_older_event_reuses_last_successful_profile(tmp_path):
    url = 'https://www.tapology.com/fightcenter/fighters/321-cached-fighter'
    store = DataStore(tmp_path)
    now = datetime.now(timezone.utc)
    _write_latest_profile_cache(store, url=url, scraped_at=(now - timedelta(days=2)).isoformat())
    scraper = TapologyScraper(ScraperConfig(profile_cache_ttl_days=60))
    scraper._store = store
    cached = scraper._cached_event_profile(url, fallback_name='Cached fighter',
        event_date=(now - timedelta(days=10)).date().isoformat(), fetched_at=now.isoformat())
    assert cached['profile_cache_hit'] is True
    assert scraper._profile_fetches_started == 0
