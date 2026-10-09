from copy import deepcopy

from mma_future_scraper.contract import populate_results_contract_fields
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION
from mma_future_scraper.validation import validate_results_document


def valid_payload():
    payload = {
        "schema_version": RESULTS_SCHEMA_VERSION,
        "events": [
            {
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_1",
                "event_url": "https://www.tapology.com/fightcenter/events/1-test",
                "event_name": "Test FC 1",
                "promotion_name": "Test FC",
                "promotion_url": "https://www.tapology.com/fightcenter/promotions/2729-test-fc",
                "event_date": "2026-05-01",
                "bouts": [
                    {
                        "internal_bout_id": "mf_bout_000001",
                        "internal_event_id": "mf_event_000001",
                        "source_bout_id": "tapology_bout_1",
                        "source_event_id": "tapology_event_1",
                        "bout_url": "https://www.tapology.com/fightcenter/bouts/1-test",
                        "status": "completed",
                        "pro_am": "professional",
                        "winner": "fighter_a",
                        "loser": "fighter_b",
                        "result_type": "win_loss",
                        "fighter_a": {
                            "name": "Jane Doe",
                            "url": "https://www.tapology.com/fightcenter/fighters/1-jane-doe",
                            "source_fighter_id": "tapology_fighter_1",
                            "prefight_record": {"raw": "1-0"},
                        },
                        "fighter_b": {
                            "name": "Ann Other",
                            "url": "https://www.tapology.com/fightcenter/fighters/2-ann-other",
                            "source_fighter_id": "tapology_fighter_2",
                            "prefight_record": {"raw": "0-1"},
                        },
                        "result": {"method_category": "decision"},
                    }
                ],
            }
        ],
    }
    return populate_results_contract_fields(payload)


def test_validate_results_document_accepts_lean_payload():
    assert validate_results_document(valid_payload()) == []
    assert valid_payload()["events"][0]["bouts"][0]["flags"]["is_scoring_candidate"] is True


def test_validate_results_document_requires_v0_5_schema():
    payload = valid_payload()
    payload["schema_version"] = "scraper_results_v0_4"

    assert "bad_schema:scraper_results_v0_4" in validate_results_document(payload)


def test_validate_results_document_schema_version_is_v0_5():
    assert valid_payload()["schema_version"] == "scraper_results_v0_5"


def test_validate_results_document_rejects_debug_fields_and_empty_warnings():
    payload = valid_payload()
    event = payload["events"][0]
    event["event_url_hash"] = "hash"
    event["warnings"] = []
    bout = event["bouts"][0]
    bout["raw_text"] = "debug"
    bout["finish_points_candidate"] = 0
    bout["warnings"] = []

    errors = validate_results_document(payload)
    assert any(error.startswith("banned_key:$.events[0].event_url_hash") for error in errors)
    assert "empty_warnings:$.events[0]" in errors
    assert any(error.endswith(".raw_text") for error in errors)
    assert any(error.endswith(".finish_points_candidate") for error in errors)


def test_validate_results_document_requires_explicit_source_fighter_id_key():
    payload = valid_payload()
    del payload["events"][0]["bouts"][0]["fighter_a"]["source_fighter_id"]
    payload["events"][0]["bouts"][0]["fighter_b"]["source_fighter_id"] = None

    errors = validate_results_document(payload)
    assert "missing:$.events[0].bouts[0].fighter_a.source_fighter_id" in errors
    assert "missing:$.events[0].bouts[0].fighter_b.source_fighter_id" not in errors


def test_validate_results_document_rejects_missing_hash_fields():
    payload = valid_payload()
    del payload["events"][0]["identity_hash"]
    del payload["events"][0]["bouts"][0]["content_hash"]

    errors = validate_results_document(payload)
    assert "missing:$.events[0].identity_hash" in errors
    assert "missing:$.events[0].bouts[0].content_hash" in errors


def test_validate_results_document_rejects_identical_identity_and_content_hashes():
    payload = valid_payload()
    event_hash = payload["events"][0]["identity_hash"]
    bout_hash = payload["events"][0]["bouts"][0]["identity_hash"]
    payload["events"][0]["content_hash"] = event_hash
    payload["events"][0]["bouts"][0]["content_hash"] = bout_hash

    errors = validate_results_document(payload)
    assert "identical_hashes:$.events[0]" in errors
    assert "identical_hashes:$.events[0].bouts[0]" in errors


def test_validate_results_document_accepts_null_promotion_id_only_with_warning():
    payload = valid_payload()
    event = payload["events"][0]
    event["promotion_url"] = None
    event["source_promotion_id"] = None
    event["source_promotion_numeric_id"] = None
    event["warnings"] = ["missing_source_promotion_id"]

    assert validate_results_document(payload) == []

    missing_warning = deepcopy(payload)
    missing_warning["events"][0]["warnings"] = []
    errors = validate_results_document(missing_warning)
    assert "missing_warning:$.events[0].missing_source_promotion_id" in errors


def test_validate_results_document_rejects_misaligned_numeric_ids():
    payload = valid_payload()
    event = payload["events"][0]
    bout = event["bouts"][0]
    event["source_event_numeric_id"] = "2"
    bout["source_bout_numeric_id"] = "2"
    bout["fighter_a"]["source_fighter_numeric_id"] = "2"

    errors = validate_results_document(payload)
    assert "bad_numeric_id:$.events[0].source_event_numeric_id:2:1" in errors
    assert "bad_numeric_id:$.events[0].bouts[0].source_bout_numeric_id:2:1" in errors
    assert "bad_numeric_id:$.events[0].bouts[0].fighter_a.source_fighter_numeric_id:2:1" in errors


def test_validate_results_document_accepts_slug_fighter_source_id_with_null_numeric_id():
    payload = valid_payload()
    fighter = payload["events"][0]["bouts"][0]["fighter_a"]
    fighter["url"] = "https://www.tapology.com/fightcenter/fighters/derek-campos"
    fighter["source_fighter_id"] = "tapology_fighter_url_fixturehash"
    fighter["source_fighter_numeric_id"] = None

    assert validate_results_document(payload) == []


def test_validate_results_document_rejects_missing_flags():
    payload = valid_payload()
    del payload["events"][0]["bouts"][0]["flags"]

    assert "missing:$.events[0].bouts[0].flags" in validate_results_document(payload)


def test_validate_results_document_rejects_non_boolean_flag_values():
    payload = valid_payload()
    payload["events"][0]["bouts"][0]["flags"]["is_completed"] = "yes"

    errors = validate_results_document(payload)
    assert "bad_flag:$.events[0].bouts[0].flags.is_completed:yes" in errors


def test_validate_results_document_rejects_impossible_scoring_candidate():
    payload = valid_payload()
    bout = payload["events"][0]["bouts"][0]
    bout["result_type"] = "draw"
    bout["flags"]["is_scoring_candidate"] = True

    errors = validate_results_document(payload)
    assert "bad_scoring_candidate:$.events[0].bouts[0].flags" in errors
