import json

import pytest

from mma_future_scraper.gender_backfill import backfill_gender_evidence, build_gender_evidence_queue


HE = "John Roe is the #10 ranked MMA fighter in the Europe region. He is only ranked in regions where he has fought at least 2 times out of his last 6 MMA bouts."
SHE = "Jane Roe is the #10 ranked MMA fighter in the Europe region. She is only ranked in regions where she has fought at least 2 times out of her last 6 MMA bouts."


def url(number, slug="fighter"):
    return f"https://www.tapology.com/fightcenter/fighters/{number}-{slug}"


def profile(number, weight_class, **extra):
    summary = {"weight_class": weight_class}
    summary.update(extra.pop("summary", {}))
    return {
        "source_fighter_id": f"tapology_fighter_{number}",
        "source_url": url(number),
        "status": "success",
        "display_name": f"Fighter {number}",
        "scraped_at": "2026-11-17T10:00:00+00:00",
        "profile_summary": summary,
        "fight_history": extra.pop("fights", []),
        **extra,
    }


def capture(root, run, number, sentence, *, landed=None):
    folder = root / run / "profile_snapshots"
    folder.mkdir(parents=True, exist_ok=True)
    lines = ["Bad Left Hook: she is the favourite", "Fighter Details", "Regional MMA Rankings", "Rankings FAQ", sentence, "MMA RECORD BY PROMOTION"]
    (folder / f"tapology_fighter_{number}.json").write_text(
        json.dumps({"url": landed or url(number), "lines": lines, "rows": [], "scraped_at": "2026-11-17T10:00:00+00:00"}), encoding="utf-8"
    )


@pytest.fixture
def data(tmp_path):
    runs = tmp_path / "runs"
    profiles = tmp_path / "latest" / "fighter_profiles.json"
    profiles.parent.mkdir()
    profiles.write_text(
        json.dumps(
            {
                "schema_version": "tapology_fighter_profiles_v0_1",
                "profiles": [
                    profile(1, "Strawweight"),
                    profile(2, "Flyweight"),
                    profile(3, "Bantamweight"),
                    profile(4, "Strawweight", summary={"gender": "female", "gender_evidence": {"status": "explicit", "pronoun": "she"}}),
                    profile(5, "Featherweight"),
                    profile(6, "Lightweight", status="failed_parse"),
                ]
            }
        ),
        encoding="utf-8",
    )
    capture(runs, "20261117T000000Z", 1, SHE)
    capture(runs, "20261120T000000Z", 1, HE)
    capture(runs, "20261117T000000Z", 2, "Fighter 2 is currently ineligible for Tapology's regional MMA rankings.")
    capture(runs, "20261117T000000Z", 4, HE)
    capture(runs, "20261117T000000Z", 5, HE, landed=url(999, "someone-else"))
    return profiles, runs


def summaries(path):
    return {item["source_fighter_id"]: item["profile_summary"] for item in json.loads(path.read_text(encoding="utf-8"))["profiles"]}


def test_dry_run_reports_without_writing(data):
    profiles, runs = data
    before = profiles.read_bytes()

    report = backfill_gender_evidence(profiles, runs)

    assert report["mode"] == "dry_run"
    assert report["profiles_without_evidence"] == 4
    assert report["evidence_added"] == 2
    assert report["no_usable_capture"] == 2
    assert report["gender_male"] == 1 and report["gender_none"] == 1
    assert report["backup"] is None
    assert profiles.read_bytes() == before
    assert list(profiles.parent.glob("*.bak-gender-*")) == []


def test_apply_writes_evidence_from_the_newest_capture_and_keeps_a_backup(data):
    profiles, runs = data
    before = profiles.read_bytes()

    report = backfill_gender_evidence(profiles, runs, apply=True)
    after = summaries(profiles)

    assert after["tapology_fighter_1"]["gender"] == "male"
    assert after["tapology_fighter_1"]["gender_evidence"]["pronoun"] == "he"
    assert after["tapology_fighter_2"]["gender"] is None
    assert after["tapology_fighter_2"]["gender_evidence"]["status"] == "ineligible"
    assert "gender_evidence" not in after["tapology_fighter_3"]
    assert (profiles.parent / report["backup"].rsplit("/", 1)[-1]).read_bytes() == before


def test_existing_evidence_and_captures_of_another_page_are_left_alone(data):
    profiles, runs = data

    backfill_gender_evidence(profiles, runs, apply=True)
    after = summaries(profiles)

    assert after["tapology_fighter_4"] == {"weight_class": "Strawweight", "gender": "female", "gender_evidence": {"status": "explicit", "pronoun": "she"}}
    assert "gender_evidence" not in after["tapology_fighter_5"]
    assert "gender_evidence" not in after["tapology_fighter_6"]


def test_a_second_run_changes_nothing(data):
    profiles, runs = data
    backfill_gender_evidence(profiles, runs, apply=True)
    written = profiles.read_bytes()

    report = backfill_gender_evidence(profiles, runs, apply=True)

    assert report.get("evidence_added", 0) == 0
    assert report["backup"] is None
    assert profiles.read_bytes() == written


def queue_profiles(tmp_path, items):
    path = tmp_path / "fighter_profiles.json"
    path.write_text(json.dumps({"profiles": items}), encoding="utf-8")
    return path


def test_queue_lists_only_profiles_whose_class_cannot_settle_the_gender(tmp_path):
    path = queue_profiles(
        tmp_path,
        [
            profile(1, "Lightweight", fights=[{"fight_date": "2026-11-01"}]),
            profile(2, "Strawweight", fights=[{"fight_date": "2020-01-01"}]),
            profile(3, "Welterweight"),
            profile(4, None),
            profile(5, "Flyweight", fights=[{"fight_date": "2026-11-15"}]),
            profile(6, "Atom", fights=[{"fight_date": "2026-05-01"}]),
            profile(7, "Bantamweight", summary={"gender": None, "gender_evidence": {"status": "ineligible"}}),
            profile(8, "Featherweight", fetch_scope="career_record"),
            profile(9, "Flyweight", status="blocked"),
        ],
    )
    output = tmp_path / "queue.json"

    report = build_gender_evidence_queue(path, output)
    queue = json.loads(output.read_text(encoding="utf-8"))

    assert queue["schema_version"] == "fighter_profile_queue_v0_1"
    assert queue["created_at"]
    assert [item["source_fighter_id"] for item in queue["queue"]] == [
        "tapology_fighter_6",
        "tapology_fighter_2",
        "tapology_fighter_4",
        "tapology_fighter_5",
        "tapology_fighter_1",
        "tapology_fighter_8",
    ]
    assert [item["priority"] for item in queue["queue"]] == [95, 95, 90, 70, 70, 70]
    assert all(item["should_refresh"] is True and item["stale_reason"] == "gender_evidence_missing" for item in queue["queue"])
    assert report["count_by_priority"] == {"95": 2, "90": 1, "70": 3}
    assert report["unique_profile_urls"] == 6


def test_queue_never_narrows_a_whole_career_profile(tmp_path):
    path = queue_profiles(tmp_path, [profile(1, "Flyweight"), profile(2, "Flyweight", fetch_scope="career_record"), profile(3, "Flyweight", fetch_scope="professional_mma_career")])
    output = tmp_path / "queue.json"

    build_gender_evidence_queue(path, output)
    scopes = {item["source_fighter_id"]: item["fetch_scope"] for item in json.loads(output.read_text(encoding="utf-8"))["queue"]}

    assert scopes == {"tapology_fighter_1": "professional_mma_career", "tapology_fighter_2": "career_record", "tapology_fighter_3": "professional_mma_career"}


def test_queue_respects_tracking_decisions_and_is_not_overwritten(tmp_path):
    path = queue_profiles(tmp_path, [profile(1, "Flyweight"), profile(2, "Flyweight")])
    decisions = tmp_path / "tracking.json"
    decisions.write_text(
        json.dumps({"schema_version": "mmaf_tracking_decisions_v1", "revision": "r1", "fighters": [{"source_fighter_id": "tapology_fighter_2", "tracking_mode": "opponent_only"}]}),
        encoding="utf-8",
    )
    output = tmp_path / "queue.json"

    report = build_gender_evidence_queue(path, output, tracking_decisions_path=decisions)

    assert [item["source_fighter_id"] for item in json.loads(output.read_text(encoding="utf-8"))["queue"]] == ["tapology_fighter_1"]
    assert report["tracking_skipped_count"] == 1 and report["tracking_decisions_revision"] == "r1"
    with pytest.raises(FileExistsError):
        build_gender_evidence_queue(path, output)
