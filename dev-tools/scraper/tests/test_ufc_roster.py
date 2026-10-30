from __future__ import annotations

import hashlib
import json
import zipfile

import pytest

from mma_future_scraper.import_bundle import export_ufc_roster_bundle
from mma_future_scraper.ufc_roster import build_document, parse_roster_rows, validate_live_document


def sample_document():
    current=[{"name":"Željko Petrović","url":"/fighters/zeljko-petrovic","cells":["Željko Petrović","Lightweight"]},
             {"name":"Zero Bout","url":"/fighters/zero-bout","cells":["Zero Bout","0"]}]
    former=[{"name":"Former Fighter","url":"/fighters/former-fighter","cells":["Former Fighter"]}]
    log=[{"name":"Željko Petrović","url":"/fighters/zeljko-petrovic","text":"✅ Fighter added: Željko Petrović","date":"October 19, 2026"}]
    return build_document(current,former,log,run_id="ufc-test",scraped_at="2026-10-20T10:00:00Z")


def test_build_document_preserves_zero_bout_and_missing_fields():
    doc=sample_document()
    assert doc["counts"]=={"current":2,"former":1,"log_events":1,"fighters":3}
    zero=next(row for row in doc["fighters"] if row["display_name"]=="Zero Bout")
    assert zero["roster_status"]=="current"
    assert zero["date_of_birth"] is None
    signed=next(row for row in doc["fighters"] if row["display_name"]=="Željko Petrović")
    assert signed["events"][0]["action"]=="added"
    assert doc["schema_version"]=="mmaf_ufc_roster_v2"
    assert signed["events"][0]["source_url"]=="https://www.roster.watch/fighters/zeljko-petrovic"
    body=dict(doc);checksum=body.pop("content_sha256")
    assert checksum==hashlib.sha256(json.dumps(body,ensure_ascii=False,separators=(",",":")).encode()).hexdigest()


def test_document_requires_all_three_nonempty_pages():
    with pytest.raises(ValueError,match="current roster"):
        build_document([], [{"name":"Former","url":"/fighters/former"}], [{"name":"Former","url":"/fighters/former","text":"removed"}],run_id="bad",scraped_at="2026-10-20T10:00:00Z")


def test_live_validation_rejects_partial_page_and_large_drop():
    partial = {"counts": {"current": 99, "former": 600, "log_events": 200}}
    with pytest.raises(ValueError, match="current page appears incomplete"):
        validate_live_document(partial)

    current = {"counts": {"current": 500, "former": 1200, "log_events": 800}}
    previous = {"counts": {"current": 700, "former": 2100, "log_events": 1300}}
    with pytest.raises(ValueError, match="former count dropped unexpectedly"):
        validate_live_document(current, previous)


def test_duplicate_current_and_former_prefers_current():
    doc=build_document([{"name":"Same Fighter","url":"/fighters/same"}],[{"name":"Same Fighter","url":"/fighters/same"}],[{"name":"Same Fighter","url":"/fighters/same","text":"added"}],run_id="same",scraped_at="2026-10-20T10:00:00Z")
    assert len(doc["fighters"])==1
    assert doc["fighters"][0]["roster_status"]=="current"


def test_log_only_addition_is_unknown_and_has_a_log_evidence_link():
    doc=build_document([{"name":"Current","url":"/fighters/current"}],[{"name":"Former","url":"/fighters/former"}],[{"name":"New Signing","text":"✅ Fighter added: New Signing","date":"2026-10-25"}],run_id="log-only",scraped_at="2026-10-25T00:00:00Z")
    row=next(row for row in doc["fighters"] if row["display_name"]=="New Signing")
    assert row["roster_status"]=="log_only"
    assert row["source_url"]=="https://www.roster.watch/log.html"
    assert row["events"]==[{"date":"2026-10-25","action":"added","text":"✅ Fighter added: New Signing","source_url":"https://www.roster.watch/log.html"}]


def test_name_only_log_does_not_merge_with_same_named_profile():
    doc=build_document([{"name":"Same Name","url":"/fighters/first"}],[{"name":"Former","url":"/fighters/former"}],[{"name":"Same Name","text":"✅ Fighter added: Same Name"}],run_id="separate",scraped_at="2026-10-25T00:00:00Z")
    same=[row for row in doc["fighters"] if row["display_name"]=="Same Name"]
    assert len(same)==2
    assert {row["roster_status"] for row in same}=={"current","log_only"}


def test_name_only_current_former_and_log_remain_separate():
    doc=build_document([{"name":"Same Name"}],[{"name":"Same Name"}],[{"name":"Same Name","text":"removed"}],run_id="name-only",scraped_at="2026-10-25T00:00:00Z")
    assert len(doc["fighters"])==3
    assert {row["roster_status"] for row in doc["fighters"]}=={"current","former","log_only"}
    assert all(row["source_url"] for row in doc["fighters"])


def test_standalone_bundle_has_manifest_and_checksums(tmp_path):
    latest=tmp_path/"latest";latest.mkdir();doc=sample_document()
    (latest/"ufc_roster.json").write_text(json.dumps(doc,ensure_ascii=False),encoding="utf-8")
    output=tmp_path/"ufc.zip";result=export_ufc_roster_bundle(output,latest_dir=latest)
    assert result["mode"]=="ufc_roster"
    with zipfile.ZipFile(output) as archive:
        names=set(archive.namelist());assert names=={"latest/ufc_roster.json","latest/package_manifest.json"}
        manifest=json.loads(archive.read("latest/package_manifest.json"))
        roster=archive.read("latest/ufc_roster.json")
        assert manifest["mode"]=="ufc_roster"
        assert manifest["files"]["ufc_roster.json"]["sha256"]==hashlib.sha256(roster).hexdigest()


def test_parser_keeps_unicode_name_and_optional_dob():
    rows=parse_roster_rows([{"name":"  João  Silva ","url":"/fighters/joao","date_of_birth":"October 1, 1999","cells":[]}],"current")
    assert rows[0]["display_name"]=="João Silva"
    assert rows[0]["date_of_birth"]=="1999-10-01"


def test_failed_fetch_preserves_good_snapshot_and_exposes_status(tmp_path, monkeypatch):
    from mma_future_scraper import ufc_roster

    latest=tmp_path/"latest";latest.mkdir()
    good=sample_document();(latest/"ufc_roster.json").write_text(json.dumps(good),encoding="utf-8")
    monkeypatch.setattr(ufc_roster,"RUNS_DIR",tmp_path/"runs")
    def blocked(*args,**kwargs):
        raise RuntimeError("source blocked")
    monkeypatch.setattr(ufc_roster,"launch_browser",blocked)
    with pytest.raises(RuntimeError,match="source blocked"):
        ufc_roster.check_ufc_roster(latest_dir=latest)
    assert json.loads((latest/"ufc_roster.json").read_text())==good
    status=json.loads((latest/"ufc_roster_status.json").read_text())
    assert status["status"]=="failed" and status["snapshot_run_id"]==good["run_id"]
