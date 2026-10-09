from __future__ import annotations

import re
import time
from pathlib import Path
from typing import Any, Callable

from .config import BROWSER_ENGINES, ScraperConfig


CHALLENGE_PATTERNS = [
    "captcha",
    "verify you are human",
    "verifying you are human",
    "are you a human",
    "not a bot",
    "security verification",
    "performing security verification",
    "this website uses a security service",
    "cloudflare",
    "turnstile",
    "cf-turnstile-response",
    "challenges.cloudflare.com",
    "/cdn-cgi/challenge-platform/",
    "__cf_chl_tk",
    "ray id",
    "just a moment",
]

STRONG_CHALLENGE_PATTERNS = [
    "verify you are human",
    "verifying you are human",
    "security verification",
    "performing security verification",
    "this website uses a security service",
    "cloudflare",
    "turnstile",
    "cf-turnstile-response",
    "challenges.cloudflare.com",
    "/cdn-cgi/challenge-platform/",
    "__cf_chl_tk",
    "just a moment",
]

WEAK_CHALLENGE_PATTERNS = [
    "captcha",
    "anti-spam",
    "anti spam",
    "are you a human",
    "not a bot",
]

HARD_BLOCK_PATTERNS = CHALLENGE_PATTERNS + [
    "access denied",
    "login required",
    "sign in to continue",
]

CONTEXTUAL_BLOCK_PATTERNS = [
    re.compile(r"\b(?:access|request|connection|visitor|ip|traffic)\s+(?:has\s+been\s+)?blocked\b"),
    re.compile(r"\b(?:you|your browser|your access|your ip)\s+(?:have\s+been\s+|has\s+been\s+|are\s+)?blocked\b"),
    re.compile(r"\bblocked\s+(?:by|from accessing|for security|due to|because of)\b"),
    re.compile(r"\btemporarily\s+blocked\b"),
]


def dismiss_blocking_ui(
    page: Any,
    log: Callable[..., None],
    summary: dict[str, Any],
    *,
    max_rounds: int = 1,
    settle_ms: int = 300,
) -> int:
    total_dismissed = 0
    rounds = max(int(max_rounds or 1), 1)
    for _round in range(rounds):
        dismissed = 0
        for selector in _CLOSE_CONTROL_SELECTORS:
            try:
                locator = page.locator(selector)
                if locator.count() and locator.first.is_visible(timeout=700):
                    locator.first.click(timeout=2500)
                    summary.setdefault("selectors_used", []).append(f"popup close:{selector}")
                    summary["popup_dismissals_count"] = int(summary.get("popup_dismissals_count") or 0) + 1
                    log("info", "popup_dismissed", selector=selector)
                    page.wait_for_timeout(settle_ms)
                    dismissed += 1
                    total_dismissed += 1
            except Exception:
                continue
        candidates = [
            "Accept",
            "Accept All",
            "I Accept",
            "I Agree",
            "Agree",
            "Got it",
            "OK",
            "Close",
            "No thanks",
            "Not now",
            "Continue",
        ]
        for label in candidates:
            try:
                locator = page.get_by_role("button", name=label, exact=False)
                if locator.count() and locator.first.is_visible(timeout=700):
                    locator.first.click(timeout=2500)
                    summary.setdefault("selectors_used", []).append(f"popup button:{label}")
                    summary["popup_dismissals_count"] = int(summary.get("popup_dismissals_count") or 0) + 1
                    log("info", "popup_dismissed", label=label)
                    page.wait_for_timeout(settle_ms)
                    dismissed += 1
                    total_dismissed += 1
            except Exception:
                continue
        if not dismissed:
            break
    if total_dismissed:
        return total_dismissed
    try:
        page.keyboard.press("Escape")
        page.wait_for_timeout(150)
        summary.setdefault("selectors_used", []).append("popup escape")
        log("info", "popup_escape_attempted")
    except Exception:
        pass
    return 0


_CLOSE_CONTROL_SELECTORS = [
    "#ad_position_box #dismiss-button-element",
    "#ad_position_box [id*='dismiss' i]",
    "#ad_position_box button[aria-label*='close' i]",
    "#ad_position_box [role='button'][aria-label*='close' i]",
    "#ad_position_box button[title*='close' i]",
    "#ad_position_box [role='button'][title*='close' i]",
    "#ad_position_box .close",
    "#ad_position_box .ad-close",
    "#ad_position_box .close-ad",
    "#dismiss-button-element",
    "[id*='dismiss-button' i]",
    "button[aria-label*='close' i]",
    "[role='button'][aria-label*='close' i]",
    "button[aria-label*='dismiss' i]",
    "[role='button'][aria-label*='dismiss' i]",
    "button[title*='close' i]",
    "[role='button'][title*='close' i]",
    ".modal button.close",
    ".modal .close",
    ".popup button.close",
    ".popup .close",
    ".overlay button.close",
    ".overlay .close",
    ".ad-close",
    ".close-ad",
    ".close",
    "button:has-text('×')",
    "[role='button']:has-text('×')",
    "button:has-text('X')",
]


def detect_hard_block(page: Any) -> str | None:
    try:
        title = page.title()
    except Exception:
        title = ""
    try:
        body = page.locator("body").inner_text(timeout=3000)
    except Exception:
        body = ""
    haystack = f"{title}\n{body}".lower()
    for pattern in HARD_BLOCK_PATTERNS:
        if pattern in haystack:
            return pattern
    title_text = title.strip().lower()
    if title_text in {"blocked", "access blocked", "request blocked"} or title_text.startswith("blocked "):
        return "blocked"
    for pattern in CONTEXTUAL_BLOCK_PATTERNS:
        if pattern.search(haystack):
            return "blocked"
    return None


def detect_challenge_indicators(page: Any) -> list[str]:
    classified = classify_challenge_indicators(page)
    return classified["strong"] + classified["weak"]


def classify_challenge_indicators(page: Any) -> dict[str, list[str]]:
    try:
        title = page.title()
    except Exception:
        title = ""
    try:
        body = page.locator("body").inner_text(timeout=3000)
    except Exception:
        body = ""
    try:
        html = page.content()
    except Exception:
        html = ""
    url = str(getattr(page, "url", "") or "")
    haystack = f"{url}\n{title}\n{body}\n{html}".lower()
    title_lower = title.lower()
    strong = [pattern for pattern in STRONG_CHALLENGE_PATTERNS if pattern in haystack]
    if "just a moment" in title_lower and "just a moment" not in strong:
        strong.append("just a moment")
    if "ray id" in haystack and any(
        context in haystack
        for context in (
            "cloudflare",
            "security verification",
            "performing security verification",
            "verify you are human",
            "verifying you are human",
            "this website uses a security service",
            "challenge-platform",
            "turnstile",
        )
    ):
        strong.append("ray id")
    weak = [pattern for pattern in WEAK_CHALLENGE_PATTERNS if pattern in haystack and pattern not in strong]
    return {
        "strong": list(dict.fromkeys(strong)),
        "weak": list(dict.fromkeys(weak)),
    }


def is_manual_solvable_challenge(block_reason: str | None) -> bool:
    if not block_reason:
        return False
    return block_reason in CHALLENGE_PATTERNS


def wait_for_manual_challenge(
    page: Any,
    timeout_seconds: int,
    poll_interval_ms: int,
    log: Callable[..., None],
    initial_reason: str,
    context_name: str,
    readiness_check: Callable[[], bool] | None = None,
) -> str | None:
    timeout_seconds = max(int(timeout_seconds), 0)
    poll_seconds = max(int(poll_interval_ms), 250) / 1000.0
    deadline = time.monotonic() + timeout_seconds
    print(
        f"[manual-challenge] Cloudflare/Turnstile challenge detected ({initial_reason}) at "
        f"{context_name}. Complete it manually in the browser window. "
        "Do not close the browser until the scraper says it is safe. "
        f"Waiting up to {timeout_seconds} seconds...",
        flush=True,
    )
    log(
        "warn",
        "manual_challenge_wait_start",
        reason=initial_reason,
        context=context_name,
        timeout_seconds=timeout_seconds,
    )
    while True:
        try:
            page.wait_for_timeout(int(poll_seconds * 1000))
        except Exception:
            time.sleep(poll_seconds)
        current = detect_hard_block(page)
        ready = readiness_check() if readiness_check is not None else True
        if not is_manual_solvable_challenge(current):
            if current:
                log(
                    "warn",
                    "manual_challenge_wait_unsolvable",
                    reason=current,
                    context=context_name,
                )
                return current
            if not ready:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    log(
                        "error",
                        "manual_challenge_wait_timeout",
                        context=context_name,
                        reason="destination_not_ready",
                        timeout_seconds=timeout_seconds,
                    )
                    print(
                        f"[manual-challenge] Timed out after {timeout_seconds}s waiting for "
                        f"{context_name} to become ready after the challenge.",
                        flush=True,
                    )
                    return "destination_not_ready"
                continue
            log(
                "info",
                "manual_challenge_wait_cleared",
                context=context_name,
                previous_reason=initial_reason,
            )
            print(
                f"[manual-challenge] Challenge cleared at {context_name}. Resuming scrape.",
                flush=True,
            )
            return None
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            log(
                "error",
                "manual_challenge_wait_timeout",
                context=context_name,
                reason=current,
                timeout_seconds=timeout_seconds,
            )
            print(
                f"[manual-challenge] Timed out after {timeout_seconds}s waiting for challenge "
                f"at {context_name}. Aborting as blocked.",
                flush=True,
            )
            return current


def save_debug_artifacts(page: Any, run_dir: Path, name: str) -> dict[str, str]:
    debug_dir = run_dir / "raw_debug"
    debug_dir.mkdir(parents=True, exist_ok=True)
    safe_name = "".join(ch if ch.isalnum() or ch in "-_" else "_" for ch in name)[:80]
    paths: dict[str, str] = {
        "html_path": str(debug_dir / f"{safe_name}.html"),
        "screenshot_path": str(debug_dir / f"{safe_name}.png"),
    }
    try:
        (debug_dir / f"{safe_name}.html").write_text(page.content(), encoding="utf-8")
    except Exception:
        pass
    try:
        page.screenshot(path=paths["screenshot_path"], full_page=True, timeout=5000)
    except Exception:
        pass
    return paths


def _playwright_install_hint(browser_engine: str) -> str:
    return f".\\.venv\\Scripts\\python.exe -m playwright install {browser_engine}"


def _browser_label(config: ScraperConfig) -> str:
    if config.browser_executable_path:
        return f"{config.browser_engine}/{config.browser_executable_path}"
    if config.browser_channel:
        return f"{config.browser_engine}/{config.browser_channel}"
    return config.browser_engine


def _browser_type(playwright: Any, browser_engine: str) -> Any:
    if browser_engine not in BROWSER_ENGINES:
        allowed = ", ".join(BROWSER_ENGINES)
        raise ValueError(f"unsupported browser_engine:{browser_engine}; expected one of: {allowed}")
    return getattr(playwright, browser_engine)


def _launch_options(config: ScraperConfig) -> dict[str, Any]:
    options: dict[str, Any] = {
        "headless": config.headless,
        "slow_mo": config.slow_mo,
    }
    if config.browser_channel:
        if config.browser_engine != "chromium":
            raise ValueError("--browser-channel can only be used with --browser-engine chromium")
        options["channel"] = config.browser_channel
    if config.browser_executable_path:
        if config.browser_engine != "chromium":
            raise ValueError("--browser-executable-path can only be used with --browser-engine chromium")
        if config.browser_channel:
            raise ValueError("--browser-executable-path cannot be combined with --browser-channel")
        options["executable_path"] = str(config.browser_executable_path)
    return options


def _raise_browser_launch_error(config: ScraperConfig, exc: Exception) -> None:
    hint = _playwright_install_hint(config.browser_engine)
    channel_hint = ""
    if config.browser_channel:
        channel_hint = (
            f" Browser channel '{config.browser_channel}' uses your locally installed browser; "
            "make sure that browser is installed, or retry without --browser-channel."
        )
    if config.browser_executable_path:
        channel_hint = (
            f" Browser executable '{config.browser_executable_path}' could not be used; "
            "make sure the path exists and points to a Chromium-family browser."
        )
    raise RuntimeError(
        f"Playwright browser '{_browser_label(config)}' could not be launched. "
        f"If the Playwright engine is not installed, run: {hint}.{channel_hint} Error: {exc}"
    ) from exc


def launch_browser(config: ScraperConfig):
    from playwright.sync_api import sync_playwright

    playwright = sync_playwright().start()
    try:
        browser_type = _browser_type(playwright, config.browser_engine)
        launch_options = _launch_options(config)
        if config.browser_profile_dir:
            config.browser_profile_dir.mkdir(parents=True, exist_ok=True)
            context = browser_type.launch_persistent_context(
                user_data_dir=str(config.browser_profile_dir),
                **launch_options,
                viewport={"width": 1365, "height": 900},
            )
            browser = None
        else:
            browser = browser_type.launch(**launch_options)
            context = browser.new_context(viewport={"width": 1365, "height": 900})
        context.set_default_timeout(config.action_timeout_ms)
        return playwright, browser, context
    except Exception as exc:
        playwright.stop()
        _raise_browser_launch_error(config, exc)
