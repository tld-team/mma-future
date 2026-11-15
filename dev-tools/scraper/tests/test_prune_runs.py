from __future__ import annotations

from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import tarfile

import pytest

from mma_future_scraper.cli import main
from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.fighter_profiles import FighterProfileEnricher
from mma_future_scraper.prune_runs import PruneError, prune_runs
from mma_future_scraper.storage import DataStore

NOW = datetime(2026, 11, 15, 12, 0, tzinfo=timezone.utc)
PROTECTED = ("state", "latest", "derived", "upcoming")


def _run_id(delta: timedelta, now: datetime = NOW) -> str:
    return (now - delta).strftime("%Y%m%dT%H%M%SZ")


def _write(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(payload if isinstance(payload, str) else json.dumps(payload), encoding="utf-8")


def _tree_digest(root: Path) -> dict[str, str]:
    return {
        str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
        for p in sorted(root.rglob("*"))
        if p.is_file()
    }


def _event_run(runs: Path, run_id: str) -> Path:
    run = runs / run_id
    _write(run / "run_manifest.json", {"run_id": run_id, "status": "success"})
    _write(run / "results.json", {"run_id": run_id, "events": []})
    _write(run / "debug.json", {"attempt": run_id})
    _write(run / "raw_debug" / "page.html", "<html>" + "x" * 100 + "</html>")
    _write(run / "fighter_profiles_report.json", {"cumulative": True, "pad": "y" * 500})
    return run


def _profile_run(runs: Path, run_id: str, snapshots: dict[str, str]) -> Path:
    run = runs / run_id
    _write(run / "fighter_profiles.json", {"run_id": run_id, "profiles": []})
    _write(run / "fighter_profiles_report.json", {"run_id": run_id, "pad": "z" * 500})
    _write(run / "fighter_profiles_report.md", "# report\n")
    for fighter, marker in snapshots.items():
        _write(run / "profile_snapshots" / f"{fighter}.json", {"marker": marker})
    return run


@pytest.fixture
def data_dir(tmp_path: Path) -> dict:
    data = tmp_path / "data"
    runs = data / "runs"
    _write(data / "state" / "scraper_state.json", {"state": 1})
    _write(data / "latest" / "fighter_profiles.json", {"profiles": ["cumulative"]})
    _write(data / "latest" / "fighter_profiles_report.json", {"cumulative": True})
    _write(data / "derived" / "fighters.json", {"derived": True})
    _write(data / "upcoming" / "latest" / "upcoming.json", {"upcoming": True})
    _write(data / "upcoming" / "state" / "state.json", {"upcoming_state": True})

    events = [_run_id(timedelta(days=10 + i)) for i in range(7)]  # events[0] newest
    for run_id in events:
        _event_run(runs, run_id)
    old_profiles = _run_id(timedelta(days=30, hours=1))
    newer_profiles = _run_id(timedelta(days=20, hours=1))
    referenced = _run_id(timedelta(days=25, hours=1))
    finalized_ref = _run_id(timedelta(days=26, hours=1))
    young = _run_id(timedelta(hours=3))
    _profile_run(runs, old_profiles, {"111-alpha": "old", "222-beta": "only-old"})
    _profile_run(runs, newer_profiles, {"111-alpha": "new"})
    _profile_run(runs, referenced, {"333-gamma": "referenced"})
    _profile_run(runs, finalized_ref, {"444-delta": "finalized-cycle"})
    _profile_run(runs, young, {"111-alpha": "young"})
    _write(data / "state" / "import-cycles" / "open-cycle.json", {
        "cycle_id": "open-cycle", "status": "open",
        "tasks": {"events-1": {"kind": "events", "status": "pending"},
                  "profiles-1": {"kind": "profiles", "status": "completed", "run_id": referenced}},
    })
    _write(data / "state" / "import-cycles" / "done-cycle.json", {
        "cycle_id": "done-cycle", "status": "finalized",
        "tasks": {"profiles-1": {"kind": "profiles", "status": "completed", "run_id": finalized_ref}},
    })
    return {"data": data, "runs": runs, "events": events, "old_profiles": old_profiles,
            "newer_profiles": newer_profiles, "referenced": referenced, "finalized_ref": finalized_ref, "young": young}


def test_dry_run_reports_plan_and_changes_nothing(data_dir):
    before = _tree_digest(data_dir["data"])
    summary = prune_runs(data_dir["data"], now=NOW, details=True)

    assert _tree_digest(data_dir["data"]) == before
    assert summary["mode"] == "dry_run"
    kept = {row["run_id"]: row["reasons"] for row in summary["kept"]}
    assert set(kept) == {*data_dir["events"][:5], data_dir["referenced"], data_dir["young"]}
    assert kept[data_dir["referenced"]] == ["open_import_cycle:open-cycle"]
    assert kept[data_dir["young"]] == ["younger_than_48h"]
    pruned = {row["run_id"] for row in summary["pruned"]}
    assert pruned == {*data_dir["events"][5:], data_dir["old_profiles"], data_dir["newer_profiles"], data_dir["finalized_ref"]}
    assert summary["snapshot_archive"]["fighters"] == 3
    assert summary["bytes_reclaimable"] > 0
    assert not (data_dir["data"] / "archive").exists()


def test_apply_keeps_required_runs_and_never_touches_cumulative_state(data_dir):
    data, runs = data_dir["data"], data_dir["runs"]
    protected_before = {name: _tree_digest(data / name) for name in PROTECTED}
    kept_runs = [*data_dir["events"][:5], data_dir["referenced"], data_dir["young"]]
    kept_before = {run_id: _tree_digest(runs / run_id) for run_id in kept_runs}

    summary = prune_runs(data, apply=True, now=NOW)

    assert summary["mode"] == "apply"
    assert {name: _tree_digest(data / name) for name in PROTECTED} == protected_before
    assert {run_id: _tree_digest(runs / run_id) for run_id in kept_runs} == kept_before
    for run_id in data_dir["events"][5:]:
        run = runs / run_id
        assert sorted(p.name for p in run.iterdir()) == ["results.json", "run_manifest.json"]
    for run_id in (data_dir["old_profiles"], data_dir["newer_profiles"], data_dir["finalized_ref"]):
        assert sorted(p.name for p in (runs / run_id).iterdir()) == ["fighter_profiles.json"]
    assert summary["bytes_removed"] == summary["bytes_reclaimable"]


def test_archive_contains_newest_snapshot_per_fighter(data_dir):
    summary = prune_runs(data_dir["data"], apply=True, now=NOW)

    archive_path = Path(summary["snapshot_archive"]["path"])
    assert archive_path.parent == data_dir["data"] / "archive"
    with tarfile.open(archive_path, "r:gz") as archive:
        names = sorted(archive.getnames())
        read = lambda name: json.loads(archive.extractfile(name).read())
        assert names == ["archive_manifest.json", "profile_snapshots/111-alpha.json",
                         "profile_snapshots/222-beta.json", "profile_snapshots/444-delta.json"]
        assert read("profile_snapshots/111-alpha.json") == {"marker": "new"}
        assert read("profile_snapshots/222-beta.json") == {"marker": "only-old"}
        assert read("profile_snapshots/444-delta.json") == {"marker": "finalized-cycle"}
        assert read("archive_manifest.json")["fighters"]["111-alpha"] == data_dir["newer_profiles"]
    # The young run's newer snapshot is not pruned and stays on disk.
    assert (data_dir["runs"] / data_dir["young"] / "profile_snapshots" / "111-alpha.json").is_file()


def test_unreadable_import_cycle_aborts_without_deleting(data_dir):
    _write(data_dir["data"] / "state" / "import-cycles" / "broken.json", "{not json")
    before = _tree_digest(data_dir["data"])
    with pytest.raises(PruneError):
        prune_runs(data_dir["data"], apply=True, now=NOW)
    assert _tree_digest(data_dir["data"]) == before


@pytest.mark.parametrize("protected", PROTECTED)
def test_archive_dir_inside_protected_tree_is_refused(data_dir, protected):
    with pytest.raises(PruneError):
        prune_runs(data_dir["data"], apply=True, now=NOW, archive_dir=data_dir["data"] / protected / "archive")


def test_cli_prune_runs_is_dry_run_by_default(tmp_path, capsys):
    data = tmp_path / "data"
    now = datetime.now(timezone.utc)
    _event_run(data / "runs", _run_id(timedelta(days=40), now))
    _profile_run(data / "runs", _run_id(timedelta(days=41), now), {"111-alpha": "a"})
    _write(data / "latest" / "fighter_profiles_report.json", {"cumulative": True})
    before = _tree_digest(data / "runs")

    assert main(["prune-runs", "--data-dir", str(data), "--keep-event-runs", "0"]) == 0
    summary = json.loads(capsys.readouterr().out)
    assert summary["mode"] == "dry_run" and summary["runs_pruned"] == 2
    assert _tree_digest(data / "runs") == before

    assert main(["prune-runs", "--data-dir", str(data), "--keep-event-runs", "0", "--apply"]) == 0
    assert (data / "latest" / "fighter_profiles_report.json").is_file()
    assert len(list((data / "archive").glob("profile_snapshots_*.tar.gz"))) == 1


def _profile(fighter_id: str) -> dict:
    return {"source_url": f"https://www.tapology.com/fightcenter/fighters/{fighter_id}", "status": "success",
            "profile_summary": {"name": fighter_id}}


def test_profile_run_writes_run_scoped_report_and_cumulative_report_only_to_latest(tmp_path, monkeypatch):
    # Any implicit DataStore() also resolves to tmp_path, so a stray cumulative
    # write into runs/<run_id>/ would be visible here.
    monkeypatch.setattr(DataStore.__init__, "__defaults__", (tmp_path,))
    store = DataStore(tmp_path)
    _write(store.latest_dir / "fighter_profiles.json", {
        "schema_version": "tapology_fighter_profiles_v0_1", "run_id": "20261101T000000Z",
        "profiles": [_profile("1-existing-one"), _profile("2-existing-two")],
    })
    run_id = "20261115T000000Z"
    run_dir = store.runs_dir / run_id
    run_dir.mkdir(parents=True)
    enricher = FighterProfileEnricher(ScraperConfig())
    enricher._store = store
    document = {"schema_version": "tapology_fighter_profiles_v0_1", "run_id": run_id, "profiles": [_profile("3-new")]}

    result = enricher._write_document(document, run_dir, started_clock=0.0)

    run_report = json.loads((run_dir / "fighter_profiles_report.json").read_text())
    latest_report = json.loads((store.latest_dir / "fighter_profiles_report.json").read_text())
    assert run_report["profiles_total"] == 1
    assert latest_report["profiles_total"] == 3
    assert result["report_paths"] == {
        "run_json": str(run_dir / "fighter_profiles_report.json"),
        "latest_json": str(store.latest_dir / "fighter_profiles_report.json"),
    }
    assert sorted(p.name for p in store.runs_dir.iterdir()) == [run_id]
