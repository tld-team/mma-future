from mma_future_scraper.contract import populate_results_contract_fields
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION


def fixture_doc():
    return {
        "schema_version": RESULTS_SCHEMA_VERSION,
        "events": [
            {
                "source_event_id": "tapology_event_141836",
                "event_url": "https://www.tapology.com/fightcenter/events/141836-rookie-fc-9",
                "event_name": "Rookie FC 9",
                "promotion_name": "Rookie Fighting Championship",
                "promotion_url": "https://www.tapology.com/fightcenter/promotions/2729-rookie-fighting-championship-rfc",
                "event_date": "2026-05-10",
                "location": "Naples, Italy",
                "venue": "Palasirio",
                "enclosure": "Cage",
                "bouts": [
                    {
                        "source_bout_id": "tapology_bout_1130296",
                        "bout_url": "https://www.tapology.com/fightcenter/bouts/1130296-fixture",
                        "status": "completed",
                        "pro_am": "professional",
                        "card_position": "main_event",
                        "weight_class": "BANTAMWEIGHT",
                        "weight_lbs": 135,
                        "winner": "fighter_a",
                        "loser": "fighter_b",
                        "result_type": "win_loss",
                        "fighter_a": {
                            "name": "Gianni Vazquez",
                            "url": "https://www.tapology.com/fightcenter/fighters/99104-gianni-vazquez",
                            "source_fighter_id": "tapology_fighter_99104",
                            "prefight_record": {"raw": "13-5-1", "wins": 13, "losses": 5, "draws": 1, "nc": 0},
                        },
                        "fighter_b": {
                            "name": "Kamron Ortikov",
                            "url": "https://www.tapology.com/fightcenter/fighters/389586-kamron-ortikov",
                            "source_fighter_id": "tapology_fighter_389586",
                            "prefight_record": {"raw": "7-2-0", "wins": 7, "losses": 2, "draws": 0, "nc": 0},
                        },
                        "result": {"method_category": "ko_tko", "method_detail": "Punches", "round": 2, "time": "1:34"},
                    }
                ],
            }
        ],
    }


def test_event_identity_hash_is_stable():
    first = populate_results_contract_fields(fixture_doc())["events"][0]["identity_hash"]
    second = populate_results_contract_fields(fixture_doc())["events"][0]["identity_hash"]

    assert first == second


def test_event_content_hash_changes_when_material_event_content_changes():
    first = populate_results_contract_fields(fixture_doc())["events"][0]["content_hash"]
    changed = fixture_doc()
    changed["events"][0]["venue"] = "New Venue"
    second = populate_results_contract_fields(changed)["events"][0]["content_hash"]

    assert first != second


def test_event_content_hash_changes_when_completeness_changes_but_identity_does_not():
    first_event = populate_results_contract_fields(fixture_doc())["events"][0]
    changed = fixture_doc()
    changed["events"][0]["source_event_status"] = "in_progress"
    changed["events"][0]["source_bouts_total"] = 2
    changed["events"][0]["bouts_included"] = 1
    changed["events"][0]["bouts_skipped_total"] = 1
    changed["events"][0]["bouts_skipped_no_result"] = 1
    changed["events"][0]["needs_recheck"] = True
    changed["events"][0]["skipped_bouts"] = [
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
    ]
    second_event = populate_results_contract_fields(changed)["events"][0]

    assert second_event["identity_hash"] == first_event["identity_hash"]
    assert second_event["content_hash"] != first_event["content_hash"]


def test_profile_fetch_timestamp_does_not_change_event_content_hash():
    base = fixture_doc()
    profile = {
        "source_fighter_id": "tapology_fighter_99104",
        "source_fighter_numeric_id": "99104",
        "profile_url": "https://www.tapology.com/fightcenter/fighters/99104-gianni-vazquez",
        "profile_fetched": True,
        "profile_fetched_at": "2026-06-01T00:00:00+00:00",
        "name": "Gianni Vazquez",
        "profile_warnings": [],
    }
    base["events"][0]["fighter_profiles"] = {"tapology_fighter_99104": profile}
    first = populate_results_contract_fields(base)["events"][0]["content_hash"]
    changed = fixture_doc()
    changed_profile = {**profile, "profile_fetched_at": "2026-06-02T00:00:00+00:00"}
    changed["events"][0]["fighter_profiles"] = {"tapology_fighter_99104": changed_profile}

    assert populate_results_contract_fields(changed)["events"][0]["content_hash"] == first


def test_event_content_hash_changes_when_profile_nationality_changes():
    base = fixture_doc()
    profile = {
        "source_fighter_id": "tapology_fighter_99104",
        "source_fighter_numeric_id": "99104",
        "profile_url": "https://www.tapology.com/fightcenter/fighters/99104-gianni-vazquez",
        "profile_fetched": True,
        "profile_fetched_at": "2026-06-01T00:00:00+00:00",
        "name": "Gianni Vazquez",
        "nationality": {
            "raw": "Brazil",
            "country_name": "Brazil",
            "country_code": "BRA",
            "source": "profile_header_flag",
            "is_missing": False,
        },
        "profile_warnings": [],
    }
    base["events"][0]["fighter_profiles"] = {"tapology_fighter_99104": profile}
    first = populate_results_contract_fields(base)["events"][0]["content_hash"]

    changed = fixture_doc()
    changed_profile = {
        **profile,
        "nationality": {
            "raw": "Japan",
            "country_name": "Japan",
            "country_code": "JPN",
            "source": "profile_header_flag",
            "is_missing": False,
        },
    }
    changed["events"][0]["fighter_profiles"] = {"tapology_fighter_99104": changed_profile}

    assert populate_results_contract_fields(changed)["events"][0]["content_hash"] != first


def test_bout_identity_hash_is_stable():
    first = populate_results_contract_fields(fixture_doc())["events"][0]["bouts"][0]["identity_hash"]
    second = populate_results_contract_fields(fixture_doc())["events"][0]["bouts"][0]["identity_hash"]

    assert first == second


def test_bout_content_hash_changes_when_result_method_or_prefight_changes():
    base = populate_results_contract_fields(fixture_doc())["events"][0]["bouts"][0]["content_hash"]

    changed_result = fixture_doc()
    changed_result["events"][0]["bouts"][0]["result"]["method_detail"] = "Elbows"
    assert populate_results_contract_fields(changed_result)["events"][0]["bouts"][0]["content_hash"] != base

    changed_prefight = fixture_doc()
    changed_prefight["events"][0]["bouts"][0]["fighter_a"]["prefight_record"] = {"raw": "14-5-1"}
    assert populate_results_contract_fields(changed_prefight)["events"][0]["bouts"][0]["content_hash"] != base


def test_bout_content_hash_changes_when_result_time_semantics_changes():
    doc = fixture_doc()
    doc["events"][0]["bouts"][0]["result"]["time_semantics"] = "round_clock"
    base = populate_results_contract_fields(doc)["events"][0]["bouts"][0]["content_hash"]

    changed = fixture_doc()
    changed["events"][0]["bouts"][0]["result"]["time_semantics"] = "unknown"

    assert populate_results_contract_fields(changed)["events"][0]["bouts"][0]["content_hash"] != base


def test_bout_content_hash_changes_when_flags_change():
    base = populate_results_contract_fields(fixture_doc())["events"][0]["bouts"][0]["content_hash"]
    changed = fixture_doc()
    changed["events"][0]["bouts"][0]["status"] = "upcoming"

    assert populate_results_contract_fields(changed)["events"][0]["bouts"][0]["content_hash"] != base


def test_bout_identity_hash_does_not_change_when_flags_change():
    base = populate_results_contract_fields(fixture_doc())["events"][0]["bouts"][0]["identity_hash"]
    changed = fixture_doc()
    changed["events"][0]["bouts"][0]["status"] = "upcoming"

    assert populate_results_contract_fields(changed)["events"][0]["bouts"][0]["identity_hash"] == base


def test_identity_and_content_hashes_are_distinct():
    event = populate_results_contract_fields(fixture_doc())["events"][0]
    bout = event["bouts"][0]

    assert event["identity_hash"] != event["content_hash"]
    assert bout["identity_hash"] != bout["content_hash"]


def test_fallback_bout_id_gets_auditable_warning():
    doc = fixture_doc()
    bout = doc["events"][0]["bouts"][0]
    bout["source_bout_id"] = "tapology_bout_fallback_abc123"
    bout["bout_url"] = None
    populated = populate_results_contract_fields(doc)["events"][0]["bouts"][0]

    assert populated["source_bout_numeric_id"] is None
    assert "fallback_source_bout_id" in populated["warnings"]
