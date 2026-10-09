from mma_future_scraper.dedupe import build_success_state, dedupe_events, empty_state, mark_new_flags


def test_dedupe_events_and_bouts():
    events = [
        {
            "source_event_id": "event-1",
            "event_url": "https://example.test/events/1",
            "bouts": [
                {"source_bout_id": "bout-1", "bout_url": "https://example.test/bouts/1"},
                {"source_bout_id": "bout-1", "bout_url": "https://example.test/bouts/1"},
            ],
        },
        {"source_event_id": "event-1", "event_url": "https://example.test/events/1", "bouts": []},
    ]
    unique = dedupe_events(events)
    assert len(unique) == 1
    assert len(unique[0]["bouts"]) == 1


def test_mark_new_flags_against_state():
    state = empty_state()
    state["known_event_ids"] = ["event-1"]
    state["known_bout_ids"] = ["bout-1"]
    marked = mark_new_flags(
        [{"source_event_id": "event-1", "bouts": [{"source_bout_id": "bout-1"}, {"source_bout_id": "bout-2"}]}],
        state,
    )
    assert marked[0]["is_new_since_last_successful_run"] is False
    assert marked[0]["bouts"][0]["is_new_since_last_successful_run"] is False
    assert marked[0]["bouts"][1]["is_new_since_last_successful_run"] is True


def test_build_success_state_accumulates_ids():
    state = build_success_state(
        prior_state=empty_state(),
        run_id="run-1",
        finished_at="2026-05-12T00:00:00+00:00",
        output_documents=[
            {
                "events": [
                    {
                        "source_event_id": "event-1",
                        "event_url": "https://example.test/events/1",
                        "bouts": [{"source_bout_id": "bout-1", "bout_url": "https://example.test/bouts/1"}],
                    }
                ]
            }
        ],
    )
    assert state["last_successful_run_id"] == "run-1"
    assert state["known_event_ids"] == ["event-1"]
    assert state["known_bout_ids"] == ["bout-1"]
