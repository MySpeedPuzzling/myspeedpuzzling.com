#!/usr/bin/env python3
"""Picks <= 20 audit rows that need stripping and together cover every case the full run meets."""
import csv
import sys

rows = [r for r in csv.DictReader(open(sys.argv[1])) if r['needs_strip'] == 'True' and not r['error']]
rows.sort(key=lambda r: r['key'])
T = lambda r, f: r[f] == 'True'  # noqa: E731

cases = [
    ('finished photo, iPhone JPEG, GPS, Orientation 6, MPF',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'JPEG' and T(r, 'gps')
     and r['orientation'] == '6' and r['make'] == 'Apple' and T(r, 'secondary')),
    ('finished photo, Samsung JPEG, GPS, upright',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'JPEG' and T(r, 'gps')
     and r['orientation'] == '1' and r['make'].lower() == 'samsung'),
    ('finished photo, Pixel JPEG, GPS',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'JPEG' and T(r, 'gps')
     and r['make'] == 'Google'),
    ('finished photo, HEIC, GPS',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'HEIC' and T(r, 'gps')),
    ('finished photo, HEIC, GPS, EXIF Orientation 6',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'HEIC' and T(r, 'gps')
     and r['orientation'] == '6'),
    ('finished photo, JPEG with XMP + IPTC (edited)',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] == 'JPEG' and T(r, 'xmp')
     and T(r, 'iptc')),
    ('puzzle image, JPEG, GPS, Orientation 6',
     lambda r: r['kind'] == 'puzzle.image' and r['format'] == 'JPEG' and T(r, 'gps') and r['orientation'] == '6'),
    ('puzzle image, JPEG, GPS, upright',
     lambda r: r['kind'] == 'puzzle.image' and r['format'] == 'JPEG' and T(r, 'gps') and r['orientation'] == '1'),
    ('puzzle image, JPEG, camera EXIF without GPS, Orientation 3 or 8',
     lambda r: r['kind'] == 'puzzle.image' and r['format'] == 'JPEG' and not T(r, 'gps')
     and r['orientation'] in ('3', '8')),
    ('puzzle image, PNG with text/XMP',
     lambda r: r['kind'] == 'puzzle.image' and r['format'] == 'PNG'),
    ('puzzle image, Extended WebP with EXIF',
     lambda r: r['kind'] == 'puzzle.image' and r['format'].startswith('Extended WEBP')),
    ('puzzle image, HEIC, GPS',
     lambda r: r['kind'] == 'puzzle.image' and r['format'] == 'HEIC' and T(r, 'gps')),
    ('avatar, JPEG, GPS',
     lambda r: r['kind'] == 'player.avatar' and r['format'] == 'JPEG' and T(r, 'gps')),
    ('competition logo',
     lambda r: r['kind'] == 'competition.logo'),
    ('brand logo',
     lambda r: r['kind'] == 'manufacturer.logo'),
    ('change-request proposal',
     lambda r: r['kind'] == 'puzzle_change_request.proposed_image'),
    ('unreferenced root object, GPS',
     lambda r: r['kind'] == 'unreferenced:(root)' and T(r, 'gps')),
    ('finished photo, AVIF or PNG',
     lambda r: r['kind'] == 'puzzle_solving_time.finished_puzzle_photo' and r['format'] in ('AVIF', 'PNG')),
    ('result share image (PNG) carrying the photo\'s GPS',
     lambda r: r['kind'] == 'sample:players/*/results/' and T(r, 'gps')),
]
picked = []
for label, match in cases:
    row = next((r for r in rows if match(r) and r['key'] not in {p[1]['key'] for p in picked}), None)
    if row:
        picked.append((label, row))
    else:
        print(f'# no row for: {label}', file=sys.stderr)
with open('pilot-keys.txt', 'w') as keys, open('pilot-cases.tsv', 'w') as cases_out:
    for label, r in picked:
        keys.write(r['key'] + '\n')
        cases_out.write(f"{label}\t{r['key']}\t{r['format']}\t{r['size']}\tgps={r['gps']}\t"
                        f"orientation={r['orientation']}\t{r['make']} {r['model']}\n")
print(f'{len(picked)} pilot objects')
for label, r in picked:
    print(f"{label:60s} {r['format']:14s} {int(r['size']) / 1e6:6.2f} MB gps={r['gps']:5s} o={r['orientation']:2s} {r['make']}")
