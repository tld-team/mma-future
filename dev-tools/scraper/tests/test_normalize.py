from mma_future_scraper.normalize import (
    build_bout_flags,
    explicit_non_mma_event_reason,
    has_completed_result,
    is_explicit_non_mma_bout,
    is_ufc_promotion,
    normalize_method,
    parse_prefight_record,
    professional_status,
)


def test_parse_prefight_record_full_with_nc():
    record = parse_prefight_record("8-1-0, 1 NC")
    assert record == {
        "raw": "8-1-0, 1 NC",
        "wins": 8,
        "losses": 1,
        "draws": 0,
        "nc": 1,
        "is_missing": False,
    }


def test_parse_prefight_record_short():
    record = parse_prefight_record("8-1")
    assert record["wins"] == 8
    assert record["losses"] == 1
    assert record["draws"] == 0
    assert record["nc"] == 0
    assert record["is_missing"] is False


def test_parse_prefight_record_missing():
    record = parse_prefight_record("N/A")
    assert record["wins"] is None
    assert record["is_missing"] is True


def test_normalize_method_na_is_unknown():
    method = normalize_method("N/a")
    assert method["method_category"] == "unknown"
    assert method["method_detail"] is None
    assert method["warnings"] == ["unknown_method"]


def test_normalize_method_categories():
    assert normalize_method("KO/TKO")["method_category"] == "ko_tko"
    assert normalize_method("Submission")["method_category"] == "submission"
    assert normalize_method("Rear Naked Choke")["method_category"] == "submission"
    assert normalize_method("Punches")["method_category"] == "ko_tko"
    assert normalize_method("Unanimous Decision")["method_category"] == "decision"
    assert normalize_method("No Contest")["method_category"] == "no_contest"


def test_normalize_method_details():
    assert normalize_method("KO/TKO, Punches")["method_detail"] == "Punches"
    assert normalize_method("Submission, Rear Naked Choke")["method_detail"] == "Rear Naked Choke"
    assert normalize_method("Decision, Unanimous")["method_detail"] == "unanimous"


def test_has_completed_result_skips_unfinished_card_row():
    assert has_completed_result("Jane Doe 8-1 MMA MAIN EVENT 125 3 x 5 Ann Other 7-2 MMA") is False


def test_has_completed_result_accepts_completed_result_row():
    assert has_completed_result("Jane Doe def. Ann Other via Unanimous Decision R3 5:00") is True


def test_has_completed_result_accepts_tapology_w_l_markers():
    assert has_completed_result("8 W Salvatore Bellotta vs. Valentino Greco S. Bellotta vs Valentino Greco L Arm Triangle Choke R1 160") is True


def test_professional_status_detects_pro_and_amateur():
    assert professional_status("MAIN EVENT 160 3 x 5 | Pro") == "pro"
    assert professional_status("CO-MAIN 185 3 x 3 | Amateur") == "amateur"
    assert professional_status("MAIN CARD 145 3 x 5") == "unknown"


def test_explicit_non_mma_bout_detection():
    assert is_explicit_non_mma_bout("MAIN CARD 1 x 10 | Pro GRAPPLING") is True
    assert is_explicit_non_mma_bout("MAIN CARD 4 x 3 | Pro Boxing") is True
    assert is_explicit_non_mma_bout("MAIN CARD 3 x 3 | Pro Kickboxing") is True
    assert is_explicit_non_mma_bout("MAIN CARD 3 x 3 | Pro Muay Thai") is True
    assert is_explicit_non_mma_bout("MAIN CARD 3 x 3 | Pro Modified Boxing") is True
    assert is_explicit_non_mma_bout("MAIN CARD Boxing (Bare Knuckle) BKFC") is True
    assert is_explicit_non_mma_bout("POSTLIM 145 3 x 5 | Pro MMA") is False
    assert is_explicit_non_mma_bout("MAIN EVENT 160 3 x 5 | Pro") is False


def test_explicit_non_mma_event_detects_boxing_promotions_and_metadata():
    assert explicit_non_mma_event_reason(promotion_name="Sampson Boxing") == "non_mma_event_boxing"
    assert explicit_non_mma_event_reason(promotion_name="Matchroom Boxing") == "non_mma_event_boxing"
    assert explicit_non_mma_event_reason(promotion_name="Superstars OF Boxing") == "non_mma_event_boxing"
    assert explicit_non_mma_event_reason(promotion_name="Federacion Argentina DE BOX") == "non_mma_event_boxeo"
    assert explicit_non_mma_event_reason(event_name="Festival de Boxeo") == "non_mma_event_boxeo"
    assert explicit_non_mma_event_reason(enclosure="Ring Boxing Bouts: 6") == "non_mma_event_boxing"
    assert (
        explicit_non_mma_event_reason(
            promotion_url="https://www.tapology.com/fightcenter/promotions/2484-matchroom-boxing-mb"
        )
        == "non_mma_event_boxing"
    )


def test_explicit_non_mma_event_detects_freestyle_and_open_mat():
    assert explicit_non_mma_event_reason(promotion_name="Real American Freestyle") == "non_mma_event_freestyle"
    assert (
        explicit_non_mma_event_reason(
            promotion_url="https://www.tapology.com/fightcenter/promotions/6307-real-american-freestyle-raf"
        )
        == "non_mma_event_freestyle"
    )
    assert explicit_non_mma_event_reason(enclosure="Open Mat") == "non_mma_event_open_mat"
    assert (
        explicit_non_mma_event_reason(
            promotion_name="RAF",
            raw_text="Result: Tech Fall 11-0. Result: Pinfall at 1:42.",
        )
        == "non_mma_event_freestyle"
    )


def test_explicit_non_mma_event_does_not_use_page_wide_freestyle_text_against_mma_event():
    assert (
        explicit_non_mma_event_reason(
            event_name="ARMMADA 18",
            promotion_name="ARMMADA",
            raw_text=(
                "Completed MMA Event. MMA Bouts: 6. Gradinac vs. Brasil. "
                "Unrelated sidebar: Real American Freestyle result by Tech Fall."
            ),
        )
        is None
    )


def test_explicit_non_mma_event_allows_known_valid_mma_promotions():
    allowed = [
        {"promotion_name": "Pancrase Hybrid Wrestling", "raw_text": "Historical wrestling wording on MMA page"},
        {"promotion_name": "PFL"},
        {"promotion_name": "Professional Fighters League"},
        {"promotion_name": "Brave Combat Federation"},
        {"promotion_name": "Brave CF"},
        {"promotion_name": "Shooto"},
        {"promotion_name": "Shamrock Fighting Championships"},
        {"promotion_name": "Shamrock FC"},
        {"promotion_name": "Tough N Xtreme"},
        {"promotion_name": "TNX"},
    ]

    for kwargs in allowed:
        assert explicit_non_mma_event_reason(**kwargs) is None


def test_explicit_non_mma_bout_backup_filter_is_conservative():
    assert is_explicit_non_mma_bout("Tech Fall") is True
    assert is_explicit_non_mma_bout("Pinfall") is True
    assert is_explicit_non_mma_bout("MAIN CARD 3 x 5 | Pro MMA") is False
    assert is_explicit_non_mma_bout("Professional MMA") is False
    assert is_explicit_non_mma_bout("Ground and Pound") is False
    assert is_explicit_non_mma_bout("Rear Naked Choke") is False
    assert is_explicit_non_mma_bout("Boxing Bout") is True
    assert is_explicit_non_mma_bout("Boxeo") is True


def test_ufc_promotion_detection_is_event_metadata_scoped():
    assert is_ufc_promotion("PFL") is False
    assert is_ufc_promotion("Professional Fighters League") is False
    assert is_ufc_promotion("Cage Warriors") is False
    assert is_ufc_promotion("UFC") is True
    assert is_ufc_promotion("Ultimate Fighting Championship") is True
    assert is_ufc_promotion(None, "https://www.tapology.com/fightcenter/promotions/1-ultimate-fighting-championship-ufc") is True
    assert is_ufc_promotion(None, event_name="UFC Fight Night: Example vs. Fixture") is True
    assert is_ufc_promotion(None, event_name="Regional FC: former UFC fighter showcase") is False


def bout_flags_fixture(**overrides):
    bout = {
        "status": "completed",
        "pro_am": "professional",
        "result_type": "win_loss",
        "result": {"method_category": "decision"},
    }
    bout.update(overrides)
    return build_bout_flags(bout)


def test_completed_professional_win_loss_is_scoring_candidate():
    flags = bout_flags_fixture()

    assert flags == {
        "is_completed": True,
        "is_professional": True,
        "is_amateur": False,
        "is_cancelled": False,
        "is_overturned": False,
        "is_upcoming": False,
        "is_scoring_candidate": True,
        "is_bout_scoring_candidate": True,
    }


def test_draw_is_not_scoring_candidate():
    flags = bout_flags_fixture(result_type="draw", result={"method_category": "draw"})

    assert flags["is_scoring_candidate"] is False


def test_no_contest_is_not_scoring_candidate():
    flags = bout_flags_fixture(result_type="no_contest", result={"method_category": "no_contest"})

    assert flags["is_scoring_candidate"] is False


def test_unknown_result_is_not_scoring_candidate():
    flags = bout_flags_fixture(result_type="unknown")

    assert flags["is_scoring_candidate"] is False


def test_upcoming_is_not_scoring_candidate():
    flags = bout_flags_fixture(status="upcoming")

    assert flags["is_completed"] is False
    assert flags["is_upcoming"] is True
    assert flags["is_scoring_candidate"] is False


def test_amateur_is_not_scoring_candidate():
    flags = bout_flags_fixture(pro_am="amateur")

    assert flags["is_professional"] is False
    assert flags["is_amateur"] is True
    assert flags["is_scoring_candidate"] is False


def test_cancelled_is_not_scoring_candidate():
    flags = bout_flags_fixture(status="cancelled", result_type="cancelled")

    assert flags["is_cancelled"] is True
    assert flags["is_scoring_candidate"] is False


def test_overturned_is_not_scoring_candidate():
    flags = bout_flags_fixture(is_overturned=True)

    assert flags["is_overturned"] is True
    assert flags["is_scoring_candidate"] is False
