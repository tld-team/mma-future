import pytest

from mma_future_scraper.country_normalizer import (
    COUNTRY_NORMALIZER_SUPPORTED_ALIAS_COUNT,
    COUNTRY_NORMALIZER_SUPPORTED_ENTRY_COUNT,
    normalize_country,
)
from mma_future_scraper.fighter_profiles import build_event_profile_enrichment_summary, normalize_nationality_raw


@pytest.mark.parametrize(
    ("raw", "country_name", "country_code"),
    [
        ("united states", "United States", "USA"),
        ("United States", "United States", "USA"),
        ("USA", "United States", "USA"),
        ("brazil", "Brazil", "BRA"),
        ("Japan", "Japan", "JPN"),
        ("South Korea", "South Korea", "KOR"),
        ("Republic of Korea", "South Korea", "KOR"),
        ("Russia", "Russia", "RUS"),
        ("Russian Federation", "Russia", "RUS"),
        ("Taiwan", "Taiwan", "TWN"),
        ("England", "England", "ENG"),
        ("Scotland", "Scotland", "SCO"),
        ("Wales", "Wales", "WAL"),
        ("Northern Ireland", "Northern Ireland", "NIR"),
        ("Guam", "Guam", "GUM"),
        ("South Africa", "South Africa", "ZAF"),
        ("Costa Rica", "Costa Rica", "CRI"),
        ("Kyrgyzstan", "Kyrgyzstan", "KGZ"),
        ("Azerbaijan", "Azerbaijan", "AZE"),
        ("Armenia", "Armenia", "ARM"),
        ("Colombia", "Colombia", "COL"),
        ("Senegal", "Senegal", "SEN"),
        ("Paraguay", "Paraguay", "PRY"),
        ("Ukraine", "Ukraine", "UKR"),
        ("Bahrain", "Bahrain", "BHR"),
        ("Uzbekistan", "Uzbekistan", "UZB"),
        ("Zimbabwe", "Zimbabwe", "ZWE"),
    ],
)
def test_normalize_nationality_raw_supports_required_aliases(raw, country_name, country_code):
    normalized = normalize_nationality_raw(raw)

    assert normalized == {
        "raw": raw,
        "country_name": country_name,
        "country_code": country_code,
        "source": "profile_header_flag",
        "is_missing": False,
    }


def test_country_normalizer_preserves_distinct_home_nations_and_united_kingdom():
    assert normalize_country("UK") == {
        "canonical_name": "United Kingdom",
        "display_code": "GBR",
        "alpha2": "GB",
        "alpha3": "GBR",
        "matched_alias": "UK",
        "match_type": "alias",
    }
    assert normalize_country("England") == {
        "canonical_name": "England",
        "display_code": "ENG",
        "alpha2": None,
        "alpha3": None,
        "matched_alias": "England",
        "match_type": "territory",
    }
    assert normalize_country("Scotland")["display_code"] == "SCO"
    assert normalize_country("Wales")["display_code"] == "WAL"
    assert normalize_country("Northern Ireland")["display_code"] == "NIR"


def test_country_normalizer_supports_hong_kong_and_territory_aliases():
    assert normalize_country("Hong Kong")["canonical_name"] == "Hong Kong"
    assert normalize_country("Hong Kong SAR")["display_code"] == "HKG"
    assert normalize_country("Guam")["display_code"] == "GUM"


def test_normalize_nationality_raw_keeps_unmatched_raw_for_diagnostics():
    normalized = normalize_nationality_raw("Atlantis")

    assert normalized == {
        "raw": "Atlantis",
        "country_name": None,
        "country_code": None,
        "source": "profile_header_flag",
        "is_missing": True,
    }


def test_event_profile_enrichment_summary_reports_unmatched_country_diagnostics_with_counts():
    events = [
        {
            "location": "Metropolis, Wakanda",
            "fighter_profiles": {
                "tapology_fighter_1": {
                    "profile_fetched": True,
                    "profile_warnings": [],
                    "nationality": {
                        "raw": "Atlantis",
                        "country_name": None,
                        "country_code": None,
                        "source": "profile_header_flag",
                        "is_missing": True,
                    },
                    "born_raw": "Nookat, Osh region, Kyrgyzstan",
                    "fighting_out_of_raw": "Birnin Zana, Wakanda",
                },
                "tapology_fighter_2": {
                    "profile_fetched": True,
                    "profile_warnings": [],
                    "nationality": {
                        "raw": "Atlantis",
                        "country_name": None,
                        "country_code": None,
                        "source": "profile_header_flag",
                        "is_missing": True,
                    },
                    "born_raw": "Gokwe North, Zimbabwe",
                    "fighting_out_of_raw": "Golden City, Wakanda",
                },
            },
        }
    ]

    summary = build_event_profile_enrichment_summary(events)

    assert summary["unmatched_nationality_raw_values"] == [{"raw": "Atlantis", "count": 2}]
    assert summary["unmatched_location_country_candidates"] == [{"raw": "Wakanda", "count": 1}]
    assert summary["unmatched_born_country_candidates"] == []
    assert summary["unmatched_fighting_out_country_candidates"] == [{"raw": "Wakanda", "count": 2}]


def test_country_normalizer_has_broad_static_coverage():
    assert COUNTRY_NORMALIZER_SUPPORTED_ENTRY_COUNT >= 240
    assert COUNTRY_NORMALIZER_SUPPORTED_ALIAS_COUNT >= 320
