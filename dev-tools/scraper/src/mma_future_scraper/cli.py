from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

from .config import BROWSER_CHANNELS, BROWSER_ENGINES, LATEST_DIR, ScraperConfig
from .fighter_profile_queue import (
    DEFAULT_QUEUE_PATH,
    build_fighter_profile_queue,
)
from .fighter_profile_report import write_profile_enrichment_report
from .fighter_profiles import FighterProfileEnricher
from .fighters import build_fighter_index_from_latest, format_processor_summary
from .logging_utils import log
from .models import RESULTS_SCHEMA_VERSION
from .pre_import import build_pre_import_artifacts
from .state_report import build_state_report, format_state_report, write_state_report
from .tapology import TapologyScraper
from .validation import validate_results_document


def add_browser_options(parser: argparse.ArgumentParser) -> None:
    parser.add_argument(
        "--browser-engine",
        choices=BROWSER_ENGINES,
        default="chromium",
        help="Playwright browser engine to launch (default: chromium)",
    )
    parser.add_argument(
        "--browser-channel",
        choices=BROWSER_CHANNELS,
        default=None,
        help=(
            "Optional installed Chromium browser channel to launch, such as msedge or chrome. "
            "Only valid with --browser-engine chromium."
        ),
    )
    parser.add_argument(
        "--browser-executable-path",
        type=Path,
        default=None,
        help=(
            "Optional direct path to a Chromium-family browser executable, such as Chrome or Brave. "
            "Cannot be combined with --browser-channel."
        ),
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Standalone Tapology FightCenter scraper for MMA Future")
    subparsers = parser.add_subparsers(dest="command", required=True)

    run = subparsers.add_parser("run", help="Scrape FightCenter results and/or upcoming events")
    mode = run.add_mutually_exclusive_group()
    mode.add_argument("--results-only", action="store_true", help="Scrape only FightCenter MMA results")
    mode.add_argument("--upcoming-only", action="store_true", help="Scrape only FightCenter MMA upcoming events")
    headless = run.add_mutually_exclusive_group()
    headless.add_argument("--headless", action="store_true", default=True, help="Run browser headless (default)")
    headless.add_argument("--headed", action="store_true", help="Run visible browser for debugging")
    run.add_argument("--max-pages", type=int, default=None, help="Maximum list pages to traverse")
    run.add_argument("--max-events", type=int, default=None, help="Maximum events to open")
    run.add_argument("--limit-events", type=int, default=None, help="Alias for --max-events")
    run.add_argument(
        "--mode",
        choices=["daily", "backfill"],
        default="daily",
        help="State-aware run mode: daily skips old stable known events; backfill revisits all discovered events",
    )
    run.add_argument(
        "--promotion-scope",
        choices=["all", "regional"],
        default="all",
        help="FightCenter promotion filter to use (default: all)",
    )
    run.add_argument("--sport", default="mma", help="FightCenter sport filter to use (default: mma)")
    run.add_argument(
        "--from-date",
        type=valid_iso_date,
        default=None,
        help="Collect only events whose normalized event_date is on or after YYYY-MM-DD",
    )
    run.add_argument(
        "--recheck-days",
        type=int,
        default=14,
        help="Keep recent incomplete/no-result events eligible this many days after event date",
    )
    run.add_argument(
        "--recheck-complete-days",
        type=int,
        default=0,
        help="Also recheck complete events this many days after event date (default: disabled)",
    )
    run.add_argument(
        "--recheck-known-limit",
        type=int,
        default=0,
        help="Also keep this many newest known complete events eligible (default: disabled)",
    )
    run.add_argument(
        "--stop-after-known-complete",
        type=int,
        default=20,
        help="Stop list traversal after this many consecutive known complete events outside recheck scope",
    )
    run.add_argument(
        "--force-recheck-event",
        action="append",
        default=[],
        help="Force recheck of a source_event_id or Tapology event URL; can be passed more than once",
    )
    run.add_argument("--force-full-scan", action="store_true", help="Ignore incremental skipping and scan all visible list events")
    run.add_argument(
        "--skip-known-complete",
        action="store_true",
        help="Respect state cache even during backfill and skip stable known-complete events outside recheck scope.",
    )
    run.add_argument(
        "--cumulative-results",
        action="store_true",
        help="Include cached event payloads for known skipped events in the final results output.",
    )
    run.add_argument(
        "--fresh-results",
        action="store_true",
        help="Force fresh scraping of the visible results scope and do not include cached event payloads in output.",
    )
    profile_enrichment = run.add_mutually_exclusive_group()
    profile_enrichment.add_argument(
        "--enrich-fighter-profiles",
        dest="enrich_fighter_profiles",
        action="store_true",
        default=None,
        help="Fetch Tapology fighter profiles and embed event-level profile snapshots",
    )
    profile_enrichment.add_argument(
        "--no-enrich-fighter-profiles",
        dest="enrich_fighter_profiles",
        action="store_false",
        help="Disable event-level fighter profile enrichment",
    )
    run.add_argument(
        "--tracking-decisions",
        type=Path,
        default=None,
        help="Private WordPress tracking manifest used to skip archived profile requests.",
    )
    run.add_argument("--slow-mo", type=int, default=0, help="Playwright slow motion in milliseconds")
    run.add_argument(
        "--page-pause-seconds",
        type=non_negative_float,
        default=0.0,
        help="Pause after each processed FightCenter listing page/load-more batch before advancing.",
    )
    run.add_argument(
        "--profile-pause-seconds",
        type=non_negative_float,
        default=None,
        help="Pause between fighter profile fetches; default preserves the existing short scraper delay.",
    )
    run.add_argument(
        "--event-pause-seconds",
        type=non_negative_float,
        default=0.0,
        help="Additional configured pause between event pages.",
    )
    run.add_argument(
        "--jitter-seconds",
        type=non_negative_float,
        default=0.0,
        help="Random 0..N seconds added to configured pacing pauses.",
    )
    run.add_argument(
        "--max-profile-fetches",
        type=positive_int,
        default=None,
        help="Safety cap for unique fighter profile fetch attempts; event/bout scraping continues after the cap.",
    )
    profile_cache = run.add_mutually_exclusive_group()
    profile_cache.add_argument(
        "--profile-cache-ttl-days",
        type=positive_int,
        default=7,
        help="Reuse verified source profiles for at most seven days; uncovered relevant results always force refresh.",
    )
    profile_cache.add_argument(
        "--no-profile-cache",
        dest="profile_cache_ttl_days",
        action="store_const",
        const=None,
        help="Disable persistent latest/fighter_profiles.json cache for event-level fighter profile enrichment.",
    )
    run.add_argument(
        "--profile-batch-size",
        type=positive_int,
        default=None,
        help="Pause after every N unique fighter profile fetches when paired with --profile-batch-pause-seconds.",
    )
    run.add_argument(
        "--profile-batch-pause-seconds",
        "--pause-after-profile-batch",
        dest="profile_batch_pause_seconds",
        type=non_negative_float,
        default=0.0,
        help="Duration of the optional pause after each configured profile batch.",
    )
    run.add_argument(
        "--max-consecutive-access-failures",
        type=positive_int,
        default=None,
        help="Stop safely after this many consecutive access-like failures when access degradation stopping is enabled.",
    )
    run.add_argument(
        "--max-profile-failure-rate",
        type=failure_rate_float,
        default=None,
        help="Stop safely when unique profile fetch failure rate exceeds this 0..1 value.",
    )
    run.add_argument(
        "--max-event-fetch-failures",
        type=positive_int,
        default=None,
        help="Stop safely after this many event page fetch failures when access degradation stopping is enabled.",
    )
    run.add_argument(
        "--stop-on-access-degradation",
        action="store_true",
        help="Enable conservative stop conditions for access/rate degradation instead of continuing a larger run.",
    )
    run.add_argument(
        "--load-more-post-click-wait-ms",
        type=non_negative_int,
        default=1500,
        help="Short wait after one #loadMoreButton click before checking for new unique event URLs.",
    )
    run.add_argument(
        "--load-more-wait-timeout-ms",
        type=non_negative_int,
        default=20000,
        help="Maximum wait for new unique FightCenter event URLs after a load-more click.",
    )
    run.add_argument(
        "--load-more-popup-wait-ms",
        type=non_negative_int,
        default=1500,
        help="Brief window used to catch and close an ad popup page opened by a load-more click.",
    )
    run.add_argument(
        "--load-more-overlay-check-rounds",
        type=non_negative_int,
        default=5,
        help="How many in-page overlay dismissal passes to run after a load-more click.",
    )
    run.add_argument(
        "--load-more-overlay-check-interval-ms",
        type=non_negative_int,
        default=700,
        help="Delay between in-page overlay dismissal passes after a load-more click.",
    )
    add_browser_options(run)
    run.add_argument("--debug", action="store_true", help="Write raw page excerpts and parser diagnostics to the run folder")
    run.add_argument(
        "--wait-for-manual-challenge",
        type=non_negative_int,
        default=0,
        metavar="SECONDS",
        help=(
            "When a Cloudflare/Turnstile/not-a-bot challenge is detected and --headed is set, "
            "pause for up to SECONDS so the operator can solve it manually in the visible browser. "
            "Requires --headed; ignored with a warning in headless mode."
        ),
    )
    run.add_argument(
        "--pause-on-manual-challenge",
        action="store_true",
        help=(
            "In headed mode, when a Cloudflare/Turnstile challenge is detected, stop all automated "
            "FightCenter readiness checks until the operator explicitly presses ENTER."
        ),
    )
    run.add_argument(
        "--browser-profile-dir",
        type=Path,
        default=None,
        help=(
            "Optional headed-only Playwright browser profile directory for local operator session persistence. "
            "Default runs use a fresh browser context."
        ),
    )
    run.add_argument(
        "--operator-navigates-to-fightcenter",
        action="store_true",
        help=(
            "Headed/manual mode: open the browser and wait for the operator to manually open FightCenter "
            "before any scraper readiness checks or parsing."
        ),
    )
    run.add_argument(
        "--use-current-page",
        action="store_true",
        help=(
            "Use the currently visible FightCenter listing as page 1 after readiness is confirmed; "
            "do not reset or re-apply FightCenter filters."
        ),
    )

    auth = subparsers.add_parser(
        "tapology-auth",
        help="Open a headed persistent Tapology browser profile for manual local session preparation",
    )
    auth.add_argument(
        "--browser-profile-dir",
        type=Path,
        required=True,
        help="Required local Playwright browser profile directory that will retain Tapology session state.",
    )
    auth.add_argument("--slow-mo", type=int, default=0, help="Playwright slow motion in milliseconds")
    add_browser_options(auth)

    inspect = subparsers.add_parser("inspect-filters", help="Print FightCenter filter controls and DOM option values")
    inspect_headless = inspect.add_mutually_exclusive_group()
    inspect_headless.add_argument("--headless", action="store_true", default=True)
    inspect_headless.add_argument("--headed", action="store_true")
    inspect.add_argument("--slow-mo", type=int, default=0)
    add_browser_options(inspect)

    browser_check = subparsers.add_parser(
        "browser-check",
        help="Launch a Playwright browser against a local blank page without contacting Tapology",
    )
    browser_check_headless = browser_check.add_mutually_exclusive_group()
    browser_check_headless.add_argument("--headless", action="store_true", default=True)
    browser_check_headless.add_argument("--headed", action="store_true")
    browser_check.add_argument("--slow-mo", type=int, default=0)
    add_browser_options(browser_check)

    export = subparsers.add_parser("export-import-bundle", help="Create a health-checked ZIP for WordPress regular import")
    export.add_argument("--output", type=Path, required=True)
    export.add_argument("--base-receipt",type=Path,help="Completed destination report/receipt for a delta package")
    export.add_argument("--latest-dir", type=Path, default=LATEST_DIR)
    export.add_argument("--expected-pages", type=positive_int, default=None)
    export.add_argument("--max-event-fetch-failures", type=non_negative_int, default=0)
    export.add_argument("--min-profile-success-rate", type=failure_rate_float, default=0.99)
    upcoming = subparsers.add_parser("scrape-upcoming", help="Scrape the next 1-30 days into the isolated calendar lane")
    upcoming.add_argument("--days", type=positive_int, default=10, help="Inclusive period starting today (default: 10; maximum: 30)")
    upcoming.add_argument("--timezone", default="Europe/Belgrade", help="IANA timezone used to calculate today and coverage")
    upcoming.add_argument("--data-dir", type=Path, default=None, help="Override the isolated upcoming data directory")
    upcoming_head = upcoming.add_mutually_exclusive_group()
    upcoming_head.add_argument("--headless", action="store_true", default=True)
    upcoming_head.add_argument("--headed", action="store_true")
    upcoming.add_argument("--max-pages", type=positive_int, default=None)
    upcoming.add_argument("--max-events", type=positive_int, default=None)
    upcoming.add_argument("--slow-mo", type=non_negative_int, default=0)
    upcoming.add_argument("--browser-profile-dir", type=Path, default=None)
    upcoming.add_argument("--resume-from", type=Path, default=None, help="Resume from a checkpoint_upcoming.json file")
    upcoming.add_argument("--debug", action="store_true")
    add_browser_options(upcoming)
    upcoming_export = subparsers.add_parser("export-upcoming-bundle", help="Create a mmaf_upcoming_v1 ZIP for controlled WordPress import")
    upcoming_export.add_argument("--output", type=Path, required=True)
    upcoming_export.add_argument("--data-dir", type=Path, default=None)
    ufc=subparsers.add_parser('check-ufc-roster',help='Fetch current/former UFC lists and the roster.watch change log')
    ufc_head=ufc.add_mutually_exclusive_group();ufc_head.add_argument('--headless',action='store_true',default=True);ufc_head.add_argument('--headed',action='store_true')
    ufc.add_argument('--slow-mo',type=int,default=0);ufc.add_argument('--latest-dir',type=Path,default=LATEST_DIR);add_browser_options(ufc)
    ufc_export=subparsers.add_parser('export-ufc-roster-bundle',help='Create a standalone UFC roster import ZIP')
    ufc_export.add_argument('--output',type=Path,required=True);ufc_export.add_argument('--latest-dir',type=Path,default=LATEST_DIR)
    subparsers.add_parser("validate-latest", help="Validate latest JSON files exist and have basic schema integrity")
    gate = subparsers.add_parser(
        "pre-import-check",
        help="Build data/latest/manual_review.json and daily_summary.json, then enforce the import health gate",
    )
    gate.add_argument("--expected-pages", type=positive_int, default=None, help="Required latest run pages_scanned value")
    gate.add_argument("--max-event-fetch-failures", type=non_negative_int, default=0)
    gate.add_argument("--allow-missing-cached-event-payloads", action="store_true")
    gate.add_argument("--min-profile-success-rate", type=failure_rate_float, default=0.99)
    subparsers.add_parser("state-report", help="Print a read-only local state report and write data/latest/state_report.json")
    subparsers.add_parser("build-fighter-index", help="Build local derived fighter aggregation files from data/latest/results.json")

    queue = subparsers.add_parser(
        "build-fighter-profile-queue",
        help="Build a local Tapology fighter profile enrichment queue from scraper artifacts",
    )
    queue.add_argument(
        "--include-run-history",
        action="store_true",
        help="Also collect fighter profile URLs from data/runs/*/results.json",
    )
    queue.add_argument(
        "--input-file",
        type=Path,
        default=None,
        help="Optional text or JSON file containing fighter profile URLs",
    )
    queue.add_argument(
        "--skip-already-enriched",
        action="store_true",
        help="Omit URLs already present as successful latest fighter profile enrichments",
    )
    queue.add_argument(
        "--queue-mode",
        choices=["default", "strict-daily", "recovery", "full-maintenance"],
        default="default",
        help="Profile refresh selection mode for the queue artifact",
    )
    queue.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_QUEUE_PATH,
        help="Queue JSON output path",
    )
    queue.add_argument(
        "--tracking-decisions",
        type=Path,
        default=None,
        help="Private JSON exported by `wp mmaf fighter tracking-export`; current WordPress decisions filter profile requests.",
    )

    enrich = subparsers.add_parser(
        "enrich-fighter-profiles",
        help="Prototype optional Tapology fighter profile enrichment from existing latest results",
    )
    enrich_headless = enrich.add_mutually_exclusive_group()
    enrich_headless.add_argument("--headless", action="store_true", default=True, help="Run browser headless (default)")
    enrich_headless.add_argument("--headed", action="store_true", help="Run visible browser for debugging")
    enrich.add_argument("--limit-fighters", type=positive_int, default=10, help="Maximum fighter profile pages to visit")
    enrich.add_argument("--slow-mo", type=int, default=0, help="Playwright slow motion in milliseconds")
    add_browser_options(enrich)
    enrich.add_argument("--debug", action="store_true", help="Include profile raw text excerpts in enrichment output")
    enrich.add_argument(
        "--source-results",
        type=Path,
        default=LATEST_DIR / "results.json",
        help="Results JSON to read fighter profile URLs from",
    )
    enrich.add_argument("--from-queue", action="store_true", help="Read fighter profile URLs from a queue JSON artifact")
    enrich.add_argument(
        "--queue-file",
        type=Path,
        default=DEFAULT_QUEUE_PATH,
        help="Queue JSON to read when --from-queue is used",
    )
    enrich.add_argument(
        "--skip-already-enriched",
        action="store_true",
        help="When reading a queue, skip entries marked already_enriched",
    )
    report = subparsers.add_parser(
        "build-profile-enrichment-report",
        help="Build a coverage report from a fighter profile enrichment JSON artifact",
    )
    report.add_argument(
        "--source-profiles",
        type=Path,
        default=LATEST_DIR / "fighter_profiles.json",
        help="Fighter profiles JSON to audit",
    )
    report.add_argument("--no-markdown", action="store_true", help="Skip latest/fighter_profiles_report.md")

    staging = subparsers.add_parser(
        "fight-history-staging-dry-run",
        help=(
            "Read-only classification of fighter_profiles.json history rows into staging candidates. "
            "Does not write to the WordPress canonical DB."
        ),
    )
    staging.add_argument(
        "--source-profiles",
        type=Path,
        default=LATEST_DIR / "fighter_profiles.json",
        help="Fighter profiles JSON to classify",
    )
    staging.add_argument(
        "--canonical-dump",
        type=Path,
        default=LATEST_DIR / "canonical_bouts_dump.json",
        help="Optional read-only canonical bouts dump for dedup comparison",
    )
    staging.add_argument(
        "--output-json",
        type=Path,
        default=LATEST_DIR / "fight_history_staging_dry_run.json",
        help="Output JSON path",
    )
    staging.add_argument(
        "--output-markdown",
        type=Path,
        default=LATEST_DIR / "fight_history_staging_dry_run.md",
        help="Output Markdown summary path",
    )
    staging.add_argument(
        "--no-markdown",
        action="store_true",
        help="Skip writing the Markdown summary",
    )
    staging.add_argument(
        "--sample-rows",
        type=int,
        default=25,
        help="Number of rows included in the sample_rows section of the report",
    )
    for command in [run, enrich]:
        command.add_argument('--cycle-id', help='Registered import cycle')
        command.add_argument('--cycle-task', help='Declared task, e.g. events-1 or profiles-1')
        command.add_argument('--finalize-import', action='store_true', help='Publish the ZIP automatically once all cycle tasks finish')
        command.add_argument('--import-output-dir', type=Path, default=Path('output/import-ready'))
    recovery=subparsers.add_parser('prepare-import-recovery',help='Reparse exact saved DOM snapshots and prepare targeted source enrichment')
    recovery.add_argument('--source-profiles',type=Path,required=True)
    recovery.add_argument('--snapshots-dir',type=Path,required=True)
    recovery.add_argument('--output-dir',type=Path,required=True)
    for name in ['begin-import-cycle','record-import-run','retry-import-task','finalize-import']:
        command=subparsers.add_parser(name)
        command.add_argument('--cycle-id', required=True)
        command.add_argument('--latest-dir', type=Path, default=LATEST_DIR)
        if name=='begin-import-cycle':
            command.add_argument('--event-runs',type=positive_int,default=1)
            command.add_argument('--profile-runs',type=non_negative_int,default=1)
            command.add_argument('--expected-pages',type=positive_int)
            command.add_argument('--max-event-fetch-failures',type=non_negative_int,default=0)
            command.add_argument('--min-profile-success-rate',type=failure_rate_float,default=0.99)
        elif name in ['record-import-run','retry-import-task']:
            command.add_argument('--cycle-task',required=True)
            if name=='record-import-run':command.add_argument('--run-dir',type=Path,required=True)
        else:
            command.add_argument('--output-dir',type=Path,default=Path('output/import-ready'))
            command.add_argument('--base-receipt',type=Path)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    from .import_cycle import ImportCycle, writer_lock
    latest=getattr(args,'latest_dir',LATEST_DIR)
    cycle=None; started=False
    try:
        with writer_lock(latest.parent):
            if args.command in ('begin-import-cycle','record-import-run','retry-import-task','finalize-import'):
                cycle=ImportCycle(args.cycle_id,latest)
                if args.command=='begin-import-cycle':
                    result=cycle.begin(args.event_runs,args.profile_runs,expected_pages=args.expected_pages,max_event_fetch_failures=args.max_event_fetch_failures,min_profile_success_rate=args.min_profile_success_rate)
                elif args.command=='record-import-run':
                    cycle.adopt(args.cycle_task,args.run_dir);result=cycle.read()
                elif args.command=='retry-import-task':
                    value=cycle.read();task=value['tasks'].get(args.cycle_task)
                    if value['status']!='open' or not task or task['status'] not in ('running','failed'):raise ValueError('Only an interrupted or failed task can be retried.')
                    task['status']='pending'
                    from .storage import atomic_write_json
                    atomic_write_json(cycle.path,value);result=value
                else: result=cycle.finalize(args.output_dir,json.loads(args.base_receipt.read_text()) if args.base_receipt else None)
                print(json.dumps(result,ensure_ascii=False,indent=2));return 0
            if getattr(args,'cycle_id',None):
                if not args.cycle_task:raise ValueError('--cycle-task is required with --cycle-id')
                if args.command=='run' and args.upcoming_only:raise ValueError('An import cycle event task must include results.')
                cycle=ImportCycle(args.cycle_id,latest)
                cycle.start(args.cycle_task,'events' if args.command=='run' else 'profiles');started=True
            elif getattr(args,'finalize_import',False):raise ValueError('--finalize-import requires a registered cycle')
            result=_dispatch(args)
            if cycle:
                cycle.finish(args.cycle_task,result);started=False
                if result==0 and args.finalize_import and all(t['status']=='completed' for t in cycle.read()['tasks'].values()):
                    print(json.dumps(cycle.finalize(args.import_output_dir),ensure_ascii=False,indent=2))
            return result
    except (ValueError,RuntimeError,OSError) as exc:
        if cycle and started:cycle.finish(args.cycle_task,2)
        log('error','import_pipeline_failed',error=str(exc));return 2
    except BaseException:
        if cycle and started:cycle.finish(args.cycle_task,2)
        raise


def _dispatch(args) -> int:
    if args.command == "scrape-upcoming":
        from .upcoming_calendar import scrape_upcoming
        try:
            result = scrape_upcoming(
                days=args.days,
                timezone_name=args.timezone,
                data_dir=args.data_dir,
                config_overrides={
                    "headless": not args.headed,
                    "slow_mo": args.slow_mo,
                    "max_pages": args.max_pages,
                    "max_events": args.max_events,
                    "browser_engine": args.browser_engine,
                    "browser_channel": args.browser_channel,
                    "browser_executable_path": args.browser_executable_path,
                    "browser_profile_dir": args.browser_profile_dir if args.headed else None,
                    "resume_upcoming_checkpoint": args.resume_from,
                    "debug": args.debug,
                },
            )
        except (ValueError, RuntimeError, OSError) as error:
            print(json.dumps({"successful": False, "error": str(error)}, ensure_ascii=False, indent=2))
            return 2
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    if args.command == "export-upcoming-bundle":
        from .upcoming_calendar import export_upcoming_bundle
        try:
            result = export_upcoming_bundle(args.output, data_dir=args.data_dir)
        except (ValueError, OSError) as error:
            print(json.dumps({"ready_for_import": False, "error": str(error)}, ensure_ascii=False, indent=2))
            return 2
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    if args.command == 'check-ufc-roster':
        from .ufc_roster import check_ufc_roster
        try: result=check_ufc_roster(latest_dir=args.latest_dir,config=ScraperConfig(headless=not args.headed,slow_mo=args.slow_mo,browser_engine=args.browser_engine,browser_channel=args.browser_channel,browser_executable_path=args.browser_executable_path))
        except Exception as error:
            print(json.dumps({"ok":False,"error":str(error),"status_file":str(args.latest_dir / 'ufc_roster_status.json')},ensure_ascii=False,indent=2),file=sys.stderr)
            return 2
        print(json.dumps(result,ensure_ascii=False,indent=2));return 0
    if args.command == 'export-ufc-roster-bundle':
        from .import_bundle import export_ufc_roster_bundle
        result=export_ufc_roster_bundle(args.output,latest_dir=args.latest_dir);print(json.dumps(result,ensure_ascii=False,indent=2));return 0

    if args.command=='prepare-import-recovery':
        from .import_recovery import prepare_recovery
        print(json.dumps(prepare_recovery(args.source_profiles,args.snapshots_dir,args.output_dir),ensure_ascii=False,indent=2));return 0

    if args.command == "inspect-filters":
        scraper = TapologyScraper(
            ScraperConfig(
                headless=not args.headed,
                slow_mo=args.slow_mo,
                browser_engine=args.browser_engine,
                browser_channel=args.browser_channel,
                browser_executable_path=args.browser_executable_path,
            )
        )
        controls = scraper.inspect_filters()
        print(json.dumps(controls, ensure_ascii=False, indent=2))
        return 0

    if args.command == "browser-check":
        return browser_check(
            headless=not args.headed,
            slow_mo=args.slow_mo,
            browser_engine=args.browser_engine,
            browser_channel=args.browser_channel,
            browser_executable_path=args.browser_executable_path,
        )

    if args.command == "tapology-auth":
        return tapology_auth(
            browser_profile_dir=args.browser_profile_dir,
            slow_mo=args.slow_mo,
            browser_engine=args.browser_engine,
            browser_channel=args.browser_channel,
            browser_executable_path=args.browser_executable_path,
        )

    if args.command == "export-import-bundle":
        from .import_bundle import export_import_bundle
        try:
            result = export_import_bundle(args.output, latest_dir=args.latest_dir,
                expected_pages=args.expected_pages,
                max_event_fetch_failures=args.max_event_fetch_failures,
                min_profile_success_rate=args.min_profile_success_rate,base_receipt=json.loads(args.base_receipt.read_text()) if args.base_receipt else None)
        except (ValueError, OSError) as error:
            print(json.dumps({"ready_for_import": False, "error": str(error)}, indent=2))
            return 1
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    if args.command == "validate-latest":
        return validate_latest()

    if args.command == "pre-import-check":
        daily_summary, manual_review = build_pre_import_artifacts(
            expected_pages=args.expected_pages,
            max_event_fetch_failures=args.max_event_fetch_failures,
            allow_missing_cached_event_payloads=args.allow_missing_cached_event_payloads,
            min_profile_success_rate=args.min_profile_success_rate,
        )
        print(
            json.dumps(
                {
                    "ready_for_import": daily_summary.get("ready_for_import"),
                    "daily_summary": str(LATEST_DIR / "daily_summary.json"),
                    "manual_review": str(LATEST_DIR / "manual_review.json"),
                    "manual_review_count": manual_review.get("manual_review_count"),
                    "blocking_issues": daily_summary.get("blocking_issues"),
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0 if daily_summary.get("ready_for_import") else 1

    if args.command == "state-report":
        report = build_state_report()
        path = write_state_report(report=report)
        print(format_state_report(report))
        print(f"Report written: {path}")
        return 0

    if args.command == "build-fighter-index":
        derived = build_fighter_index_from_latest()
        print(format_processor_summary(derived["processor_report"], derived.get("paths", {})))
        return 0 if not derived["processor_report"].get("validation_errors") else 1

    if args.command == "build-fighter-profile-queue":
        queue_document, report, paths = build_fighter_profile_queue(
            include_run_history=args.include_run_history,
            input_file=args.input_file,
            skip_already_enriched=args.skip_already_enriched,
            queue_mode=args.queue_mode,
            output_path=args.output,
            report_path=args.output.with_name("fighter_profile_queue_report.json"),
            tracking_decisions_path=args.tracking_decisions,
        )
        print(
            json.dumps(
                {
                    "schema_version": queue_document.get("schema_version"),
                    "queue_mode": queue_document.get("queue_mode"),
                    "queue_output": str(paths["queue"]),
                    "report_output": str(paths["report"]),
                    "total_candidates_seen": queue_document.get("total_candidates_seen"),
                    "unique_profile_urls": queue_document.get("unique_profile_urls"),
                    "already_enriched_count": queue_document.get("already_enriched_count"),
                    "refreshable_count": queue_document.get("refreshable_count"),
                    "fresh_count": queue_document.get("fresh_count"),
                    "mode_filtered_count": queue_document.get("mode_filtered_count"),
                    "tracking_skipped_count": queue_document.get("tracking_skipped_count"),
                    "tracking_skipped_by_mode": queue_document.get("tracking_skipped_by_mode"),
                    "profile_requests_before_tracking": queue_document.get("profile_requests_before_tracking"),
                    "profile_requests_after_tracking": queue_document.get("profile_requests_after_tracking"),
                    "missing_url_count": queue_document.get("missing_url_count"),
                    "duplicate_url_count": report.get("duplicate_url_count"),
                    "count_by_stale_reason": report.get("count_by_stale_reason"),
                    "sources": queue_document.get("sources"),
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0 if queue_document.get("unique_profile_urls") else 2

    if args.command == "enrich-fighter-profiles":
        enricher = FighterProfileEnricher(
            ScraperConfig(
                headless=not args.headed,
                slow_mo=args.slow_mo,
                browser_engine=args.browser_engine,
                browser_channel=args.browser_channel,
                browser_executable_path=args.browser_executable_path,
                debug=args.debug,
            )
        )
        document = enricher.run(
            limit_fighters=args.limit_fighters,
            source_results_path=args.source_results,
            from_queue=args.from_queue,
            queue_path=args.queue_file,
            skip_already_enriched=args.skip_already_enriched,
            debug=args.debug,
        )
        print(
            json.dumps(
                {
                    "schema_version": document.get("schema_version"),
                    "run_id": document.get("run_id"),
                    "profiles_total": document.get("profiles_total"),
                    "profiles_success": document.get("profiles_success"),
                    "profiles_failed": document.get("profiles_failed"),
                    "latest_profiles_total": document.get("latest_profiles_total"),
                    "latest_profiles_success": document.get("latest_profiles_success"),
                    "merge_stats": document.get("merge_stats"),
                    "run_output": str(Path("data") / "runs" / str(document.get("run_id")) / "fighter_profiles.json"),
                    "latest_output": str(LATEST_DIR / "fighter_profiles.json"),
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0 if document.get("profiles_total") else 2

    if args.command == "build-profile-enrichment-report":
        if not args.source_profiles.exists():
            log("error", "profile_enrichment_report_missing_source", path=str(args.source_profiles))
            return 2
        document = json.loads(args.source_profiles.read_text(encoding="utf-8"))
        run_id = document.get("run_id")
        run_dir = args.source_profiles.parent if run_id and args.source_profiles.parent.name == str(run_id) else None
        report, paths = write_profile_enrichment_report(
            document,
            run_dir=run_dir,
            latest_dir=LATEST_DIR,
            write_markdown=not args.no_markdown,
        )
        print(
            json.dumps(
                {
                    "schema_version": report.get("schema_version"),
                    "run_id": report.get("run_id"),
                    "profiles_total": report.get("profiles_total"),
                    "profiles_success": report.get("profiles_success"),
                    "profiles_failed": report.get("profiles_failed"),
                    "success_rate": report.get("success_rate"),
                    "run_output": str(paths.get("run_json")) if paths.get("run_json") else None,
                    "latest_output": str(paths.get("latest_json")),
                    "latest_markdown": str(paths.get("latest_markdown")) if paths.get("latest_markdown") else None,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0 if report.get("profiles_total") else 2

    if args.command == "fight-history-staging-dry-run":
        from .staging_dry_run import (
            load_canonical_dump,
            load_profiles_document,
            write_dry_run_report,
        )

        if not args.source_profiles.exists():
            log("error", "staging_dry_run_missing_source_profiles", path=str(args.source_profiles))
            return 2
        profiles_document = load_profiles_document(args.source_profiles)
        canonical_dump_path = args.canonical_dump if args.canonical_dump and args.canonical_dump.exists() else None
        canonical_dump = load_canonical_dump(canonical_dump_path)
        result = write_dry_run_report(
            profiles_document,
            canonical_dump=canonical_dump,
            canonical_dump_path=canonical_dump_path,
            output_json=args.output_json,
            output_markdown=None if args.no_markdown else args.output_markdown,
            sample_rows=args.sample_rows,
        )
        report = result["report"]
        print(
            json.dumps(
                {
                    "schema_version": report.get("schema_version"),
                    "writes_db": report.get("writes_db"),
                    "summary": report.get("summary"),
                    "canonical_dump_available": report["canonical_dump"]["available"],
                    "output_json": result["paths"]["json"],
                    "output_markdown": result["paths"]["markdown"],
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0

    if args.command == "run":
        if args.cumulative_results and args.fresh_results:
            print(
                "error: --cumulative-results cannot be combined with --fresh-results",
                file=sys.stderr,
                flush=True,
            )
            return 2
        wait_seconds = args.wait_for_manual_challenge
        pause_on_manual_challenge = bool(args.pause_on_manual_challenge)
        if wait_seconds and not args.headed:
            log(
                "warn",
                "wait_for_manual_challenge_ignored",
                reason="requires_headed_mode",
                requested_seconds=wait_seconds,
            )
            print(
                "[manual-challenge] --wait-for-manual-challenge requires --headed; "
                "ignoring the option and running in default fail-fast mode.",
                file=sys.stderr,
                flush=True,
            )
            wait_seconds = 0
        if pause_on_manual_challenge and not args.headed:
            log(
                "warn",
                "pause_on_manual_challenge_ignored",
                reason="requires_headed_mode",
            )
            print(
                "[manual-challenge] --pause-on-manual-challenge requires --headed; "
                "ignoring the option and running in default fail-fast mode.",
                file=sys.stderr,
                flush=True,
            )
            pause_on_manual_challenge = False
        browser_profile_dir = args.browser_profile_dir
        if browser_profile_dir and not args.headed:
            log(
                "warn",
                "browser_profile_dir_ignored",
                reason="requires_headed_mode",
                browser_profile_dir=str(browser_profile_dir),
            )
            print(
                "[manual-challenge] --browser-profile-dir is intended for --headed local sessions; "
                "ignoring it for this headless run.",
                file=sys.stderr,
                flush=True,
            )
            browser_profile_dir = None
        operator_navigates_to_fightcenter = bool(args.operator_navigates_to_fightcenter)
        if operator_navigates_to_fightcenter and not args.headed:
            log(
                "warn",
                "operator_navigates_to_fightcenter_ignored",
                reason="requires_headed_mode",
            )
            print(
                "[manual-challenge] --operator-navigates-to-fightcenter requires --headed; "
                "ignoring it and using the default navigation flow.",
                file=sys.stderr,
                flush=True,
            )
            operator_navigates_to_fightcenter = False
        scraper = TapologyScraper(
            ScraperConfig(
                headless=not args.headed,
                browser_engine=args.browser_engine,
                browser_channel=args.browser_channel,
                browser_executable_path=args.browser_executable_path,
                run_mode=args.mode,
                slow_mo=args.slow_mo,
                max_pages=args.max_pages,
                max_events=args.limit_events if args.limit_events is not None else args.max_events,
                promotion_scope=args.promotion_scope,
                sport=args.sport,
                from_date=args.from_date,
                recheck_days=args.recheck_days,
                recheck_complete_days=args.recheck_complete_days,
                recheck_known_limit=args.recheck_known_limit,
                stop_after_known_complete=args.stop_after_known_complete,
                force_recheck_events=tuple(args.force_recheck_event),
                force_full_scan=args.force_full_scan,
                skip_known_complete=args.skip_known_complete,
                cumulative_results=args.cumulative_results,
                fresh_results=args.fresh_results,
                enrich_fighter_profiles=args.enrich_fighter_profiles,
                tracking_decisions_path=args.tracking_decisions,
                use_profile_cache=args.profile_cache_ttl_days is not None,
                profile_cache_ttl_days=args.profile_cache_ttl_days,
                page_pause_seconds=args.page_pause_seconds,
                profile_pause_seconds=args.profile_pause_seconds,
                event_pause_seconds=args.event_pause_seconds,
                jitter_seconds=args.jitter_seconds,
                max_profile_fetches=args.max_profile_fetches,
                profile_batch_size=args.profile_batch_size,
                profile_batch_pause_seconds=args.profile_batch_pause_seconds,
                max_consecutive_access_failures=args.max_consecutive_access_failures,
                max_profile_failure_rate=args.max_profile_failure_rate,
                max_event_fetch_failures=args.max_event_fetch_failures,
                stop_on_access_degradation=args.stop_on_access_degradation,
                load_more_post_click_wait_ms=args.load_more_post_click_wait_ms,
                load_more_wait_timeout_ms=args.load_more_wait_timeout_ms,
                load_more_popup_wait_ms=args.load_more_popup_wait_ms,
                load_more_overlay_check_rounds=args.load_more_overlay_check_rounds,
                load_more_overlay_check_interval_ms=args.load_more_overlay_check_interval_ms,
                debug=args.debug,
                wait_for_manual_challenge_seconds=wait_seconds,
                pause_on_manual_challenge=pause_on_manual_challenge,
                browser_profile_dir=browser_profile_dir,
                operator_navigates_to_fightcenter=operator_navigates_to_fightcenter,
                use_current_page=bool(args.use_current_page),
            )
        )
        summary = scraper.run(results=not args.upcoming_only, upcoming=not args.results_only)
        return 0 if summary.get("successful") else 2

    return 1


def valid_iso_date(value: str) -> str:
    try:
        date.fromisoformat(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("expected YYYY-MM-DD") from exc
    return value


def positive_int(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("expected positive integer") from exc
    if parsed <= 0:
        raise argparse.ArgumentTypeError("expected positive integer")
    return parsed


def non_negative_int(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("expected non-negative integer") from exc
    if parsed < 0:
        raise argparse.ArgumentTypeError("expected non-negative integer")
    return parsed


def non_negative_float(value: str) -> float:
    try:
        parsed = float(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("expected non-negative number") from exc
    if parsed < 0:
        raise argparse.ArgumentTypeError("expected non-negative number")
    return parsed


def failure_rate_float(value: str) -> float:
    parsed = non_negative_float(value)
    if parsed > 1:
        raise argparse.ArgumentTypeError("expected number from 0 to 1")
    return parsed


def browser_check(
    *,
    headless: bool,
    slow_mo: int = 0,
    browser_engine: str = "chromium",
    browser_channel: str | None = None,
    browser_executable_path: Path | None = None,
) -> int:
    from .browser import launch_browser

    playwright = browser = context = None
    try:
        playwright, browser, context = launch_browser(
            ScraperConfig(
                headless=headless,
                slow_mo=slow_mo,
                browser_engine=browser_engine,
                browser_channel=browser_channel,
                browser_executable_path=browser_executable_path,
            )
        )
        page = context.new_page()
        page.goto(
            "data:text/html,<title>MMA%20Future%20Browser%20Check</title><h1>ok</h1>",
            wait_until="domcontentloaded",
            timeout=10_000,
        )
        title = page.title()
        page.close()
        if title != "MMA Future Browser Check":
            log("error", "browser_check_failed", reason="unexpected_title", title=title)
            return 1
        log(
            "info",
            "browser_check_ok",
            headless=headless,
            browser_engine=browser_engine,
            browser_channel=browser_channel,
            browser_executable_path=str(browser_executable_path) if browser_executable_path else None,
        )
        return 0
    except Exception as exc:
        log(
            "error",
            "browser_check_failed",
            headless=headless,
            browser_engine=browser_engine,
            browser_channel=browser_channel,
            browser_executable_path=str(browser_executable_path) if browser_executable_path else None,
            error=str(exc),
        )
        return 1
    finally:
        if context:
            context.close()
        if browser:
            browser.close()
        if playwright:
            playwright.stop()


def tapology_auth(
    *,
    browser_profile_dir: Path,
    slow_mo: int = 0,
    browser_engine: str = "chromium",
    browser_channel: str | None = None,
    browser_executable_path: Path | None = None,
) -> int:
    from .browser import launch_browser

    playwright = browser = context = None
    try:
        config = ScraperConfig(
            headless=False,
            slow_mo=slow_mo,
            browser_engine=browser_engine,
            browser_channel=browser_channel,
            browser_executable_path=browser_executable_path,
            browser_profile_dir=browser_profile_dir,
        )
        playwright, browser, context = launch_browser(config)
        page = context.new_page()
        log(
            "info",
            "tapology_auth_browser_open",
            browser_engine=browser_engine,
            browser_channel=browser_channel,
            browser_executable_path=str(browser_executable_path) if browser_executable_path else None,
            browser_profile_dir=str(browser_profile_dir),
        )
        print(
            "A headed browser has been opened with the persistent Tapology profile. "
            "Manually open https://www.tapology.com/fightcenter, complete Cloudflare if shown, "
            "and confirm the page loads normally. Press ENTER here when finished.",
            flush=True,
        )
        try:
            response = input("[tapology-auth] Press ENTER when finished, or type abort to stop: ")
        except EOFError:
            print(
                "manual_challenge_input_unavailable: terminal input is unavailable for tapology-auth",
                file=sys.stderr,
                flush=True,
            )
            log("error", "manual_challenge_input_unavailable", command="tapology-auth")
            return 2
        if response.strip().lower() in {"abort", "q", "quit", "exit"}:
            log("warn", "tapology_auth_aborted_by_operator")
            return 2
        try:
            page.close()
        except Exception:
            pass
        log("info", "tapology_auth_complete", browser_profile_dir=str(browser_profile_dir))
        return 0
    except Exception as exc:
        log("error", "tapology_auth_failed", error=str(exc), browser_profile_dir=str(browser_profile_dir))
        return 1
    finally:
        if context:
            context.close()
        if browser:
            browser.close()
        if playwright:
            playwright.stop()


def validate_latest() -> int:
    errors: list[str] = []
    for filename, schema_version in [
        ("results.json", RESULTS_SCHEMA_VERSION),
        ("changes.json", "scraper_changes_v0_1"),
        ("run_manifest.json", "scraper_run_manifest_v0_1"),
    ]:
        path = LATEST_DIR / filename
        if not path.exists():
            errors.append(f"missing:{path}")
            continue
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"invalid_json:{path}:{exc}")
            continue
        if schema_version and payload.get("schema_version") != schema_version:
            errors.append(f"bad_schema:{path}:{payload.get('schema_version')}")
        if filename == "results.json":
            event_ids = [event.get("source_event_id") or event.get("event_url") for event in payload.get("events", [])]
            if len(event_ids) != len(set(event_ids)):
                errors.append(f"duplicate_events:{path}")
            bout_ids = [
                bout.get("source_bout_id") or bout.get("bout_url")
                for event in payload.get("events", [])
                for bout in event.get("bouts", [])
            ]
            if len(bout_ids) != len(set(bout_ids)):
                errors.append(f"duplicate_bouts:{path}")
            errors.extend(f"{path}:{error}" for error in validate_results_document(payload))

    if errors:
        for error in errors:
            log("error", "validate_latest_failed", error=error)
        return 1
    build_pre_import_artifacts(write=True)
    log("info", "validate_latest_ok", latest_dir=str(LATEST_DIR))
    return 0


if __name__ == "__main__":
    sys.exit(main())
