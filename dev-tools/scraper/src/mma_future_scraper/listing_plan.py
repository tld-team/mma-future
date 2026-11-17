"""Pre-scrape listing analysis for cumulative FightCenter results runs.

The planner walks only FightCenter listing pages (no event pages) and decides,
per listed event, whether a results run still has work to do there. It answers
"how deep must the run go" without stopping at the first known event: known
events that were incomplete when first scraped stay work until they are filled,
while events whose remaining gaps cannot change on a re-fetch are settled.

Every decision is local and read-only: state/, latest/ and runs/ are never
modified here.
"""
from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable, Iterable

from .utils import source_event_id_from_url

PLAN_SCHEMA_VERSION = "mmaf_listing_plan_v1"

WORK = "work"
SETTLED = "settled"
WAITING = "waiting"
STALE = "stale"
SKIP = "skip"
ACTIONS = (WORK, SETTLED, WAITING, STALE, SKIP)

# Warning codes describing what was deliberately left out of an event. A
# re-fetch returns the same card, so these never make an event fillable.
STRUCTURAL_WARNING_CODES = frozenset(
    {
        "bouts_skipped_amateur",
        "bouts_skipped_non_mma",
        "bouts_skipped_junior",
        "bouts_skipped_cancelled",
        "bouts_skipped_unknown_professional_status",
        "missing_source_promotion_id",
    }
)
# Only bout detail pages carry pre-fight records; event-card runs never fill it.
MATCHUP_ONLY_WARNING_CODES = frozenset({"missing_prefight_record"})
SETTLED_INDEX_STATES = frozenset({"complete", "ignored", "no_relevant_pro_mma_bouts"})
IGNORED_COVERAGE_REASONS = frozenset({"legacy_unverified"})

DEFAULT_PLAN_MAX_PAGES = 25
DEFAULT_SETTLE_PAGES = 2
DEFAULT_LOOKBACK_DAYS = 30
DEFAULT_DATE_MARGIN_DAYS = 2
DEFAULT_STALE_AFTER_DAYS = 30
DEFAULT_STALE_MIN_RECHECKS = 3
DEFAULT_FETCH_SECONDS_ESTIMATE = 8.0


@dataclass(frozen=True)
class Decision:
    action: str
    reason: str


def _parse_date(value: Any) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(str(value)[:10])
    except ValueError:
        return None


def _parse_datetime(value: Any) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)


def warning_code(value: Any) -> str:
    return str(value or "").split(":", 1)[0].strip()


def item_source_event_id(item: dict[str, Any]) -> str | None:
    return item.get("source_event_id") or source_event_id_from_url(item.get("event_url"))


def load_coverage_history(data_dir: Path) -> dict[str, dict[str, Any]]:
    """Newest observed coverage entry per event across latest/ and every run.

    latest/event_coverage.json only lists what the newest run observed, so an
    event excluded three runs ago is absent from it. Run folders keep their
    coverage files (prune-runs leaves them), which makes the union the durable
    record of exclusions and fetch failures.
    """
    data_dir = Path(data_dir)
    paths = [data_dir / "latest" / "event_coverage.json"]
    runs_dir = data_dir / "runs"
    if runs_dir.is_dir():
        paths.extend(sorted(runs_dir.glob("*/event_coverage.json")))
    paths.extend(sorted((data_dir / "recovery" / "results").glob("*/event_coverage.json")))
    history: dict[str, dict[str, Any]] = {}
    for path in paths:
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        entries = document.get("events") if isinstance(document, dict) else None
        for entry in entries or []:
            if not isinstance(entry, dict):
                continue
            sid = entry.get("source_event_id")
            observed = _parse_datetime(entry.get("observed_at"))
            if not sid or observed is None or entry.get("parser_version") == "legacy":
                continue
            current = history.get(sid)
            if current is None or observed >= _parse_datetime(current.get("observed_at")):
                history[sid] = {key: value for key, value in entry.items() if key != "bouts"}
    return history


class ListingClassifier:
    """Decides, from local state only, whether a listed event still needs a fetch."""

    def __init__(
        self,
        *,
        bundle: dict[str, Any],
        coverage_history: dict[str, dict[str, Any]] | None = None,
        now: datetime | None = None,
        scrape_matchup_pages: bool = False,
        recheck_complete_days: int = 0,
        stale_after_days: int = DEFAULT_STALE_AFTER_DAYS,
        stale_min_rechecks: int = DEFAULT_STALE_MIN_RECHECKS,
        include_backfill: bool = False,
        force_ids: Iterable[str] = (),
        checkpoint_complete_ids: Iterable[str] = (),
        checkpoint_excluded_ids: Iterable[str] = (),
        empty_results_until: dict[str, datetime] | None = None,
        is_excluded_promotion: Callable[[dict[str, Any]], bool] | None = None,
    ) -> None:
        self.now = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
        self.today = self.now.date()
        self.events_index: dict[str, Any] = bundle.get("events_index") or {}
        self.incomplete: dict[str, Any] = bundle.get("incomplete_events") or {}
        self.payloads: dict[str, Any] = bundle.get("event_payloads") or {}
        self.coverage = coverage_history or {}
        self.scrape_matchup_pages = scrape_matchup_pages
        self.recheck_complete_days = max(0, int(recheck_complete_days or 0))
        self.stale_after_days = stale_after_days
        self.stale_min_rechecks = stale_min_rechecks
        self.include_backfill = include_backfill
        self.force_ids = {value for value in force_ids if value}
        self.checkpoint_complete_ids = set(checkpoint_complete_ids)
        self.checkpoint_excluded_ids = set(checkpoint_excluded_ids)
        self.empty_results_until = dict(empty_results_until or {})
        self.is_excluded_promotion = is_excluded_promotion or (lambda item: False)
        dates = [d for d in (_parse_date(r.get("event_date")) for r in self.events_index.values()) if d]
        self.coverage_start: date | None = min(dates) if dates else None
        self.newest_known: date | None = max(dates) if dates else None

    # -- helpers -----------------------------------------------------------
    def _fillable_codes(self, record: dict[str, Any]) -> set[str]:
        structural = set(STRUCTURAL_WARNING_CODES)
        if not self.scrape_matchup_pages:
            structural |= MATCHUP_ONLY_WARNING_CODES
        codes = {warning_code(code) for code in record.get("last_warning_codes") or []}
        return {code for code in codes if code and code not in structural}

    def _coverage_open(self, sid: str, record: dict[str, Any]) -> bool:
        """A newer partial observation than the indexed scrape means real gaps."""
        entry = self.coverage.get(sid)
        if not entry or entry.get("status") != "partial":
            return False
        if not set(entry.get("reasons") or []) - IGNORED_COVERAGE_REASONS:
            return False
        observed = _parse_datetime(entry.get("observed_at"))
        scraped = _parse_datetime(record.get("last_successfully_scraped_at") or record.get("last_scraped_at"))
        return observed is not None and (scraped is None or observed >= scraped)

    def _is_stale(self, record: dict[str, Any], queued: dict[str, Any] | None) -> bool:
        event_date = _parse_date(record.get("event_date"))
        if event_date is None or self.stale_after_days <= 0:
            return False
        rechecks = int((queued or {}).get("recheck_count") or 0)
        if not queued:
            rechecks = max(rechecks, int(record.get("scrape_count") or 0) - 1)
        return event_date < self.today - timedelta(days=self.stale_after_days) and rechecks >= self.stale_min_rechecks

    def _payload_missing(self, sid: str, record: dict[str, Any]) -> bool:
        if record.get("state") in {"ignored", "no_relevant_pro_mma_bouts"}:
            return False
        payload = self.payloads.get(sid)
        return not (isinstance(payload, dict) and payload.get("bouts"))

    # -- public API --------------------------------------------------------
    def classify(self, item: dict[str, Any]) -> Decision:
        sid = item_source_event_id(item)
        if not sid:
            return Decision(WORK, "unidentified_listing_item")
        if self.is_excluded_promotion(item):
            return Decision(SKIP, "excluded_promotion_ufc")
        if sid in self.force_ids or item.get("event_url") in self.force_ids:
            return Decision(WORK, "forced_event")
        if sid in self.checkpoint_complete_ids:
            return Decision(SETTLED, "checkpoint_complete")
        if sid in self.checkpoint_excluded_ids:
            return Decision(SETTLED, "checkpoint_excluded")
        empty_until = self.empty_results_until.get(sid)
        if empty_until and self.now < empty_until:
            return Decision(WAITING, "empty_results_cooldown")

        record = self.events_index.get(sid)
        if not record:
            return self._classify_unindexed(sid, item)
        return self.classify_known(sid, record)

    def _classify_unindexed(self, sid: str, item: dict[str, Any]) -> Decision:
        entry = self.coverage.get(sid)
        if entry:
            status = entry.get("status")
            if status == "excluded":
                return Decision(SETTLED, "known_excluded")
            if status == "unavailable":
                return Decision(WORK, "previous_fetch_failed")
            return Decision(WORK, f"known_{status or 'unknown'}_not_indexed")
        listed = _parse_date(item.get("event_date"))
        if (
            not self.include_backfill
            and self.coverage_start is not None
            and listed is not None
            and listed < self.coverage_start
        ):
            return Decision(SKIP, "before_coverage_start")
        return Decision(WORK, "new_visible_event")

    def classify_known(self, sid: str, record: dict[str, Any]) -> Decision:
        queued = self.incomplete.get(sid)
        state = record.get("state")
        coverage_open = self._coverage_open(sid, record)
        if state in SETTLED_INDEX_STATES and not record.get("needs_recheck") and not queued and not coverage_open:
            if self._payload_missing(sid, record):
                # Cumulative output needs the cached card; without it the event drops out.
                return Decision(WORK, "missing_cached_payload")
            event_date = _parse_date(record.get("event_date"))
            if (
                self.recheck_complete_days
                and event_date
                and event_date >= self.today - timedelta(days=self.recheck_complete_days)
            ):
                return Decision(WORK, "recent_recheck_window")
            return Decision(SETTLED, "known_complete")

        reason = (queued or {}).get("recheck_reason") or record.get("recheck_reason") or state or "known_not_complete"
        if (
            reason == "parser_warnings"
            and not coverage_open
            and not self._fillable_codes(record)
            and not self._payload_missing(sid, record)
        ):
            return Decision(SETTLED, "structural_parser_warnings")
        due_at = _parse_datetime((queued or {}).get("next_recheck_after"))
        if due_at is not None and due_at > self.now:
            return Decision(WAITING, "incomplete_recheck_not_due")
        if self._is_stale(record, queued):
            return Decision(STALE, f"stale_{reason}")
        if reason == "parser_warnings":
            return Decision(WORK, "parser_warnings_fillable")
        if coverage_open and reason in SETTLED_INDEX_STATES:
            return Decision(WORK, "coverage_partial")
        return Decision(WORK, reason)

    def open_targets(self, lookback_days: int) -> dict[str, date]:
        """Known events that still need a fetch and should appear in the listing."""
        floor = self.today - timedelta(days=max(0, lookback_days))
        targets: dict[str, date] = {}
        for sid, record in self.events_index.items():
            event_date = _parse_date(record.get("event_date"))
            if event_date is None or event_date < floor:
                continue
            if sid in self.checkpoint_complete_ids or sid in self.checkpoint_excluded_ids:
                continue
            decision = self.classify_known(sid, record)
            if decision.action == WORK:
                targets[sid] = event_date
        return targets


class ListingPlanner:
    """Consumes listing pages in order and decides how deep the run must go."""

    def __init__(
        self,
        classifier: ListingClassifier,
        *,
        max_pages: int = DEFAULT_PLAN_MAX_PAGES,
        settle_pages: int = DEFAULT_SETTLE_PAGES,
        lookback_days: int = DEFAULT_LOOKBACK_DAYS,
        date_margin_days: int = DEFAULT_DATE_MARGIN_DAYS,
    ) -> None:
        self.classifier = classifier
        self.max_pages = max(1, int(max_pages))
        self.settle_pages = max(1, int(settle_pages))
        self.lookback_days = lookback_days
        self.date_margin_days = max(0, int(date_margin_days))
        self.targets = classifier.open_targets(lookback_days)
        # Always re-verify the lookback window: Tapology publishes past events
        # late, so unknown events can appear between already-known ones. Go
        # deeper when the last scraped event or an open known event is older.
        candidates = [classifier.today - timedelta(days=max(0, int(lookback_days)))]
        candidates.extend(self.targets.values())
        if classifier.newest_known is not None:
            candidates.append(classifier.newest_known)
        self.horizon: date = min(candidates) - timedelta(days=self.date_margin_days)
        self.pages: list[dict[str, Any]] = []
        self.entries: list[dict[str, Any]] = []
        self.seen: set[str] = set()
        self.last_work_page = 0
        self.clean_streak = 0
        self.passed_horizon = False
        self.stop_reason: str | None = None
        self.interrupted: str | None = None

    def add_page(self, page_number: int, items: list[dict[str, Any]]) -> None:
        counts = {action: 0 for action in ACTIONS}
        dates: list[date] = []
        for position, item in enumerate(items, start=1):
            sid = item_source_event_id(item)
            if sid and sid in self.seen:
                continue
            if sid:
                self.seen.add(sid)
            decision = self.classifier.classify(item)
            counts[decision.action] += 1
            listed = _parse_date(item.get("event_date"))
            if listed:
                dates.append(listed)
            self.entries.append(
                {
                    "source_event_id": sid,
                    "event_url": item.get("event_url"),
                    "event_name": item.get("event_name"),
                    "event_date": item.get("event_date"),
                    "promotion_name": item.get("promotion_name"),
                    "list_page_number": page_number,
                    "list_position": item.get("list_position") or position,
                    "action": decision.action,
                    "reason": decision.reason,
                }
            )
        if counts[WORK]:
            self.last_work_page = page_number
            self.clean_streak = 0
        else:
            self.clean_streak += 1
        newest = max(dates) if dates else None
        if self.horizon is not None and newest is not None and newest < self.horizon:
            self.passed_horizon = True
        self.pages.append(
            {
                "page": page_number,
                "items": sum(counts.values()),
                **counts,
                "newest_date": newest.isoformat() if newest else None,
                "oldest_date": min(dates).isoformat() if dates else None,
            }
        )

    def should_stop(self) -> bool:
        if self.stop_reason:
            return True
        if self.horizon is not None and self.passed_horizon and self.clean_streak >= self.settle_pages:
            self.stop_reason = "settled_beyond_horizon"
        elif len(self.pages) >= self.max_pages:
            self.stop_reason = "max_pages_cap"
        return self.stop_reason is not None

    def mark_exhausted(self) -> None:
        self.stop_reason = self.stop_reason or "listing_exhausted"

    def mark_interrupted(self, reason: str) -> None:
        self.interrupted = reason
        self.stop_reason = "interrupted"

    @property
    def complete(self) -> bool:
        return self.stop_reason in {"settled_beyond_horizon", "listing_exhausted"}

    @property
    def recommended_max_pages(self) -> int:
        if not self.pages:
            return 0
        if not self.complete:
            # Unfinished analysis: everything walked is in scope, plus one page.
            return len(self.pages) + 1
        if not self.last_work_page:
            return 0
        # One page of buffer: new events published between plan and run push
        # the listing down by a few rows.
        buffer = 0 if self.stop_reason == "listing_exhausted" else 1
        return min(self.last_work_page + buffer, len(self.pages))

    def report(
        self,
        *,
        plan_id: str,
        generated_at: str,
        data_dir: Path | str | None = None,
        event_pause_seconds: float = 0.0,
        page_pause_seconds: float = 0.0,
        fetch_seconds_estimate: float = DEFAULT_FETCH_SECONDS_ESTIMATE,
        extra: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        by_action: dict[str, int] = {action: 0 for action in ACTIONS}
        by_reason: dict[str, dict[str, int]] = {action: {} for action in ACTIONS}
        recommended = self.recommended_max_pages
        in_scope = [entry for entry in self.entries if entry["list_page_number"] <= max(recommended, 0)]
        for entry in self.entries:
            by_action[entry["action"]] += 1
            reasons = by_reason[entry["action"]]
            reasons[entry["reason"]] = reasons.get(entry["reason"], 0) + 1
        work = [entry for entry in in_scope if entry["action"] == WORK]
        work_beyond = [entry for entry in self.entries if entry["action"] == WORK and entry not in in_scope]
        unseen_targets = [
            {
                "source_event_id": sid,
                "event_date": target_date.isoformat(),
                "event_url": (self.classifier.events_index.get(sid) or {}).get("event_url"),
                "reason": self.classifier.classify_known(sid, self.classifier.events_index[sid]).reason,
                "hint": "not in the walked listing; use --force-recheck-event to fetch it directly",
            }
            for sid, target_date in sorted(self.targets.items(), key=lambda pair: pair[1])
            if sid not in self.seen
        ]
        older_open = sorted(
            (target_date.isoformat(), sid)
            for sid, target_date in self.classifier.open_targets(365_000).items()
            if sid not in self.targets and sid not in self.seen
        )
        stale = [entry for entry in self.entries if entry["action"] == STALE]
        seconds = len(work) * (fetch_seconds_estimate + event_pause_seconds) + max(recommended - 1, 0) * page_pause_seconds
        return {
            "schema_version": PLAN_SCHEMA_VERSION,
            "plan_id": plan_id,
            "generated_at": generated_at,
            "data_dir": str(data_dir) if data_dir else None,
            "complete": self.complete,
            "stop_reason": self.stop_reason,
            "interrupted_reason": self.interrupted,
            "pages_walked": len(self.pages),
            "last_work_page": self.last_work_page,
            "recommended_max_pages": recommended,
            "nothing_to_do": self.complete and not work and not unseen_targets,
            "work_events_count": len(work),
            "work_events_beyond_recommendation": len(work_beyond),
            "estimated_minutes": round(seconds / 60, 1),
            "horizon_date": self.horizon.isoformat() if self.horizon else None,
            "coverage_start_date": self.classifier.coverage_start.isoformat() if self.classifier.coverage_start else None,
            "newest_known_event_date": self.classifier.newest_known.isoformat() if self.classifier.newest_known else None,
            "open_targets_count": len(self.targets),
            "settings": {
                "max_pages": self.max_pages,
                "settle_pages": self.settle_pages,
                "lookback_days": self.lookback_days,
                "date_margin_days": self.date_margin_days,
                "stale_after_days": self.classifier.stale_after_days,
                "stale_min_rechecks": self.classifier.stale_min_rechecks,
                "scrape_matchup_pages": self.classifier.scrape_matchup_pages,
                "include_backfill": self.classifier.include_backfill,
                "event_pause_seconds": event_pause_seconds,
                "page_pause_seconds": page_pause_seconds,
                "fetch_seconds_estimate": fetch_seconds_estimate,
            },
            "counts_by_action": by_action,
            "counts_by_reason": by_reason,
            "pages": self.pages,
            "work_events": work,
            "open_targets_not_in_listing": unseen_targets,
            # Older than the lookback and not reached by the walk: picked up when
            # a deeper run passes them, or retired by the stale rule.
            "open_known_beyond_lookback": [{"event_date": d, "source_event_id": sid} for d, sid in older_open],
            "stale_events": stale,
            **(extra or {}),
        }


def format_plan_summary(plan: dict[str, Any]) -> str:
    reasons = plan.get("counts_by_reason", {}).get(WORK, {})
    reason_text = ", ".join(f"{name} {count}" for name, count in sorted(reasons.items(), key=lambda p: -p[1])) or "-"
    settled = plan.get("counts_by_action", {}).get(SETTLED, 0)
    lines = [
        "[listing-plan] ------------------------------------------------------------",
        f"[listing-plan] stop: {plan.get('stop_reason')} | complete: {plan.get('complete')} | pages walked: {plan.get('pages_walked')}",
        f"[listing-plan] horizon: {plan.get('horizon_date')} | newest known: {plan.get('newest_known_event_date')} "
        f"| coverage start: {plan.get('coverage_start_date')}",
        f"[listing-plan] recommended --max-pages: {plan.get('recommended_max_pages')} "
        f"(last page with work: {plan.get('last_work_page')})",
        f"[listing-plan] events to fetch: {plan.get('work_events_count')} (~{plan.get('estimated_minutes')} min) "
        f"| settled/skipped: {settled}",
        f"[listing-plan] why: {reason_text}",
    ]
    for page in plan.get("pages", []):
        lines.append(
            f"[listing-plan]   page {page['page']:>2}: {page['work']:>3} work / {page['items']:>3} "
            f"({page.get('newest_date')} .. {page.get('oldest_date')})"
        )
    if plan.get("open_targets_not_in_listing"):
        lines.append(
            f"[listing-plan] {len(plan['open_targets_not_in_listing'])} open known event(s) were not in the walked "
            "listing; see open_targets_not_in_listing in the plan file."
        )
    if plan.get("open_known_beyond_lookback"):
        lines.append(
            f"[listing-plan] {len(plan['open_known_beyond_lookback'])} older open event(s) lie beyond the lookback "
            "and were not reached; raise --plan-lookback-days to include them."
        )
    if plan.get("stale_events"):
        lines.append(f"[listing-plan] {len(plan['stale_events'])} long-incomplete event(s) treated as stale (not fetched).")
    if plan.get("interrupted_reason"):
        lines.append(f"[listing-plan] INTERRUPTED: {plan['interrupted_reason']}")
    elif not plan.get("complete"):
        lines.append("[listing-plan] Page cap reached before the listing settled; more work may exist deeper.")
    if plan.get("nothing_to_do"):
        lines.append("[listing-plan] Nothing to fetch: every listed event is settled.")
    if plan.get("plan_path"):
        lines.append(f"[listing-plan] plan file: {plan['plan_path']}")
    lines.append("[listing-plan] ------------------------------------------------------------")
    return "\n".join(lines)
