"""Build a profile refresh queue for ranked fighters whose career evidence is ageing.

The ranking formula only keeps a fighter in the main list while their whole-career
profile evidence is at most 30 days old. The daily strict queue only follows new or
changed events, so a ranked fighter who does not fight for a month would never be
re-scraped. This module reads the site's public rankings REST API (read-only GET
requests) and writes an ``mmaf_source_enrichment_v1`` document that
``prepare_fighters.py --enrichment-queue`` and ``enrich-fighter-profiles --from-queue``
already accept.
"""

from __future__ import annotations

import json
import re
import ssl
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable
from urllib.parse import urlencode, urlsplit

from .fighter_profile_queue import (
    load_tracking_decisions,
    normalize_tapology_fighter_url,
    tracking_allows_profile,
    tracking_decision_for,
)
from .storage import atomic_write_json
from .utils import source_fighter_id_from_url, utc_now


RANKINGS_ENDPOINT = "/wp-json/mma-future/v1/rankings"
RANKING_VIEWS = ("main", "provisional")
MAX_PER_PAGE = 50
MAX_VERIFICATION_AGE_DAYS = 30
DEFAULT_MIN_AGE_DAYS = 20
DEFAULT_LIMIT = 60
USER_AGENT = "mma-future-scraper/ranking-refresh-queue"
# python.org macOS builds ship without a CA bundle until "Install Certificates" runs;
# fall back to the operating system bundle while keeping certificate verification on.
SYSTEM_CA_BUNDLES = ("/etc/ssl/cert.pem", "/etc/ssl/certs/ca-certificates.crt", "/etc/pki/tls/certs/ca-bundle.crt")
STALE_REASON = "ranking_evidence_refresh"
# Only numeric Tapology identities are confirmed for fetch (slug-only URLs hash to an
# unconfirmed tapology_fighter_url_* key and need identity review instead).
NUMERIC_SOURCE_ID = re.compile(r"tapology_fighter_\d+")

Fetch = Callable[[str], str]


class RankingApiError(RuntimeError):
    """The rankings API could not be read completely; no queue is written."""


def build_ranking_refresh_queue(
    *,
    site_url: str,
    output_path: Path,
    min_age_days: float = DEFAULT_MIN_AGE_DAYS,
    limit: int = DEFAULT_LIMIT,
    per_page: int = MAX_PER_PAGE,
    tracking_decisions_path: Path | None = None,
    now: datetime | None = None,
    fetch: Fetch | None = None,
    retries: int = 3,
    retry_pause_seconds: float = 2.0,
    sleep: Callable[[float], None] = time.sleep,
) -> dict[str, Any]:
    site = _site_root(site_url)
    if not 1 <= per_page <= MAX_PER_PAGE:
        raise ValueError(f"per_page must be between 1 and {MAX_PER_PAGE}")
    if limit <= 0:
        raise ValueError("limit must be positive")
    now = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    fetch = fetch or _http_get
    tracking = load_tracking_decisions(tracking_decisions_path)

    views: dict[str, dict[str, Any]] = {}
    rows: list[tuple[str, dict[str, Any]]] = []
    for view in RANKING_VIEWS:
        items, meta = _read_view(site, view, per_page=per_page, fetch=fetch, retries=retries, retry_pause_seconds=retry_pause_seconds, sleep=sleep)
        views[view] = meta
        rows.extend((view, item) for item in items)
    run_ids = {meta["active_ranking_run_id"] for meta in views.values()}
    if len(run_ids) != 1:
        raise RankingApiError(f"Main and provisional lists come from different ranking runs: {sorted(map(str, run_ids))}")

    candidates: dict[str, dict[str, Any]] = {}
    invalid_source: list[dict[str, Any]] = []
    tracking_skipped: dict[str, int] = {}
    fresh = 0
    for view, item in rows:
        career = item.get("career_record") if isinstance(item.get("career_record"), dict) else {}
        assessment = item.get("scoring_assessment") if isinstance(item.get("scoring_assessment"), dict) else {}
        url = normalize_tapology_fighter_url(career.get("source_url"))
        source_id = source_fighter_id_from_url(url)
        if not url or not NUMERIC_SOURCE_ID.fullmatch(source_id or ""):
            invalid_source.append({"fighter_id": item.get("fighter_id"), "display_name": item.get("display_name"), "view": view, "source_url": career.get("source_url")})
            continue
        decision = tracking_decision_for(tracking, source_id, url)
        if not tracking_allows_profile(decision):
            mode = str((decision or {}).get("tracking_mode") or "unknown")
            tracking_skipped[mode] = tracking_skipped.get(mode, 0) + 1
            continue
        verified_at = _parse_time(assessment.get("verified_at"))
        age_days = round((now - verified_at).total_seconds() / 86400, 2) if verified_at else None
        if age_days is not None and age_days <= min_age_days:
            fresh += 1
            continue
        reasons = [str(reason) for reason in assessment.get("reasons") or [] if reason]
        candidate = {
            "fighter_id": item.get("fighter_id"),
            "display_name": item.get("display_name"),
            "source_fighter_id": source_id,
            "source_url": url,
            "action": "refresh_source_evidence",
            "fetch_scope": "professional_mma_career",
            "force_refresh": True,
            "should_refresh": True,
            "stale_reason": STALE_REASON,
            "ranking_view": view,
            "ranking_rank": item.get("rank"),
            "verified_at": verified_at.isoformat().replace("+00:00", "Z") if verified_at else None,
            "evidence_age_days": age_days,
            "career_record": career or None,
            "reasons": reasons or ["Career evidence approaches the verification window; refresh the whole professional MMA career."],
        }
        previous = candidates.get(source_id)
        if previous is None or _sort_key(candidate) < _sort_key(previous):
            candidates[source_id] = candidate

    ordered = sorted(candidates.values(), key=_sort_key)
    selected = ordered[:limit]
    by_reason: dict[str, int] = {}
    for item in ordered:
        for reason in item["reasons"]:
            by_reason[reason] = by_reason.get(reason, 0) + 1
    document = {
        "schema_version": "mmaf_source_enrichment_v1",
        "contract_version": 1,
        "generated_at": utc_now(),
        "career_scope": "professional_mma_career_v1",
        "max_verification_age_days": MAX_VERIFICATION_AGE_DAYS,
        "scope": "ranking_evidence_refresh",
        "source": {
            "type": "public_rankings_api",
            "site_url": site,
            "endpoint": RANKINGS_ENDPOINT,
            "active_ranking_run_id": next(iter(run_ids)),
            "views": views,
        },
        "selection": {
            "reference_time": now.replace(microsecond=0).isoformat(),
            "min_age_days": min_age_days,
            "limit": limit,
            "order": "oldest_verified_at_first_missing_first",
        },
        "summary": {
            "ranked_items_seen": len(rows),
            "unique_eligible_fighters": len(candidates),
            "fresh_count": fresh,
            "selected_count": len(selected),
            "deferred_by_limit_count": len(ordered) - len(selected),
            "missing_verified_at_count": sum(1 for item in ordered if item["verified_at"] is None),
            "invalid_source_url_count": len(invalid_source),
            "tracking_decisions_revision": tracking["revision"],
            "tracking_skipped_count": sum(tracking_skipped.values()),
            "tracking_skipped_by_mode": tracking_skipped,
            "selected_by_view": {view: sum(1 for item in selected if item["ranking_view"] == view) for view in RANKING_VIEWS},
            "eligible_by_reason": dict(sorted(by_reason.items())),
        },
        "invalid_source_urls": invalid_source,
        "target_count": len(selected),
        "items": selected,
    }
    atomic_write_json(output_path, document)
    return document


def _sort_key(item: dict[str, Any]) -> tuple[int, str, int, str]:
    # Missing evidence first, then the oldest verification; main fighters before
    # provisional ones on ties because they are the ones about to drop out.
    verified = item.get("verified_at")
    return (0 if verified is None else 1, verified or "", 0 if item.get("ranking_view") == "main" else 1, item["source_fighter_id"])


def _read_view(
    site: str,
    view: str,
    *,
    per_page: int,
    fetch: Fetch,
    retries: int,
    retry_pause_seconds: float,
    sleep: Callable[[float], None],
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    items: list[dict[str, Any]] = []
    page = 1
    total_pages: int | None = None
    total: int | None = None
    run_id: Any = None
    while True:
        query = {"per_page": per_page, "page": page}
        if view != "main":
            query["view"] = view
        url = f"{site}{RANKINGS_ENDPOINT}?{urlencode(query)}"
        payload = _fetch_json(url, fetch=fetch, retries=retries, retry_pause_seconds=retry_pause_seconds, sleep=sleep)
        page_items = payload.get("items")
        if not isinstance(page_items, list) or not isinstance(payload.get("total_pages"), int):
            raise RankingApiError(f"Unexpected rankings response shape: {url}")
        if payload.get("view", view) != view:
            raise RankingApiError(f"Rankings API returned view {payload.get('view')!r} for {view!r}")
        if page == 1:
            total_pages, total, run_id = payload["total_pages"], payload.get("total"), payload.get("active_ranking_run_id")
        elif payload["total_pages"] != total_pages or payload.get("active_ranking_run_id") != run_id:
            raise RankingApiError(f"Ranking changed while reading {view} pages; run again.")
        items.extend(item for item in page_items if isinstance(item, dict))
        if page >= total_pages:
            break
        page += 1
    if isinstance(total, int) and total != len(items):
        raise RankingApiError(f"{view} list returned {len(items)} items, expected {total}.")
    return items, {"active_ranking_run_id": run_id, "total": len(items), "total_pages": total_pages or 0}


def _fetch_json(
    url: str,
    *,
    fetch: Fetch,
    retries: int,
    retry_pause_seconds: float,
    sleep: Callable[[float], None],
) -> dict[str, Any]:
    last_error: Exception | None = None
    for attempt in range(1, max(1, retries) + 1):
        try:
            return parse_api_json(fetch(url))
        except urllib.error.HTTPError as error:
            last_error = error
            if error.code not in (408, 429) and error.code < 500:
                break
        except (urllib.error.URLError, TimeoutError, ConnectionError, ValueError) as error:
            last_error = error
        if attempt < retries:
            sleep(retry_pause_seconds * attempt)
    raise RankingApiError(f"Cannot read rankings API {url}: {last_error}")


def parse_api_json(body: str) -> dict[str, Any]:
    """Decode the JSON object even when PHP notices precede it."""
    match = re.search(r'\{\s*"', body)
    if not match:
        raise ValueError("Response contains no JSON object.")
    value, _ = json.JSONDecoder().raw_decode(body, match.start())
    if not isinstance(value, dict):
        raise ValueError("Response JSON is not an object.")
    return value


def _http_get(url: str, timeout: float = 30.0) -> str:
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=timeout, context=_ssl_context()) as response:
        return response.read().decode(response.headers.get_content_charset() or "utf-8", errors="replace")


def _ssl_context() -> ssl.SSLContext:
    context = ssl.create_default_context()
    if not context.cert_store_stats().get("x509_ca"):
        for bundle in SYSTEM_CA_BUNDLES:
            if Path(bundle).is_file():
                context.load_verify_locations(cafile=bundle)
                break
    return context


def _site_root(site_url: str) -> str:
    parts = urlsplit((site_url or "").strip())
    if parts.scheme not in ("http", "https") or not parts.netloc:
        raise ValueError("--site-url must be an http(s) site address such as https://example.com")
    return f"{parts.scheme}://{parts.netloc}{parts.path.rstrip('/')}"


def _parse_time(value: Any) -> datetime | None:
    if not value or not isinstance(value, str):
        return None
    try:
        parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00").replace(" ", "T"))
    except ValueError:
        return None
    return (parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)).astimezone(timezone.utc)
