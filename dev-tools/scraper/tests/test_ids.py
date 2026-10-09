from mma_future_scraper.contract import (
    source_bout_numeric_id,
    source_event_numeric_id,
    source_fighter_numeric_id,
    source_promotion_id_from_url,
    source_promotion_numeric_id_from_url,
)
from mma_future_scraper.utils import (
    canonical_url,
    fallback_bout_id,
    source_bout_id_from_url,
    source_event_id_from_url,
    source_fighter_id_from_url,
    url_hash,
)


def test_source_event_id_from_url():
    assert (
        source_event_id_from_url("https://www.tapology.com/fightcenter/events/12345-example-event")
        == "tapology_event_12345"
    )


def test_source_event_numeric_id_from_url():
    assert source_event_numeric_id("https://www.tapology.com/fightcenter/events/12345-example-event") == "12345"


def test_source_bout_id_from_url():
    assert (
        source_bout_id_from_url("https://www.tapology.com/fightcenter/bouts/98765-fighter-a-vs-fighter-b")
        == "tapology_bout_98765"
    )


def test_source_bout_numeric_id_from_url():
    assert source_bout_numeric_id("https://www.tapology.com/fightcenter/bouts/98765-fighter-a-vs-fighter-b") == "98765"


def test_source_fighter_id_from_url():
    assert (
        source_fighter_id_from_url("https://www.tapology.com/fightcenter/fighters/123456-jane-doe")
        == "tapology_fighter_123456"
    )
    assert source_fighter_id_from_url("https://www.tapology.com/fightcenter/fighters/rin-nakai").startswith(
        "tapology_fighter_url_"
    )


def test_slug_only_fighter_url_keeps_canonical_url_identity():
    assert (
        canonical_url("https://www.TAPOLOGY.com/fightcenter/fighters/rin-nakai/?x=1#profile")
        == "https://www.tapology.com/fightcenter/fighters/rin-nakai"
    )
    assert url_hash("https://www.tapology.com/fightcenter/fighters/rin-nakai/") == url_hash(
        "https://www.tapology.com/fightcenter/fighters/rin-nakai"
    )


def test_source_fighter_numeric_id_from_url():
    assert source_fighter_numeric_id("https://www.tapology.com/fightcenter/fighters/123456-jane-doe") == "123456"


def test_slug_only_fighter_urls_get_stable_non_numeric_source_ids():
    first = source_fighter_id_from_url("https://www.tapology.com/fightcenter/fighters/derek-campos")
    second = source_fighter_id_from_url("https://www.tapology.com/fightcenter/fighters/derek-campos/")
    other = source_fighter_id_from_url("https://www.tapology.com/fightcenter/fighters/rin-nakai")

    assert first == second
    assert first.startswith("tapology_fighter_url_")
    assert other.startswith("tapology_fighter_url_")
    assert first != other
    assert source_fighter_numeric_id("https://www.tapology.com/fightcenter/fighters/derek-campos", first) is None


def test_source_promotion_ids_from_url():
    url = "https://www.tapology.com/fightcenter/promotions/2729-rookie-fighting-championship-rfc"
    assert source_promotion_id_from_url(url) == "tapology_promotion_2729"
    assert source_promotion_numeric_id_from_url(url) == "2729"


def test_url_hash_is_stable_for_trailing_slash():
    assert url_hash("https://www.tapology.com/fightcenter/events/1/") == url_hash(
        "https://www.tapology.com/fightcenter/events/1"
    )


def test_fallback_bout_hash_is_order_independent():
    first = fallback_bout_id(
        event_url="https://www.tapology.com/fightcenter/events/1-test",
        event_date="2026-05-12",
        fighter_a_name="Jane Doe",
        fighter_b_name="Ann Other",
        weight_class="Flyweight",
        result_or_method="Decision",
    )
    second = fallback_bout_id(
        event_url="https://www.tapology.com/fightcenter/events/1-test",
        event_date="2026-05-12",
        fighter_a_name="Ann Other",
        fighter_b_name="Jane Doe",
        weight_class="Flyweight",
        result_or_method="Decision",
    )
    assert first == second
