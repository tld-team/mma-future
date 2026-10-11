#!/usr/bin/env bash
# A separate cron entry; still reports a worker that has stopped running.
set -euo pipefail
ops_config="${1:?Private config required}"
ops_root="$(dirname "$ops_config")"
exec 9>"$ops_root/monitor.lock"
flock -n 9 || exit 0
readarray -t ops_values < <(python3 - "$ops_config" <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
for key in ['wp_root','php','wp_cli']:print(c[key])
PY
)
export MMAF_OPERATIONS_DIR="$ops_root"
ops_wp=("${ops_values[1]}" -d memory_limit=512M "${ops_values[2]}" --path="${ops_values[0]}")
if ! "${ops_wp[@]}" mmaf operations monitor > "$ops_root/monitor-status.json.tmp"; then
 # Bootstrap failure cannot use wp_mail. Deduplicate the independent transport fallback.
 if [[ ! -f "$ops_root/bootstrap-alert.sent" ]] || find "$ops_root/bootstrap-alert.sent" -mmin +1440 | read -r _; then
  "${ops_values[1]}" -r 'exit(mail("luka.m@tldteam.com","[MMA Future][staging] Critical: WordPress bootstrap","Independent monitor could not load WordPress. Inspect the private release status and staging Operations page.")?0:1);' && touch "$ops_root/bootstrap-alert.sent"
 fi
 exit 1
fi
mv "$ops_root/monitor-status.json.tmp" "$ops_root/monitor-status.json"
if [[ ! -f "$ops_root/diagnostics.checked" ]] || find "$ops_root/diagnostics.checked" -mmin +60 | read -r _; then
 "${ops_wp[@]}" mmaf operations diagnostics >/dev/null && touch "$ops_root/diagnostics.checked"
fi
