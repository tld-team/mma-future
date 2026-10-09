from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from mma_future_scraper import browser as browser_mod
from mma_future_scraper import tapology as tap_mod
from mma_future_scraper import cli as cli_mod
from mma_future_scraper.browser import (
    detect_hard_block,
    is_manual_solvable_challenge,
    wait_for_manual_challenge,
)
from mma_future_scraper.config import ScraperConfig
from mma_future_scraper.storage import DataStore
from mma_future_scraper.tapology import FatalScrapeError, TapologyScraper


class _FakeLocator:
    def __init__(self, present: bool, text: str = "") -> None:
        self._present = present
        self._text = text
        self.selected_values: list[str] = []

    def count(self) -> int:
        return 1 if self._present else 0

    def inner_text(self, timeout: int | None = None) -> str:
        return self._text

    @property
    def first(self) -> "_FakeLocator":
        return self

    def is_visible(self, timeout: int | None = None) -> bool:
        return False

    def click(self, timeout: int | None = None) -> None:
        return None

    def select_option(self, value: str | None = None, **kwargs: Any) -> None:
        self.selected_values.append(str(value))


class _ScriptedPage:
    """Fake Playwright page driven by a list of (title, body) snapshots.

    Each call to detect_hard_block consumes one snapshot pair (title via title(),
    body via locator('body').inner_text()). When the script is exhausted the
    last snapshot is repeated indefinitely.
    """

    def __init__(self, snapshots: list[tuple[str, str]]):
        self._snapshots = list(snapshots) or [("", "")]
        self._step = 0
        self._pending_body = ""
        self.wait_ms_log: list[int] = []
        self.goto_log: list[str] = []
        self.url = "https://www.tapology.com/fightcenter"

    def _advance(self) -> tuple[str, str]:
        if self._step >= len(self._snapshots):
            return self._snapshots[-1]
        snap = self._snapshots[self._step]
        self._step += 1
        return snap

    def title(self) -> str:
        title, body = self._advance()
        self._pending_body = body
        return title

    def locator(self, selector: str) -> _FakeLocator:
        if selector == "body":
            return _FakeLocator(True, self._pending_body)
        return _FakeLocator(False)

    def get_by_role(self, role: str, name: str | None = None, exact: bool | None = None) -> _FakeLocator:
        return _FakeLocator(False)

    def goto(self, url: str, **kwargs: Any) -> None:
        self.goto_log.append(url)
        self.url = url

    def evaluate(self, script: str, *args: Any) -> list[Any]:
        return []

    def wait_for_timeout(self, ms: int) -> None:
        self.wait_ms_log.append(int(ms))
        # Cap real sleep to keep tests fast while still advancing time.monotonic.
        import time as _t

        _t.sleep(min(int(ms), 250) / 1000.0)

    def content(self) -> str:
        return "<html></html>"

    def screenshot(self, path: str | None = None, full_page: bool = False, timeout: int | None = None) -> None:
        if path:
            Path(path).write_bytes(b"")

    def close(self) -> None:
        return None


class _StaticFightCenterPage:
    def __init__(
        self,
        *,
        title: str = "Tapology FightCenter",
        body: str = "FightCenter MMA Events",
        url: str = "https://www.tapology.com/fightcenter",
        has_form: bool = True,
        event_rows: bool = False,
    ) -> None:
        self._title = title
        self._body = body
        self.url = url
        self.has_form = has_form
        self.event_rows = event_rows
        self.wait_ms_log: list[int] = []
        self.goto_log: list[str] = []

    def title(self) -> str:
        return self._title

    def locator(self, selector: str) -> _FakeLocator:
        if selector == "body":
            return _FakeLocator(True, self._body)
        return _FakeLocator(False)

    def evaluate(self, script: str, *args: Any) -> Any:
        if "document.getElementById('fightCenterRefine')" in script:
            if not self.has_form:
                return []
            return [
                {
                    "tag": "select",
                    "selector": "#promotion",
                    "id": "promotion",
                    "name": "promotion",
                    "label": "Promotion",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Promotion",
                    "options": [{"value": "all", "text": "All"}],
                },
                {
                    "tag": "select",
                    "selector": "#schedule",
                    "id": "schedule",
                    "name": "schedule",
                    "label": "Schedule",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Schedule",
                    "options": [{"value": "results", "text": "Results"}],
                },
                {
                    "tag": "select",
                    "selector": "#sport",
                    "id": "sport",
                    "name": "sport",
                    "label": "Sport",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Sport",
                    "options": [{"value": "mma", "text": "MMA"}],
                },
            ]
        return {
            "location_href": self.url,
            "has_fightcenter_form": self.has_form,
            "filter_controls_count": 3 if self.has_form else 0,
            "has_fightcenter_heading": "fightcenter" in self._body.lower(),
            "has_event_listing_rows": self.event_rows,
            "event_listing_links_count": 1 if self.event_rows else 0,
            "body_text_excerpt": self._body,
        }

    def wait_for_function(self, *_args: Any, **_kwargs: Any) -> None:
        if not self.has_form:
            raise TimeoutError("not ready")

    def wait_for_timeout(self, ms: int) -> None:
        self.wait_ms_log.append(int(ms))

    def goto(self, url: str, **kwargs: Any) -> None:
        self.goto_log.append(url)
        self.url = url

    def content(self) -> str:
        return f"<html><title>{self._title}</title><body>{self._body}</body></html>"

    def screenshot(self, path: str | None = None, full_page: bool = False, timeout: int | None = None) -> None:
        if path:
            Path(path).write_bytes(b"")


class _OperatorConfirmPage:
    def __init__(self, states: list[dict[str, Any]]) -> None:
        self._states = states
        self._index = 0
        self.url = states[0].get("url", "https://www.tapology.com/fightcenter")
        self.interactions: list[str] = []
        self.goto_log: list[str] = []
        self.wait_ms_log: list[int] = []

    @property
    def state(self) -> dict[str, Any]:
        return self._states[min(self._index, len(self._states) - 1)]

    def advance(self) -> None:
        self._index = min(self._index + 1, len(self._states) - 1)
        self.url = self.state.get("url", self.url)

    def title(self) -> str:
        self.interactions.append("title")
        return self.state.get("title", "")

    def locator(self, selector: str) -> _FakeLocator:
        self.interactions.append(f"locator:{selector}")
        if selector == "body":
            return _FakeLocator(True, self.state.get("body", ""))
        return _FakeLocator(False)

    def evaluate(self, script: str, *args: Any) -> Any:
        self.interactions.append("evaluate")
        state = self.state
        has_form = bool(state.get("has_form"))
        has_rows = bool(state.get("event_rows"))
        if "document.getElementById('fightCenterRefine')" in script:
            if not has_form:
                return []
            return [
                {
                    "tag": "select",
                    "selector": "#promotion",
                    "id": "promotion",
                    "name": "promotion",
                    "label": "Promotion",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Promotion",
                    "value": state.get("promotion_value", "all"),
                    "options": [{"value": "all", "text": state.get("promotion_text", "All"), "selected": True}],
                },
                {
                    "tag": "select",
                    "selector": "#schedule",
                    "id": "schedule",
                    "name": "schedule",
                    "label": "Schedule",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Schedule",
                    "value": state.get("schedule_value", "results"),
                    "options": [{"value": state.get("schedule_value", "results"), "text": state.get("schedule_text", "Results"), "selected": True}],
                },
                {
                    "tag": "select",
                    "selector": "#sport",
                    "id": "sport",
                    "name": "sport",
                    "label": "Sport",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Sport",
                    "value": state.get("sport_value", "mma"),
                    "options": [{"value": state.get("sport_value", "mma"), "text": state.get("sport_text", "MMA"), "selected": True}],
                },
                {
                    "tag": "select",
                    "selector": "#region",
                    "id": "region",
                    "name": "region",
                    "label": "Region",
                    "form_id": "fightCenterRefine",
                    "nearby_text": "Region",
                    "value": state.get("region_value", ""),
                    "options": [{"value": state.get("region_value", ""), "text": state.get("region_text", "All"), "selected": True}],
                },
            ]
        return {
            "location_href": state.get("url", self.url),
            "has_fightcenter_form": has_form,
            "filter_controls_count": 3 if has_form else 0,
            "has_fightcenter_heading": "fightcenter" in state.get("body", "").lower(),
            "has_event_listing_rows": has_rows,
            "event_listing_links_count": 1 if has_rows else 0,
            "body_text_excerpt": state.get("body", ""),
        }

    def wait_for_function(self, *_args: Any, **_kwargs: Any) -> None:
        self.interactions.append("wait_for_function")

    def wait_for_timeout(self, ms: int) -> None:
        self.interactions.append("wait_for_timeout")
        self.wait_ms_log.append(int(ms))

    def goto(self, url: str, **kwargs: Any) -> None:
        self.interactions.append("goto")
        self.goto_log.append(url)
        self.url = url

    def content(self) -> str:
        self.interactions.append("content")
        return self.state.get("html", f"<html><title>{self.state.get('title', '')}</title><body>{self.state.get('body', '')}</body></html>")

    def screenshot(self, path: str | None = None, full_page: bool = False, timeout: int | None = None) -> None:
        self.interactions.append("screenshot")
        if path:
            Path(path).write_bytes(b"")

    def close(self) -> None:
        self.interactions.append("close")


def _silent_log(level: str, event: str, **kwargs: Any) -> None:
    return None


def test_scraper_config_default_browser_engine_is_chromium():
    assert ScraperConfig().browser_engine == "chromium"
    assert ScraperConfig().browser_channel is None
    assert ScraperConfig().browser_executable_path is None


def test_cli_run_accepts_browser_engine_allowed_values(monkeypatch):
    captured: list[ScraperConfig] = []

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured.append(config)

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)

    for engine in ["chromium", "firefox", "webkit"]:
        exit_code = cli_mod.main(["run", "--results-only", "--browser-engine", engine])
        assert exit_code == 0

    assert [config.browser_engine for config in captured] == ["chromium", "firefox", "webkit"]


def test_cli_run_accepts_installed_chromium_browser_channel(monkeypatch):
    captured: list[ScraperConfig] = []

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured.append(config)

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)

    exit_code = cli_mod.main(["run", "--results-only", "--browser-channel", "msedge"])

    assert exit_code == 0
    assert captured[0].browser_engine == "chromium"
    assert captured[0].browser_channel == "msedge"


def test_cli_run_accepts_browser_executable_path(monkeypatch, tmp_path):
    captured: list[ScraperConfig] = []

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured.append(config)

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    browser_path = tmp_path / "browser.exe"

    exit_code = cli_mod.main(["run", "--results-only", "--browser-executable-path", str(browser_path)])

    assert exit_code == 0
    assert captured[0].browser_engine == "chromium"
    assert captured[0].browser_channel is None
    assert captured[0].browser_executable_path == browser_path


def test_cli_rejects_invalid_browser_engine():
    parser = cli_mod.build_parser()
    with pytest.raises(SystemExit):
        parser.parse_args(["run", "--browser-engine", "edge"])


def test_cli_rejects_invalid_browser_channel():
    parser = cli_mod.build_parser()
    with pytest.raises(SystemExit):
        parser.parse_args(["run", "--browser-channel", "opera"])


def test_browser_launch_uses_selected_engine(monkeypatch):
    launched: dict[str, Any] = {}

    class _LaunchContext:
        def set_default_timeout(self, ms: int) -> None:
            launched["timeout"] = ms

    class _LaunchBrowser:
        def new_context(self, **kwargs: Any) -> _LaunchContext:
            launched["new_context"] = kwargs
            return _LaunchContext()

    class _BrowserType:
        def __init__(self, name: str) -> None:
            self.name = name

        def launch(self, **kwargs: Any) -> _LaunchBrowser:
            launched["engine"] = self.name
            launched["launch"] = kwargs
            return _LaunchBrowser()

    class _Playwright:
        chromium = _BrowserType("chromium")
        firefox = _BrowserType("firefox")
        webkit = _BrowserType("webkit")

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    playwright, browser, context = browser_mod.launch_browser(ScraperConfig(browser_engine="firefox", slow_mo=25))

    assert playwright is not None
    assert browser is not None
    assert context is not None
    assert launched["engine"] == "firefox"
    assert launched["launch"] == {"headless": True, "slow_mo": 25}
    assert launched["new_context"] == {"viewport": {"width": 1365, "height": 900}}
    assert launched["timeout"] == ScraperConfig().action_timeout_ms


def test_browser_launch_uses_installed_chromium_channel(monkeypatch):
    launched: dict[str, Any] = {}

    class _LaunchContext:
        def set_default_timeout(self, ms: int) -> None:
            launched["timeout"] = ms

    class _LaunchBrowser:
        def new_context(self, **kwargs: Any) -> _LaunchContext:
            launched["new_context"] = kwargs
            return _LaunchContext()

    class _BrowserType:
        def launch(self, **kwargs: Any) -> _LaunchBrowser:
            launched["launch"] = kwargs
            return _LaunchBrowser()

    class _Playwright:
        chromium = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    playwright, browser, context = browser_mod.launch_browser(ScraperConfig(browser_channel="msedge"))

    assert playwright is not None
    assert browser is not None
    assert context is not None
    assert launched["launch"] == {"headless": True, "slow_mo": 0, "channel": "msedge"}
    assert launched["new_context"] == {"viewport": {"width": 1365, "height": 900}}
    assert launched["timeout"] == ScraperConfig().action_timeout_ms


def test_browser_launch_uses_browser_executable_path(monkeypatch, tmp_path):
    launched: dict[str, Any] = {}

    class _LaunchContext:
        def set_default_timeout(self, ms: int) -> None:
            launched["timeout"] = ms

    class _LaunchBrowser:
        def new_context(self, **kwargs: Any) -> _LaunchContext:
            launched["new_context"] = kwargs
            return _LaunchContext()

    class _BrowserType:
        def launch(self, **kwargs: Any) -> _LaunchBrowser:
            launched["launch"] = kwargs
            return _LaunchBrowser()

    class _Playwright:
        chromium = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())
    browser_path = tmp_path / "brave.exe"

    playwright, browser, context = browser_mod.launch_browser(
        ScraperConfig(browser_executable_path=browser_path)
    )

    assert playwright is not None
    assert browser is not None
    assert context is not None
    assert launched["launch"] == {"headless": True, "slow_mo": 0, "executable_path": str(browser_path)}
    assert launched["new_context"] == {"viewport": {"width": 1365, "height": 900}}
    assert launched["timeout"] == ScraperConfig().action_timeout_ms


def test_browser_launch_rejects_executable_path_combined_with_channel(monkeypatch, tmp_path):
    launched: dict[str, Any] = {}

    class _BrowserType:
        pass

    class _Playwright:
        chromium = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    with pytest.raises(RuntimeError) as excinfo:
        browser_mod.launch_browser(
            ScraperConfig(browser_channel="chrome", browser_executable_path=tmp_path / "chrome.exe")
        )

    assert "--browser-executable-path cannot be combined with --browser-channel" in str(excinfo.value)
    assert launched["stopped"] is True


def test_browser_launch_rejects_executable_path_with_non_chromium_engine(monkeypatch, tmp_path):
    launched: dict[str, Any] = {}

    class _BrowserType:
        pass

    class _Playwright:
        chromium = _BrowserType()
        firefox = _BrowserType()
        webkit = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    with pytest.raises(RuntimeError) as excinfo:
        browser_mod.launch_browser(
            ScraperConfig(browser_engine="firefox", browser_executable_path=tmp_path / "firefox.exe")
        )

    assert "--browser-executable-path can only be used with --browser-engine chromium" in str(excinfo.value)
    assert launched["stopped"] is True


def test_browser_launch_rejects_channel_with_non_chromium_engine(monkeypatch):
    launched: dict[str, Any] = {}

    class _BrowserType:
        pass

    class _Playwright:
        chromium = _BrowserType()
        firefox = _BrowserType()
        webkit = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    with pytest.raises(RuntimeError) as excinfo:
        browser_mod.launch_browser(ScraperConfig(browser_engine="firefox", browser_channel="msedge"))

    assert "--browser-channel can only be used with --browser-engine chromium" in str(excinfo.value)
    assert launched["stopped"] is True


def test_browser_launch_uses_selected_engine_with_persistent_profile(monkeypatch, tmp_path):
    launched: dict[str, Any] = {}

    class _LaunchContext:
        def set_default_timeout(self, ms: int) -> None:
            launched["timeout"] = ms

    class _BrowserType:
        def __init__(self, name: str) -> None:
            self.name = name

        def launch_persistent_context(self, **kwargs: Any) -> _LaunchContext:
            launched["engine"] = self.name
            launched["persistent"] = kwargs
            return _LaunchContext()

    class _Playwright:
        chromium = _BrowserType("chromium")
        firefox = _BrowserType("firefox")
        webkit = _BrowserType("webkit")

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())
    profile_dir = tmp_path / "tapology-firefox"

    playwright, browser, context = browser_mod.launch_browser(
        ScraperConfig(
            browser_engine="firefox",
            headless=False,
            slow_mo=10,
            browser_profile_dir=profile_dir,
        )
    )

    assert playwright is not None
    assert browser is None
    assert context is not None
    assert profile_dir.exists()
    assert launched["engine"] == "firefox"
    assert launched["persistent"] == {
        "user_data_dir": str(profile_dir),
        "headless": False,
        "slow_mo": 10,
        "viewport": {"width": 1365, "height": 900},
    }


def test_browser_launch_missing_engine_install_hint(monkeypatch):
    launched: dict[str, Any] = {}

    class _BrowserType:
        def launch(self, **kwargs: Any) -> None:
            raise RuntimeError("Executable doesn't exist")

    class _Playwright:
        chromium = _BrowserType()
        firefox = _BrowserType()
        webkit = _BrowserType()

        def stop(self) -> None:
            launched["stopped"] = True

    class _SyncPlaywright:
        def start(self) -> _Playwright:
            return _Playwright()

    monkeypatch.setattr("playwright.sync_api.sync_playwright", lambda: _SyncPlaywright())

    with pytest.raises(RuntimeError) as excinfo:
        browser_mod.launch_browser(ScraperConfig(browser_engine="firefox"))

    assert "playwright install firefox" in str(excinfo.value)
    assert launched["stopped"] is True


def test_readme_documents_separate_browser_profile_dirs_per_engine():
    readme = Path("README.md").read_text(encoding="utf-8")

    assert "--browser-engine firefox" in readme
    assert ".\\data\\browser_profile\\tapology-firefox" in readme
    assert "Do not reuse Chromium profile directories for Firefox" in readme


def test_is_manual_solvable_challenge_distinguishes_patterns():
    assert is_manual_solvable_challenge("just a moment") is True
    assert is_manual_solvable_challenge("cloudflare") is True
    assert is_manual_solvable_challenge("performing security verification") is True
    assert is_manual_solvable_challenge("turnstile") is True
    assert is_manual_solvable_challenge("login required") is False
    assert is_manual_solvable_challenge("access denied") is False
    assert is_manual_solvable_challenge(None) is False


def test_detect_hard_block_flags_cloudflare_title_without_body_text():
    page = _ScriptedPage([("Just a moment...", "")])

    assert detect_hard_block(page) == "just a moment"


def test_detect_hard_block_ignores_incidental_blocked_copy():
    page = _ScriptedPage(
        [
            (
                "GFN 1 | Tapology",
                "Latest MMA news: Jon Jones reveals why Lewis Hamilton blocked him on social media.",
            )
        ]
    )

    assert detect_hard_block(page) is None


def test_detect_hard_block_flags_contextual_blocked_page():
    page = _ScriptedPage([("Access blocked", "Your access has been blocked for security reasons.")])

    assert detect_hard_block(page) == "blocked"


def test_apply_filters_classifies_cloudflare_as_not_cleared_before_missing_controls(tmp_path):
    scraper = TapologyScraper(ScraperConfig(debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    page = _ScriptedPage(
        [
            (
                "Just a moment...",
                "www.tapology.com Performing security verification Verify you are human",
            )
        ]
    )

    with pytest.raises(FatalScrapeError, match="cloudflare_challenge_not_cleared"):
        scraper._apply_filters(page, "results", {}, run_dir=tmp_path)

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "cloudflare_challenge_not_cleared"
    assert diagnostic["controls_count"] == 0
    assert diagnostic["title"] == "Just a moment..."
    assert "security verification" in diagnostic["body_excerpt"]
    assert (tmp_path / "raw_debug" / "fightcenter_results_challenge_not_cleared.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_challenge_not_cleared.png").exists()


def test_apply_filters_saves_readiness_diagnostic_when_controls_missing(tmp_path):
    scraper = TapologyScraper(ScraperConfig(debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    page = _StaticFightCenterPage(has_form=False)

    with pytest.raises(tap_mod.FilterApplyError, match="promotion, schedule, sport"):
        scraper._apply_filters(page, "results", {}, run_dir=tmp_path)

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "missing_fightcenter_filter_controls"
    assert diagnostic["missing_controls"] == ["promotion", "schedule", "sport"]
    assert diagnostic["challenge_indicators"] == []
    assert "required_filter_controls" in diagnostic["markers_missing"]
    assert (tmp_path / "raw_debug" / "fightcenter_results_missing_filter_controls.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_missing_filter_controls.png").exists()


def test_wait_for_manual_challenge_returns_none_when_cleared():
    page = _ScriptedPage([("Tapology FightCenter", "Welcome to the FightCenter results listings")])
    result = wait_for_manual_challenge(
        page,
        timeout_seconds=2,
        poll_interval_ms=100,
        log=_silent_log,
        initial_reason="cloudflare",
        context_name="unit_test",
    )
    assert result is None
    assert page.wait_ms_log  # at least one poll wait happened


def test_wait_for_manual_challenge_instructs_operator_not_to_close_browser(capsys):
    page = _ScriptedPage([("Tapology FightCenter", "Welcome to the FightCenter results listings")])
    result = wait_for_manual_challenge(
        page,
        timeout_seconds=2,
        poll_interval_ms=100,
        log=_silent_log,
        initial_reason="cloudflare",
        context_name="unit_test",
    )
    out = capsys.readouterr().out
    assert result is None
    assert "Do not close the browser until the scraper says it is safe" in out


def test_wait_for_manual_challenge_returns_reason_on_timeout():
    page = _ScriptedPage([("Just a moment...", "Performing security verification by Cloudflare")] * 50)
    result = wait_for_manual_challenge(
        page,
        timeout_seconds=1,
        poll_interval_ms=100,
        log=_silent_log,
        initial_reason="cloudflare",
        context_name="unit_test",
    )
    assert result is not None
    assert is_manual_solvable_challenge(result)


def test_wait_for_manual_challenge_does_not_clear_without_destination_readiness():
    page = _ScriptedPage([("Tapology FightCenter", "Loading shell without controls")] * 50)
    result = wait_for_manual_challenge(
        page,
        timeout_seconds=1,
        poll_interval_ms=100,
        log=_silent_log,
        initial_reason="cloudflare",
        context_name="unit_test",
        readiness_check=lambda: False,
    )
    assert result == "destination_not_ready"


def test_fightcenter_page_with_controls_passes_readiness():
    scraper = TapologyScraper(ScraperConfig())
    page = _StaticFightCenterPage()
    summary: dict[str, Any] = {}

    scraper._ensure_fightcenter_ready(page, "results", None, summary, "https://www.tapology.com/fightcenter")

    assert page.goto_log == []


def test_fightcenter_ready_timeout_records_diagnostic(tmp_path):
    scraper = TapologyScraper(ScraperConfig(debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    page = _StaticFightCenterPage(has_form=False, body="FightCenter shell")
    summary: dict[str, Any] = {}

    with pytest.raises(FatalScrapeError, match="fightcenter_ready_timeout"):
        scraper._ensure_fightcenter_ready(page, "results", tmp_path, summary, "https://www.tapology.com/fightcenter")

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "fightcenter_ready_timeout"
    assert "required_filter_controls" in diagnostic["markers_missing"]
    assert (tmp_path / "raw_debug" / "fightcenter_results_ready_timeout.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_ready_timeout.png").exists()


def test_unexpected_page_after_challenge_records_diagnostic(tmp_path):
    scraper = TapologyScraper(ScraperConfig(debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    page = _StaticFightCenterPage(
        title="Sign in",
        body="Please sign in",
        url="https://www.tapology.com/login",
        has_form=False,
    )
    summary: dict[str, Any] = {}

    with pytest.raises(FatalScrapeError, match="unexpected_page_after_challenge"):
        scraper._ensure_fightcenter_ready(page, "results", tmp_path, summary, "https://www.tapology.com/fightcenter")

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "unexpected_page_after_challenge"
    assert diagnostic["challenge_indicators"] == []
    assert (tmp_path / "raw_debug" / "fightcenter_results_unexpected_page_after_challenge.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_unexpected_page_after_challenge.png").exists()


def test_fightcenter_challenge_safe_reload_attempted_at_most_once(tmp_path):
    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            wait_for_manual_challenge_seconds=1,
            manual_challenge_poll_interval_ms=100,
            debug=True,
        )
    )
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    page = _StaticFightCenterPage(
        title="Just a moment...",
        body="Performing security verification Ray ID",
        has_form=False,
    )
    summary: dict[str, Any] = {}

    with pytest.raises(FatalScrapeError, match="cloudflare_challenge_not_cleared"):
        scraper._ensure_fightcenter_ready(page, "results", tmp_path, summary, "https://www.tapology.com/fightcenter")

    assert page.goto_log == ["https://www.tapology.com/fightcenter"]
    assert summary.get("fightcenter_safe_reloads") == 1


def test_hard_pause_enters_without_polling_before_operator_enter(monkeypatch, tmp_path, capsys):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "Performing security verification Ray ID",
                "html": "<input name='cf-turnstile-response'>",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "has_form": True,
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True))
    forbidden_before_enter = {"wait_for_function", "wait_for_timeout", "evaluate", "goto"}

    def _operator_input(prompt: str) -> str:
        assert not forbidden_before_enter.intersection(page.interactions)
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    out = capsys.readouterr().out
    assert "fully paused" in out
    assert page.goto_log == []
    assert "wait_for_function" not in page.interactions


def test_hard_pause_operator_enter_ready_page_continues(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "This website uses a security service",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True))
    inputs = 0

    def _operator_input(prompt: str) -> str:
        nonlocal inputs
        inputs += 1
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    assert inputs == 1
    assert page.goto_log == []


def test_hard_pause_challenge_after_enter_returns_to_pause(monkeypatch, tmp_path, capsys):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "Performing security verification Ray ID",
                "html": "<input name='cf-turnstile-response'>",
                "has_form": False,
            },
            {
                "title": "Just a moment...",
                "body": "Verifying you are human Ray ID",
                "html": "<script src='/cdn-cgi/challenge-platform/test'></script>",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "has_form": True,
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True, debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")
    inputs = 0

    def _operator_input(prompt: str) -> str:
        nonlocal inputs
        inputs += 1
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    assert inputs == 2
    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "operator_confirmed_but_challenge_still_present"
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_but_challenge_still_present.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_but_challenge_still_present.png").exists()
    assert "Challenge markers are still present" in capsys.readouterr().out


def test_hard_pause_unexpected_page_after_enter_is_classified(monkeypatch, tmp_path, capsys):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "Performing security verification",
                "has_form": False,
            },
            {
                "title": "Sign in",
                "body": "Please sign in",
                "url": "https://www.tapology.com/login",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True, debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")

    def _operator_input(prompt: str) -> str:
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "operator_confirmed_unexpected_page"
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_unexpected_page.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_unexpected_page.png").exists()
    assert "not the expected Tapology FightCenter page" in capsys.readouterr().out


def test_hard_pause_operator_confirmed_not_ready_records_requested_artifacts(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "Performing security verification",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter shell without filters",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True, debug=True))
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")

    def _operator_input(prompt: str) -> str:
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    diagnostic = scraper._debug_doc["diagnostics"][0]
    assert diagnostic["type"] == "operator_confirmed_but_not_ready"
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_but_not_ready.html").exists()
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_but_not_ready.png").exists()


def test_hard_pause_closed_stdin_classifies_input_unavailable(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Just a moment...",
                "body": "Performing security verification",
                "has_form": False,
            }
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, pause_on_manual_challenge=True))

    def _closed_input(prompt: str) -> str:
        raise EOFError

    monkeypatch.setattr("builtins.input", _closed_input)

    with pytest.raises(FatalScrapeError, match="manual_challenge_input_unavailable"):
        scraper._ensure_fightcenter_ready(page, "results", tmp_path, {}, "https://www.tapology.com/fightcenter")

    assert page.goto_log == []
    assert "wait_for_function" not in page.interactions


def test_wait_for_manual_challenge_returns_when_block_becomes_unsolvable():
    page = _ScriptedPage([("Login required", "Please sign in to continue")])
    result = wait_for_manual_challenge(
        page,
        timeout_seconds=2,
        poll_interval_ms=100,
        log=_silent_log,
        initial_reason="cloudflare",
        context_name="unit_test",
    )
    assert result == "login required" or result == "sign in to continue"


def test_raise_if_blocked_default_fails_fast_without_wait():
    scraper = TapologyScraper(ScraperConfig(headless=True))
    page = _ScriptedPage([("Just a moment...", "Cloudflare security check")])
    with pytest.raises(FatalScrapeError) as excinfo:
        scraper._raise_if_blocked(page, None, "default_fail_fast")
    assert "manual_challenge_timeout" not in str(excinfo.value)
    assert "cloudflare" in str(excinfo.value).lower()


def test_raise_if_blocked_headless_ignores_wait_option():
    # Even if wait seconds set, headless mode must fail fast (no operator present).
    scraper = TapologyScraper(
        ScraperConfig(headless=True, wait_for_manual_challenge_seconds=30)
    )
    page = _ScriptedPage([("Just a moment...", "Cloudflare security check")])
    with pytest.raises(FatalScrapeError) as excinfo:
        scraper._raise_if_blocked(page, None, "headless_ignored")
    assert "manual_challenge_timeout" not in str(excinfo.value)


def test_raise_if_blocked_headed_wait_clears_then_continues():
    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            wait_for_manual_challenge_seconds=5,
            manual_challenge_poll_interval_ms=100,
        )
    )
    page = _ScriptedPage(
        [
            ("Just a moment...", "Performing security verification by Cloudflare"),
            ("Tapology FightCenter", "Welcome to the FightCenter regional listings"),
        ]
    )
    # Should not raise — the challenge clears on the second poll snapshot.
    scraper._raise_if_blocked(page, None, "headed_wait_cleared")


def test_raise_if_blocked_headed_wait_times_out_raises_manual_challenge_timeout():
    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            wait_for_manual_challenge_seconds=1,
            manual_challenge_poll_interval_ms=100,
        )
    )
    page = _ScriptedPage(
        [("Just a moment...", "Performing security verification by Cloudflare")] * 50
    )
    with pytest.raises(FatalScrapeError) as excinfo:
        scraper._raise_if_blocked(page, None, "headed_wait_timeout")
    assert "manual_challenge_timeout" in str(excinfo.value)


def test_cli_run_warns_and_zeros_wait_without_headed(monkeypatch, capsys):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            captured["results"] = results
            captured["upcoming"] = upcoming
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    exit_code = cli_mod.main(["run", "--results-only", "--wait-for-manual-challenge", "30"])
    assert exit_code == 0
    err = capsys.readouterr().err
    assert "requires --headed" in err
    assert captured["config"].wait_for_manual_challenge_seconds == 0
    assert captured["config"].headless is True
    assert captured["config"].pause_on_manual_challenge is False


def test_cli_run_warns_and_disables_hard_pause_without_headed(monkeypatch, capsys):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    exit_code = cli_mod.main(["run", "--results-only", "--pause-on-manual-challenge"])
    assert exit_code == 0
    assert captured["config"].headless is True
    assert captured["config"].pause_on_manual_challenge is False
    assert "requires --headed" in capsys.readouterr().err


def test_cli_run_propagates_wait_when_headed(monkeypatch):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    exit_code = cli_mod.main(
        ["run", "--results-only", "--headed", "--wait-for-manual-challenge", "45"]
    )
    assert exit_code == 0
    assert captured["config"].wait_for_manual_challenge_seconds == 45
    assert captured["config"].pause_on_manual_challenge is False
    assert captured["config"].headless is False
    assert captured["config"].browser_engine == "chromium"
    assert captured["config"].browser_profile_dir is None


def test_cli_run_propagates_hard_pause_with_wait_and_browser_profile(monkeypatch, tmp_path):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    profile_dir = tmp_path / "tapology-profile"
    exit_code = cli_mod.main(
        [
            "run",
            "--results-only",
            "--headed",
            "--wait-for-manual-challenge",
            "900",
            "--browser-engine",
            "firefox",
            "--pause-on-manual-challenge",
            "--browser-profile-dir",
            str(profile_dir),
        ]
    )
    assert exit_code == 0
    assert captured["config"].headless is False
    assert captured["config"].browser_engine == "firefox"
    assert captured["config"].wait_for_manual_challenge_seconds == 900
    assert captured["config"].pause_on_manual_challenge is True
    assert captured["config"].browser_profile_dir == profile_dir


def test_cli_browser_profile_dir_is_opt_in_and_headed_only(monkeypatch, tmp_path, capsys):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    profile_dir = tmp_path / "tapology-profile"

    exit_code = cli_mod.main(
        [
            "run",
            "--results-only",
            "--headed",
            "--browser-profile-dir",
            str(profile_dir),
        ]
    )

    assert exit_code == 0
    assert captured["config"].headless is False
    assert captured["config"].browser_profile_dir == profile_dir

    captured.clear()
    exit_code = cli_mod.main(
        [
            "run",
            "--results-only",
            "--browser-profile-dir",
            str(profile_dir),
        ]
    )
    assert exit_code == 0
    assert captured["config"].headless is True
    assert captured["config"].browser_profile_dir is None
    assert "headed local sessions" in capsys.readouterr().err


def test_cli_run_propagates_operator_navigation_and_current_page(monkeypatch, tmp_path):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            captured["results"] = results
            captured["upcoming"] = upcoming
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)
    profile_dir = tmp_path / "tapology-profile"

    exit_code = cli_mod.main(
        [
            "run",
            "--results-only",
            "--headed",
            "--operator-navigates-to-fightcenter",
            "--use-current-page",
            "--browser-engine",
            "firefox",
            "--pause-on-manual-challenge",
            "--browser-profile-dir",
            str(profile_dir),
        ]
    )

    assert exit_code == 0
    assert captured["config"].headless is False
    assert captured["config"].browser_engine == "firefox"
    assert captured["config"].operator_navigates_to_fightcenter is True
    assert captured["config"].use_current_page is True
    assert captured["config"].pause_on_manual_challenge is True
    assert captured["config"].browser_profile_dir == profile_dir


def test_cli_run_warns_and_ignores_operator_navigation_without_headed(monkeypatch, capsys):
    captured: dict[str, Any] = {}

    class _StubScraper:
        def __init__(self, config: ScraperConfig) -> None:
            captured["config"] = config

        def run(self, *, results: bool, upcoming: bool) -> dict[str, Any]:
            return {"successful": True}

    monkeypatch.setattr(cli_mod, "TapologyScraper", _StubScraper)

    exit_code = cli_mod.main(["run", "--results-only", "--operator-navigates-to-fightcenter"])

    assert exit_code == 0
    assert captured["config"].headless is True
    assert captured["config"].operator_navigates_to_fightcenter is False
    assert "requires --headed" in capsys.readouterr().err


def test_tapology_auth_opens_persistent_headed_profile_and_waits(monkeypatch, tmp_path, capsys):
    captured: dict[str, Any] = {"input_called": False}

    class _AuthPage:
        def __init__(self) -> None:
            self.closed = False

        def close(self) -> None:
            self.closed = True
            captured["page_closed"] = True

    class _AuthContext:
        def __init__(self) -> None:
            self.page = _AuthPage()
            self.closed = False

        def set_default_timeout(self, ms: int) -> None:
            captured["default_timeout"] = ms

        def new_page(self) -> _AuthPage:
            captured["new_page"] = True
            return self.page

        def close(self) -> None:
            self.closed = True
            captured["context_closed"] = True

    def _fake_launch(config: ScraperConfig):
        captured["config"] = config
        return _FakePlaywright(), _FakeBrowser(), _AuthContext()

    import mma_future_scraper.browser as browser_mod

    monkeypatch.setattr(browser_mod, "launch_browser", _fake_launch)

    def _operator_input(prompt: str) -> str:
        captured["input_called"] = True
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    profile_dir = tmp_path / "tapology-profile"
    exit_code = cli_mod.main(
        ["tapology-auth", "--browser-engine", "firefox", "--browser-profile-dir", str(profile_dir)]
    )

    assert exit_code == 0
    assert captured["config"].headless is False
    assert captured["config"].browser_engine == "firefox"
    assert captured["config"].browser_profile_dir == profile_dir
    assert captured["input_called"] is True
    assert captured["new_page"] is True
    assert captured["context_closed"] is True
    assert "persistent Tapology profile" in capsys.readouterr().out
    assert not (tmp_path / "results.json").exists()
    assert not (tmp_path / "changes.json").exists()


def test_tapology_auth_closed_stdin_classifies_input_unavailable(monkeypatch, tmp_path, capsys):
    class _AuthContext:
        def set_default_timeout(self, ms: int) -> None:
            return None

        def new_page(self) -> _ScriptedPage:
            return _ScriptedPage([("", "")])

        def close(self) -> None:
            return None

    def _fake_launch(config: ScraperConfig):
        return _FakePlaywright(), _FakeBrowser(), _AuthContext()

    import mma_future_scraper.browser as browser_mod

    monkeypatch.setattr(browser_mod, "launch_browser", _fake_launch)

    def _closed_input(prompt: str) -> str:
        raise EOFError

    monkeypatch.setattr("builtins.input", _closed_input)

    exit_code = cli_mod.main(["tapology-auth", "--browser-profile-dir", str(tmp_path / "profile")])

    assert exit_code == 2
    assert "manual_challenge_input_unavailable" in capsys.readouterr().err


def test_cli_browser_check_does_not_contact_tapology(monkeypatch):
    captured: dict[str, Any] = {}

    class _BrowserCheckPage:
        def __init__(self) -> None:
            self.goto_url = None

        def goto(self, url: str, **kwargs: Any) -> None:
            self.goto_url = url
            captured["url"] = url

        def title(self) -> str:
            return "MMA Future Browser Check"

        def close(self) -> None:
            return None

    class _BrowserCheckContext:
        def set_default_timeout(self, ms: int) -> None:
            return None

        def new_page(self) -> _BrowserCheckPage:
            return _BrowserCheckPage()

        def close(self) -> None:
            return None

    def _fake_launch(config: ScraperConfig):
        captured["config"] = config
        return _FakePlaywright(), _FakeBrowser(), _BrowserCheckContext()

    import mma_future_scraper.browser as browser_mod

    monkeypatch.setattr(browser_mod, "launch_browser", _fake_launch)
    exit_code = cli_mod.main(["browser-check", "--headed"])
    assert exit_code == 0
    assert captured["config"].headless is False
    assert str(captured["url"]).startswith("data:text/html,")


def test_cli_rejects_negative_wait():
    parser = cli_mod.build_parser()
    with pytest.raises(SystemExit):
        parser.parse_args(["run", "--wait-for-manual-challenge", "-5"])


def test_operator_navigation_does_not_goto_or_poll_before_enter(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
                "event_rows": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )
    forbidden_before_enter = {"wait_for_function", "wait_for_timeout", "evaluate", "goto"}
    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            pause_on_manual_challenge=True,
            operator_navigates_to_fightcenter=True,
            use_current_page=True,
        )
    )

    def _operator_input(prompt: str) -> str:
        assert not forbidden_before_enter.intersection(page.interactions)
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._wait_for_operator_current_fightcenter_page(page, "results", tmp_path, {})

    assert page.goto_log == []
    assert "wait_for_function" not in page.interactions
    assert page.interactions.count("evaluate") >= 1


def test_operator_navigation_use_current_page_skips_initial_goto_and_filter_reapply(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
                "event_rows": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )

    class _OnePageContext:
        def new_page(self) -> _OperatorConfirmPage:
            return page

    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            pause_on_manual_challenge=True,
            operator_navigates_to_fightcenter=True,
            use_current_page=True,
            max_pages=1,
        )
    )

    def _operator_input(prompt: str) -> str:
        page.advance()
        return ""

    def _fake_traverse(page_arg: Any, kind: str, context: Any, run_dir: Path, summary: dict[str, Any]) -> list[dict[str, Any]]:
        assert page_arg is page
        assert page.goto_log == []
        assert "current_fightcenter_page" in summary.get("selectors_used", [])
        return [{"event_url": "https://www.tapology.com/fightcenter/events/1-fixture"}]

    monkeypatch.setattr("builtins.input", _operator_input)
    monkeypatch.setattr(scraper, "_traverse_event_list", _fake_traverse)

    document = scraper._scrape_kind(_OnePageContext(), "results", "run", "started", tmp_path, {})

    assert len(document["events"]) == 1
    assert page.goto_log == []
    assert all(not interaction.startswith("locator:#promotion") for interaction in page.interactions)


def test_operator_navigation_challenge_after_enter_is_classified(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
            },
            {
                "title": "Just a moment...",
                "body": "Verifying you are human Ray ID",
                "html": "<input name='cf-turnstile-response'>",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": False,
            },
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )
    scraper = TapologyScraper(
        ScraperConfig(headless=False, pause_on_manual_challenge=True, operator_navigates_to_fightcenter=True, debug=True)
    )
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")

    def _operator_input(prompt: str) -> str:
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    scraper._wait_for_operator_current_fightcenter_page(page, "results", tmp_path, {})

    assert scraper._debug_doc["diagnostics"][0]["type"] == "operator_confirmed_but_challenge_still_present"
    assert scraper._debug_doc["diagnostics"][0]["strong_challenge_indicators"]
    assert scraper._debug_doc["diagnostics"][0]["weak_challenge_indicators"] == []
    assert "fightcenter_url" in scraper._debug_doc["diagnostics"][0]["fightcenter_markers_present"]
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_but_challenge_still_present.html").exists()


def test_ready_fightcenter_with_generic_captcha_text_is_not_challenge_blocked(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport hidden captcha helper text",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )
    scraper = TapologyScraper(
        ScraperConfig(headless=False, pause_on_manual_challenge=True, operator_navigates_to_fightcenter=True, use_current_page=True)
    )
    summary: dict[str, Any] = {}

    monkeypatch.setattr("builtins.input", lambda _prompt: "")

    selected_page = scraper._wait_for_operator_current_fightcenter_page(page, "results", tmp_path, summary)
    snapshot = scraper._fightcenter_readiness_snapshot(selected_page, "results")

    assert selected_page is page
    assert snapshot["operator_current_page_ready"] is True
    assert snapshot["strong_challenge_indicators"] == []
    assert snapshot["weak_challenge_indicators"] == ["captcha"]
    assert "results" in summary.get("pre_scrape_confirmation", {})


def test_operator_navigation_accepts_ready_fightcenter_with_empty_title(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "",
                "body": "TAPOLOGY FightCenter Promotion Schedule Sport PEF 11 Friday MMA Show Bouts",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            },
        ]
    )
    scraper = TapologyScraper(
        ScraperConfig(headless=False, operator_navigates_to_fightcenter=True, use_current_page=True)
    )
    summary: dict[str, Any] = {}

    monkeypatch.setattr("builtins.input", lambda _prompt: "")

    selected_page = scraper._wait_for_operator_current_fightcenter_page(page, "results", tmp_path, summary)
    snapshot = scraper._fightcenter_readiness_snapshot(selected_page, "results")

    assert selected_page is page
    assert snapshot["title"] == ""
    assert snapshot["operator_current_page_ready"] is True
    assert snapshot["markers_missing"] == []
    assert "results" in summary.get("pre_scrape_confirmation", {})


def test_operator_navigation_unexpected_page_after_enter_is_classified(monkeypatch, tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
            },
            {
                "title": "Sign in",
                "body": "Please sign in",
                "url": "https://www.tapology.com/login",
                "has_form": False,
            },
        ]
    )
    scraper = TapologyScraper(
        ScraperConfig(headless=False, operator_navigates_to_fightcenter=True, debug=True)
    )
    scraper._debug_doc = scraper._empty_debug_document("test_run", "2026-06-04T00:00:00+00:00")

    def _operator_input(prompt: str) -> str:
        page.advance()
        return ""

    monkeypatch.setattr("builtins.input", _operator_input)

    with pytest.raises(FatalScrapeError, match="operator_confirmed_unexpected_page"):
        scraper._wait_for_operator_current_fightcenter_page(page, "results", tmp_path, {})

    assert scraper._debug_doc["diagnostics"][0]["type"] == "operator_confirmed_unexpected_page"
    assert (tmp_path / "raw_debug" / "fightcenter_results_operator_confirmed_unexpected_page.html").exists()


def test_use_current_page_wrong_filter_state_fails_instead_of_scraping(tmp_path):
    page = _OperatorConfirmPage(
        [
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
                "schedule_value": "upcoming",
                "schedule_text": "Upcoming",
            },
        ]
    )
    scraper = TapologyScraper(ScraperConfig(headless=False, use_current_page=True))
    snapshot = scraper._fightcenter_readiness_snapshot(page, "results")

    with pytest.raises(FatalScrapeError, match="wrong_fightcenter_filter_state"):
        scraper._print_and_validate_prescrape_confirmation(page, "results", tmp_path, {}, snapshot=snapshot)


class _MultiPageContext:
    def __init__(self, pages: list[Any]) -> None:
        self.pages = pages
        self.new_page_calls = 0

    def new_page(self) -> Any:
        self.new_page_calls += 1
        page = _OperatorConfirmPage(
            [
                {
                    "title": "about:blank",
                    "body": "",
                    "url": "about:blank",
                    "has_form": False,
                    "event_rows": False,
                }
            ]
        )
        self.pages.append(page)
        return page


def test_operator_current_page_selects_fightcenter_over_blank(monkeypatch, tmp_path):
    blank = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
                "event_rows": False,
            }
        ]
    )
    fightcenter = _OperatorConfirmPage(
        [
            {
                "title": "Tapology FightCenter",
                "body": "FightCenter MMA Events Promotion Schedule Sport",
                "url": "https://www.tapology.com/fightcenter",
                "has_form": True,
                "event_rows": True,
            }
        ]
    )
    context = _MultiPageContext([blank, fightcenter])
    scraper = TapologyScraper(
        ScraperConfig(headless=False, pause_on_manual_challenge=True, operator_navigates_to_fightcenter=True, use_current_page=True)
    )

    monkeypatch.setattr("builtins.input", lambda _prompt: "")

    selected_page = scraper._wait_for_operator_current_fightcenter_page(blank, "results", tmp_path, {}, context=context)

    assert selected_page is fightcenter


def test_operator_current_page_multiple_blanks_do_not_crash(monkeypatch, tmp_path):
    blanks = [
        _OperatorConfirmPage(
            [
                {
                    "title": "about:blank",
                    "body": "",
                    "url": "about:blank",
                    "has_form": False,
                    "event_rows": False,
                }
            ]
        )
        for _ in range(2)
    ]
    context = _MultiPageContext(blanks)
    scraper = TapologyScraper(ScraperConfig(headless=False, operator_navigates_to_fightcenter=True, use_current_page=True))

    monkeypatch.setattr("builtins.input", lambda _prompt: "")

    with pytest.raises(FatalScrapeError, match="operator_confirmed_unexpected_page|operator_confirmed_but_not_ready"):
        scraper._wait_for_operator_current_fightcenter_page(blanks[0], "results", tmp_path, {}, context=context)


def test_operator_current_page_reuses_existing_page_without_extra_blank():
    existing = _OperatorConfirmPage(
        [
            {
                "title": "about:blank",
                "body": "",
                "url": "about:blank",
                "has_form": False,
                "event_rows": False,
            }
        ]
    )
    context = _MultiPageContext([existing])
    scraper = TapologyScraper(
        ScraperConfig(headless=False, operator_navigates_to_fightcenter=True, use_current_page=True)
    )

    selected_page = scraper._new_fightcenter_page(context)

    assert selected_page is existing
    assert context.new_page_calls == 0


class _FakeContext:
    def __init__(self, page: _ScriptedPage) -> None:
        self._page = page

    def new_page(self) -> _ScriptedPage:
        return self._page

    def set_default_timeout(self, ms: int) -> None:
        return None

    def close(self) -> None:
        return None


class _FakeBrowser:
    def close(self) -> None:
        return None


class _FakePlaywright:
    def stop(self) -> None:
        return None


def test_full_run_with_manual_challenge_timeout_does_not_update_latest(monkeypatch, tmp_path):
    page = _ScriptedPage(
        [("Just a moment...", "Performing security verification by Cloudflare")] * 200
    )

    def _fake_launch(config: ScraperConfig):
        return _FakePlaywright(), _FakeBrowser(), _FakeContext(page)

    store = DataStore(data_dir=tmp_path)

    # Redirect all storage paths used during .run() into tmp_path.
    monkeypatch.setattr(tap_mod, "launch_browser", _fake_launch)
    monkeypatch.setattr(tap_mod, "ensure_data_dirs", store.ensure_dirs)

    scraper = TapologyScraper(
        ScraperConfig(
            headless=False,
            wait_for_manual_challenge_seconds=1,
            manual_challenge_poll_interval_ms=100,
            debug=False,
        )
    )
    scraper._store = store

    summary = scraper.run(results=True, upcoming=False)

    assert summary["successful"] is False
    assert summary["latest_updated"] is False
    assert summary["state_updated"] is False
    assert any("cloudflare_challenge_not_cleared" in err for err in summary.get("errors", []))

    latest_results = tmp_path / "latest" / "results.json"
    assert not latest_results.exists(), "latest/results.json must not be written on failed/blocked runs"

    # The failed run should still leave a diagnostic manifest in runs/<run_id>/.
    run_manifests = list((tmp_path / "runs").glob("*/run_manifest.json"))
    assert run_manifests, "expected a run manifest to be written for the failed attempt"
    manifest = json.loads(run_manifests[0].read_text(encoding="utf-8"))
    assert manifest.get("status") == "failed"
    options = manifest.get("options") or {}
    assert options.get("wait_for_manual_challenge_seconds") == 1
    assert options.get("pause_on_manual_challenge") is False
    assert options.get("headless") is False
