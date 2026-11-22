import json

from mma_future_scraper.fighter_profile_queue import _existing_profiles, _gender_evidence_refresh_priority
from mma_future_scraper.fighter_profiles import _extract_gender_evidence, _extract_profile_summary


HEADLINES = [
    "Bad Left Hook: 'She doesn't even want to mention my name': Caroline Dubois says Alycia Baumgardner running scared",
    "MMA Junkie: Terrance McKinney explains welterweight move since he fought at UFC 334",
]
DETAILS = ["Fighter Details", "Weight Class:", "Strawweight", "| Last Weigh-In:", "125.5 lbs"]
END = ["MMA RECORD BY PROMOTION", "PAULO DA SILVA", "MMA FIGHT RECORD"]

MALE = [
    "Regional MMA Rankings",
    "Rankings FAQ",
    "Alejandro Alvarado-Jimenez is the #13,189 ranked MMA fighter in the North America region. Alvarado-Jimenez is also "
    "ranked in 2 North America sub-regions (Canada, Québec). He is only ranked in regions where he has fought at least 2 "
    "times out of his last 6 MMA bouts.",
    "Alvarado-Jimenez is ranked at Featherweight in each region, since he fought at least once in the weight class in his last 2 fights.",
    "North America",
    "#",
    "13,189",
    "Pound for Pound",
    "(out of 18,954)",
    "902",
    "Pro Featherweight",
]
FEMALE = [
    "Regional MMA Rankings",
    "Rankings FAQ",
    "Danna Cortes is the #5,216 ranked MMA fighter in the Central & South America region. Cortes is also ranked in 2 "
    "Central & South America sub-regions (Latin America, Mexico). She is only ranked in regions where she has fought at "
    "least 2 times out of her last 6 MMA bouts.",
    "Due to the comparatively small number of female fighters in most regions, all women are grouped into a single Pound "
    "for Pound ranking in each region, instead of separate rankings for each weight class.",
    "Central & South America",
    "#",
    "397",
    "Pound for Pound Women",
    "(out of 1,283)",
]


def page(section):
    return HEADLINES + DETAILS + section + END


def evidence(section):
    warnings = []
    gender, proof = _extract_gender_evidence(page(section), warnings)
    return gender, proof, warnings


def test_he_in_the_ranking_text_is_male():
    gender, proof, warnings = evidence(MALE)

    assert gender == "male"
    assert proof["pronoun"] == "he"
    assert proof["status"] == "explicit"
    assert proof["women_p4p_ranking"] is False and proof["female_grouping_note"] is False
    assert "He is only ranked in regions where he has fought" in proof["excerpt"]
    assert len(proof["excerpt"]) <= 240
    assert warnings == []


def test_she_with_the_womens_ranking_line_is_female():
    gender, proof, warnings = evidence(FEMALE)

    assert gender == "female"
    assert proof == {
        "pronoun": "she",
        "women_p4p_ranking": True,
        "female_grouping_note": True,
        "status": "explicit",
        "excerpt": proof["excerpt"],
    }
    assert warnings == []


def test_few_fights_wording_is_recognised():
    section = [
        "Regional MMA Rankings",
        "Jane Roe is the #300 ranked MMA fighter in the Europe region. With 5 or fewer total MMA fights she is "
        "automatically ranked in any region she has competed in.",
        "Pound for Pound Women",
    ]

    assert evidence(section)[0] == "female"


def test_womens_ranking_line_alone_is_enough():
    gender, proof, _ = evidence(["Regional MMA Rankings", "Europe", "#", "12", "Pound for Pound Women", "(out of 400)"])

    assert gender == "female"
    assert proof["pronoun"] is None and proof["status"] == "explicit"


def test_headlines_outside_the_ranking_section_are_ignored():
    section = ["Regional MMA Rankings", "Rankings FAQ", "Chas Dunhour is currently ineligible for Tapology's regional MMA rankings."]
    gender, proof, warnings = evidence(section)

    assert gender is None
    assert proof["pronoun"] is None
    assert proof["status"] == "ineligible"
    assert warnings == []


def test_major_organisation_and_inactivity_are_ineligible():
    for sentence in (
        "Fighting for a major organization has made Josefine Knutsson ineligible for Tapology's regional MMA rankings.",
        "Michał Pasternak is ineligible for Tapology's regional MMA rankings due to inactivity.",
    ):
        gender, proof, _ = evidence(["Regional MMA Rankings", sentence, "PRO MMA STATISTICS"])
        assert gender is None and proof["status"] == "ineligible"


def test_pre_debut_text_uses_their_and_gives_no_gender():
    section = [
        "Regional MMA Rankings",
        "Rankings FAQ",
        "Dawid Skawiańczyk will become eligible for Tapology's regional MMA rankings following the completion of their first MMA bout.",
    ]
    gender, proof, _ = evidence(section)

    assert gender is None
    assert proof["status"] == "pre_debut"


def test_contradicting_signals_give_no_gender_and_a_warning():
    for section in (MALE + ["Pound for Pound Women"], MALE + [FEMALE[2]]):
        gender, proof, warnings = evidence(section)
        assert gender is None
        assert proof["status"] == "conflict"
        assert warnings == ["gender_evidence_conflict"]


def test_page_without_the_ranking_section_is_reported_as_missing():
    warnings = []
    gender, proof = _extract_gender_evidence(HEADLINES + DETAILS + END, warnings)

    assert gender is None
    assert proof["status"] == "missing_section"
    assert warnings == []


def test_text_after_the_section_end_is_not_read():
    section = ["Regional MMA Rankings", "Rankings FAQ"]
    lines = HEADLINES + DETAILS + section + END + [MALE[2]]

    assert _extract_gender_evidence(lines, [])[0] is None


def test_profile_summary_carries_gender_next_to_the_weight_class():
    lines = page(MALE)
    summary = _extract_profile_summary({}, " ".join(lines), lines, "https://www.tapology.com/fightcenter/fighters/288111-paulo-da-silva-ph", [])

    assert summary["weight_class"] == "Strawweight"
    assert summary["gender"] == "male"
    assert summary["gender_evidence"]["status"] == "explicit"


def cached(weight_class, **summary):
    return {"status": "success", "weight_class": weight_class, "has_gender_evidence": "gender_evidence" in summary}


def test_profiles_parsed_before_gender_evidence_are_refreshed_by_how_ambiguous_their_class_is():
    assert _gender_evidence_refresh_priority(cached("Strawweight")) == 95
    assert _gender_evidence_refresh_priority(cached("Atom")) == 95
    for weight_class in ("Flyweight", "Bantamweight", "Featherweight", "Lightweight"):
        assert _gender_evidence_refresh_priority(cached(weight_class)) == 70
    for weight_class in ("Welterweight", "Light Heavy", "Heavyweight", None):
        assert _gender_evidence_refresh_priority(cached(weight_class)) == 0


def test_profiles_that_already_have_gender_evidence_are_not_refreshed_for_it():
    assert _gender_evidence_refresh_priority(cached("Strawweight", gender_evidence={})) == 0
    assert _gender_evidence_refresh_priority({"status": "failed_fetch", "weight_class": "Strawweight"}) == 0


def test_cached_profiles_expose_weight_class_and_gender_evidence_to_the_queue(tmp_path):
    path = tmp_path / "fighter_profiles.json"
    path.write_text(
        json.dumps(
            {
                "profiles": [
                    {"source_url": "https://www.tapology.com/fightcenter/fighters/1-old", "status": "success", "profile_summary": {"weight_class": "Strawweight"}},
                    {
                        "source_url": "https://www.tapology.com/fightcenter/fighters/2-new",
                        "status": "success",
                        "profile_summary": {"weight_class": "Strawweight", "gender": None, "gender_evidence": {"status": "ineligible"}},
                    },
                ]
            }
        ),
        encoding="utf-8",
    )
    by_id = _existing_profiles(path)["by_id"]

    assert _gender_evidence_refresh_priority(by_id["tapology_fighter_1"]) == 95
    assert _gender_evidence_refresh_priority(by_id["tapology_fighter_2"]) == 0
