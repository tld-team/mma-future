import json

import mma_future_scraper.fighter_profile_queue as fighter_profile_queue
from mma_future_scraper.fighter_profile_report import build_profile_enrichment_report, profile_completeness_score
from mma_future_scraper.fighter_profile_queue import (
    build_fighter_profile_queue,
    collect_fighter_profile_urls_from_queue,
    normalize_tapology_fighter_url,
)
from mma_future_scraper.fighter_profiles import (
    build_event_profile_enrichment_summary,
    collect_fighter_profile_urls,
    event_profile_summary,
    extract_event_name_from_raw_text,
    failed_profile,
    merge_fighter_profiles_documents,
    normalize_profile_for_event_output,
    parse_profile_snapshot,
    scope_fight_row_text,
)


def test_collect_fighter_profile_urls_from_results_keeps_first_seen_order(tmp_path):
    results_path = tmp_path / "results.json"
    results_path.write_text(
        json.dumps(
            {
                "events": [
                    {
                        "bouts": [
                            {
                                "fighter_a": {"url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe"},
                                "fighter_b": {"url": "https://www.tapology.com/fightcenter/fighters/200-ann-other"},
                            },
                            {
                                "fighter_a": {"url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe"},
                                "fighter_b": {"url": "https://www.tapology.com/fightcenter/fighters/300-sam-third"},
                            },
                        ]
                    }
                ]
            }
        ),
        encoding="utf-8",
    )

    assert collect_fighter_profile_urls(results_path, 2) == [
        "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
        "https://www.tapology.com/fightcenter/fighters/200-ann-other",
    ]


def test_normalize_tapology_fighter_url_accepts_slug_only_profiles():
    assert (
        normalize_tapology_fighter_url("https://www.tapology.com/fightcenter/fighters/rin-nakai?x=1#bio")
        == "https://www.tapology.com/fightcenter/fighters/rin-nakai"
    )


def test_parse_profile_snapshot_extracts_explicit_summary_record_and_history():
    snapshot = {
        "title": "Jane Doe | MMA Fighter Page | Tapology",
        "headings": ["Jane Doe"],
        "meta_image_url": "https://images.tapology.com/headshot.jpg",
        "images": [],
        "body_text": (
            "Jane Doe Pro MMA Record: 5-1-0, 1 NC Amateur MMA Record: 2-0-0 "
            "Nickname: The Example Age: 27 Date of Birth: January 4, 1999 "
            "Country: United States Born Location: Denver, Colorado "
            "Fighting out of: Miami, Florida Association: Example MMA "
            "Height: 5'6\" Reach: 66\" Weight: 125 lbs Last Weigh-In: 124.8 lbs Weight Class: Flyweight"
        ),
        "lines": [
            "Jane Doe",
            "Pro MMA Record: 5-1-0, 1 NC",
            "Amateur MMA Record: 2-0-0",
            "Nickname: The Example",
            "Age: 27",
            "Date of Birth: January 4, 1999",
            "Country: United States",
            "Born Location: Denver, Colorado",
            "Fighting out of: Miami, Florida",
            "Association: Example MMA",
            "Height: 5'6\"",
            "Reach: 66\"",
            "Weight: 125 lbs",
            "Last Weigh-In: 124.8 lbs",
            "Weight Class: Flyweight",
        ],
        "rows": [
            {
                "raw_text": "W Jane Doe def. Ann Other Decision Round 3 5:00 May 1, 2026 Example FC 12 4-0-0",
                "cells": ["May 1, 2026", "Example FC 12", "Ann Other", "W", "Decision", "Round 3", "5:00"],
                "links": [
                    {"text": "Example FC 12", "href": "https://www.tapology.com/fightcenter/events/999-example-fc-12"},
                    {"text": "Bout", "href": "https://www.tapology.com/fightcenter/bouts/888-example-bout"},
                    {"text": "Ann Other", "href": "https://www.tapology.com/fightcenter/fighters/200-ann-other"},
                ],
            }
        ],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/100-jane-doe")

    assert profile["status"] == "success"
    assert profile["source_fighter_id"] == "tapology_fighter_100"
    assert profile["display_name"] == "Jane Doe"
    assert profile["profile_summary"]["nickname"] == "The Example"
    assert profile["profile_summary"]["date_of_birth"] == "1999-01-04"
    assert profile["profile_summary"]["birth_year"] == 1999
    assert profile["profile_summary"]["fighting_out_of"] == "Miami, Florida"
    assert profile["profile_summary"]["last_weigh_in"] == "124.8 lbs"
    assert profile["record_summary"]["wins"] == 5
    assert profile["record_summary"]["losses"] == 1
    assert profile["record_summary"]["no_contests"] == 1
    assert profile["fight_history"][0]["fight_date"] == "2026-05-01"
    assert profile["fight_history"][0]["opponent_name"] == "Ann Other"
    assert profile["fight_history"][0]["event_name"] == "Example FC 12"
    assert profile["fight_history"][0]["event_url"] == "https://www.tapology.com/fightcenter/events/999-example-fc-12"
    assert profile["fight_history"][0]["source_event_id"] == "tapology_event_999"
    assert profile["fight_history"][0]["bout_url"] == "https://www.tapology.com/fightcenter/bouts/888-example-bout"
    assert profile["fight_history"][0]["source_bout_id"] == "tapology_bout_888"


def test_event_profile_output_lowen_tynanes_style_profile():
    snapshot = {
        "title": "Lowen Tynanes | Tapology",
        "headings": ["Lowen Tynanes"],
        "body_text": (
            "Lowen Tynanes Pro MMA Record: 13-2-0 Date of Birth: October 21, 1990 "
            "Country: United States Height: 5'10\" (177cm) Reach: N/A Last Weigh-In: 155.4 lbs "
            "Weight Class: Lightweight Affiliation: Hawai'i Elite MMA"
        ),
        "lines": [
            "Lowen Tynanes",
            "Pro MMA Record: 13-2-0",
            "Date of Birth: October 21, 1990",
            "Country: United States",
            "Height: 5'10\" (177cm)",
            "Reach: N/A",
            "Last Weigh-In: 155.4 lbs",
            "Weight Class: Lightweight",
            "Affiliation: Hawai'i Elite MMA",
        ],
        "rows": [
            {
                "raw_text": "W DEC Yuji Ephoeviga 13-3-0 Decision · Unanimous R3 5:00 Shooto June 01, 2026",
                "cells": ["June 01, 2026", "Shooto", "Yuji Ephoeviga", "W", "Decision", "R3", "5:00"],
                "links": [
                    {"text": "Shooto", "href": "https://www.tapology.com/fightcenter/events/141000-shooto"},
                    {"text": "Yuji Ephoeviga", "href": "https://www.tapology.com/fightcenter/fighters/123-yuji-ephoeviga"},
                ],
            }
        ],
        "images": [],
    }

    parsed = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/34077-lowen-tynanes")
    profile = normalize_profile_for_event_output(
        parsed,
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
    )

    assert profile["name"] == "Lowen Tynanes"
    assert "/fighters/34077-lowen-tynanes" in profile["profile_url"]
    assert profile["country"] == "United States"
    assert profile["nationality"] == {
        "raw": "United States",
        "country_name": "United States",
        "country_code": "USA",
        "source": "profile_summary_country_field",
        "is_missing": False,
    }
    assert profile["pro_mma_record_raw"] == "13-2-0"
    assert profile["pro_mma_record"] == {"raw": "13-2-0", "wins": 13, "losses": 2, "draws": 0, "nc": 0}
    assert profile["date_of_birth"] == "1990-10-21"
    assert isinstance(profile["age_years_at_scrape"], int)
    assert profile["age_at_event"] == 35
    assert profile["height_cm"] == 177
    assert profile["reach_cm"] is None
    assert profile["weight_class"] == "Lightweight"
    assert profile["last_weigh_in_lbs"] == 155.4
    assert profile["affiliation"] == "Hawai'i Elite MMA"
    assert profile["last_fight_date"] == "2026-06-01"
    assert profile["last_fight_promotion"] == "Shooto"


def test_event_profile_output_yuji_ephoeviga_style_profile():
    snapshot = {
        "title": "Yuji Ephoeviga | Tapology",
        "headings": ["Yuji Ephoeviga"],
        "body_text": (
            "Yuji Ephoeviga Pro MMA Record: 13-3-0 Nickname: The Chef Date of Birth: October 19, 1999 "
            "Born Location: Nerima, Tokyo, Japan Fighting out of: Tokyo, Japan Height: 5'9\" (175cm) "
            "Reach: 75.5\" (192cm) Last Weigh-In: 155.0 lbs Weight Class: Lightweight Affiliation: TRIBE TOKYO MMA"
        ),
        "lines": [
            "Yuji Ephoeviga",
            "Pro MMA Record: 13-3-0",
            "Nickname: The Chef",
            "Date of Birth: October 19, 1999",
            "Born Location: Nerima, Tokyo, Japan",
            "Fighting out of: Tokyo, Japan",
            "Height: 5'9\" (175cm)",
            "Reach: 75.5\" (192cm)",
            "Last Weigh-In: 155.0 lbs",
            "Weight Class: Lightweight",
            "Affiliation: TRIBE TOKYO MMA",
        ],
        "rows": [],
        "images": [],
    }

    parsed = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/123-yuji-ephoeviga")
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["name"] == "Yuji Ephoeviga"
    assert profile["nickname"] == "The Chef"
    assert profile["pro_mma_record_raw"] == "13-3-0"
    assert profile["pro_mma_record"]["raw"] == "13-3-0"
    assert profile["date_of_birth"] == "1999-10-19"
    assert profile["height_cm"] == 175
    assert profile["reach_cm"] == 192
    assert profile["weight_class"] == "Lightweight"
    assert profile["last_weigh_in_lbs"] == 155.0
    assert profile["affiliation"] == "TRIBE TOKYO MMA"
    assert "Tokyo, Japan" in profile["fighting_out_of_raw"]
    assert "Nerima, Tokyo, Japan" in profile["born_raw"]
    assert profile["born_city"] == "Nerima"
    assert profile["born_region"] == "Tokyo"
    assert profile["born_country"] == "Japan"
    assert profile["fighting_out_of_city"] == "Tokyo"
    assert profile["fighting_out_of_region"] is None
    assert profile["fighting_out_of_country"] == "Japan"


def test_event_profile_location_parser_keeps_country_only_values_out_of_city():
    parsed = parse_profile_snapshot(
        {
            "title": "Country Only | Tapology",
            "headings": ["Country Only"],
            "body_text": (
                "Country Only Pro MMA Record: 1-0-0 Born Location: United States "
                "Fighting out of: Japan"
            ),
            "lines": [
                "Country Only",
                "Pro MMA Record: 1-0-0",
                "Born Location: United States",
                "Fighting out of: Japan",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/321-country-only",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_city"] is None
    assert profile["born_region"] is None
    assert profile["born_country"] == "United States"
    assert profile["fighting_out_of_city"] is None
    assert profile["fighting_out_of_region"] is None
    assert profile["fighting_out_of_country"] == "Japan"


def test_event_profile_location_parser_handles_central_american_country_values():
    parsed = parse_profile_snapshot(
        {
            "title": "Regional Country Fighter | Tapology",
            "headings": ["Regional Country Fighter"],
            "body_text": (
                "Regional Country Fighter Pro MMA Record: 1-0-0 "
                "Born Location: Honduras Fighting out of: Tegucigalpa, Honduras"
            ),
            "lines": [
                "Regional Country Fighter",
                "Pro MMA Record: 1-0-0",
                "Born Location: Honduras",
                "Fighting out of: Tegucigalpa, Honduras",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/324-regional-country-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_raw"] == "Honduras"
    assert profile["born_country"] == "Honduras"
    assert profile["born_city"] is None
    assert profile["fighting_out_of_raw"] == "Tegucigalpa, Honduras"
    assert profile["fighting_out_of_city"] == "Tegucigalpa"
    assert profile["fighting_out_of_country"] == "Honduras"


def test_event_profile_location_parser_handles_panama_and_cuba_country_only_values():
    panama = parse_profile_snapshot(
        {
            "title": "Panama Fighter | Tapology",
            "headings": ["Panama Fighter"],
            "body_text": "Panama Fighter Pro MMA Record: 1-0-0 Fighting out of: Panama",
            "lines": ["Panama Fighter", "Pro MMA Record: 1-0-0", "Fighting out of: Panama"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/325-panama-fighter",
    )
    cuba = parse_profile_snapshot(
        {
            "title": "Cuba Fighter | Tapology",
            "headings": ["Cuba Fighter"],
            "body_text": "Cuba Fighter Pro MMA Record: 1-0-0 Born Location: Cuba Fighting out of: Cuba",
            "lines": ["Cuba Fighter", "Pro MMA Record: 1-0-0", "Born Location: Cuba", "Fighting out of: Cuba"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/326-cuba-fighter",
    )

    panama_profile = normalize_profile_for_event_output(panama, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")
    cuba_profile = normalize_profile_for_event_output(cuba, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert panama_profile["fighting_out_of_country"] == "Panama"
    assert panama_profile["fighting_out_of_city"] is None
    assert cuba_profile["born_country"] == "Cuba"
    assert cuba_profile["fighting_out_of_country"] == "Cuba"


def test_event_profile_location_parser_handles_extended_country_allowlist_values():
    parsed = parse_profile_snapshot(
        {
            "title": "South African Fighter | Tapology",
            "headings": ["South African Fighter"],
            "body_text": (
                "South African Fighter Pro MMA Record: 1-0-0 "
                "Born Location: Baku, Azerbaijan "
                "Fighting out of: Johannesburg, Gauteng, South Africa"
            ),
            "lines": [
                "South African Fighter",
                "Pro MMA Record: 1-0-0",
                "Born Location: Baku, Azerbaijan",
                "Fighting out of: Johannesburg, Gauteng, South Africa",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/327-south-african-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_city"] == "Baku"
    assert profile["born_country"] == "Azerbaijan"
    assert profile["fighting_out_of_raw"] == "Johannesburg, Gauteng, South Africa"
    assert profile["fighting_out_of_city"] == "Johannesburg"
    assert profile["fighting_out_of_region"] == "Gauteng"
    assert profile["fighting_out_of_country"] == "South Africa"


def test_event_profile_location_parser_does_not_infer_country_from_region():
    parsed = parse_profile_snapshot(
        {
            "title": "Regional Fighter | Tapology",
            "headings": ["Regional Fighter"],
            "body_text": "Regional Fighter Pro MMA Record: 1-0-0 Born Location: Yoichi, Hokkaido",
            "lines": ["Regional Fighter", "Pro MMA Record: 1-0-0", "Born Location: Yoichi, Hokkaido"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/322-regional-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_city"] == "Yoichi"
    assert profile["born_region"] == "Hokkaido"
    assert profile["born_country"] is None


def test_event_profile_nationality_from_header_flag_title():
    parsed = parse_profile_snapshot(
        {
            "title": "Brazilian Fighter | Tapology",
            "headings": ["Brazilian Fighter"],
            "body_text": "Brazilian Fighter Pro MMA Record: 1-0-0",
            "lines": ["Brazilian Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "span", "class_name": "flag br", "title": "Brazil"}],
        },
        "https://www.tapology.com/fightcenter/fighters/333-brazilian-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "Brazil",
        "country_name": "Brazil",
        "country_code": "BRA",
        "source": "profile_header_flag",
        "is_missing": False,
    }
    assert profile["country"] == "Brazil"


def test_event_profile_nationality_from_header_flag_alt():
    parsed = parse_profile_snapshot(
        {
            "title": "Japanese Fighter | Tapology",
            "headings": ["Japanese Fighter"],
            "body_text": "Japanese Fighter Pro MMA Record: 1-0-0",
            "lines": ["Japanese Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "country-flag", "alt": "Japan"}],
        },
        "https://www.tapology.com/fightcenter/fighters/334-japanese-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "Japan",
        "country_name": "Japan",
        "country_code": "JPN",
        "source": "profile_header_flag",
        "is_missing": False,
    }
    assert "missing_or_unavailable:country" not in parsed["warnings"]
    assert "missing_or_unavailable:nationality" not in parsed["warnings"]


def test_event_profile_nationality_from_header_flag_tooltip_attribute():
    parsed = parse_profile_snapshot(
        {
            "title": "American Fighter | Tapology",
            "headings": ["American Fighter"],
            "body_text": "American Fighter Pro MMA Record: 1-0-0",
            "lines": ["American Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "span", "class_name": "flag-icon", "data_original_title": "United States"}],
        },
        "https://www.tapology.com/fightcenter/fighters/335-american-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "United States",
        "country_name": "United States",
        "country_code": "USA",
        "source": "profile_header_flag",
        "is_missing": False,
    }
    assert "missing_or_unavailable:country" not in parsed["warnings"]
    assert "missing_or_unavailable:nationality" not in parsed["warnings"]


def test_event_profile_nationality_from_lowercase_united_states_flag_alt():
    parsed = parse_profile_snapshot(
        {
            "title": "American Fighter | Tapology",
            "headings": ["American Fighter"],
            "body_text": "American Fighter Pro MMA Record: 1-0-0",
            "lines": ["American Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "country-flag", "alt": "united states"}],
        },
        "https://www.tapology.com/fightcenter/fighters/337-american-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "united states",
        "country_name": "United States",
        "country_code": "USA",
        "source": "profile_header_flag",
        "is_missing": False,
    }


def test_event_profile_nationality_from_country_href_iso2_code():
    parsed = parse_profile_snapshot(
        {
            "title": "Moroccan Fighter | Tapology",
            "headings": ["Moroccan Fighter"],
            "body_text": "Moroccan Fighter Pro MMA Record: 1-0-0",
            "lines": ["Moroccan Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [
                {
                    "tag": "a",
                    "class_name": "profile country-flag",
                    "href": "https://www.tapology.com/search/mma-fighters-by-nationality/country-ma",
                }
            ],
        },
        "https://www.tapology.com/fightcenter/fighters/338-moroccan-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "MA",
        "country_name": "Morocco",
        "country_code": "MAR",
        "source": "profile_header_flag",
        "is_missing": False,
    }
    assert profile["country"] == "Morocco"


def test_event_profile_nationality_from_hashed_flag_asset_iso2_code():
    parsed = parse_profile_snapshot(
        {
            "title": "Moroccan Fighter | Tapology",
            "headings": ["Moroccan Fighter"],
            "body_text": "Moroccan Fighter Pro MMA Record: 1-0-0",
            "lines": ["Moroccan Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [
                {
                    "tag": "img",
                    "class_name": "profile country-flag",
                    "src": "/assets/flags/MA-b4fe93a8fff956dfe6664e3427e26e5679d5a0d060766219f419ad60eadfc5fa.gif",
                }
            ],
        },
        "https://www.tapology.com/fightcenter/fighters/339-moroccan-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "MA",
        "country_name": "Morocco",
        "country_code": "MAR",
        "source": "profile_header_flag",
        "is_missing": False,
    }
    assert profile["country"] == "Morocco"


def test_event_profile_nationality_accepts_required_header_flag_values():
    examples = [
        ("United States", "United States", "USA"),
        ("USA", "United States", "USA"),
        ("Japan", "Japan", "JPN"),
        ("Brazil", "Brazil", "BRA"),
        ("Russia", "Russia", "RUS"),
        ("Republic of Korea", "South Korea", "KOR"),
        ("South Korea", "South Korea", "KOR"),
        ("Taiwan", "Taiwan", "TWN"),
        ("England", "England", "ENG"),
        ("Scotland", "Scotland", "SCO"),
        ("Wales", "Wales", "WAL"),
        ("Northern Ireland", "Northern Ireland", "NIR"),
        ("Guam", "Guam", "GUM"),
        ("South Africa", "South Africa", "ZAF"),
    ]

    for raw, country_name, country_code in examples:
        parsed = parse_profile_snapshot(
            {
                "title": f"{raw} Fighter | Tapology",
                "headings": [f"{raw} Fighter"],
                "body_text": f"{raw} Fighter Pro MMA Record: 1-0-0",
                "lines": [f"{raw} Fighter", "Pro MMA Record: 1-0-0"],
                "rows": [],
                "images": [],
                "flag_candidates": [{"tag": "img", "class_name": "profile country-flag", "alt": raw}],
            },
            "https://www.tapology.com/fightcenter/fighters/900-country-fighter",
        )
        profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

        assert profile["nationality"] == {
            "raw": raw,
            "country_name": country_name,
            "country_code": country_code,
            "source": "profile_header_flag",
            "is_missing": False,
        }
        assert "missing_or_unavailable:nationality" not in parsed["warnings"]


def test_event_profile_rejects_dirty_low_confidence_nationality_candidates():
    dirty_values = [
        "MF",
        "CC",
        "PF",
        "SC",
        "LC",
        "GFC",
        "Shooto",
        "DEEP",
        "TNX",
        "IMMAF",
        "Team Bonds",
        "Andy Bravo",
        "Fabián Castillo",
        "Mauricio Blandin",
        "Arkansas Machine Fitness & MMA",
    ]

    for raw in dirty_values:
        parsed = parse_profile_snapshot(
            {
                "title": f"{raw} | Tapology",
                "headings": [raw],
                "body_text": f"{raw} Pro MMA Record: 1-0-0",
                "lines": [raw, "Pro MMA Record: 1-0-0"],
                "rows": [],
                "images": [],
                "flag_candidates": [{"tag": "a", "text": raw, "class_name": "fighter-link"}],
            },
            "https://www.tapology.com/fightcenter/fighters/901-dirty-candidate",
        )
        profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

        assert profile["nationality"] == {
            "raw": None,
            "country_name": None,
            "country_code": None,
            "source": "profile_header_flag",
            "is_missing": True,
        }
        assert profile["country"] is None
        assert "missing_or_unavailable:nationality" in parsed["warnings"]
        assert any(warning.startswith("nationality_candidate_rejected:") for warning in parsed["warnings"])


def test_event_profile_rejects_promotion_like_header_flag_metadata_when_unmatched():
    parsed = parse_profile_snapshot(
        {
            "title": "Noisy Flag Fighter | Tapology",
            "headings": ["Noisy Flag Fighter"],
            "body_text": "Noisy Flag Fighter Pro MMA Record: 1-0-0",
            "lines": ["Noisy Flag Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "profile country-flag", "title": "IMMAF"}],
        },
        "https://www.tapology.com/fightcenter/fighters/904-noisy-flag-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")
    summary = build_event_profile_enrichment_summary([{"fighter_profiles": {"tapology_fighter_904": profile}}])

    assert profile["nationality"] == {
        "raw": None,
        "country_name": None,
        "country_code": None,
        "source": "profile_header_flag",
        "is_missing": True,
    }
    assert "nationality_candidate_rejected:promotion_name_like" in parsed["warnings"]
    assert summary["unmatched_nationality_raw_values"] == []
    assert summary["rejected_nationality_candidate_reasons"]["promotion_name_like"] == 1


def test_event_profile_keeps_unmatched_country_like_header_flag_metadata_raw():
    parsed = parse_profile_snapshot(
        {
            "title": "Unknown Country Fighter | Tapology",
            "headings": ["Unknown Country Fighter"],
            "body_text": "Unknown Country Fighter Pro MMA Record: 1-0-0",
            "lines": ["Unknown Country Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "profile country-flag", "title": "Atlantis"}],
        },
        "https://www.tapology.com/fightcenter/fighters/905-unknown-country-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "Atlantis",
        "country_name": None,
        "country_code": None,
        "source": "profile_header_flag",
        "is_missing": True,
    }


def test_event_profile_nationality_falls_back_to_profile_country_field():
    parsed = parse_profile_snapshot(
        {
            "title": "Summary Fallback Fighter | Tapology",
            "headings": ["Summary Fallback Fighter"],
            "body_text": "Summary Fallback Fighter Pro MMA Record: 1-0-0 Country: Brazil",
            "lines": ["Summary Fallback Fighter", "Pro MMA Record: 1-0-0", "Country: Brazil"],
            "rows": [],
            "images": [],
            "flag_candidates": [],
        },
        "https://www.tapology.com/fightcenter/fighters/906-summary-fallback-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "Brazil",
        "country_name": "Brazil",
        "country_code": "BRA",
        "source": "profile_summary_country_field",
        "is_missing": False,
    }
    assert profile["country"] == "Brazil"


def test_event_profile_country_field_keeps_unmatched_raw_for_later_mapping():
    parsed = parse_profile_snapshot(
        {
            "title": "Raw Country Field Fighter | Tapology",
            "headings": ["Raw Country Field Fighter"],
            "body_text": "Raw Country Field Fighter Pro MMA Record: 1-0-0 Nationality: Atlantis",
            "lines": ["Raw Country Field Fighter", "Pro MMA Record: 1-0-0", "Nationality: Atlantis"],
            "rows": [],
            "images": [],
            "flag_candidates": [],
        },
        "https://www.tapology.com/fightcenter/fighters/907-raw-country-field-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": "Atlantis",
        "country_name": None,
        "country_code": None,
        "source": "profile_summary_country_field",
        "is_missing": True,
    }
    assert profile["country"] is None


def test_event_profile_rejects_two_letter_country_code_from_src_only_context():
    parsed = parse_profile_snapshot(
        {
            "title": "Costa Rica Fighter | Tapology",
            "headings": ["Costa Rica Fighter"],
            "body_text": "Costa Rica Fighter Pro MMA Record: 1-0-0 Born Location: San Jose, Costa Rica",
            "lines": ["Costa Rica Fighter", "Pro MMA Record: 1-0-0", "Born Location: San Jose, Costa Rica"],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "profile-photo", "src": "/assets/mf.png"}],
        },
        "https://www.tapology.com/fightcenter/fighters/902-costa-rica-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_country"] == "Costa Rica"
    assert profile["nationality"]["raw"] is None
    assert profile["nationality"]["country_name"] is None
    assert profile["country"] is None
    assert "nationality_candidate_rejected:low_confidence_two_letter_token" in parsed["warnings"]


def test_event_profile_nationality_rejection_diagnostics_are_compact_and_counted():
    parsed = parse_profile_snapshot(
        {
            "title": "Andy Bravo | Tapology",
            "headings": ["Andy Bravo"],
            "body_text": "Andy Bravo Pro MMA Record: 1-0-0 Fighting out of: Costa Rica",
            "lines": ["Andy Bravo", "Pro MMA Record: 1-0-0", "Fighting out of: Costa Rica"],
            "rows": [],
            "images": [],
            "flag_candidates": [
                {"tag": "a", "text": "Andy Bravo", "class_name": "fighter-link"},
                {"tag": "span", "text": "Team Bonds", "class_name": "team"},
                {"tag": "img", "src": "/assets/flags/MF.png", "class_name": "flag"},
            ],
        },
        "https://www.tapology.com/fightcenter/fighters/903-andy-bravo",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")
    summary = build_event_profile_enrichment_summary([{"fighter_profiles": {"tapology_fighter_903": profile}}])

    assert profile["nationality"]["is_missing"] is True
    assert profile["fighting_out_of_country"] == "Costa Rica"
    assert summary["rejected_nationality_candidate_counts"] == 3
    assert summary["rejected_nationality_candidate_reasons"]["fighter_name_like"] == 1
    assert summary["rejected_nationality_candidate_reasons"]["team_or_affiliation_like"] == 1
    assert summary["rejected_nationality_candidate_reasons"]["low_confidence_two_letter_token"] == 1
    assert {item["raw"] for item in summary["rejected_nationality_candidates"]} == {"Andy Bravo", "Team Bonds", "MF"}
    assert summary["unmatched_nationality_raw_values"] == []


def test_event_profile_nationality_missing_without_flag_candidate():
    parsed = parse_profile_snapshot(
        {
            "title": "No Flag Fighter | Tapology",
            "headings": ["No Flag Fighter"],
            "body_text": "No Flag Fighter Pro MMA Record: 1-0-0",
            "lines": ["No Flag Fighter", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/336-no-flag-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["nationality"] == {
        "raw": None,
        "country_name": None,
        "country_code": None,
        "source": "profile_header_flag",
        "is_missing": True,
    }
    assert "missing_or_unavailable:nationality" in parsed["warnings"]
    assert "missing_or_unavailable:country" not in parsed["warnings"]


def test_event_profile_nationality_not_inferred_from_born_or_fighting_out_country():
    parsed = parse_profile_snapshot(
        {
            "title": "Location Only Fighter | Tapology",
            "headings": ["Location Only Fighter"],
            "body_text": (
                "Location Only Fighter Pro MMA Record: 1-0-0 "
                "Born Location: Tokyo, Japan Fighting out of: Las Vegas, Nevada, United States"
            ),
            "lines": [
                "Location Only Fighter",
                "Pro MMA Record: 1-0-0",
                "Born Location: Tokyo, Japan",
                "Fighting out of: Las Vegas, Nevada, United States",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/338-location-only-fighter",
    )

    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["born_country"] == "Japan"
    assert profile["fighting_out_of_country"] == "United States"
    assert profile["nationality"]["is_missing"] is True
    assert profile["country"] is None
    assert "missing_or_unavailable:nationality" in parsed["warnings"]
    assert "missing_or_unavailable:country" not in parsed["warnings"]


def test_event_profile_current_record_does_not_replace_bout_prefight_record():
    parsed = parse_profile_snapshot(
        {
            "title": "Lowen Tynanes | Tapology",
            "headings": ["Lowen Tynanes"],
            "body_text": "Lowen Tynanes Pro MMA Record: 13-2-0",
            "lines": ["Lowen Tynanes", "Pro MMA Record: 13-2-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/34077-lowen-tynanes",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")
    bout_fighter = {"prefight_record": {"raw": "12-2-0"}}

    assert bout_fighter["prefight_record"]["raw"] == "12-2-0"
    assert profile["pro_mma_record"]["raw"] == "13-2-0"


def test_last_fight_inconsistent_row_nulls_event_output_without_failed_enrichment():
    snapshot = {
        "title": "Mixed Row | Tapology",
        "headings": ["Mixed Row"],
        "body_text": "Mixed Row Pro MMA Record: 2-0-0",
        "lines": ["Mixed Row", "Pro MMA Record: 2-0-0"],
        "rows": [
            {
                "raw_text": (
                    "W DEC First Opponent Decision Unanimous R3 5:00 June 01, 2026 "
                    "add_circle L SUB Second Opponent Other FC 9 March 01, 2026"
                ),
                "cells": [],
                "links": [
                    {"text": "Other FC 9", "href": "https://www.tapology.com/fightcenter/events/999-other-fc-9"},
                    {"text": "First Opponent", "href": "https://www.tapology.com/fightcenter/fighters/333-first-opponent"},
                ],
            }
        ],
        "images": [],
    }

    parsed = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/323-mixed-row")
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["status"] == "success"
    assert parsed["fight_history"][0]["fight_date"] == "2026-06-01"
    assert "last_fight_parse_inconsistent" in parsed["fight_history"][0]["warnings"]
    assert profile["profile_fetched"] is True
    assert "last_fight_parse_inconsistent" in profile["profile_warnings"]
    assert profile["last_fight_raw"] is None
    assert profile["last_fight_date"] is None
    assert profile["last_fight_promotion"] is None


def test_fighting_out_of_leakage_is_nulled_when_ui_chrome_bleeds_into_field():
    parsed = parse_profile_snapshot(
        {
            "title": "Hide Kato | Tapology",
            "headings": ["Hide Kato"],
            "body_text": (
                "Hide Kato Pro MMA Record: 1-0-0 "
                "Fighting out of: Japan HIDE KATO COMBAT SPORTS RECORD VIEW MODE Basic Odds "
                "casino Expand All Details add_circle chevron_left Display Options "
                "Affiliation: Honest Gym"
            ),
            "lines": [
                "Hide Kato",
                "Pro MMA Record: 1-0-0",
                "Fighting out of: Japan HIDE KATO COMBAT SPORTS RECORD VIEW MODE Basic Odds casino Expand All Details add_circle chevron_left Display Options",
                "Affiliation: Honest Gym",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/500-hide-kato",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["profile_summary"]["fighting_out_of"] is None
    assert "fighting_out_parse_inconsistent" in parsed["warnings"]
    assert profile["fighting_out_of_raw"] is None
    assert "fighting_out_parse_inconsistent" in profile["profile_warnings"]
    assert profile["affiliation"] == "Honest Gym"


def test_fighting_out_of_leakage_is_nulled_when_affiliation_text_is_merged():
    parsed = parse_profile_snapshot(
        {
            "title": "Arkansas Fighter | Tapology",
            "headings": ["Arkansas Fighter"],
            "body_text": (
                "Arkansas Fighter Pro MMA Record: 1-0-0 "
                "Fighting out of: Arkansas Machine Fitness & MMA "
                "Affiliation: Machine Fitness & MMA"
            ),
            "lines": [
                "Arkansas Fighter",
                "Pro MMA Record: 1-0-0",
                "Fighting out of: Arkansas Machine Fitness & MMA",
                "Affiliation: Machine Fitness & MMA",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/501-arkansas-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["profile_summary"]["fighting_out_of"] is None
    assert "fighting_out_parse_inconsistent" in parsed["warnings"]
    assert profile["fighting_out_of_raw"] is None
    assert profile["affiliation"] == "Machine Fitness & MMA"


def test_profile_association_page_chrome_is_nulled():
    parsed = parse_profile_snapshot(
        {
            "title": "Chrome Fighter | Tapology",
            "headings": ["Chrome Fighter"],
            "body_text": (
                "Chrome Fighter Pro MMA Record: 1-0-0 "
                "Affiliation: Joe Rogan on call at the White House"
            ),
            "lines": [
                "Chrome Fighter",
                "Pro MMA Record: 1-0-0",
                "Affiliation: Joe Rogan on call at the White House",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/510-chrome-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["profile_summary"]["association"] is None
    assert profile["affiliation"] is None
    assert "association_parse_inconsistent" in profile["profile_warnings"]


def test_last_weigh_in_leakage_is_nulled_when_photos_text_is_captured():
    parsed = parse_profile_snapshot(
        {
            "title": "Photo Leakage | Tapology",
            "headings": ["Photo Leakage"],
            "body_text": "Photo Leakage Pro MMA Record: 1-0-0 Last Weigh-In: PHOTOS : weigh-in gallery",
            "lines": [
                "Photo Leakage",
                "Pro MMA Record: 1-0-0",
                "Last Weigh-In: PHOTOS : weigh-in gallery",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/502-photo-leakage",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["profile_summary"]["last_weigh_in"] is None
    assert parsed["profile_summary"]["last_weigh_in_lbs"] is None
    assert "last_weigh_in_parse_inconsistent" in parsed["warnings"]
    assert profile["last_weigh_in_raw"] is None
    assert profile["last_weigh_in_lbs"] is None


def test_last_weigh_in_leakage_is_nulled_when_value_is_implausible():
    parsed = parse_profile_snapshot(
        {
            "title": "Implausible Weigh-In | Tapology",
            "headings": ["Implausible Weigh-In"],
            "body_text": "Implausible Weigh-In Pro MMA Record: 1-0-0 Last Weigh-In: 6.6 lbs",
            "lines": [
                "Implausible Weigh-In",
                "Pro MMA Record: 1-0-0",
                "Last Weigh-In: 6.6 lbs",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/503-implausible-weigh-in",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["profile_summary"]["last_weigh_in"] is None
    assert parsed["profile_summary"]["last_weigh_in_lbs"] is None
    assert "last_weigh_in_parse_inconsistent" in parsed["warnings"]
    assert profile["last_weigh_in_raw"] is None
    assert profile["last_weigh_in_lbs"] is None


def test_profile_image_is_not_emitted_even_when_tapology_meta_image_is_present():
    parsed = parse_profile_snapshot(
        {
            "title": "Real Image | Tapology",
            "headings": ["Real Image"],
            "meta_image_url": "https://images.tapology.com/letterbox_images/34077/default/Lowen-Tynanes.jpeg",
            "body_text": "Real Image Pro MMA Record: 1-0-0",
            "lines": ["Real Image", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/504-real-image",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["status"] == "success"
    assert profile["profile_image_url"] is None


def test_profile_image_rejects_tapology_facebook_fist_placeholder_without_failed_parse():
    parsed = parse_profile_snapshot(
        {
            "title": "Placeholder Image | Tapology",
            "headings": ["Placeholder Image"],
            "meta_image_url": (
                "https://www.tapology.com/assets/logo/"
                "Facebook_Fist-5561b6f3371aa46398edfc19905ddb2961d8c74863b31afcf574d9a3415b929e.png"
            ),
            "body_text": "Placeholder Image Pro MMA Record: 1-0-0",
            "lines": ["Placeholder Image", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/505-placeholder-image",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert parsed["status"] == "success"
    assert profile["profile_fetched"] is True
    assert profile["profile_image_url"] is None


def test_profile_image_rejects_region_logo_placeholder():
    parsed = parse_profile_snapshot(
        {
            "title": "Region Logo | Tapology",
            "headings": ["Region Logo"],
            "meta_image_url": "https://images.tapology.com/regions/logos/1/default/japan.png",
            "body_text": "Region Logo Pro MMA Record: 1-0-0",
            "lines": ["Region Logo", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/506-region-logo",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["profile_image_url"] is None


def test_profile_image_rejects_gym_logo_placeholder():
    parsed = parse_profile_snapshot(
        {
            "title": "Gym Logo | Tapology",
            "headings": ["Gym Logo"],
            "meta_image_url": "https://images.tapology.com/gyms/logos/1/default/team.png",
            "body_text": "Gym Logo Pro MMA Record: 1-0-0",
            "lines": ["Gym Logo", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/507-gym-logo",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["profile_image_url"] is None


def test_profile_image_is_not_emitted_from_image_list():
    parsed = parse_profile_snapshot(
        {
            "title": "Letterbox Image | Tapology",
            "headings": ["Letterbox Image"],
            "body_text": "Letterbox Image Pro MMA Record: 1-0-0",
            "lines": ["Letterbox Image", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [
                {
                    "src": "https://images.tapology.com/letterbox_images/34078/default/Real-Fighter.jpg",
                    "alt": "Letterbox Image fighter photo",
                    "class_name": "fighter-profile",
                    "width": 300,
                }
            ],
        },
        "https://www.tapology.com/fightcenter/fighters/508-letterbox-image",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["profile_image_url"] is None


def test_profile_image_is_not_emitted_from_headshot_image_list():
    parsed = parse_profile_snapshot(
        {
            "title": "Headshot Image | Tapology",
            "headings": ["Headshot Image"],
            "body_text": "Headshot Image Pro MMA Record: 1-0-0",
            "lines": ["Headshot Image", "Pro MMA Record: 1-0-0"],
            "rows": [],
            "images": [
                {
                    "src": "https://images.tapology.com/headshot_images/34079/default/Real-Fighter.jpg",
                    "alt": "Headshot Image profile",
                    "class_name": "profile-photo",
                    "width": 180,
                }
            ],
        },
        "https://www.tapology.com/fightcenter/fighters/509-headshot-image",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["profile_image_url"] is None


def test_failed_event_profile_output_is_minimal_and_summarizable():
    profile = normalize_profile_for_event_output(
        failed_profile("https://www.tapology.com/fightcenter/fighters/34077-lowen-tynanes", "failed_parse", "profile_fetch_failed"),
        fallback_name="Lowen Tynanes",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
    )

    assert profile["profile_fetched"] is False
    assert profile["profile_fetched_at"] is None
    assert profile["name"] == "Lowen Tynanes"
    assert "profile_fetch_failed" in profile["profile_warnings"]
    assert event_profile_summary(profile)["height_cm"] is None


def test_failed_event_profile_output_keeps_specific_fetch_reason():
    profile = normalize_profile_for_event_output(
        failed_profile(
            "https://www.tapology.com/fightcenter/fighters/34077-lowen-tynanes",
            "timeout",
            "profile_fetch_failed:timeout",
            diagnostics={"attempt": 2},
        ),
        fallback_name="Lowen Tynanes",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
    )

    assert profile["profile_fetched"] is False
    assert "profile_fetch_failed:timeout" in profile["profile_warnings"]
    assert "profile_fetch_failed" not in profile["profile_warnings"]
    assert profile["nationality"]["is_missing"] is True


def test_event_profile_enrichment_summary_marks_all_failed_profiles_failed():
    failed = normalize_profile_for_event_output(
        failed_profile(
            "https://www.tapology.com/fightcenter/fighters/1-one",
            "blocked",
            "profile_fetch_failed:blocked_or_challenge",
        ),
        fallback_name="One",
        event_date="2026-06-01",
        fetched_at="2026-06-02T00:00:00+00:00",
    )
    event = {"fighter_profiles": {"tapology_fighter_1": failed}}

    summary = build_event_profile_enrichment_summary([event])

    assert summary["profile_enrichment_status"] == "failed"
    assert summary["profile_fetch_attempts"] == 1
    assert summary["profile_fetch_successes"] == 0
    assert summary["profile_fetch_failures"] == 1
    assert summary["profile_fetch_failure_reasons"] == {"profile_fetch_failed:blocked_or_challenge": 1}
    assert summary["profile_fields_coverage"]["date_of_birth"]["present"] == 0
    assert summary["profile_fields_coverage"]["date_of_birth"]["missing"] == 1


def test_event_profile_enrichment_summary_counts_present_fields():
    parsed = parse_profile_snapshot(
        {
            "title": "Complete Fighter | Tapology",
            "headings": ["Complete Fighter"],
            "body_text": (
                "Complete Fighter Pro MMA Record: 3-0-0 Date of Birth: January 1, 2000 "
                "Born Location: Tokyo, Japan Fighting out of: Tokyo, Japan Height: 5'9\" (175cm) "
                "Reach: 70\" (178cm) Last Weigh-In: 155.0 lbs Weight Class: Lightweight Affiliation: Test MMA"
            ),
            "lines": [
                "Complete Fighter",
                "Pro MMA Record: 3-0-0",
                "Date of Birth: January 1, 2000",
                "Born Location: Tokyo, Japan",
                "Fighting out of: Tokyo, Japan",
                "Height: 5'9\" (175cm)",
                "Reach: 70\" (178cm)",
                "Last Weigh-In: 155.0 lbs",
                "Weight Class: Lightweight",
                "Affiliation: Test MMA",
            ],
            "rows": [],
            "images": [],
            "flag_candidates": [{"tag": "img", "class_name": "country-flag", "alt": "Japan"}],
        },
        "https://www.tapology.com/fightcenter/fighters/2-complete-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    summary = build_event_profile_enrichment_summary([{"fighter_profiles": {"tapology_fighter_2": profile}}])

    assert summary["profile_enrichment_status"] == "ok"
    assert summary["profile_fields_coverage"]["nationality"]["present"] == 1
    assert summary["profile_fields_coverage"]["country"]["present"] == 1
    assert summary["profile_fields_coverage"]["height_cm"]["present"] == 1
    assert summary["profile_fields_coverage"]["pro_mma_record_raw"]["present"] == 1
    assert summary["profile_fields_coverage"]["fighting_out_of_raw"]["present"] == 1
    assert not any(item["warning"] == "missing_or_unavailable:country" for item in summary["top_profile_warning_codes"])


def test_missing_last_weigh_in_warning_uses_precise_name_and_keeps_legacy_alias():
    parsed = parse_profile_snapshot(
        {
            "title": "No Weigh-In | Tapology",
            "headings": ["No Weigh-In"],
            "body_text": "No Weigh-In Pro MMA Record: 1-0-0 Weight Class: Flyweight",
            "lines": [
                "No Weigh-In",
                "Pro MMA Record: 1-0-0",
                "Weight Class: Flyweight",
            ],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/504-no-weigh-in",
    )

    assert "missing_or_unavailable:last_weigh_in_lbs" in parsed["warnings"]
    assert "missing_or_unavailable:weight" in parsed["warnings"]


def test_event_profile_enrichment_summary_includes_profile_context_breakdown():
    profile = {
        "profile_fetched": True,
        "profile_warnings": [],
        "nationality": {"is_missing": True},
    }
    summary = build_event_profile_enrichment_summary(
        [
            {
                "fighter_profiles": {"tapology_fighter_1": profile},
                "profile_enrichment": {
                    "profile_context_counts": {
                        "retained_bout_profiles": 1,
                        "skipped_mma_profiles": 2,
                        "skipped_non_mma_profiles": 3,
                    }
                },
            }
        ]
    )

    assert summary["profile_context_counts"] == {
        "retained_bout_profiles": 1,
        "skipped_mma_profiles": 2,
        "skipped_non_mma_profiles": 3,
    }


def test_parse_profile_snapshot_extracts_height_cm_from_bracketed_centimeters():
    snapshot = {
        "title": "Tall Fighter | Tapology",
        "headings": ["Tall Fighter"],
        "body_text": (
            "Tall Fighter Pro MMA Record: 3-0-0 Date of Birth: 1990 Jan 1 "
            "Height: 5'11\" (180cm) | Reach: 70\" (178cm) Weight Class: Lightweight"
        ),
        "lines": [
            "Tall Fighter",
            "Pro MMA Record: 3-0-0",
            "Date of Birth: 1990 Jan 1",
            "Height: 5'11\" (180cm) | Reach: 70\" (178cm)",
            "Weight Class: Lightweight",
        ],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/777-tall-fighter")

    assert profile["profile_summary"]["height"] == "5'11\" (180cm)"
    assert profile["profile_summary"]["height_cm"] == 180


def test_parse_profile_snapshot_extracts_height_cm_from_feet_inches_only():
    snapshot = {
        "title": "No Cm | Tapology",
        "headings": ["No Cm"],
        "body_text": "No Cm Pro MMA Record: 1-0-0 Height: 6'0\" Weight Class: Welterweight",
        "lines": [
            "No Cm",
            "Pro MMA Record: 1-0-0",
            "Height: 6'0\"",
            "Weight Class: Welterweight",
        ],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/888-no-cm")

    assert profile["profile_summary"]["height"] == "6'0\""
    # 6'0" = 72 inches = 182.88cm rounded to 183
    assert profile["profile_summary"]["height_cm"] == 183


def test_parse_profile_snapshot_keeps_height_cm_none_when_height_unavailable():
    snapshot = {
        "title": "Unknown Height | Tapology",
        "headings": ["Unknown Height"],
        "body_text": "Unknown Height Pro MMA Record: 1-0-0 Height: N/A | Reach: N/A",
        "lines": ["Unknown Height", "Pro MMA Record: 1-0-0", "Height: N/A | Reach: N/A"],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/999-unknown-height")

    assert profile["profile_summary"]["height"] is None
    assert profile["profile_summary"]["height_cm"] is None


def test_parse_profile_snapshot_does_not_guess_country_from_born_location():
    snapshot = {
        "title": "Local Fighter | Tapology",
        "headings": ["Local Fighter"],
        "body_text": (
            "Local Fighter Pro MMA Record: 2-0-0 "
            "Born: Tokyo, Japan Fighting out of: Japan "
            "Height: 5'8\" (173cm) Weight Class: Bantamweight"
        ),
        "lines": [
            "Local Fighter",
            "Pro MMA Record: 2-0-0",
            "Born: Tokyo, Japan",
            "Fighting out of: Japan",
            "Height: 5'8\" (173cm)",
            "Weight Class: Bantamweight",
        ],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/123-local-fighter")
    event_profile = normalize_profile_for_event_output(profile, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["profile_summary"]["born_location"] == "Tokyo, Japan"
    assert profile["profile_summary"]["fighting_out_of"] == "Japan"
    # country/nationality must remain None when no explicit Country/Nationality label is present
    assert profile["profile_summary"]["country"] is None
    assert event_profile["nationality"] == {
        "raw": None,
        "country_name": None,
        "country_code": None,
        "source": "profile_header_flag",
        "is_missing": True,
    }


def test_event_profile_nationality_not_inferred_from_fighting_out_of_raw():
    parsed = parse_profile_snapshot(
        {
            "title": "Fights Out Fighter | Tapology",
            "headings": ["Fights Out Fighter"],
            "body_text": "Fights Out Fighter Pro MMA Record: 1-0-0 Fighting out of: Brazil",
            "lines": ["Fights Out Fighter", "Pro MMA Record: 1-0-0", "Fighting out of: Brazil"],
            "rows": [],
            "images": [],
        },
        "https://www.tapology.com/fightcenter/fighters/337-fights-out-fighter",
    )
    profile = normalize_profile_for_event_output(parsed, event_date="2026-06-01", fetched_at="2026-06-02T00:00:00+00:00")

    assert profile["fighting_out_of_raw"] == "Brazil"
    assert profile["fighting_out_of_country"] == "Brazil"
    assert profile["nationality"]["is_missing"] is True
    assert profile["nationality"]["country_code"] is None


def test_parse_profile_snapshot_marks_failed_when_redirected_to_fightcenter_index():
    snapshot = {
        "url": "https://www.tapology.com/fightcenter",
        "title": "FightCenter | Tapology",
        "headings": ["FightCenter"],
        "body_text": "THE TAPOLOGY FightCenter Quick Filter Major Results PROMOTION All UFC",
        "lines": ["THE TAPOLOGY FightCenter Quick Filter"],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/286477-sayaka-hishinuma")

    assert profile["status"] == "failed_parse"
    assert profile["error_reason"] == "redirected_to_fightcenter_index"
    assert profile["display_name"] is None
    assert profile["fight_history"] == []
    assert profile["profile_summary"]["height_cm"] is None


def test_parse_profile_snapshot_extracts_multiple_history_rows_from_new_mma_fight_record_layout():
    body_text = (
        "Some Fighter Pro MMA Record: 27-2-1 Height: 5'1\" (155cm) "
        "Some Fighter MMA FIGHT RECORD VIEW MODE Basic Odds casino Expand All Details "
        "add_circle filter_list Cancelled: Shown view_list Sport Filters: MMA Grapple Custom "
        "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision Unanimous DEEP JEWELS 53 2026 May 24 add_circle "
        "C Pannie Kianzad Cancelled Bout 2026 Mar 21 add_circle "
        "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO 3:32 R1 DEEP JEWELS 47 2024 Nov 23 add_circle "
        "W SUB Namiko Kawabata 24-2-1 4-3 Front Choke 1:33 R3 DEEP JEWELS 45 2024 May 26"
    )
    snapshot = {
        "title": "Some Fighter | Tapology",
        "headings": ["Some Fighter"],
        "body_text": body_text,
        "lines": body_text.split(" "),
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/some-fighter")

    history = profile["fight_history"]
    assert len(history) >= 4, history
    # First fight should be the most recent
    assert history[0]["fight_date"] == "2026-05-24"
    assert history[0]["opponent_name"] == "Yuka Okutomi"
    assert history[0]["result"] == "win"
    # Cancelled fights are preserved with is_cancelled
    assert any(row.get("is_cancelled") for row in history)
    # Opponent prefight record column captured for a normal win row
    suzuki = next(row for row in history if row.get("opponent_name") == "Haruka Suzuki")
    assert suzuki["prefight_record_raw"] == "25-2-1"
    assert suzuki["opponent_record_raw"] == "2-2"


def test_parse_profile_snapshot_extracts_multiple_history_rows_from_combat_sports_layout():
    body_text = (
        "Mixed Veteran Pro MMA Record: 31-44-2 Height: 5'5\" (166cm) "
        "MIXED VETERAN COMBAT SPORTS RECORD VIEW MODE Basic Odds casino Expand All Details "
        "add_circle filter_list Cancelled: Shown view_list Sport Filters: MMA BK Box Boxing BK MMA Muay Thai "
        "L SUB Cam Teague 31-43-2 10-2 Rear Naked Choke 2:32 R1 Island Fights 97 2026 May 22 add_circle "
        "L DEC Toby Misech Boxing (Bare Knuckle) Decision Unanimous BKFC Fight Night 2026 Apr 11 add_circle "
        "W TKO Pat Sullivan Boxing (Bare Knuckle) Left Hand 1:16 R2 BKFC: Knuckle Mania 6 2026 Feb 7"
    )
    snapshot = {
        "title": "Mixed Veteran | Tapology",
        "headings": ["Mixed Veteran"],
        "body_text": body_text,
        "lines": body_text.split(" "),
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/999-mixed-veteran")

    history = profile["fight_history"]
    assert len(history) >= 3, history
    # The first row is the most recent fight extracted from the combat-sports record
    cam = next(row for row in history if row.get("opponent_name") == "Cam Teague")
    assert cam["result"] == "loss"
    assert cam["fight_date"] == "2026-05-22"
    assert cam["prefight_record_raw"] == "31-43-2"


def test_parse_profile_snapshot_works_for_slug_only_url_with_new_layout():
    body_text = (
        "Rin Nakai Pro MMA Record: 27-2-1 Date of Birth: 1986 Oct 22 "
        "Height: 5'1\" (155cm) Weight Class: Flyweight "
        "RIN NAKAI MMA FIGHT RECORD VIEW MODE Basic Odds casino "
        "Expand All Details add_circle filter_list Sport Filters: MMA Grapple Custom "
        "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision Unanimous DEEP JEWELS 53 2026 May 24 add_circle "
        "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO 3:32 R1 DEEP JEWELS 47 2024 Nov 23"
    )
    snapshot = {
        "url": "https://www.tapology.com/fightcenter/fighters/rin-nakai",
        "title": "Rin Nakai | Tapology",
        "headings": ["Rin Nakai"],
        "body_text": body_text,
        "lines": body_text.split(" "),
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/rin-nakai")

    assert profile["status"] == "success"
    assert profile["source_fighter_id"].startswith("tapology_fighter_url_")
    assert profile["display_name"] == "Rin Nakai"
    assert profile["profile_summary"]["date_of_birth"] == "1986-10-22"
    assert profile["profile_summary"]["birth_year"] == 1986
    assert profile["profile_summary"]["height_cm"] == 155
    assert len(profile["fight_history"]) >= 2


def test_parse_profile_snapshot_does_not_infer_birth_year_from_age_only():
    snapshot = {
        "title": "Age Only | MMA Fighter Page | Tapology",
        "headings": ["Age Only"],
        "body_text": "Age Only Pro MMA Record: 1-0-0 Age: 22",
        "lines": ["Age Only", "Pro MMA Record: 1-0-0", "Age: 22"],
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/101-age-only")

    assert profile["profile_summary"]["age"] == 22
    assert profile["profile_summary"]["date_of_birth"] is None
    assert profile["profile_summary"]["birth_year"] is None


def test_profile_completeness_score_rewards_profile_record_and_history():
    profile = {
        "status": "success",
        "profile_summary": {
            "date_of_birth": "1999-01-04",
            "image_url": "https://images.tapology.com/headshot.jpg",
            "height": "5'6\"",
            "reach": "66\"",
            "weight": "125 lbs",
            "weight_class": "Flyweight",
            "association": "Example MMA",
            "born_location": "Denver, Colorado",
        },
        "record_summary": {"wins": 5, "losses": 1, "draws": 0, "no_contests": 0},
        "fight_history": [{"opponent_name": f"Fighter {index}"} for index in range(10)],
    }

    assert profile_completeness_score(profile) == 15


def test_build_profile_enrichment_report_counts_coverage_and_examples():
    document = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "source": "tapology",
        "run_id": "20260519T000000Z",
        "scraped_at": "2026-05-19T00:00:00+00:00",
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                "status": "success",
                "display_name": "Jane Doe",
                "profile_summary": {
                    "nickname": "The Example",
                    "date_of_birth": "1999-01-04",
                    "age": 27,
                    "birth_year": 1999,
                    "born_location": "Denver, Colorado",
                    "fighting_out_of": "Miami, Florida",
                    "association": "Example MMA",
                    "height": "5'6\"",
                    "reach": "66\"",
                    "weight": "125 lbs",
                    "last_weigh_in": "125 lbs",
                    "weight_class": "Flyweight",
                    "image_url": "https://images.tapology.com/headshot.jpg",
                },
                "record_summary": {
                    "pro_record_raw": "5-1-0",
                    "wins": 5,
                    "losses": 1,
                    "draws": 0,
                    "no_contests": 0,
                    "amateur_record_raw": "2-0-0",
                    "finish_breakdown": {"ko_tko": "1"},
                },
                "fight_history": [
                    {
                        "fight_date": "2026-05-01",
                        "event_name": "Example FC 12",
                        "opponent_name": "Ann Other",
                        "opponent_url": "https://www.tapology.com/fightcenter/fighters/200-ann-other",
                        "result": "win",
                        "method": "Decision",
                        "round": 3,
                        "time": "5:00",
                        "weight_class": "Flyweight",
                        "is_amateur": False,
                        "is_cancelled": False,
                        "is_upcoming": False,
                        "opponent_record_raw": "4-0-0",
                    }
                ],
                "warnings": [],
            },
            {
                "source_fighter_id": "tapology_fighter_200",
                "source_url": "https://www.tapology.com/fightcenter/fighters/200-ann-other",
                "status": "failed_parse",
                "display_name": None,
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [],
                "warnings": ["profile_summary_not_found"],
            },
        ],
    }

    report = build_profile_enrichment_report(document)

    assert report["profiles_total"] == 2
    assert report["profiles_success"] == 1
    assert report["profiles_failed_parse"] == 1
    assert report["success_rate"] == 0.5
    assert report["coverage"]["profile"]["with_exact_dob"]["count"] == 1
    assert report["coverage"]["record"]["with_parsed_wins_losses_draws_no_contests"]["count"] == 1
    assert report["coverage"]["fight_history"]["total_fight_history_rows"] == 1
    assert report["coverage"]["fight_history_rows"]["rows_with_opponent_url"]["count"] == 1
    assert report["coverage"]["warnings"]["count_by_warning_type"]["profile_summary_not_found"] == 1
    assert report["examples"]["high_completeness"][0]["display_name"] == "Jane Doe"
    assert report["examples"]["warnings_or_failures"][0]["source_fighter_id"] == "tapology_fighter_200"


def test_build_fighter_profile_queue_merges_local_sources_and_marks_existing(tmp_path):
    latest_dir = tmp_path / "latest"
    runs_dir = tmp_path / "runs"
    derived_dir = tmp_path / "derived"
    latest_dir.mkdir()
    (runs_dir / "20260519T000000Z").mkdir(parents=True)
    derived_dir.mkdir()
    latest_results = latest_dir / "results.json"
    latest_results.write_text(
        json.dumps(
            {
                "scraped_at": "2026-05-19T00:00:00+00:00",
                "events": [
                    {
                        "event_date": "2026-05-18",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Jane Doe",
                                    "url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe?x=1",
                                    "source_fighter_id": "tapology_fighter_100",
                                    "source_fighter_numeric_id": "100",
                                },
                                "fighter_b": {"name": "Missing URL"},
                            }
                        ],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    (runs_dir / "20260519T000000Z" / "results.json").write_text(
        json.dumps(
            {
                "events": [
                    {
                        "event_date": "2026-05-17",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Ann Other",
                                    "url": "https://www.tapology.com/fightcenter/fighters/200-ann-other",
                                },
                                "fighter_b": {
                                    "name": "Jane Doe",
                                    "url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                                },
                            }
                        ],
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    derived_index = derived_dir / "fighters_index.json"
    derived_index.write_text(
        json.dumps(
            {
                "fighters": {
                    "tapology_fighter_300": {
                        "fighter_source_url": "https://www.tapology.com/fightcenter/fighters/300-sam-third",
                        "fighter_source_id": "300",
                        "display_name": "Sam Third",
                    }
                }
            }
        ),
        encoding="utf-8",
    )
    existing_profiles = latest_dir / "fighter_profiles.json"
    existing_profiles.write_text(
        json.dumps(
            {
                "profiles": [
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                        "status": "success",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )

    queue, report, paths = build_fighter_profile_queue(
        latest_results_path=latest_results,
        runs_dir=runs_dir,
        derived_index_path=derived_index,
        existing_profiles_path=existing_profiles,
        include_run_history=True,
        output_path=latest_dir / "fighter_profile_queue.json",
        report_path=latest_dir / "fighter_profile_queue_report.json",
    )

    assert paths["queue"].exists()
    assert paths["report"].exists()
    assert queue["unique_profile_urls"] == 3
    assert queue["missing_url_count"] == 1
    assert queue["duplicate_url_count"] == 1
    assert report["unique_urls_from_latest_results"] == 1
    assert report["unique_urls_from_run_history"] == 2
    assert report["unique_urls_from_derived_index"] == 1
    jane = next(item for item in queue["queue"] if item["source_fighter_id"] == "tapology_fighter_100")
    assert jane["already_enriched"] is True
    assert jane["source_url"] == "https://www.tapology.com/fightcenter/fighters/100-jane-doe"
    assert jane["seen_in"] == ["latest_results", "run_history"]
    assert "last_scraped_at" in jane
    assert jane["stale_reason"] in {"fresh", "active_dataset_last_30_days", "inactive_dataset_over_30_days", "missing_last_scraped_at"}


def test_build_fighter_profile_queue_strict_daily_filters_to_changed_events(tmp_path):
    latest_dir = tmp_path / "latest"
    latest_dir.mkdir()
    results_path = latest_dir / "results.json"
    results_path.write_text(
        json.dumps(
            {
                "events": [
                    {
                        "internal_event_id": "mf_event_000001",
                        "source_event_id": "tapology_event_1",
                        "event_date": "2026-06-08",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Changed A",
                                    "url": "https://www.tapology.com/fightcenter/fighters/101-changed-a",
                                },
                                "fighter_b": {
                                    "name": "Changed B",
                                    "url": "https://www.tapology.com/fightcenter/fighters/102-changed-b",
                                },
                            }
                        ],
                    },
                    {
                        "internal_event_id": "mf_event_000002",
                        "source_event_id": "tapology_event_2",
                        "event_date": "2026-06-08",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Unchanged A",
                                    "url": "https://www.tapology.com/fightcenter/fighters/201-unchanged-a",
                                },
                                "fighter_b": {
                                    "name": "Unchanged B",
                                    "url": "https://www.tapology.com/fightcenter/fighters/202-unchanged-b",
                                },
                            }
                        ],
                    },
                ]
            }
        ),
        encoding="utf-8",
    )
    changes_path = latest_dir / "changes.json"
    changes_path.write_text(
        json.dumps({"new_events": ["mf_event_000001"], "updated_events": [], "incomplete_events": []}),
        encoding="utf-8",
    )

    queue, report, _paths = build_fighter_profile_queue(
        latest_results_path=results_path,
        latest_changes_path=changes_path,
        existing_profiles_path=latest_dir / "missing_profiles.json",
        derived_index_path=latest_dir / "missing_index.json",
        queue_mode="strict-daily",
        output_path=latest_dir / "fighter_profile_queue.json",
        report_path=latest_dir / "fighter_profile_queue_report.json",
    )

    assert queue["queue_mode"] == "strict-daily"
    assert report["queue_mode"] == "strict-daily"
    assert queue["unique_profile_urls"] == 2
    assert queue["mode_filtered_count"] == 2
    assert {item["source_fighter_id"] for item in queue["queue"]} == {
        "tapology_fighter_101",
        "tapology_fighter_102",
    }


def test_build_fighter_profile_queue_classifies_refresh_priority_and_freshness(tmp_path, monkeypatch):
    monkeypatch.setattr(fighter_profile_queue, "_utc_today", lambda: fighter_profile_queue.date(2026, 6, 9))

    monkeypatch.setattr(fighter_profile_queue, "_utc_now", lambda: fighter_profile_queue.datetime(2026,6,9,13,tzinfo=fighter_profile_queue.timezone.utc))
    latest_dir = tmp_path / "latest"
    latest_dir.mkdir()
    results_path = latest_dir / "results.json"
    results_path.write_text(
        json.dumps(
            {
                "scraped_at": "2026-06-09T00:00:00+00:00",
                "events": [
                    {
                        "event_date": "2026-06-08",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Fresh Fighter",
                                    "url": "https://www.tapology.com/fightcenter/fighters/100-fresh-fighter",
                                },
                                "fighter_b": {
                                    "name": "Never Scraped",
                                    "url": "https://www.tapology.com/fightcenter/fighters/200-never-scraped",
                                },
                            }
                        ],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    existing_profiles = latest_dir / "fighter_profiles.json"
    existing_profiles.write_text(
        json.dumps(
            {
                "scraped_at": "2026-06-09T00:00:00+00:00",
                "profiles": [
                    {
                        "source_fighter_id": "tapology_fighter_100",
                        "source_url": "https://www.tapology.com/fightcenter/fighters/100-fresh-fighter",
                        "status": "success",
                        "scraped_at": "2026-06-09T12:00:00+00:00",
                        "record_summary":{"pro_record_raw":"1-0-0","wins":1,"losses":0,"draws":0,"no_contests":0},
                        "career_record_evidence":{"scope":"professional_mma","verified":True,"record_raw":"1-0-0","label":"Pro MMA Record","evidence":"explicit_mma_record_label"},
                        "period_evidence":{"start_date":"2026-05-09","checked_through":"2026-06-09","scope_verified":True,"first_page_verified":True,"load_complete":True,"chronological_descending":True,"history_exhausted":True},
                        "warnings": [],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )

    queue, report, _paths = build_fighter_profile_queue(
        latest_results_path=results_path,
        derived_index_path=tmp_path / "derived" / "fighters_index.json",
        existing_profiles_path=existing_profiles,
        output_path=latest_dir / "fighter_profile_queue.json",
        report_path=latest_dir / "fighter_profile_queue_report.json",
    )

    assert queue["refreshable_count"] == 1
    assert queue["fresh_count"] == 1
    assert report["count_by_stale_reason"]["fresh"] == 1
    assert report["count_by_stale_reason"]["never_scraped"] == 1

    fresh = next(item for item in queue["queue"] if item["source_fighter_id"] == "tapology_fighter_100")
    never = next(item for item in queue["queue"] if item["source_url"].endswith("/200-never-scraped"))
    assert fresh["should_refresh"] is False
    assert fresh["stale_reason"] == "fresh"
    assert never["should_refresh"] is True
    assert never["priority"] > fresh["priority"]
    assert queue["queue"][0]["source_url"].endswith("/200-never-scraped")


def test_build_fighter_profile_queue_refreshes_known_fighter_after_new_dataset_fight(tmp_path, monkeypatch):
    monkeypatch.setattr(fighter_profile_queue, "_utc_today", lambda: fighter_profile_queue.date(2026, 6, 14))

    latest_dir = tmp_path / "latest"
    latest_dir.mkdir()
    results_path = latest_dir / "results.json"
    results_path.write_text(
        json.dumps(
            {
                "scraped_at": "2026-06-14T00:00:00+00:00",
                "events": [
                    {
                        "event_date": "2026-06-13",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Known Fighter",
                                    "url": "https://www.tapology.com/fightcenter/fighters/100-known-fighter",
                                },
                                "fighter_b": {
                                    "name": "Other Fighter",
                                    "url": "https://www.tapology.com/fightcenter/fighters/101-other-fighter",
                                },
                            }
                        ],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    existing_profiles = latest_dir / "fighter_profiles.json"
    existing_profiles.write_text(
        json.dumps(
            {
                "profiles": [
                    {
                        "source_fighter_id": "tapology_fighter_100",
                        "source_url": "https://www.tapology.com/fightcenter/fighters/100-known-fighter",
                        "status": "success",
                        "scraped_at": "2026-06-11T12:00:00+00:00",
                        "warnings": [],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )

    queue, report, _paths = build_fighter_profile_queue(
        latest_results_path=results_path,
        derived_index_path=tmp_path / "derived" / "fighters_index.json",
        existing_profiles_path=existing_profiles,
        output_path=latest_dir / "fighter_profile_queue.json",
        report_path=latest_dir / "fighter_profile_queue_report.json",
    )

    known = next(item for item in queue["queue"] if item["source_fighter_id"] == "tapology_fighter_100")
    assert known["already_enriched"] is True
    assert known["should_refresh"] is True
    assert known["stale_reason"] == "newer_dataset_fight_after_profile_scrape"
    assert report["count_by_stale_reason"]["newer_dataset_fight_after_profile_scrape"] == 1


def test_build_fighter_profile_queue_uses_latest_upcoming_for_priority(tmp_path, monkeypatch):
    monkeypatch.setattr(fighter_profile_queue, "_utc_today", lambda: fighter_profile_queue.date(2026, 6, 9))

    latest_dir = tmp_path / "latest"
    latest_dir.mkdir()
    results_path = latest_dir / "results.json"
    results_path.write_text(json.dumps({"events": []}), encoding="utf-8")
    upcoming_path = latest_dir / "upcoming.json"
    upcoming_path.write_text(
        json.dumps(
            {
                "scraped_at": "2026-06-09T00:00:00+00:00",
                "events": [
                    {
                        "event_date": "2026-06-15",
                        "bouts": [
                            {
                                "fighter_a": {
                                    "name": "Upcoming Fighter",
                                    "url": "https://www.tapology.com/fightcenter/fighters/300-upcoming-fighter",
                                },
                                "fighter_b": {
                                    "name": "Opponent",
                                    "url": "https://www.tapology.com/fightcenter/fighters/301-opponent",
                                },
                            }
                        ],
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    existing_profiles = latest_dir / "fighter_profiles.json"
    existing_profiles.write_text(
        json.dumps(
            {
                "profiles": [
                    {
                        "source_fighter_id": "tapology_fighter_300",
                        "source_url": "https://www.tapology.com/fightcenter/fighters/300-upcoming-fighter",
                        "status": "success",
                        "scraped_at": "2026-06-01T00:00:00+00:00",
                        "warnings": [],
                    }
                ]
            }
        ),
        encoding="utf-8",
    )

    queue, report, _paths = build_fighter_profile_queue(
        latest_results_path=results_path,
        latest_upcoming_path=upcoming_path,
        derived_index_path=tmp_path / "derived" / "fighters_index.json",
        existing_profiles_path=existing_profiles,
        output_path=latest_dir / "fighter_profile_queue.json",
        report_path=latest_dir / "fighter_profile_queue_report.json",
    )

    upcoming = next(item for item in queue["queue"] if item["source_url"].endswith("/300-upcoming-fighter"))
    assert report["unique_urls_from_latest_upcoming"] == 2
    assert upcoming["latest_upcoming_at"] == "2026-06-15"
    assert upcoming["stale_reason"] == "upcoming_event_next_30_days"
    assert upcoming["should_refresh"] is True
    assert upcoming["refresh_ttl_days"] == 3


def test_merge_fighter_profiles_documents_retains_previous_success_on_failed_refresh():
    existing = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "scraped_at": "2026-06-01T00:00:00+00:00",
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                "status": "success",
                "display_name": "Jane Doe",
                "scraped_at": "2026-06-01T00:00:00+00:00",
                "profile_summary": {"date_of_birth": "1999-01-01"},
                "record_summary": {"pro_record_raw": "5-1-0", "wins": 5, "losses": 1, "draws": 0, "no_contests": 0},
                "fight_history": [{"fight_date": "2026-05-01"}],
                "warnings": [],
            }
        ],
    }
    batch = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "20260609T000000Z",
        "scraped_at": "2026-06-09T00:00:00+00:00",
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                "status": "blocked",
                "display_name": None,
                "profile_summary": {},
                "record_summary": {},
                "fight_history": [],
                "warnings": ["profile_fetch_failed:blocked_or_challenge"],
            }
        ],
    }

    merged = merge_fighter_profiles_documents(existing, batch, run_id="20260609T000000Z", scraped_at="2026-06-09T00:00:00+00:00")

    assert merged["profiles_total"] == 1
    assert merged["profiles_success"] == 1
    assert merged["merge_stats"]["retained_previous_success_on_failure"] == 1
    assert merged["profiles"][0]["status"] == "success"
    assert merged["profiles"][0]["display_name"] == "Jane Doe"


def test_merge_fighter_profiles_documents_replaces_existing_on_success():
    existing = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "scraped_at": "2026-06-01T00:00:00+00:00",
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                "status": "success",
                "display_name": "Jane Doe",
                "scraped_at": "2026-06-01T00:00:00+00:00",
                "profile_summary": {"date_of_birth": "1999-01-01"},
                "record_summary": {"pro_record_raw": "5-1-0", "wins": 5, "losses": 1, "draws": 0, "no_contests": 0},
                "fight_history": [{"fight_date": "2026-05-01"}],
                "warnings": [],
            }
        ],
    }
    batch = {
        "schema_version": "tapology_fighter_profiles_v0_1",
        "run_id": "20260609T000000Z",
        "scraped_at": "2026-06-09T00:00:00+00:00",
        "profiles": [
            {
                "source_fighter_id": "tapology_fighter_100",
                "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                "status": "success",
                "display_name": "Jane Doe Updated",
                "scraped_at": "2026-06-09T00:00:00+00:00",
                "profile_summary": {"date_of_birth": "1999-01-01", "height": "5'7\""},
                "record_summary": {"pro_record_raw": "6-1-0", "wins": 6, "losses": 1, "draws": 0, "no_contests": 0},
                "fight_history": [{"fight_date": "2026-06-08"}],
                "warnings": [],
            }
        ],
    }

    merged = merge_fighter_profiles_documents(existing, batch, run_id="20260609T000000Z", scraped_at="2026-06-09T00:00:00+00:00")

    assert merged["profiles_total"] == 1
    assert merged["merge_stats"]["updated"] == 1
    assert merged["profiles"][0]["display_name"] == "Jane Doe Updated"
    assert merged["profiles"][0]["record_summary"]["wins"] == 6


# ---------------------------------------------------------------------------
# extract_event_name_from_raw_text — narrow parser unit tests
# ---------------------------------------------------------------------------


def test_extract_event_name_after_round_marker():
    raw = "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47 2024 Nov 23"
    assert extract_event_name_from_raw_text(raw) == "DEEP JEWELS 47"


def test_extract_event_name_after_decision_unanimous():
    raw = "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision · Unanimous DEEP JEWELS 53 2026 May 24"
    assert extract_event_name_from_raw_text(raw) == "DEEP JEWELS 53"


def test_extract_event_name_after_decision_split():
    raw = "W DEC Kanako Takeshita 1-0 3-0 Decision · Split Smackgirl: World ReMix 2008 (Round 2) 2008 Apr 25"
    assert (
        extract_event_name_from_raw_text(raw)
        == "Smackgirl: World ReMix 2008 (Round 2)"
    )


def test_extract_event_name_handles_legacy_loss_placeholder():
    raw = "L DEC Johnavan Vistante 26-26-2 5-2 Loss Destiny MMA 2012 Sep 8"
    assert extract_event_name_from_raw_text(raw) == "Destiny MMA"


def test_extract_event_name_handles_legacy_win_placeholder():
    raw = "W TKO Michael Casteel 24-23-2 2-10 Win King of the Cage 2011 Sep 10"
    assert extract_event_name_from_raw_text(raw) == "King of the Cage"


def test_extract_event_name_after_bare_ko_tko_label():
    raw = "W TKO Faridun Kamolov 3-1 0-0 KO/TKO BetCity Fight Nights 137 2026 May 23"
    assert extract_event_name_from_raw_text(raw) == "BetCity Fight Nights 137"


def test_extract_event_name_after_draw_decision_time_limit():
    raw = "D DEC Atsushi Tamura 3-2-1 0-0-3 Draw · Decision · Time Limit ZST 42: SWAT! 2014 Aug 31"
    assert extract_event_name_from_raw_text(raw) == "ZST 42: SWAT!"


def test_extract_event_name_strips_tapology_icon_residue():
    raw = "W DEC Kazue Matake Modified Rules MMA Decision · Unanimous Smackgirl 2007 person_off 2007 May 19"
    assert extract_event_name_from_raw_text(raw) == "Smackgirl 2007"


def test_extract_event_name_returns_none_for_cancelled_only_segment():
    raw = "C Pannie Kianzad Cancelled Bout 2026 Mar 21"
    assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_returns_none_without_end_marker():
    raw = "Some Opponent fought somewhere"
    assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_rejects_generic_noise_tokens():
    # The terminator is present but only "MMA" sits between it and the date.
    raw = "W TKO Some Opponent 1-0 1-0 KO/TKO MMA 2024 Jan 1"
    assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_rejects_tapology_ui_preamble_tokens():
    for token in ["VIEW MODE", "Basic Odds", "Sport Filters", "Win", "Loss", "Cancelled: Shown"]:
        raw = f"W TKO Some Opponent 1-0 1-0 KO/TKO {token} 2024 Jan 1"
        assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_does_not_use_opponent_name_as_event_name():
    raw = "W TKO Sam Example 1-0 0-0 KO/TKO Sam Example 2024 Jan 1"
    assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_rejects_record_only_residue():
    raw = "W TKO Some Opponent 1-0 0-0 KO/TKO 1-0 2024 Jan 1"
    assert extract_event_name_from_raw_text(raw) is None


def test_extract_event_name_handles_explicit_date_raw_argument():
    raw = "W TKO Foo 1-0 0-0 KO/TKO · 1:00 · R1 Tournament Finals 2020 Jan 1"
    # When date_raw is supplied explicitly we still slice cleanly before it.
    assert extract_event_name_from_raw_text(raw, "2020 Jan 1") == "Tournament Finals"


def test_extract_event_name_returns_none_for_empty_input():
    assert extract_event_name_from_raw_text(None) is None
    assert extract_event_name_from_raw_text("") is None


# ---------------------------------------------------------------------------
# parse_profile_snapshot integration — body parser now lifts event_name
# ---------------------------------------------------------------------------


def test_body_parser_populates_event_name_from_raw_text():
    body_text = (
        "Rin Nakai Pro MMA Record: 27-2-1 "
        "RIN NAKAI MMA FIGHT RECORD VIEW MODE Basic Odds casino Expand All Details "
        "add_circle filter_list Sport Filters: MMA Grapple Custom "
        "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision · Unanimous DEEP JEWELS 53 2026 May 24 add_circle "
        "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47 2024 Nov 23 add_circle "
        "L DEC Leslie Smith 14-1-1 7-6-1 Decision · Unanimous UFC Fight Night 85 2016 Mar 19"
    )
    snapshot = {
        "url": "https://www.tapology.com/fightcenter/fighters/rin-nakai",
        "title": "Rin Nakai | Tapology",
        "headings": ["Rin Nakai"],
        "body_text": body_text,
        "lines": body_text.split(" "),
        "rows": [],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/rin-nakai")

    history = {row.get("opponent_name"): row for row in profile["fight_history"]}
    assert history["Yuka Okutomi"]["event_name"] == "DEEP JEWELS 53"
    assert history["Haruka Suzuki"]["event_name"] == "DEEP JEWELS 47"
    assert history["Leslie Smith"]["event_name"] == "UFC Fight Night 85"


def test_snapshot_row_trims_at_first_add_circle_to_isolate_first_fight():
    """A Tapology container <tr> may render the whole fight list as a single
    raw_text. The per-row parser must scope to the first fight so flags like
    is_cancelled don't bleed in from later "Cancelled Bout" segments."""
    snapshot = {
        "title": "Rin Nakai | Tapology",
        "headings": ["Rin Nakai"],
        "body_text": "Rin Nakai Pro MMA Record: 27-2-1",
        "lines": ["Rin Nakai", "Pro MMA Record: 27-2-1"],
        "rows": [
            {
                "raw_text": (
                    "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision · Unanimous DEEP JEWELS 53 2026 May 24 "
                    "add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
                ),
                "cells": [],
                "links": [
                    {
                        "text": "DEEP JEWELS 53",
                        "href": "https://www.tapology.com/fightcenter/events/142423-deep-jewels-53",
                    },
                    {
                        "text": "Yuka Okutomi",
                        "href": "https://www.tapology.com/fightcenter/fighters/200-yuka-okutomi",
                    },
                ],
            }
        ],
        "images": [],
    }

    profile = parse_profile_snapshot(snapshot, "https://www.tapology.com/fightcenter/fighters/100-rin-nakai")

    [row] = profile["fight_history"]
    assert row["opponent_name"] == "Yuka Okutomi"
    assert row["event_name"] == "DEEP JEWELS 53"
    # The "Cancelled Bout" entry must NOT pollute this row's flags.
    assert row.get("is_cancelled") is not True


def test_collect_fighter_profile_urls_from_queue_respects_skip_and_limit(tmp_path):
    queue_path = tmp_path / "fighter_profile_queue.json"
    queue_path.write_text(
        json.dumps(
            {
                "schema_version": "fighter_profile_queue_v0_1",
                "queue": [
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                        "already_enriched": True,
                        "should_refresh": False,
                    },
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/200-ann-other",
                        "already_enriched": False,
                        "should_refresh": True,
                    },
                ],
            }
        ),
        encoding="utf-8",
    )

    urls, metadata = collect_fighter_profile_urls_from_queue(queue_path, limit=10, skip_already_enriched=True)

    assert urls == ["https://www.tapology.com/fightcenter/fighters/200-ann-other"]
    assert metadata["available_queue_urls"] == 2
    assert metadata["selected_for_enrichment"] == 1
    assert metadata["skipped_already_enriched"] == 1
    assert metadata["skipped_fresh"] == 0


def test_collect_fighter_profile_urls_from_queue_skips_fresh_entries_by_default(tmp_path):
    queue_path = tmp_path / "fighter_profile_queue.json"
    queue_path.write_text(
        json.dumps(
            {
                "schema_version": "fighter_profile_queue_v0_1",
                "queue": [
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/100-jane-doe",
                        "already_enriched": True,
                        "should_refresh": False,
                    },
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/200-ann-other",
                        "already_enriched": False,
                        "should_refresh": True,
                    },
                ],
            }
        ),
        encoding="utf-8",
    )

    urls, metadata = collect_fighter_profile_urls_from_queue(queue_path, limit=10, skip_already_enriched=False)

    assert urls == ["https://www.tapology.com/fightcenter/fighters/200-ann-other"]
    assert metadata["skipped_already_enriched"] == 0
    assert metadata["skipped_fresh"] == 1


# ---------------------------------------------------------------------------
# Row-boundary / section-heading scoping
# ---------------------------------------------------------------------------


def test_scope_fight_row_text_trims_at_add_circle_separator():
    """A multi-fight container row keeps only the first fight's text."""
    raw = (
        "W DEC 1 Yuka Okutomi 26-2-1 4-1 Decision DEEP JEWELS 53 2026 May 24 "
        "add_circle C Pannie Kianzad Cancelled Bout 2026 Mar 21"
    )
    scoped = scope_fight_row_text(raw)
    assert "Pannie Kianzad" not in scoped
    assert "Cancelled" not in scoped
    assert scoped.startswith("W DEC")


def test_scope_fight_row_text_strips_leading_professional_bouts_prefix():
    """A DOM container that leads with PROFESSIONAL BOUTS drops that prefix."""
    raw = (
        "PROFESSIONAL BOUTS L DEC Ye Ji Lee 5-5 6-8 Decision DEEP JEWELS 53 2026 May 24 "
        "add_circle W TKO Misaki Suda"
    )
    scoped = scope_fight_row_text(raw)
    assert not scoped.upper().startswith("PROFESSIONAL BOUTS")
    assert scoped.startswith("L DEC Ye Ji Lee")
    assert "Misaki Suda" not in scoped


def test_scope_fight_row_text_truncates_at_trailing_amateur_bouts_heading():
    """A pro row followed by an AMATEUR BOUTS heading must drop the trailing
    section text so per-row exclusion-flag derivation cannot bleed."""
    raw = "W DEC Manzorro 1-1 0-0 Decision WOW 30 2026 May 22 AMATEUR BOUTS L DEC something"
    scoped = scope_fight_row_text(raw)
    assert "AMATEUR" not in scoped
    assert "something" not in scoped


def test_scope_fight_row_text_keeps_first_fight_after_view_mode_preamble():
    """When the first add_circle is preceded only by UI chrome (VIEW MODE
    Sport Filters ...), the actual first fight lives AFTER the separator
    and we must keep it instead of returning the preamble."""
    raw = (
        "VIEW MODE Sport Filters: MMA Grapple Custom add_circle "
        "W TKO Pat Sullivan Boxing (Bare Knuckle) Left Hand BKFC: Knuckle Mania 6 2026 Feb 7 "
        "add_circle L DEC Next Opponent"
    )
    scoped = scope_fight_row_text(raw)
    assert "VIEW MODE" not in scoped
    assert "Sport Filters" not in scoped
    assert scoped.startswith("W TKO Pat Sullivan")
    assert "Next Opponent" not in scoped


def test_scope_fight_row_text_preserves_clean_single_fight_row():
    """A clean single-fight row must round-trip unchanged."""
    raw = "W TKO Haruka Suzuki 25-2-1 2-2 KO/TKO · 3:32 · R1 DEEP JEWELS 47 2024 Nov 23"
    assert scope_fight_row_text(raw) == raw


def test_scope_fight_row_text_preserves_legitimate_cancelled_row():
    """A genuinely cancelled row must keep its 'Cancelled Bout' text so the
    exclusion flag survives."""
    raw = "C Pannie Kianzad Cancelled Bout 2026 Mar 21"
    assert scope_fight_row_text(raw) == raw


def test_scope_fight_row_text_handles_empty_and_none():
    assert scope_fight_row_text(None) == ""
    assert scope_fight_row_text("") == ""
    assert scope_fight_row_text("   ") == ""
