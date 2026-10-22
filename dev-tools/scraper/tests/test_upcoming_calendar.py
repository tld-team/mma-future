from __future__ import annotations

import json
import zipfile
from datetime import date

import pytest

from mma_future_scraper.upcoming_calendar import (
    build_document,
    build_run_manifest,
    export_upcoming_bundle,
    scrape_upcoming,
    validate_days,
)
from mma_future_scraper.storage import atomic_write_json


def raw_event(event_date: str = "2026-12-31") -> dict:
    return {
        "source_event_id": "tapology_event_101",
        "event_url": "https://www.tapology.com/fightcenter/events/101-test",
        "event_name": "New Year Fight Night",
        "promotion_name": "Regional MMA",
        "event_date": event_date,
        "event_time_raw": "8:00 PM",
        "event_timezone_raw": "ET",
        "location": "New York, United States",
        "venue": "Test Arena",
        "source_event_status": "scheduled",
        "bouts": [
            {
                "source_bout_id": "tapology_bout_201",
                "bout_url": "https://www.tapology.com/fightcenter/bouts/201-test",
                "pro_am": "professional",
                "status": "scheduled",
                "weight_class": "Lightweight",
                "fighter_a": {"name": "Ana A", "source_fighter_id": "tapology_fighter_1"},
                "fighter_b": {"name": "Bea B", "source_fighter_id": "tapology_fighter_2"},
                "flags": {"is_professional": True},
            },
            {
                "source_bout_id": "tapology_bout_202",
                "pro_am": "amateur",
                "fighter_a": {"name": "Am A"},
                "fighter_b": {"name": "Am B"},
                "flags": {"is_professional": False},
            },
        ],
    }


def test_exact_inclusive_window_and_shared_professional_scope():
    document = build_document(
        {"run": {"run_id": "run-1"}, "scraped_at": "2026-12-30T10:00:00Z", "events": [raw_event()]},
        {"date_window_stop_confirmed": True, "event_fetch_failures": 0},
        start=date(2026, 12, 30),
        end=date(2027, 1, 8),
        timezone_name="Europe/Belgrade",
    )
    assert document["coverage"]["requested_days"] == 10
    assert document["coverage"]["complete"] is True
    assert len(document["events"]) == 1
    assert len(document["events"][0]["bouts"]) == 1
    assert document["events"][0]["bouts"][0]["scope_evidence"] == "shared_professional_mma_classifier"
    assert document["events"][0]["start_utc"].endswith("Z")


def test_period_is_capped_at_thirty_days():
    assert validate_days(30) == 30
    with pytest.raises(ValueError):
        validate_days(31)


def test_incomplete_read_never_claims_complete_coverage():
    event = raw_event()
    event["warnings"] = ["fight_card_missing"]
    document = build_document(
        {"run": {"run_id": "run-2"}, "events": [event]},
        {"date_window_stop_confirmed": True, "event_fetch_failures": 0},
        start=date(2026, 12, 31),
        end=date(2026, 12, 31),
        timezone_name="UTC",
    )
    assert document["coverage"]["complete"] is False
    assert document["coverage"]["incomplete_events"] == 1


def test_incomplete_scrape_preserves_last_success(monkeypatch, tmp_path):
    data_dir = tmp_path / "upcoming"
    latest = data_dir / "latest"
    latest.mkdir(parents=True)
    previous = {"run_id": "previous-success"}
    atomic_write_json(latest / "upcoming_calendar.json", previous)

    class IncompleteScraper:
        def __init__(self, config):
            self.config = config

        def run(self, *, results, upcoming):
            event = raw_event("2026-12-31")
            event["warnings"] = ["fight_card_missing"]
            atomic_write_json(
                self.config.data_dir / "latest" / "upcoming.json",
                {"run": {"run_id": "run-incomplete"}, "events": [event]},
            )
            return {"successful": True, "date_window_stop_confirmed": True}

    monkeypatch.setattr("mma_future_scraper.upcoming_calendar.TapologyScraper", IncompleteScraper)
    with pytest.raises(RuntimeError, match="last successful export was preserved"):
        scrape_upcoming(days=1, data_dir=data_dir, today=date(2026, 12, 31))

    assert json.loads((latest / "upcoming_calendar.json").read_text()) == previous
    manifest = json.loads((data_dir / "runs" / "run-incomplete" / "upcoming_run_manifest.json").read_text())
    assert manifest["status"] == "incomplete"


def test_bundle_has_checksums_and_upcoming_mode(tmp_path):
    data_dir = tmp_path / "upcoming"
    latest = data_dir / "latest"
    latest.mkdir(parents=True)
    document = build_document(
        {"run": {"run_id": "run-3"}, "events": [raw_event()]},
        {"date_window_stop_confirmed": True, "event_fetch_failures": 0},
        start=date(2026, 12, 31),
        end=date(2026, 12, 31),
        timezone_name="UTC",
    )
    atomic_write_json(latest / "upcoming_calendar.json", document)
    atomic_write_json(latest / "upcoming_run_manifest.json", build_run_manifest(document, {}))
    output = tmp_path / "bundle.zip"
    result = export_upcoming_bundle(output, data_dir=data_dir)
    assert result["mode"] == "upcoming"
    with zipfile.ZipFile(output) as archive:
        manifest = json.loads(archive.read("latest/package_manifest.json"))
        assert manifest["contract"] == "mmaf_upcoming_v1"
        assert set(manifest["files"]) == {"upcoming.json", "run_manifest.json"}
