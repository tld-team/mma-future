from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import os


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
DATA_DIR = Path(os.environ.get('MMAF_SCRAPER_DATA_DIR',PACKAGE_ROOT / 'data')).expanduser().resolve()
LATEST_DIR = DATA_DIR / "latest"
RUNS_DIR = DATA_DIR / "runs"
DEBUG_DIR = DATA_DIR / "debug"
STATE_DIR = DATA_DIR / "state"
STATE_FILE = STATE_DIR / "scraper_state.json"
EVENTS_INDEX_FILE = STATE_DIR / "events_index.json"
BOUTS_INDEX_FILE = STATE_DIR / "bouts_index.json"
INCOMPLETE_EVENTS_FILE = STATE_DIR / "incomplete_events.json"
EVENT_PAYLOADS_FILE = STATE_DIR / "event_payloads.json"
FIGHTCENTER_URL = "https://www.tapology.com/fightcenter"
BROWSER_ENGINES = ("chromium", "firefox", "webkit")
BROWSER_CHANNELS = ("chrome", "chrome-beta", "chrome-dev", "msedge", "msedge-beta", "msedge-dev")


@dataclass(frozen=True)
class ScraperConfig:
    source: str = "tapology"
    source_url: str = FIGHTCENTER_URL
    run_mode: str = "daily"
    promotion_scope: str = "all"
    sport: str = "mma"
    exclude_promotions: tuple[str, ...] = ("ufc",)
    browser_engine: str = "chromium"
    browser_channel: str | None = None
    browser_executable_path: Path | None = None
    headless: bool = True
    slow_mo: int = 0
    max_pages: int | None = None
    max_events: int | None = None
    from_date: str | None = None
    recheck_days: int = 14
    recheck_complete_days: int = 0
    recheck_known_limit: int = 0
    stop_after_known_complete: int = 20
    force_recheck_events: tuple[str, ...] = ()
    force_full_scan: bool = False
    skip_known_complete: bool = False
    cumulative_results: bool = False
    fresh_results: bool = False
    enrich_fighter_profiles: bool | None = None
    use_profile_cache: bool = True
    profile_cache_ttl_days: int | None = 7
    page_timeout_ms: int = 30_000
    action_timeout_ms: int = 12_000
    conservative_delay_ms: int = 750
    event_delay_ms: int = 1_000
    page_pause_seconds: float = 0.0
    profile_pause_seconds: float | None = None
    event_pause_seconds: float = 0.0
    jitter_seconds: float = 0.0
    max_profile_fetches: int | None = None
    profile_batch_size: int | None = None
    profile_batch_pause_seconds: float = 0.0
    max_consecutive_access_failures: int | None = None
    max_profile_failure_rate: float | None = None
    max_event_fetch_failures: int | None = None
    stop_on_access_degradation: bool = False
    load_more_post_click_wait_ms: int = 1_500
    load_more_wait_timeout_ms: int = 20_000
    load_more_popup_wait_ms: int = 1_500
    load_more_overlay_check_rounds: int = 5
    load_more_overlay_check_interval_ms: int = 700
    debug: bool = False
    wait_for_manual_challenge_seconds: int = 0
    pause_on_manual_challenge: bool = False
    manual_challenge_poll_interval_ms: int = 2_000
    browser_profile_dir: Path | None = None
    operator_navigates_to_fightcenter: bool = False
    use_current_page: bool = False
