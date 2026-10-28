"""Fetch the public roster.watch lists without visiting fighter profile pages."""
from __future__ import annotations

import hashlib
import json
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urlparse

from .browser import detect_hard_block, launch_browser
from .config import LATEST_DIR, RUNS_DIR, ScraperConfig
from .storage import atomic_write_json

URLS = {
    "current": "https://www.roster.watch/",
    "former": "https://www.roster.watch/former.html",
    "log": "https://www.roster.watch/log.html",
}
MINIMUM_LIVE_COUNTS = {"current": 100, "former": 500, "log_events": 100}


def _iso_date(value: str | None) -> str | None:
    if not value:
        return None
    text = value.strip()
    for pattern in ("%Y-%m-%d", "%B %d, %Y", "%b %d, %Y", "%m/%d/%Y"):
        try:
            return datetime.strptime(text, pattern).date().isoformat()
        except ValueError:
            pass
    return None


def _key(name: str, url: str | None) -> str:
    if url:
        path = urlparse(url).path.strip("/")
        if path:
            return path[:191]
    return "name:" + hashlib.sha256(name.casefold().encode()).hexdigest()[:40]


def parse_roster_rows(rows: list[dict], status: str) -> list[dict]:
    fighters = []
    name_occurrences: dict[str, int] = {}
    for raw in rows:
        name = re.sub(r"\s+", " ", str(raw.get("name") or "")).strip()
        if not name:
            continue
        url = urljoin(URLS[status], str(raw.get("url") or "")) if raw.get("url") else None
        name_key = _key(name, None)
        name_occurrences[name_key] = name_occurrences.get(name_key, 0) + 1
        source_key = _key(name, url) if url else f"roster:{status}:{name_key}:{name_occurrences[name_key]}"
        cells = [re.sub(r"\s+", " ", str(value)).strip() for value in raw.get("cells", [])]
        joined = " | ".join(cells)
        dob = _iso_date(raw.get("date_of_birth"))
        if not dob and len(cells)>2:
            match=re.search(r"\((\d{4}-\d{2}-\d{2})\)",cells[2])
            dob=_iso_date(match.group(1)) if match else None
        gender_raw = str(raw.get("gender") or (cells[3] if len(cells)>3 else ""))
        gender = "female" if ("♀" in gender_raw or re.search(r"\bwomen'?s\b|\bfemale\b", joined, re.I)) else "male" if ("♂" in gender_raw or re.search(r"\bmale\b",gender_raw,re.I)) else None
        weight = raw.get("weight_class") or (cells[4] if len(cells)>4 else None)
        fighters.append({
            "source_key": source_key, "display_name": name, "source_url": url or URLS[status],
            "date_of_birth": dob, "gender": gender, "weight_class": weight,
            "roster_status": status, "events": [], "source_cells": cells,
        })
    return fighters


def parse_log_rows(rows: list[dict]) -> list[dict]:
    events = []
    for raw in rows:
        name = re.sub(r"\s+", " ", str(raw.get("name") or "")).strip()
        text = re.sub(r"\s+", " ", str(raw.get("text") or "")).strip()
        if not name:
            continue
        lowered = text.casefold()
        action = "removed" if any(word in lowered for word in ("removed", "release", "cut", "❌")) else "added" if any(word in lowered for word in ("added", "signed", "✅")) else "unknown"
        url = urljoin(URLS["log"], str(raw.get("url") or "")) if raw.get("url") else None
        events.append({"source_key": _key(name, url), "display_name": name, "source_url": url or URLS["log"],
                       "date": _iso_date(raw.get("date")), "action": action, "text": text})
    return events


def build_document(current_rows: list[dict], former_rows: list[dict], log_rows: list[dict], *, run_id: str, scraped_at: str) -> dict:
    current = parse_roster_rows(current_rows, "current")
    former = parse_roster_rows(former_rows, "former")
    events = parse_log_rows(log_rows)
    if not current or not former or not events:
        raise ValueError("Expected current roster, former roster, and change log records")
    by_key = {}
    for row in former + current:  # current status wins if a source appears on both lists.
        existing = by_key.get(row["source_key"])
        if existing and existing["display_name"] != row["display_name"]:
            raise ValueError("A roster source identity was reused for different names")
        by_key[row["source_key"]] = row
    for event in events:
        target=event["source_key"]
        if target not in by_key:
            by_key[target]={"source_key":target,"display_name":event["display_name"],"source_url":event["source_url"],"date_of_birth":None,"gender":None,"weight_class":None,"roster_status":"log_only","events":[],"source_cells":[]}
        by_key[target]["events"].append({k: event[k] for k in ("date", "action", "text", "source_url")})
    fighters = sorted(by_key.values(), key=lambda row: (row["display_name"].casefold(), row["source_key"]))
    document = {
        "schema_version": "mmaf_ufc_roster_v2", "source": "roster.watch", "run_id": run_id,
        "scraped_at": scraped_at, "source_urls": URLS,
        "counts": {"current": len(current), "former": len(former), "log_events": len(events), "fighters": len(fighters)},
        "fighters": fighters,
    }
    canonical = json.dumps(document, ensure_ascii=False, separators=(",", ":"))
    document["content_sha256"] = hashlib.sha256(canonical.encode()).hexdigest()
    return document


def validate_live_document(document: dict, previous: dict | None = None) -> None:
    """Reject a partial response before it can replace the last good snapshot."""
    counts = document.get("counts", {})
    for key, minimum in MINIMUM_LIVE_COUNTS.items():
        if int(counts.get(key, 0)) < minimum:
            raise ValueError(f"roster.watch {key} page appears incomplete")
        prior = int((previous or {}).get("counts", {}).get(key, 0))
        if prior and int(counts[key]) < int(prior * 0.6):
            raise ValueError(f"roster.watch {key} count dropped unexpectedly")


def _extract_roster(page) -> list[dict]:
    return page.evaluate("""() => Array.from(document.querySelectorAll('tr')).map(row => {
      const cells=Array.from(row.querySelectorAll('th,td')).map(c=>c.innerText.trim());
      const link=Array.from(row.querySelectorAll('a[href]')).find(a=>/fighter/i.test(a.getAttribute('href')||''));
      if(!link) return null;
      const headers=Array.from(row.closest('table')?.querySelectorAll('thead th')||[]).map(h=>h.innerText.trim().toLowerCase());
      const value=(needle)=>{const i=headers.findIndex(h=>h.includes(needle)); return i>=0?cells[i]:null};
      return {name:link.innerText.trim(),url:link.href,cells,date_of_birth:value('birth'),gender:value('gender'),weight_class:value('weight')||value('division')};
    }).filter(Boolean)""")


def _extract_log(page) -> list[dict]:
    return page.evaluate(r"""() => {
      const out=[];
      for(const heading of document.querySelectorAll('h3')){
        let node=heading.nextSibling;
        while(node){
          if(node.nodeType===1 && /^H[1-3]$/.test(node.tagName)) break;
          const text=(node.textContent||'').trim();
          const match=text.match(/(?:✅|❌)?\s*Fighter\s+(added|removed)\s*:\s*([\s\S]+)$/i);
          if(match){
            const links=node.nodeType===1?Array.from(node.querySelectorAll('a[href]')):[];
            const link=links.find(a=>a.innerText.trim()===match[2].trim())||links[0];
            out.push({name:match[2].trim(),url:link?link.href:null,text:text,date:heading.innerText.trim()});
          }
          node=node.nextSibling;
        }
      }
      return out;
    }""")


def check_ufc_roster(*, latest_dir: Path = LATEST_DIR, config: ScraperConfig | None = None) -> dict:
    config = config or ScraperConfig()
    scraped_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    run_id = "ufc-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:8]
    run_dir = RUNS_DIR / run_id
    run_dir.mkdir(parents=True, exist_ok=False)
    playwright = browser = context = None
    try:
        playwright, browser, context = launch_browser(config)
        page = context.new_page()
        extracted = {}
        for kind, url in URLS.items():
            response = page.goto(url, wait_until="domcontentloaded", timeout=config.page_timeout_ms)
            if response is None or response.status >= 400:
                raise RuntimeError(f"roster.watch {kind} page returned an invalid response")
            block = detect_hard_block(page)
            if block:
                raise RuntimeError(f"roster.watch {kind} page was blocked: {block}")
            page.wait_for_timeout(300)
            extracted[kind] = _extract_log(page) if kind == "log" else _extract_roster(page)
        document = build_document(extracted["current"], extracted["former"], extracted["log"], run_id=run_id, scraped_at=scraped_at)
        latest_path = latest_dir / "ufc_roster.json"
        previous = json.loads(latest_path.read_text(encoding="utf-8")) if latest_path.is_file() else None
        validate_live_document(document, previous)
        atomic_write_json(run_dir / "ufc_roster.json", document)
        atomic_write_json(latest_path, document)  # publish only after every page validated
        atomic_write_json(latest_dir / "ufc_roster_status.json", {"status": "success", "run_id": run_id, "checked_at": datetime.now(timezone.utc).isoformat(), "snapshot_run_id": run_id, "error": None})
        return {"ok": True, "run_id": run_id, "output": str(latest_path), "counts": document["counts"]}
    except Exception as exc:
        failure={"status": "failed", "run_id": run_id, "checked_at": datetime.now(timezone.utc).isoformat(), "error": str(exc)}
        atomic_write_json(run_dir / "ufc_roster_failure.json", failure)
        latest_path=latest_dir / "ufc_roster.json"
        if latest_path.is_file():
            try: failure["snapshot_run_id"]=json.loads(latest_path.read_text(encoding="utf-8")).get("run_id")
            except (OSError, ValueError): failure["snapshot_run_id"]=None
        atomic_write_json(latest_dir / "ufc_roster_status.json", failure)
        raise
    finally:
        if context is not None:
            context.close()
        if browser is not None:
            browser.close()
        if playwright is not None:
            playwright.stop()
