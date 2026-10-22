from __future__ import annotations

import json
import re
import random
import time
from copy import deepcopy
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urljoin

from .browser import (
    classify_challenge_indicators,
    detect_hard_block,
    dismiss_blocking_ui,
    is_manual_solvable_challenge,
    launch_browser,
    save_debug_artifacts,
    wait_for_manual_challenge,
)
from .config import FIGHTCENTER_URL, ScraperConfig
from .contract import populate_bout_contract_fields, populate_event_contract_fields, source_bout_numeric_id, source_fighter_numeric_id
from .dedupe import dedupe_events
from .event_coverage import ROWS_JS, PARSER_VERSION, assess as assess_event, classification as row_classification, completed_result as row_completed_result, document as coverage_document
from .fighter_profiles import (
    build_event_profile_enrichment_summary,
    event_profile_summary,
    failed_profile,
    normalize_profile_for_event_output,
    parse_profile_snapshot,
    snapshot_profile_page,
)
from .logging_utils import log
from .models import RESULTS_SCHEMA_VERSION, UPCOMING_SCHEMA_VERSION
from .normalize import (
    explicit_non_mma_event_reason,
    has_completed_result,
    is_explicit_non_mma_bout,
    is_ufc_promotion,
    normalize_method,
    parse_prefight_record,
    parse_round,
    parse_time,
    professional_status,
    prefight_record_warnings,
    result_time_semantics,
)
from .selectors import (
    BOUT_LINK_SELECTOR,
    EVENT_LINK_SELECTOR,
    FILTER_ALIASES,
    FIGHTER_LINK_SELECTOR,
    FIGHTCENTER_EVENTS_SELECTOR,
    FIGHTCENTER_FORM_ID,
    FIGHTCENTER_LOAD_MORE_SELECTOR,
    PREFERRED_FIGHT_CARD_SELECTORS,
)
from .storage import DataStore, ensure_data_dirs, should_recheck_event, write_json
from .utils import (
    absolute_url,
    canonical_url,
    fallback_bout_id,
    first_match,
    normalize_name,
    normalize_space,
    parse_location_parts,
    parse_date_to_iso,
    source_bout_id_from_url,
    source_event_id_from_url,
    source_fighter_id_from_url,
    url_hash,
    utc_now,
)


class FatalScrapeError(RuntimeError):
    pass


class FilterApplyError(FatalScrapeError):
    pass


PRESERVE_NULL_KEYS = {
    "source_bouts_total",
    "source_mma_bouts_total",
    "source_rows_seen",
    "source_mma_rows_seen",
    "event_datetime_raw",
    "event_time_raw",
    "event_timezone_raw",
    "timezone_raw",
    "reason",
    "source_bout_id",
    "source_bout_numeric_id",
    "bout_url",
    "fighter_a_name",
    "fighter_a_url",
    "fighter_a_source_fighter_id",
    "fighter_a_source_fighter_numeric_id",
    "fighter_b_name",
    "fighter_b_url",
    "fighter_b_source_fighter_id",
    "fighter_b_source_fighter_numeric_id",
    "card_position",
    "weight_lbs",
    "weight_class",
    "profile_ref",
    "profile_summary",
    "source_fighter_id",
    "source_fighter_numeric_id",
    "profile_url",
    "profile_fetched_at",
    "name",
    "display_name",
    "given_name",
    "nickname",
    "nationality",
    "raw",
    "country_name",
    "country_code",
    "country",
    "born_raw",
    "born_city",
    "born_region",
    "born_country",
    "fighting_out_of_raw",
    "fighting_out_of_city",
    "fighting_out_of_region",
    "fighting_out_of_country",
    "date_of_birth_raw",
    "date_of_birth",
    "age_raw",
    "age_years_at_scrape",
    "age_at_event",
    "height_raw",
    "height_cm",
    "reach_raw",
    "reach_cm",
    "last_weigh_in_raw",
    "last_weigh_in_lbs",
    "affiliation",
    "pro_mma_record_raw",
    "pro_mma_record",
    "current_streak_raw",
    "current_streak",
    "last_fight_raw",
    "last_fight_date",
    "last_fight_promotion",
    "career_disclosed_earnings_raw",
    "career_disclosed_earnings_usd",
    "profile_image_url",
    "flag_image_url",
    "city",
    "region",
    "country",
}


def should_skip_results_event_no_usable_bouts(event: dict[str, Any]) -> bool:
    if event.get("bouts"):
        return False
    return skipped_results_event_category(event) in {'non_mma','all_amateur','excluded_mixed_card'}


def skipped_results_event_is_non_mma_or_no_results(event: dict[str, Any]) -> bool:
    return skipped_results_event_category(event) is not None


def skipped_results_event_category(event: dict[str, Any]) -> str | None:
    warnings = [str(warning) for warning in event.get("warnings", [])]
    skipped_bouts = event.get("skipped_bouts", []) if isinstance(event.get("skipped_bouts"), list) else []
    skipped_reasons = [str(item.get("reason")) for item in skipped_bouts if isinstance(item, dict)]
    if any(reason in {'unknown','missing_required_data','no_result'} for reason in skipped_reasons) or any(w.startswith(('bouts_skipped_unknown_professional_status:','fight_card_missing')) for w in warnings):
        return 'no_usable_pro_mma_bouts' if 'no_result' not in skipped_reasons else 'no_results'
    if skipped_reasons and set(skipped_reasons).issubset({'amateur','junior','non_mma','cancelled'}):
        if set(skipped_reasons)=={'amateur'}:return 'all_amateur'
        if set(skipped_reasons)=={'non_mma'}:return 'non_mma'
        return 'excluded_mixed_card'
    if any(warning.startswith(("event_skipped_non_mma:", "bouts_skipped_non_mma:")) for warning in warnings) or (
        skipped_reasons and all(reason == "non_mma" for reason in skipped_reasons)
    ):
        return "non_mma"
    if any(warning.startswith("bouts_skipped_no_result:") for warning in warnings) or "no_result" in skipped_reasons:
        return "no_results"
    if skipped_reasons and all(reason == "amateur" for reason in skipped_reasons):
        return "all_amateur"
    if any(warning.startswith("bouts_skipped_amateur:") for warning in warnings):
        return "all_amateur"
    if any(
        warning.startswith(
            (
                "bouts_skipped_unknown_professional_status:",
                "fight_card_missing",
            )
        )
        for warning in warnings
    ):
        return "no_usable_pro_mma_bouts"
    if skipped_reasons:
        return "no_usable_pro_mma_bouts"
    return None


class TapologyScraper:
    def __init__(self, config: ScraperConfig):
        self.config = config
        self._debug_doc: dict[str, Any] | None = None
        self._store = DataStore(config.data_dir) if config.data_dir else DataStore()
        self._state_bundle: dict[str, Any] | None = None
        self._from_date = self._parse_iso_date(self.config.from_date)
        self._until_date = self._parse_iso_date(self.config.until_date)
        self._force_recheck_events = {
            source_event_id_from_url(value) or value for value in self.config.force_recheck_events
        }
        self._fighter_profile_cache: dict[str, dict[str, Any]] = {}
        self._persistent_profile_cache: dict[str, Any] | None = None
        self._profile_fetches_started = 0
        self._profile_fetch_failures_seen = 0
        self._consecutive_access_failures = 0
        self._event_fetch_failures_seen = 0
        self._access_stop_reason: str | None = None
        self._pause_counts = {
            "page_pauses_count": 0,
            "event_pauses_count": 0,
            "profile_pauses_count": 0,
            "profile_batch_pauses_count": 0,
            "total_pause_seconds": 0.0,
            "profile_fetches_before_cap": 0,
        }
        self._pagination_counts = {
            "load_more_clicks": 0,
            "load_more_successes": 0,
            "load_more_no_new_events": 0,
            "load_more_timeouts": 0,
            "load_more_fallback_attempts": 0,
            "load_more_fallback_successes": 0,
            "load_more_fallback_failures": 0,
            "load_more_popup_pages_closed": 0,
            "load_more_overlay_dismissals": 0,
            "unique_listing_event_urls_seen": 0,
            "duplicate_listing_event_urls_seen": 0,
        }
        self._cache_counts = {
            "events_skipped_known_cache": 0,
            "profile_cache_hits": 0,
            "profile_cache_misses": 0,
            "profile_cache_expired": 0,
            "profile_cache_disabled": 0,
        }
        self._active_summary: dict[str, Any] | None = None
        self._coverage_entries: dict[str, dict] = {}
        self._active_run_dir: Path | None = None

    def inspect_filters(self) -> list[dict[str, Any]]:
        self._store.ensure_dirs()
        playwright, browser, context = launch_browser(self.config)
        try:
            page = context.new_page()
            log("info", "browser_launch", headless=self.config.headless)
            page.goto(FIGHTCENTER_URL, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
            summary: dict[str, Any] = {}
            self._dismiss_overlays(page, summary, max_rounds=2, context="inspect_filters")
            self._ensure_fightcenter_ready(page, "results", None, summary, FIGHTCENTER_URL)
            controls = self._collect_filter_controls(page)
            log("info", "filters_detected", controls=controls)
            return controls
        finally:
            if context:
                context.close()
            if browser:
                browser.close()
            if playwright:
                playwright.stop()

    def run(self, *, results: bool = True, upcoming: bool = True) -> dict[str, Any]:
        self._store.ensure_dirs()
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        started_at = utc_now()
        started_clock = time.time()
        run_dir = self._store.create_run_dir(run_id)
        self._active_run_dir = run_dir
        state_bundle = self._store.begin_attempt(run_id, started_at)
        self._state_bundle = state_bundle
        summary = self._empty_summary(run_id, started_at)
        self._active_summary = summary
        results_doc = self._empty_document("results", run_id, started_at)
        upcoming_doc = self._empty_document("upcoming", run_id, started_at)
        self._debug_doc = self._empty_debug_document(run_id, started_at) if self.config.debug else None
        self._partial_results_doc: dict[str, Any] | None = None
        self._partial_upcoming_doc: dict[str, Any] | None = None
        success = False

        log("info", "run_start", run_id=run_id, headless=self.config.headless)
        playwright = browser = context = None
        try:
            direct_event_items = self._resolve_direct_forced_event_items(state_bundle)
            playwright, browser, context = launch_browser(self.config)
            log(
                "info",
                "browser_launch",
                headless=self.config.headless,
                slow_mo=self.config.slow_mo,
                browser_engine=self.config.browser_engine,
            )
            if results:
                results_doc = self._scrape_kind(
                    context,
                    "results",
                    run_id,
                    started_at,
                    run_dir,
                    summary,
                    direct_event_items=direct_event_items,
                )
            if upcoming:
                upcoming_doc = self._scrape_kind(
                    context,
                    "upcoming",
                    run_id,
                    started_at,
                    run_dir,
                    summary,
                    direct_event_items=direct_event_items,
                )

            for document in [results_doc, upcoming_doc]:
                document["events"] = dedupe_events(document["events"])
                document["run"]["events_known"] = self._known_events_count(document["events"], state_bundle)
                self._refresh_run_counts(document)

            finished_at = utc_now()
            for document in [results_doc, upcoming_doc]:
                document["scraped_at"] = finished_at
                document["run"]["finished_at"] = finished_at

            summary.update(self._aggregate_counts(results_doc, upcoming_doc))
            summary["finished_at"] = finished_at
            summary["duration_seconds"] = round(time.time() - started_clock, 3)
            summary["warnings_count"] = len(summary.get("warnings", []))
            summary["errors_count"] = len(summary.get("errors", []))
            self._sync_run_warning_counts(results_doc, summary)
            if upcoming:
                self._sync_run_warning_counts(upcoming_doc, summary, update_summary=False)
            self._classify_incomplete_pagination(summary, results_doc)
            summary["successful"] = True
            summary["latest_updated"] = True
            summary["state_updated"] = True

            debug_doc = self._finish_debug_document(finished_at)
            coverage = coverage_document(results_doc['events'], self._coverage_entries.values(), run_id)
            write_json(run_dir / 'event_coverage.json', coverage)
            self._store.commit_success(
                run_dir=run_dir,
                bundle=state_bundle,
                run_id=run_id,
                started_at=started_at,
                finished_at=finished_at,
                results_doc=results_doc,
                summary=summary,
                options=self._manifest_options(),
                debug_doc=debug_doc,
                upcoming_doc=upcoming_doc if upcoming else None,
            )
            success = True
            log("info", "run_end", run_id=run_id, successful=True, run_dir=str(run_dir))
            return summary
        except FatalScrapeError as exc:
            partial_results_doc = self._partial_results_doc or results_doc
            summary["errors"].append(str(exc))
            summary["successful"] = False
            summary["latest_updated"] = False
            summary["state_updated"] = False
            summary["finished_at"] = utc_now()
            summary["duration_seconds"] = round(time.time() - started_clock, 3)
            summary["warnings_count"] = len(summary.get("warnings", []))
            summary["errors_count"] = len(summary.get("errors", []))
            self._store.record_failed_attempt(
                run_dir=run_dir,
                bundle=state_bundle,
                run_id=run_id,
                started_at=started_at,
                finished_at=summary["finished_at"],
                options=self._manifest_options(),
                counts=self._failed_attempt_counts(summary, partial_results_doc, upcoming_doc),
                warnings=summary.get("warnings", []),
                errors=summary.get("errors", []),
                partial_results=partial_results_doc,
                partial_upcoming=self._partial_upcoming_doc,
                debug_doc=self._finish_debug_document(summary["finished_at"]),
            )
            log("error", "run_failed", reason=str(exc), run_dir=str(run_dir))
            return summary
        except Exception as exc:
            partial_results_doc = self._partial_results_doc or results_doc
            summary["errors"].append(f"unexpected_error:{exc}")
            summary["successful"] = False
            summary["latest_updated"] = False
            summary["state_updated"] = False
            summary["finished_at"] = utc_now()
            summary["duration_seconds"] = round(time.time() - started_clock, 3)
            summary["warnings_count"] = len(summary.get("warnings", []))
            summary["errors_count"] = len(summary.get("errors", []))
            self._store.record_failed_attempt(
                run_dir=run_dir,
                bundle=state_bundle,
                run_id=run_id,
                started_at=started_at,
                finished_at=summary["finished_at"],
                options=self._manifest_options(),
                counts=self._failed_attempt_counts(summary, partial_results_doc, upcoming_doc),
                warnings=summary.get("warnings", []),
                errors=summary.get("errors", []),
                partial_results=partial_results_doc,
                partial_upcoming=self._partial_upcoming_doc,
                debug_doc=self._finish_debug_document(summary["finished_at"]),
            )
            log("error", "run_failed", reason=str(exc), run_dir=str(run_dir))
            return summary
        finally:
            if self._active_run_dir:
                write_json(run_dir / 'event_coverage.json', coverage_document(results_doc.get('events', []), self._coverage_entries.values(), run_id))
            self._active_summary = None
            if context:
                context.close()
            if browser:
                browser.close()
            if playwright:
                playwright.stop()
            if not success:
                log("info", "latest_state_not_updated", run_id=run_id)

    def _empty_summary(self, run_id: str, started_at: str) -> dict[str, Any]:
        return {
            "run_id": run_id,
            "started_at": started_at,
            "finished_at": None,
            "duration_seconds": None,
            "run_mode": self.config.run_mode,
            "filters_used": {},
            "selectors_used": [],
            "fallback_selectors_used": [],
            "events_seen": 0,
            "events_written": 0,
            "events_skipped_known_stable": 0,
            "events_skipped_known_cache": 0,
            "events_from_payload_cache": 0,
            "missing_cached_event_payloads": 0,
            "cumulative_event_scope": [],
            "cumulative_excluded_event_ids": [],
            "events_rechecked_lookback": 0,
            "events_rechecked_incomplete": 0,
            "early_stop_triggered": False,
            "early_stop_reason": None,
            "pages_scanned": 0,
            "first_listing_url": None,
            "last_listing_url": None,
            "listing_urls_visited": [],
            "pagination_next_found": False,
            "pagination_stop_reason": None,
            "date_window_stop_confirmed": False,
            "load_more_clicks": 0,
            "load_more_successes": 0,
            "load_more_no_new_events": 0,
            "load_more_timeouts": 0,
            "load_more_fallback_attempts": 0,
            "load_more_fallback_successes": 0,
            "load_more_fallback_failures": 0,
            "load_more_popup_pages_closed": 0,
            "load_more_overlay_dismissals": 0,
            "unique_listing_event_urls_seen": 0,
            "duplicate_listing_event_urls_seen": 0,
            "popup_dismissals_count": 0,
            "source_mma_bouts_total_mismatches": 0,
            "page_pauses_count": 0,
            "event_pauses_count": 0,
            "profile_pauses_count": 0,
            "profile_batch_pauses_count": 0,
            "total_pause_seconds": 0.0,
            "profile_fetches_before_cap": 0,
            "profile_enrichment_partial": False,
            "access_stop_reason": None,
            "consecutive_access_failures": 0,
            "event_fetch_failures": 0,
            "skipped_events_no_usable_bouts": 0,
            "skipped_events_non_mma_or_no_results": 0,
            "events_skipped_non_mma": 0,
            "events_skipped_no_results": 0,
            "events_skipped_all_amateur": 0,
            "events_skipped_no_usable_pro_mma_bouts": 0,
            "events_skipped_ufc": 0,
            "skipped_event_examples": [],
            "bouts_seen": 0,
            "bouts_source_rows_seen": 0,
            "bouts_written": 0,
            "bouts_skipped_amateur": 0,
            "bouts_skipped_non_mma": 0,
            "bouts_skipped_no_result": 0,
            "bouts_skipped_unknown_professional_status": 0,
            "failed_event_urls": [],
            "failed_event_examples": [],
            "failed_bout_urls": [],
            "failed_bout_examples": [],
            "failed_profile_examples": [],
            "unmatched_nationality_raw_values": [],
            "rejected_nationality_candidates": [],
            "rejected_nationality_candidate_counts": 0,
            "rejected_nationality_candidate_reasons": {},
            "nationality_extraction_source_counts": {},
            "unmatched_location_country_candidates": [],
            "unmatched_born_country_candidates": [],
            "unmatched_fighting_out_country_candidates": [],
            "warnings": [],
            "errors": [],
            "warnings_count": 0,
            "errors_count": 0,
            "latest_updated": False,
            "state_updated": False,
            "successful": False,
        }

    def _empty_document(self, kind: str, run_id: str, started_at: str) -> dict[str, Any]:
        is_results = kind == "results"
        return {
            "schema_version": RESULTS_SCHEMA_VERSION if is_results else UPCOMING_SCHEMA_VERSION,
            "source": "tapology",
            "source_url": FIGHTCENTER_URL,
            "scraped_at": started_at,
            "filters": {
                "promotion": self.config.promotion_scope,
                "schedule": kind,
                "sport": self.config.sport,
                "region": "all",
                "exclude_promotions": list(self.config.exclude_promotions),
            },
            "run": {
                "run_id": run_id,
                "started_at": started_at,
                "finished_at": None,
                "events_seen": 0,
                "events_written": 0,
                "events_skipped_known_stable": 0,
                "events_skipped_known_cache": 0,
                "events_from_payload_cache": 0,
                "missing_cached_event_payloads": 0,
                "events_rechecked_lookback": 0,
                "events_rechecked_incomplete": 0,
                "early_stop_triggered": False,
                "early_stop_reason": None,
                "pages_scanned": 0,
                "first_listing_url": None,
                "last_listing_url": None,
                "listing_urls_visited": [],
                "pagination_next_found": False,
                "pagination_stop_reason": None,
                "date_window_stop_confirmed": False,
                "load_more_clicks": 0,
                "load_more_successes": 0,
                "load_more_no_new_events": 0,
                "load_more_timeouts": 0,
                "load_more_fallback_attempts": 0,
                "load_more_fallback_successes": 0,
                "load_more_fallback_failures": 0,
                "load_more_popup_pages_closed": 0,
                "load_more_overlay_dismissals": 0,
                "unique_listing_event_urls_seen": 0,
                "duplicate_listing_event_urls_seen": 0,
                "popup_dismissals_count": 0,
                "source_mma_bouts_total_mismatches": 0,
                "page_pauses_count": 0,
                "event_pauses_count": 0,
                "profile_pauses_count": 0,
                "profile_batch_pauses_count": 0,
                "total_pause_seconds": 0.0,
                "profile_fetches_before_cap": 0,
                "profile_enrichment_partial": False,
                "access_stop_reason": None,
                "consecutive_access_failures": 0,
                "event_fetch_failures": 0,
                "skipped_events_no_usable_bouts": 0,
                "skipped_events_non_mma_or_no_results": 0,
                "events_skipped_non_mma": 0,
                "events_skipped_no_results": 0,
                "events_skipped_all_amateur": 0,
                "events_skipped_no_usable_pro_mma_bouts": 0,
                "events_skipped_ufc": 0,
                "skipped_event_examples": [],
                "events_known": 0,
                "bouts_seen": 0,
                "bouts_source_rows_seen": 0,
                "bouts_written": 0,
                "bouts_skipped_amateur": 0,
                "bouts_skipped_non_mma": 0,
                "bouts_skipped_no_result": 0,
                "bouts_skipped_unknown_professional_status": 0,
                "failed_event_examples": [],
                "failed_bout_examples": [],
                "failed_profile_examples": [],
                "unmatched_nationality_raw_values": [],
                "rejected_nationality_candidates": [],
                "rejected_nationality_candidate_counts": 0,
                "rejected_nationality_candidate_reasons": {},
                "nationality_extraction_source_counts": {},
                "unmatched_location_country_candidates": [],
                "unmatched_born_country_candidates": [],
                "unmatched_fighting_out_country_candidates": [],
                "warnings_count": 0,
                "retained_event_warnings_count": 0,
                "skipped_event_warnings_count": 0,
                "errors_count": 0,
            },
            "events": [],
        }

    def _empty_debug_document(self, run_id: str, started_at: str) -> dict[str, Any]:
        return {
            "run_id": run_id,
            "source": "tapology",
            "source_url": FIGHTCENTER_URL,
            "started_at": started_at,
            "finished_at": None,
            "events": [],
            "bouts": [],
            "diagnostics": [],
        }

    def _finish_debug_document(self, finished_at: str | None) -> dict[str, Any] | None:
        if not self._debug_doc:
            return None
        self._debug_doc["finished_at"] = finished_at
        return self._debug_doc

    def _manifest_options(self) -> dict[str, Any]:
        return {
            "run_mode": self.config.run_mode,
            "limit_events": self.config.max_events,
            "max_pages": self.config.max_pages,
            "from_date": self.config.from_date,
            "until_date": self.config.until_date,
            "recheck_days": self.config.recheck_days,
            "recheck_complete_days": self.config.recheck_complete_days,
            "recheck_known_limit": self.config.recheck_known_limit,
            "stop_after_known_complete": self.config.stop_after_known_complete,
            "force_recheck_event": sorted(self.config.force_recheck_events),
            "force_full_scan": self.config.force_full_scan,
            "skip_known_complete": self.config.skip_known_complete,
            "cumulative_results": self.config.cumulative_results,
            "fresh_results": self.config.fresh_results,
            "effective_force_full_scan": self._effective_force_full_scan(),
            "debug": self.config.debug,
            "headless": self.config.headless,
            "browser_engine": self.config.browser_engine,
            "wait_for_manual_challenge_seconds": self.config.wait_for_manual_challenge_seconds,
            "pause_on_manual_challenge": self.config.pause_on_manual_challenge,
            "browser_profile_dir": str(self.config.browser_profile_dir) if self.config.browser_profile_dir else None,
            "operator_navigates_to_fightcenter": self.config.operator_navigates_to_fightcenter,
            "use_current_page": self.config.use_current_page,
            "promotion_scope": self.config.promotion_scope,
            "sport": self.config.sport,
            "exclude_promotions": list(self.config.exclude_promotions),
            "enrich_fighter_profiles": self._effective_enrich_fighter_profiles(),
            "use_profile_cache": self.config.use_profile_cache,
            "profile_cache_ttl_days": self.config.profile_cache_ttl_days,
            "page_pause_seconds": self.config.page_pause_seconds,
            "profile_pause_seconds": self.config.profile_pause_seconds,
            "event_pause_seconds": self.config.event_pause_seconds,
            "jitter_seconds": self.config.jitter_seconds,
            "max_profile_fetches": self.config.max_profile_fetches,
            "profile_batch_size": self.config.profile_batch_size,
            "profile_batch_pause_seconds": self.config.profile_batch_pause_seconds,
            "max_consecutive_access_failures": self.config.max_consecutive_access_failures,
            "max_profile_failure_rate": self.config.max_profile_failure_rate,
            "max_event_fetch_failures": self.config.max_event_fetch_failures,
            "stop_on_access_degradation": self.config.stop_on_access_degradation,
            "load_more_post_click_wait_ms": self.config.load_more_post_click_wait_ms,
            "load_more_wait_timeout_ms": self.config.load_more_wait_timeout_ms,
            "load_more_popup_wait_ms": self.config.load_more_popup_wait_ms,
            "load_more_overlay_check_rounds": self.config.load_more_overlay_check_rounds,
            "load_more_overlay_check_interval_ms": self.config.load_more_overlay_check_interval_ms,
        }

    def _effective_force_full_scan(self) -> bool:
        return self.config.fresh_results or self.config.force_full_scan or (
            self.config.run_mode == "backfill" and not self.config.skip_known_complete
        )

    def _effective_enrich_fighter_profiles(self) -> bool:
        if self.config.enrich_fighter_profiles is not None:
            return bool(self.config.enrich_fighter_profiles)
        return False

    def _known_events_count(self, events: list[dict[str, Any]], bundle: dict[str, Any]) -> int:
        known = set(bundle.get("events_index", {}).keys())
        return sum(1 for event in events if event.get("source_event_id") in known)

    def _cached_event_payload(self, source_event_id: str | None) -> dict[str, Any] | None:
        if not source_event_id or self._state_bundle is None:
            return None
        payload = (self._state_bundle.get("event_payloads") or {}).get(source_event_id)
        if not isinstance(payload, dict) or not payload.get("bouts"):
            return None
        return payload

    def _known_event_is_cumulative_excluded(self, source_event_id: str | None) -> bool:
        if not source_event_id or self._state_bundle is None:
            return False
        record = (self._state_bundle.get("events_index") or {}).get(source_event_id) or {}
        return record.get("state") in {"ignored", "no_relevant_pro_mma_bouts"}

    def _record_cumulative_scope_item(self, summary: dict[str, Any], item: dict[str, Any]) -> None:
        source_event_id = item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
        if not source_event_id:
            return
        scope = summary.setdefault("cumulative_event_scope", [])
        if any(existing.get("source_event_id") == source_event_id for existing in scope if isinstance(existing, dict)):
            return
        scope.append(
            {
                "source_event_id": source_event_id,
                "event_url": item.get("event_url"),
                "event_date": item.get("event_date"),
                "list_page_number": item.get("list_page_number"),
                "list_position": item.get("list_position"),
            }
        )

    def _record_cumulative_excluded_item(self, summary: dict[str, Any], item: dict[str, Any]) -> None:
        source_event_id = item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
        if not source_event_id:
            return
        excluded = summary.setdefault("cumulative_excluded_event_ids", [])
        if source_event_id not in excluded:
            excluded.append(source_event_id)

    def _record_cumulative_excluded_event(self, summary: dict[str, Any], event: dict[str, Any]) -> None:
        source_event_id = event.get("source_event_id") or source_event_id_from_url(event.get("event_url"))
        if not source_event_id:
            return
        excluded = summary.setdefault("cumulative_excluded_event_ids", [])
        if source_event_id not in excluded:
            excluded.append(source_event_id)

    def _debug_append(self, section: str, payload: dict[str, Any]) -> None:
        if self._debug_doc is None:
            return
        self._debug_doc.setdefault(section, []).append(payload)

    def _sleep(self, seconds: float) -> None:
        time.sleep(seconds)

    def _jitter_seconds(self) -> float:
        jitter = float(self.config.jitter_seconds or 0)
        if jitter <= 0:
            return 0.0
        return random.uniform(0, jitter)

    def _pause(self, pause_kind: str, base_seconds: float | None, **details: Any) -> None:
        base = float(base_seconds or 0)
        if base <= 0:
            return
        duration = round(base + self._jitter_seconds(), 3)
        counter_key = {
            "page": "page_pauses_count",
            "event": "event_pauses_count",
            "profile": "profile_pauses_count",
            "profile_batch": "profile_batch_pauses_count",
        }.get(pause_kind)
        if counter_key:
            self._pause_counts[counter_key] += 1
        self._pause_counts["total_pause_seconds"] = round(
            float(self._pause_counts.get("total_pause_seconds") or 0) + duration,
            3,
        )
        log("info", "configured_pause", reason=pause_kind, duration_seconds=duration, **details)
        self._sleep(duration)

    def _runtime_diagnostic_counts(self) -> dict[str, Any]:
        return {
            **self._pause_counts,
            **self._pagination_counts,
            **self._cache_counts,
            "access_stop_reason": self._access_stop_reason,
            "consecutive_access_failures": self._consecutive_access_failures,
            "event_fetch_failures": self._event_fetch_failures_seen,
            "profile_enrichment_partial": self._profile_cap_reached(),
        }

    def _sync_runtime_diagnostics(self, target: dict[str, Any]) -> None:
        target.update(self._runtime_diagnostic_counts())

    def _dismiss_overlays(
        self,
        page: Any,
        summary: dict[str, Any],
        *,
        max_rounds: int = 1,
        settle_ms: int = 300,
        context: str | None = None,
    ) -> int:
        try:
            dismissed = dismiss_blocking_ui(page, log, summary, max_rounds=max_rounds, settle_ms=settle_ms)
        except TypeError as exc:
            if "unexpected keyword" not in str(exc):
                raise
            dismissed = dismiss_blocking_ui(page, log, summary) or 0
        if dismissed and context:
            summary.setdefault("selectors_used", []).append(f"overlay_check:{context}")
            log("info", "page_overlay_dismissed", context=context, count=dismissed)
        return dismissed

    def _failed_attempt_counts(self, summary: dict[str, Any], *documents: dict[str, Any]) -> dict[str, Any]:
        counts = {**self._aggregate_counts(*documents), **self._runtime_diagnostic_counts()}
        for key in (
            "early_stop_triggered",
            "early_stop_reason",
            "pagination_stop_reason",
            "date_window_stop_confirmed",
            "pages_scanned",
            "first_listing_url",
            "last_listing_url",
            "listing_urls_visited",
            "pagination_next_found",
            "profile_enrichment_partial",
            "access_stop_reason",
            "pagination_incomplete",
            "failed_event_examples",
            "failed_bout_examples",
            "failed_profile_examples",
            "popup_dismissals_count",
        ):
            if key in summary and summary.get(key) is not None:
                counts[key] = summary.get(key)
        return counts

    def _classify_incomplete_pagination(self, summary: dict[str, Any], results_doc: dict[str, Any]) -> None:
        max_pages = int(self.config.max_pages or 0)
        if max_pages <= 1:
            return
        stop_reason = summary.get("pagination_stop_reason")
        if stop_reason not in {"load_more_timeout", "load_more_no_new_events", "access_degraded"}:
            return
        pages_scanned = int(summary.get("pages_scanned") or 0)
        if pages_scanned >= max_pages:
            return
        summary["early_stop_triggered"] = True
        summary["early_stop_reason"] = stop_reason
        summary["pagination_incomplete"] = True
        if stop_reason == "load_more_timeout":
            self._access_stop_reason = self._access_stop_reason or "load_more_timeout"
            summary["access_stop_reason"] = summary.get("access_stop_reason") or "load_more_timeout"
        run = results_doc.setdefault("run", {})
        run["early_stop_triggered"] = True
        run["early_stop_reason"] = stop_reason
        run["pagination_stop_reason"] = stop_reason
        run["pagination_incomplete"] = True
        run["access_stop_reason"] = summary.get("access_stop_reason")
        self._sync_runtime_diagnostics(run)
        raise FatalScrapeError(f"pagination_incomplete:{stop_reason}:{pages_scanned}/{max_pages}")

    def _profile_cache_key(self, profile_url: str | None) -> str | None:
        return source_fighter_id_from_url(profile_url) or canonical_url(profile_url)

    def _persistent_profile_cache_entry(self, profile_url: str | None) -> dict[str, Any] | None:
        if not profile_url:
            return None
        if not self.config.use_profile_cache:
            self._cache_counts["profile_cache_disabled"] += 1
            return None
        cache = self._load_persistent_profile_cache()
        index = cache.get("index", {})
        key = self._profile_cache_key(profile_url)
        entry = index.get(key or "") or index.get(canonical_url(profile_url) or "")
        if not entry:
            self._cache_counts["profile_cache_misses"] += 1
            return None
        if not self._persistent_profile_cache_entry_fresh(entry):
            self._cache_counts["profile_cache_expired"] += 1
            return None
        self._cache_counts["profile_cache_hits"] += 1
        return entry

    def _cached_event_profile(
        self,
        profile_url: str | None,
        *,
        fallback_name: str | None,
        event_date: str | None,
        fetched_at: str,
        known_results: list[dict] | None = None,
    ) -> dict[str, Any] | None:
        entry = self._persistent_profile_cache_entry(profile_url)
        if not entry:
            return None
        from .scoring_evidence import needs_refresh
        source_profile = deepcopy(entry.get("profile") or {})
        source_profile["scraped_at"] = entry.get("cache_scraped_at")
        if needs_refresh(source_profile, known_results=known_results):
            self._cache_counts["profile_cache_hits"] -= 1
            self._cache_counts["profile_cache_expired"] += 1
            return None
        cache_scraped_at = entry.get("cache_scraped_at") or fetched_at
        # A newly completed event must not reuse a profile from before that event,
        # even if the seven-day TTL has not expired.
        if event_date:
            try:
                fight_day = date.fromisoformat(str(event_date)[:10])
                cached_day = date.fromisoformat(str(cache_scraped_at)[:10])
                if cached_day < fight_day <= datetime.now(timezone.utc).date():
                    self._cache_counts["profile_cache_hits"] -= 1
                    self._cache_counts["profile_cache_expired"] += 1
                    return None
            except (ValueError, TypeError):
                return None
        normalized = normalize_profile_for_event_output(
            deepcopy(entry.get("profile") or {}),
            profile_url=profile_url,
            fallback_name=fallback_name,
            event_date=event_date,
            fetched_at=cache_scraped_at,
        )
        normalized["profile_cache_hit"] = True
        normalized["profile_cache_source"] = "latest_fighter_profiles"
        normalized["profile_cache_scraped_at"] = cache_scraped_at
        return normalized

    def _load_persistent_profile_cache(self) -> dict[str, Any]:
        if self._persistent_profile_cache is not None:
            return self._persistent_profile_cache
        path = self._store.latest_dir / "fighter_profiles.json"
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            self._persistent_profile_cache = {"index": {}, "path": str(path)}
            return self._persistent_profile_cache
        scraped_at = document.get("scraped_at")
        index: dict[str, dict[str, Any]] = {}
        for profile in document.get("profiles", []) if isinstance(document, dict) else []:
            if not isinstance(profile, dict) or profile.get("status") != "success":
                continue
            profile_url = canonical_url(profile.get("source_url"))
            source_fighter_id = profile.get("source_fighter_id") or source_fighter_id_from_url(profile_url)
            entry = {
                "profile": profile,
                "cache_scraped_at": profile.get("scraped_at") or profile.get("profile_scraped_at") or scraped_at,
            }
            if source_fighter_id:
                index[source_fighter_id] = entry
            if profile_url:
                index[profile_url] = entry
        self._persistent_profile_cache = {
            "index": index,
            "path": str(path),
            "scraped_at": scraped_at,
            "profiles_indexed": len({id(entry["profile"]) for entry in index.values()}),
        }
        return self._persistent_profile_cache

    def _persistent_profile_cache_entry_fresh(self, entry: dict[str, Any]) -> bool:
        ttl_days = min(7, self.config.profile_cache_ttl_days or 7)
        cache_scraped_at = entry.get("cache_scraped_at")
        if not cache_scraped_at:
            return False
        try:
            cached_at = datetime.fromisoformat(str(cache_scraped_at).replace("Z", "+00:00"))
        except ValueError:
            return False
        if cached_at.tzinfo is None:
            cached_at = cached_at.replace(tzinfo=timezone.utc)
        return cached_at.astimezone(timezone.utc) >= datetime.now(timezone.utc) - timedelta(days=int(ttl_days))

    def _profile_cap_reached(self) -> bool:
        cap = self.config.max_profile_fetches
        return cap is not None and self._profile_fetches_started >= cap

    def _configured_profile_pause_seconds(self) -> float:
        if self.config.profile_pause_seconds is not None:
            return float(self.config.profile_pause_seconds)
        return max(self.config.conservative_delay_ms, self.config.event_delay_ms) / 1000

    def _safe_mode_enabled(self) -> bool:
        return bool(
            self.config.stop_on_access_degradation
            or self.config.max_consecutive_access_failures is not None
            or self.config.max_profile_failure_rate is not None
            or self.config.max_event_fetch_failures is not None
        )

    def _effective_max_consecutive_access_failures(self) -> int:
        return int(self.config.max_consecutive_access_failures or 3)

    def _effective_max_profile_failure_rate(self) -> float | None:
        if self.config.max_profile_failure_rate is not None:
            return float(self.config.max_profile_failure_rate)
        return 0.15 if self.config.stop_on_access_degradation else None

    def _effective_max_event_fetch_failures(self) -> int:
        return int(self.config.max_event_fetch_failures or 2)

    def _record_access_failure(self, reason: str, summary: dict[str, Any] | None = None) -> None:
        self._consecutive_access_failures += 1
        if summary is not None:
            summary["consecutive_access_failures"] = self._consecutive_access_failures
        if self._safe_mode_enabled() and self._consecutive_access_failures >= self._effective_max_consecutive_access_failures():
            self._stop_for_access_degradation(f"max_consecutive_access_failures:{reason}")

    def _record_access_success(self, summary: dict[str, Any] | None = None) -> None:
        self._consecutive_access_failures = 0
        if summary is not None:
            summary["consecutive_access_failures"] = 0

    def _stop_for_access_degradation(self, reason: str) -> None:
        self._access_stop_reason = reason
        raise FatalScrapeError(f"access_degraded:{reason}")

    def _check_profile_failure_threshold(self) -> None:
        threshold = self._effective_max_profile_failure_rate()
        if threshold is None or self._profile_fetches_started <= 0:
            return
        failure_rate = self._profile_fetch_failures_seen / self._profile_fetches_started
        if failure_rate > threshold:
            self._stop_for_access_degradation(
                f"profile_failure_rate_exceeded:{failure_rate:.3f}>{threshold:.3f}"
            )

    def _check_event_failure_threshold(self) -> None:
        if not self._safe_mode_enabled():
            return
        if self._event_fetch_failures_seen >= self._effective_max_event_fetch_failures():
            self._stop_for_access_degradation(
                f"event_fetch_failures_exceeded:{self._event_fetch_failures_seen}"
            )

    def _is_access_failure_message(self, message: str | None) -> bool:
        lowered = normalize_space(message).lower()
        return any(
            token in lowered
            for token in (
                "cloudflare",
                "challenge",
                "blocked",
                "access denied",
                "timeout",
                "http_error",
                "429",
                "503",
                "navigation_failed",
                "net::",
            )
        )

    def _is_browser_context_closed_message(self, message: str | None) -> bool:
        lowered = normalize_space(message).lower()
        return any(
            token in lowered
            for token in (
                "target page, context or browser has been closed",
                "browsercontext.new_page",
                "browser has been closed",
                "context has been closed",
                "target closed",
            )
        )

    def _scrape_kind(
        self,
        context: Any,
        kind: str,
        run_id: str,
        started_at: str,
        run_dir: Path,
        summary: dict[str, Any],
        direct_event_items: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        document = self._empty_document(kind, run_id, started_at)
        if direct_event_items is not None:
            document["source_url"] = direct_event_items[0].get("event_url") if len(direct_event_items) == 1 else FIGHTCENTER_URL
            document["events"] = self._scrape_direct_event_items(context, direct_event_items, kind, run_dir, summary)
            self._refresh_run_counts(document)
            self._copy_summary_skip_counts(document, summary, kind)
            self._write_results_checkpoint(kind, run_id, started_at, run_dir, summary, document["events"])
            return document
        page = self._new_fightcenter_page(context)
        close_page_on_exit = not (self.config.operator_navigates_to_fightcenter and self.config.use_current_page)
        try:
            if self.config.operator_navigates_to_fightcenter:
                log("info", "fightcenter_operator_navigation_wait", kind=kind)
                page = self._wait_for_operator_current_fightcenter_page(page, kind, run_dir, summary, context=context)
            else:
                log("info", "fightcenter_open", kind=kind, url=FIGHTCENTER_URL)
                page.goto(FIGHTCENTER_URL, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
                self._dismiss_overlays(page, summary, max_rounds=2, context=f"fightcenter_open:{kind}")
                self._ensure_fightcenter_ready(page, kind, run_dir, summary, FIGHTCENTER_URL)
                if self.config.use_current_page:
                    self._print_and_validate_prescrape_confirmation(page, kind, run_dir, summary)

            if self.config.use_current_page:
                filters_used = self._current_filter_values(page, kind)
                summary.setdefault("selectors_used", []).append("current_fightcenter_page")
                log("info", "fightcenter_use_current_page", kind=kind, url=getattr(page, "url", None))
            else:
                filters_used = self._apply_filters(page, kind, summary, run_dir=run_dir)
            summary.setdefault("filters_used", {})[kind] = filters_used
            events: list[dict[str, Any]] = []
            try:
                events = self._traverse_event_list(page, kind, context, run_dir, summary)
            except FatalScrapeError as exc:
                events = list(getattr(exc, "partial_events", events) or [])
                document["events"] = events
                self._refresh_run_counts(document)
                self._copy_summary_skip_counts(document, summary, kind)
                document["run"]["partial_failure_reason"] = str(exc)
                self._write_results_checkpoint(
                    kind,
                    run_id,
                    started_at,
                    run_dir,
                    summary,
                    events,
                    failure_reason=str(exc),
                )
                if kind == "results":
                    self._partial_results_doc = document
                elif kind == "upcoming":
                    self._partial_upcoming_doc = document
                raise
            document["events"] = events
            self._refresh_run_counts(document)
            self._copy_summary_skip_counts(document, summary, kind)
            self._write_results_checkpoint(kind, run_id, started_at, run_dir, summary, events)
            return document
        finally:
            if close_page_on_exit:
                page.close()

    def _write_results_checkpoint(
        self,
        kind: str,
        run_id: str,
        started_at: str,
        run_dir: Path,
        summary: dict[str, Any],
        events: list[dict[str, Any]],
        *,
        failure_reason: str | None = None,
    ) -> None:
        if kind not in {"results", "upcoming"}:
            return
        checkpoint_at = utc_now()
        checkpoint = self._empty_document(
            kind,
            run_id or str(summary.get("run_id") or ""),
            started_at or str(summary.get("started_at") or checkpoint_at),
        )
        checkpoint["events"] = list(events)
        checkpoint["scraped_at"] = checkpoint_at
        checkpoint["run"]["finished_at"] = None
        checkpoint["run"]["checkpoint_at"] = checkpoint_at
        checkpoint["run"]["checkpoint_event_count"] = len(events)
        checkpoint["run"]["checkpoint_partial"] = True
        if failure_reason:
            checkpoint["run"]["partial_failure_reason"] = failure_reason
        self._refresh_run_counts(checkpoint)
        self._copy_summary_skip_counts(checkpoint, summary, kind)
        if failure_reason:
            checkpoint["run"]["partial_failure_reason"] = failure_reason
        if kind == "results":
            self._partial_results_doc = checkpoint
        else:
            self._partial_upcoming_doc = checkpoint
        write_json(run_dir / f"checkpoint_{kind}.json", checkpoint)

    def _resume_upcoming_events(self, summary: dict[str, Any]) -> list[dict[str, Any]]:
        checkpoint_path = self.config.resume_upcoming_checkpoint
        if checkpoint_path is None:
            return []
        path = Path(checkpoint_path).expanduser().resolve()
        try:
            checkpoint = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise FatalScrapeError(f"Unable to read upcoming resume checkpoint {path}: {exc}") from exc
        if checkpoint.get("schema_version") != UPCOMING_SCHEMA_VERSION or not isinstance(checkpoint.get("events"), list):
            raise FatalScrapeError(f"Invalid upcoming resume checkpoint: {path}")
        events = dedupe_events(checkpoint["events"])
        summary["upcoming_resume_checkpoint"] = str(path)
        summary["upcoming_events_resumed"] = len(events)
        log("info", "upcoming_resume_loaded", checkpoint=str(path), events=len(events))
        return events

    def _new_fightcenter_page(self, context: Any) -> Any:
        if self.config.operator_navigates_to_fightcenter and self.config.use_current_page:
            pages = list(getattr(context, "pages", []) or [])
            if pages:
                return pages[0]
        return context.new_page()

    def _resolve_direct_forced_event_items(self, state_bundle: dict[str, Any]) -> list[dict[str, Any]] | None:
        if not self.config.force_recheck_events:
            return None
        items: list[dict[str, Any]] = []
        seen: set[str] = set()
        for index, value in enumerate(self.config.force_recheck_events, start=1):
            item = self._resolve_direct_forced_event_item(value, state_bundle, index)
            key = item.get("source_event_id") or item.get("event_url")
            if key and key in seen:
                continue
            if key:
                seen.add(key)
            items.append(item)
        if not items:
            raise FatalScrapeError("forced_event_no_targets")
        log(
            "info",
            "forced_event_direct_targets_resolved",
            targets=[item.get("source_event_id") or item.get("event_url") for item in items],
        )
        return items

    def _resolve_direct_forced_event_item(
        self,
        value: str,
        state_bundle: dict[str, Any],
        list_position: int,
    ) -> dict[str, Any]:
        normalized_value = normalize_space(value)
        source_event_id = source_event_id_from_url(normalized_value)
        if source_event_id:
            event_url = canonical_url(normalized_value)
            indexed = self._known_event_payload(source_event_id, state_bundle) or {}
            return self._direct_event_item_from_payload(
                {
                    **indexed,
                    "source_event_id": source_event_id,
                    "event_url": event_url,
                },
                list_position,
            )

        if not re.fullmatch(r"tapology_event_[A-Za-z0-9_]+", normalized_value):
            raise FatalScrapeError(f"forced_event_target_not_event_url_or_source_id:{value}")

        indexed = self._known_event_payload(normalized_value, state_bundle)
        if not indexed or not indexed.get("event_url"):
            raise FatalScrapeError(f"source ID cannot be resolved to URL: {value}")
        return self._direct_event_item_from_payload(indexed, list_position)

    def _known_event_payload(self, source_event_id: str, state_bundle: dict[str, Any]) -> dict[str, Any] | None:
        indexed = (state_bundle.get("events_index") or {}).get(source_event_id)
        if isinstance(indexed, dict) and indexed.get("event_url"):
            return indexed
        latest_event = self._latest_event_payload(source_event_id)
        if latest_event:
            return latest_event
        return None

    def _latest_event_payload(self, source_event_id: str) -> dict[str, Any] | None:
        latest_path = self._store.latest_dir / "results.json"
        try:
            import json

            latest = json.loads(latest_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None
        for event in latest.get("events", []) if isinstance(latest, dict) else []:
            if isinstance(event, dict) and event.get("source_event_id") == source_event_id and event.get("event_url"):
                return event
        return None

    def _direct_event_item_from_payload(self, payload: dict[str, Any], list_position: int) -> dict[str, Any]:
        event_url = canonical_url(payload.get("event_url"))
        if not event_url:
            raise FatalScrapeError(f"source ID cannot be resolved to URL: {payload.get('source_event_id')}")
        return {
            "event_name": payload.get("event_name"),
            "event_url": event_url,
            "source_event_id": payload.get("source_event_id") or source_event_id_from_url(event_url),
            "promotion_name": payload.get("promotion_name"),
            "event_date": payload.get("event_date"),
            "location": payload.get("location"),
            "region": payload.get("region"),
            "visible_main_bout": payload.get("visible_main_bout"),
            "list_page_number": None,
            "list_position": list_position,
            "recheck_reason": "forced_event",
        }

    def _scrape_direct_event_items(
        self,
        context: Any,
        direct_event_items: list[dict[str, Any]],
        kind: str,
        run_dir: Path,
        summary: dict[str, Any],
    ) -> list[dict[str, Any]]:
        events: list[dict[str, Any]] = []
        failures: list[str] = []
        nonfatal_notes: list[str] = []
        for item in direct_event_items:
            if self.config.max_events and len(events) >= self.config.max_events:
                break
            try:
                if self._list_item_matches_excluded_ufc(item):
                    event = self._skipped_ufc_event_from_item(item)
                    self._record_skipped_ufc_event(summary, event)
                    nonfatal_notes.append(f"forced_event_skipped_ufc:{item.get('event_url')}")
                    continue
                event = self._scrape_event_page(context, item, kind, run_dir, summary)
                if self._event_is_skipped_ufc(event):
                    self._record_skipped_ufc_event(summary, event)
                    nonfatal_notes.append(f"forced_event_skipped_ufc:{item.get('event_url')}")
                    continue
                if not event.get('bouts') and (event.get('event_coverage') or {}).get('status')!='excluded' and any(str(w).startswith('fight_card_missing') for w in event.get('warnings',[])):
                    raise FatalScrapeError('forced_event_no_usable_bouts:'+str(item.get('event_url')))
                if kind == "results" and should_skip_results_event_no_usable_bouts(event):
                    self._record_skipped_results_event(summary, event)
                    nonfatal_notes.append(f"forced_event_no_usable_bouts:{item.get('event_url')}")
                    continue
                events.append(event)
                self._write_results_checkpoint(kind, "", "", run_dir, summary, events)
                log("info", "event_parsed", kind=kind, event_url=item.get("event_url"), bouts=len(event.get("bouts", [])))
            except FatalScrapeError as exc:
                sid=item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
                if sid not in self._coverage_entries:self._record_failed_event(summary,item,exc)
                raise
            except Exception as exc:
                self._record_failed_event(summary, item, exc)
                failures.append(f"forced_event_failed:{item.get('event_url')}:{exc}")
                self._event_fetch_failures_seen += 1
                summary["event_fetch_failures"] = self._event_fetch_failures_seen
                if self._is_browser_context_closed_message(str(exc)):
                    self._stop_for_access_degradation("browser_context_closed")
                if self._is_access_failure_message(str(exc)):
                    self._record_access_failure(str(exc), summary)
                log("error", "event_failed", event_url=item.get("event_url"), error=str(exc))
                self._check_event_failure_threshold()
        summary["warnings"].extend(nonfatal_notes)
        if failures or not events:
            summary["warnings"].extend(failures)
            raise FatalScrapeError("; ".join(failures or nonfatal_notes or ["forced_event_no_events_scraped"]))
        return events

    def _collect_filter_controls(self, page: Any) -> list[dict[str, Any]]:
        return page.evaluate(
            """
            () => {
              const form = document.getElementById('fightCenterRefine') || document;
              const labelFor = (el) => {
                if (el.id) {
                  const label = document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
                  if (label) return label.innerText.trim();
                }
                const wrapped = el.closest('label');
                if (wrapped) return wrapped.innerText.trim();
                return '';
              };
              const cssSelector = (el) => {
                if (el.id) return `#${CSS.escape(el.id)}`;
                if (el.name) return `${el.tagName.toLowerCase()}[name="${CSS.escape(el.name)}"]`;
                return el.tagName.toLowerCase();
              };
              return Array.from(form.querySelectorAll('select,input[type=radio],input[type=checkbox]')).map((el) => {
                const form = el.closest('form');
                const block = el.closest('li,fieldset,div,p') || el.parentElement;
                return {
                  tag: el.tagName.toLowerCase(),
                  type: el.getAttribute('type') || '',
                  selector: cssSelector(el),
                  id: el.id || '',
                  name: el.getAttribute('name') || '',
                  value: el.value || '',
                  label: labelFor(el),
                  aria_label: el.getAttribute('aria-label') || '',
                  placeholder: el.getAttribute('placeholder') || '',
                  nearby_text: (block?.innerText || '').trim().slice(0, 300),
                  form_id: form?.id || '',
                  form_name: form?.getAttribute('name') || '',
                  options: el.tagName.toLowerCase() === 'select'
                    ? Array.from(el.options).map((option) => ({
                        value: option.value,
                        text: option.text.trim(),
                        selected: option.selected
                      }))
                    : []
                };
              });
            }
            """
        )

    def _wait_for_filter_controls(self, page: Any) -> None:
        try:
            page.wait_for_function(
                "() => document.querySelector('#fightCenterRefine select, #fightCenterRefine input[type=radio], #fightCenterRefine input[type=checkbox]')",
                timeout=8_000,
            )
        except Exception:
            pass

    def _fightcenter_readiness_snapshot(
        self,
        page: Any,
        kind: str,
        controls: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        page_url = str(getattr(page, "url", "") or "")
        try:
            title = page.title()
        except Exception:
            title = ""
        try:
            body_excerpt = normalize_space(page.locator("body").inner_text(timeout=1000))[:1000]
        except Exception:
            body_excerpt = ""
        try:
            dom_markers = page.evaluate(
                """
                ({ rootSelector }) => {
                  const bodyText = document.body?.innerText || '';
                  const form = document.querySelector('#fightCenterRefine');
                  const controls = form
                    ? Array.from(form.querySelectorAll('select,input[type=radio],input[type=checkbox]'))
                    : [];
                  const headings = Array.from(document.querySelectorAll('h1,h2,h3,legend'))
                    .map((el) => (el.innerText || '').trim().toLowerCase());
                  const eventLinks = Array.from(document.querySelectorAll('a[href*="/fightcenter/events/"]'));
                  const root = document.querySelector(rootSelector);
                  return {
                    location_href: window.location.href,
                    has_fightcenter_form: Boolean(form),
                    filter_controls_count: controls.length,
                    has_fightcenter_heading: headings.some((text) => text.includes('fightcenter') || text.includes('fight center')),
                    has_event_listing_rows: Boolean(root && eventLinks.length > 0),
                    event_listing_links_count: eventLinks.length,
                    body_text_excerpt: bodyText.replace(/\\s+/g, ' ').trim().slice(0, 1000),
                  };
                }
                """,
                {"rootSelector": FIGHTCENTER_EVENTS_SELECTOR},
            )
        except Exception:
            dom_markers = {}
        if not isinstance(dom_markers, dict):
            dom_markers = {}
        page_url = str(dom_markers.get("location_href") or page_url)
        if not body_excerpt:
            body_excerpt = str(dom_markers.get("body_text_excerpt") or "")
        if controls is None:
            try:
                controls = self._collect_filter_controls(page)
            except Exception:
                controls = []

        promotion = self._find_fightcenter_select(controls, "promotion", self._promotion_aliases())
        schedule = self._find_fightcenter_select(
            controls,
            "schedule",
            FILTER_ALIASES["schedule_results"] if kind == "results" else FILTER_ALIASES["schedule_upcoming"],
        )
        sport = self._find_fightcenter_select(controls, "sport", self._sport_aliases())
        required_controls_present = {
            "promotion": bool(promotion),
            "schedule": bool(schedule),
            "sport": bool(sport),
        }
        url_lower = page_url.lower()
        url_is_fightcenter = "/fightcenter" in url_lower and "/fightcenter/events/" not in url_lower
        classified_challenge_indicators = classify_challenge_indicators(page)
        strong_challenge_indicators = classified_challenge_indicators.get("strong", [])
        weak_challenge_indicators = classified_challenge_indicators.get("weak", [])
        challenge_indicators = strong_challenge_indicators + weak_challenge_indicators
        has_form = bool(dom_markers.get("has_fightcenter_form")) or any(
            control.get("form_id") == FIGHTCENTER_FORM_ID for control in controls
        )
        has_event_rows = bool(dom_markers.get("has_event_listing_rows"))
        has_heading_and_form = bool(dom_markers.get("has_fightcenter_heading")) and has_form
        has_required_controls = all(required_controls_present.values())
        strong_markers = {
            "fightcenter_url": url_is_fightcenter,
            "fightcenter_form": has_form,
            "required_filter_controls": has_required_controls,
            "event_listing_rows": has_event_rows,
            "fightcenter_heading_with_form": has_heading_and_form,
        }
        missing_markers = [name for name, present in strong_markers.items() if not present]
        fightcenter_title = "fightcenter" in title.lower() or "fight center" in title.lower()
        ready = (
            not strong_challenge_indicators
            and url_is_fightcenter
            and (has_form or has_required_controls or has_event_rows or has_heading_and_form)
        )
        operator_current_page_ready = (
            not strong_challenge_indicators
            and url_is_fightcenter
            and has_required_controls
            and has_event_rows
            and (fightcenter_title or has_heading_and_form or has_form)
        )
        return {
            "ready": ready,
            "operator_current_page_ready": operator_current_page_ready,
            "kind": kind,
            "url": page_url,
            "title": title,
            "body_excerpt": body_excerpt,
            "challenge_indicators": challenge_indicators,
            "strong_challenge_indicators": strong_challenge_indicators,
            "weak_challenge_indicators": weak_challenge_indicators,
            "markers_present": [name for name, present in strong_markers.items() if present],
            "markers_missing": missing_markers,
            "fightcenter_markers_present": [name for name, present in strong_markers.items() if present],
            "fightcenter_markers_missing": missing_markers,
            "required_controls_present": required_controls_present,
            "controls_count": len(controls),
            "event_listing_links_count": int(dom_markers.get("event_listing_links_count") or 0),
        }

    def _classify_fightcenter_not_ready(self, snapshot: dict[str, Any]) -> str:
        if snapshot.get("strong_challenge_indicators") or snapshot.get("weak_challenge_indicators"):
            return "cloudflare_challenge_not_cleared"
        url = str(snapshot.get("url") or "").lower()
        if url and "/fightcenter" not in url:
            return "unexpected_page_after_challenge"
        return "fightcenter_ready_timeout"

    def _record_fightcenter_readiness_diagnostic(
        self,
        page: Any,
        kind: str,
        classification: str,
        snapshot: dict[str, Any],
        run_dir: Path | None,
    ) -> dict[str, Any]:
        suffixes = {
            "cloudflare_challenge_not_cleared": "challenge_not_cleared",
            "blocked_or_challenge_page": "blocked_or_challenge_page",
            "unexpected_page_after_challenge": "unexpected_page_after_challenge",
            "fightcenter_ready_timeout": "ready_timeout",
            "missing_fightcenter_filter_controls": "missing_filter_controls",
            "operator_confirmed_but_challenge_still_present": "operator_confirmed_but_challenge_still_present",
            "operator_confirmed_but_not_ready": "operator_confirmed_but_not_ready",
            "operator_confirmed_unexpected_page": "operator_confirmed_unexpected_page",
        }
        artifact_paths: dict[str, str] = {}
        if run_dir:
            artifact_paths = save_debug_artifacts(
                page,
                run_dir,
                f"fightcenter_{kind}_{suffixes.get(classification, classification)}",
            )
        diagnostic = {
            "type": classification,
            "kind": kind,
            "url": snapshot.get("url"),
            "title": snapshot.get("title"),
            "body_excerpt": snapshot.get("body_excerpt"),
            "challenge_indicators": snapshot.get("challenge_indicators", []),
            "strong_challenge_indicators": snapshot.get("strong_challenge_indicators", []),
            "weak_challenge_indicators": snapshot.get("weak_challenge_indicators", []),
            "markers_present": snapshot.get("markers_present", []),
            "markers_missing": snapshot.get("markers_missing", []),
            "fightcenter_markers_present": snapshot.get("fightcenter_markers_present", snapshot.get("markers_present", [])),
            "fightcenter_markers_missing": snapshot.get("fightcenter_markers_missing", snapshot.get("markers_missing", [])),
            "required_controls_present": snapshot.get("required_controls_present", {}),
            "controls_count": snapshot.get("controls_count"),
            **artifact_paths,
        }
        self._debug_append("diagnostics", diagnostic)
        log("error", classification, **diagnostic)
        return diagnostic

    def _classify_operator_confirmed_fightcenter_not_ready(self, snapshot: dict[str, Any]) -> str:
        if snapshot.get("strong_challenge_indicators") or snapshot.get("weak_challenge_indicators"):
            return "operator_confirmed_but_challenge_still_present"
        url = str(snapshot.get("url") or "").lower()
        if url and "/fightcenter" not in url:
            return "operator_confirmed_unexpected_page"
        return "operator_confirmed_but_not_ready"

    def _hard_pause_for_fightcenter_challenge(
        self,
        page: Any,
        kind: str,
        run_dir: Path | None,
        initial_indicators: list[str],
    ) -> None:
        reason = initial_indicators[0] if initial_indicators else "challenge"
        print(
            "\n[manual-challenge] Cloudflare/Turnstile security verification detected "
            f"at fightcenter_{kind} ({reason}).\n"
            "[manual-challenge] The scraper is now fully paused. It will not reload, navigate, "
            "poll selectors, or inspect FightCenter readiness while waiting.\n"
            "[manual-challenge] Complete Cloudflare manually in the browser. You may refresh, type/open "
            "https://www.tapology.com/fightcenter, or adjust the page yourself.\n"
            "[manual-challenge] Press ENTER only after the real Tapology FightCenter page with filters/events is visible.",
            flush=True,
        )
        log(
            "warn",
            "manual_challenge_hard_pause_start",
            kind=kind,
            reason=reason,
            challenge_indicators=initial_indicators,
        )
        while True:
            try:
                response = input(
                    "[manual-challenge] Press ENTER only after the real Tapology FightCenter page "
                    "with filters/events is visible. Type abort to stop: "
                )
            except EOFError as exc:
                raise FatalScrapeError(
                    "manual_challenge_input_unavailable: terminal input closed before operator confirmation"
                ) from exc
            if response.strip().lower() in {"abort", "q", "quit", "exit"}:
                raise FatalScrapeError("manual_challenge_aborted: operator aborted hard-pause verification")

            snapshot = self._fightcenter_readiness_snapshot(page, kind)
            if snapshot["ready"]:
                print("[manual-challenge] FightCenter readiness confirmed. Resuming scrape.", flush=True)
                log("info", "manual_challenge_hard_pause_cleared", kind=kind, markers=snapshot["markers_present"])
                return

            classification = self._classify_operator_confirmed_fightcenter_not_ready(snapshot)
            self._record_fightcenter_readiness_diagnostic(page, kind, classification, snapshot, run_dir)
            if classification == "operator_confirmed_but_challenge_still_present":
                print(
                    "[manual-challenge] Challenge markers are still present after operator confirmation. "
                    "Stay in the browser and press ENTER again only after the real FightCenter page is visible.",
                    flush=True,
                )
                continue
            if classification == "operator_confirmed_unexpected_page":
                print(
                    "[manual-challenge] The visible page is not the expected Tapology FightCenter page "
                    f"(url={snapshot.get('url')}, title={snapshot.get('title')}). "
                    "Correct it manually, then press ENTER again, or type abort.",
                    flush=True,
                )
                continue
            print(
                "[manual-challenge] FightCenter is visible but required readiness markers are missing "
                f"(missing={snapshot.get('markers_missing')}). Correct the page manually, then press ENTER again, "
                "or type abort.",
                flush=True,
            )

    def _wait_for_operator_current_fightcenter_page(
        self,
        page: Any,
        kind: str,
        run_dir: Path | None,
        summary: dict[str, Any],
        *,
        context: Any | None = None,
    ) -> Any:
        print(
            "\n[operator-navigation] A headed browser has been opened with the configured profile.\n"
            "[operator-navigation] Manually open https://www.tapology.com/fightcenter, complete Cloudflare, "
            "set filters, wait until the real FightCenter page with filters/events is visible, then press ENTER.\n"
            "[operator-navigation] While waiting, the scraper will not navigate, reload, poll selectors, "
            "run readiness checks, apply filters, or parse events.",
            flush=True,
        )
        log("warn", "operator_navigation_wait_start", kind=kind)
        while True:
            try:
                response = input(
                    "[operator-navigation] Press ENTER only after the real Tapology FightCenter page "
                    "with filters/events is visible. Type abort to stop: "
                )
            except EOFError as exc:
                raise FatalScrapeError(
                    "manual_challenge_input_unavailable: terminal input closed before operator confirmation"
                ) from exc
            if response.strip().lower() in {"abort", "q", "quit", "exit"}:
                raise FatalScrapeError("manual_challenge_aborted: operator aborted operator-navigation verification")

            page, snapshot = self._select_operator_fightcenter_page(page, kind, context=context)
            if snapshot.get("operator_current_page_ready"):
                print("[operator-navigation] FightCenter readiness confirmed on the current page.", flush=True)
                if snapshot.get("weak_challenge_indicators"):
                    log(
                        "warn",
                        "weak_captcha_marker_ignored_on_ready_fightcenter_page",
                        kind=kind,
                        weak_challenge_indicators=snapshot.get("weak_challenge_indicators"),
                        markers=snapshot["markers_present"],
                    )
                log("info", "operator_navigation_ready", kind=kind, markers=snapshot["markers_present"])
                self._print_and_validate_prescrape_confirmation(page, kind, run_dir, summary, snapshot=snapshot)
                return page

            classification = self._classify_operator_confirmed_fightcenter_not_ready(snapshot)
            self._record_fightcenter_readiness_diagnostic(page, kind, classification, snapshot, run_dir)
            if not self.config.pause_on_manual_challenge:
                raise FatalScrapeError(
                    f"{classification}: operator-confirmed page is not a ready FightCenter listing "
                    f"(url={snapshot.get('url')}, title={snapshot.get('title')}, "
                    f"challenge_indicators={snapshot.get('challenge_indicators')}, "
                    f"strong_challenge_indicators={snapshot.get('strong_challenge_indicators')}, "
                    f"weak_challenge_indicators={snapshot.get('weak_challenge_indicators')}, "
                    f"markers_present={snapshot.get('markers_present')})"
                )
            if classification == "operator_confirmed_but_challenge_still_present":
                print(
                    "[operator-navigation] Challenge markers are still present. Complete verification manually, "
                    "then press ENTER again only after the real FightCenter page is visible.",
                    flush=True,
                )
                continue
            if classification == "operator_confirmed_unexpected_page":
                print(
                    "[operator-navigation] The visible page is not the expected Tapology FightCenter page "
                    f"(url={snapshot.get('url')}, title={snapshot.get('title')}). "
                    "Correct it manually, then press ENTER again, or type abort.",
                    flush=True,
                )
                continue
            print(
                "[operator-navigation] FightCenter is visible but required readiness markers are missing "
                f"(missing={snapshot.get('markers_missing')}). Correct the page manually, then press ENTER again, "
                "or type abort.",
                flush=True,
            )

    def _select_operator_fightcenter_page(
        self,
        current_page: Any,
        kind: str,
        *,
        context: Any | None = None,
    ) -> tuple[Any, dict[str, Any]]:
        candidates = self._operator_page_candidates(current_page, context=context)
        best_page = current_page
        best_snapshot = self._fightcenter_readiness_snapshot(current_page, kind)
        best_score = self._operator_page_score(best_snapshot)
        for candidate in candidates:
            if candidate is current_page:
                continue
            try:
                snapshot = self._fightcenter_readiness_snapshot(candidate, kind)
            except Exception:
                continue
            score = self._operator_page_score(snapshot)
            if score > best_score:
                best_page = candidate
                best_snapshot = snapshot
                best_score = score
        return best_page, best_snapshot

    def _operator_page_candidates(self, current_page: Any, *, context: Any | None = None) -> list[Any]:
        pages: list[Any] = []
        for source in (context, getattr(current_page, "context", None)):
            try:
                pages.extend(list(getattr(source, "pages", []) or []))
            except Exception:
                continue
        if current_page not in pages:
            pages.insert(0, current_page)
        deduped: list[Any] = []
        for page in pages:
            if page not in deduped:
                deduped.append(page)
        return deduped

    def _operator_page_score(self, snapshot: dict[str, Any]) -> int:
        url = str(snapshot.get("url") or "").lower()
        score = 0
        if snapshot.get("operator_current_page_ready"):
            score += 100
        if snapshot.get("ready"):
            score += 50
        if "/fightcenter" in url:
            score += 25
        if "about:blank" not in url:
            score += 5
        markers = set(snapshot.get("markers_present") or [])
        if "required_filter_controls" in markers:
            score += 10
        if "event_listing_rows" in markers:
            score += 10
        if "fightcenter_form" in markers:
            score += 5
        return score

    def _current_filter_values(self, page: Any, kind: str) -> dict[str, Any]:
        try:
            controls = self._collect_filter_controls(page)
        except Exception:
            controls = []
        values = self._selected_fightcenter_filter_values(controls, kind)
        return {
            key: value
            for key, value in values.items()
            if value.get("selector") or value.get("value") is not None or value.get("text") is not None
        }

    def _selected_fightcenter_filter_values(self, controls: list[dict[str, Any]], kind: str) -> dict[str, dict[str, Any]]:
        filter_controls = {
            "promotion": self._find_fightcenter_select(controls, "promotion", self._promotion_aliases(), option_required=False),
            "schedule": self._find_fightcenter_select(
                controls,
                "schedule",
                FILTER_ALIASES["schedule_results"] if kind == "results" else FILTER_ALIASES["schedule_upcoming"],
                option_required=False,
            ),
            "sport": self._find_fightcenter_select(controls, "sport", self._sport_aliases(), option_required=False),
            "region": self._find_fightcenter_select(controls, "region", FILTER_ALIASES["region_all"], option_required=False),
        }
        selected: dict[str, dict[str, Any]] = {}
        for key, control in filter_controls.items():
            selected[key] = self._selected_option(control)
        return selected

    def _selected_option(self, control: dict[str, Any] | None) -> dict[str, Any]:
        if not control:
            return {"selector": None, "value": None, "text": None, "readable": False}
        value = control.get("value")
        selected_option = None
        for option in control.get("options", []) or []:
            if option.get("selected"):
                selected_option = option
                break
        if selected_option is None and value is not None:
            for option in control.get("options", []) or []:
                if str(option.get("value", "")) == str(value):
                    selected_option = option
                    break
        if selected_option is not None:
            return {
                "selector": control.get("selector"),
                "value": selected_option.get("value", ""),
                "text": selected_option.get("text"),
                "readable": True,
            }
        return {
            "selector": control.get("selector"),
            "value": value,
            "text": None,
            "readable": value is not None,
        }

    def _print_and_validate_prescrape_confirmation(
        self,
        page: Any,
        kind: str,
        run_dir: Path | None,
        summary: dict[str, Any],
        *,
        snapshot: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        if snapshot is None:
            snapshot = self._fightcenter_readiness_snapshot(page, kind)
        if snapshot.get("strong_challenge_indicators"):
            classification = "operator_confirmed_but_challenge_still_present"
            self._record_fightcenter_readiness_diagnostic(page, kind, classification, snapshot, run_dir)
            raise FatalScrapeError(
                f"{classification}: challenge markers present before current-page scrape "
                f"({snapshot.get('strong_challenge_indicators')})"
            )
        if not snapshot.get("ready"):
            classification = self._classify_operator_confirmed_fightcenter_not_ready(snapshot)
            self._record_fightcenter_readiness_diagnostic(page, kind, classification, snapshot, run_dir)
            raise FatalScrapeError(
                f"{classification}: current page is not a ready FightCenter listing "
                f"(url={snapshot.get('url')}, title={snapshot.get('title')})"
            )

        filters = self._current_filter_values(page, kind)
        schedule = filters.get("schedule", {})
        sport = filters.get("sport", {})
        promotion = filters.get("promotion", {})
        region = filters.get("region", {})
        schedule_text = normalize_space(schedule.get("text") or schedule.get("value")).lower()
        sport_text = normalize_space(sport.get("text") or sport.get("value")).lower()
        expected_schedule = "results" if kind == "results" else "upcoming"
        appears_results_mode = "result" in schedule_text or (kind == "results" and "results" in str(snapshot.get("url", "")).lower())
        appears_upcoming_mode = "upcoming" in schedule_text or (kind == "upcoming" and "upcoming" in str(snapshot.get("url", "")).lower())
        appears_expected_schedule = appears_results_mode if kind == "results" else appears_upcoming_mode
        appears_mma_sport = "mma" in sport_text or "mixed martial arts" in sport_text
        appears_listing = bool(snapshot.get("ready")) and "event_listing_rows" in (snapshot.get("markers_present") or [])
        filters_confident = bool(schedule.get("readable") and sport.get("readable"))

        confirmation = {
            "current_url": snapshot.get("url"),
            "page_title": snapshot.get("title"),
            "filters": {
                "promotion": promotion,
                "schedule": schedule,
                "sport": sport,
                "region": region,
            },
            "visible_listing_event_rows": int(snapshot.get("event_listing_links_count") or 0),
            "appears_results_mode": appears_results_mode,
            "appears_mma_sport": appears_mma_sport,
            "appears_fightcenter_listing_page": appears_listing,
            "filters_confident": filters_confident,
        }
        summary.setdefault("pre_scrape_confirmation", {})[kind] = confirmation
        log("info", "pre_scrape_confirmation", kind=kind, **confirmation)
        print(
            "\nPre-scrape FightCenter confirmation:\n"
            f"  current URL: {confirmation['current_url']}\n"
            f"  page title: {confirmation['page_title']}\n"
            f"  promotion: {promotion.get('text') or promotion.get('value') or 'unreadable'}\n"
            f"  schedule: {schedule.get('text') or schedule.get('value') or 'unreadable'}\n"
            f"  sport: {sport.get('text') or sport.get('value') or 'unreadable'}\n"
            f"  region: {region.get('text') or region.get('value') or 'unreadable'}\n"
            f"  visible listing event rows: {confirmation['visible_listing_event_rows']}\n"
            f"  appears results mode: {confirmation['appears_results_mode']}\n"
            f"  appears MMA sport filter: {confirmation['appears_mma_sport']}\n"
            f"  appears FightCenter listing page: {confirmation['appears_fightcenter_listing_page']}",
            flush=True,
        )

        if kind == "results" and schedule.get("readable") and not appears_expected_schedule:
            raise FatalScrapeError(
                f"wrong_fightcenter_filter_state: expected Results schedule, got {schedule.get('text') or schedule.get('value')}"
            )
        if kind == "upcoming" and schedule.get("readable") and not appears_expected_schedule:
            raise FatalScrapeError(
                f"wrong_fightcenter_filter_state: expected Upcoming schedule, got {schedule.get('text') or schedule.get('value')}"
            )
        if normalize_space(self.config.sport).lower() == "mma" and sport.get("readable") and not appears_mma_sport:
            raise FatalScrapeError(
                f"wrong_fightcenter_filter_state: expected MMA sport, got {sport.get('text') or sport.get('value')}"
            )
        if not appears_listing:
            raise FatalScrapeError("unexpected_page_after_challenge: current page is not a FightCenter listing page")
        if not filters_confident:
            self._confirm_unreadable_filters_or_fail()
        return confirmation

    def _confirm_unreadable_filters_or_fail(self) -> None:
        try:
            response = input(
                "[operator-navigation] Filter values were not read confidently. "
                "Type continue to scrape the visible current page, or abort to stop: "
            )
        except EOFError as exc:
            raise FatalScrapeError(
                "manual_challenge_input_unavailable: filter confirmation required but terminal input is unavailable"
            ) from exc
        if response.strip().lower() not in {"continue", "c"}:
            raise FatalScrapeError("manual_challenge_aborted: operator did not confirm unreadable filter state")

    def _recover_fightcenter_once(
        self,
        page: Any,
        kind: str,
        original_url: str,
        summary: dict[str, Any],
    ) -> dict[str, Any]:
        summary["fightcenter_safe_reloads"] = int(summary.get("fightcenter_safe_reloads") or 0) + 1
        log("warn", "fightcenter_safe_reload", kind=kind, url=original_url)
        try:
            page.wait_for_timeout(1500)
        except Exception:
            time.sleep(1.5)
        try:
            page.goto(original_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
        except Exception as exc:
            log("warn", "fightcenter_safe_reload_failed", kind=kind, error=str(exc))
        self._wait_for_filter_controls(page)
        return self._fightcenter_readiness_snapshot(page, kind)

    def _ensure_fightcenter_ready(
        self,
        page: Any,
        kind: str,
        run_dir: Path | None,
        summary: dict[str, Any],
        original_url: str,
    ) -> None:
        initial_classified_challenge_indicators = classify_challenge_indicators(page)
        initial_strong_challenge_indicators = initial_classified_challenge_indicators.get("strong", [])
        if initial_strong_challenge_indicators and not self.config.headless and self.config.pause_on_manual_challenge:
            self._hard_pause_for_fightcenter_challenge(page, kind, run_dir, initial_strong_challenge_indicators)
            return

        self._wait_for_filter_controls(page)
        snapshot = self._fightcenter_readiness_snapshot(page, kind)
        if snapshot["ready"]:
            if snapshot.get("weak_challenge_indicators"):
                log(
                    "warn",
                    "weak_captcha_marker_ignored_on_ready_fightcenter_page",
                    kind=kind,
                    weak_challenge_indicators=snapshot.get("weak_challenge_indicators"),
                    markers=snapshot["markers_present"],
                )
            log("info", "fightcenter_ready", kind=kind, markers=snapshot["markers_present"])
            return

        if (
            snapshot.get("challenge_indicators")
            and not self.config.headless
            and self.config.wait_for_manual_challenge_seconds > 0
        ):
            initial_reason = snapshot["challenge_indicators"][0]
            remaining = wait_for_manual_challenge(
                page,
                timeout_seconds=self.config.wait_for_manual_challenge_seconds,
                poll_interval_ms=self.config.manual_challenge_poll_interval_ms,
                log=log,
                initial_reason=initial_reason,
                context_name=f"fightcenter_{kind}",
                readiness_check=lambda: self._fightcenter_readiness_snapshot(page, kind)["ready"],
            )
            if remaining is None:
                return
            snapshot = self._fightcenter_readiness_snapshot(page, kind)
            if snapshot["ready"]:
                return
            snapshot = self._recover_fightcenter_once(page, kind, original_url, summary)
            if snapshot["ready"]:
                log("info", "fightcenter_ready_after_safe_reload", kind=kind)
                return

        classification = self._classify_fightcenter_not_ready(snapshot)
        self._record_fightcenter_readiness_diagnostic(page, kind, classification, snapshot, run_dir)
        raise FatalScrapeError(
            f"{classification}: FightCenter page not ready "
            f"(url={snapshot.get('url')}, title={snapshot.get('title')}, "
            f"challenge_indicators={snapshot.get('challenge_indicators')}, "
            f"markers_present={snapshot.get('markers_present')})"
        )

    def _apply_filters(self, page: Any, kind: str, summary: dict[str, Any], run_dir: Path | None = None) -> dict[str, Any]:
        controls = self._collect_filter_controls(page)
        log("info", "filters_detected", kind=kind, controls_count=len(controls))
        promotion_aliases = self._promotion_aliases()
        sport_aliases = self._sport_aliases()
        promotion = self._find_fightcenter_select(controls, "promotion", promotion_aliases)
        schedule = self._find_fightcenter_select(
            controls,
            "schedule",
            FILTER_ALIASES["schedule_results"] if kind == "results" else FILTER_ALIASES["schedule_upcoming"],
        )
        sport = self._find_fightcenter_select(controls, "sport", sport_aliases)
        region = self._find_fightcenter_select(controls, "region", FILTER_ALIASES["region_all"], option_required=False)

        missing = [name for name, value in {"promotion": promotion, "schedule": schedule, "sport": sport}.items() if not value]
        if missing:
            snapshot = self._fightcenter_readiness_snapshot(page, kind, controls=controls)
            if snapshot.get("challenge_indicators"):
                self._record_fightcenter_readiness_diagnostic(
                    page,
                    kind,
                    "cloudflare_challenge_not_cleared",
                    snapshot,
                    run_dir,
                )
                raise FatalScrapeError(
                    "cloudflare_challenge_not_cleared: FightCenter controls missing while "
                    f"challenge indicators are still present ({snapshot.get('challenge_indicators')})"
                )
            self._record_missing_filter_controls(page, kind, missing, controls, run_dir)
            raise FilterApplyError(f"Could not find required FightCenter filter controls: {', '.join(missing)}")

        used: dict[str, Any] = {}
        for key, control, aliases in [
            ("promotion", promotion, promotion_aliases),
            ("schedule", schedule, FILTER_ALIASES["schedule_results"] if kind == "results" else FILTER_ALIASES["schedule_upcoming"]),
            ("sport", sport, sport_aliases),
        ]:
            value, text = self._option_value(control, aliases)
            if value is None:
                raise FilterApplyError(f"Could not resolve option value for {key}")
            page.locator(control["selector"]).select_option(value=value)
            used[key] = {"selector": control["selector"], "value": value, "text": text}
            log("info", "filter_selected", kind=kind, key=key, option_text=text, option_value=value)

        if region:
            region_value, region_text = self._option_value(region, FILTER_ALIASES["region_all"], prefer_blank=True)
            if region_value is not None:
                page.locator(region["selector"]).select_option(value=region_value)
                used["region"] = {"selector": region["selector"], "value": region_value, "text": region_text}
                log("info", "filter_selected", kind=kind, key="region", option_text=region_text, option_value=region_value)

        self._submit_filters(page, summary)
        page.wait_for_timeout(self.config.conservative_delay_ms)
        self._raise_if_blocked(page, None, f"after_filters_{kind}")
        try:
            page.wait_for_selector(FIGHTCENTER_EVENTS_SELECTOR, timeout=10_000)
        except Exception:
            raise FilterApplyError(f"Filtered event list not found: {FIGHTCENTER_EVENTS_SELECTOR}")
        return used

    def _record_missing_filter_controls(
        self,
        page: Any,
        kind: str,
        missing: list[str],
        controls: list[dict[str, Any]],
        run_dir: Path | None,
    ) -> None:
        page_url = getattr(page, "url", None)
        title = None
        body_excerpt = None
        try:
            title = page.title()
        except Exception:
            pass
        try:
            body_excerpt = normalize_space(page.locator("body").inner_text(timeout=1000))[:1000]
        except Exception:
            pass

        diagnostic = {
            "type": "missing_fightcenter_filter_controls",
            "kind": kind,
            "missing_controls": missing,
            "controls_count": len(controls),
            "url": page_url,
            "title": title,
            "body_excerpt": body_excerpt,
        }
        try:
            snapshot = self._fightcenter_readiness_snapshot(page, kind, controls=controls)
            diagnostic.update(
                {
                    "challenge_indicators": snapshot.get("challenge_indicators", []),
                    "markers_present": snapshot.get("markers_present", []),
                    "markers_missing": snapshot.get("markers_missing", []),
                    "required_controls_present": snapshot.get("required_controls_present", {}),
                }
            )
        except Exception:
            pass
        if run_dir:
            diagnostic.update(
                save_debug_artifacts(page, run_dir, f"fightcenter_{kind}_missing_filter_controls")
            )
        self._debug_append("diagnostics", diagnostic)
        log("error", "missing_filter_controls", **diagnostic)

    def _submit_filters(self, page: Any, summary: dict[str, Any]) -> None:
        form = page.locator(f"#{FIGHTCENTER_FORM_ID}")
        clicked = False
        before_events_text = self._event_list_text(page)
        submit_candidates = [
            f"#{FIGHTCENTER_FORM_ID} input[type=submit]",
            f"#{FIGHTCENTER_FORM_ID} button[type=submit]",
            f"#{FIGHTCENTER_FORM_ID} button:has-text('Search')",
            f"#{FIGHTCENTER_FORM_ID} button:has-text('Filter')",
            f"#{FIGHTCENTER_FORM_ID} input[value*='Search']",
            f"#{FIGHTCENTER_FORM_ID} input[value*='Filter']",
        ]
        for selector in submit_candidates:
            try:
                locator = page.locator(selector)
                if locator.count() and locator.first.is_visible(timeout=500):
                    locator.first.click(timeout=5000)
                    summary.setdefault("selectors_used", []).append(selector)
                    clicked = True
                    break
            except Exception:
                continue
        if not clicked and form.count():
            page.evaluate(
                """(formId) => {
                  const form = document.getElementById(formId);
                  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
                }""",
                FIGHTCENTER_FORM_ID,
            )
            summary.setdefault("selectors_used", []).append(f"#{FIGHTCENTER_FORM_ID}.requestSubmit")
            clicked = True
        if not clicked:
            raise FilterApplyError("Could not submit FightCenter filter form")
        try:
            page.wait_for_load_state("domcontentloaded", timeout=10_000)
        except Exception:
            pass
        if before_events_text:
            try:
                page.wait_for_function(
                    """({ rootSelector, beforeText }) => {
                      const root = document.querySelector(rootSelector);
                      return root && root.innerText.trim() && root.innerText.trim() !== beforeText;
                    }""",
                    {"rootSelector": FIGHTCENTER_EVENTS_SELECTOR, "beforeText": before_events_text},
                    timeout=10_000,
                )
            except Exception:
                pass
        try:
            page.wait_for_selector(FIGHTCENTER_EVENTS_SELECTOR, timeout=10_000)
        except Exception:
            pass
        log("info", "filters_applied")

    def _event_list_text(self, page: Any) -> str:
        try:
            locator = page.locator(FIGHTCENTER_EVENTS_SELECTOR)
            if locator.count():
                return normalize_space(locator.first.inner_text(timeout=1000))
        except Exception:
            return ""
        return ""

    def _find_fightcenter_select(
        self,
        controls: list[dict[str, Any]],
        label_keyword: str,
        aliases: list[str],
        *,
        option_required: bool = True,
    ) -> dict[str, Any] | None:
        label_keyword = label_keyword.lower()
        matching_label_controls: list[dict[str, Any]] = []
        for control in controls:
            if control.get("tag") != "select":
                continue
            if self._control_matches_filter_label(control, label_keyword):
                matching_label_controls.append(control)

        for control in matching_label_controls:
            if not option_required or self._option_value(control, aliases, prefer_blank=label_keyword == "region")[0] is not None:
                return control
        return self._find_select_by_option(controls, aliases, label_keyword) if option_required else None

    def _control_matches_filter_label(self, control: dict[str, Any], label_keyword: str) -> bool:
        label_keyword = label_keyword.lower()
        id_name_parts = [
            normalize_space(control.get("id")).lower(),
            normalize_space(control.get("name")).lower(),
            normalize_space(control.get("label")).lower(),
            normalize_space(control.get("aria_label")).lower(),
        ]
        if label_keyword in id_name_parts:
            return True
        nearby_lines = [
            normalize_space(line).lower()
            for line in str(control.get("nearby_text", "")).splitlines()
            if normalize_space(line)
        ]
        return bool(nearby_lines and nearby_lines[0] == label_keyword)

    def _find_select_by_option(
        self,
        controls: list[dict[str, Any]],
        aliases: list[str],
        preferred_label: str | None = None,
    ) -> dict[str, Any] | None:
        if preferred_label:
            for control in controls:
                if control.get("tag") != "select":
                    continue
                haystack = " ".join(
                    [
                        control.get("id", ""),
                        control.get("name", ""),
                        control.get("label", ""),
                        control.get("aria_label", ""),
                        control.get("nearby_text", ""),
                    ]
                ).lower()
                option_texts = [normalize_space(option.get("text")).lower() for option in control.get("options", [])]
                if preferred_label in haystack and any(alias in option_texts for alias in aliases):
                    return control
        for control in controls:
            if control.get("tag") != "select":
                continue
            option_texts = [normalize_space(option.get("text")).lower() for option in control.get("options", [])]
            if any(alias in option_texts for alias in aliases):
                return control
        for control in controls:
            if control.get("tag") != "select":
                continue
            haystack = " ".join(
                [
                    control.get("id", ""),
                    control.get("name", ""),
                    control.get("label", ""),
                    control.get("aria_label", ""),
                    control.get("nearby_text", ""),
                ]
            ).lower()
            if any(alias in haystack for alias in aliases):
                return control
        return None

    def _promotion_aliases(self) -> list[str]:
        scope = normalize_space(self.config.promotion_scope).lower()
        return FILTER_ALIASES.get(f"promotion_{scope}", [scope])

    def _sport_aliases(self) -> list[str]:
        sport = normalize_space(self.config.sport).lower()
        return FILTER_ALIASES["sport"] if sport == "mma" else [sport]

    def _find_region_select(self, controls: list[dict[str, Any]]) -> dict[str, Any] | None:
        for control in controls:
            if control.get("tag") != "select":
                continue
            haystack = " ".join([control.get("id", ""), control.get("name", ""), control.get("label", "")]).lower()
            if "region" in haystack or "location" in haystack:
                return control
        return None

    def _option_value(
        self,
        control: dict[str, Any],
        aliases: list[str],
        *,
        prefer_blank: bool = False,
    ) -> tuple[str | None, str | None]:
        options = control.get("options", [])
        if prefer_blank:
            for option in options:
                text = normalize_space(option.get("text")).lower()
                value = option.get("value", "")
                if value == "" or text in aliases:
                    return value, option.get("text")
        for alias in aliases:
            for option in options:
                if normalize_space(option.get("text")).lower() == alias:
                    return option.get("value", ""), option.get("text")
        for alias in aliases:
            for option in options:
                if alias and alias in normalize_space(option.get("text")).lower():
                    return option.get("value", ""), option.get("text")
        return None, None

    def _traverse_event_list(
        self,
        page: Any,
        kind: str,
        context: Any,
        run_dir: Path,
        summary: dict[str, Any],
    ) -> list[dict[str, Any]]:
        events = self._resume_upcoming_events(summary) if kind == "upcoming" else []
        resumed_event_urls = {
            canonical_url(event.get("event_url"))
            for event in events
            if canonical_url(event.get("event_url"))
        }
        resumed_event_ids = {
            str(event.get("source_event_id"))
            for event in events
            if event.get("source_event_id")
        }
        seen_event_urls: set[str] = set()
        last_dated_list_item: date | None = None
        saw_from_date_eligible_item = False
        saw_window_item = False
        list_order_confirmed = True
        page_number = 1
        consecutive_known_complete = 0
        stop_requested = False
        while True:
            self._dismiss_overlays(page, summary, max_rounds=2, context=f"listing_page:{kind}:{page_number}")
            raw_list_items = self._extract_event_list_items(page, page_number)
            listing_url = getattr(page, "url", FIGHTCENTER_URL)
            summary.setdefault("listing_urls_visited", [])
            if listing_url and listing_url not in summary["listing_urls_visited"]:
                summary["listing_urls_visited"].append(listing_url)
            summary["first_listing_url"] = summary.get("first_listing_url") or listing_url
            summary["last_listing_url"] = listing_url or summary.get("last_listing_url")
            list_items = []
            for item in raw_list_items:
                event_url = item.get("event_url")
                if event_url in seen_event_urls:
                    self._pagination_counts["duplicate_listing_event_urls_seen"] += 1
                    continue
                if event_url:
                    seen_event_urls.add(event_url)
                    self._pagination_counts["unique_listing_event_urls_seen"] = len(seen_event_urls)
                list_items.append(item)
            self._sync_runtime_diagnostics(summary)
            log(
                "info",
                "list_page_parsed",
                kind=kind,
                page_number=page_number,
                events=len(list_items),
                raw_events=len(raw_list_items),
            )
            summary["pages_scanned"] = int(summary.get("pages_scanned") or 0) + 1
            for item in list_items:
                if self.config.max_events and len(events) >= self.config.max_events:
                    break
                if kind == "upcoming" and (
                    canonical_url(item.get("event_url")) in resumed_event_urls
                    or str(item.get("source_event_id") or "") in resumed_event_ids
                ):
                    summary["upcoming_events_skipped_from_checkpoint"] = int(
                        summary.get("upcoming_events_skipped_from_checkpoint") or 0
                    ) + 1
                    continue
                item_date = self._parse_iso_date(item.get("event_date"))
                if kind == "upcoming" and item_date:
                    if self._from_date and item_date < self._from_date:
                        last_dated_list_item = item_date
                        continue
                    if self._until_date and item_date > self._until_date:
                        if saw_window_item and (last_dated_list_item is None or item_date >= last_dated_list_item):
                            summary["date_window_stop_confirmed"] = True
                            stop_requested = True
                            break
                        last_dated_list_item = item_date
                        continue
                    saw_window_item = True
                    last_dated_list_item = item_date
                elif self._from_date and item_date:
                    if last_dated_list_item and item_date > last_dated_list_item:
                        list_order_confirmed = False
                        warning = "from_date_stop_disabled:list_not_newest_to_oldest"
                        if warning not in summary["warnings"]:
                            summary["warnings"].append(warning)
                        log(
                            "warning",
                            "from_date_order_not_confirmed",
                            previous_date=last_dated_list_item.isoformat(),
                            current_date=item_date.isoformat(),
                        )
                    last_dated_list_item = item_date
                    if item_date < self._from_date:
                        log(
                            "info",
                            "event_skipped_before_from_date",
                            source_event_id=item.get("source_event_id"),
                            event_date=item.get("event_date"),
                            from_date=self.config.from_date,
                        )
                        if saw_from_date_eligible_item and list_order_confirmed:
                            stop_requested = True
                            log(
                                "info",
                                "from_date_stop",
                                event_date=item.get("event_date"),
                                from_date=self.config.from_date,
                            )
                            break
                        continue
                    saw_from_date_eligible_item = True
                if self._list_item_matches_excluded_ufc(item):
                    event = self._skipped_ufc_event_from_item(item)
                    self._record_skipped_ufc_event(summary, event)
                    log(
                        "info",
                        "event_skipped_ufc_listing",
                        source_event_id=event.get("source_event_id"),
                        event_url=event.get("event_url"),
                        promotion_name=event.get("promotion_name"),
                    )
                    continue
                if kind == "results" and self.config.cumulative_results:
                    self._record_cumulative_scope_item(summary, item)
                if kind == "results" and self._state_bundle is not None:
                    should_scrape, reason = should_recheck_event(
                        list_item=item,
                        bundle=self._state_bundle,
                        now=datetime.now(timezone.utc),
                        recheck_days=self.config.recheck_days,
                        recheck_complete_days=self.config.recheck_complete_days,
                        recheck_known_limit=self.config.recheck_known_limit,
                        force_recheck_events=self._force_recheck_events,
                        force_full_scan=self._effective_force_full_scan(),
                    )
                    if not should_scrape:
                        missing_cached_payload = False
                        if reason == "known_complete_outside_recheck_window":
                            consecutive_known_complete += 1
                            if self.config.cumulative_results:
                                source_event_id = item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
                                if self._known_event_is_cumulative_excluded(source_event_id):
                                    self._record_cumulative_excluded_item(summary, item)
                                elif self._cached_event_payload(source_event_id) is None:
                                    missing_cached_payload = True
                                    warning = f"missing_cached_event_payload:{source_event_id}"
                                    if warning not in summary["warnings"]:
                                        summary["warnings"].append(warning)
                                    summary["missing_cached_event_payloads"] = (
                                        int(summary.get("missing_cached_event_payloads") or 0) + 1
                                    )
                                    log(
                                        "warning",
                                        "missing_cached_event_payload",
                                        source_event_id=source_event_id,
                                        event_url=item.get("event_url"),
                                    )
                            if not missing_cached_payload:
                                summary["events_skipped_known_stable"] = (
                                    int(summary.get("events_skipped_known_stable") or 0) + 1
                                )
                                summary["events_skipped_known_cache"] = (
                                    int(summary.get("events_skipped_known_cache") or 0) + 1
                                )
                                self._cache_counts["events_skipped_known_cache"] += 1
                        else:
                            consecutive_known_complete = 0
                            if self.config.cumulative_results:
                                source_event_id = item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))
                                if self._cached_event_payload(source_event_id) is None:
                                    missing_cached_payload = True
                                    warning = f"missing_cached_event_payload:{source_event_id}"
                                    if warning not in summary["warnings"]:
                                        summary["warnings"].append(warning)
                                    summary["missing_cached_event_payloads"] = (
                                        int(summary.get("missing_cached_event_payloads") or 0) + 1
                                    )
                        log(
                            "info",
                            "event_skipped",
                            source_event_id=item.get("source_event_id"),
                            reason=reason,
                            consecutive_known_complete=consecutive_known_complete,
                        )
                        if missing_cached_payload:
                            should_scrape = True
                            reason = "missing_cached_event_payload"
                            consecutive_known_complete = 0
                        elif consecutive_known_complete >= self.config.stop_after_known_complete:
                            stop_requested = True
                            summary["early_stop_triggered"] = True
                            summary["early_stop_reason"] = (
                                f"{self.config.stop_after_known_complete} consecutive known stable events outside recheck scope"
                            )
                            break
                        else:
                            continue
                    consecutive_known_complete = 0
                    if reason == "recent_recheck_window":
                        summary["events_rechecked_lookback"] = int(summary.get("events_rechecked_lookback") or 0) + 1
                    elif reason not in {"new_visible_event", "force_full_scan", "forced_event", "recent_known_limit"}:
                        summary["events_rechecked_incomplete"] = (
                            int(summary.get("events_rechecked_incomplete") or 0) + 1
                        )
                    item["recheck_reason"] = reason
                try:
                    event = self._scrape_event_page(context, item, kind, run_dir, summary)
                    if self._event_is_skipped_ufc(event):
                        self._record_skipped_ufc_event(summary, event)
                        log(
                            "info",
                            "event_skipped_ufc",
                            source_event_id=event.get("source_event_id"),
                            event_url=event.get("event_url"),
                        )
                        continue
                    if not self._event_matches_date_window(event):
                        log(
                            "info",
                            "event_skipped_outside_date_window_after_scrape",
                            source_event_id=event.get("source_event_id"),
                            event_date=event.get("event_date"),
                            from_date=self.config.from_date,
                            until_date=self.config.until_date,
                        )
                        continue
                    if self._from_date:
                        saw_from_date_eligible_item = True
                    if kind == "results" and should_skip_results_event_no_usable_bouts(event):
                        if self.config.cumulative_results:
                            self._record_cumulative_excluded_event(summary, event)
                        self._record_skipped_results_event(summary, event)
                        log(
                            "info",
                            "event_skipped_no_usable_bouts",
                            source_event_id=event.get("source_event_id"),
                            event_url=event.get("event_url"),
                            warnings=event.get("warnings", []),
                        )
                        continue
                    events.append(event)
                    self._write_results_checkpoint(kind, "", "", run_dir, summary, events)
                    self._record_access_success(summary)
                    log("info", "event_parsed", kind=kind, event_url=item.get("event_url"), bouts=len(event.get("bouts", [])))
                    if self.config.event_pause_seconds > 0:
                        self._pause(
                            "event",
                            self.config.event_pause_seconds,
                            kind=kind,
                            events_written=len(events),
                            event_url=item.get("event_url"),
                        )
                    else:
                        page.wait_for_timeout(self.config.event_delay_ms)
                except FatalScrapeError as exc:
                    setattr(exc, "partial_events", list(events))
                    raise
                except Exception as exc:
                    self._record_failed_event(summary, item, exc)
                    self._event_fetch_failures_seen += 1
                    summary["event_fetch_failures"] = self._event_fetch_failures_seen
                    log("error", "event_failed", event_url=item.get("event_url"), error=str(exc))
                    try:
                        if self._is_browser_context_closed_message(str(exc)):
                            self._stop_for_access_degradation("browser_context_closed")
                        if self._is_access_failure_message(str(exc)):
                            self._record_access_failure(str(exc), summary)
                        self._check_event_failure_threshold()
                    except FatalScrapeError as fatal:
                        setattr(fatal, "partial_events", list(events))
                        raise
            if self.config.max_events and len(events) >= self.config.max_events:
                summary["pagination_stop_reason"] = "max_events"
                break
            if stop_requested:
                summary["pagination_stop_reason"] = summary.get("early_stop_reason") or "requested_stop"
                break
            if self.config.max_pages and page_number >= self.config.max_pages:
                # This can legitimately fire on the last available batch when the
                # configured page cap matches the site depth. `pagination_next_found`
                # may still reflect the previous successful load-more check.
                summary["pagination_stop_reason"] = "max_pages"
                break
            self._pause("page", self.config.page_pause_seconds, kind=kind, page_number=page_number)
            try:
                if not self._advance_list_page(page, summary, seen_event_urls, run_dir=run_dir, page_number=page_number):
                    break
            except FatalScrapeError as exc:
                setattr(exc, "partial_events", list(events))
                raise
            page_number += 1
            try:
                self._raise_if_blocked(page, run_dir, f"list_page_{kind}_{page_number}")
            except FatalScrapeError as exc:
                setattr(exc, "partial_events", list(events))
                raise
            self._sync_runtime_diagnostics(summary)
        return events

    @staticmethod
    def _parse_iso_date(value: str | None) -> date | None:
        if not value:
            return None
        try:
            return date.fromisoformat(value)
        except ValueError:
            return None

    def _event_matches_from_date(self, event: dict[str, Any]) -> bool:
        return self._event_matches_date_window(event)

    def _event_matches_date_window(self, event: dict[str, Any]) -> bool:
        event_date = self._parse_iso_date(event.get("event_date"))
        if event_date is None:
            return False if self._from_date or self._until_date else True
        if self._from_date and event_date < self._from_date:
            return False
        if self._until_date and event_date > self._until_date:
            return False
        return True

    def _extract_event_list_items(self, page: Any, page_number: int) -> list[dict[str, Any]]:
        try:
            page.wait_for_selector(FIGHTCENTER_EVENTS_SELECTOR, timeout=10_000)
        except Exception:
            raise FatalScrapeError(f"FightCenter event list not found: {FIGHTCENTER_EVENTS_SELECTOR}")
        rows = page.evaluate(
            """
            ({ rootSelector, eventSelector }) => {
              const root = document.querySelector(rootSelector);
              if (!root) return [];
              const seen = new Set();
              return Array.from(root.querySelectorAll(eventSelector)).map((link) => {
                const href = link.getAttribute('href') || '';
                if (!href || seen.has(href)) return null;
                seen.add(href);
                let container = link.closest('article,li,tr,.event,.fightCard,.fcListing,.result,.promotion,.fightcenterEvent') || link.parentElement;
                let parent = container;
                for (let i = 0; i < 3 && parent && root.contains(parent) && (parent.innerText || '').length < 120; i++) {
                  parent = parent.parentElement;
                  if (parent && root.contains(parent)) container = parent;
                }
                return {
                  href,
                  link_text: link.innerText.trim(),
                  raw_text: (container?.innerText || link.innerText || '').trim()
                };
              }).filter(Boolean);
            }
            """,
            {"rootSelector": FIGHTCENTER_EVENTS_SELECTOR, "eventSelector": EVENT_LINK_SELECTOR},
        )
        items: list[dict[str, Any]] = []
        for index, row in enumerate(rows, start=1):
            event_url = absolute_url(FIGHTCENTER_URL, row.get("href"))
            raw_text = normalize_space(row.get("raw_text"))
            date_raw = self._extract_date_raw(raw_text)
            item = {
                "event_name": normalize_space(row.get("link_text")),
                "event_url": event_url,
                "source_event_id": source_event_id_from_url(event_url),
                "promotion_name": self._extract_promotion(raw_text),
                "event_date": parse_date_to_iso(date_raw),
                "location": self._extract_location(raw_text),
                "region": None,
                "visible_main_bout": self._extract_visible_main_bout(raw_text),
                "list_page_number": page_number,
                "list_position": index,
            }
            self._debug_append(
                "events",
                {
                    "event_url": event_url,
                    "source_event_id": item["source_event_id"],
                    "list_page_number": page_number,
                    "list_position": index,
                    "raw_text": raw_text,
                    "event_date_raw": date_raw,
                    "visible_bout_count": self._extract_visible_bout_count(raw_text),
                },
            )
            if event_url:
                items.append(item)
        return items

    def _record_failed_event(self, summary: dict[str, Any], item: dict[str, Any], exc: Exception) -> None:
        sid=item.get('source_event_id') or source_event_id_from_url(item.get('event_url'))
        if sid:
            self._coverage_entries[sid]={'source_event_id':sid,'event_url':item.get('event_url'),'observed_at':utc_now(),'parser_version':PARSER_VERSION,'status':'unavailable','reasons':[str(exc)[:500]],'bouts':[],'counts':{},'unique_bouts':0,'source_mma_bouts_total':None}
        event_url = item.get("event_url")
        summary.setdefault("failed_event_urls", []).append(event_url)
        summary.setdefault("warnings", []).append(f"event_failed:{event_url}:{exc}")
        examples = summary.setdefault("failed_event_examples", [])
        if len(examples) < 25:
            examples.append(
                {
                    "source_event_id": item.get("source_event_id") or source_event_id_from_url(event_url),
                    "event_name": item.get("event_name"),
                    "event_url": event_url,
                    "list_page_number": item.get("list_page_number"),
                    "list_position": item.get("list_position"),
                    "reason": str(exc),
                }
            )

    def _record_failed_bout(
        self,
        summary: dict[str, Any],
        *,
        bout_url: str | None,
        event_url: str | None = None,
        row: dict[str, Any] | None = None,
        reason: Exception | str,
    ) -> None:
        summary.setdefault("failed_bout_urls", []).append(bout_url)
        examples = summary.setdefault("failed_bout_examples", [])
        if len(examples) < 25:
            fighters = (row or {}).get("fighter_links") or []
            examples.append(
                {
                    "source_bout_id": source_bout_id_from_url(bout_url),
                    "bout_url": bout_url,
                    "event_url": event_url,
                    "fighter_a_name": fighters[0].get("name") if len(fighters) > 0 else None,
                    "fighter_b_name": fighters[1].get("name") if len(fighters) > 1 else None,
                    "reason": str(reason),
                }
            )

    def _record_failed_profile(
        self,
        summary: dict[str, Any] | None,
        *,
        event: dict[str, Any] | None,
        fighter: dict[str, Any] | None,
        profile_url: str | None,
        fallback_name: str | None,
        reason: Exception | str,
    ) -> None:
        if summary is None:
            return
        examples = summary.setdefault("failed_profile_examples", [])
        if len(examples) >= 25:
            return
        source_fighter_id = (
            (fighter or {}).get("source_fighter_id")
            or source_fighter_id_from_url(profile_url)
        )
        examples.append(
            {
                "source_fighter_id": source_fighter_id,
                "profile_url": profile_url,
                "fighter_name": (fighter or {}).get("name") or fallback_name,
                "event_url": (event or {}).get("event_url"),
                "source_event_id": (event or {}).get("source_event_id"),
                "event_name": (event or {}).get("event_name"),
                "reason": str(reason),
            }
        )

    def _advance_list_page(
        self,
        page: Any,
        summary: dict[str, Any],
        seen_event_urls: set[str] | None = None,
        *,
        run_dir: Path | None = None,
        page_number: int | None = None,
    ) -> bool:
        before_count = self._event_list_link_count(page)
        before_urls = self._event_list_urls(page)
        before_page_url = page.url
        seen_event_urls = seen_event_urls or set(before_urls)
        self._pagination_counts["unique_listing_event_urls_seen"] = max(
            int(self._pagination_counts.get("unique_listing_event_urls_seen") or 0),
            int(summary.get("unique_listing_event_urls_seen") or 0),
            len(seen_event_urls),
        )
        self._pagination_counts["duplicate_listing_event_urls_seen"] = max(
            int(self._pagination_counts.get("duplicate_listing_event_urls_seen") or 0),
            int(summary.get("duplicate_listing_event_urls_seen") or 0),
        )
        self._dismiss_overlays(page, summary, max_rounds=2, context="load_more_before_click")
        button, button_diagnostics = self._select_load_more_button(page)
        summary["pagination_next_found"] = bool(button)
        if not button:
            summary["pagination_next_found"] = False
            if summary.get("pagination_stop_reason") != "load_more_timeout":
                summary["pagination_stop_reason"] = "natural_end_no_load_more_button"
            self._sync_runtime_diagnostics(summary)
            log("info", "list_no_more_results", load_more=button_diagnostics)
            return False
        next_href = self._load_more_href(button)
        if next_href:
            button_diagnostics["href"] = next_href
        self._pagination_counts["load_more_clicks"] += 1
        summary.setdefault("selectors_used", []).append("#loadMoreButton")
        try:
            if hasattr(button, "scroll_into_view_if_needed"):
                button.scroll_into_view_if_needed(timeout=5000)
            click_ok, click_error = self._click_load_more_button(page, button, summary)
            if not click_ok:
                raise RuntimeError(click_error or "load_more_click_failed")
        except Exception as exc:
            if self._advance_list_page_via_href(
                page,
                next_href,
                summary,
                seen_event_urls,
                run_dir=run_dir,
                page_number=page_number,
                reason="click_exception",
                before_count=before_count,
                before_urls=before_urls,
                before_page_url=before_page_url,
                error=str(exc),
            ):
                return True
            self._pagination_counts["load_more_timeouts"] += 1
            summary["pagination_stop_reason"] = "load_more_timeout"
            self._sync_runtime_diagnostics(summary)
            self._write_load_more_timeout_diagnostics(
                page,
                summary,
                run_dir,
                before_count=before_count,
                after_count=self._event_list_link_count(page),
                button_diagnostics=button_diagnostics,
                current_batch_number=page_number,
            )
            if self.config.stop_on_access_degradation:
                self._stop_for_access_degradation("load_more_timeout")
            log("warning", "list_load_more_click_timeout", selector="#loadMoreButton", error=str(exc))
            return False
        self._settle_load_more_after_click(page, summary, before_count=before_count)
        wait_result = self._wait_for_more_events(page, before_count, before_urls, before_page_url)
        after_urls = self._event_list_urls(page)
        after_count = self._event_list_link_count(page)
        new_unique_urls = set(after_urls) - set(seen_event_urls)
        if wait_result == "success" and new_unique_urls:
            self._pagination_counts["load_more_successes"] += 1
            self._pagination_counts["unique_listing_event_urls_seen"] = len(set(seen_event_urls) | set(after_urls))
            self._sync_runtime_diagnostics(summary)
            log(
                "info",
                "list_advanced",
                selector="#loadMoreButton",
                events_before=before_count,
                events_after=after_count,
                url_before=before_page_url,
                url_after=page.url,
                new_unique_events=len(new_unique_urls),
            )
            return True
        if wait_result == "timeout":
            if self._advance_list_page_via_href(
                page,
                next_href,
                summary,
                seen_event_urls,
                run_dir=run_dir,
                page_number=page_number,
                reason="wait_timeout",
                before_count=before_count,
                before_urls=before_urls,
                before_page_url=before_page_url,
            ):
                return True
            self._pagination_counts["load_more_timeouts"] += 1
            summary["pagination_stop_reason"] = "load_more_timeout"
            self._sync_runtime_diagnostics(summary)
            self._write_load_more_timeout_diagnostics(
                page,
                summary,
                run_dir,
                before_count=before_count,
                after_count=after_count,
                button_diagnostics=button_diagnostics,
                current_batch_number=page_number,
            )
            if self.config.stop_on_access_degradation:
                self._stop_for_access_degradation("load_more_timeout")
        else:
            self._pagination_counts["load_more_no_new_events"] += 1
            summary["pagination_stop_reason"] = "load_more_no_new_events"
            self._sync_runtime_diagnostics(summary)
        log(
            "warning",
            "list_load_more_stopped",
            selector="#loadMoreButton",
            reason=summary["pagination_stop_reason"],
            events_before=before_count,
            events_after=after_count,
            new_unique_events=len(new_unique_urls),
        )
        return False

    def _click_load_more_button(self, page: Any, button: Any, summary: dict[str, Any]) -> tuple[bool, str | None]:
        popup_wait_ms = max(int(self.config.load_more_popup_wait_ms or 0), 0)
        if popup_wait_ms <= 0 or not hasattr(page, "expect_popup"):
            try:
                button.click(timeout=5000)
                return True, None
            except Exception as exc:
                return False, str(exc)
        try:
            with page.expect_popup(timeout=popup_wait_ms) as popup_info:
                button.click(timeout=5000)
            popup = getattr(popup_info, "value", None)
            if popup is not None:
                self._close_load_more_popup(popup, summary)
            return True, None
        except Exception as exc:
            if self._is_popup_wait_timeout_message(str(exc)):
                return True, None
            return False, str(exc)

    def _close_load_more_popup(self, popup: Any, summary: dict[str, Any]) -> None:
        try:
            popup_url = getattr(popup, "url", None)
        except Exception:
            popup_url = None
        try:
            popup.close()
            self._pagination_counts["load_more_popup_pages_closed"] += 1
            summary.setdefault("selectors_used", []).append("load_more_popup_page_closed")
            summary["popup_dismissals_count"] = int(summary.get("popup_dismissals_count") or 0) + 1
            self._sync_runtime_diagnostics(summary)
            log("info", "load_more_popup_page_closed", url=popup_url)
        except Exception as exc:
            summary.setdefault("warnings", []).append(f"load_more_popup_close_failed:{exc}")
            log("warning", "load_more_popup_page_close_failed", url=popup_url, error=str(exc))

    def _is_popup_wait_timeout_message(self, message: str | None) -> bool:
        lowered = normalize_space(message).lower()
        return (
            "expect_popup" in lowered
            or "waiting for event \"popup\"" in lowered
            or "waiting for event 'popup'" in lowered
        ) and "timeout" in lowered

    def _settle_load_more_after_click(self, page: Any, summary: dict[str, Any], *, before_count: int) -> None:
        rounds = max(int(self.config.load_more_overlay_check_rounds or 0), 1)
        interval_ms = max(int(self.config.load_more_overlay_check_interval_ms or 0), 100)
        initial_wait_ms = max(int(self.config.load_more_post_click_wait_ms or 0), interval_ms)
        for round_index in range(rounds):
            page.wait_for_timeout(initial_wait_ms if round_index == 0 else interval_ms)
            dismissed = self._dismiss_overlays(
                page,
                summary,
                max_rounds=2,
                settle_ms=interval_ms,
                context="load_more_after_click",
            )
            if dismissed:
                self._pagination_counts["load_more_overlay_dismissals"] += int(dismissed)
                summary.setdefault("selectors_used", []).append("load_more_overlay_check")
                self._sync_runtime_diagnostics(summary)
                continue
            if self._event_list_link_count(page) > before_count:
                return

    def _load_more_href(self, button: Any) -> str | None:
        try:
            href = button.get_attribute("href", timeout=1000)
        except TypeError:
            try:
                href = button.get_attribute("href")
            except Exception:
                return None
        except Exception:
            return None
        return normalize_space(href) or None

    def _advance_list_page_via_href(
        self,
        page: Any,
        next_href: str | None,
        summary: dict[str, Any],
        seen_event_urls: set[str],
        *,
        run_dir: Path | None,
        page_number: int | None,
        reason: str,
        before_count: int,
        before_urls: list[str],
        before_page_url: str,
        error: str | None = None,
    ) -> bool:
        if not next_href:
            return False
        next_url = urljoin(FIGHTCENTER_URL, next_href)
        if not next_url:
            return False
        self._pagination_counts["load_more_fallback_attempts"] += 1
        summary.setdefault("selectors_used", []).append("load_more_href_fallback")
        try:
            page.goto(next_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
            self._dismiss_overlays(page, summary, max_rounds=2, context="load_more_href_fallback")
            page.wait_for_selector(FIGHTCENTER_EVENTS_SELECTOR, timeout=10_000)
        except Exception as exc:
            self._pagination_counts["load_more_fallback_failures"] += 1
            self._sync_runtime_diagnostics(summary)
            self._write_load_more_fallback_diagnostic(
                page,
                summary,
                run_dir,
                next_url=next_url,
                reason=reason,
                outcome="navigation_failed",
                before_count=before_count,
                after_count=self._event_list_link_count(page),
                before_page_url=before_page_url,
                current_batch_number=page_number,
                error=error or str(exc),
            )
            log("warning", "list_load_more_href_fallback_failed", url=next_url, reason=reason, error=str(exc))
            return False
        after_urls = self._event_list_urls(page)
        after_count = self._event_list_link_count(page)
        new_unique_urls = set(after_urls) - set(seen_event_urls)
        if not new_unique_urls:
            self._pagination_counts["load_more_fallback_failures"] += 1
            self._sync_runtime_diagnostics(summary)
            self._write_load_more_fallback_diagnostic(
                page,
                summary,
                run_dir,
                next_url=next_url,
                reason=reason,
                outcome="no_new_unique_events",
                before_count=before_count,
                after_count=after_count,
                before_page_url=before_page_url,
                current_batch_number=page_number,
                error=error,
            )
            log(
                "warning",
                "list_load_more_href_fallback_no_new_events",
                url=next_url,
                reason=reason,
                events_before=before_count,
                events_after=after_count,
                new_unique_events=0,
            )
            return False
        self._pagination_counts["load_more_fallback_successes"] += 1
        self._pagination_counts["load_more_successes"] += 1
        self._pagination_counts["unique_listing_event_urls_seen"] = len(set(seen_event_urls) | set(after_urls))
        self._sync_runtime_diagnostics(summary)
        self._write_load_more_fallback_diagnostic(
            page,
            summary,
            run_dir,
            next_url=next_url,
            reason=reason,
            outcome="success",
            before_count=before_count,
            after_count=after_count,
            before_page_url=before_page_url,
            current_batch_number=page_number,
            new_unique_events=len(new_unique_urls),
            error=error,
        )
        log(
            "info",
            "list_load_more_href_fallback_advanced",
            url=next_url,
            reason=reason,
            events_before=before_count,
            events_after=after_count,
            url_before=before_page_url,
            url_after=page.url,
            new_unique_events=len(new_unique_urls),
        )
        return True

    def _select_load_more_button(self, page: Any) -> tuple[Any | None, dict[str, Any]]:
        diagnostics: dict[str, Any] = {
            "selector": "#loadMoreButton",
            "found": False,
            "visible": False,
            "enabled": False,
            "candidate_count": 0,
        }
        try:
            locator = page.locator("#loadMoreButton")
            count = int(locator.count() or 0)
            diagnostics["found"] = count > 0
            diagnostics["candidate_count"] = count
            if count <= 0:
                return None, diagnostics
            try:
                candidates = locator.evaluate_all(
                    """
                    (buttons) => buttons.map((button, index) => {
                      const rect = button.getBoundingClientRect();
                      const style = window.getComputedStyle(button);
                      const visible = !!(
                        rect.width > 0 &&
                        rect.height > 0 &&
                        style.visibility !== 'hidden' &&
                        style.display !== 'none'
                      );
                      const ariaDisabled = (button.getAttribute('aria-disabled') || '').toLowerCase() === 'true';
                      const disabled = !!button.disabled || ariaDisabled || button.classList.contains('disabled');
                      return { index, visible, enabled: !disabled, bottom: rect.bottom + window.scrollY };
                    }).filter((item) => item.visible && item.enabled).sort((a, b) => b.bottom - a.bottom)
                    """
                )
                if candidates:
                    diagnostics["visible"] = True
                    diagnostics["enabled"] = True
                    diagnostics["selected_index"] = int(candidates[0]["index"])
                    return locator.nth(diagnostics["selected_index"]), diagnostics
            except Exception:
                pass
            for index in range(count):
                candidate = locator.nth(index) if hasattr(locator, "nth") else locator.first
                visible = bool(candidate.is_visible(timeout=500))
                enabled = bool(candidate.is_enabled(timeout=500)) if hasattr(candidate, "is_enabled") else visible
                diagnostics["visible"] = diagnostics["visible"] or visible
                diagnostics["enabled"] = diagnostics["enabled"] or enabled
                if visible and enabled:
                    diagnostics["selected_index"] = index
                    return candidate, diagnostics
        except Exception as exc:
            diagnostics["error"] = str(exc)
        return None, diagnostics

    def _write_load_more_timeout_diagnostics(
        self,
        page: Any,
        summary: dict[str, Any],
        run_dir: Path | None,
        *,
        before_count: int,
        after_count: int,
        button_diagnostics: dict[str, Any],
        current_batch_number: int | None,
    ) -> None:
        artifact_paths: dict[str, str] = {}
        if run_dir is not None and self.config.debug:
            try:
                artifact_paths = save_debug_artifacts(page, run_dir, "fightcenter_load_more_timeout")
            except Exception:
                artifact_paths = {}
        diagnostic = {
            "kind": "fightcenter_load_more_timeout",
            "page_url": getattr(page, "url", None),
            "page_title": self._safe_page_title(page),
            "html_path": artifact_paths.get("html_path"),
            "screenshot_path": artifact_paths.get("screenshot_path"),
            "load_more_button_found": bool(button_diagnostics.get("found")),
            "load_more_button_visible": bool(button_diagnostics.get("visible")),
            "load_more_button_enabled": bool(button_diagnostics.get("enabled")),
            "event_url_count_before_click": before_count,
            "event_url_count_after_timeout": after_count,
            "unique_event_urls_seen": int(summary.get("unique_listing_event_urls_seen") or 0),
            "duplicate_event_urls_seen": int(summary.get("duplicate_listing_event_urls_seen") or 0),
            "browser_engine": self.config.browser_engine,
            "max_pages": self.config.max_pages,
            "load_more_wait_timeout_ms": self.config.load_more_wait_timeout_ms,
            "current_page_batch_number": current_batch_number,
        }
        summary.setdefault("diagnostics", []).append(diagnostic)
        self._debug_append("diagnostics", diagnostic)

    def _write_load_more_fallback_diagnostic(
        self,
        page: Any,
        summary: dict[str, Any],
        run_dir: Path | None,
        *,
        next_url: str,
        reason: str,
        outcome: str,
        before_count: int,
        after_count: int,
        before_page_url: str,
        current_batch_number: int | None,
        new_unique_events: int = 0,
        error: str | None = None,
    ) -> None:
        artifact_paths: dict[str, str] = {}
        if run_dir is not None and self.config.debug and outcome != "success":
            try:
                artifact_paths = save_debug_artifacts(page, run_dir, f"fightcenter_load_more_fallback_{outcome}")
            except Exception:
                artifact_paths = {}
        diagnostic = {
            "kind": "fightcenter_load_more_href_fallback",
            "reason": reason,
            "outcome": outcome,
            "next_url": next_url,
            "page_url_before": before_page_url,
            "page_url_after": getattr(page, "url", None),
            "page_title": self._safe_page_title(page),
            "html_path": artifact_paths.get("html_path"),
            "screenshot_path": artifact_paths.get("screenshot_path"),
            "event_url_count_before": before_count,
            "event_url_count_after": after_count,
            "new_unique_events": new_unique_events,
            "browser_engine": self.config.browser_engine,
            "max_pages": self.config.max_pages,
            "load_more_wait_timeout_ms": self.config.load_more_wait_timeout_ms,
            "current_page_batch_number": current_batch_number,
        }
        if error:
            diagnostic["error"] = error
        summary.setdefault("diagnostics", []).append(diagnostic)
        self._debug_append("diagnostics", diagnostic)

    def _safe_page_title(self, page: Any) -> str | None:
        try:
            return page.title()
        except Exception:
            return None

    def _event_list_link_count(self, page: Any) -> int:
        try:
            return page.locator(f"{FIGHTCENTER_EVENTS_SELECTOR} {EVENT_LINK_SELECTOR}").count()
        except Exception:
            return 0

    def _event_list_urls(self, page: Any) -> list[str]:
        try:
            return page.locator(f"{FIGHTCENTER_EVENTS_SELECTOR} {EVENT_LINK_SELECTOR}").evaluate_all(
                "(links) => links.map((link) => link.href).filter(Boolean)"
            )
        except Exception:
            return []

    def _wait_for_more_events(
        self,
        page: Any,
        before_count: int,
        before_urls: list[str],
        before_page_url: str,
    ) -> str:
        try:
            page.wait_for_function(
                """({ rootSelector, eventSelector, beforeCount, beforeUrls, beforePageUrl }) => {
                  const root = document.querySelector(rootSelector);
                  if (!root) return false;
                  const links = Array.from(root.querySelectorAll(eventSelector)).map((link) => link.href).filter(Boolean);
                  if (links.length > beforeCount) return true;
                  if (window.location.href !== beforePageUrl && links.some((href) => !beforeUrls.includes(href))) return true;
                  return false;
                }""",
                {
                    "rootSelector": FIGHTCENTER_EVENTS_SELECTOR,
                    "eventSelector": EVENT_LINK_SELECTOR,
                    "beforeCount": before_count,
                    "beforeUrls": before_urls,
                    "beforePageUrl": before_page_url,
                },
                timeout=max(int(self.config.load_more_wait_timeout_ms or 0), 1),
            )
            return "success"
        except Exception:
            after_urls = self._event_list_urls(page)
            if self._event_list_link_count(page) > before_count or (
                page.url != before_page_url and bool(set(after_urls) - set(before_urls))
            ):
                return "success"
            return "timeout"

    def _scrape_event_page(
        self,
        context: Any,
        list_item: dict[str, Any],
        kind: str,
        run_dir: Path,
        summary: dict[str, Any],
    ) -> dict[str, Any]:
        event_url = list_item.get("event_url")
        page = context.new_page()
        try:
            log("info", "event_open", kind=kind, event_url=event_url)
            page.goto(event_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
            self._dismiss_overlays(page, summary, max_rounds=3, context="event_page")
            self._raise_if_blocked(page, run_dir, f"event_{list_item.get('source_event_id') or url_hash(event_url)}")
            scraped_at = utc_now()
            raw_page_text = normalize_space(page.locator("body").inner_text(timeout=5000))
            status_text = self._extract_event_status_text(page)
            event_name = self._first_visible_text(page, ["h1", "h2"]) or list_item.get("event_name")
            date_raw = self._extract_date_raw(raw_page_text) or list_item.get("event_date_raw")
            event_time_raw = self._extract_event_time(raw_page_text)
            event_timezone_raw = self._extract_timezone(raw_page_text)
            location_raw = self._extract_location(raw_page_text) or list_item.get("location")
            location_parts = parse_location_parts(location_raw)
            event = {
                "source_event_id": source_event_id_from_url(event_url),
                "source_event_numeric_id": None,
                "event_url": event_url,
                "event_name": normalize_space(event_name),
                "promotion_name": self._extract_event_promotion(page, raw_page_text) or list_item.get("promotion_name"),
                "promotion_url": self._extract_promotion_url(page),
                "source_promotion_id": None,
                "source_promotion_numeric_id": None,
                "event_date": parse_date_to_iso(date_raw),
                "source_event_status": "unknown",
                "source_bouts_total": self._extract_source_bouts_total(raw_page_text),
                "source_mma_bouts_total": self._extract_source_bouts_total(raw_page_text),
                "bouts_included": 0,
                "bouts_skipped_total": 0,
                "bouts_skipped_no_result": 0,
                "needs_recheck": False,
                "skipped_bouts": [],
                "event_datetime_raw": self._extract_event_datetime_raw(raw_page_text),
                "event_time_raw": event_time_raw,
                "event_timezone_raw": event_timezone_raw,
                "timezone_raw": event_timezone_raw,
                "location": location_raw,
                "venue": self._extract_venue(raw_page_text),
                "enclosure": self._extract_enclosure(raw_page_text),
                "city": location_parts.get("city"),
                "country": location_parts.get("country"),
                "region": location_parts.get("region"),
                "warnings": [],
                "bouts": [],
                "fighter_profiles": {},
            }
            if self._event_matches_excluded_promotion(event):
                self._scope_coverage(event,"excluded_promotion_ufc",event.get("promotion_name") or event.get("promotion_url"),run_dir)
                event["warnings"].append("event_skipped_ufc")
                summary["warnings"].append(f"event_skipped_ufc:{event_url}")
                log(
                    "info",
                    "event_skipped_excluded_promotion",
                    source_event_id=event.get("source_event_id"),
                    event_url=event.get("event_url"),
                    promotion_name=event.get("promotion_name"),
                )
                self._finalize_event_completeness(event, raw_page_text, kind, status_text)
                populate_event_contract_fields(event)
                return self._prune_none(event)
            non_mma_reason = explicit_non_mma_event_reason(
                event_name=event.get("event_name"),
                promotion_name=event.get("promotion_name"),
                promotion_url=event.get("promotion_url"),
                enclosure=event.get("enclosure"),
                venue=event.get("venue"),
                raw_text=raw_page_text,
            )
            self._debug_append(
                "events",
                {
                    "event_url": event_url,
                    "source_event_id": event["source_event_id"],
                    "title": normalize_space(page.title()),
                    "raw_text_excerpt": raw_page_text[:3000],
                    "event_date_raw": date_raw,
                    "scraped_at": scraped_at,
                },
            )
            card, selector, fallback = self._find_fight_card(page)
            if not card:
                if non_mma_reason and not event.get('source_mma_bouts_total'):
                    warning=f'event_skipped_non_mma:{non_mma_reason}'
                    event['warnings'].append(warning);summary['warnings'].append(f'{warning}:{event_url}')
                    self._scope_coverage(event,'non_mma_metadata',non_mma_reason,run_dir)
                else:
                    proof=assess_event(event,[],[]);event['event_coverage']=proof;self._coverage_entries[event['source_event_id']]=proof
                if (event.get("event_coverage") or {}).get("status")!="excluded":
                    event["warnings"].append("fight_card_missing")
                    summary["warnings"].append(f"fight_card_missing:{event_url}")
                if self.config.debug:
                    save_debug_artifacts(page, run_dir, f"missing_fight_card_{event.get('source_event_id') or url_hash(event_url)}")
                self._finalize_event_completeness(event, raw_page_text, kind, status_text)
                populate_event_contract_fields(event)
                return self._prune_none(event)
            summary["selectors_used"].append(selector)
            if fallback:
                summary["fallback_selectors_used"].append(selector)
            event["bouts"] = self._parse_bouts(card, event, page, context, kind, summary)
            self._finalize_event_completeness(event, raw_page_text, kind, status_text)
            if self._effective_enrich_fighter_profiles():
                self._enrich_event_fighter_profiles(event, context, run_dir, scraped_at, summary)
            populate_event_contract_fields(event)
            return self._prune_none(event)
        finally:
            page.close()

    def _scope_coverage(self,event,reason,evidence,run_dir):
        proof=assess_event(event,[],[])
        proof.update(status='excluded',reasons=[],event_exclusion=reason,exclusion_evidence=str(evidence))
        event['event_coverage']=proof;self._coverage_entries[event['source_event_id']]=proof
        write_json(run_dir/'event_snapshots'/f"{event['source_event_id']}.json",{'parser_version':PARSER_VERSION,'source_url':event['event_url'],'observed_at':proof['observed_at'],'event':event,'rows':[]})

    def _find_fight_card(self, page: Any) -> tuple[Any | None, str | None, bool]:
        for index, selector in enumerate(PREFERRED_FIGHT_CARD_SELECTORS):
            try:
                locator = page.locator(selector)
                if locator.count() and locator.first.is_visible(timeout=800):
                    return locator.first, selector, index > 0
            except Exception:
                continue
        return None, None, False

    def _finalize_event_completeness(
        self,
        event: dict[str, Any],
        raw_page_text: str,
        kind: str,
        status_text: str | None = None,
    ) -> None:
        skipped_bouts = event.setdefault("skipped_bouts", [])
        bouts = event.get("bouts", [])
        event["bouts_included"] = len(bouts)
        event["bouts_skipped_total"] = len(skipped_bouts)
        event["bouts_skipped_no_result"] = sum(1 for item in skipped_bouts if item.get("reason") == "no_result")
        event["source_rows_seen"] = len(bouts) + len(skipped_bouts)
        event["source_mma_rows_seen"] = len(bouts) + sum(
            1 for item in skipped_bouts if item.get("reason") != "non_mma"
        )
        event["source_bouts_total"] = event.get("source_bouts_total") or self._extract_source_bouts_total(raw_page_text)
        event["source_mma_bouts_total"] = event.get("source_mma_bouts_total") or event.get("source_bouts_total")
        self._append_source_mma_total_warning(event)
        event["source_event_status"] = self._infer_source_event_status(event, raw_page_text, kind, status_text)
        event["needs_recheck"] = self._event_needs_recheck(event)

    def _infer_source_event_status(
        self,
        event: dict[str, Any],
        raw_page_text: str,
        kind: str,
        status_text: str | None = None,
    ) -> str:
        marker_text = normalize_space(status_text).lower()
        if re.search(r"\bin\s+progress\s+mma\s+event\b|\bevent\s+status\s*:\s*in\s+progress\b", marker_text):
            return "in_progress"
        if re.search(
            r"\bcancell?ed\s+mma\s+event\b|\bpostponed\s+mma\s+event\b|\bscrapped\s+mma\s+event\b|"
            r"\bevent\s+status\s*:\s*(?:cancell?ed|postponed|scrapped)\b",
            marker_text,
        ):
            return "cancelled"
        if kind == "upcoming" or re.search(
            r"\bupcoming\s+mma\s+event\b|\bscheduled\s+mma\s+event\b|\bevent\s+status\s*:\s*upcoming\b",
            marker_text,
        ):
            return "upcoming"
        if int(event.get("bouts_skipped_no_result") or 0) > 0:
            return "in_progress"
        if event.get("bouts"):
            return "completed"
        return "unknown"

    def _event_needs_recheck(self, event: dict[str, Any]) -> bool:
        if (event.get('event_coverage') or {}).get('status') in {'partial','unavailable'}:
            return True
        if event.get("source_event_status") in {"in_progress", "upcoming"}:
            return True
        if int(event.get("bouts_skipped_no_result") or 0) > 0:
            return True
        total = event.get("source_mma_bouts_total")
        if total is not None and int(event.get("source_mma_rows_seen") or 0) < int(total):
            return True
        return False

    def _append_source_mma_total_warning(self, event: dict[str, Any]) -> None:
        if any(str(warning).startswith("event_skipped_non_mma:") for warning in event.get("warnings", [])):
            return
        total = event.get("source_mma_bouts_total")
        if total is None:
            return
        try:
            total_int = int(total)
        except (TypeError, ValueError):
            return
        seen = int(event.get("source_mma_rows_seen") or 0)
        if total_int == seen:
            return
        warning = f"source_mma_bouts_total_mismatch:{total_int}:{seen}"
        warnings = event.setdefault("warnings", [])
        if warning not in warnings:
            warnings.append(warning)
        skipped_reasons: dict[str, int] = {}
        for skipped in event.get("skipped_bouts", []) or []:
            reason = str(skipped.get("reason") or "unknown")
            skipped_reasons[reason] = skipped_reasons.get(reason, 0) + 1
        event["source_mma_bouts_total_mismatch"] = {
            "source_mma_bouts_total": total_int,
            "source_rows_seen": int(event.get("source_rows_seen") or 0),
            "source_mma_rows_seen": seen,
            "retained_bouts": len(event.get("bouts", []) or []),
            "skipped_bouts_by_reason": dict(sorted(skipped_reasons.items())),
            "non_mma_rows_involved": skipped_reasons.get("non_mma", 0) > 0,
            "amateur_rows_involved": skipped_reasons.get("amateur", 0) > 0,
        }

    def _extract_event_status_text(self, page: Any) -> str | None:
        try:
            return normalize_space(
                page.evaluate(
                    """
                    () => {
                      const selectors = [
                        'h1', 'h2', 'h3', 'header',
                        '.eventStatus', '.event-status', '.status',
                        '[class*="event-status" i]', '[class*="eventStatus" i]',
                        '[class*="status" i]', '[id*="status" i]'
                      ];
                      const texts = [];
                      const seen = new Set();
                      for (const selector of selectors) {
                        for (const el of document.querySelectorAll(selector)) {
                          if (seen.has(el)) continue;
                          seen.add(el);
                          const text = (el.innerText || '').trim();
                          if (text && text.length <= 300) texts.push(text);
                        }
                      }
                      return texts.join(' | ');
                    }
                    """
                )
            )
        except Exception:
            return None

    def _enrich_event_fighter_profiles(
        self,
        event: dict[str, Any],
        context: Any,
        run_dir: Path,
        scraped_at: str,
        summary: dict[str, Any] | None = None,
    ) -> None:
        profiles = event.setdefault("fighter_profiles", {})
        fighter_refs = self._event_fighter_references(event)
        profile_context_counts = self._profile_reference_context_counts(fighter_refs)
        for index, fighter in enumerate(fighter_refs, start=1):
            source_id = fighter.get("source_fighter_id") or source_fighter_id_from_url(fighter.get("url"))
            url = canonical_url(fighter.get("url"))
            if not source_id and not url:
                continue
            try:
                profile = self._fetch_event_fighter_profile(
                    context,
                    url,
                    fallback_name=fighter.get("name"),
                    event_date=event.get("event_date"),
                    fetched_at=scraped_at,
                    run_dir=run_dir,
                    known_results=self._known_profile_results(event, source_id),
                )
            except Exception as exc:
                self._record_failed_profile(
                    summary,
                    event=event,
                    fighter=fighter,
                    profile_url=url,
                    fallback_name=fighter.get("name"),
                    reason=exc,
                )
                raise
            if profile.get("profile_fetched") is False:
                self._record_failed_profile(
                    summary,
                    event=event,
                    fighter=fighter,
                    profile_url=url,
                    fallback_name=fighter.get("name"),
                    reason=";".join(profile.get("profile_warnings") or []) or profile.get("status") or "profile_fetch_failed",
                )
            key = profile.get("source_fighter_id") or source_id or url
            if key:
                profiles[key] = profile
            if index < len(fighter_refs):
                pause_seconds = self._configured_profile_pause_seconds()
                if self.config.profile_pause_seconds is not None:
                    self._pause(
                        "profile",
                        pause_seconds,
                        event_url=event.get("event_url"),
                        profile_index=index,
                        profiles_total=len(fighter_refs),
                    )
                elif pause_seconds > 0:
                    self._sleep(pause_seconds)
        event["profile_enrichment"] = {
            **build_event_profile_enrichment_summary([event]),
            "profile_context_counts": profile_context_counts,
            "partial": self._profile_cap_reached(),
        }
        if summary is not None:
            self._sync_runtime_diagnostics(summary)
        for profile in profiles.values():
            if isinstance(profile, dict):
                profile.pop("_nationality_diagnostics", None)
        self._attach_profile_refs(event)

    def _event_fighter_references(self, event: dict[str, Any]) -> list[dict[str, Any]]:
        refs: list[dict[str, Any]] = []
        refs_by_key: dict[str, dict[str, Any]] = {}

        def add(name: str | None, url: str | None, source_id: str | None, context: str) -> None:
            url = canonical_url(url)
            key = source_id or source_fighter_id_from_url(url) or url
            if not key:
                return
            existing = refs_by_key.get(key)
            if existing:
                existing.setdefault("contexts", set()).add(context)
                if not existing.get("name") and name:
                    existing["name"] = name
                if not existing.get("url") and url:
                    existing["url"] = url
                if not existing.get("source_fighter_id") and source_id:
                    existing["source_fighter_id"] = source_id
                return
            entry = {"name": name, "url": url, "source_fighter_id": source_id, "contexts": {context}}
            refs_by_key[key] = entry
            refs.append(entry)

        for bout in event.get("bouts", []):
            for fighter_key in ("fighter_a", "fighter_b"):
                fighter = bout.get(fighter_key) or {}
                add(fighter.get("name"), fighter.get("url"), fighter.get("source_fighter_id"), "retained_bout")
        for ref in refs:
            ref["contexts"] = sorted(ref.get("contexts") or [])
        return refs

    def _profile_reference_context_counts(self, fighter_refs: list[dict[str, Any]]) -> dict[str, int]:
        counts = {
            "retained_bout_profiles": 0,
            "skipped_mma_profiles": 0,
            "skipped_non_mma_profiles": 0,
        }
        for fighter in fighter_refs:
            contexts = set(fighter.get("contexts") or [])
            if "retained_bout" in contexts:
                counts["retained_bout_profiles"] += 1
            elif "skipped_mma" in contexts:
                counts["skipped_mma_profiles"] += 1
            elif "skipped_non_mma" in contexts:
                counts["skipped_non_mma_profiles"] += 1
        return counts

    @staticmethod
    def _known_profile_results(event: dict, source_id: str | None) -> list[dict]:
        known = []
        for bout in event.get("bouts", []):
            for role in ("fighter_a", "fighter_b"):
                if bout.get(role, {}).get("source_fighter_id") != source_id:
                    continue
                result_type = bout.get("result_type")
                result = ("win" if bout.get("winner") == role else "loss") if result_type == "win_loss" else {"draw": "draw", "no_contest": "no_contest"}.get(result_type)
                if result:
                    known.append({"source_bout_id": bout.get("source_bout_id"), "fight_date": event.get("event_date"), "result": result})
        return known

    def _fetch_event_fighter_profile(
        self,
        context: Any,
        profile_url: str | None,
        *,
        fallback_name: str | None,
        event_date: str | None,
        fetched_at: str,
        run_dir: Path,
        known_results: list[dict] | None = None,
    ) -> dict[str, Any]:
        cache_key = self._profile_cache_key(profile_url)
        if cache_key and cache_key in self._fighter_profile_cache:
            from .scoring_evidence import needs_refresh
            if not known_results or not needs_refresh(self._fighter_profile_cache[cache_key], known_results=known_results):
                return self._fighter_profile_cache[cache_key]
        if not profile_url:
            profile = failed_profile("", "navigation_failed", "profile_fetch_failed:missing_url")
            normalized = normalize_profile_for_event_output(
                profile,
                profile_url=profile_url,
                fallback_name=fallback_name,
                event_date=event_date,
                fetched_at=fetched_at,
            )
            return normalized
        cached = self._cached_event_profile(
            profile_url,
            fallback_name=fallback_name,
            event_date=event_date,
            fetched_at=fetched_at,
            known_results=known_results,
        )
        if cached:
            key = cache_key or cached.get("source_fighter_id") or profile_url
            if key:
                self._fighter_profile_cache[key] = cached
            return cached
        if self._profile_cap_reached():
            self._pause_counts["profile_fetches_before_cap"] = self._profile_fetches_started
            profile = failed_profile(profile_url, "skipped", "profile_fetch_skipped:max_profile_fetches_reached")
            normalized = normalize_profile_for_event_output(
                profile,
                profile_url=profile_url,
                fallback_name=fallback_name,
                event_date=event_date,
                fetched_at=fetched_at,
            )
            key = cache_key or normalized.get("source_fighter_id") or profile_url
            if key:
                self._fighter_profile_cache[key] = normalized
            return normalized
        self._profile_fetches_started += 1
        self._pause_counts["profile_fetches_before_cap"] = self._profile_fetches_started
        profile = self._fetch_event_fighter_profile_with_retries(
            context,
            profile_url,
            fallback_name=fallback_name,
            run_dir=run_dir,
            cache_key=cache_key,
        )
        normalized = normalize_profile_for_event_output(
            profile,
            profile_url=profile_url,
            fallback_name=fallback_name,
            event_date=event_date,
            fetched_at=fetched_at,
        )
        if normalized.get("profile_fetched") is False:
            self._profile_fetch_failures_seen += 1
            if any(self._is_access_failure_message(warning) for warning in normalized.get("profile_warnings") or []):
                self._record_access_failure(",".join(normalized.get("profile_warnings") or []))
        else:
            self._record_access_success()
        key = cache_key or normalized.get("source_fighter_id") or profile_url
        if key:
            self._fighter_profile_cache[key] = normalized
        self._check_profile_failure_threshold()
        batch_size = self.config.profile_batch_size
        if (
            batch_size
            and self.config.profile_batch_pause_seconds > 0
            and self._profile_fetches_started % batch_size == 0
        ):
            self._pause(
                "profile_batch",
                self.config.profile_batch_pause_seconds,
                profile_fetches=self._profile_fetches_started,
                batch_size=batch_size,
            )
        return normalized

    def _fetch_event_fighter_profile_with_retries(
        self,
        context: Any,
        profile_url: str,
        *,
        fallback_name: str | None,
        run_dir: Path,
        cache_key: str | None,
    ) -> dict[str, Any]:
        attempts = 2
        last_profile: dict[str, Any] | None = None
        for attempt in range(1, attempts + 1):
            profile, retryable = self._fetch_event_fighter_profile_once(
                context,
                profile_url,
                fallback_name=fallback_name,
                run_dir=run_dir,
                cache_key=cache_key,
                attempt=attempt,
                max_attempts=attempts,
            )
            last_profile = profile
            if profile.get("status") == "success" or not retryable or attempt >= attempts:
                return profile
            self._sleep(max(self.config.conservative_delay_ms, self.config.event_delay_ms) / 1000)
        return last_profile or failed_profile(profile_url, "navigation_failed", "profile_fetch_failed:navigation_failed")

    def _fetch_event_fighter_profile_once(
        self,
        context: Any,
        profile_url: str,
        *,
        fallback_name: str | None,
        run_dir: Path,
        cache_key: str | None,
        attempt: int,
        max_attempts: int,
    ) -> tuple[dict[str, Any], bool]:
        page = context.new_page()
        diagnostic_name = f"fighter_profile_{cache_key or url_hash(profile_url)}"
        diagnostic: dict[str, Any] = {
            "type": "fighter_profile_fetch",
            "profile_url": profile_url,
            "fallback_name": fallback_name,
            "source_fighter_id": cache_key if cache_key and cache_key.startswith("tapology_fighter_") else source_fighter_id_from_url(profile_url),
            "attempt": attempt,
            "max_attempts": max_attempts,
            "session_path": "shared_event_browser_context_new_page",
        }
        started = time.monotonic()
        try:
            response = page.goto(profile_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
            diagnostic["elapsed_seconds"] = round(time.monotonic() - started, 3)
            diagnostic["current_url"] = canonical_url(page.url)
            try:
                diagnostic["http_status"] = response.status if response else None
            except Exception:
                diagnostic["http_status"] = None
            self._dismiss_overlays(page, self._active_summary or {}, max_rounds=3, context="profile_page")
            block_reason = detect_hard_block(page)
            if (
                block_reason
                and not self.config.headless
                and self.config.wait_for_manual_challenge_seconds > 0
                and is_manual_solvable_challenge(block_reason)
            ):
                remaining = wait_for_manual_challenge(
                    page,
                    timeout_seconds=self.config.wait_for_manual_challenge_seconds,
                    poll_interval_ms=self.config.manual_challenge_poll_interval_ms,
                    log=log,
                    initial_reason=block_reason,
                    context_name=profile_url,
                )
                block_reason = remaining
            if block_reason:
                diagnostic.update({"outcome": "failed", "reason": "profile_fetch_failed:blocked_or_challenge", "block_reason": block_reason})
                if self.config.debug:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_blocked_attempt_{attempt}")
                profile = failed_profile(
                    profile_url,
                    "blocked",
                    "profile_fetch_failed:blocked_or_challenge",
                    page,
                    diagnostics=diagnostic,
                )
                return profile, True
            status = diagnostic.get("http_status")
            if isinstance(status, int) and status >= 400:
                reason = "profile_fetch_failed:http_error"
                diagnostic.update({"outcome": "failed", "reason": reason})
                if self.config.debug:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_http_{status}_attempt_{attempt}")
                return failed_profile(profile_url, "http_error", reason, page, diagnostics=diagnostic), status in {408, 429, 500, 502, 503, 504}
            snapshot = snapshot_profile_page(page)
            body_text = normalize_space(snapshot.get("body_text"))
            diagnostic.update(
                {
                    "body_text_length": len(body_text),
                    "page_title": normalize_space(snapshot.get("title")),
                    "landed_url": canonical_url(snapshot.get("url")) or snapshot.get("url"),
                }
            )
            if not body_text:
                reason = "profile_fetch_failed:empty_html"
                diagnostic.update({"outcome": "failed", "reason": reason})
                if self.config.debug:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_empty_html_attempt_{attempt}")
                return failed_profile(profile_url, "empty_html", reason, page, diagnostics=diagnostic), True
            profile = parse_profile_snapshot(snapshot, profile_url)
            if profile.get("status") != "success":
                reason = "profile_parse_empty_required_sections" if profile.get("error_reason") == "profile_summary_not_found" else "profile_parse_failed"
                profile.setdefault("warnings", []).append(reason)
                profile["warnings"] = sorted(set(profile.get("warnings") or []))
                diagnostic.update({"outcome": "failed", "reason": reason})
                profile["diagnostics"] = {**(profile.get("diagnostics") or {}), **diagnostic}
                if self.config.debug:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_failed_parse_attempt_{attempt}")
                return profile, False
            diagnostic["outcome"] = "success"
            profile["diagnostics"] = {**(profile.get("diagnostics") or {}), **diagnostic}
            return profile, False
        except Exception as exc:
            reason = self._profile_fetch_exception_reason(exc)
            diagnostic.update({"outcome": "failed", "reason": reason, "exception": str(exc)[:500]})
            log("warning", "fighter_profile_event_enrichment_failed", profile_url=profile_url, attempt=attempt, reason=reason, error=str(exc))
            if self.config.debug:
                try:
                    save_debug_artifacts(page, run_dir, f"{diagnostic_name}_{reason.rsplit(':', 1)[-1]}_attempt_{attempt}")
                except Exception:
                    pass
            return failed_profile(profile_url, reason.rsplit(":", 1)[-1], reason, page, diagnostics=diagnostic), True
        finally:
            self._debug_append("diagnostics", diagnostic)
            page.close()

    def _profile_fetch_exception_reason(self, exc: Exception) -> str:
        message = str(exc).lower()
        if "timeout" in message:
            return "profile_fetch_failed:timeout"
        if "net::" in message or "navigation" in message or "err_" in message:
            return "profile_fetch_failed:navigation_failed"
        return "profile_fetch_failed:navigation_failed"

    def _attach_profile_refs(self, event: dict[str, Any]) -> None:
        profiles = event.get("fighter_profiles") or {}
        for bout in event.get("bouts", []):
            for fighter_key in ("fighter_a", "fighter_b"):
                fighter = bout.get(fighter_key)
                if not isinstance(fighter, dict):
                    continue
                ref = fighter.get("source_fighter_id") or source_fighter_id_from_url(fighter.get("url"))
                fighter["profile_ref"] = ref
                fighter["profile_summary"] = event_profile_summary(profiles.get(ref)) if ref else event_profile_summary(None)

    def _parse_bouts(
        self,
        card: Any,
        event: dict[str, Any],
        page: Any,
        context: Any,
        kind: str,
        summary: dict[str, Any],
    ) -> list[dict[str, Any]]:
        rows = card.evaluate(ROWS_JS, {"bout": BOUT_LINK_SELECTOR, "fighter": FIGHTER_LINK_SELECTOR})
        if self._active_run_dir:
            sid=event.get('source_event_id') or source_event_id_from_url(event.get('event_url'))
            if sid:
                write_json(self._active_run_dir/'event_snapshots'/f'{sid}.json', {'parser_version':PARSER_VERSION,'source_url':page.url,'observed_at':utc_now(),'event':{k:event.get(k) for k in ('source_event_id','event_name','event_date','source_mma_bouts_total')},'rows':rows})
        bouts: list[dict[str, Any]] = []
        seen_row_keys: set[str] = set()
        skipped_no_result = 0
        skipped_amateur = 0
        skipped_unknown_pro_status = 0
        skipped_non_mma = 0
        for row in rows:
            try:
                row_bout_url = absolute_url(page.url, row.get("bout_href"))
                row_key = source_bout_id_from_url(row_bout_url) or url_hash(row_bout_url)
                if row_key and row_key in seen_row_keys:
                    continue
                if row_key:
                    seen_row_keys.add(row_key)
                division, sport = row_classification(row)
                if sport=='non_mma':
                    if row_key:
                        seen_row_keys.add(row_key)
                    skipped_non_mma += 1
                    event.setdefault("skipped_bouts", []).append(self._skipped_bout_record(row, page.url, "non_mma"))
                    log(
                        "info",
                        "bout_skipped_non_mma",
                        event_url=event.get("event_url"),
                        raw_text=normalize_space(row.get("raw_text"))[:160],
                    )
                    continue
                pro_status = division
                if re.search(r'\bcancell?ed\b|\bscrapped\b',str(row.get('method_raw') or row.get('raw_text') or ''),re.I):
                    event.setdefault('skipped_bouts',[]).append(self._skipped_bout_record(row,page.url,'cancelled'))
                    continue
                if pro_status == "amateur":
                    if row_key:
                        seen_row_keys.add(row_key)
                    skipped_amateur += 1
                    event.setdefault("skipped_bouts", []).append(self._skipped_bout_record(row, page.url, "amateur"))
                    log(
                        "info",
                        "bout_skipped_amateur",
                        event_url=event.get("event_url"),
                        raw_text=normalize_space(row.get("raw_text"))[:160],
                    )
                    continue
                if pro_status == 'junior':
                    event.setdefault('skipped_bouts', []).append(self._skipped_bout_record(row, page.url, 'junior'))
                    continue
                if pro_status != "pro" or (row.get('source_row_version') and sport!='mma'):
                    skipped_unknown_pro_status += 1
                    event.setdefault("skipped_bouts", []).append(self._skipped_bout_record(row, page.url, "unknown"))
                    log(
                        "info",
                        "bout_skipped_unknown_professional_status",
                        event_url=event.get("event_url"),
                        raw_text=normalize_space(row.get("raw_text"))[:160],
                    )
                    continue
                if kind == "results" and not row_completed_result(row):
                    if row_key:
                        seen_row_keys.add(row_key)
                    skipped_no_result += 1
                    event.setdefault("skipped_bouts", []).append(self._skipped_bout_record(row, page.url, "no_result"))
                    log(
                        "info",
                        "bout_skipped_no_result",
                        event_url=event.get("event_url"),
                        raw_text=normalize_space(row.get("raw_text"))[:160],
                    )
                    continue
                bout = self._normalize_bout_row(row, event, page.url, kind)
                row_key = bout.get("source_bout_id") or bout.get("bout_url") or row_key
                if row_key:
                    seen_row_keys.add(row_key)
                if bout.get("bout_url") and self.config.scrape_matchup_pages:
                    details = self._scrape_matchup_page(context, bout["bout_url"], summary)
                    if details:
                        self._apply_matchup_details(bout, details)
                else:
                    reason = "calendar_mode" if bout.get("bout_url") else "no_bout_url"
                    log("info", "matchup_page_skipped", reason=reason, event_url=event.get("event_url"))
                if kind=='results' and bout.get('result_type') not in {'win_loss','draw','no_contest'}:
                    event.setdefault('skipped_bouts',[]).append(self._skipped_bout_record(row,page.url,'no_result'))
                    skipped_no_result+=1
                    continue
                populate_bout_contract_fields(bout, event)
                bouts.append(bout)
                log("info", "bout_parsed", source_bout_id=bout.get("source_bout_id"), event_url=event.get("event_url"))
            except FatalScrapeError:
                raise
            except Exception as exc:
                self._record_failed_bout(
                    summary,
                    bout_url=absolute_url(page.url, row.get("bout_href")),
                    event_url=event.get("event_url"),
                    row=row,
                    reason=exc,
                )
                event.setdefault("warnings", []).append(f"bout_failed:{exc}")
                event.setdefault("skipped_bouts", []).append(self._skipped_bout_record(row, page.url, "unknown"))
                log("error", "bout_failed", error=str(exc), raw_text=normalize_space(row.get("raw_text"))[:160])
        if skipped_no_result:
            event.setdefault("warnings", []).append(f"bouts_skipped_no_result:{skipped_no_result}")
            summary["warnings"].append(f"bouts_skipped_no_result:{event.get('event_url')}:{skipped_no_result}")
        if skipped_amateur:
            event.setdefault("warnings", []).append(f"bouts_skipped_amateur:{skipped_amateur}")
            summary["warnings"].append(f"bouts_skipped_amateur:{event.get('event_url')}:{skipped_amateur}")
        if skipped_unknown_pro_status:
            event.setdefault("warnings", []).append(f"bouts_skipped_unknown_professional_status:{skipped_unknown_pro_status}")
            summary["warnings"].append(
                f"bouts_skipped_unknown_professional_status:{event.get('event_url')}:{skipped_unknown_pro_status}"
            )
        if skipped_non_mma:
            event.setdefault("warnings", []).append(f"bouts_skipped_non_mma:{skipped_non_mma}")
            summary["warnings"].append(f"bouts_skipped_non_mma:{event.get('event_url')}:{skipped_non_mma}")
        accepted={b.get('bout_url'):b for b in bouts}
        skipped={s.get('bout_url'):s for s in event.get('skipped_bouts',[])}
        decisions=[];seen=set()
        for row in rows:
            url=absolute_url(page.url,row.get('bout_href'));sid=source_bout_id_from_url(url)
            key=sid or url_hash(str(row))
            if key in seen:continue
            seen.add(key);division,sport=row_classification(row);reason=(skipped.get(url) or {}).get('reason')
            decision='accepted' if url in accepted else ('excluded' if reason in {'amateur','junior','non_mma','cancelled'} else 'unresolved')
            decisions.append({'source_bout_id':(accepted.get(url) or {}).get('source_bout_id') or sid or 'unresolved_'+key,'sport':sport,'division':division,'decision':decision,'reason':reason if decision!='accepted' else None,'classification_evidence':row.get('classification_evidence') or 'legacy_row_label'})
        coverage=assess_event(event,rows,decisions)
        event['event_coverage']=coverage
        sid=event.get('source_event_id') or source_event_id_from_url(event.get('event_url'))
        if sid:self._coverage_entries[sid]=coverage
        confirmed=sum(row_classification(r)==('pro','mma') and row_completed_result(r) for r in rows)
        if kind=='results' and confirmed and not bouts:
            raise FatalScrapeError('event_parser_zero_professional_results:'+str(sid))
        return bouts

    def _skipped_bout_record(self, row: dict[str, Any], source_url: str, reason: str) -> dict[str, Any]:
        raw_text = normalize_space(row.get("raw_text"))
        bout_url = absolute_url(source_url, row.get("bout_href"))
        source_bout_id = source_bout_id_from_url(bout_url)
        fighters = row.get("fighter_links") or []
        fighter_a = fighters[0] if len(fighters) > 0 else {}
        fighter_b = fighters[1] if len(fighters) > 1 else {}
        fighter_a_url = absolute_url(source_url, fighter_a.get("href"))
        fighter_b_url = absolute_url(source_url, fighter_b.get("href"))
        fighter_a_source_id = source_fighter_id_from_url(fighter_a_url)
        fighter_b_source_id = source_fighter_id_from_url(fighter_b_url)
        return {
            "reason": reason if reason in {"no_result", "amateur", "junior", "cancelled", "overturned", "non_mma", "missing_required_data", "unknown"} else "unknown",
            "source_bout_id": source_bout_id,
            "source_bout_numeric_id": source_bout_numeric_id(bout_url, source_bout_id),
            "bout_url": bout_url,
            "fighter_a_name": normalize_space(fighter_a.get("name")),
            "fighter_a_url": fighter_a_url,
            "fighter_a_source_fighter_id": fighter_a_source_id,
            "fighter_a_source_fighter_numeric_id": source_fighter_numeric_id(fighter_a_url, fighter_a_source_id),
            "fighter_b_name": normalize_space(fighter_b.get("name")),
            "fighter_b_url": fighter_b_url,
            "fighter_b_source_fighter_id": fighter_b_source_id,
            "fighter_b_source_fighter_numeric_id": source_fighter_numeric_id(fighter_b_url, fighter_b_source_id),
            "card_position": self._extract_card_position(raw_text),
            "weight_lbs": self._extract_weight_lbs(raw_text),
            "weight_class": self._extract_weight_class(raw_text),
        }

    def _normalize_bout_row(
        self,
        row: dict[str, Any],
        event: dict[str, Any],
        source_url: str,
        kind: str,
    ) -> dict[str, Any]:
        raw_text = normalize_space(row.get("raw_text"))
        bout_url = absolute_url(source_url, row.get("bout_href"))
        fighters = row.get("fighter_links") or []
        fighter_a = fighters[0] if len(fighters) > 0 else {"name": None, "href": None}
        fighter_b = fighters[1] if len(fighters) > 1 else {"name": None, "href": None}
        records = [] if row.get('records_are_postfight') or re.search(r'\b(?:up to|down to|climbed to|fell to)\b',raw_text,re.I) else self._extract_records(raw_text)
        explicit_records=row.get('prefight_records') or {}
        if explicit_records:records=[explicit_records.get('a'),explicit_records.get('b')]
        record_a = parse_prefight_record(records[0] if len(records) > 0 else None)
        record_b = parse_prefight_record(records[1] if len(records) > 1 else None)
        method_raw = row.get('method_raw') or self._extract_method_raw(raw_text)
        method = normalize_method(method_raw)
        warnings = method.pop("warnings", [])
        warnings += prefight_record_warnings(record_a) + prefight_record_warnings(record_b)
        winner_name, loser_name = self._infer_winner_loser(raw_text, fighter_a.get("name"), fighter_b.get("name"))
        winner, loser = self._winner_loser_roles(winner_name, loser_name, fighter_a.get("name"), fighter_b.get("name"))
        badges=row.get('result_badges') or []
        if badges==['W','L']:winner,loser='fighter_a','fighter_b'
        elif badges==['L','W']:winner,loser='fighter_b','fighter_a'
        result_type = self._result_type(method.get("method_category"), winner, loser, raw_text)
        if badges in [['N','N'],['NC','NC']]:result_type='no_contest';winner=loser=None;method['method_category']='no_contest'
        elif badges==['D','D']:result_type='draw';winner=loser=None;method['method_category']='draw'
        event_date = event.get("event_date")
        fighter_a_url = absolute_url(source_url, fighter_a.get("href"))
        fighter_b_url = absolute_url(source_url, fighter_b.get("href"))
        fighter_a_source_id = source_fighter_id_from_url(fighter_a_url)
        fighter_b_source_id = source_fighter_id_from_url(fighter_b_url)
        if not fighter_a_source_id and not fighter_a_url:
            warnings.append("missing_fighter_a_identity")
        if not fighter_b_source_id and not fighter_b_url:
            warnings.append("missing_fighter_b_identity")
        source_bout_id = source_bout_id_from_url(bout_url) or fallback_bout_id(
            event_url=event.get("event_url"),
            event_date=event_date,
            fighter_a_name=fighter_a.get("name"),
            fighter_b_name=fighter_b.get("name"),
            weight_class=self._extract_weight_class(raw_text),
            result_or_method=method_raw,
        )
        clock=row.get('result_clock') or raw_text
        result_round = parse_round(clock)
        result_time = parse_time(clock)
        billing=row.get('billing_text') or raw_text
        bout = {
            "source_bout_id": source_bout_id,
            "source_bout_numeric_id": None,
            "bout_url": bout_url,
            "status": "upcoming" if kind == "upcoming" else "completed",
            "pro_am": "professional",
            "card_position": self._extract_card_position(billing),
            "weight_class": self._extract_weight_class(billing),
            "weight_lbs": self._extract_weight_lbs(billing),
            "title_fight": self._extract_title_fight(raw_text),
            "winner": winner,
            "loser": loser,
            "result_type": result_type,
            "fighter_a": {
                "name": normalize_space(fighter_a.get("name")),
                "url": fighter_a_url,
                "source_fighter_id": fighter_a_source_id,
                "source_fighter_numeric_id": None,
                "prefight_record": record_a,
            },
            "fighter_b": {
                "name": normalize_space(fighter_b.get("name")),
                "url": fighter_b_url,
                "source_fighter_id": fighter_b_source_id,
                "source_fighter_numeric_id": None,
                "prefight_record": record_b,
            },
                "result": {
                    **method,
                    "round": result_round,
                    "time": result_time,
                    "time_semantics": result_time_semantics(method.get("method_category"), result_round, result_time, clock),
                },
                "warnings": sorted(set(warnings)),
            }
        self._debug_append(
            "bouts",
            {
                "source_bout_id": source_bout_id,
                "bout_url": bout_url,
                "event_url": event.get("event_url"),
                "raw_text": raw_text,
                "method_raw": method_raw,
                "result_raw": self._extract_result_raw(raw_text),
                "row_prefight_record_candidates": records,
                "diagnostics": sorted(set(warnings)),
            },
        )
        return self._prune_none(bout)

    def _scrape_matchup_page(self, context: Any, bout_url: str, summary: dict[str, Any]) -> dict[str, Any] | None:
        page = context.new_page()
        try:
            log("info", "matchup_page_open", bout_url=bout_url)
            page.goto(bout_url, wait_until="domcontentloaded", timeout=self.config.page_timeout_ms)
            self._dismiss_overlays(page, summary, max_rounds=3, context="matchup_page")
            self._raise_if_blocked(page, None, f"matchup_{url_hash(bout_url)}")
            body = normalize_space(page.locator("body").inner_text(timeout=5000))
            details = self._parse_matchup_page_details(body)
            debug_payload = {
                "source_url": bout_url,
                "title": normalize_space(page.title()),
                "raw_text_excerpt": body[:3000],
                "scraped_at": utc_now(),
            }
            debug_payload.update({"parsed": details})
            self._debug_append("bouts", debug_payload)
            return details
        except FatalScrapeError:
            raise
        except Exception as exc:
            self._record_failed_bout(summary, bout_url=bout_url, reason=exc)
            summary["warnings"].append(f"matchup_page_failed:{bout_url}:{exc}")
            log("warning", "matchup_page_failed", bout_url=bout_url, error=str(exc))
            return None
        finally:
            page.close()

    def _parse_matchup_page_details(self, text: str) -> dict[str, Any]:
        prefight_records = self._extract_prefight_records_from_matchup(text)
        title_info = self._extract_matchup_title_info(text)
        result_segment = self._extract_matchup_result_segment(text) or text
        method_raw = self._extract_matchup_method_raw(result_segment)
        method = normalize_method(method_raw)
        warnings = method.pop("warnings", [])
        result_round = parse_round(result_segment)
        result_time = parse_time(result_segment)
        return self._prune_none(
            {
                "fighter_a_prefight_record": parse_prefight_record(prefight_records[0]) if len(prefight_records) > 0 else None,
                "fighter_b_prefight_record": parse_prefight_record(prefight_records[1]) if len(prefight_records) > 1 else None,
                "result": {
                    **method,
                    "round": result_round,
                    "time": result_time,
                    "time_semantics": result_time_semantics(method.get("method_category"), result_round, result_time, result_segment),
                },
                "card_position": self._extract_card_position(title_info or text),
                "weight_class": self._extract_weight_class(title_info or text),
                "weight_lbs": self._extract_weight_lbs(title_info or text),
                "title_fight": self._extract_title_fight(title_info or text),
                "warnings": warnings,
            }
        )

    def _apply_matchup_details(self, bout: dict[str, Any], details: dict[str, Any]) -> None:
        if details.get("fighter_a_prefight_record"):
            bout["fighter_a"]["prefight_record"] = details["fighter_a_prefight_record"]
        if details.get("fighter_b_prefight_record"):
            bout["fighter_b"]["prefight_record"] = details["fighter_b_prefight_record"]
        result_details = details.get("result") or {}
        for key in ["method_category", "method_detail", "round", "time", "time_semantics"]:
            if key in result_details and result_details[key] not in (None,'','unknown'):
                # Optional detail pages must not erase the explicit card result.
                if key == 'method_category' and bout.get('result_type') == 'no_contest' and result_details[key] != 'no_contest':
                    continue
                bout.setdefault("result", {})[key] = result_details[key]
        for key in ["card_position", "weight_class", "weight_lbs", "title_fight"]:
            if details.get(key) is not None:
                bout[key] = details[key]
        warnings = sorted(set((bout.get("warnings") or []) + (details.get("warnings") or [])))
        if warnings:
            bout["warnings"] = warnings
        elif "warnings" in bout:
            del bout["warnings"]

    def _extract_prefight_records_from_matchup(self, text: str) -> list[str]:
        match = re.search(
            r"((?:\d+\s*-\s*\d+(?:\s*-\s*\d+)?|N/A)(?:\s*\([^)]*\))?)\s+(?:PRO|AMATEUR)\s+RECORD AT FIGHT\s+"
            r"((?:\d+\s*-\s*\d+(?:\s*-\s*\d+)?|N/A)(?:\s*\([^)]*\))?)",
            text,
            re.I,
        )
        if not match:
            return []
        return [normalize_space(match.group(1)), normalize_space(match.group(2))]

    def _extract_matchup_title_info(self, text: str) -> str | None:
        match = re.search(
            r"BOUT INFORMATION\s+.*?\s+(MAIN EVENT|CO-MAIN EVENT|MAIN CARD|PRELIM|POSTLIM)(.*?)(?:\s+Event:|\s+Date:)",
            text,
            re.I,
        )
        if not match:
            return None
        return normalize_space(f"{match.group(1)} {match.group(2)}")

    def _extract_matchup_result_segment(self, text: str) -> str | None:
        match = re.search(
            r"\b[WL]\s+[^.]{1,120}?\s+defeats?\s+[^.]{1,120}?\s+[WL]\s+"
            r"(.*?)(?=\s+[A-Z][A-Za-z .'\"-]+\s+vs\.|\s+BOUT INFORMATION|\s+PRO RECORD AT FIGHT|$)",
            text,
            re.I,
        )
        if match:
            return normalize_space(match.group(1))
        match = re.search(
            r"\bends\s+in\s+a\s+draw\b\s+(.*?)(?=\s+[A-Z][A-Za-z .'\"-]+\s+vs\.|\s+BOUT INFORMATION|\s+PRO RECORD AT FIGHT|$)",
            text,
            re.I,
        )
        if not match:
            return None
        segment = normalize_space(match.group(1))
        trailing = text[match.end():]
        if trailing.lstrip().startswith("Total"):
            segment = normalize_space(f"{segment} Total")
        return segment

    def _extract_matchup_method_raw(self, text: str) -> str | None:
        match = re.search(
            r"\b(?:W\s+[^.]{1,80}?\s+defeats?\s+[^.]{1,80}?\s+L\s+)?"
            r"(KO/TKO|TKO|KO|Submission|Decision|DQ|Disqualification|No Contest|NC|Draw|N/A)"
            r"(?:,\s*([^0-9]+?))?(?:\s+\d{1,2}:\d{2}|\s+Round|\s+\d+\s+Rounds|\s+[A-Z][a-z]+ vs\.|$)",
            text,
            re.I,
        )
        if not match:
            return None
        return normalize_space(", ".join(part for part in match.groups() if part))

    def _raise_if_blocked(self, page: Any, run_dir: Path | None, name: str) -> None:
        block_reason = detect_hard_block(page)
        if not block_reason:
            return
        if (
            not self.config.headless
            and self.config.wait_for_manual_challenge_seconds > 0
            and is_manual_solvable_challenge(block_reason)
        ):
            remaining = wait_for_manual_challenge(
                page,
                timeout_seconds=self.config.wait_for_manual_challenge_seconds,
                poll_interval_ms=self.config.manual_challenge_poll_interval_ms,
                log=log,
                initial_reason=block_reason,
                context_name=name,
            )
            if remaining is None:
                return
            if run_dir and self.config.debug:
                save_debug_artifacts(page, run_dir, f"{name}_manual_challenge_timeout")
            if self._safe_mode_enabled():
                self._access_stop_reason = f"manual_challenge_timeout:{remaining}"
            raise FatalScrapeError(
                f"manual_challenge_timeout: challenge still present after "
                f"{self.config.wait_for_manual_challenge_seconds}s ({remaining})"
            )
        if run_dir and self.config.debug:
            save_debug_artifacts(page, run_dir, name)
        if self._safe_mode_enabled():
            self._access_stop_reason = f"hard_block:{block_reason}"
        raise FatalScrapeError(f"Hard block detected: {block_reason}")

    def _event_matches_excluded_promotion(self, event: dict[str, Any]) -> bool:
        excluded = {normalize_space(value).lower() for value in self.config.exclude_promotions}
        return "ufc" in excluded and is_ufc_promotion(
            event.get("promotion_name"),
            event.get("promotion_url"),
            event.get("event_name"),
        )

    def _list_item_matches_excluded_ufc(self, item: dict[str, Any]) -> bool:
        excluded = {normalize_space(value).lower() for value in self.config.exclude_promotions}
        return "ufc" in excluded and is_ufc_promotion(
            item.get("promotion_name"),
            item.get("promotion_url"),
            item.get("event_name"),
        )

    def _skipped_ufc_event_from_item(self, item: dict[str, Any]) -> dict[str, Any]:
        return {
            "source_event_id": item.get("source_event_id") or source_event_id_from_url(item.get("event_url")),
            "event_url": item.get("event_url"),
            "event_name": item.get("event_name"),
            "promotion_name": item.get("promotion_name"),
            "promotion_url": item.get("promotion_url"),
            "event_date": item.get("event_date"),
            "warnings": ["event_skipped_ufc"],
            "bouts": [],
        }

    def _event_is_skipped_ufc(self, event: dict[str, Any]) -> bool:
        return "event_skipped_ufc" in set(event.get("warnings") or [])

    def _record_skipped_ufc_event(self, summary: dict[str, Any], event: dict[str, Any]) -> None:
        summary["events_skipped_ufc"] = int(summary.get("events_skipped_ufc", 0)) + 1
        if len(summary["skipped_event_examples"]) < 10:
            summary["skipped_event_examples"].append(
                {
                    "source_event_id": event.get("source_event_id"),
                    "event_name": event.get("event_name"),
                    "event_url": event.get("event_url"),
                    "warnings": event.get("warnings", []),
                }
            )

    def _record_skipped_results_event(self, summary: dict[str, Any], event: dict[str, Any]) -> None:
        category = skipped_results_event_category(event) or "no_usable_pro_mma_bouts"
        summary["skipped_events_no_usable_bouts"] = int(summary.get("skipped_events_no_usable_bouts") or 0) + 1
        if skipped_results_event_is_non_mma_or_no_results(event):
            summary["skipped_events_non_mma_or_no_results"] = (
                int(summary.get("skipped_events_non_mma_or_no_results") or 0) + 1
            )
        if category == "non_mma":
            summary["events_skipped_non_mma"] = int(summary.get("events_skipped_non_mma") or 0) + 1
        elif category == "no_results":
            summary["events_skipped_no_results"] = int(summary.get("events_skipped_no_results") or 0) + 1
        elif category == "all_amateur":
            summary["events_skipped_all_amateur"] = int(summary.get("events_skipped_all_amateur") or 0) + 1
            summary["events_skipped_no_usable_pro_mma_bouts"] = (
                int(summary.get("events_skipped_no_usable_pro_mma_bouts") or 0) + 1
            )
        elif category == "no_usable_pro_mma_bouts":
            summary["events_skipped_no_usable_pro_mma_bouts"] = (
                int(summary.get("events_skipped_no_usable_pro_mma_bouts") or 0) + 1
            )
        if len(summary["skipped_event_examples"]) < 10:
            summary["skipped_event_examples"].append(
                {
                    "source_event_id": event.get("source_event_id"),
                    "event_name": event.get("event_name"),
                    "event_url": event.get("event_url"),
                    "skip_category": category,
                    "reason": category,
                    "warnings": event.get("warnings", []),
                }
            )

    def _refresh_run_counts(self, document: dict[str, Any]) -> None:
        events = document.get("events", [])
        bouts = [bout for event in events for bout in event.get("bouts", [])]
        skipped_bouts = [skipped for event in events for skipped in event.get("skipped_bouts", [])]
        warnings_count = sum(len(event.get("warnings", [])) for event in events) + sum(
            len(bout.get("warnings", [])) for bout in bouts
        )
        skipped_amateur = self._count_event_warnings(events, "bouts_skipped_amateur")
        skipped_non_mma = self._count_event_warnings(events, "bouts_skipped_non_mma")
        skipped_no_result = self._count_event_warnings(events, "bouts_skipped_no_result")
        skipped_unknown = self._count_event_warnings(events, "bouts_skipped_unknown_professional_status")
        source_rows_seen = len(bouts) + len(skipped_bouts)
        skipped_events_no_usable = int(document["run"].get("skipped_events_no_usable_bouts") or 0)
        skipped_ufc = int(document["run"].get("events_skipped_ufc") or 0)
        skipped_known_stable = int(document["run"].get("events_skipped_known_stable") or 0)
        document["run"]["events_seen"] = len(events) + skipped_events_no_usable + skipped_ufc
        document["run"]["events_written"] = len(events)
        document["run"]["events_skipped_known_stable"] = skipped_known_stable
        document["run"]["bouts_written"] = len(bouts)
        document["run"]["bouts_seen"] = source_rows_seen
        document["run"]["bouts_source_rows_seen"] = source_rows_seen
        document["run"]["bouts_skipped_amateur"] = skipped_amateur
        document["run"]["bouts_skipped_non_mma"] = skipped_non_mma
        document["run"]["bouts_skipped_no_result"] = skipped_no_result
        document["run"]["bouts_skipped_unknown_professional_status"] = skipped_unknown
        document["run"]["source_mma_bouts_total_mismatches"] = sum(
            1
            for event in events
            if any(str(warning).startswith("source_mma_bouts_total_mismatch:") for warning in event.get("warnings", []))
        )
        document["run"].update(build_event_profile_enrichment_summary(events))
        document["run"]["warnings_count"] = warnings_count
        document["run"]["retained_event_warnings_count"] = warnings_count
        document["run"]["skipped_event_warnings_count"] = 0
        document["run"]["errors_count"] = 0

    def _copy_summary_skip_counts(self, document: dict[str, Any], summary: dict[str, Any], kind: str) -> None:
        document["run"]["events_skipped_ufc"] = summary.get("events_skipped_ufc", 0)
        if kind == "results":
            document["run"]["skipped_events_no_usable_bouts"] = summary.get("skipped_events_no_usable_bouts", 0)
            document["run"]["skipped_events_non_mma_or_no_results"] = summary.get(
                "skipped_events_non_mma_or_no_results", 0
            )
            document["run"]["events_skipped_non_mma"] = summary.get("events_skipped_non_mma", 0)
            document["run"]["events_skipped_no_results"] = summary.get("events_skipped_no_results", 0)
            document["run"]["events_skipped_all_amateur"] = summary.get("events_skipped_all_amateur", 0)
            document["run"]["events_skipped_no_usable_pro_mma_bouts"] = summary.get(
                "events_skipped_no_usable_pro_mma_bouts", 0
            )
            document["run"]["events_skipped_known_stable"] = summary.get("events_skipped_known_stable", 0)
            document["run"]["events_skipped_known_cache"] = summary.get("events_skipped_known_cache", 0)
            document["run"]["events_from_payload_cache"] = summary.get("events_from_payload_cache", 0)
            document["run"]["missing_cached_event_payloads"] = summary.get("missing_cached_event_payloads", 0)
            document["run"]["events_rechecked_lookback"] = summary.get("events_rechecked_lookback", 0)
            document["run"]["events_rechecked_incomplete"] = summary.get("events_rechecked_incomplete", 0)
            document["run"]["early_stop_triggered"] = summary.get("early_stop_triggered", False)
            document["run"]["early_stop_reason"] = summary.get("early_stop_reason")
            document["run"]["pages_scanned"] = summary.get("pages_scanned", 0)
            document["run"]["first_listing_url"] = summary.get("first_listing_url")
            document["run"]["last_listing_url"] = summary.get("last_listing_url")
            document["run"]["listing_urls_visited"] = summary.get("listing_urls_visited", [])
            document["run"]["pagination_next_found"] = summary.get("pagination_next_found", False)
            document["run"]["pagination_stop_reason"] = summary.get("pagination_stop_reason")
            document["run"]["popup_dismissals_count"] = int(summary.get("popup_dismissals_count") or 0)
            self._sync_runtime_diagnostics(document["run"])
            document["run"]["failed_event_examples"] = summary.get("failed_event_examples", [])[:25]
            document["run"]["failed_bout_examples"] = summary.get("failed_bout_examples", [])[:25]
            document["run"]["failed_profile_examples"] = summary.get("failed_profile_examples", [])[:25]
        elif kind == "upcoming":
            document["run"]["pages_scanned"] = summary.get("pages_scanned", 0)
            document["run"]["first_listing_url"] = summary.get("first_listing_url")
            document["run"]["last_listing_url"] = summary.get("last_listing_url")
            document["run"]["listing_urls_visited"] = summary.get("listing_urls_visited", [])
            document["run"]["pagination_next_found"] = summary.get("pagination_next_found", False)
            document["run"]["pagination_stop_reason"] = summary.get("pagination_stop_reason")
            document["run"]["date_window_stop_confirmed"] = bool(summary.get("date_window_stop_confirmed"))
        document["run"]["skipped_event_examples"] = summary.get("skipped_event_examples", [])[:10]
        document["run"]["events_seen"] = (
            len(document.get("events", []))
            + int(document["run"].get("skipped_events_no_usable_bouts") or 0)
            + int(document["run"].get("events_skipped_ufc") or 0)
        )
        self._sync_run_warning_counts(document, summary, update_summary=False)

    def _sync_run_warning_counts(
        self,
        document: dict[str, Any],
        summary: dict[str, Any],
        *,
        update_summary: bool = True,
    ) -> None:
        run = document.setdefault("run", {})
        retained = int(run.get("retained_event_warnings_count") or run.get("warnings_count") or 0)
        total = int(summary.get("warnings_count") or len(summary.get("warnings", [])) or retained)
        run["warnings_count"] = total
        run["retained_event_warnings_count"] = retained
        run["skipped_event_warnings_count"] = max(total - retained, 0)
        run["errors_count"] = int(summary.get("errors_count") or len(summary.get("errors", [])) or 0)
        if update_summary:
            summary["warnings_count"] = total
            summary["retained_event_warnings_count"] = retained
            summary["skipped_event_warnings_count"] = run["skipped_event_warnings_count"]
            summary["errors_count"] = run["errors_count"]

    def _aggregate_counts(self, *documents: dict[str, Any]) -> dict[str, Any]:
        first_listing_url = next(
            (doc["run"].get("first_listing_url") for doc in documents if doc["run"].get("first_listing_url")),
            None,
        )
        listing_urls_visited: list[str] = []
        for doc in documents:
            for url in doc["run"].get("listing_urls_visited", []) or []:
                if url and url not in listing_urls_visited:
                    listing_urls_visited.append(url)
        profile_summary = build_event_profile_enrichment_summary(
            [
                event
                for doc in documents
                for event in doc.get("events", [])
                if isinstance(event, dict)
            ]
        )
        return {
            "events_seen": sum(doc["run"].get("events_seen", 0) for doc in documents),
            "events_written": sum(doc["run"].get("events_written", 0) for doc in documents),
            "events_skipped_known_stable": sum(doc["run"].get("events_skipped_known_stable", 0) for doc in documents),
            "events_skipped_known_cache": sum(doc["run"].get("events_skipped_known_cache", 0) for doc in documents),
            "events_from_payload_cache": sum(doc["run"].get("events_from_payload_cache", 0) for doc in documents),
            "missing_cached_event_payloads": sum(doc["run"].get("missing_cached_event_payloads", 0) for doc in documents),
            "events_rechecked_lookback": sum(doc["run"].get("events_rechecked_lookback", 0) for doc in documents),
            "events_rechecked_incomplete": sum(doc["run"].get("events_rechecked_incomplete", 0) for doc in documents),
            "early_stop_triggered": any(doc["run"].get("early_stop_triggered") for doc in documents),
            "early_stop_reason": next(
                (doc["run"].get("early_stop_reason") for doc in documents if doc["run"].get("early_stop_reason")),
                None,
            ),
            "pages_scanned": sum(doc["run"].get("pages_scanned", 0) for doc in documents),
            "first_listing_url": first_listing_url,
            "last_listing_url": next(
                (doc["run"].get("last_listing_url") for doc in reversed(documents) if doc["run"].get("last_listing_url")),
                None,
            ),
            "listing_urls_visited": listing_urls_visited,
            "pagination_next_found": any(doc["run"].get("pagination_next_found") for doc in documents),
            "pagination_stop_reason": next(
                (
                    doc["run"].get("pagination_stop_reason")
                    for doc in reversed(documents)
                    if doc["run"].get("pagination_stop_reason")
                ),
                None,
            ),
            "date_window_stop_confirmed": any(
                doc["run"].get("date_window_stop_confirmed") for doc in documents
            ),
            **self._runtime_diagnostic_counts(),
            "skipped_events_no_usable_bouts": sum(
                doc["run"].get("skipped_events_no_usable_bouts", 0) for doc in documents
            ),
            "skipped_events_non_mma_or_no_results": sum(
                doc["run"].get("skipped_events_non_mma_or_no_results", 0) for doc in documents
            ),
            "events_skipped_non_mma": sum(doc["run"].get("events_skipped_non_mma", 0) for doc in documents),
            "events_skipped_no_results": sum(doc["run"].get("events_skipped_no_results", 0) for doc in documents),
            "events_skipped_all_amateur": sum(doc["run"].get("events_skipped_all_amateur", 0) for doc in documents),
            "events_skipped_no_usable_pro_mma_bouts": sum(
                doc["run"].get("events_skipped_no_usable_pro_mma_bouts", 0) for doc in documents
            ),
            "events_skipped_ufc": sum(doc["run"].get("events_skipped_ufc", 0) for doc in documents),
            "bouts_seen": sum(doc["run"].get("bouts_seen", 0) for doc in documents),
            "bouts_source_rows_seen": sum(doc["run"].get("bouts_source_rows_seen", doc["run"].get("bouts_seen", 0)) for doc in documents),
            "bouts_written": sum(doc["run"].get("bouts_written", 0) for doc in documents),
            "bouts_skipped_amateur": sum(doc["run"].get("bouts_skipped_amateur", 0) for doc in documents),
            "bouts_skipped_non_mma": sum(doc["run"].get("bouts_skipped_non_mma", 0) for doc in documents),
            "bouts_skipped_no_result": sum(doc["run"].get("bouts_skipped_no_result", 0) for doc in documents),
            "bouts_skipped_unknown_professional_status": sum(doc["run"].get("bouts_skipped_unknown_professional_status", 0) for doc in documents),
            "source_mma_bouts_total_mismatches": sum(
                doc["run"].get("source_mma_bouts_total_mismatches", 0) for doc in documents
            ),
            **profile_summary,
        }

    def _count_event_warnings(self, events: list[dict[str, Any]], prefix: str) -> int:
        total = 0
        for event in events:
            for warning in event.get("warnings", []):
                if warning.startswith(f"{prefix}:"):
                    try:
                        total += int(warning.rsplit(":", 1)[1])
                    except ValueError:
                        pass
        return total

    def _first_visible_text(self, page: Any, selectors: list[str]) -> str | None:
        for selector in selectors:
            try:
                locator = page.locator(selector)
                if locator.count():
                    text = normalize_space(locator.first.inner_text(timeout=1000))
                    if text:
                        return text
            except Exception:
                continue
        return None

    def _extract_records(self, text: str) -> list[str]:
        return re.findall(r"\b\d+\s*-\s*\d+(?:\s*-\s*\d+)?(?:\s*[,;(]\s*\d+\s*NC\)?)?", text, re.I)

    def _extract_method_raw(self, text: str) -> str | None:
        tapology_result_match = re.search(
            r"\b[WL]\s+(?:\u2022|-)\s*([^\u00b7\n]+?)(?:\s*\u00b7|\s+\bR\d|\s+\d{1,2}:\d{2}|$)",
            text,
            re.I,
        )
        if tapology_result_match:
            return normalize_space(tapology_result_match.group(1))
        row_match = re.search(
            r"\b(KO/TKO|TKO|KO|Submission|Decision|DQ|Disqualification|No Contest|NC|Draw|N/A)"
            r"(?:,\s*([^0-9#|]+?))?(?=\s+\d{1,2}:\d{2}|\s+Round|\s+\d+\s+Rounds|\s+[WL]\b|$)",
            text,
            re.I,
        )
        if row_match:
            return normalize_space(", ".join(part for part in row_match.groups() if part))
        return first_match(
            [
                r"\b(KO/TKO|TKO|KO|Submission|Decision[^,\n]*|DQ|Disqualification|No Contest|NC|Draw|Cancelled|Canceled)\b",
                r"\b(N/A|N/a|n/a|Unknown)\b",
            ],
            text,
        )

    def _extract_result_raw(self, text: str) -> str | None:
        return first_match([r"\b(def\.?|defeated|vs\.?|draw|no contest|cancelled|canceled)\b"], text)

    def _extract_weight_class(self, text: str) -> str | None:
        return first_match(
            [
                r"\b(Super Lightweight|Light Heavyweight|Open Weight|Catchweight|Strawweight|Flyweight|Bantamweight|Featherweight|Lightweight|Welterweight|Middleweight|Heavyweight)\b",
            ],
            text,
        )

    def _extract_weight_lbs(self, text: str) -> int | None:
        match = re.search(r"\b(\d{3})\s*LBS?\b|\b(MAIN EVENT|CO-MAIN|MAIN CARD|PRELIM|POSTLIM)\s+(\d{3})\b", text, re.I)
        if not match:
            return None
        return int(match.group(1) or match.group(3))

    def _extract_card_position(self, text: str) -> str:
        lowered = normalize_space(text).lower()
        if re.search(r"\bmain event\b", lowered):
            return "main_event"
        if re.search(r"\bco-main(?: event)?\b", lowered):
            return "co_main_event"
        if re.search(r"\bmain card\b", lowered):
            return "main_card"
        if re.search(r"\bprelim\b", lowered):
            return "prelim"
        if re.search(r"\bpostlim\b", lowered):
            return "postlim"
        return "unknown"

    def _extract_title_fight(self, text: str) -> dict[str, Any] | None:
        title_line = first_match(
            [
                r"\bTitle on Line:\s*(.*?)(?:\s+Belt Status Before Fight:|\s+Event Links:|\s+[A-Z][a-z]+ Total Disclosed Pay:|$)",
                r"\b([A-Z][A-Za-z0-9&.' -]+ Championship)\b",
            ],
            text,
        )
        if not title_line or title_line.lower() == "n/a":
            return None
        status = first_match([r"\bBelt Status Before Fight:\s*(.*?)(?:\s+Event Links:|\s+[A-Z][a-z]+ Total Disclosed Pay:|$)"], text)
        return self._prune_none({"is_title_fight": True, "title_name": title_line, "belt_status_before_fight": status})

    def _winner_loser_roles(
        self,
        winner_name: str | None,
        loser_name: str | None,
        fighter_a_name: str | None,
        fighter_b_name: str | None,
    ) -> tuple[str | None, str | None]:
        winner_norm = normalize_name(winner_name)
        loser_norm = normalize_name(loser_name)
        a_norm = normalize_name(fighter_a_name)
        b_norm = normalize_name(fighter_b_name)
        winner = "fighter_a" if winner_norm and winner_norm == a_norm else ("fighter_b" if winner_norm and winner_norm == b_norm else None)
        loser = "fighter_a" if loser_norm and loser_norm == a_norm else ("fighter_b" if loser_norm and loser_norm == b_norm else None)
        return winner, loser

    def _result_type(self, method_category: str | None, winner: str | None, loser: str | None, text: str) -> str:
        lowered = normalize_space(text).lower()
        if method_category == "draw" or re.search(r"\bdraw\b", lowered):
            return "draw"
        if method_category == "no_contest" or re.search(r"\bno contest\b|\bnc\b", lowered):
            return "no_contest"
        if "cancel" in lowered or "scrapped" in lowered:
            return "cancelled"
        if winner and loser:
            return "win_loss"
        return "unknown"

    def _infer_winner_loser(self, text: str, fighter_a: str | None, fighter_b: str | None) -> tuple[str | None, str | None]:
        lowered = text.lower()
        a = normalize_name(fighter_a)
        b = normalize_name(fighter_b)
        text_norm = normalize_name(text)
        if a and b:
            if re.search(rf"\bw\b.*{re.escape(a)}.*\bl\b.*{re.escape(b)}", text_norm):
                return fighter_a, fighter_b
            if re.search(rf"\bw\b.*{re.escape(b)}.*\bl\b.*{re.escape(a)}", text_norm):
                return fighter_b, fighter_a
        if "def" not in lowered and "defeated" not in lowered:
            return None, None
        def_index = text_norm.find(" def")
        if def_index < 0:
            def_index = text_norm.find(" defeated")
        if a and b and def_index >= 0:
            a_index = text_norm.find(a)
            b_index = text_norm.find(b)
            if 0 <= a_index < def_index < b_index:
                return fighter_a, fighter_b
            if 0 <= b_index < def_index < a_index:
                return fighter_b, fighter_a
        return None, None

    def _extract_date_raw(self, text: str) -> str | None:
        return first_match(
            [
                r"((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2},\s+\d{4})",
                r"([A-Z][a-z]+\s+\d{1,2},\s+\d{4})",
                r"(\d{4}-\d{2}-\d{2})",
            ],
            text,
        )

    def _extract_event_time(self, text: str) -> str | None:
        return first_match([r"\b(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm)?)\b"], text)

    def _extract_timezone(self, text: str) -> str | None:
        return first_match([r"\b(ET|PT|CT|MT|GMT|UTC|BST|CET|CEST|EST|EDT|PST|PDT)\b"], text)

    def _extract_event_datetime_raw(self, text: str) -> str | None:
        match = re.search(
            r"((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+"
            r"[A-Z][a-z]+\s+\d{1,2},\s+\d{4}\s+at\s+"
            r"\d{1,2}:\d{2}\s*(?:AM|PM|am|pm)\s*"
            r"(?:ET|PT|CT|MT|GMT|UTC|BST|CET|CEST|EST|EDT|PST|PDT)?)",
            text,
            re.I,
        )
        if match:
            return normalize_space(match.group(1))
        date_raw = self._extract_date_raw(text)
        time_raw = self._extract_event_time(text)
        timezone_raw = self._extract_timezone(text)
        if date_raw and time_raw:
            return normalize_space(f"{date_raw} at {time_raw} {timezone_raw or ''}")
        return None

    def _extract_source_bouts_total(self, text: str) -> int | None:
        match = re.search(r"\bMMA\s+Bouts?\s*:\s*(\d+)\b", text, re.I)
        return int(match.group(1)) if match else None

    def _extract_location(self, text: str) -> str | None:
        return self._extract_labeled_value(text, "Location", ["Bout Billing", "Pro/Am", "Weight", "MMA Bouts", "Promotion Links", "Event Links"])

    def _extract_venue(self, text: str) -> str | None:
        return self._extract_labeled_value(text, "Venue", ["Enclosure", "Location", "Bout Billing", "MMA Bouts"])

    def _extract_enclosure(self, text: str) -> str | None:
        enclosure = self._extract_labeled_value(
            text,
            "Enclosure",
            [
                "Location",
                "Bout Billing",
                "MMA Bouts",
                "Promotion Links",
                "Ring Announcer",
                "TV Announcers",
                "Matchmaker",
                "Broadcast",
            ],
        )
        normalized = normalize_space(enclosure).lower()
        if normalized == "cage":
            return "Cage"
        if normalized == "ring":
            return "Ring"
        return None

    def _extract_labeled_value(self, text: str, label: str, stop_labels: list[str]) -> str | None:
        stops = "|".join(re.escape(stop) + r":" for stop in stop_labels)
        match = re.search(rf"\b{re.escape(label)}:\s*(.*?)(?=\s+(?:{stops})|$)", text, re.I)
        if not match:
            return None
        value = normalize_space(match.group(1))
        if not value or value.lower() in {"n/a", "none"}:
            return None
        value = re.sub(r"\s+\b(?:Venue|Enclosure|Location|Bout Billing|Pro/Am|Weight|MMA Bouts|Promotion Links|Event Links):.*$", "", value, flags=re.I)
        if re.search(r"\b(?:Venue|Enclosure|Location|Bout Billing|Pro/Am|Weight|MMA Bouts|Promotion Links|Event Links):", value, re.I):
            return None
        value = normalize_space(value)
        if not value:
            return None
        return value

    def _extract_promotion(self, text: str) -> str | None:
        text = normalize_space(text)
        if not text:
            return None
        text = re.split(r"\s+[•]\s+|\s+\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+", text, maxsplit=1, flags=re.I)[0]
        return normalize_space(text.title()) or None

    def _extract_event_promotion(self, page: Any, text: str) -> str | None:
        try:
            locator = page.locator("a[href*='/fightcenter/promotions/'], a[href*='/promotions/']")
            if locator.count():
                promotion_url = absolute_url(page.url, locator.first.get_attribute("href"))
                return self._promotion_name_from_url(promotion_url) or normalize_space(locator.first.inner_text(timeout=1000))
        except Exception:
            pass
        return None

    def _promotion_name_from_url(self, promotion_url: str | None) -> str | None:
        if not promotion_url:
            return None
        match = re.search(r"/(?:fightcenter/)?promotions/\d+-([^/?#]+)", promotion_url)
        if not match:
            return None
        words = match.group(1).split("-")
        if words and len(words[-1]) <= 4:
            words = words[:-1]
        return " ".join(word.upper() if len(word) <= 3 else word.title() for word in words) or None

    def _extract_promotion_url(self, page: Any) -> str | None:
        try:
            locator = page.locator("a[href*='/fightcenter/promotions/'], a[href*='/promotions/']")
            if locator.count():
                return absolute_url(page.url, locator.first.get_attribute("href"))
        except Exception:
            pass
        return None

    def _extract_visible_main_bout(self, text: str) -> str | None:
        return first_match([r"([A-Z][A-Za-z .'-]+\s+vs\.?\s+[A-Z][A-Za-z .'-]+)"], text)

    def _extract_visible_bout_count(self, text: str) -> int | None:
        match = re.search(r"\b(\d+)\s+bouts?\b", text, re.I)
        return int(match.group(1)) if match else None

    def _prune_none(self, value: Any) -> Any:
        if isinstance(value, dict):
            return {
                key: item if key == "event_coverage" else self._prune_none(item)
                for key, item in value.items()
                if (
                    item is not None
                    or key
                    in {
                        "winner",
                        "loser",
                        "source_event_numeric_id",
                        "source_bout_numeric_id",
                        "source_fighter_id",
                        "source_fighter_numeric_id",
                        "source_promotion_id",
                        "source_promotion_numeric_id",
                    }
                    or key in PRESERVE_NULL_KEYS
                )
                and not (key == "warnings" and item == [])
            }
        if isinstance(value, list):
            return [self._prune_none(item) for item in value]
        return value
