from __future__ import annotations

from mma_future_scraper.staging_dry_run import (
    analyze_prefight,
    build_canonical_index,
    build_dry_run_report,
    classify_sport,
    classify_staging,
    compare_against_canonical,
    parse_record,
    recommend_action,
)


# ---------------------------------------------------------------------------
# Sport classification
# ---------------------------------------------------------------------------


def _row(**overrides):
    base = {
        "fight_date": "2025-10-17",
        "event_name": "DEEP JEWELS 47",
        "event_url": "https://www.tapology.com/fightcenter/events/100-deep-jewels-47",
        "opponent_name": "Haruka Suzuki",
        "opponent_url": "https://www.tapology.com/fightcenter/fighters/200-haruka-suzuki",
        "result": "win",
        "method": "KO/TKO",
        "method_category": "ko_tko",
        "round": 1,
        "time": "3:32",
        "is_cancelled": False,
        "is_upcoming": False,
        "is_amateur": False,
        "is_professional": True,
        "is_overturned": False,
        "opponent_record_raw": "2-2",
        "prefight_record_raw": "25-2-1",
        "raw_text": "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47",
        "bout_url": "https://www.tapology.com/fightcenter/bouts/300-foo",
        "source_bout_id": "tapology_bout_300",
    }
    base.update(overrides)
    return base


def test_sport_mma_candidate_default():
    assert classify_sport(_row()) == "mma_candidate"


def test_sport_cancelled_takes_priority():
    row = _row(is_cancelled=True, event_name="DEEP JEWELS 47")
    assert classify_sport(row) == "cancelled"


def test_sport_amateur():
    assert classify_sport(_row(is_amateur=True)) == "amateur"


def test_sport_overturned():
    assert classify_sport(_row(is_overturned=True)) == "overturned"


def test_sport_grappling():
    row = _row(
        event_name="Nitro Grappling Expo 3",
        method="Kimura",
        raw_text="W SUB Charis Furgat-Johnson Grappling Kimura Nitro Grappling Expo 3 2025 Oct 17",
    )
    assert classify_sport(row) == "grappling"


def test_sport_boxing():
    row = _row(event_name="WBA Title Fight", raw_text="W boxing decision", method="Decision")
    assert classify_sport(row) == "boxing"


def test_sport_bare_knuckle():
    row = _row(event_name="BKFC 12", raw_text="BKFC bare knuckle fight")
    assert classify_sport(row) == "bare_knuckle"


def test_sport_kickboxing():
    row = _row(event_name="Glory 80", raw_text="Glory kickboxing")
    assert classify_sport(row) == "kickboxing"


def test_sport_muay_thai():
    row = _row(event_name="ONE Friday Fights", raw_text="Muay Thai rules at Lumpinee")
    assert classify_sport(row) == "muay_thai"


def test_sport_adcc_bjj_grappling():
    row = _row(event_name="ADCC Open", raw_text="BJJ no-gi grappling match")
    assert classify_sport(row) == "grappling"


def test_sport_unknown_when_no_signal():
    row = _row(event_name="", method="", method_category="", opponent_name="", raw_text="")
    assert classify_sport(row) == "unknown_sport"


# ---------------------------------------------------------------------------
# Non-MMA rows must not be staging candidates
# ---------------------------------------------------------------------------


def test_non_mma_grappling_is_not_staging_candidate():
    row = _row(event_name="Nitro Grappling Expo 3", raw_text="grappling kimura")
    sport = classify_sport(row)
    assert sport == "grappling"
    assert classify_staging(row, sport_type=sport, profile_fighter_id="tapology_fighter_1") == "not_staging_candidate"


def test_cancelled_row_is_not_staging_candidate():
    row = _row(is_cancelled=True)
    sport = classify_sport(row)
    assert sport == "cancelled"
    assert classify_staging(row, sport_type=sport, profile_fighter_id="tapology_fighter_1") == "not_staging_candidate"


def test_amateur_and_overturned_rows_are_not_staging_candidates():
    for row, expected_sport in [
        (_row(is_amateur=True), "amateur"),
        (_row(is_overturned=True), "overturned"),
    ]:
        sport = classify_sport(row)
        assert sport == expected_sport
        assert classify_staging(row, sport_type=sport, profile_fighter_id="tapology_fighter_1") == "not_staging_candidate"


# ---------------------------------------------------------------------------
# Staging confidence
# ---------------------------------------------------------------------------


def test_staging_high_confidence():
    row = _row()
    assert (
        classify_staging(row, sport_type="mma_candidate", profile_fighter_id="tapology_fighter_1")
        == "high"
    )


def test_staging_medium_confidence_no_urls():
    row = _row(event_url=None, opponent_url=None, bout_url=None)
    assert (
        classify_staging(row, sport_type="mma_candidate", profile_fighter_id="tapology_fighter_1")
        == "medium"
    )


def test_staging_low_confidence_missing_prefight():
    row = _row(opponent_url=None, bout_url=None, opponent_record_raw=None, prefight_record_raw=None)
    assert (
        classify_staging(row, sport_type="mma_candidate", profile_fighter_id="tapology_fighter_1")
        == "low"
    )


def test_staging_requires_profile_fighter_id():
    row = _row()
    assert (
        classify_staging(row, sport_type="mma_candidate", profile_fighter_id=None)
        == "not_staging_candidate"
    )


def test_staging_requires_date_and_opponent():
    row = _row(fight_date=None, opponent_name=None)
    assert (
        classify_staging(row, sport_type="mma_candidate", profile_fighter_id="tapology_fighter_1")
        == "not_staging_candidate"
    )


# ---------------------------------------------------------------------------
# Prefight record parsing
# ---------------------------------------------------------------------------


def test_parse_record_simple():
    parsed = parse_record("27-2-1")
    assert parsed["wins"] == 27
    assert parsed["losses"] == 2
    assert parsed["draws"] == 1
    assert parsed["nc"] == 0
    assert parsed["is_missing"] is False


def test_parse_record_two_part():
    parsed = parse_record("4-1")
    assert parsed["wins"] == 4
    assert parsed["losses"] == 1
    assert parsed["draws"] == 0
    assert parsed["nc"] == 0


def test_parse_record_with_nc():
    parsed = parse_record("10-2-0, 1 NC")
    assert parsed["wins"] == 10
    assert parsed["losses"] == 2
    assert parsed["draws"] == 0
    assert parsed["nc"] == 1


def test_parse_record_missing():
    assert parse_record(None)["is_missing"] is True
    assert parse_record("N/A")["is_missing"] is True
    assert parse_record("unknown")["is_missing"] is True


def test_analyze_prefight_full():
    row = _row()
    out = analyze_prefight(row)
    assert out["fighter_prefight_present"] is True
    assert out["opponent_prefight_present"] is True
    assert out["formula_v12_ready"] is True
    assert out["opponent_diff_prefight_total"] is not None


def test_analyze_prefight_missing_opponent():
    row = _row(opponent_record_raw=None)
    out = analyze_prefight(row)
    assert out["opponent_prefight_present"] is False
    assert out["formula_v12_ready"] is False
    assert out["warning_missing_opponent_prefight"] is True


# ---------------------------------------------------------------------------
# Canonical comparison
# ---------------------------------------------------------------------------


def _canonical_dump():
    return {
        "schema_version": "canonical_bouts_dump_v0_1",
        "bouts": [
            {
                "bout_id": 1,
                "event_id": 1,
                "source_bout_ids": ["tapology_bout_300"],
                "source_event_ids": ["tapology_event_100"],
                "fighter_source_ids": ["tapology_fighter_1", "tapology_fighter_200"],
                "event_name": "DEEP JEWELS 47",
                "event_date": "2025-10-17",
                "fighter_names": ["Rin Nakai", "Haruka Suzuki"],
            }
        ],
    }


def test_canonical_dedup_already_canonical_by_source_bout_id():
    index = build_canonical_index(_canonical_dump())
    row = _row()
    res = compare_against_canonical(row, profile_source_fighter_id="tapology_fighter_1", canonical_index=index)
    assert res["status"] == "already_canonical"


def test_canonical_dedup_missing_when_no_match():
    index = build_canonical_index(_canonical_dump())
    row = _row(source_bout_id=None, bout_url=None, opponent_url=None, fight_date="2024-01-01", event_name="OTHER EVENT")
    res = compare_against_canonical(row, profile_source_fighter_id="tapology_fighter_1", canonical_index=index)
    assert res["status"] == "missing_from_canonical"


def test_canonical_dedup_dump_missing():
    index = build_canonical_index(None)
    res = compare_against_canonical(_row(), profile_source_fighter_id="tapology_fighter_1", canonical_index=index)
    assert res["status"] == "canonical_dump_missing"


# ---------------------------------------------------------------------------
# Recommended actions
# ---------------------------------------------------------------------------


def test_recommend_action_ignore_non_mma():
    assert recommend_action(
        sport_type="grappling",
        staging_confidence="not_staging_candidate",
        canonical_status="missing_from_canonical",
        prefight={"needs_bout_detail_fetch": False},
    ) == "ignore_non_mma"


def test_recommend_action_ignore_cancelled():
    assert recommend_action(
        sport_type="cancelled",
        staging_confidence="not_staging_candidate",
        canonical_status="missing_from_canonical",
        prefight={"needs_bout_detail_fetch": False},
    ) == "ignore_cancelled"


def test_recommend_action_stage_for_review():
    assert recommend_action(
        sport_type="mma_candidate",
        staging_confidence="high",
        canonical_status="missing_from_canonical",
        prefight={"needs_bout_detail_fetch": False},
    ) == "stage_for_review"


def test_recommend_action_already_canonical():
    assert recommend_action(
        sport_type="mma_candidate",
        staging_confidence="high",
        canonical_status="already_canonical",
        prefight={"needs_bout_detail_fetch": False},
    ) == "already_canonical"


def test_recommend_action_needs_bout_detail_fetch():
    assert recommend_action(
        sport_type="mma_candidate",
        staging_confidence="low",
        canonical_status="missing_from_canonical",
        prefight={"needs_bout_detail_fetch": True},
    ) == "needs_bout_detail_fetch"


# ---------------------------------------------------------------------------
# End-to-end report
# ---------------------------------------------------------------------------


def test_staging_medium_when_event_name_present_but_no_event_url():
    """A row with event_name from raw_text but no event_url should still
    qualify as a medium-confidence staging candidate as long as it has
    opponent_url evidence and both prefight records."""
    row = _row(event_url=None, source_event_id=None, bout_url=None)
    sport = classify_sport(row)
    assert sport == "mma_candidate"
    assert (
        classify_staging(row, sport_type=sport, profile_fighter_id="tapology_fighter_1")
        == "medium"
    )


def test_staging_dry_run_backfills_event_name_from_raw_text():
    """Profiles produced by older parser runs store rows without event_name
    even though raw_text clearly contains the Tapology event label. The
    dry-run must recover the label and promote the row to staging."""
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [
                    {
                        "fight_date": "2024-11-23",
                        "event_name": None,
                        "event_url": None,
                        "opponent_name": "Haruka Suzuki",
                        "opponent_url": None,
                        "result": "win",
                        "method": "KO/TKO",
                        "method_category": "ko_tko",
                        "round": 1,
                        "time": "3:32",
                        "is_cancelled": False,
                        "is_upcoming": False,
                        "is_amateur": False,
                        "is_overturned": False,
                        "opponent_record_raw": "2-2",
                        "prefight_record_raw": "25-2-1",
                        "raw_text": "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47 2024 Nov 23",
                    }
                ],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=None)
    [entry] = report["rows"]
    assert entry["row"]["event_name"] == "DEEP JEWELS 47"
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert entry["classification"]["staging_confidence"] == "medium"
    assert entry["classification"]["recommended_action"] == "stage_for_review"


def test_staging_dry_run_does_not_invent_event_name_for_ambiguous_raw_text():
    """If raw_text has no recognizable end-of-method marker, the dry-run
    must leave event_name empty and the row remains not_staging_candidate."""
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [
                    {
                        "fight_date": "2024-11-23",
                        "event_name": None,
                        "opponent_name": "Some Opponent",
                        "result": "win",
                        "method_category": "ko_tko",
                        "raw_text": "Some Opponent fought somewhere",
                        "prefight_record_raw": "5-0",
                        "opponent_record_raw": "2-2",
                    }
                ],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=None)
    [entry] = report["rows"]
    assert entry["row"]["event_name"] is None
    assert entry["classification"]["staging_confidence"] == "not_staging_candidate"


def test_staging_dry_run_does_not_promote_non_mma_after_event_backfill():
    """Even if raw_text yields a clean event name, a grappling or bare-knuckle
    row must remain non-MMA and never become a staging candidate."""
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [
                    {
                        "fight_date": "2026-02-07",
                        "event_name": None,
                        "opponent_name": "Pat Sullivan",
                        "result": "win",
                        "method_category": "ko_tko",
                        "raw_text": "W TKO Pat Sullivan Boxing (Bare Knuckle) Left Hand · 1:16 · R2 BKFC: Knuckle Mania 6 2026 Feb 7",
                        "prefight_record_raw": "10-2",
                        "opponent_record_raw": "5-3",
                    }
                ],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=None)
    [entry] = report["rows"]
    # Event name is recovered…
    assert entry["row"]["event_name"] == "BKFC: Knuckle Mania 6"
    # …but the sport is still bare_knuckle and the row is filtered out.
    assert entry["classification"]["sport_type"] == "bare_knuckle"
    assert entry["classification"]["staging_confidence"] == "not_staging_candidate"
    assert entry["classification"]["recommended_action"] == "ignore_non_mma"


def test_staging_dry_run_scopes_multi_fight_raw_text_before_classification():
    """Legacy container rows can include several add_circle-separated fights.
    Only the first fight should drive event backfill and sport filtering."""
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [
                    {
                        "fight_date": "2026-05-24",
                        "event_name": None,
                        "opponent_name": "Yuka Okutomi",
                        "result": "win",
                        "method": "Decision",
                        "method_category": "decision",
                        "is_cancelled": False,
                        "is_amateur": False,
                        "is_overturned": False,
                        "prefight_record_raw": "26-2-1",
                        "opponent_record_raw": "4-1",
                        "raw_text": (
                            "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision Â· Unanimous DEEP JEWELS 53 2026 May 24 "
                            "add_circle W TKO Pat Sullivan Boxing (Bare Knuckle) Left Hand Â· 1:16 Â· R2 "
                            "BKFC: Knuckle Mania 6 2026 Feb 7 add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
                        ),
                    }
                ],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=None)
    [entry] = report["rows"]
    assert entry["row"]["event_name"] == "DEEP JEWELS 53"
    assert entry["row"]["opponent_name"] == "Yuka Okutomi"
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert entry["classification"]["staging_confidence"] == "medium"


def test_build_dry_run_report_no_db_writes_flag():
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [_row()],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=_canonical_dump())
    assert report["writes_db"] is False
    assert report["non_writable_phase"] is True
    assert report["summary"]["profile_history_rows_total"] == 1
    assert report["summary"]["rows_mma_candidate"] == 1
    assert report["summary"]["already_canonical"] == 1


# ---------------------------------------------------------------------------
# Row-boundary / exclusion-flag re-derivation
# ---------------------------------------------------------------------------


def _doc_with_history(history_row: dict) -> dict:
    return {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [history_row],
            }
        ],
    }


def test_completed_row_followed_by_cancelled_row_does_not_become_cancelled():
    """A clean W DEC row whose container also held a later 'C ... Cancelled
    Bout' fragment must not inherit is_cancelled=True or be classified as
    cancelled at staging time."""
    row = {
        "fight_date": "2026-05-24",
        "opponent_name": "Yuka Okutomi",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "DEEP JEWELS 53",
        "event_url": "https://www.tapology.com/fightcenter/events/142423-deep-jewels-53",
        "is_cancelled": True,
        "is_amateur": False,
        "is_overturned": True,
        "prefight_record_raw": "26-2-1",
        "opponent_record_raw": "4-1",
        "raw_text": (
            "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision DEEP JEWELS 53 2026 May 24 "
            "add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
        ),
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "mma_candidate"
    warnings = entry["warnings"]
    assert "row_boundary_suspected" in warnings
    assert "exclusion_flag_from_neighbor_suspected:cancelled" in warnings
    assert "exclusion_flag_from_neighbor_suspected:overturned" in warnings


def test_professional_row_followed_by_amateur_bouts_does_not_become_amateur():
    row = {
        "fight_date": "2026-05-22",
        "opponent_name": "Alejandro Manzorro",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "WOW 30: Lisboa",
        "is_cancelled": False,
        "is_amateur": True,
        "is_overturned": False,
        "prefight_record_raw": "1-0",
        "opponent_record_raw": "0-0",
        "raw_text": (
            "PROFESSIONAL BOUTS W DEC Alejandro Manzorro 1-1 0-0 Decision WOW 30: Lisboa 2026 May 22 "
            "add_circle AMATEUR BOUTS L DEC Foo Bar"
        ),
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert "exclusion_flag_from_neighbor_suspected:amateur" in entry["warnings"]


def test_completed_row_with_no_contest_neighbor_does_not_become_overturned():
    row = {
        "fight_date": "2026-05-24",
        "opponent_name": "Ye Ji Lee",
        "result": "loss",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "DEEP JEWELS 53",
        "is_cancelled": False,
        "is_amateur": True,
        "is_overturned": True,
        "prefight_record_raw": "5-4",
        "opponent_record_raw": "6-7",
        "raw_text": (
            "PROFESSIONAL BOUTS L DEC Ye Ji Lee 5-5 6-8 Decision DEEP JEWELS 53 2026 May 24 "
            "add_circle NC Some Other No Contest Round 1 2025 Jan 1"
        ),
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert "exclusion_flag_from_neighbor_suspected:overturned" in entry["warnings"]


def test_genuine_cancelled_row_remains_cancelled():
    row = {
        "fight_date": "2026-03-21",
        "opponent_name": "Pannie Kianzad",
        "result": "cancelled",
        "method": "unknown",
        "method_category": "unknown",
        "is_cancelled": True,
        "is_amateur": False,
        "is_overturned": False,
        "raw_text": "C Pannie Kianzad Cancelled Bout 2026 Mar 21",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "cancelled"
    assert entry["classification"]["recommended_action"] == "ignore_cancelled"


def test_genuine_amateur_row_remains_amateur():
    row = {
        "fight_date": "2007-04-01",
        "opponent_name": "Foo Bar",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "Local Card",
        "is_cancelled": False,
        "is_amateur": True,
        "is_overturned": False,
        "raw_text": "W DEC Foo Bar Decision Amateur Local Card 2007 Apr 1",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "amateur"


def test_genuine_non_mma_rows_remain_excluded():
    for raw, expected_sport in [
        ("L DEC Corey Stokes Boxing Decision Off the Hook Boxing Series 2 2025 Sep 27", "boxing"),
        (
            "L TKO Toby Misech Boxing (Bare Knuckle) Decision BKFC Fight Night 2026 Apr 11",
            "bare_knuckle",
        ),
        ("W SUB Charis Furgat-Johnson Grappling Kimura Nitro Grappling Expo 3 2025 Oct 17", "grappling"),
        ("L TKO Changpuek Muay Thai Knee MAS Fight 2017 Apr 15", "muay_thai"),
    ]:
        row = {
            "fight_date": "2025-01-01",
            "opponent_name": "Placeholder",
            "result": "win",
            "method": "Decision",
            "method_category": "decision",
            "raw_text": raw,
        }
        report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
        [entry] = report["rows"]
        assert entry["classification"]["sport_type"] == expected_sport, raw
        assert entry["classification"]["recommended_action"] == "ignore_non_mma"


def test_polluted_opponent_name_demotes_to_needs_bout_detail_fetch():
    """An opponent_name carrying 'Modified Rules MMA' / method text /
    record numbers must not enter the clean stage_for_review bucket."""
    row = {
        "fight_date": "2007-05-19",
        "opponent_name": "Kazue Matake Modified Rules MMA",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "Smackgirl 2007",
        "event_url": "https://www.tapology.com/fightcenter/events/1-smackgirl-2007",
        "is_cancelled": False,
        "is_amateur": False,
        "is_overturned": False,
        "prefight_record_raw": "5-2-1",
        "opponent_record_raw": "0-0",
        "raw_text": "W DEC Kazue Matake Modified Rules MMA Decision Smackgirl 2007 2007 May 19",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert "polluted_opponent_suspected" in entry["warnings"]
    assert entry["classification"]["recommended_action"] == "needs_bout_detail_fetch"


def test_polluted_opponent_with_method_text_is_flagged():
    row = {
        "fight_date": "2021-10-17",
        "opponent_name": "Mariya Suzuki Custom Rules No Contest Ground Pound 0:22 R2 HEAT 49",
        "result": "no_contest",
        "raw_text": "NC Mariya Suzuki Custom Rules No Contest Ground Pound 0:22 R2 HEAT 49 2021 Oct 17",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert "polluted_opponent_suspected" in entry["warnings"]


def test_polluted_opponent_with_date_token_is_flagged():
    row = {
        "fight_date": "2025-10-17",
        "opponent_name": "Charis Furgat-Johnson Grappling Kimura Nitro Grappling Expo 3 2025 Oct 17",
        "result": "win",
        "raw_text": "W SUB Charis Furgat-Johnson Grappling Kimura Nitro Grappling Expo 3 2025 Oct 17",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert "polluted_opponent_suspected" in entry["warnings"]


def test_clean_opponent_emits_no_pollution_warning():
    row = {
        "fight_date": "2024-11-23",
        "opponent_name": "Haruka Suzuki",
        "result": "win",
        "method": "KO/TKO",
        "method_category": "ko_tko",
        "event_name": "DEEP JEWELS 47",
        "prefight_record_raw": "25-2-1",
        "opponent_record_raw": "2-2",
        "raw_text": "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47 2024 Nov 23",
    }
    report = build_dry_run_report(_doc_with_history(row), canonical_dump=None)
    [entry] = report["rows"]
    assert entry["warnings"] == []
    assert "polluted_opponent_suspected" not in entry["warnings"]


def test_summary_counts_include_warning_rollups():
    polluted_row = {
        "fight_date": "2007-05-19",
        "opponent_name": "Kazue Matake Modified Rules MMA",
        "result": "win",
        "method": "Decision",
        "event_name": "Smackgirl 2007",
        "prefight_record_raw": "5-0",
        "opponent_record_raw": "0-0",
        "raw_text": "W DEC Kazue Matake Modified Rules MMA Decision Smackgirl 2007 2007 May 19",
    }
    bleed_row = {
        "fight_date": "2026-05-24",
        "opponent_name": "Yuka Okutomi",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "DEEP JEWELS 53",
        "is_cancelled": True,
        "is_amateur": False,
        "is_overturned": True,
        "prefight_record_raw": "26-2-1",
        "opponent_record_raw": "4-1",
        "raw_text": (
            "W DEC Yuka Okutomi 26-2-1 4-1 Decision DEEP JEWELS 53 2026 May 24 "
            "add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
        ),
    }
    profiles_document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "T",
        "scraped_at": "2026-05-26T00:00:00+00:00",
        "profiles_total": 1,
        "profiles_success": 1,
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_1",
                "source_url": "https://www.tapology.com/fightcenter/fighters/1-foo",
                "status": "success",
                "display_name": "Foo",
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [polluted_row, bleed_row],
            }
        ],
    }
    report = build_dry_run_report(profiles_document, canonical_dump=None)
    summary = report["summary"]
    assert summary["rows_with_warnings"] >= 2
    assert summary["rows_with_polluted_opponent"] >= 1
    assert summary["rows_with_row_boundary_suspect"] >= 1
    assert summary["rows_with_exclusion_neighbor_suspect"] >= 1
    assert "by_warning" in summary
    assert summary["by_warning"].get("polluted_opponent_suspected", 0) >= 1


def test_already_canonical_row_keeps_already_canonical_action_even_with_neighbor_bleed():
    """The Yuka Okutomi DOM row matches canonical via source_bout_id. Its
    persisted is_cancelled=True (from the next-row Pannie Kianzad bleed)
    must not turn its recommended action into ignore_cancelled."""
    bleed_row = {
        "fight_date": "2026-05-24",
        "opponent_name": "Yuka Okutomi",
        "result": "win",
        "method": "Decision",
        "method_category": "decision",
        "event_name": "DEEP JEWELS 53",
        "event_url": "https://www.tapology.com/fightcenter/events/100-deep-jewels-47",
        "source_event_id": "tapology_event_100",
        "bout_url": "https://www.tapology.com/fightcenter/bouts/300-foo",
        "source_bout_id": "tapology_bout_300",
        "opponent_url": "https://www.tapology.com/fightcenter/fighters/200-haruka-suzuki",
        "is_cancelled": True,
        "is_amateur": False,
        "is_overturned": True,
        "prefight_record_raw": "26-2-1",
        "opponent_record_raw": "4-1",
        "raw_text": (
            "W DEC Yuka Okutomi 26-2-1 4-1 Decision DEEP JEWELS 53 2026 May 24 "
            "add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
        ),
    }
    report = build_dry_run_report(_doc_with_history(bleed_row), canonical_dump=_canonical_dump())
    [entry] = report["rows"]
    assert entry["classification"]["sport_type"] == "mma_candidate"
    assert entry["classification"]["canonical_match_status"] == "already_canonical"
    assert entry["classification"]["recommended_action"] == "already_canonical"


def test_recommend_action_polluted_opponent_demotes_clean_mma_candidate():
    assert recommend_action(
        sport_type="mma_candidate",
        staging_confidence="high",
        canonical_status="missing_from_canonical",
        prefight={"needs_bout_detail_fetch": False},
        opponent_polluted=True,
    ) == "needs_bout_detail_fetch"
