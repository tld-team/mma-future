#!/usr/bin/env bash
# Cron: * * * * * bash /path/to/plugin/bin/regular-import-runner.sh /path/to/wordpress /usr/local/bin/php /usr/local/bin/wp >> /private/path/import-worker.log 2>&1
set -eu
wp_root="${1:?WordPress root required}"
php_bin="${2:?PHP CLI path required}"
wp_cli="${3:?WP CLI path required}"
# Kernel lock is released even if the supervisor is killed. PHP also locks each step.
exec 9<"$0"
flock -n 9 || exit 0
"$php_bin" -d memory_limit=512M -d max_execution_time=0 "$wp_cli" --path="$wp_root" mmaf background work --seconds=35 --memory-mb=256 --max-steps=500
"$php_bin" -d memory_limit=512M "$wp_cli" --path="$wp_root" mmaf import-job cleanup >/dev/null
