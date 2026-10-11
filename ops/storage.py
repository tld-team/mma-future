"""Private, streaming backup/media primitives shared by release and retention."""
import datetime
import gzip
import hashlib
import json
import os
import shutil
import tempfile
from pathlib import Path


def atomic(path, value, mode=0o600):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as output:
            json.dump(value, output, sort_keys=True)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def capacity(root, quota=None, additional=0, additional_inodes=0):
    disk = shutil.disk_usage(root)
    stat = os.statvfs(root)
    quota = quota or {}
    checked = quota.get('checked_at', 0)
    now = datetime.datetime.now(datetime.timezone.utc).timestamp()
    fresh = isinstance(checked, (int, float)) and 0 <= now - checked <= 3600
    bytes_known = fresh and (quota.get('bytes_unlimited') is True or
                            (isinstance(quota.get('limit_bytes'), int) and isinstance(quota.get('used_bytes'), int)))
    inodes_known = fresh and (quota.get('inodes_unlimited') is True or
                             (isinstance(quota.get('limit_inodes'), int) and isinstance(quota.get('used_inodes'), int)))
    account_free = None if not bytes_known else (disk.free if quota.get('bytes_unlimited') else max(0, quota['limit_bytes'] - quota['used_bytes']))
    inode_free = None if not inodes_known else (stat.f_favail if quota.get('inodes_unlimited') else max(0, quota['limit_inodes'] - quota['used_inodes']))
    reserve = max(1024 ** 3, int(additional * .2))
    required = additional + reserve
    return {'checked_at': now, 'filesystem_free': disk.free,
            'filesystem_used_percent': round(100 * disk.used / disk.total, 1),
            'account_free': account_free, 'account_inode_free': inode_free,
            'account_used_percent': (None if not bytes_known or quota.get('bytes_unlimited') or not quota['limit_bytes'] else round(100 * quota['used_bytes'] / quota['limit_bytes'], 1)),
            'account_inode_used_percent': (None if not inodes_known or quota.get('inodes_unlimited') or not quota['limit_inodes'] else round(100 * quota['used_inodes'] / quota['limit_inodes'], 1)),
            'required_bytes': required, 'required_inodes': additional_inodes,
            'ok': bytes_known and inodes_known and min(disk.free, account_free) >= required
                  and min(stat.f_favail, inode_free) >= additional_inodes,
            'reason': None if bytes_known and inodes_known else 'Account quota is unknown or stale.'}


def check_sql(path, expected=None):
    path = Path(path)
    checksum = digest(path)
    if expected and checksum != expected:
        raise ValueError('Backup checksum mismatch')
    tables = 0
    size = 0
    # Bounded reads support long extended INSERT lines; EOF validates gzip CRC.
    with gzip.open(path, 'rb') as source:
        tail = b''
        needle = b'\nCREATE TABLE '
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            size += len(chunk)
            value = tail + chunk
            tables += value.count(needle)
            if size == len(chunk) and value.startswith(b'CREATE TABLE '): tables += 1
            tail = value[-(len(needle)-1):]
    if not tables or size < 1024:
        raise ValueError('SQL export is empty or incomplete')
    return {'sha256': checksum, 'sql_bytes': size, 'compressed_bytes': path.stat().st_size, 'tables': tables}


class MediaStore:
    """Immutable content-addressed copies; manifests retain attachment file paths."""
    def __init__(self, root):
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)
        self.root.chmod(0o700)

    def capture(self, uploads):
        uploads = Path(uploads).resolve()
        files = {}
        if not uploads.exists():
            return files
        for directory, folders, names in os.walk(uploads, followlinks=False):
            folders[:] = sorted(name for name in folders if not (Path(directory) / name).is_symlink())
            for name in sorted(names):
                source = Path(directory) / name
                if source.is_symlink() or not source.is_file():
                    raise ValueError('Media symlinks and special files are not supported')
                key = source.relative_to(uploads).as_posix()
                checksum = digest(source)
                target = self.root / checksum[:2] / checksum
                target.parent.mkdir(parents=True, exist_ok=True)
                if not target.exists():
                    temporary = target.with_suffix('.pending')
                    shutil.copyfile(source, temporary)
                    temporary.chmod(0o600)
                    if digest(temporary) != checksum:
                        raise ValueError('Media changed while capturing backup')
                    temporary.replace(target)
                elif digest(target) != checksum:
                    raise ValueError('Existing media backup object is damaged')
                files[key] = {'sha256': checksum, 'bytes': source.stat().st_size}
        return files

    def restore(self, manifest, destination):
        destination = Path(destination).resolve()
        for name, metadata in manifest.items():
            path = Path(name)
            if path.is_absolute() or '..' in path.parts or '\\' in name:
                raise ValueError('Unsafe media path')
            target = destination / path
            if target.resolve() != target or target.is_symlink():
                raise ValueError('Media destination contains a symlink')
            source = self.root / metadata['sha256'][:2] / metadata['sha256']
            if digest(source) != metadata['sha256'] or source.stat().st_size != metadata['bytes']:
                raise ValueError('Media backup object is missing or damaged')
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
            target.chmod(0o644)


class BackupCatalog:
    def __init__(self, root):
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)
        self.root.chmod(0o700)

    def register(self, path, manifest):
        path = Path(path).resolve()
        manifest = dict(manifest, path=str(path), format=1)
        identity = hashlib.sha256(str(path).encode()).hexdigest()
        atomic(self.root / (identity + '.json'), manifest)
        return manifest

    def read(self):
        return [json.loads(path.read_text()) for path in sorted(self.root.glob('*.json'))]

    def retention(self, now=None):
        now = now or datetime.datetime.now(datetime.timezone.utc)
        records = self.read()
        records.sort(key=lambda r: r['created_at'], reverse=True)
        keep = set()
        # Distinct calendar periods. A held/risk backup is protected independently.
        for unit, count in [('day', 7), ('week', 4), ('month', 3)]:
            periods = set()
            for item in records:
                if not item.get('verified'):
                    continue
                stamp = datetime.datetime.fromisoformat(item['created_at'].replace('Z', '+00:00'))
                period = stamp.date() if unit == 'day' else stamp.isocalendar()[:2] if unit == 'week' else (stamp.year, stamp.month)
                if period not in periods and len(periods) < count:
                    periods.add(period)
                    keep.add(item['path'])
        # A same-format/schema verified replacement must have an actual restore proof.
        restorable = {(item.get('format'), item.get('sql_format','native-v1'), item.get('schema')) for item in records
                      if item.get('verified') and item.get('restore_verified_at') and item['path'] in keep}
        result = []
        for item in records:
            held = bool(item.get('holds'))
            eligible = (item['path'] not in keep and not held and item.get('verified') and
                        (item.get('format'), item.get('sql_format','native-v1'), item.get('schema')) in restorable)
            result.append({'path': item['path'], 'eligible': bool(eligible),
                           'reason': 'retained_period' if item['path'] in keep else 'reference_hold' if held else
                           'verified_replacement' if eligible else 'restore_proof_required'})
        return result
