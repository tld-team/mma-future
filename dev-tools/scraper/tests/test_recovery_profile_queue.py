import json

from mma_future_scraper.recovery_profile_queue import build_recovery_profile_queue


def test_recovery_queue_deduplicates_numeric_id_and_keeps_provenance(tmp_path):
    source = tmp_path / "recovered.json"
    output = tmp_path / "queue.json"
    source.write_text(json.dumps({"events": [
        {"source_event_id": "tapology_event_1", "bouts": [{
            "source_bout_id": "tapology_bout_10", "pro_am": "professional", "flags": {"is_completed": True},
            "fighter_a": {"name": "Usman", "source_fighter_id": "tapology_fighter_194617", "url": "https://www.tapology.com/fightcenter/fighters/194617-old"},
            "fighter_b": {"name": "Other", "source_fighter_id": "tapology_fighter_2", "url": "https://www.tapology.com/fightcenter/fighters/2-other"},
        }]},
        {"source_event_id": "tapology_event_2", "bouts": [{
            "source_bout_id": "tapology_bout_11", "pro_am": "professional", "flags": {"is_completed": True},
            "fighter_a": {"name": "Usman", "source_fighter_id": "tapology_fighter_194617", "url": "https://www.tapology.com/fightcenter/fighters/194617-new"},
            "fighter_b": {},
        }]},
    ]}))
    document = build_recovery_profile_queue(
        source,
        output,
        only_source_ids={"tapology_fighter_194617"},
        confirmed_source_ids={"tapology_fighter_194617"},
    )
    assert document["candidate_count"] == 1
    assert document["fetchable_count"] == 1 and document["identity_review_count"] == 0
    [item] = document["items"]
    assert item["fetch_scope"] == "professional_mma_career"
    assert item["recovery_evidence"]["source_event_ids"] == ["tapology_event_1", "tapology_event_2"]
    assert item["recovery_evidence"]["source_bout_ids"] == ["tapology_bout_10", "tapology_bout_11"]
    assert json.loads(output.read_text()) == document


def test_recovery_candidates_do_not_become_fetches_before_canonical_confirmation(tmp_path):
    source = tmp_path / "recovered.json"
    output = tmp_path / "queue.json"
    source.write_text(json.dumps({"events": [{"source_event_id": "tapology_event_1", "bouts": [{
        "source_bout_id": "tapology_bout_10", "pro_am": "professional", "flags": {"is_completed": True},
        "fighter_a": {"name": "Candidate", "source_fighter_id": "tapology_fighter_10", "url": "https://www.tapology.com/fightcenter/fighters/10-candidate"},
        "fighter_b": {},
    }]}]}))
    document = build_recovery_profile_queue(source, output)
    assert document["fetchable_count"] == 0 and document["coverage_review_count"] == 1
    assert document["items"][0]["action"] == "review_canonical_coverage"
    assert document["items"][0]["fetch_scope"] is None


def test_recovery_queue_keeps_slug_only_identity_for_review(tmp_path):
    source = tmp_path / "recovered.json"
    output = tmp_path / "queue.json"
    source.write_text(json.dumps({"events": [{"source_event_id": "tapology_event_1", "bouts": [{
        "source_bout_id": "tapology_bout_1", "pro_am": "professional", "flags": {"is_completed": True},
        "fighter_a": {"name": "Legacy", "source_fighter_id": "tapology_fighter_url_hash", "url": "https://www.tapology.com/fightcenter/fighters/legacy-slug"},
        "fighter_b": {},
    }]}]}))
    document = build_recovery_profile_queue(source, output)
    assert document["candidate_count"] == 1 and document["identity_review_count"] == 1
    assert document["items"][0]["action"] == "review_source_identity"
    assert document["items"][0]["fetch_scope"] is None
