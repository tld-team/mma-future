"""Compile reviewed profile DOM evidence into an explicit, scoped career package.

The compiler does not contact sources, infer identities from names, or import
opponent careers. Source evidence is retained separately for review.
"""
from __future__ import annotations
import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
import hashlib, json, re, zipfile
from .fighter_profiles import parse_profile_snapshot
from .utils import source_fighter_id_from_url

RESULTS = {"win": "wins", "loss": "losses", "draw": "draws", "no_contest": "nc"}
OPPOSITE = {"win":"loss","loss":"win","draw":"draw","no_contest":"no_contest"}

def compile_history(manifest: dict, snapshots: list[dict], bout_snapshots: list[dict] | None = None) -> tuple[dict, dict]:
    by_bout_evidence = {s.get("url", "").split("/bouts/")[-1].split("-")[0]: s for s in (bout_snapshots or []) if "/fightcenter/bouts/" in s.get("url", "")}
    by_source = {}
    for snapshot in snapshots:
        source_id = source_fighter_id_from_url(snapshot.get("url",""))
        if source_id in by_source:
            raise ValueError(f"Duplicate source evidence: {source_id}")
        by_source[source_id] = snapshot
    profiles, evidence_counts, by_bout = [], Counter(), defaultdict(list)
    for target in manifest["targets"]:
        source_id = target["source_fighter_id"]
        snapshot = by_source.get(source_id)
        profile = {
            "fighter_id": int(target["fighter_id"]),
            "source_fighter_id": source_id,
            "source_url": target["source_url"],
            "display_name": target["display_name"],
            "source_status":"unavailable","rows":[],
            "expected_record":None,
            "history_scope":"unknown","history_scope_verified":False,
            "history_exhausted":False,
        }
        if snapshot:
            parsed = parse_profile_snapshot(snapshot, target["source_url"])
            explicit = any(r.get("data_division") == "pro" and r.get("data_sport") == "mma" for r in snapshot.get("rows", []))
            record = parsed.get("record_summary", {})
            expected = dict(zip(("wins","losses","draws","nc"), (record.get("wins"),record.get("losses"),record.get("draws"),record.get("no_contests"))))
            profile.update(
                scraped_at=snapshot.get("scraped_at"),
                source_status=parsed["status"],
                source_error_reason=parsed.get("error_reason"),
                expected_record=expected if all(v is not None for v in expected.values()) else None,
                history_scope="professional_mma" if explicit else parsed.get("history_scope","unknown"),
                history_scope_verified=explicit or parsed.get("history_scope_verified",False),
                history_exhausted=snapshot.get("history_exhausted") is True,
                rows=parsed.get("fight_history", []),
                verified_facts=explicit_profile_facts(snapshot, parsed.get("profile_summary", {})),
                career_record_evidence=parsed.get("career_record_evidence"),
                period_evidence=parsed.get("period_evidence"),
                parser_version=parsed.get("parser_version"),
                record_raw=record.get("pro_record_raw"),
                current_roster=parsed.get("current_roster"),
            )
            for row in profile["rows"]:
                # A solitary unlabelled record is ambiguous. Do not invent the
                # missing pre-fight record from the current profile summary.
                raw = next((r for r in snapshot.get("rows",[]) if "tapology_bout_"+str(r.get("data_bout_id","")) == row.get("source_bout_id")), {})
                if raw.get("data_division") in {"am","amateur"}:
                    row["is_amateur"] = True
                    row["is_professional"] = False
                if raw.get("data_division"):
                    row["source_division"] = raw["data_division"]
                row_text = raw.get("raw_text", row.get("raw_text", ""))
                if raw.get("data_division") or raw.get("data_sport"):
                    row["classification_evidence"] = "profile_dom_classification"
                    row["source_sport"] = raw.get("data_sport")
                if re.search(r"\brecord ineligible mma\b", row_text, re.I):
                    row["record_eligible"] = False
                    row["record_eligibility_evidence"] = "source_profile_record_ineligible_mma"
                if re.search(r"\bexhibition mma\b", row_text, re.I):
                    row["is_exhibition"] = True
                if not row.get("opponent_record_raw"):
                    row.pop("prefight_record_raw", None)
                if raw.get("data_status") == "no_contest":
                    row["final_result_verified"] = True
                    row["final_result_evidence"] = "profile_dom_final_no_contest"
                supplement_bout(row, profile, by_bout_evidence)
                if row.get("source_bout_id") and not excluded(row) and row.get("result") in RESULTS:
                    by_bout[row["source_bout_id"]].append((profile,row))
        else:
            profile["source_error_reason"]="No reviewed source snapshot was available for this target."
        profiles.append(profile)
    conflicts=[]
    for bout_id, appearances in by_bout.items():
        signatures=set()
        for profile,row in appearances:
            opponent=row.get("opponent_source_id")
            pair=sorted((profile["source_fighter_id"],opponent or ""))
            perspective=row.get("result") if pair[0] == profile["source_fighter_id"] else OPPOSITE.get(row.get("result"))
            signatures.add((tuple(pair),row.get("source_event_id"),row.get("fight_date"),perspective))
        if len(signatures)>1:
            reason="Source profiles disagree about participants, event, date or final result."
            for profile,row in appearances:
                row["source_conflict_reason"]=reason
            conflicts.append({"source_bout_id":bout_id,"fighter_ids":sorted(set(p["fighter_id"] for p,_ in appearances)),"reason":reason})
    report_profiles=[]
    for profile in profiles:
        accepted=[r for r in profile["rows"] if not excluded(r) and r.get("record_eligible") is not False and r.get("result") in RESULTS]
        totals={k:0 for k in RESULTS.values()}
        seen=set()
        for row in accepted:
            key=row.get("source_bout_id") or (row.get("fight_date"),row.get("opponent_source_id"),row.get("source_event_id"),row.get("result"))
            if key in seen: continue
            seen.add(key);totals[RESULTS[row["result"]]]+=1
        missing=[{"source_bout_id":r.get("source_bout_id"),"fight_date":r.get("fight_date"),"missing":[k for k in ("source_bout_id","source_event_id","opponent_source_id","bout_url","event_url") if not r.get(k)]} for r in accepted if any(not r.get(k) for k in ("source_bout_id","source_event_id","opponent_source_id","bout_url","event_url"))]
        evidence_counts["profiles"]+=1;evidence_counts["source_rows"]+=len(profile["rows"]);evidence_counts["professional_results"]+=len(seen);evidence_counts["rows_missing_identity_evidence"]+=len(missing)
        report_profiles.append({"fighter_id":profile["fighter_id"],"source_fighter_id":profile["source_fighter_id"],"source_status":profile["source_status"],"expected_record":profile["expected_record"],"source_record":totals,"record_matches":totals==profile["expected_record"],"missing_evidence":missing})
    return (
        {"schema_version":"mmaf_career_history_v1","source":"tapology","generated_at":datetime.now(timezone.utc).isoformat(),"target_manifest":manifest,"profiles":profiles},
        {"counts":dict(evidence_counts),"conflicts":conflicts,"profiles":report_profiles},
    )

def explicit_profile_facts(snapshot: dict, summary: dict) -> dict:
    """Approved fact fields require their own visible profile label."""
    facts = {}
    fields = {"height_cm":("height_cm",["Height"]), "height":("height",["Height"]),
              "reach_cm":("reach_cm",["Reach"]), "birthplace":("born_location",["Born Location","Birthplace","Born In","Born"]),
              "fighting_out_of":("fighting_out_of",["Fighting out of","Fights out of"]),
              "team_name":("association",["Affiliation","Association","Team","Gym"])}
    lines = snapshot.get("lines", [])
    def labelled(labels):
        for i, line in enumerate(lines):
            for label in labels:
                match = re.search(r"(?:^|\|)\s*"+re.escape(label)+r"\s*:\s*([^|]*)",line,re.I)
                if match:
                    value = match[1].strip() or (lines[i+1].strip() if i+1<len(lines) else "")
                    if value and value.lower() not in {"n/a","unknown","not available","none"} and not value.endswith(":"):
                        return {"label":label,"raw_value":value}
        return None
    for field,(key,labels) in fields.items():
        proof = labelled(labels)
        value = summary.get(key)
        if proof and value is not None:
            facts[field] = dict(proof,value=value,evidence="explicit_profile_label")
    for field, labels in {"stance":["Stance"],"coach_name":["Head Coach"]}.items():
        proof = labelled(labels)
        if proof:
            facts[field] = dict(proof,value=proof["raw_value"],evidence="explicit_profile_label")
    return facts

def supplement_bout(row: dict, profile: dict, evidence: dict) -> None:
    """Only exact bout/participant identities can supplement a profile row."""
    source_id = row.get("source_bout_id", "")
    snapshot = evidence.get(source_id.removeprefix("tapology_bout_"))
    if not snapshot:
        return
    participants = {}
    for link in snapshot.get("matchup", []):
        identity = source_fighter_id_from_url(link.get("url", ""))
        if identity and identity not in participants:
            participants[identity] = link
    own = profile["source_fighter_id"]
    if own not in participants or len(participants) != 2:
        row["bout_detail_limitation"] = "Bout page does not confirm this target and one opponent."
        return
    other = next(k for k in participants if k != own)
    if row.get("opponent_source_id") and row["opponent_source_id"] != other:
        row["source_conflict_reason"] = "Profile and bout page identify different opponents."
        return
    row["opponent_source_id"] = other
    row["opponent_url"] = participants[other]["url"]
    row["opponent_name"] = participants[other]["text"]
    row["bout_detail_url"] = snapshot["url"]
    row["bout_detail_verified_at"] = snapshot.get("scraped_at")
    events = {x["url"]:x for x in snapshot.get("event_links", []) if re.search(r"/events/[0-9]+(?:-|$)", x.get("url", ""))}
    if len(events) == 1:
        event = next(iter(events.values()))
        identity = "tapology_event_" + re.search(r"/events/([0-9]+)", event["url"])[1]
        if row.get("source_event_id") and row["source_event_id"] != identity:
            row["source_conflict_reason"] = "Profile and bout page identify different events."
            return
        row.update(source_event_id=identity, event_url=event["url"], event_name=event["text"])
    elif not row.get("source_event_id"):
        row["bout_detail_limitation"] = "The source bout page also has no linked event identity."
    header = snapshot.get("header", "")
    if "PAST MMA BOUT" in header and "Awaiting Results" in snapshot.get("body", ""):
        row["bout_detail_limitation"] = "The source bout page is still awaiting a confirmed result."
    lines = (snapshot.get("result") or "").splitlines()
    if header.startswith("COMPLETED") and lines:
        first = {"W":"win", "L":"loss", "D":"draw", "NC":"no_contest"}.get(lines[0])
        result = first if list(participants)[0] == own else OPPOSITE.get(first)
        if result:
            if row.get("result") in RESULTS and row["result"] != result:
                row["source_conflict_reason"] = "Profile and bout page report different final results."
            else:
                row.update(result=result, final_result_verified=True, final_result_evidence="exact_source_bout_page")

def excluded(row: dict) -> bool:
    return bool(row.get("is_exhibition") or row.get("is_amateur") or row.get("is_cancelled") or row.get("is_upcoming") or row.get("discipline") not in (None,"","mma") or row.get("source_division") not in (None,"","pro"))

def export_bundle(document: dict, destination: Path) -> None:
    payload=json.dumps(document,ensure_ascii=False,indent=2).encode()
    transport={"format":"mmaf-import-bundle","format_version":1,"mode":"career_history","files":{"career_history.json":{"bytes":len(payload),"sha256":hashlib.sha256(payload).hexdigest()}}}
    with zipfile.ZipFile(destination,"w",zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("career_history.json",payload)
        archive.writestr("package_manifest.json",json.dumps(transport,indent=2))

def main() -> None:
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest",type=Path,required=True)
    parser.add_argument("--snapshots",type=Path,required=True)
    parser.add_argument("--output",type=Path,required=True)
    parser.add_argument("--bout-snapshots",type=Path)
    parser.add_argument("--bundle",type=Path)
    args=parser.parse_args()
    document,report=compile_history(json.loads(args.manifest.read_text()),json.loads(args.snapshots.read_text())["snapshots"], json.loads(args.bout_snapshots.read_text())["snapshots"] if args.bout_snapshots else None)
    args.output.write_text(json.dumps(document,ensure_ascii=False,indent=2))
    args.output.with_name(args.output.stem+"-evidence-report.json").write_text(json.dumps(report,ensure_ascii=False,indent=2))
    if args.bundle: export_bundle(document,args.bundle)
    print(json.dumps(report["counts"]))

if __name__ == "__main__": main()
