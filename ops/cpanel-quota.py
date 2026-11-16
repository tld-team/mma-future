#!/usr/bin/env python3
"""Print cPanel account quota as capacity.json evidence for the monitor's quota_command.

The figures come from cPanel's own `uapi Quota get_quota_info`, the same source as
cPanel Statistics. cPanel reports an unlimited disk or inode limit as 0 (shown as ∞
in Statistics); only that explicit value maps to unlimited. Any missing, malformed
or failed response exits non-zero with no output, so the previous evidence simply
expires and releases stay blocked instead of assuming free space.
"""
import json, math, shutil, subprocess, sys, time

FALLBACK_UAPI = ('/usr/local/cpanel/bin/uapi', '/usr/bin/uapi')


def number(data, key):
    value = data.get(key)
    if isinstance(value, bool) or value is None:
        raise ValueError('cPanel quota field missing: ' + key)
    value = float(value)
    if not math.isfinite(value) or value < 0:
        raise ValueError('cPanel quota field invalid: ' + key)
    return value


def evidence(response, now=None):
    result = response.get('result') if isinstance(response, dict) else None
    if not isinstance(result, dict) or result.get('status') != 1 or result.get('errors'):
        raise ValueError('cPanel quota request failed')
    data = result.get('data')
    if not isinstance(data, dict):
        raise ValueError('cPanel quota data missing')
    out = {'source': 'cpanel_uapi', 'checked_at': time.time() if now is None else now}
    megabyte_limit, megabytes_used = number(data, 'megabyte_limit'), number(data, 'megabytes_used')
    if megabyte_limit == 0:
        out['bytes_unlimited'] = True
    else:
        out['limit_bytes'] = int(megabyte_limit * 1024 * 1024)
        out['used_bytes'] = int(megabytes_used * 1024 * 1024)
    inode_limit, inodes_used = number(data, 'inode_limit'), number(data, 'inodes_used')
    if inode_limit == 0:
        out['inodes_unlimited'] = True
    else:
        out['limit_inodes'] = int(inode_limit)
        out['used_inodes'] = int(inodes_used)
    return out


def uapi_path(explicit=None):
    candidates = [explicit] if explicit else [shutil.which('uapi'), *FALLBACK_UAPI]
    for candidate in candidates:
        if candidate and shutil.which(candidate):
            return candidate
    raise FileNotFoundError('cPanel uapi was not found')


def main(argv):
    explicit = argv[1] if len(argv) > 1 else None
    try:
        raw = subprocess.check_output([uapi_path(explicit), 'Quota', 'get_quota_info', '--output=json'], timeout=45, stderr=subprocess.PIPE)
        print(json.dumps(evidence(json.loads(raw))))
    except (OSError, subprocess.SubprocessError, ValueError) as error:
        print('cPanel quota unavailable: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
