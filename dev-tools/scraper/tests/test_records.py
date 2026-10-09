from mma_future_scraper.utils import parse_date_to_iso


def test_parse_date_to_iso():
    assert parse_date_to_iso("Saturday, May 9, 2026") == "2026-05-09"


def test_parse_date_rejects_no_year():
    assert parse_date_to_iso("Saturday, May 9") is None
