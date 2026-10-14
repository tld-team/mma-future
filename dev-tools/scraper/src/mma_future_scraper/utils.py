from __future__ import annotations

import hashlib
import re
from datetime import datetime, timezone
from typing import Iterable
from urllib.parse import urljoin, urlsplit, urlunsplit

from dateutil import parser as date_parser

from .country_normalizer import normalize_country


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def stable_hash(value: str, length: int = 24) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:length]


def normalize_space(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def normalize_name(value: str | None) -> str:
    value = normalize_space(value).lower()
    return re.sub(r"[^a-z0-9]+", " ", value).strip()


def parse_location_parts(value: str | None) -> dict[str, str | None]:
    parts = [normalize_space(part) for part in (value or "").split(",") if normalize_space(part)]
    empty = {"city": None, "region": None, "country": None}
    if not parts:
        return empty
    match = normalize_country(parts[-1])
    country = match["canonical_name"] if match else None
    if len(parts) == 1:
        return {"city": None, "region": None, "country": country}
    if len(parts) == 2:
        if country:
            return {"city": parts[0], "region": None, "country": country}
        return {"city": parts[0], "region": parts[1], "country": None}
    return {"city": parts[0], "region": parts[1], "country": country}


def canonical_url(url: str | None) -> str | None:
    if not url:
        return None
    parts = urlsplit(url)
    path = re.sub(r"/+$", "", parts.path)
    return urlunsplit((parts.scheme.lower(), parts.netloc.lower(), path, "", ""))


def absolute_url(base_url: str, href: str | None) -> str | None:
    if not href:
        return None
    return canonical_url(urljoin(base_url, href))


def url_hash(url: str | None) -> str | None:
    url = canonical_url(url)
    return stable_hash(url) if url else None


def source_event_id_from_url(url: str | None) -> str | None:
    url = canonical_url(url)
    if not url:
        return None
    match = re.search(r"/fightcenter/events/([^/?#]+)", url)
    if not match:
        return None
    slug = match.group(1)
    number = re.match(r"(\d+)", slug)
    return f"tapology_event_{number.group(1)}" if number else f"tapology_event_{stable_hash(slug, 16)}"


def source_bout_id_from_url(url: str | None) -> str | None:
    url = canonical_url(url)
    if not url:
        return None
    match = re.search(r"/fightcenter/bouts/([^/?#]+)", url)
    if not match:
        return None
    slug = match.group(1)
    number = re.match(r"(\d+)", slug)
    return f"tapology_bout_{number.group(1)}" if number else f"tapology_bout_{stable_hash(slug, 16)}"


def source_fighter_id_from_url(url: str | None) -> str | None:
    url = canonical_url(url)
    if not url:
        return None
    parts=urlsplit(url)
    if parts.scheme not in ('http','https') or parts.netloc.lower() not in ('tapology.com','www.tapology.com'):
        return None
    url='https://www.tapology.com'+parts.path.lower()
    match = re.search(r"/fightcenter/fighters/(\d+)(?:-|$)", url)
    if match:
        return f"tapology_fighter_{match.group(1)}"
    if re.search(r"/fightcenter/fighters/[^/?#]+$", url):
        return f"tapology_fighter_url_{stable_hash(url)}"
    return None


def fallback_bout_id(
    *,
    event_url: str | None,
    event_date: str | None,
    fighter_a_name: str | None,
    fighter_b_name: str | None,
    weight_class: str | None = None,
    result_or_method: str | None = None,
) -> str:
    fighters = sorted([normalize_name(fighter_a_name), normalize_name(fighter_b_name)])
    payload = "|".join(
        [
            "tapology",
            canonical_url(event_url) or "",
            event_date or "",
            fighters[0] if fighters else "",
            fighters[1] if len(fighters) > 1 else "",
            normalize_space(weight_class).lower(),
            normalize_space(result_or_method).lower(),
        ]
    )
    return f"tapology_bout_fallback_{stable_hash(payload)}"


def parse_date_to_iso(raw: str | None) -> str | None:
    raw = normalize_space(raw)
    if not raw:
        return None
    try:
        parsed = date_parser.parse(raw, fuzzy=True, default=datetime(1900, 1, 1))
    except (ValueError, OverflowError):
        return None
    if parsed.year == 1900:
        return None
    return parsed.date().isoformat()


def first_match(patterns: Iterable[str], text: str, flags: int = re.I) -> str | None:
    for pattern in patterns:
        match = re.search(pattern, text, flags)
        if match:
            return normalize_space(match.group(1) if match.groups() else match.group(0))
    return None
