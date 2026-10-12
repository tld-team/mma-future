#!/usr/bin/env bash
# Upload marker last. FTPS is mandatory; certificates are verified by curl.
set -euo pipefail
ops_package="${1:?Package required}"
ops_marker="${ops_package%.zip}.ready"
: "${FTP_SERVER:?}" "${FTP_USERNAME:?}" "${FTP_PASSWORD:?}" "${FTP_INCOMING_DIRECTORY:?}"
ops_base="ftp://${FTP_SERVER}:${FTP_PORT:-21}/${FTP_INCOMING_DIRECTORY#/}"
ops_base="${ops_base%/}/"
for ops_file in "$ops_package" "$ops_marker"; do
 curl --fail --silent --show-error --ssl-reqd --ftp-create-dirs --retry 3 --connect-timeout 20 --max-time 600 --user "${FTP_USERNAME}:${FTP_PASSWORD}" --upload-file "$ops_file" "$ops_base$(basename "$ops_file")"
done
