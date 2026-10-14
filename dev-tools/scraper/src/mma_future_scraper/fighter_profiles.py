from __future__ import annotations

import json
import re
import time
from copy import deepcopy
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any

from .browser import (
    detect_hard_block,
    dismiss_blocking_ui,
    is_manual_solvable_challenge,
    launch_browser,
    save_debug_artifacts,
    wait_for_manual_challenge,
)
from .config import LATEST_DIR, ScraperConfig
from .country_normalizer import (
    normalize_country,
    summarize_unmatched_country_values,
    summarize_unmatched_location_country_candidates,
)
from .fighter_profile_queue import build_fighter_profile_queue_report, collect_fighter_profile_urls_from_queue
from .fighter_profile_report import add_profile_completeness_scores, write_profile_enrichment_report
from .logging_utils import log
from .normalize import normalize_method, parse_round, parse_time
from .storage import DataStore, atomic_write_json
from .utils import (
    absolute_url,
    canonical_url,
    normalize_space,
    parse_location_parts,
    parse_date_to_iso,
    source_bout_id_from_url,
    source_event_id_from_url,
    source_fighter_id_from_url,
    url_hash,
    utc_now,
)


FIGHTER_PROFILES_SCHEMA_VERSION = "tapology_fighter_profiles_v0_1"

PROFILE_SUMMARY_FIELDS = [
    "nickname",
    "date_of_birth_raw",
    "date_of_birth",
    "age",
    "birth_year",
    "country",
    "nationality",
    "born_location",
    "fighting_out_of",
    "association",
    "height",
    "height_cm",
    "reach",
    "reach_cm",
    "weight",
    "last_weigh_in",
    "last_weigh_in_lbs",
    "weight_class",
    "image_url",
]

RECORD_SUMMARY_FIELDS = [
    "pro_record_raw",
    "wins",
    "losses",
    "draws",
    "no_contests",
    "amateur_record_raw",
    "finish_breakdown",
]

EVENT_PROFILE_COVERAGE_FIELDS = [
    "nationality",
    "country",
    "date_of_birth",
    "age_years_at_scrape",
    "height_cm",
    "reach_cm",
    "weight_class",
    "pro_mma_record_raw",
    "born_raw",
    "fighting_out_of_raw",
    "affiliation",
    "last_fight_date",
    "last_weigh_in_lbs",
]

DATE_PATTERN = (
    r"(?:\d{4}-\d{2}-\d{2}|"
    r"(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},\s+\d{4}|"
    r"\d{4}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2})"
)
RECORD_PATTERN = r"\b(\d{1,2})\s*-\s*(\d{1,2})\s*-\s*(\d{1,2})(?:\s*(?:,|\()\s*(\d{1,2})\s*NC\)?)?"
ROW_RECORD_PATTERN = r"\b\d{1,2}\s*-\s*\d{1,2}(?:\s*-\s*\d{1,2})?(?:\s*(?:,|\()\s*\d{1,2}\s*NC\)?)?"
WEIGHT_CLASS_PATTERN = (
    r"\b(Super Lightweight|Light Heavyweight|Open Weight|Catchweight|Strawweight|Flyweight|"
    r"Bantamweight|Featherweight|Lightweight|Welterweight|Middleweight|Heavyweight)\b"
)

# Tokens that close a Tapology fight-history method/result block. Anything that
# appears AFTER the last occurrence of one of these tokens (and before the date)
# is treated as the event name. Each token corresponds to a distinct Tapology
# rendering: round marker ("R2"), bout time ("3:32"), explicit cancellation,
# a Decision adjective ("Decision · Unanimous/Split/..."), a Draw stamp, a
# No Contest stamp, the bare "KO/TKO" method label, or the legacy "Win"/"Loss"
# method placeholder used on older regional fights. The list is intentionally
# narrow — we never invent an event name when no terminator is present.
_EVENT_END_MARKERS_RE = re.compile(
    r"(?:"
    r"R\d+\b"
    r"|\b\d{1,2}:\d{2}\b"
    r"|Cancelled\s+Bout\b"
    r"|Decision\s*\u00c2?[\u00b7\u30fb\u2022]\s*(?:Unanimous|Split|Majority|Technical|Judges?|Points|Scores?|Time\s+Limit)"
    r"|Draw\s*\u00c2?[\u00b7\u30fb\u2022]\s*(?:Draw|Decision)"
    r"|Decision\s*[\u00b7\u30fb\u2022]\s*(?:Unanimous|Split|Majority|Technical|Judges?|Points|Scores?|Time\s+Limit)"
    r"|Draw\s*[\u00b7\u30fb\u2022]\s*(?:Draw|Decision)"
    r"|Decision\s*[·・•\-]\s*(?:Unanimous|Split|Majority|Technical|Judges?|Points|Scores?|Time\s+Limit)"
    r"|Draw\s*[·・•\-]\s*(?:Draw|Decision)"
    r"|\bTime\s+Limit\b"
    r"|No\s+Contest\b"
    r"|KO/TKO\b"
    r"|(?<=\d\s)(?:Win|Loss)\b"
    r")",
    re.IGNORECASE,
)

# Candidate event-name strings that we always reject as noise. The first three
# are Tapology UI affordances; the remaining entries protect against picking up
# generic result/method words when the markers above happen to leave nothing
# meaningful behind.
_GENERIC_EVENT_REJECT = {
    "",
    "n/a",
    "na",
    "none",
    "unknown",
    "mma",
    "win",
    "loss",
    "draw",
    "no contest",
    "basic odds",
    "view mode",
    "sport filters",
    "cancelled: shown",
    "expand all details",
}


# Section headings emitted by the Tapology profile DOM. They mark the start
# (or end) of a record table; they MUST NOT survive inside a per-fight row's
# text because they cause cross-row exclusion-flag bleed (an AMATEUR BOUTS
# heading later in the container would otherwise mark a completed pro row as
# amateur).
_FIGHT_ROW_SECTION_HEADING_RE = re.compile(
    r"\b(?:"
    r"PROFESSIONAL\s+BOUTS"
    r"|AMATEUR\s+BOUTS"
    r"|CANCELLED\s+BOUTS"
    r"|COMBAT\s+SPORTS\s+RECORD"
    r"|MMA\s+FIGHT\s+RECORD"
    r"|MMA\s+RECORD\s+BY"
    r"|PRO\s+MMA\s+STATISTICS"
    r"|Basic\s+Odds"
    r"|VIEW\s+MODE"
    r"|Sport\s+Filters"
    r")\b",
    re.IGNORECASE,
)

# Leading section-heading prefixes that some DOM container rows include before
# the first result token. ``Sport Filters: MMA Grapple Custom`` is matched
# loosely because Tapology renders the active filter list right after the
# colon.
_FIGHT_ROW_LEADING_PREFIX_RE = re.compile(
    r"^(?:"
    r"(?:PROFESSIONAL\s+BOUTS|AMATEUR\s+BOUTS|CANCELLED\s+BOUTS|COMBAT\s+SPORTS\s+RECORD"
    r"|MMA\s+FIGHT\s+RECORD|MMA\s+RECORD\s+BY|PRO\s+MMA\s+STATISTICS|Basic\s+Odds)"
    r"|VIEW\s+MODE[^WLDNC]*"
    r"|Sport\s+Filters\s*:?[^WLDNC]*"
    r")\s+(?=(?:W|L|D|NC|C)\b)",
    re.IGNORECASE,
)

NATIONALITY_SOURCE = "profile_header_flag"
MISSING_NATIONALITY = {
    "raw": None,
    "country_name": None,
    "country_code": None,
    "source": NATIONALITY_SOURCE,
    "is_missing": True,
}

_PROFILE_UI_CHROME_RE = re.compile(
    r"\b(?:COMBAT\s+SPORTS\s+RECORD|MMA\s+FIGHT\s+RECORD|VIEW\s+MODE|Display\s+Options|Expand\s+All\s+Details|Sport\s+Filters)\b",
    re.I,
)
_AFFILIATION_HINT_RE = re.compile(
    r"\b(?:team|gym|mma|bjj|jiu[\s-]*jitsu|academy|fitness|dojo|kickboxing|muay\s*thai|boxing|wrestling|grappling|martial\s+arts|fight\s+club)\b",
    re.I,
)
_AFFILIATION_CHROME_RE = re.compile(
    r"\b(?:joe\s+rogan|white\s+house|most\s+violent\s+fighters|rankings\s+faq|resource\s+links|personal\s+links|photos|videos|news)\b",
    re.I,
)
_PROMOTION_NAME_HINT_RE = re.compile(
    r"\b(?:fc|gfc|shooto|deep|tnx|immaf|ufc|bellator|pfl|one|rizin|lfa|cage|fight(?:ing)?|championship|promotion)\b",
    re.I,
)
_NATIONALITY_ATTRS = (
    "title",
    "alt",
    "data_original_title",
    "data_bs_title",
    "aria_label",
    "data_title",
    "data_tooltip",
)
_FLAG_CONTEXT_RE = re.compile(r"\bflag\b|flag[-_]|[-_]flag\b|country|national", re.I)
_PLACEHOLDER_PROFILE_IMAGE_RE = re.compile(
    r"(?:/assets/logo/|/regions/logos/|/gyms/logos/|facebook[_-]?fist|tapology[_-]?fist|default[_-]?fighter|placeholder|missing[_-]?fighter|no[_-]?photo|default[_-]?profile|region[_-]placeholder|gym[_-](?:logo|placeholder)|default[_-]logo|social[_-]?placeholder)",
    re.I,
)
_LAST_WEIGH_IN_MIN_LBS = 90.0
_LAST_WEIGH_IN_MAX_LBS = 400.0


_RESULT_TOKEN_PROBE_RE = re.compile(
    r"\b(W|L|D|NC|C)\b\s+(?:SUB|DEC|KO/TKO|TKO|KO|DQ|Cancelled|Win|Loss|[A-Z][a-z])",
)


def scope_fight_row_text(raw_text: str | None) -> str:
    """Trim a Tapology fight-row raw_text to one fight's worth of text.

    Four pollution patterns produced by the new Tapology DOM are handled:

    1. Multi-fight container rows: snapshot innerText concatenates every
       fight in the list, separated by ``add_circle``. Anything after the
       first separator belongs to the NEXT fight and must be removed.
    2. Section heading prefix: some container rows lead with
       ``PROFESSIONAL BOUTS``, ``COMBAT SPORTS RECORD``, or the
       ``VIEW MODE ... Sport Filters: MMA Grapple Custom`` preamble before
       the first result token.
    3. Preamble before the FIRST add_circle: when the leading prefix is the
       VIEW MODE / Sport Filters chrome and the actual first fight sits
       AFTER the separator, the head before the separator is junk and we
       must keep the rest instead.
    4. Section heading inside the text: a row whose first fight is followed
       by an ``AMATEUR BOUTS`` (or similar) heading would otherwise inherit
       ``is_amateur=True`` from text that does not belong to this fight.

    Conservative trim — never invents content, only removes bleed. An empty
    input returns an empty string. The helper is shared with the staging
    dry-run so legacy on-disk rows with the same bleed are corrected at
    classification time without requiring a rescrape.
    """

    if not raw_text:
        return ""
    text = normalize_space(raw_text)
    if not text:
        return text
    # 1) Strip a leading section-heading prefix before the first result
    #    token. Anchored to a following W/L/D/NC/C so we never delete a
    #    heading-only row.
    text = _FIGHT_ROW_LEADING_PREFIX_RE.sub("", text)
    # 2) Trim at the next-fight separator. When the first add_circle is
    #    PRECEDED by a real result token, the head IS the first fight and
    #    we drop the tail. When no result token precedes the separator,
    #    the head is preamble chrome (VIEW MODE / Sport Filters block) and
    #    the actual first fight sits AFTER the separator — we drop the
    #    head and then re-apply the trim to keep only the first fight.
    if "add_circle" in text:
        head, _, rest = text.partition("add_circle")
        if _RESULT_TOKEN_PROBE_RE.search(head):
            text = normalize_space(head)
        else:
            text = normalize_space(rest)
            # Re-strip in case the preamble itself was missing the result
            # anchor that the leading-prefix regex requires.
            text = _FIGHT_ROW_LEADING_PREFIX_RE.sub("", text)
            if "add_circle" in text:
                text = normalize_space(text.split("add_circle", 1)[0])
    # 3) Truncate at any section heading appearing AFTER the start of this
    #    fight. Trailing AMATEUR BOUTS / COMBAT SPORTS RECORD / Sport
    #    Filters mean we have leaked into another section.
    inner = _FIGHT_ROW_SECTION_HEADING_RE.search(text)
    if inner and inner.start() > 0:
        text = text[: inner.start()]
    return normalize_space(text)


class FighterProfileEnricher:
    def __init__(self, config: ScraperConfig):
        self.config = config
        self._store = DataStore()

    def run(
        self,
        *,
        limit_fighters: int = 10,
        source_results_path: Path | None = None,
        from_queue: bool = False,
        queue_path: Path | None = None,
        skip_already_enriched: bool = False,
        debug: bool = False,
    ) -> dict[str, Any]:
        limit_fighters = max(0, int(limit_fighters))
        self._store.ensure_dirs()
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        started_at = utc_now()
        started_clock = time.time()
        run_dir = self._store.create_run_dir(run_id)
        document = empty_fighter_profiles_document(run_id, started_at)
        if from_queue:
            resolved_queue_path = queue_path or LATEST_DIR / "fighter_profile_queue.json"
            profile_urls, input_metadata = collect_fighter_profile_urls_from_queue(
                resolved_queue_path,
                limit=limit_fighters,
                skip_already_enriched=skip_already_enriched,
            )
            _write_queue_run_selection_report(resolved_queue_path, selected_for_enrichment=len(profile_urls))
        else:
            source_path = source_results_path or LATEST_DIR / "results.json"
            profile_urls = collect_fighter_profile_urls(source_path, limit_fighters)
            input_metadata = {
                "mode": "results",
                "source_results": str(source_path),
                "selected_for_enrichment": len(profile_urls),
                "skip_already_enriched": False,
            }
        document["input"] = input_metadata

        log("info", "fighter_profile_enrichment_start", run_id=run_id, limit_fighters=limit_fighters, urls=len(profile_urls))
        playwright = browser = context = None
        try:
            if not profile_urls:
                document["errors"].append({"status": "skipped_no_url", "reason": "no_fighter_profile_urls_found"})
                return self._write_document(document, run_dir, started_clock)

            playwright, browser, context = launch_browser(self.config)
            for index, source_url in enumerate(profile_urls, start=1):
                profile = self._scrape_profile(context, source_url, run_dir, debug)
                document["profiles"].append(profile)
                if profile["status"] == "success":
                    document["profiles_success"] += 1
                else:
                    document["profiles_failed"] += 1
                    document["errors"].append(
                        {
                            "source_url": source_url,
                            "source_fighter_id": profile.get("source_fighter_id"),
                            "status": profile["status"],
                            "reason": profile.get("error_reason"),
                        }
                    )
                document["profiles_total"] = len(document["profiles"])
                log(
                    "info",
                    "fighter_profile_enriched",
                    index=index,
                    total=len(profile_urls),
                    source_url=source_url,
                    status=profile["status"],
                )
                if index < len(profile_urls):
                    page_delay_ms = max(self.config.event_delay_ms, self.config.conservative_delay_ms)
                    time.sleep(page_delay_ms / 1000)
            return self._write_document(document, run_dir, started_clock)
        finally:
            if context:
                context.close()
            if browser:
                browser.close()
            if playwright:
                playwright.stop()

    def _scrape_profile(self, context: Any, source_url: str, run_dir: Path, debug: bool) -> dict[str, Any]:
        diagnostic_name = f"fighter_profile_{source_fighter_id_from_url(source_url) or url_hash(source_url)}"
        last_profile: dict[str, Any] | None = None
        for attempt in range(1, 3):
            page = context.new_page()
            diagnostics: dict[str, Any] = {
                "type": "fighter_profile_fetch",
                "source_url": source_url,
                "attempt": attempt,
                "max_attempts": 2,
                "session_path": "standalone_profile_browser_context_new_page",
            }
            started = time.monotonic()
            try:
                log("info", "fighter_profile_open", source_url=source_url, attempt=attempt)
                response = page.goto(source_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
                diagnostics["elapsed_seconds"] = round(time.monotonic() - started, 3)
                try:
                    diagnostics["http_status"] = response.status if response else None
                except Exception:
                    diagnostics["http_status"] = None
                summary: dict[str, Any] = {}
                dismiss_blocking_ui(page, log, summary)
                block_reason = detect_hard_block(page)
                if (
                    block_reason
                    and not self.config.headless
                    and self.config.wait_for_manual_challenge_seconds > 0
                    and is_manual_solvable_challenge(block_reason)
                ):
                    block_reason = wait_for_manual_challenge(
                        page,
                        timeout_seconds=self.config.wait_for_manual_challenge_seconds,
                        poll_interval_ms=self.config.manual_challenge_poll_interval_ms,
                        log=log,
                        initial_reason=block_reason,
                        context_name=source_url,
                    )
                if block_reason:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_blocked_attempt_{attempt}")
                    diagnostics.update({"reason": "profile_fetch_failed:blocked_or_challenge", "block_reason": block_reason})
                    last_profile = failed_profile(source_url, "blocked", "profile_fetch_failed:blocked_or_challenge", page, diagnostics=diagnostics)
                elif isinstance(diagnostics.get("http_status"), int) and diagnostics["http_status"] >= 400:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_http_{diagnostics['http_status']}_attempt_{attempt}")
                    diagnostics["reason"] = "profile_fetch_failed:http_error"
                    last_profile = failed_profile(source_url, "http_error", "profile_fetch_failed:http_error", page, diagnostics=diagnostics)
                else:
                    snapshot = snapshot_profile_page(page)
                    snapshot['scraped_at']=utc_now()
                    snapshot_path=run_dir/'profile_snapshots'/(source_fighter_id_from_url(source_url)+'.json')
                    atomic_write_json(snapshot_path,snapshot)

                    body_text = normalize_space(snapshot.get("body_text"))
                    diagnostics["body_text_length"] = len(body_text)
                    if not body_text:
                        save_debug_artifacts(page, run_dir, f"{diagnostic_name}_empty_html_attempt_{attempt}")
                        diagnostics["reason"] = "profile_fetch_failed:empty_html"
                        last_profile = failed_profile(source_url, "empty_html", "profile_fetch_failed:empty_html", page, diagnostics=diagnostics)
                    else:
                        profile = parse_profile_snapshot(snapshot, source_url)
                        profile['scraped_at']=snapshot['scraped_at']
                        if profile["status"] != "success":
                            profile.setdefault("warnings", []).append("profile_parse_empty_required_sections")
                            profile["warnings"] = sorted(set(profile.get("warnings") or []))
                            profile["diagnostics"] = {**(profile.get("diagnostics") or {}), **diagnostics}
                            save_debug_artifacts(page, run_dir, f"{diagnostic_name}_failed_parse_attempt_{attempt}")
                        elif debug:
                            profile["raw_text_excerpt"] = body_text[:3000]
                        return profile
                if attempt < 2:
                    time.sleep(max(self.config.event_delay_ms, self.config.conservative_delay_ms) / 1000)
                    continue
                return last_profile or failed_profile(source_url, "navigation_failed", "profile_fetch_failed:navigation_failed", page, diagnostics=diagnostics)
            except Exception as exc:
                reason = _profile_fetch_exception_reason(exc)
                diagnostics.update({"reason": reason, "exception": str(exc)[:500]})
                try:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_{reason.rsplit(':', 1)[-1]}_attempt_{attempt}")
                except Exception:
                    pass
                last_profile = failed_profile(source_url, reason.rsplit(":", 1)[-1], reason, page, diagnostics=diagnostics)
                if attempt < 2:
                    time.sleep(max(self.config.event_delay_ms, self.config.conservative_delay_ms) / 1000)
                    continue
                return last_profile
            finally:
                page.close()

    def _http_status_hint(self, page: Any) -> str | None:
        try:
            title = normalize_space(page.title()).lower()
            body = normalize_space(page.locator("body").inner_text(timeout=3000)).lower()
        except Exception:
            return None
        if "404" in title or "page not found" in body or "not found" in title:
            return "not_found"
        return None

    def _write_document(self, document: dict[str, Any], run_dir: Path, started_clock: float) -> dict[str, Any]:
        finished_at = utc_now()
        document["scraped_at"] = finished_at
        document["duration_seconds"] = round(time.time() - started_clock, 3)
        document["profiles"] = [_stamp_profile_metadata(profile, scraped_at=finished_at) for profile in document["profiles"]]
        document["profiles_total"] = len(document["profiles"])
        document["profiles_success"] = sum(1 for profile in document["profiles"] if profile.get("status") == "success")
        document["profiles_failed"] = document["profiles_total"] - document["profiles_success"]
        document = add_profile_completeness_scores(document)
        atomic_write_json(run_dir / "fighter_profiles.json", document)

        existing_latest = _load_profile_document(self._store.latest_dir / "fighter_profiles.json")
        merged_document = merge_fighter_profiles_documents(
            existing_latest,
            document,
            run_id=document.get("run_id"),
            scraped_at=finished_at,
        )
        atomic_write_json(self._store.latest_dir / "fighter_profiles.json", merged_document)

        _, run_report_paths = write_profile_enrichment_report(document, run_dir=run_dir, latest_dir=run_dir)
        _, latest_report_paths = write_profile_enrichment_report(merged_document, run_dir=None, latest_dir=self._store.latest_dir)
        log(
            "info",
            "fighter_profile_enrichment_end",
            run_id=document["run_id"],
            profiles_total=document["profiles_total"],
            profiles_success=document["profiles_success"],
            profiles_failed=document["profiles_failed"],
            latest_profiles_total=merged_document["profiles_total"],
            latest_profiles_success=merged_document["profiles_success"],
            run_path=str(run_dir / "fighter_profiles.json"),
            latest_path=str(self._store.latest_dir / "fighter_profiles.json"),
            report_path=str(latest_report_paths.get("latest_json")),
        )
        document["latest_profiles_total"] = merged_document["profiles_total"]
        document["latest_profiles_success"] = merged_document["profiles_success"]
        document["latest_profiles_failed"] = merged_document["profiles_failed"]
        document["merge_stats"] = merged_document.get("merge_stats") or {}
        document["report_paths"] = {
            "run_json": str(run_report_paths.get("run_json")) if run_report_paths.get("run_json") else None,
            "latest_json": str(latest_report_paths.get("latest_json")) if latest_report_paths.get("latest_json") else None,
        }
        return document


def _write_queue_run_selection_report(queue_path: Path, *, selected_for_enrichment: int) -> None:
    if not queue_path.exists():
        return
    try:
        queue_document = json.loads(queue_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return
    report = build_fighter_profile_queue_report(queue_document, selected_for_enrichment=selected_for_enrichment)
    atomic_write_json(queue_path.with_name("fighter_profile_queue_report.json"), report)


def empty_fighter_profiles_document(run_id: str, scraped_at: str) -> dict[str, Any]:
    return {
        "schema_version": FIGHTER_PROFILES_SCHEMA_VERSION,
        "source": "tapology",
        "run_id": run_id,
        "scraped_at": scraped_at,
        "duration_seconds": None,
        "profiles_total": 0,
        "profiles_success": 0,
        "profiles_failed": 0,
        "profiles": [],
        "errors": [],
    }


def merge_fighter_profiles_documents(
    existing_document: dict[str, Any] | None,
    new_document: dict[str, Any],
    *,
    run_id: str | None = None,
    scraped_at: str | None = None,
) -> dict[str, Any]:
    scraped_at_value = normalize_space(scraped_at) or normalize_space(new_document.get("scraped_at")) or utc_now()
    normalized_existing = _normalize_profile_document(existing_document, fallback_scraped_at=scraped_at_value)
    normalized_new = _normalize_profile_document(new_document, fallback_scraped_at=scraped_at_value)

    existing_profiles = [profile for profile in normalized_existing.get("profiles", []) if isinstance(profile, dict)]
    new_profiles = [profile for profile in normalized_new.get("profiles", []) if isinstance(profile, dict)]

    existing_by_url = {
        canonical_url(profile.get("source_url")): profile
        for profile in existing_profiles
        if canonical_url(profile.get("source_url"))
    }
    existing_by_id = {
        str(profile.get("source_fighter_id")): profile
        for profile in existing_profiles
        if profile.get("source_fighter_id")
    }

    merged_profiles: list[dict[str, Any]] = []
    consumed_existing: set[int] = set()
    merge_stats = {
        "batch_total": len(new_profiles),
        "batch_success": sum(1 for profile in new_profiles if profile.get("status") == "success"),
        "batch_failed": sum(1 for profile in new_profiles if profile.get("status") != "success"),
        "updated": 0,
        "inserted": 0,
        "retained_untouched": 0,
        "retained_previous_success_on_failure": 0,
        "failed_without_previous_success": 0,
    }

    for profile in new_profiles:
        source_url = canonical_url(profile.get("source_url"))
        source_fighter_id = normalize_space(profile.get("source_fighter_id"))
        existing_profile = None
        if source_url:
            existing_profile = existing_by_url.get(source_url)
        if existing_profile is None and source_fighter_id:
            existing_profile = existing_by_id.get(source_fighter_id)
        if existing_profile is not None:
            consumed_existing.add(id(existing_profile))

        if profile.get("status") == "success":
            merged_profiles.append(profile)
            if existing_profile is not None:
                merge_stats["updated"] += 1
            else:
                merge_stats["inserted"] += 1
            continue

        if existing_profile is not None and existing_profile.get("status") == "success":
            retained = deepcopy(existing_profile)
            retained['last_attempt'] = {'status': profile.get('status'), 'at': profile.get('scraped_at'), 'error_reason': profile.get('error_reason')}
            merged_profiles.append(retained)
            merge_stats["retained_previous_success_on_failure"] += 1
            continue

        merged_profiles.append(profile)
        if existing_profile is not None:
            merge_stats["updated"] += 1
        else:
            merge_stats["inserted"] += 1
        merge_stats["failed_without_previous_success"] += 1

    for profile in existing_profiles:
        if id(profile) in consumed_existing:
            continue
        merged_profiles.append(profile)
        merge_stats["retained_untouched"] += 1

    merged_profiles = _dedupe_merged_profiles(merged_profiles)
    merged_document = empty_fighter_profiles_document(
        run_id=normalize_space(run_id) or normalize_space(new_document.get("run_id")) or normalize_space(normalized_existing.get("run_id")) or "latest",
        scraped_at=scraped_at_value,
    )
    merged_document["source"] = normalize_space(new_document.get("source")) or normalize_space(normalized_existing.get("source")) or "tapology"
    merged_document["input"] = deepcopy(new_document.get("input") or {})
    merged_document["profiles"] = merged_profiles
    merged_document["profiles_total"] = len(merged_profiles)
    merged_document["profiles_success"] = sum(1 for profile in merged_profiles if profile.get("status") == "success")
    merged_document["profiles_failed"] = merged_document["profiles_total"] - merged_document["profiles_success"]
    merged_document["errors"] = list(new_document.get("errors") or [])
    merged_document["duration_seconds"] = new_document.get("duration_seconds")
    merged_document["merge_stats"] = merge_stats
    return add_profile_completeness_scores(merged_document)


def _normalize_profile_document(document: dict[str, Any] | None, *, fallback_scraped_at: str) -> dict[str, Any]:
    if not isinstance(document, dict):
        document = {}
    normalized = empty_fighter_profiles_document(
        run_id=normalize_space(document.get("run_id")) or "latest",
        scraped_at=normalize_space(document.get("scraped_at")) or fallback_scraped_at,
    )
    normalized["source"] = normalize_space(document.get("source")) or "tapology"
    normalized["duration_seconds"] = document.get("duration_seconds")
    normalized["input"] = deepcopy(document.get("input") or {})
    normalized["errors"] = list(document.get("errors") or [])
    normalized["profiles"] = [
        _stamp_profile_metadata(profile, scraped_at=normalize_space(profile.get("scraped_at")) or normalize_space(document.get("scraped_at")) or fallback_scraped_at)
        for profile in document.get("profiles", [])
        if isinstance(profile, dict)
    ]
    normalized["profiles_total"] = len(normalized["profiles"])
    normalized["profiles_success"] = sum(1 for profile in normalized["profiles"] if profile.get("status") == "success")
    normalized["profiles_failed"] = normalized["profiles_total"] - normalized["profiles_success"]
    if isinstance(document.get("merge_stats"), dict):
        normalized["merge_stats"] = deepcopy(document["merge_stats"])
    return normalized


def _stamp_profile_metadata(profile: dict[str, Any], *, scraped_at: str) -> dict[str, Any]:
    normalized = deepcopy(profile)
    source_url = canonical_url(normalized.get("source_url")) or normalize_space(normalized.get("source_url"))
    source_fighter_id = normalize_space(normalized.get("source_fighter_id")) or source_fighter_id_from_url(source_url)
    normalized["source_url"] = source_url
    normalized["source_fighter_id"] = source_fighter_id
    normalized["scraped_at"] = normalize_space(normalized.get("scraped_at")) or scraped_at
    normalized["status"] = normalize_space(normalized.get("status")) or "failed_parse"
    normalized.setdefault('last_attempt', {'status': normalized['status'], 'at': normalized['scraped_at'], 'error_reason': normalized.get('error_reason')})
    normalized.setdefault("display_name", None)
    normalized.setdefault("warnings", [])
    normalized.setdefault("profile_summary", {})
    normalized.setdefault("record_summary", {})
    normalized.setdefault("fight_history", [])
    return normalized


def _dedupe_merged_profiles(profiles: list[dict[str, Any]]) -> list[dict[str, Any]]:
    deduped: dict[tuple[str, str], dict[str, Any]] = {}
    for profile in profiles:
        source_url = canonical_url(profile.get("source_url")) or ""
        source_fighter_id = normalize_space(profile.get("source_fighter_id")) or ""
        key = (source_fighter_id, source_url)
        existing = deduped.get(key)
        if existing is None:
            deduped[key] = profile
            continue
        existing_status = existing.get("status") == "success"
        current_status = profile.get("status") == "success"
        if current_status and not existing_status:
            deduped[key] = profile
            continue
        if current_status == existing_status and str(profile.get("scraped_at") or "") > str(existing.get("scraped_at") or ""):
            deduped[key] = profile
    return sorted(
        deduped.values(),
        key=lambda profile: (
            profile.get("status") != "success",
            str(profile.get("display_name") or "").lower(),
            str(profile.get("source_url") or "").lower(),
        ),
    )


def _load_profile_document(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def collect_fighter_profile_urls(results_path: Path, limit: int) -> list[str]:
    if limit <= 0 or not results_path.exists():
        return []
    payload = json.loads(results_path.read_text(encoding="utf-8"))
    urls: list[str] = []
    seen: set[str] = set()
    for event in payload.get("events", []):
        for bout in event.get("bouts", []):
            for fighter_key in ("fighter_a", "fighter_b"):
                fighter = bout.get(fighter_key) or {}
                url = canonical_url(fighter.get("url"))
                if not url or url in seen:
                    continue
                seen.add(url)
                urls.append(url)
                if len(urls) >= limit:
                    return urls
    return urls


def snapshot_profile_page(page: Any) -> dict[str, Any]:
    return page.evaluate(
        """
        () => {
          const clean = (value) => String(value || '').replace(/\\s+/g, ' ').trim();
          const abs = (value) => {
            if (!value) return null;
            try { return new URL(value, location.href).toString().replace(/[?#].*$/, ''); }
            catch (_) { return null; }
          };
          const lines = Array.from(new Set((document.body?.innerText || '')
            .split(/\\n+/).map(clean).filter(Boolean)));
          const headings = Array.from(document.querySelectorAll('h1,h2,h3'))
            .map((el) => clean(el.innerText)).filter(Boolean);
          const metaImage = document.querySelector("meta[property='og:image'], meta[name='twitter:image']")?.getAttribute('content') || '';
          const images = Array.from(document.querySelectorAll('img')).map((img) => ({
            src: abs(img.getAttribute('src') || img.getAttribute('data-src') || ''),
            alt: clean(img.getAttribute('alt') || ''),
            title: clean(img.getAttribute('title') || ''),
            aria_label: clean(img.getAttribute('aria-label') || ''),
            data_original_title: clean(img.getAttribute('data-original-title') || ''),
            data_bs_title: clean(img.getAttribute('data-bs-title') || ''),
            data_title: clean(img.getAttribute('data-title') || ''),
            data_tooltip: clean(img.getAttribute('data-tooltip') || img.getAttribute('data-tippy-content') || ''),
            class_name: clean(img.className || ''),
            width: Number(img.naturalWidth || img.width || 0),
            height: Number(img.naturalHeight || img.height || 0)
          })).filter((img) => img.src);
          const attrPayload = (el) => ({
            tag: clean(el.tagName || '').toLowerCase(),
            text: clean(el.innerText || el.textContent || ''),
            src: abs(el.getAttribute('src') || el.getAttribute('data-src') || ''),
            href: abs(el.getAttribute('href') || el.closest('a')?.getAttribute('href') || ''),
            class_name: clean(el.className || ''),
            id: clean(el.id || ''),
            title: clean(el.getAttribute('title') || ''),
            alt: clean(el.getAttribute('alt') || ''),
            aria_label: clean(el.getAttribute('aria-label') || ''),
            data_original_title: clean(el.getAttribute('data-original-title') || ''),
            data_bs_title: clean(el.getAttribute('data-bs-title') || ''),
            data_title: clean(el.getAttribute('data-title') || ''),
            data_tooltip: clean(el.getAttribute('data-tooltip') || el.getAttribute('data-tippy-content') || '')
          });
          const flagish = (el) => {
            const payload = attrPayload(el);
            const haystack = [
              payload.class_name, payload.id, payload.src, payload.href,
              payload.title, payload.alt, payload.aria_label,
              payload.data_original_title, payload.data_bs_title,
              payload.data_title, payload.data_tooltip
            ].join(' ').toLowerCase();
            return /\\bflag\\b|flag-|flag_|country|national/.test(haystack);
          };
          const headerRelated = new Set();
          Array.from(document.querySelectorAll('h1,h2,h3')).forEach((heading) => {
            [heading, heading.parentElement, heading.parentElement?.parentElement, heading.previousElementSibling, heading.nextElementSibling]
              .filter(Boolean).forEach((el) => headerRelated.add(el));
          });
          Array.from(headerRelated).forEach((el) => {
            Array.from(el.querySelectorAll('img, a[href*="/search/mma-fighters-by-nationality/"], [title], [aria-label], [data-original-title], [data-bs-title], [data-title], [data-tooltip], [data-tippy-content], [class*="flag"], [class*="country"], [class*="national"]'))
              .forEach((candidate) => headerRelated.add(candidate));
          });
          const flagCandidateSet = new Set();
          Array.from(document.querySelectorAll('img, a[href*="/search/mma-fighters-by-nationality/"], [class*="flag"], [class*="country"], [class*="national"]'))
            .filter((el) => flagish(el))
            .forEach((el) => flagCandidateSet.add(el));
          Array.from(headerRelated)
            .filter((el) => el.tagName === 'IMG' || flagish(el))
            .forEach((el) => flagCandidateSet.add(el));
          const flag_candidates = Array.from(flagCandidateSet).map(attrPayload);
          const canonicalCards = Array.from(document.querySelectorAll('.fighter-record-bout'));
          const rowSet = new Set(Array.from(document.querySelectorAll(
            'table tr, li, .fight, .bout, .fighterFightHistory tr, .fightCard, .fight-card, [class*="fightHistory"], [class*="fighterFight"]'
          )));
          Array.from(document.querySelectorAll(
            'a[href*="/fightcenter/fighters/"], a[href*="/fightcenter/events/"], a[href*="/fightcenter/bouts/"]'
          )).forEach((a) => {
            const row = a.closest('tr, li, .fight, .bout, .fightCard, .fight-card, [class*="fightHistory"], [class*="fighterFight"]');
            if (row) rowSet.add(row);
          });
          // New Tapology cards may share one outer history container. Select the
          // smallest result/date-bearing ancestor of each source link instead.
          const fightAnchors = document.querySelectorAll(
            'a[href*="/fightcenter/bouts/"],a[href*="/fightcenter/events/"],a[href*="/fightcenter/fighters/"]'
          );
          const identities = (el, path) => new Set(Array.from(el.querySelectorAll('a[href*="' + path + '"]')).map(a => abs(a.getAttribute('href'))));
          Array.from(fightAnchors).forEach(a => {
            let el = a.parentElement;
            for (let depth = 0; el && depth < 8; depth++, el = el.parentElement) {
              const text = clean(el.innerText);
              if (identities(el, '/fightcenter/events/').size > 1 || identities(el, '/fightcenter/bouts/').size > 1 || identities(el, '/fightcenter/fighters/').size > 2) break;
              if (text.length > 2500) break;
              if (/\\b(W|L|D|NC|Decision|Submission|KO\\/TKO)\\b/.test(text) && /\\b(?:19|20)\\d{2}\\b/.test(text)) { rowSet.add(el); break; }
            }
          });
          const historyScopeVerified = canonicalCards.some(el => el.getAttribute('data-sport') === 'mma' && el.getAttribute('data-division') === 'pro') || /\\bMMA FIGHT RECORD\\b/i.test(clean(document.body?.innerText)) &&
            !/\\bCOMBAT SPORTS RECORD\\b/i.test(clean(document.body?.innerText));
          const historyExhausted = (canonicalCards.length > 0 || historyScopeVerified) && !Array.from(document.querySelectorAll('button,a')).some(el => /^(?:load|show) more(?: fights| results)?$/i.test(clean(el.innerText)));
          const scopeMarkers = Array.from(document.querySelectorAll('h1,h2,h3,h4,strong,span,div'))
            .filter(el => el.children.length <= 1 && /^(?:PROFESSIONAL BOUTS|AMATEUR BOUTS|AMATEUR MMA RECORD|(?:.+ )?MMA FIGHT RECORD)$/i.test(clean(el.innerText)));
          const rowScope = el => {
            if (el.hasAttribute('data-division')) {
              if (['am','amateur'].includes(el.getAttribute('data-division'))) return 'amateur';
              return el.getAttribute('data-division') === 'pro' && el.getAttribute('data-sport') === 'mma' ? 'professional_mma' : 'other';
            }
            let scope = historyScopeVerified ? 'professional_mma' : 'unknown';
            scopeMarkers.forEach(marker => {
              if (marker.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) {
                scope = /^AMATEUR/i.test(clean(marker.innerText)) ? 'amateur' : (historyScopeVerified ? 'professional_mma' : 'unknown');
              }
            });
            return scope;
          };
          const rows = (canonicalCards.length ? canonicalCards : Array.from(rowSet)).filter(el =>
            identities(el, '/fightcenter/events/').size <= 1 &&
            identities(el, '/fightcenter/bouts/').size <= 1 &&
            identities(el, '/fightcenter/fighters/').size <= 2
          ).map((el) => {
            const links = Array.from(el.querySelectorAll('a')).map((a) => ({
              text: clean(a.innerText),
              href: abs(a.getAttribute('href') || '')
            })).filter((link) => link.href || link.text);
            const cells = Array.from(el.querySelectorAll('th,td')).map((cell) => clean(cell.innerText)).filter(Boolean);
            return { raw_text: clean(el.innerText), cells, links, history_scope: rowScope(el), row_boundary_verified: identities(el, '/fightcenter/bouts/').size === 1 && identities(el, '/fightcenter/events/').size <= 1 && identities(el, '/fightcenter/fighters/').size <= 2, data_bout_id: el.getAttribute('data-bout-id'), data_sport: el.getAttribute('data-sport'), data_division: el.getAttribute('data-division'), data_status: el.getAttribute('data-status') };
          }).filter((row) => row.raw_text);
          return {
            url: location.href.replace(/[?#].*$/, ''),
            title: clean(document.title),
            body_text: clean(document.body?.innerText || ''),
            lines,
            headings,
            meta_image_url: abs(metaImage),
            images,
            flag_candidates,
            history_scope: historyScopeVerified ? 'professional_mma' : 'unknown',
            history_scope_verified: historyScopeVerified,
            history_exhausted: historyExhausted,
            rows
          };
        }
        """
    )


def parse_profile_snapshot(snapshot: dict[str, Any], source_url: str) -> dict[str, Any]:
    source_url = canonical_url(source_url) or source_url
    body_text = normalize_space(snapshot.get("body_text"))
    lines = [normalize_space(line) for line in snapshot.get("lines", []) if normalize_space(line)]
    title = normalize_space(snapshot.get("title"))
    landed_url = canonical_url(snapshot.get("url")) or normalize_space(snapshot.get("url"))
    # Tapology silently redirects unknown / removed fighter URLs to the FightCenter
    # index page, which has no profile data. Detect that case and fail explicitly so
    # downstream consumers don't treat the index page as a successful profile.
    if landed_url and not re.search(r"/fightcenter/fighters/[^/?#]+", landed_url, re.I):
        return failed_profile(source_url, "failed_parse", "redirected_to_fightcenter_index")
    if landed_url and source_fighter_id_from_url(landed_url)!=source_fighter_id_from_url(source_url):
        return failed_profile(source_url, "failed_parse", "redirected_to_different_fighter")
    warnings: list[str] = []
    display_name = _extract_display_name(snapshot, source_url)
    if not display_name:
        warnings.append("missing_display_name")

    summary = _extract_profile_summary(snapshot, body_text, lines, source_url, warnings)
    record_summary = _extract_record_summary(body_text, lines, warnings)
    fight_history = _extract_fight_history(snapshot, source_url)
    if snapshot.get("history_scope_verified"):
        for row in fight_history:
            raw = row.get("raw_text", "")
            non_mma = bool(re.search(r"\b(?:Grappling|Boxing|Muay\s*Thai|Bare\s*Knuckle|Kickboxing|Modified\s*Rules)\b", raw, re.I))
            if not row.get("discipline"):
                row["discipline"] = "other" if non_mma else "mma"
            if not row.get("classification_evidence"):
                row["is_professional"] = False
    if not fight_history:
        warnings.append("no_fight_history_extracted")

    for field in ["date_of_birth", "age", "country", "born_location", "fighting_out_of", "association", "height", "reach", "weight"]:
        if field == "country":
            nationality = summary.get("nationality")
            has_country = bool(summary.get("country")) or (
                isinstance(nationality, dict) and not nationality.get("is_missing")
            )
            if not has_country:
                warnings.append("missing_or_unavailable:nationality")
            continue
        if field == "weight":
            if summary.get("last_weigh_in_lbs") is None:
                warnings.append("missing_or_unavailable:last_weigh_in_lbs")
                warnings.append("missing_or_unavailable:weight")
            continue
        if summary.get(field) is None:
            warnings.append(f"missing_or_unavailable:{field}")
    if record_summary.get("pro_record_raw") is None:
        warnings.append("missing_or_unavailable:pro_record_raw")

    explicit_mma = any(r.get("data_division") == "pro" and r.get("data_sport") == "mma" for r in snapshot.get("rows", []))
    profile = {
        "source_fighter_id": source_fighter_id_from_url(source_url),
        "source_url": source_url,
        "status": "success" if display_name or record_summary.get("pro_record_raw") or fight_history else "failed_parse",
        "display_name": display_name,
        "profile_summary": summary,
        "record_summary": record_summary,
        "fight_history": fight_history,
        "parser_version": "profile_dom_v2",
        "history_scope": "professional_mma" if explicit_mma else snapshot.get("history_scope", "unknown"),
        "history_scope_verified": explicit_mma or bool(snapshot.get("history_scope_verified")),
        "history_exhausted": bool(snapshot.get("history_exhausted")),
        "warnings": sorted(set(warnings)),
    }
    # Keep labelled fact evidence in the regular JSON, not only the one-off career compiler.
    from .career_history import explicit_profile_facts
    profile["verified_facts"] = explicit_profile_facts(snapshot, summary)
    if profile["status"] != "success":
        profile["error_reason"] = "profile_summary_not_found"
        profile["diagnostics"] = {
            "title": title,
            "url": snapshot.get("url") or source_url,
            "raw_text_excerpt": body_text[:1000],
        }
    return profile


def failed_profile(
    source_url: str,
    status: str,
    reason: str,
    page: Any | None = None,
    diagnostics: dict[str, Any] | None = None,
) -> dict[str, Any]:
    profile_diagnostics: dict[str, Any] = dict(diagnostics or {})
    if page is not None:
        try:
            profile_diagnostics["current_url"] = canonical_url(page.url)
            profile_diagnostics["page_title"] = normalize_space(page.title())
        except Exception:
            pass
    return {
        "source_fighter_id": source_fighter_id_from_url(source_url),
        "source_url": canonical_url(source_url) or source_url,
        "status": status,
        "display_name": None,
        "profile_summary": {
            **{field: None for field in PROFILE_SUMMARY_FIELDS},
            "nationality": missing_nationality(),
        },
        "record_summary": {field: None for field in RECORD_SUMMARY_FIELDS},
        "fight_history": [],
        "warnings": [reason],
        "error_reason": reason,
        "diagnostics": profile_diagnostics,
    }


def _profile_fetch_exception_reason(exc: Exception) -> str:
    message = str(exc).lower()
    if "timeout" in message:
        return "profile_fetch_failed:timeout"
    if "net::" in message or "navigation" in message or "err_" in message:
        return "profile_fetch_failed:navigation_failed"
    return "profile_fetch_failed:navigation_failed"


def _extract_display_name(snapshot: dict[str, Any], source_url: str) -> str | None:
    for heading in snapshot.get("headings", []):
        cleaned = _clean_display_name(heading)
        if cleaned:
            return cleaned
    title = normalize_space(snapshot.get("title"))
    if title:
        cleaned = _clean_display_name(re.split(r"\s+[|:-]\s+", title, maxsplit=1)[0])
        if cleaned:
            return cleaned
    slug = re.search(r"/fighters/\d+-([^/?#]+)", source_url or "")
    if slug:
        return " ".join(part.capitalize() for part in slug.group(1).split("-") if part)
    return None


def _clean_display_name(value: str | None) -> str | None:
    value = normalize_space(value)
    if not value:
        return None
    value = re.sub(r"\s+(?:MMA\s+FIGHT|COMBAT\s+SPORTS)\s+RECORD.*$", "", value, flags=re.I)
    value = re.sub(r"\s+(MMA Fighter|Fighter Page|Profile).*$", "", value, flags=re.I)
    value = re.sub(r"\s+\d{1,2}\s*-\s*\d{1,2}\s*-\s*\d{1,2}.*$", "", value)
    value = value.strip(" -|")
    return value.title() if value.isupper() else value or None


def _extract_profile_summary(
    snapshot: dict[str, Any],
    body_text: str,
    lines: list[str],
    source_url: str,
    warnings: list[str],
) -> dict[str, Any]:
    dob_raw = _field_value(body_text, lines, ["Date of Birth", "DOB", "Birthday"])
    date_of_birth = parse_date_to_iso(dob_raw) if dob_raw and re.search(r"\d{4}", dob_raw) else None
    if dob_raw and not date_of_birth:
        warnings.append(f"ambiguous_date_of_birth:{dob_raw[:80]}")
    age = _extract_int(_field_value(body_text, lines, ["Age"]))
    image_url = _extract_image_url(snapshot, source_url)
    weight_raw = _field_value(body_text, lines, ["Last Known Weight"])
    last_weigh_in_raw = _field_value(body_text, lines, ["Last Weigh-In", "Last Weigh In", "Weigh-In", "Weighed In"])
    nickname = _clean_optional_text(_field_value(body_text, lines, ["Nickname", "Alias"]))
    height_raw = _clean_optional_text(_field_value(body_text, lines, ["Height"]))
    association = _sanitize_association(
        _field_value(body_text, lines, ["Affiliation", "Association", "Team", "Gym"]),
        warnings=warnings,
    )
    fighting_out_of = _sanitize_fighting_out_of(
        _field_value(body_text, lines, ["Fighting out of", "Fights out of"]),
        association=association,
        warnings=warnings,
    )
    last_weigh_in = _sanitize_last_weigh_in(last_weigh_in_raw, warnings=warnings)
    weight = _clean_optional_text(weight_raw) or last_weigh_in
    nationality, nationality_diagnostics = extract_profile_header_nationality_details(snapshot, source_url)
    for warning in nationality_diagnostics.get("warnings", []):
        _append_warning(warnings, warning)
    return {
        "nickname": nickname,
        "date_of_birth_raw": _clean_optional_text(dob_raw),
        "date_of_birth": date_of_birth,
        "age": age,
        "birth_year": int(date_of_birth[:4]) if date_of_birth else None,
        "country": _clean_optional_text(_field_value(body_text, lines, ["Country", "Nationality"])),
        "nationality": nationality,
        "_nationality_diagnostics": nationality_diagnostics,
        "born_location": _clean_optional_text(_field_value(body_text, lines, ["Born Location", "Birthplace", "Born In", "Born"])),
        "fighting_out_of": fighting_out_of,
        "association": association,
        "height": height_raw,
        "height_cm": _normalize_height_cm(height_raw),
        "reach": _clean_optional_text(_field_value(body_text, lines, ["Reach"])),
        "reach_cm": _normalize_reach_cm(_field_value(body_text, lines, ["Reach"])),
        "weight": weight,
        "last_weigh_in": last_weigh_in,
        "last_weigh_in_lbs": _normalize_lbs(last_weigh_in),
        "weight_class": _extract_weight_class(body_text, lines),
        "image_url": image_url,
    }


def missing_nationality() -> dict[str, Any]:
    return dict(MISSING_NATIONALITY)


def extract_profile_header_nationality(snapshot: dict[str, Any], source_url: str | None = None) -> dict[str, Any]:
    nationality, _diagnostics = extract_profile_header_nationality_details(snapshot, source_url)
    return nationality


def extract_profile_header_nationality_details(
    snapshot: dict[str, Any],
    source_url: str | None = None,
) -> tuple[dict[str, Any], dict[str, Any]]:
    candidates = snapshot.get("flag_candidates")
    if not isinstance(candidates, list):
        candidates = []
    diagnostics: dict[str, Any] = {
        "rejected_candidates": [],
        "source_counts": {},
        "warnings": [],
    }
    first_unmatched_raw: str | None = None

    def record_source(source_kind: str) -> None:
        source_counts = diagnostics.setdefault("source_counts", {})
        source_counts[source_kind] = int(source_counts.get(source_kind) or 0) + 1

    candidate_objects: list[dict[str, Any]] = []
    for candidate in candidates:
        if not isinstance(candidate, dict):
            continue
        for attr in _NATIONALITY_ATTRS:
            raw = normalize_space(candidate.get(attr))
            if raw:
                candidate_objects.append(_nationality_candidate(candidate, raw, f"metadata:{attr}", source_url))
        text_raw = normalize_space(candidate.get("text"))
        if text_raw:
            candidate_objects.append(_nationality_candidate(candidate, text_raw, "element_text", source_url))
        href_raw = _nationality_raw_from_country_href(absolute_url(source_url or "", candidate.get("href")))
        if href_raw:
            candidate_objects.append(_nationality_candidate(candidate, href_raw, "country_href", source_url))
        flag_asset_code = _nationality_code_from_flag_asset_url(absolute_url(source_url or "", candidate.get("src")))
        if flag_asset_code:
            candidate_objects.append(_nationality_candidate(candidate, flag_asset_code, "flag_asset_code", source_url))
        src_raw = _nationality_raw_from_flag_url(absolute_url(source_url or "", candidate.get("src")))
        if src_raw:
            candidate_objects.append(_nationality_candidate(candidate, src_raw, "image_src", source_url))

    seen_candidates: set[tuple[str, str, str]] = set()
    for item in candidate_objects:
        raw = item["raw"]
        source_kind = item["source_kind"]
        context = item["element_context"]
        key = (raw.casefold(), source_kind, context)
        if key in seen_candidates:
            continue
        seen_candidates.add(key)
        record_source(source_kind)
        accepted, reason = _nationality_candidate_acceptance(item, snapshot)
        if not accepted:
            item["accepted"] = False
            item["reason"] = reason
            diagnostics["rejected_candidates"].append(
                {
                    "raw": raw,
                    "reason": reason,
                    "source_kind": source_kind,
                    "element_context": context,
                }
            )
            diagnostics["warnings"].append(f"nationality_candidate_rejected:{reason}")
            continue
        normalized = normalize_nationality_raw(raw)
        if not normalized["is_missing"]:
            diagnostics["accepted_source_kind"] = source_kind
            diagnostics["accepted_context"] = context
            return normalized, diagnostics
        unmatched_reason = _nationality_rejection_reason(raw, snapshot, default="unmatched_country_candidate")
        if unmatched_reason != "unmatched_country_candidate":
            diagnostics["rejected_candidates"].append(
                {
                    "raw": raw,
                    "reason": unmatched_reason,
                    "source_kind": source_kind,
                    "element_context": context,
                }
            )
            diagnostics["warnings"].append(f"nationality_candidate_rejected:{unmatched_reason}")
            continue
        first_unmatched_raw = first_unmatched_raw or raw
    if first_unmatched_raw:
        missing = missing_nationality()
        missing["raw"] = first_unmatched_raw
        diagnostics["accepted_source_kind"] = "unmatched_high_confidence_metadata"
        return missing, diagnostics
    return missing_nationality(), diagnostics


def _nationality_candidate(
    candidate: dict[str, Any],
    raw: str,
    source_kind: str,
    source_url: str | None,
) -> dict[str, Any]:
    class_name = normalize_space(candidate.get("class_name"))
    element_id = normalize_space(candidate.get("id"))
    src = absolute_url(source_url or "", candidate.get("src"))
    href = absolute_url(source_url or "", candidate.get("href"))
    context_bits = [class_name, element_id, src or "", href or ""]
    element_context = "flag_or_country_context" if _FLAG_CONTEXT_RE.search(" ".join(context_bits)) else "non_flag_context"
    if normalize_space(candidate.get("context")):
        element_context = normalize_space(candidate.get("context"))
    return {
        "raw": normalize_space(raw),
        "source_kind": source_kind,
        "element_context": element_context,
        "tag": normalize_space(candidate.get("tag")),
        "class_name": class_name,
        "id": element_id,
        "src": src,
        "href": href,
    }


def _nationality_candidate_acceptance(item: dict[str, Any], snapshot: dict[str, Any]) -> tuple[bool, str]:
    raw = normalize_space(item.get("raw"))
    if not raw:
        return False, "empty_or_missing_flag_metadata"
    source_kind = str(item.get("source_kind") or "")
    context = str(item.get("element_context") or "")
    high_confidence_context = context != "non_flag_context"
    if source_kind == "element_text":
        if context not in {"profile_header_country_slot", "profile_header_nationality_slot"}:
            return False, _nationality_rejection_reason(raw, snapshot, default="non_flag_context")
    elif source_kind in {"country_href", "flag_asset_code"}:
        if not normalize_country(raw):
            return False, _nationality_rejection_reason(raw, snapshot, default="unmatched_country_code")
    elif source_kind == "image_src":
        if not high_confidence_context or re.fullmatch(r"[A-Za-z]{2}", raw):
            return False, _nationality_rejection_reason(raw, snapshot, default="low_confidence_two_letter_token")
    elif not source_kind.startswith("metadata:") or not high_confidence_context:
        return False, _nationality_rejection_reason(raw, snapshot, default="non_flag_context")
    return True, "accepted"


def _nationality_rejection_reason(raw: str, snapshot: dict[str, Any], *, default: str) -> str:
    raw_clean = normalize_space(raw)
    if re.fullmatch(r"[A-Za-z]{2}", raw_clean):
        return "low_confidence_two_letter_token"
    if _AFFILIATION_HINT_RE.search(raw_clean):
        return "team_or_affiliation_like"
    if _PROMOTION_NAME_HINT_RE.search(raw_clean) or re.fullmatch(r"[A-Z]{3,6}", raw_clean):
        return "promotion_name_like"
    names = {normalize_space(value).casefold() for value in snapshot.get("headings", []) if normalize_space(value)}
    title = normalize_space(snapshot.get("title"))
    if title:
        names.add(normalize_space(re.split(r"\s+[|:-]\s+", title, maxsplit=1)[0]).casefold())
    if raw_clean.casefold() in names or _looks_like_person_name(raw_clean):
        return "fighter_name_like"
    return default


def _looks_like_person_name(raw: str) -> bool:
    parts = [part for part in re.split(r"\s+", raw.strip()) if part]
    if len(parts) < 2 or len(parts) > 4:
        return False
    if any(part.lower() in {"team", "gym", "fc", "mma"} for part in parts):
        return False
    return all(re.match(r"^[A-ZÀ-Þ][A-Za-zÀ-ÿ'\".-]+$", part) for part in parts)


def normalize_nationality_raw(raw: str | None) -> dict[str, Any]:
    raw_value = normalize_space(raw)
    if not raw_value:
        return missing_nationality()
    match = normalize_country(raw_value)
    if match:
        return {
            "raw": raw_value,
            "country_name": match["canonical_name"],
            "country_code": match["display_code"],
            "source": NATIONALITY_SOURCE,
            "is_missing": False,
        }
    missing = missing_nationality()
    missing["raw"] = raw_value
    return missing


def _nationality_raw_from_flag_url(url: str | None) -> str | None:
    if not url:
        return None
    filename = url.rsplit("/", 1)[-1]
    stem = re.sub(r"\.[a-z0-9]+$", "", filename, flags=re.I)
    stem = re.sub(r"(?:^|[-_])(flag|country|nationality)(?:$|[-_])", "-", stem, flags=re.I)
    stem = normalize_space(stem.replace("-", " ").replace("_", " "))
    if not stem:
        return None
    parts = [part for part in re.split(r"\s+", stem) if part]
    for width in (3, 2):
        if len(parts) >= width:
            candidate = " ".join(parts[-width:])
            if not normalize_nationality_raw(candidate)["is_missing"]:
                return candidate
    if len(parts) == 1 and not normalize_nationality_raw(parts[0])["is_missing"]:
        return parts[0]
    return None


def _nationality_raw_from_country_href(url: str | None) -> str | None:
    if not url:
        return None
    match = re.search(r"/search/mma-fighters-by-nationality/country-([a-z]{2})(?:$|[/?#])", url, re.I)
    if not match:
        return None
    code = match.group(1).upper()
    return code if normalize_country(code) else None


def _nationality_code_from_flag_asset_url(url: str | None) -> str | None:
    if not url:
        return None
    match = re.search(r"/assets/flags/([A-Za-z]{2})-[a-f0-9]{12,}\.(?:gif|png|webp|jpe?g)(?:$|[?#])", url, re.I)
    if not match:
        return None
    code = match.group(1).upper()
    return code if normalize_country(code) else None


def _normalize_height_cm(value: str | None) -> int | None:
    """Normalize a Tapology height string to an integer in centimeters.

    Tapology renders heights as e.g. ``5'1" (155cm)`` or ``5'9" (175cm)``. When the
    bracketed centimeter value is present we trust it directly. As a fallback we
    convert from ``feet'inches`` notation. Returns ``None`` when no usable height
    is present.
    """
    if not value:
        return None
    cm_match = re.search(r"(\d{2,3})\s*cm\b", value, re.I)
    if cm_match:
        cm = int(cm_match.group(1))
        return cm if 100 <= cm <= 260 else None
    ft_in_match = re.search(r"(\d+)\s*['’]\s*(\d{1,2})?\s*(?:\"|''|in\b)?", value)
    if ft_in_match:
        feet = int(ft_in_match.group(1))
        inches = int(ft_in_match.group(2) or 0)
        if 3 <= feet <= 8 and 0 <= inches < 12:
            cm = round((feet * 12 + inches) * 2.54)
            return cm if 100 <= cm <= 260 else None
    return None


def _normalize_reach_cm(value: str | None) -> int | None:
    if not value:
        return None
    cm_match = re.search(r"(\d{2,3})\s*cm\b", value, re.I)
    if cm_match:
        cm = int(cm_match.group(1))
        return cm if 100 <= cm <= 260 else None
    inch_match = re.search(r"(\d{2,3}(?:\.\d+)?)\s*(?:\"|in\b)", value, re.I)
    if inch_match:
        cm = round(float(inch_match.group(1)) * 2.54)
        return cm if 100 <= cm <= 260 else None
    return None


def _normalize_lbs(value: str | None) -> float | None:
    if not value:
        return None
    match = re.search(r"(\d{2,3}(?:\.\d+)?)\s*(?:lbs?|pounds?)\b", value, re.I)
    return float(match.group(1)) if match else None


def _sanitize_fighting_out_of(
    value: str | None,
    *,
    association: str | None,
    warnings: list[str],
) -> str | None:
    text = _clean_optional_text(value)
    if not text:
        return None
    lowered = text.lower()
    if lowered.startswith("n/a"):
        _append_warning(warnings, "fighting_out_parse_inconsistent")
        return None
    if _PROFILE_UI_CHROME_RE.search(text):
        _append_warning(warnings, "fighting_out_parse_inconsistent")
        return None
    association_comparable = _comparable_text(association)
    text_comparable = _comparable_text(text)
    if association_comparable and association_comparable in text_comparable and association_comparable != text_comparable:
        _append_warning(warnings, "fighting_out_parse_inconsistent")
        return None
    if _AFFILIATION_HINT_RE.search(text):
        _append_warning(warnings, "fighting_out_parse_inconsistent")
        return None
    return text


def _sanitize_last_weigh_in(value: str | None, *, warnings: list[str]) -> str | None:
    text = _clean_optional_text(value)
    if not text:
        return None
    if re.match(r"photos?\s*:", text, re.I):
        _append_warning(warnings, "last_weigh_in_parse_inconsistent")
        return None
    if _PROFILE_UI_CHROME_RE.search(text):
        _append_warning(warnings, "last_weigh_in_parse_inconsistent")
        return None
    lbs = _normalize_lbs(text)
    if lbs is None or lbs < _LAST_WEIGH_IN_MIN_LBS or lbs > _LAST_WEIGH_IN_MAX_LBS:
        _append_warning(warnings, "last_weigh_in_parse_inconsistent")
        return None
    return text


def _append_warning(warnings: list[str], warning: str) -> None:
    if warning not in warnings:
        warnings.append(warning)


def _comparable_text(value: str | None) -> str:
    value = normalize_space(value).lower()
    return re.sub(r"[^a-z0-9]+", " ", value).strip()


def normalize_profile_for_event_output(
    profile: dict[str, Any],
    *,
    profile_url: str | None = None,
    fallback_name: str | None = None,
    event_date: str | None = None,
    fetched_at: str | None = None,
) -> dict[str, Any]:
    source_url = canonical_url(profile_url or profile.get("source_url")) or profile_url or profile.get("source_url")
    source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(source_url)
    source_fighter_numeric_id = _source_fighter_numeric_id(source_fighter_id)
    summary = profile.get("profile_summary") if isinstance(profile.get("profile_summary"), dict) else {}
    record = profile.get("record_summary") if isinstance(profile.get("record_summary"), dict) else {}
    fight_history = profile.get("fight_history") if isinstance(profile.get("fight_history"), list) else []
    last_fight = next((item for item in fight_history if isinstance(item, dict)), {})
    fetched = profile.get("status") == "success"
    display_name = profile.get("display_name") or fallback_name
    dob = summary.get("date_of_birth")
    age_raw = str(summary.get("age")) if summary.get("age") is not None else None
    pro_raw = record.get("pro_record_raw")
    profile_warnings = sorted(set(profile.get("warnings") or []))
    if not fetched:
        error_reason = normalize_space(profile.get("error_reason"))
        has_specific_fetch_warning = any(str(warning).startswith("profile_fetch_failed:") for warning in profile_warnings)
        has_parse_warning = any(str(warning).startswith("profile_parse") for warning in profile_warnings)
        if error_reason in {"profile_summary_not_found", "redirected_to_fightcenter_index"} and not has_parse_warning:
            profile_warnings.append("profile_parse_failed")
        elif not has_specific_fetch_warning and "profile_fetch_failed" not in profile_warnings:
            profile_warnings.append("profile_fetch_failed")
        profile_warnings = sorted(set(profile_warnings))
    born = parse_location_parts(summary.get("born_location"))
    fighting_out_of = parse_location_parts(summary.get("fighting_out_of"))
    last_fight_inconsistent = "last_fight_parse_inconsistent" in (last_fight.get("warnings") or [])
    if last_fight_inconsistent:
        profile_warnings = sorted(set(profile_warnings + ["last_fight_parse_inconsistent"]))
    nationality = _event_nationality(summary.get("nationality"))
    summary_country_raw = normalize_space(summary.get("country"))
    if nationality.get("is_missing") and summary_country_raw:
        country_field_nationality = normalize_nationality_raw(summary_country_raw)
        country_field_nationality["source"] = "profile_summary_country_field"
        nationality = _event_nationality(country_field_nationality)
    nationality_diagnostics = summary.get("_nationality_diagnostics") if isinstance(summary.get("_nationality_diagnostics"), dict) else {}
    normalized = {
        "source_fighter_id": source_fighter_id,
        "source_fighter_numeric_id": source_fighter_numeric_id,
        "profile_url": source_url,
        "profile_fetched": fetched,
        "profile_fetched_at": fetched_at if fetched else None,
        "name": display_name,
        "display_name": display_name,
        "given_name": None,
        "nickname": summary.get("nickname"),
        "nationality": nationality,
        "country": nationality.get("country_name"),
        "born_raw": summary.get("born_location"),
        "born_city": born.get("city"),
        "born_region": born.get("region"),
        "born_country": born.get("country"),
        "fighting_out_of_raw": summary.get("fighting_out_of"),
        "fighting_out_of_city": fighting_out_of.get("city"),
        "fighting_out_of_region": fighting_out_of.get("region"),
        "fighting_out_of_country": fighting_out_of.get("country"),
        "date_of_birth_raw": summary.get("date_of_birth_raw"),
        "date_of_birth": dob,
        "age_raw": age_raw,
        "age_years_at_scrape": _age_at_date(dob, _iso_date(fetched_at)) if dob else summary.get("age"),
        "age_at_event": _age_at_date(dob, _iso_date(event_date)) if dob else None,
        "height_raw": summary.get("height"),
        "height_cm": summary.get("height_cm"),
        "reach_raw": summary.get("reach"),
        "reach_cm": summary.get("reach_cm"),
        "weight_class": summary.get("weight_class"),
        "last_weigh_in_raw": summary.get("last_weigh_in"),
        "last_weigh_in_lbs": summary.get("last_weigh_in_lbs"),
        "affiliation": summary.get("association"),
        "pro_mma_record_raw": pro_raw,
        "pro_mma_record": _event_record(pro_raw, record),
        "current_streak_raw": None,
        "current_streak": None,
        "last_fight_raw": None if last_fight_inconsistent else last_fight.get("event_name"),
        "last_fight_date": None if last_fight_inconsistent else last_fight.get("fight_date"),
        "last_fight_promotion": None if last_fight_inconsistent else _promotion_hint(last_fight.get("event_name")),
        "career_disclosed_earnings_raw": None,
        "career_disclosed_earnings_usd": None,
        "profile_image_url": summary.get("image_url"),
        "flag_image_url": None,
        "resource_links": [],
        "personal_links": [],
        "social_links": [],
        "profile_warnings": profile_warnings,
    }
    if nationality_diagnostics:
        normalized["_nationality_diagnostics"] = nationality_diagnostics
    return normalized


def event_profile_summary(profile: dict[str, Any] | None) -> dict[str, Any]:
    profile = profile or {}
    return {
        "nickname": profile.get("nickname"),
        "nationality": _event_nationality(profile.get("nationality")),
        "age_at_event": profile.get("age_at_event"),
        "height_cm": profile.get("height_cm"),
        "reach_cm": profile.get("reach_cm"),
        "weight_class": profile.get("weight_class"),
    }


def build_event_profile_enrichment_summary(events: list[dict[str, Any]]) -> dict[str, Any]:
    profiles = [
        profile
        for event in events
        for profile in (event.get("fighter_profiles") or {}).values()
        if isinstance(profile, dict)
    ]
    total = len(profiles)
    successes = sum(1 for profile in profiles if profile.get("profile_fetched") is True)
    failures = sum(1 for profile in profiles if profile.get("profile_fetched") is False)
    warning_counts: dict[str, int] = {}
    failure_reasons: dict[str, int] = {}
    for profile in profiles:
        for warning in profile.get("profile_warnings") or []:
            warning_text = str(warning)
            warning_counts[warning_text] = warning_counts.get(warning_text, 0) + 1
            if warning_text.startswith("profile_fetch_failed"):
                failure_reasons[warning_text] = failure_reasons.get(warning_text, 0) + 1
    coverage = profile_fields_coverage(profiles)
    if total == 0:
        status = "not_requested"
    elif successes == 0:
        status = "failed"
    elif failures and failures >= successes:
        status = "degraded"
    else:
        status = "ok"
    profile_context_counts = _aggregate_profile_context_counts(events)
    unmatched_nationality_raw_values = summarize_unmatched_country_values(
        profile.get("nationality", {}).get("raw")
        for profile in profiles
        if isinstance(profile.get("nationality"), dict) and profile.get("nationality", {}).get("is_missing")
    )
    unmatched_born_country_candidates = summarize_unmatched_location_country_candidates(
        profile.get("born_raw") for profile in profiles
    )
    unmatched_fighting_out_country_candidates = summarize_unmatched_location_country_candidates(
        profile.get("fighting_out_of_raw") for profile in profiles
    )
    unmatched_location_country_candidates = summarize_unmatched_location_country_candidates(
        event.get("location") for event in events if isinstance(event, dict)
    )
    nationality_diagnostics = _aggregate_nationality_extraction_diagnostics(profiles, events)
    return {
        "profile_enrichment_status": status,
        "profile_fetch_attempts": total,
        "profile_fetch_successes": successes,
        "profile_fetch_failures": failures,
        "profile_fetch_failure_reasons": dict(sorted(failure_reasons.items())),
        "profile_parse_successes": successes,
        "profile_parse_failures": sum(
            1
            for profile in profiles
            if profile.get("profile_fetched") is False
            and any(str(warning).startswith("profile_parse") for warning in profile.get("profile_warnings") or [])
        ),
        "profile_context_counts": profile_context_counts,
        "profile_fields_coverage": coverage,
        "unmatched_nationality_raw_values": unmatched_nationality_raw_values,
        "unmatched_location_country_candidates": unmatched_location_country_candidates,
        "unmatched_born_country_candidates": unmatched_born_country_candidates,
        "unmatched_fighting_out_country_candidates": unmatched_fighting_out_country_candidates,
        **nationality_diagnostics,
        "top_profile_warning_codes": [
            {"warning": warning, "count": count}
            for warning, count in sorted(warning_counts.items(), key=lambda item: (-item[1], item[0]))[:10]
        ],
    }


def _aggregate_nationality_extraction_diagnostics(
    profiles: list[dict[str, Any]],
    events: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    rejected_counts: dict[tuple[str, str, str], int] = {}
    reason_counts: dict[str, int] = {}
    source_counts: dict[str, int] = {}
    rejected_total = 0
    for profile in profiles:
        diagnostics = profile.get("_nationality_diagnostics")
        if not isinstance(diagnostics, dict):
            continue
        for source_kind, count in (diagnostics.get("source_counts") or {}).items():
            source_counts[str(source_kind)] = source_counts.get(str(source_kind), 0) + int(count or 0)
        if diagnostics.get("accepted_source_kind"):
            key = f"accepted:{diagnostics.get('accepted_source_kind')}"
            source_counts[key] = source_counts.get(key, 0) + 1
        for rejected in diagnostics.get("rejected_candidates") or []:
            if not isinstance(rejected, dict):
                continue
            raw = normalize_space(rejected.get("raw"))
            if not raw:
                continue
            reason = normalize_space(rejected.get("reason")) or "unknown"
            source_kind = normalize_space(rejected.get("source_kind")) or "unknown"
            context = normalize_space(rejected.get("element_context")) or "unknown"
            rejected_counts[(raw, reason, source_kind, context)] = rejected_counts.get((raw, reason, source_kind, context), 0) + 1
            reason_counts[reason] = reason_counts.get(reason, 0) + 1
            rejected_total += 1
    for event in events or []:
        event_summary = event.get("profile_enrichment") if isinstance(event, dict) else None
        if not isinstance(event_summary, dict):
            continue
        for source_kind, count in (event_summary.get("nationality_extraction_source_counts") or {}).items():
            source_counts[str(source_kind)] = source_counts.get(str(source_kind), 0) + int(count or 0)
        for reason, count in (event_summary.get("rejected_nationality_candidate_reasons") or {}).items():
            reason_counts[str(reason)] = reason_counts.get(str(reason), 0) + int(count or 0)
            rejected_total += int(count or 0)
        for rejected in event_summary.get("rejected_nationality_candidates") or []:
            if not isinstance(rejected, dict):
                continue
            raw = normalize_space(rejected.get("raw"))
            if not raw:
                continue
            reason = normalize_space(rejected.get("reason")) or "unknown"
            source_kind = normalize_space(rejected.get("source_kind")) or "unknown"
            context = normalize_space(rejected.get("element_context")) or "unknown"
            rejected_counts[(raw, reason, source_kind, context)] = rejected_counts.get((raw, reason, source_kind, context), 0) + int(rejected.get("count") or 0)
    rejected_candidates = [
        {
            "raw": raw,
            "count": count,
            "reason": reason,
            "source_kind": source_kind,
            "element_context": context,
        }
        for (raw, reason, source_kind, context), count in sorted(
            rejected_counts.items(),
            key=lambda item: (-item[1], item[0][1], item[0][0].casefold()),
        )[:50]
    ]
    return {
        "rejected_nationality_candidates": rejected_candidates,
        "rejected_nationality_candidate_counts": rejected_total,
        "rejected_nationality_candidate_reasons": dict(sorted(reason_counts.items())),
        "nationality_extraction_source_counts": dict(sorted(source_counts.items())),
    }


def profile_fields_coverage(profiles: list[dict[str, Any]]) -> dict[str, dict[str, int]]:
    total = len(profiles)
    return {
        field: {
            "present": sum(1 for profile in profiles if _event_profile_field_present(profile, field)),
            "missing": total - sum(1 for profile in profiles if _event_profile_field_present(profile, field)),
        }
        for field in EVENT_PROFILE_COVERAGE_FIELDS
    }


def _event_profile_field_present(profile: dict[str, Any], field: str) -> bool:
    value = profile.get(field)
    if field == "nationality":
        return isinstance(value, dict) and not value.get("is_missing")
    return value not in (None, "", [], {})


def _aggregate_profile_context_counts(events: list[dict[str, Any]]) -> dict[str, int]:
    counts = {
        "retained_bout_profiles": 0,
        "skipped_mma_profiles": 0,
        "skipped_non_mma_profiles": 0,
    }
    for event in events:
        event_counts = ((event.get("profile_enrichment") or {}).get("profile_context_counts")) if isinstance(event, dict) else None
        if isinstance(event_counts, dict):
            for key in counts:
                counts[key] += int(event_counts.get(key) or 0)
            continue
        counts["retained_bout_profiles"] += len((event.get("fighter_profiles") or {})) if isinstance(event, dict) else 0
    return counts


def _event_nationality(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return missing_nationality()
    normalized = missing_nationality()
    normalized.update(
        {
            "raw": value.get("raw"),
            "country_name": value.get("country_name"),
            "country_code": value.get("country_code"),
            "source": value.get("source") or NATIONALITY_SOURCE,
            "is_missing": bool(value.get("is_missing")),
        }
    )
    if not normalized.get("raw") and not normalized.get("country_name") and not normalized.get("country_code"):
        normalized["is_missing"] = True
    return normalized


def _source_fighter_numeric_id(source_fighter_id: str | None) -> str | None:
    match = re.fullmatch(r"tapology_fighter_(\d+)", source_fighter_id or "")
    return match.group(1) if match else None


def _event_record(raw: str | None, record: dict[str, Any]) -> dict[str, Any] | None:
    if not raw:
        return None
    return {
        "raw": raw,
        "wins": record.get("wins"),
        "losses": record.get("losses"),
        "draws": record.get("draws"),
        "nc": record.get("no_contests"),
    }


def _iso_date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).date()
    except ValueError:
        try:
            return date.fromisoformat(value[:10])
        except ValueError:
            return None


def _age_at_date(dob: str | None, target: date | None) -> int | None:
    if not dob or not target:
        return None
    try:
        born = date.fromisoformat(dob)
    except ValueError:
        return None
    return target.year - born.year - ((target.month, target.day) < (born.month, born.day))


def _promotion_hint(event_name: str | None) -> str | None:
    event_name = normalize_space(event_name)
    if not event_name:
        return None
    match = re.match(r"([A-Za-z][A-Za-z.'& -]*?)(?:\s+\d|\s*:|$)", event_name)
    return normalize_space(match.group(1)) if match else None


def _extract_record_summary(body_text: str, lines: list[str], warnings: list[str]) -> dict[str, Any]:
    pro_raw = _record_field_value(body_text, lines, ["Pro MMA Record", "Professional MMA Record", "Pro Record", "MMA Record"])
    record = _parse_record(pro_raw)
    if pro_raw and not record:
        warnings.append(f"ambiguous_pro_record:{pro_raw[:80]}")
    amateur_raw = _record_field_value(body_text, lines, ["Amateur MMA Record", "Amateur Record"])
    return {
        "pro_record_raw": pro_raw,
        "wins": record["wins"] if record else None,
        "losses": record["losses"] if record else None,
        "draws": record["draws"] if record else None,
        "no_contests": record["no_contests"] if record else None,
        "amateur_record_raw": amateur_raw,
        "finish_breakdown": _extract_finish_breakdown(lines),
    }


def _extract_fight_history(snapshot: dict[str, Any], source_url: str) -> list[dict[str, Any]]:
    source_id = source_fighter_id_from_url(source_url)
    rows: list[dict[str, Any]] = []
    seen: set[str] = set()
    for row in snapshot.get("rows", []):
        raw_text = normalize_space(row.get("raw_text"))
        if not _looks_like_fight_history_row(row, raw_text, source_id):
            continue
        item = _parse_fight_history_row(row, raw_text, source_url, source_id)
        key = _fight_history_item_key(item)
        if key in seen:
            continue
        seen.add(key)
        rows.append(item)
    # The explicit cards are authoritative. A text fallback would lose sport/division
    # boundaries and introduce duplicate amateur or non-MMA rows.
    body_items = [] if any(r.get("data_bout_id") for r in snapshot.get("rows", [])) else _extract_fight_history_from_body(snapshot.get("body_text") or "", source_url)
    for item in body_items:
        key = _fight_history_item_key(item)
        if key in seen:
            continue
        seen.add(key)
        rows.append(item)
    # Merge a body-text interpretation only when it describes the identical
    # raw row. Never use name similarity to attach canonical identities.
    merged: list[dict[str, Any]] = []
    for item in rows:
        same = next((r for r in merged if r.get("raw_text") == item.get("raw_text") and r.get("fight_date") == item.get("fight_date") and r.get("result") == item.get("result")), None)
        if same is None:
            merged.append(item)
        else:
            for key, value in item.items():
                if same.get(key) in (None, "", "unknown") and value not in (None, "", "unknown"):
                    same[key] = value
    return merged


def _fight_history_item_key(item: dict[str, Any]) -> str:
    if item.get("source_bout_id"):
        return item["source_bout_id"] + "|" + (item.get("result") or "")
    return "|".join(
        [
            item.get("fight_date") or "",
            item.get("event_name") or "",
            item.get("opponent_name") or "",
            item.get("result") or "",
            item.get("method") or "",
        ]
    )


def _extract_fight_history_from_body(body_text: str, source_url: str) -> list[dict[str, Any]]:
    text = normalize_space(body_text)
    # Classic Tapology layout uses a "PROFESSIONAL BOUTS" section heading; newer
    # MMA-only layout uses "{FIGHTER NAME} MMA FIGHT RECORD"; mixed combat-sports
    # athletes (MMA + Boxing + BKFC + Muay Thai) use "{FIGHTER NAME} COMBAT SPORTS
    # RECORD". All three render fights as add_circle-separated entries. Non-MMA
    # rows captured under COMBAT SPORTS RECORD must go through staging/review
    # before any canonical import.
    match = re.search(
        r"\bPROFESSIONAL BOUTS\b(.*?)(?:\bAMATEUR BOUTS\b|\bPRO MMA STATISTICS\b|\bMMA RECORD BY\b|$)",
        text,
        re.I,
    )
    if not match:
        match = re.search(
            r"\b(?:MMA FIGHT|COMBAT SPORTS) RECORD\b(.*?)(?:\bAMATEUR BOUTS\b|\bMMA RECORD BY\b|$)",
            text,
            re.I,
        )
    if not match:
        return []
    rows: list[dict[str, Any]] = []
    for segment in re.split(r"\s+add_circle\s+", normalize_space(match.group(1))):
        item = _parse_body_fight_segment(segment)
        if item:
            rows.append(item)
    return rows


def _parse_body_fight_segment(segment: str) -> dict[str, Any] | None:
    segment = normalize_space(segment.replace("Â·", " "))
    # New Tapology layout interleaves a "VIEW MODE … Sport Filters: MMA Grapple Custom"
    # preamble with its own add_circle marker. Strip everything before the first
    # result token + recognizable bout payload so the first fight still parses.
    if not re.match(r"^(W|L|D|NC|C)\b", segment):
        m_start = re.search(
            r"\b(W|L|D|NC|C)\s+(?:SUB|DEC|KO/TKO|TKO|KO|DQ|Cancelled|[A-Z][a-z])",
            segment,
        )
        if not m_start:
            return None
        segment = segment[m_start.start():]
    date_raw = _date_raw(segment)
    fight_date = parse_date_to_iso(date_raw)
    result_token = re.match(r"^(W|L|D|NC|C)\b", segment).group(1)  # type: ignore[union-attr]
    result = {"W": "win", "L": "loss", "D": "draw", "NC": "no_contest", "C": "cancelled"}.get(result_token)
    without_result = re.sub(r"^(W|L|D|NC|C)\b\s*", "", segment, count=1)
    method_token = first_token_method(without_result)
    if method_token:
        without_result = re.sub(r"^(SUB|DEC|KO/TKO|TKO|KO|DQ)\b\s*", "", without_result, count=1, flags=re.I)
    # New Tapology layout inserts a short numeric token (fight order / rounds) between
    # the method abbreviation and the opponent name. Strip it so the opponent name
    # match below doesn't pick up "1 Yuka Okutomi".
    without_result = re.sub(r"^\d{1,3}\b\s+(?=[A-Za-z])", "", without_result)
    opponent_match = re.match(
        rf"(.+?)(?="
        rf"\s+{ROW_RECORD_PATTERN}"
        rf"|\s+Cancelled Bout\b"
        rf"|\s+(?:KO/TKO|TKO|KO|Submission|Decision|Draw|No\s+Contest)\b"
        rf"|\s+(?:Grappling|Boxing|Muay\s*Thai|Bare\s*Knuckle|Modified\s*Rules|Custom\s*Rules)\b"
        rf"|\s+MMA\s*\("
        rf"|\s+\d{{1,2}}:\d{{2}}\b"
        rf"|\s+R\d+\b"
        rf"|\s+{DATE_PATTERN}"
        rf"|$)",
        without_result,
        re.I,
    )
    opponent_name = normalize_space(opponent_match.group(1)) if opponent_match else None
    records = _records_from_text(segment)
    method_raw = _method_from_token(method_token) or _extract_method_raw(segment)
    method = normalize_method(method_raw or "")
    method.pop("warnings", None)
    event_name = extract_event_name_from_raw_text(segment, date_raw)
    item = {
        "fight_date": fight_date,
        "event_name": event_name,
        "opponent_name": opponent_name,
        "opponent_url": None,
        "result": result,
        "method": method.get("method_detail") or method.get("method_category") or method_raw,
        "method_category": method.get("method_category"),
        "round": parse_round(segment),
        "time": parse_time(segment),
        "weight_class": _extract_weight_class(segment, [segment]),
        "is_cancelled": result == "cancelled",
        "is_upcoming": False,
        "is_amateur": False,
        "is_professional": False,
        "is_overturned": bool(re.search(r"\boverturned\b|\bno contest\b|\bresult changed\b", segment, re.I)),
        "opponent_record_raw": records[1] if len(records) > 1 else None,
        "prefight_record_raw": records[0] if records else None,
        "raw_text": segment[:1000],
    }
    return {key: value for key, value in item.items() if value is not None and value != []}


def _parse_fight_history_row(row: dict[str, Any], raw_text: str, source_url: str, source_id: str | None) -> dict[str, Any]:
    links = row.get("links") or []
    event_link = next((link for link in links if "/fightcenter/events/" in (link.get("href") or "")), None)
    bout_link = next((link for link in links if "/fightcenter/bouts/" in (link.get("href") or "")), None)
    opponent_link = next(
        (
            link
            for link in links
            if "/fightcenter/fighters/" in (link.get("href") or "")
            and source_fighter_id_from_url(link.get("href")) != source_id
        ),
        None,
    )
    # Tapology's new MMA-record DOM often renders the entire fight list inside a
    # single container element. Without trimming we'd derive per-row fields
    # (method, time, is_cancelled, is_overturned) from a raw_text that mixes
    # several fights together — e.g. a clean "W DEC" row would inherit
    # is_cancelled=True from a later "Cancelled Bout" entry. The shared scope
    # helper also strips leading section headings (PROFESSIONAL BOUTS /
    # COMBAT SPORTS RECORD / VIEW MODE) and truncates at trailing headings
    # (AMATEUR BOUTS) so we never inherit is_amateur=True from a section that
    # does not belong to this fight.
    raw_text = scope_fight_row_text(raw_text)
    warnings: list[str] = []
    without_result = re.sub(r"^(W|L|D|NC|C)\b\s*", "", raw_text, count=1)
    badge_method = _method_from_token(first_token_method(without_result))
    method_raw = badge_method or _extract_method_raw(raw_text) or ""
    # The bout anchor supplies the actual detail separately from the short badge.
    detail = normalize_space((bout_link or {}).get("text", "")).split("·", 1)[0].strip()
    if badge_method and detail and not re.fullmatch(r"(?:\d{1,2}:\d{2}|R\d+|[WL]|NC)", detail, re.I):
        method_raw = badge_method + ", " + detail
    method = normalize_method(method_raw)
    method.pop("warnings", None)
    records = _records_from_text(raw_text)
    flags_text = raw_text.lower()
    event_link_text = normalize_space(event_link.get("text")) if event_link else None
    event_link_is_in_scope = bool(event_link_text and event_link_text.lower() in raw_text.lower())
    if event_link and not event_link_is_in_scope:
        warnings.append("last_fight_parse_inconsistent")
    event_url = absolute_url(source_url, event_link.get("href")) if event_link and event_link_is_in_scope else None
    bout_url = absolute_url(source_url, bout_link.get("href")) if bout_link else None
    item = {
        "fight_date": parse_date_to_iso(_date_raw(raw_text)),
        "event_name": (
            event_link_text
            if event_link and event_link_is_in_scope
            else (_event_name_from_cells(row.get("cells") or []) if not event_link else None)
        ),
        "event_url": event_url,
        "source_event_id": source_event_id_from_url(event_url),
        "bout_url": bout_url,
        "source_bout_id": source_bout_id_from_url(bout_url) or (f"tapology_bout_{row['data_bout_id']}" if str(row.get("data_bout_id") or "").isdigit() else None),
        "source_identity_evidence": "profile_dom_bout_id" if row.get("data_bout_id") else None,
        "opponent_name": normalize_space(opponent_link.get("text")) if opponent_link else None,
        "opponent_url": absolute_url(source_url, opponent_link.get("href")) if opponent_link else None,
        "opponent_source_id": source_fighter_id_from_url(opponent_link.get("href")) if opponent_link else None,
        "result": row.get("data_status") if row.get("data_status") in {"win", "loss", "draw", "no_contest"} else _extract_history_result(raw_text),
        "discipline": ("mma" if row.get("data_sport") == "mma" else "other") if row.get("data_sport") else None,
        "source_division": row.get("data_division"),
        "source_sport": row.get("data_sport"),
        "classification_evidence": "profile_dom_classification" if row.get("data_sport") or row.get("data_division") else None,
        "is_exhibition": bool(re.search(r"\bexhibition mma\b", raw_text, re.I)),
        "record_eligible": False if re.search(r"\brecord ineligible mma\b", raw_text, re.I) else None,
        "record_eligibility_evidence": "source_profile_record_ineligible_mma" if re.search(r"\brecord ineligible mma\b", raw_text, re.I) else None,
        "final_result_verified": row.get("data_status") == "no_contest",
        "final_result_evidence": "profile_dom_final_no_contest" if row.get("data_status") == "no_contest" else None,
        "method": method.get("method_detail") or method.get("method_category") or _extract_method_raw(raw_text),
        "method_category": method.get("method_category"),
        "round": parse_round(raw_text),
        "time": parse_time(raw_text),
        "weight_class": _extract_weight_class(raw_text, [raw_text]),
        "is_cancelled": row.get("data_status") in {"cancelled", "canceled"} or "cancel" in flags_text or "scrapped" in flags_text,
        "is_upcoming": row.get("data_status") == "upcoming" or bool(re.search(r"\bupcoming\b|\bvs\.?\b", flags_text)) and not re.search(r"\b[wl]\b", flags_text),
        "is_amateur": row.get("data_division") in {"am", "amateur"} or row.get("history_scope") == "amateur" or "amateur" in flags_text,
        "is_professional": row.get("data_division") == "pro" if row.get("data_division") else (row.get("history_scope") == "professional_mma" or bool(re.search(r"\bpro(?:fessional)?\b", flags_text))),
        "is_overturned": bool(re.search(r"\boverturned\b|\bno contest\b|\bresult changed\b", flags_text)),
        "opponent_record_raw": records[-1] if len(records) > 1 else None,
        "prefight_record_raw": records[0] if records else None,
        "raw_text": raw_text[:1000],
        "warnings": warnings,
    }
    if not item.get('classification_evidence') and row.get('row_boundary_verified') is True and row.get('history_scope') == 'professional_mma' and bout_url:
        item.update(source_sport='mma', discipline='mma', source_division='pro', is_professional=True,
                    classification_evidence='profile_section_classification',
                    classification_proof={'section':'professional_mma','row_boundary_verified':True})
    return {key: value for key, value in item.items() if value is not None and value != []}


def _looks_like_fight_history_row(row: dict[str, Any], raw_text: str, source_id: str | None) -> bool:
    if str(row.get("data_bout_id") or "").isdigit() and raw_text:
        return True
    if len(raw_text) < 20:
        return False
    hrefs = [link.get("href") or "" for link in row.get("links") or []]
    has_event = any("/fightcenter/events/" in href for href in hrefs)
    has_bout = any("/fightcenter/bouts/" in href for href in hrefs)
    has_opponent = any(
        "/fightcenter/fighters/" in href and source_fighter_id_from_url(href) != source_id for href in hrefs
    )
    has_date = bool(re.search(DATE_PATTERN, raw_text, re.I))
    has_result_or_method = bool(re.search(r"\b(W|L|D|NC|Draw|No Contest|Decision|KO/TKO|TKO|KO|Submission|Cancelled|Canceled)\b", raw_text, re.I))
    return (has_event or has_bout or has_opponent) and (has_date or has_result_or_method)


def _field_value(body_text: str, lines: list[str], labels: list[str]) -> str | None:
    for line in lines:
        for label in labels:
            value = _line_label_value(line, label)
            if value:
                return value
    for label in labels:
        stop_labels = [
            "Nickname",
            "Age",
            "Date of Birth",
            "DOB",
            "Birthday",
            "Born",
            "Born Location",
            "Birthplace",
            "Born In",
            "Fighting out of",
            "Fights out of",
            "Association",
            "Team",
            "Gym",
            "Affiliation",
            "Height",
            "Reach",
            "Weight",
            "Weight Class",
            "Last Weigh-In",
            "Last Weigh In",
            "Country",
            "Nationality",
            "Resource Links",
            "Personal Links",
            "Regional MMA Rankings",
            "Rankings FAQ",
            "College",
            "Foundation Style",
            "Head Coach",
            "Other Coaches",
            "Last Fight",
            "Career Disclosed Earnings",
            "Pro MMA Record",
            "Amateur MMA Record",
        ]
        value = _body_label_value(body_text, label, stop_labels)
        if value:
            return value
    return None


def _record_field_value(body_text: str, lines: list[str], labels: list[str]) -> str | None:
    for index, line in enumerate(lines):
        for label in labels:
            if re.fullmatch(re.escape(label) + r"\s*:?", line, re.I) and index + 1 < len(lines):
                following = re.fullmatch(RECORD_PATTERN, lines[index + 1], re.I)
                if following:
                    return normalize_space(following.group(0))
            if label.lower() == "mma record" and re.search(r"\bamateur\s+mma\s+record\b", line, re.I):
                continue
            if re.search(re.escape(label), line, re.I):
                match = re.search(RECORD_PATTERN, line, re.I)
                if match:
                    return normalize_space(match.group(0))
    for label in labels:
        # A generic MMA Record label must not match inside Amateur MMA Record.
        record_body = re.sub(r"\bamateur\s+mma\s+record\s*:[^|]*?(?=\b(?:Current|Height|Weight|Age|Pro)\b|$)", "", body_text, flags=re.I) if label.lower() == "mma record" else body_text
        snippet = _body_label_value(record_body, label, ["Amateur MMA Record", "Pro MMA Record", "Record", "Current Ranking", "Height", "Weight"])
        if snippet:
            match = re.search(RECORD_PATTERN, snippet, re.I)
            if match:
                return normalize_space(match.group(0))
    return None


def _line_label_value(line: str, label: str) -> str | None:
    match = re.search(rf"\b{re.escape(label)}\b\s*:\s*(.+)$", line, re.I)
    if not match:
        match = re.search(rf"^{re.escape(label)}\s+(.+)$", line, re.I)
    if not match:
        return None
    value = normalize_space(match.group(1))
    if not value or value.lower() == label.lower():
        return None
    if label.lower() == "age" and not re.search(r"\d", value):
        return None
    return _trim_value(value)


def _body_label_value(body_text: str, label: str, stop_labels: list[str]) -> str | None:
    stops = "|".join(re.escape(stop) for stop in stop_labels if stop.lower() != label.lower())
    match = re.search(
        rf"\b{re.escape(label)}\b\s*:\s*(.*?)(?=\s*(?:[|•]\s*)?(?:{stops})\b|$)",
        body_text,
        re.I,
    )
    if not match:
        return None
    return _trim_value(match.group(1))


def _trim_value(value: str) -> str | None:
    value = normalize_space(value)
    value = re.split(
        r"\s+\|\s+(?:Reach|Weight Class|Last Weigh-?In|Affiliation|Last Fight|Born|Fighting out of)\b:?",
        value,
        maxsplit=1,
        flags=re.I,
    )[0]
    value = re.sub(r"\s+(?:Edit|Wiki|Update|Photos|Videos|News)\b.*$", "", value, flags=re.I)
    value = value.strip(" :-|")
    if not value or value.lower() in {"n/a", "na", "none", "unknown", "-"}:
        return None
    return value[:240]


def _clean_optional_text(value: str | None) -> str | None:
    value = _trim_value(value or "")
    return value if value else None


def _sanitize_association(value: str | None, *, warnings: list[str]) -> str | None:
    cleaned = _clean_optional_text(value)
    if not cleaned:
        return None
    if _AFFILIATION_CHROME_RE.search(cleaned):
        _append_warning(warnings, "association_parse_inconsistent")
        return None
    return cleaned


def _extract_image_url(snapshot: dict[str, Any], source_url: str) -> str | None:
    # Fighter images are intentionally not emitted by the scraper. The WordPress
    # side uses manual media selection, and Tapology pages can expose unrelated
    # ad/social/logo assets through the same generic image/meta surfaces.
    return None


def is_tapology_placeholder_profile_image(url: str | None) -> bool:
    value = normalize_space(url)
    if not value:
        return False
    return bool(_PLACEHOLDER_PROFILE_IMAGE_RE.search(value))


def _parse_record(raw: str | None) -> dict[str, int] | None:
    if not raw:
        return None
    match = re.search(RECORD_PATTERN, raw, re.I)
    if not match:
        return None
    return {
        "wins": int(match.group(1)),
        "losses": int(match.group(2)),
        "draws": int(match.group(3)),
        "no_contests": int(match.group(4) or 0),
    }


def _extract_finish_breakdown(lines: list[str]) -> dict[str, str] | None:
    breakdown: dict[str, str] = {}
    for line in lines:
        if not re.search(r"\b(KO/TKO|TKO|KO|Submission|Decision)\b", line, re.I):
            continue
        if not re.search(r"\b(win|finish|breakdown|by)\b", line, re.I):
            continue
        for label, key in [("KO/TKO", "ko_tko"), ("TKO", "ko_tko"), ("KO", "ko_tko"), ("Submission", "submission"), ("Decision", "decision")]:
            match = re.search(rf"\b{re.escape(label)}s?\b\s*:?\s*(\d+|\d+%)", line, re.I)
            if match:
                breakdown[key] = normalize_space(match.group(1))
    return breakdown or None


def first_token_method(value: str) -> str | None:
    match = re.match(r"(SUB|DEC|KO/TKO|TKO|KO|DQ)\b", value, re.I)
    return normalize_space(match.group(1)).upper() if match else None


def _method_from_token(value: str | None) -> str | None:
    if not value:
        return None
    return {
        "SUB": "Submission",
        "DEC": "Decision",
        "KO/TKO": "KO/TKO",
        "TKO": "TKO",
        "KO": "KO",
        "DQ": "DQ",
    }.get(value.upper())


def _extract_weight_class(body_text: str, lines: list[str]) -> str | None:
    explicit = _field_value(body_text, lines, ["Weight Class", "Division"])
    if explicit:
        match = re.search(WEIGHT_CLASS_PATTERN, explicit, re.I)
        return _normalize_weight_class(normalize_space(match.group(1)) if match else explicit)
    for line in lines:
        if re.search(r"\b(weight class|division)\b", line, re.I):
            match = re.search(WEIGHT_CLASS_PATTERN, line, re.I)
            if match:
                return _normalize_weight_class(normalize_space(match.group(1)))
    return None


def _normalize_weight_class(value: str | None) -> str | None:
    value = normalize_space(value)
    if not value:
        return None
    shorthand = {
        "straw": "Strawweight",
        "fly": "Flyweight",
        "bantam": "Bantamweight",
        "feather": "Featherweight",
        "light": "Lightweight",
        "welter": "Welterweight",
        "middle": "Middleweight",
        "heavy": "Heavyweight",
    }
    return shorthand.get(value.lower(), value)


def _extract_int(value: str | None) -> int | None:
    if not value:
        return None
    match = re.search(r"\b(\d{1,3})\b", value)
    return int(match.group(1)) if match else None


def _date_raw(text: str) -> str | None:
    match = re.search(DATE_PATTERN, text, re.I)
    return normalize_space(match.group(0)) if match else None


def _records_from_text(text: str) -> list[str]:
    # ROW_RECORD_PATTERN is the row-level variant that also accepts a 2-number
    # "W-L" form (e.g. "2-2" opponent record) on top of the 3-number "W-L-D"
    # form used in fighter pro records. RECORD_PATTERN only matches W-L-D and
    # would silently drop opponent prefight W-L records from history rows.
    return [normalize_space(match.group(0)) for match in re.finditer(ROW_RECORD_PATTERN, text, re.I)]


def _extract_history_result(text: str) -> str | None:
    match = re.search(r"\b(W|L|D|NC)\b", text)
    if match:
        return {"W": "win", "L": "loss", "D": "draw", "NC": "no_contest"}.get(match.group(1))
    if re.search(r"\bdraw\b", text, re.I):
        return "draw"
    if re.search(r"\bno contest\b", text, re.I):
        return "no_contest"
    if re.search(r"\bcancel", text, re.I):
        return "cancelled"
    return None


def _extract_method_raw(text: str) -> str | None:
    match = re.search(
        r"\b(KO/TKO|TKO|KO|Submission|Decision|DQ|Disqualification|No Contest|NC|Draw|Cancelled|Canceled)\b"
        r"(?:,\s*([^0-9|]+?))?(?=\s+\d{1,2}:\d{2}|\s+Round|\s+R\d|\s+\d{4}|\s+[WL]\b|$)",
        text,
        re.I,
    )
    if not match:
        return None
    return normalize_space(", ".join(part for part in match.groups() if part))


def _event_name_from_cells(cells: list[str]) -> str | None:
    for cell in cells:
        if not re.search(DATE_PATTERN, cell, re.I) and len(cell) > 3 and not re.fullmatch(r"[WLD]|NC", cell):
            return normalize_space(cell)
    return None


def extract_event_name_from_raw_text(raw_text: str | None, date_raw: str | None = None) -> str | None:
    """Return a best-effort event_name extracted from a Tapology fight-row segment.

    The Tapology body layout encodes each professional fight as
    ``<result> <method abbrev> [<order>] <opponent> [<records>]
    <method detail> [<round/time>] <event name> <date>``. We anchor the
    event-name span at the LAST occurrence of an "end of method" marker
    (``R<n>``, a bout time like ``3:32``, ``Cancelled Bout``,
    ``Decision · Unanimous/Split/Majority/Technical/Judges/Points/Scores``,
    ``Draw · Draw`` or ``No Contest``). Everything between that marker and the
    fight date is the candidate event name.

    Returns ``None`` (no guessing) when:
      * no terminator is found,
      * the candidate string is empty after trimming,
      * the candidate is a known noise token,
      * the candidate is only a Tapology record string like ``12-3-1``.
    """

    if not raw_text:
        return None
    text = raw_text
    if date_raw:
        idx = text.find(date_raw)
        if idx > 0:
            text = text[:idx]
    else:
        # Fall back to the row-level date pattern when the caller did not pass
        # a pre-parsed date_raw (e.g. when post-processing a stored raw_text).
        date_match = re.search(DATE_PATTERN, text, re.I)
        if date_match:
            text = text[: date_match.start()]
    text = text.strip(" -|·・•·")
    if not text:
        return None
    matches = list(_EVENT_END_MARKERS_RE.finditer(text))
    if not matches:
        return None
    candidate = text[matches[-1].end():]
    candidate = candidate.strip(" -|\u00b7\u30fb\u2022")
    # Drop the Tapology icon-font residue that sometimes trails event labels
    # (e.g. ``Smackgirl 2007 person_off``).
    candidate = re.sub(r"\b(?:person_off|add_circle|filter_list|view_list|casino)\b", "", candidate, flags=re.I)
    candidate = normalize_space(candidate.strip(" -|·・•·"))
    if not candidate:
        return None
    if candidate.lower() in _GENERIC_EVENT_REJECT:
        return None
    if re.fullmatch(r"\d+(?:\s*-\s*\d+){1,3}", candidate):
        return None
    if re.fullmatch(r"#\s*\d+", candidate):
        return None
    opponent_name = _opponent_name_from_raw_text_prefix(text)
    if opponent_name and _normalize_comparable_name(opponent_name) == _normalize_comparable_name(candidate):
        return None
    return candidate


def _opponent_name_from_raw_text_prefix(text: str) -> str | None:
    without_result = re.sub(r"^(W|L|D|NC|C)\b\s*", "", normalize_space(text), count=1, flags=re.I)
    without_result = re.sub(r"^(SUB|DEC|KO/TKO|TKO|KO|DQ)\b\s*", "", without_result, count=1, flags=re.I)
    without_result = re.sub(r"^\d{1,3}\b\s+(?=[A-Za-z])", "", without_result)
    opponent_match = re.match(
        rf"(.+?)(?="
        rf"\s+{ROW_RECORD_PATTERN}"
        rf"|\s+Cancelled Bout\b"
        rf"|\s+(?:KO/TKO|TKO|KO|Submission|Decision|Win|Loss|Draw|No\s+Contest)\b"
        rf"|\s+(?:Grappling|Boxing|Muay\s*Thai|Bare\s*Knuckle|Modified\s*Rules|Custom\s*Rules)\b"
        rf"|\s+MMA\s*\("
        rf"|\s+\d{{1,2}}:\d{{2}}\b"
        rf"|\s+R\d+\b"
        rf"|\s+{DATE_PATTERN}"
        rf"|$)",
        without_result,
        re.I,
    )
    if not opponent_match:
        return None
    opponent = normalize_space(opponent_match.group(1))
    return opponent or None


def _normalize_comparable_name(value: str | None) -> str:
    value = normalize_space(value).lower()
    return re.sub(r"[^a-z0-9]+", " ", value).strip()
