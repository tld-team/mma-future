import json
import urllib.error
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

import pytest

from mma_future_scraper import fighter_profile_queue
from mma_future_scraper.cli import build_parser
from mma_future_scraper.fighter_profile_queue import collect_fighter_profile_urls_from_queue
from mma_future_scraper.ranking_refresh_queue import (
    RankingApiError,
    build_ranking_refresh_queue,
    parse_api_json,
)


FIXTURES = Path(__file__).parent / "fixtures" / "ranking_api"
SITE = "https://staging.example.test"
NOW = datetime(2026, 11, 16, 12, 0, tzinfo=timezone.utc)
PAGES = {
    ("main", "1"): "main_page_1.json",
    ("main", "2"): "main_page_2.json",
    ("provisional", "1"): "provisional_page_1.txt",
}


class FixtureApi:
    def __init__(self, failures=None, overrides=None):
        self.calls = []
        self.failures = dict(failures or {})
        self.overrides = dict(overrides or {})

    def __call__(self, url):
        self.calls.append(url)
        parts = urlsplit(url)
        assert f"{parts.scheme}://{parts.netloc}" == SITE
        assert parts.path == "/wp-json/mma-future/v1/rankings"
        query = parse_qs(parts.query)
        assert int(query["per_page"][0]) <= 50
        key = (query.get("view", ["main"])[0], query["page"][0])
        if self.failures.get(key):
            self.failures[key] -= 1
            raise urllib.error.HTTPError(url, 503, "Service Unavailable", None, None)
        if key in self.overrides:
            return self.overrides[key]
        return (FIXTURES / PAGES[key]).read_text(encoding="utf-8")


def build(tmp_path, api=None, **kwargs):
    output = tmp_path / "ranking_refresh_queue.json"
    options = {"min_age_days": 20, "limit": 60, "per_page": 2, "now": NOW, "sleep": lambda _: None}
    options.update(kwargs)
    document = build_ranking_refresh_queue(site_url=SITE + "/", output_path=output, fetch=api or FixtureApi(), **options)
    return document, output


def test_selects_old_or_missing_evidence_oldest_first(tmp_path):
    document, output = build(tmp_path)

    assert [item["fighter_id"] for item in document["items"]] == [202, 201, 102]
    assert [item["verified_at"] for item in document["items"]] == [None, "2026-10-14T23:43:16Z", "2026-10-20T09:00:00Z"]
    assert document["summary"]["fresh_count"] == 1
    assert document["summary"]["missing_verified_at_count"] == 1
    assert document["summary"]["selected_by_view"] == {"main": 1, "provisional": 2}
    assert json.loads(output.read_text()) == document


def test_item_shape_matches_source_enrichment_contract(tmp_path):
    document, _ = build(tmp_path)

    assert document["schema_version"] == "mmaf_source_enrichment_v1"
    assert document["contract_version"] == 1
    assert document["target_count"] == len(document["items"])
    stale = next(item for item in document["items"] if item["fighter_id"] == 201)
    assert stale["source_fighter_id"] == "tapology_fighter_2001"
    assert stale["source_url"] == "https://www.tapology.com/fightcenter/fighters/2001-stale-provisional"
    assert stale["action"] == "refresh_source_evidence"
    assert stale["fetch_scope"] == "professional_mma_career"
    assert stale["force_refresh"] is True and stale["should_refresh"] is True
    assert stale["reasons"] == ["career_coverage_unconfirmed", "career_verification_stale"]
    assert stale["evidence_age_days"] == pytest.approx(32.51, abs=0.01)


def test_min_age_days_controls_selection(tmp_path):
    document, _ = build(tmp_path, min_age_days=30)
    assert [item["fighter_id"] for item in document["items"]] == [202, 201]

    document, _ = build(tmp_path, min_age_days=5)
    assert [item["fighter_id"] for item in document["items"]] == [202, 201, 102, 101]


def test_limit_keeps_oldest_and_reports_deferred(tmp_path):
    document, _ = build(tmp_path, limit=2)

    assert [item["fighter_id"] for item in document["items"]] == [202, 201]
    assert document["summary"]["unique_eligible_fighters"] == 3
    assert document["summary"]["deferred_by_limit_count"] == 1


def test_skips_and_counts_invalid_or_missing_source_urls(tmp_path):
    document, _ = build(tmp_path)

    assert document["summary"]["invalid_source_url_count"] == 3
    assert sorted(row["fighter_id"] for row in document["invalid_source_urls"]) == [103, 104, 203]
    assert {item["source_fighter_id"] for item in document["items"]}.isdisjoint({None})


def test_reads_every_page_of_main_and_provisional(tmp_path):
    api = FixtureApi()
    document, _ = build(tmp_path, api=api)

    assert len(api.calls) == 3
    assert document["summary"]["ranked_items_seen"] == 7
    assert document["source"]["views"]["main"] == {"active_ranking_run_id": 41, "total": 4, "total_pages": 2}
    assert document["source"]["views"]["provisional"] == {"active_ranking_run_id": 41, "total": 3, "total_pages": 1}


def test_parses_json_after_leading_php_notice():
    body = (FIXTURES / "provisional_page_1.txt").read_text(encoding="utf-8")
    assert not body.startswith("{")
    assert parse_api_json(body)["view"] == "provisional"
    with pytest.raises(ValueError):
        parse_api_json("<b>Fatal error</b>")


def test_retries_transient_failures(tmp_path):
    api = FixtureApi(failures={("main", "2"): 2})
    document, _ = build(tmp_path, api=api, retries=3)
    assert len(document["items"]) == 3
    assert len(api.calls) == 5


def test_fails_without_writing_a_partial_queue(tmp_path):
    api = FixtureApi(failures={("provisional", "1"): 5})
    with pytest.raises(RankingApiError):
        build(tmp_path, api=api, retries=2)
    assert not (tmp_path / "ranking_refresh_queue.json").exists()


def test_fails_when_ranking_run_changes_between_pages(tmp_path):
    page = json.loads((FIXTURES / "main_page_2.json").read_text())
    page["active_ranking_run_id"] = 42
    api = FixtureApi(overrides={("main", "2"): json.dumps(page)})
    with pytest.raises(RankingApiError):
        build(tmp_path, api=api)
    assert not (tmp_path / "ranking_refresh_queue.json").exists()


def test_fails_when_page_count_does_not_match_total(tmp_path):
    page = json.loads((FIXTURES / "main_page_2.json").read_text())
    page["items"] = page["items"][:1]
    api = FixtureApi(overrides={("main", "2"): json.dumps(page)})
    with pytest.raises(RankingApiError):
        build(tmp_path, api=api)


def test_tracking_decisions_skip_opponent_only_and_paused(tmp_path):
    decisions = tmp_path / "tracking.json"
    decisions.write_text(json.dumps({
        "schema_version": "mmaf_tracking_decisions_v1",
        "revision": "r7",
        "fighters": [
            {"source_fighter_id": "tapology_fighter_2002", "tracking_mode": "opponent_only"},
            {"source_fighter_id": "tapology_fighter_1002", "tracking_mode": "paused", "recheck_due": False},
            {"source_fighter_id": "tapology_fighter_2001", "tracking_mode": "paused", "recheck_due": True},
        ],
    }))
    document, _ = build(tmp_path, tracking_decisions_path=decisions)

    assert [item["fighter_id"] for item in document["items"]] == [201]
    assert document["summary"]["tracking_decisions_revision"] == "r7"
    assert document["summary"]["tracking_skipped_by_mode"] == {"opponent_only": 1, "paused": 1}


def test_output_is_accepted_by_profile_queue_consumer(tmp_path, monkeypatch):
    monkeypatch.setattr(fighter_profile_queue, "LATEST_DIR", tmp_path / "latest")
    _, output = build(tmp_path)

    urls, metadata = collect_fighter_profile_urls_from_queue(output, limit=10)

    assert urls == [
        "https://www.tapology.com/fightcenter/fighters/2002-never-verified",
        "https://www.tapology.com/fightcenter/fighters/2001-stale-provisional",
        "https://www.tapology.com/fightcenter/fighters/1002-ageing-main",
    ]
    assert metadata["queue_schema_version"] == "mmaf_source_enrichment_v1"
    assert metadata["selected_for_enrichment"] == 3


def test_cli_defaults():
    args = build_parser().parse_args(["build-ranking-refresh-queue", "--site-url", SITE, "--output", "queue.json"])
    assert (args.min_age_days, args.limit, args.tracking_decisions) == (20, 60, None)


def test_rejects_invalid_site_url_and_page_size(tmp_path):
    with pytest.raises(ValueError):
        build_ranking_refresh_queue(site_url="staging.example.test", output_path=tmp_path / "q.json", fetch=FixtureApi())
    with pytest.raises(ValueError):
        build(tmp_path, per_page=100)
