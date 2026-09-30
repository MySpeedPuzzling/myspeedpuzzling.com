#!/usr/bin/env python3
"""Summary tables of audit.csv: per kind, per format (GPS), per upload month (GPS), sizes."""
import collections
import csv
import re
import sys
from datetime import datetime, timezone

rows = list(csv.DictReader(open(sys.argv[1] if len(sys.argv) > 1 else 'audit.csv')))
T = lambda r, f: r[f] == 'True'  # noqa: E731


def upload_month(key):
    m = re.search(r'-(1[5-9]\d{8})(?:-[0-9a-f]{8})?\.[A-Za-z]+$', key)
    if m:
        return datetime.fromtimestamp(int(m.group(1)), timezone.utc).strftime('%Y-%m')
    m = re.search(r'(?:^|/)(0[0-9a-f]{7})-([0-9a-f]{4})-7', key)
    if m:
        ms = int(m.group(1) + m.group(2), 16)
        return datetime.fromtimestamp(ms / 1000, timezone.utc).strftime('%Y-%m')
    return 'unknown'


def table(title, groups, order=None):
    print(f'\n## {title}\n')
    print(f'{"kind":48s} {"objects":>8s} {"EXIF":>7s} {"EXIF+":>7s} {"GPS":>6s} {"XMP":>6s} {"IPTC":>5s} '
          f'{"text":>5s} {"MPF":>5s} {"strip":>7s} {"strip GB":>8s} {"err":>4s}')
    keys = order or sorted(groups, key=lambda k: -len(groups[k]))
    tot = collections.Counter()
    for k in keys:
        rs = groups[k]
        c = collections.Counter()
        for r in rs:
            c['n'] += 1
            for f in ('exif', 'exif_private', 'gps', 'xmp', 'iptc', 'comment', 'secondary', 'needs_strip'):
                if T(r, f):
                    c[f] += 1
            if T(r, 'needs_strip'):
                c['bytes'] += int(r['size'])
            if r['error']:
                c['err'] += 1
        tot.update(c)
        print(f'{k:48s} {c["n"]:8d} {c["exif"]:7d} {c["exif_private"]:7d} {c["gps"]:6d} {c["xmp"]:6d} '
              f'{c["iptc"]:5d} {c["comment"]:5d} {c["secondary"]:5d} {c["needs_strip"]:7d} '
              f'{c["bytes"] / 1e9:8.2f} {c["err"]:4d}')
    c = tot
    print(f'{"TOTAL":48s} {c["n"]:8d} {c["exif"]:7d} {c["exif_private"]:7d} {c["gps"]:6d} {c["xmp"]:6d} '
          f'{c["iptc"]:5d} {c["comment"]:5d} {c["secondary"]:5d} {c["needs_strip"]:7d} '
          f'{c["bytes"] / 1e9:8.2f} {c["err"]:4d}')


by_kind = collections.defaultdict(list)
for r in rows:
    by_kind[r['kind']].append(r)
table('Per kind (primary DB column, else bucket prefix)', by_kind)

refd = [r for r in rows if not r['kind'].startswith(('unreferenced:', 'sample:'))]
by_fmt = collections.defaultdict(list)
for r in refd:
    by_fmt[r['format'] or '(unknown)'].append(r)
table('Referenced objects per format', by_fmt)

by_month = collections.defaultdict(list)
for r in refd:
    by_month[upload_month(r['key'])].append(r)
table('Referenced objects per upload month (from the key)', by_month, order=sorted(by_month))

gps_orient = collections.Counter(r['orientation'] or '-' for r in rows if T(r, 'gps'))
print('\nOrientation among GPS objects:', dict(gps_orient))
makes = collections.Counter(r['make'] for r in refd if T(r, 'gps'))
print('Top makes among referenced GPS objects:', makes.most_common(8))
errs = [r for r in rows if r['error']]
print(f'\nErrors: {len(errs)}')
for r in errs[:10]:
    print('  ', r['key'], r['error'][:120])
warn = collections.Counter(r['warnings'][:70] for r in rows if r['warnings'])
print('Top warnings:', warn.most_common(6))
