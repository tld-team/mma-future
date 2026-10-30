from mma_future_scraper.cli import main
import pytest


def test_run_rejects_cumulative_and_fresh_results(capsys):
    result = main(["run", "--results-only", "--cumulative-results", "--fresh-results"])

    captured = capsys.readouterr()
    assert result == 2
    assert "--cumulative-results cannot be combined with --fresh-results" in captured.err


@pytest.mark.parametrize("extra", [["--upcoming-only"], ["--results-only", "--fresh-results"],
                                   ["--results-only", "--force-full-scan"],
                                   ["--results-only", "--force-recheck-event", "tapology_event_1"]])
def test_resume_rejects_incompatible_modes_before_browser_launch(extra, capsys):
    assert main(["run", "--resume-results-from", "checkpoint_results.json", *extra]) == 2
    assert "results resume requires" in capsys.readouterr().err


def test_event_card_only_options_reach_the_scraper_in_headed_mode(monkeypatch):
    observed = {}
    class Scraper:
        def __init__(self, config):
            observed["config"] = config
        def run(self, **kwargs):
            return {"successful": True}
    monkeypatch.setattr("mma_future_scraper.cli.TapologyScraper", Scraper)
    assert main(["run", "--results-only", "--headed", "--no-scrape-matchup-pages", "--empty-results-cooldown-hours", "24"]) == 0
    assert observed["config"].headless is False
    assert observed["config"].scrape_matchup_pages is False
    assert observed["config"].empty_results_cooldown_hours == 24
