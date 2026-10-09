from mma_future_scraper.cli import main


def test_run_rejects_cumulative_and_fresh_results(capsys):
    result = main(["run", "--results-only", "--cumulative-results", "--fresh-results"])

    captured = capsys.readouterr()
    assert result == 2
    assert "--cumulative-results cannot be combined with --fresh-results" in captured.err
