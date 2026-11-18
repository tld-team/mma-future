#!/usr/bin/env bash
# Upload marker last. FTPS is mandatory; certificates are verified by curl.
# The staging host sometimes refuses new connections from a runner for a few minutes (seen as curl exit 28 on
# connect), so a file is retried with growing pauses before the release fails. Each attempt overwrites the file.
set -euo pipefail
ops_package="${1:?Package required}"
ops_marker="${ops_package%.zip}.ready"
: "${FTP_SERVER:?}" "${FTP_USERNAME:?}" "${FTP_PASSWORD:?}" "${FTP_INCOMING_DIRECTORY:?}"
ops_base="ftp://${FTP_SERVER}:${FTP_PORT:-21}/${FTP_INCOMING_DIRECTORY#/}"
ops_base="${ops_base%/}/"
ops_pauses=(${FTP_RETRY_PAUSES:-30 60 120 180})
for ops_file in "$ops_package" "$ops_marker"; do
 ops_attempt=0
 until curl --fail --silent --show-error --ssl-reqd --ftp-create-dirs --retry 2 --connect-timeout 30 --max-time 600 --user "${FTP_USERNAME}:${FTP_PASSWORD}" --upload-file "$ops_file" "$ops_base$(basename "$ops_file")"; do
  ops_status=$?
  # Only connection problems are worth waiting for; a refused login or a TLS failure will not fix itself.
  case "$ops_status" in 6|7|28|35|52|55|56) ;; *) echo "Upload of $(basename "$ops_file") failed with curl exit $ops_status." >&2; exit "$ops_status" ;; esac
  if [ "$ops_attempt" -ge "${#ops_pauses[@]}" ]; then
   echo "Upload of $(basename "$ops_file") still failing after $((ops_attempt + 1)) attempts (curl exit $ops_status). If this repeats, check whether the server's firewall (cPHulk, CSF/LFD) blocks GitHub Actions addresses." >&2
   exit "$ops_status"
  fi
  echo "Upload of $(basename "$ops_file") could not connect (curl exit $ops_status); trying again in ${ops_pauses[$ops_attempt]}s." >&2
  sleep "${ops_pauses[$ops_attempt]}"
  ops_attempt=$((ops_attempt + 1))
 done
done
