#!/usr/bin/env bash
# Compatibility entry point for the same managed supervisor and shared lock.
set -euo pipefail
ops_config="${1:?Private config required}"
ops_code_dir="$(cd -- "$(dirname -- "$0")" && pwd)"
exec python3 "$ops_code_dir/supervisor.py" --config "$ops_config"
