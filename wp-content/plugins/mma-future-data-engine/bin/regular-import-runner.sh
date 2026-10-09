#!/usr/bin/env bash
# Cron: * * * * * bash /path/to/plugin/bin/regular-import-runner.sh /path/to/wordpress /usr/local/bin/php /usr/local/bin/wp >> /private/path/import-worker.log 2>&1
set -eu
wp_root="${1:?WordPress root required}"
php_bin="${2:?PHP CLI path required}"
wp_cli="${3:?WP CLI path required}"
# Kernel lock is released even if the supervisor is killed. PHP also locks each step.
exec 9<"$0"
flock -n 9 || exit 0
end=$((SECONDS + 50))
while (( SECONDS < end )); do
    result=$("$php_bin" -d memory_limit=512M -d max_execution_time=0 "$wp_cli" --path="$wp_root" mmaf import-job work --once)
    case "$result" in
        *'"worked":true'*) printf '%s\n' "$result" ;;
        *)
            # The same supervisor also prepares stored profiles and one coalesced draft.
            result=$("$php_bin" -d memory_limit=512M -d max_execution_time=0 "$wp_cli" --path="$wp_root" mmaf data-process work --limit=10)
            case "$result" in
                *'"worked":true'*) printf '%s\n' "$result" ;;
                *) break ;;
            esac
            ;;
    esac
done
"$php_bin" -d memory_limit=512M "$wp_cli" --path="$wp_root" mmaf import-job cleanup >/dev/null
