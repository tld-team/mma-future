#!/usr/bin/env bash
# Installed outside code releases; caller supplies a private JSON configuration.
set -euo pipefail
ops_config="${1:?Private config required}"
ops_root="$(dirname "$ops_config")"
exec 9>"$ops_root/supervisor.lock"
flock -n 9 || exit 0
[[ ! -f "$ops_root/maintenance.flag" ]] || exit 0
readarray -t ops_values < <(python3 - "$ops_config" <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
for key in ['wp_root','php','wp_cli']:print(c[key])
PY
)
export MMAF_OPERATIONS_DIR="$ops_root"
ops_end=$((SECONDS+50))
while (( SECONDS<ops_end )); do
 [[ ! -f "$ops_root/maintenance.flag" ]] || break
 ops_worked=false
 for ops_command in 'mmaf import-job work --once' 'mmaf operations work' 'mmaf data-process work --limit=10'; do
  # Fixed command words, never user input. Results contain counts/status only.
  read -r -a ops_args <<< "$ops_command"
  ops_result=$("${ops_values[1]}" -d memory_limit=512M "${ops_values[2]}" --path="${ops_values[0]}" "${ops_args[@]}")
  if [[ "$ops_result" == *'"worked":true'* ]]; then ops_worked=true; fi
  (( SECONDS<ops_end )) || break
 done
 [[ "$ops_worked" == true ]] || break
done
"${ops_values[1]}" -d memory_limit=512M "${ops_values[2]}" --path="${ops_values[0]}" eval 'update_option("mmaf_operations_heartbeat",gmdate("Y-m-d H:i:s"),false);' >/dev/null
