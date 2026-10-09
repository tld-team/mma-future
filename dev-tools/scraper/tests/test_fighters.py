from mma_future_scraper.fighters import build_fighter_documents, validate_derived_outputs
from mma_future_scraper.models import RESULTS_SCHEMA_VERSION


def test_two_fighters_from_one_bout_are_aggregated_correctly():
    derived = build_fighter_documents(results_fixture([bout_fixture()]))
    fighters = derived["fighters_index"]["fighters"]
    logs = derived["fighter_fight_logs"]["fight_logs"]

    assert set(fighters) == {"tapology_fighter_100", "tapology_fighter_200"}
    assert fighters["tapology_fighter_100"]["display_name"] == "Winner One"
    assert fighters["tapology_fighter_100"]["bouts_count_in_dataset"] == 1
    assert fighters["tapology_fighter_100"]["seen_as_fighter_a_count"] == 1
    assert fighters["tapology_fighter_200"]["seen_as_fighter_b_count"] == 1
    assert len(logs["tapology_fighter_100"]) == 1
    assert len(logs["tapology_fighter_200"]) == 1
    assert validate_derived_outputs(derived) == []


def test_winner_gets_win_and_loser_gets_loss():
    derived = build_fighter_documents(results_fixture([bout_fixture(method_category="decision")]))
    fighters = derived["fighters_index"]["fighters"]
    logs = derived["fighter_fight_logs"]["fight_logs"]

    assert fighters["tapology_fighter_100"]["wins_in_dataset"] == 1
    assert fighters["tapology_fighter_200"]["losses_in_dataset"] == 1
    assert logs["tapology_fighter_100"][0]["result_for_fighter"] == "win"
    assert logs["tapology_fighter_200"][0]["result_for_fighter"] == "loss"


def test_draw_and_no_contest_are_handled():
    draw = bout_fixture(source_bout_id="tapology_bout_2", result_type="draw", winner=None, loser=None)
    draw["result"] = {"method_category": "draw", "method_detail": "majority"}
    no_contest = bout_fixture(
        source_bout_id="tapology_bout_3",
        fighter_a=fighter("No Contest A", 300, {"wins": 1, "losses": 0, "draws": 0, "nc": 0}),
        fighter_b=fighter("No Contest B", 400, {"wins": 1, "losses": 0, "draws": 0, "nc": 0}),
        result_type="no_contest",
        winner=None,
        loser=None,
    )
    no_contest["result"] = {"method_category": "no_contest"}
    derived = build_fighter_documents(results_fixture([draw, no_contest]))
    fighters = derived["fighters_index"]["fighters"]

    assert fighters["tapology_fighter_100"]["draws_in_dataset"] == 1
    assert fighters["tapology_fighter_200"]["draws_in_dataset"] == 1
    assert fighters["tapology_fighter_300"]["nc_in_dataset"] == 1
    assert fighters["tapology_fighter_400"]["nc_in_dataset"] == 1
    assert validate_derived_outputs(derived) == []


def test_finish_counts_distinguish_ko_submission_and_decision():
    ko = bout_fixture(source_bout_id="tapology_bout_2", method_category="ko_tko")
    sub = bout_fixture(
        source_bout_id="tapology_bout_3",
        fighter_a=fighter("Winner One", 100, {"wins": 2, "losses": 0, "draws": 0, "nc": 0}),
        fighter_b=fighter("Opponent Two", 300, {"wins": 1, "losses": 1, "draws": 0, "nc": 0}),
        method_category="submission",
    )
    dec = bout_fixture(
        source_bout_id="tapology_bout_4",
        fighter_a=fighter("Winner One", 100, {"wins": 3, "losses": 0, "draws": 0, "nc": 0}),
        fighter_b=fighter("Opponent Three", 400, {"wins": 1, "losses": 1, "draws": 0, "nc": 0}),
        method_category="decision",
    )
    derived = build_fighter_documents(results_fixture([ko, sub, dec]))
    winner = derived["fighters_index"]["fighters"]["tapology_fighter_100"]

    assert winner["wins_in_dataset"] == 3
    assert winner["finishes_in_dataset"] == 2
    assert winner["ko_tko_wins_in_dataset"] == 1
    assert winner["submission_wins_in_dataset"] == 1
    assert winner["decision_wins_in_dataset"] == 1


def test_opponent_prefight_diff_is_calculated():
    derived = build_fighter_documents(results_fixture([bout_fixture()]))
    winner_log = derived["fighter_fight_logs"]["fight_logs"]["tapology_fighter_100"][0]
    loser_log = derived["fighter_fight_logs"]["fight_logs"]["tapology_fighter_200"][0]

    assert winner_log["opponent_prefight_diff"] == 1
    assert loser_log["opponent_prefight_diff"] == 3


def test_duplicate_bout_does_not_create_duplicate_fight_log_row():
    bout = bout_fixture()
    duplicate = bout_fixture()
    derived = build_fighter_documents(results_fixture([bout, duplicate]))
    logs = derived["fighter_fight_logs"]["fight_logs"]

    assert len(logs["tapology_fighter_100"]) == 1
    assert len(logs["tapology_fighter_200"]) == 1
    assert derived["fighters_index"]["fighters"]["tapology_fighter_100"]["bouts_count_in_dataset"] == 1


def test_missing_method_category_is_reported():
    bout = bout_fixture()
    bout["result"] = {"method_category": "unknown"}
    derived = build_fighter_documents(results_fixture([bout]))
    report = derived["processor_report"]

    assert report["bouts_missing_method_category"] == 1


def test_missing_prefight_record_is_reported():
    bout = bout_fixture()
    bout["fighter_b"]["prefight_record"] = {"raw": None, "is_missing": True}
    derived = build_fighter_documents(results_fixture([bout]))
    report = derived["processor_report"]

    assert report["bouts_missing_prefight_records"] == 1
    assert report["fights_where_opponent_prefight_diff_cannot_be_calculated"] == 1


def test_fighter_index_separates_profile_prefight_and_computed_records():
    profiles_doc = {
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-winner-one",
                "status": "success",
                "scraped_at": "2026-06-10T00:00:00+00:00",
                "record_summary": {
                    "pro_record_raw": "4-1-0",
                    "wins": 4,
                    "losses": 1,
                    "draws": 0,
                    "no_contests": 0,
                },
            }
        ]
    }

    derived = build_fighter_documents(results_fixture([bout_fixture()]), profiles_doc=profiles_doc)
    winner = derived["fighters_index"]["fighters"]["tapology_fighter_100"]
    record_sources = winner["record_sources"]

    assert record_sources["tapology_profile_record"]["raw"] == "4-1-0"
    assert record_sources["prefight_record_latest"]["raw"] == "4-1-0"
    assert record_sources["computed_record_from_imported_bouts"] == {
        "source": "computed_record_from_imported_bouts",
        "wins": 1,
        "losses": 0,
        "draws": 0,
        "no_contests": 0,
        "total": 1,
    }
    assert record_sources["record_mismatch"] is True
    assert "tapology_profile_record_mismatch" in record_sources["warnings"]
    assert derived["processor_report"]["fighters_with_tapology_profile_record"] == 1
    assert derived["processor_report"]["fighters_with_record_mismatch"] == 1
    assert derived["processor_report"]["record_mismatch_fighter_keys"] == ["tapology_fighter_100"]


def test_derived_validation_catches_duplicate_fighter_fight_log_rows():
    derived = build_fighter_documents(results_fixture([bout_fixture()]))
    logs = derived["fighter_fight_logs"]["fight_logs"]["tapology_fighter_100"]
    logs.append(dict(logs[0]))

    errors = validate_derived_outputs(derived)

    assert "duplicate_fight_log_row:tapology_fighter_100:tapology_bout_1" in errors


def results_fixture(bouts):
    return {
        "schema_version": RESULTS_SCHEMA_VERSION,
        "run": {"run_id": "fixture-run"},
        "events": [
            {
                "internal_event_id": "mf_event_000001",
                "source_event_id": "tapology_event_1",
                "event_name": "Fixture FC",
                "event_date": "2026-05-01",
                "bouts": bouts,
            }
        ],
    }


def bout_fixture(
    *,
    source_bout_id="tapology_bout_1",
    fighter_a=None,
    fighter_b=None,
    result_type="win_loss",
    winner="fighter_a",
    loser="fighter_b",
    method_category="ko_tko",
):
    return {
        "internal_bout_id": source_bout_id.replace("tapology_bout_", "mf_bout_").zfill(14),
        "internal_event_id": "mf_event_000001",
        "source_event_id": "tapology_event_1",
        "source_bout_id": source_bout_id,
        "bout_url": f"https://www.tapology.com/fightcenter/bouts/{source_bout_id.removeprefix('tapology_bout_')}-fixture",
        "status": "completed",
        "pro_am": "professional",
        "card_position": "main_card",
        "weight_lbs": 155,
        "weight_class": "Lightweight",
        "winner": winner,
        "loser": loser,
        "result_type": result_type,
        "fighter_a": fighter_a or fighter("Winner One", 100, {"wins": 4, "losses": 1, "draws": 0, "nc": 0}),
        "fighter_b": fighter_b or fighter("Loser Two", 200, {"wins": 2, "losses": 1, "draws": 0, "nc": 0}),
        "result": {
            "method_category": method_category,
            "method_detail": "fixture method",
            "round": 2,
            "time": "1:23",
        },
    }


def fighter(name, source_id, record):
    return {
        "name": name,
        "url": f"https://www.tapology.com/fightcenter/fighters/{source_id}-{name.lower().replace(' ', '-')}",
        "prefight_record": {
            "raw": f"{record['wins']}-{record['losses']}-{record['draws']}",
            **record,
            "is_missing": False,
        },
    }
