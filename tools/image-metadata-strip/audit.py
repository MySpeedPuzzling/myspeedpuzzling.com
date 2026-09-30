#!/usr/bin/env python3
"""READ-ONLY metadata audit of the myspeedpuzzling bucket (2026-09-30).

For every original (not thumbnails/, not players/*/results/ unless sampled) it downloads what
exiftool needs to see all metadata and records one CSV row:
  - JPEG: the first 128 KB (EXIF/XMP/IPTC/MPF live in the APP segments before the first scan),
          2 MB when the header runs longer;
  - anything else: the whole object (PNG text chunks and WebP EXIF/XMP may follow the image
          data, HEIC keeps its Exif item wherever iloc points).
Nothing is written to the bucket. Kinds come from the DB columns that reference the key
(db-image-refs.tsv); unreferenced keys are labelled by prefix.

Usage: audit.py OUT.csv [--sample-results N] [--sample-thumbnails N] [--limit N] [--keys FILE]
"""
import argparse
import csv
import os
import random
import struct
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor

from mspexif import BUCKET, WORKDIR, classify, exiftool, s3_client

KIND_PRIORITY = [
    'puzzle.image', 'puzzle_solving_time.finished_puzzle_photo', 'player.avatar', 'manufacturer.logo',
    'competition.logo', 'competition_series.logo', 'puzzle_change_request.proposed_image',
    'puzzle_change_request.original_image', 'oauth2_client_request.logo_path',
]
HEAD = 128 * 1024
BIG_HEAD = 2 * 1024 * 1024
TMP = '/dev/shm/msp-exif-audit'


def prefix_kind(key):
    if key.startswith('thumbnails/'):
        return 'unreferenced:thumbnails/'
    if '/results/' in key:
        return 'unreferenced:players/*/results/'
    if key.startswith('players/'):
        return 'unreferenced:players/* (photos)'
    if '/' in key:
        return 'unreferenced:' + key.split('/')[0] + '/'
    if key.startswith('proposal-'):
        return 'unreferenced:proposal-*'
    return 'unreferenced:(root)'


def load_refs(path):
    refs = {}
    for line in open(path):
        parts = line.rstrip('\n').split('|', 2)
        if len(parts) == 3:
            refs.setdefault(parts[2], set()).add(parts[0])
    return refs


def jpeg_header_complete(data):
    """True when the APP/COM segments up to the first SOS are all inside data."""
    i, n = 2, len(data)
    while i + 4 <= n:
        if data[i] != 0xFF:
            return True  # not a marker - corrupt or odd; exiftool will say so
        m = data[i + 1]
        if m == 0xFF:
            i += 1
            continue
        if m == 0xDA:  # SOS
            return True
        if m in (0xD8, 0x01) or 0xD0 <= m <= 0xD7:
            i += 2
            continue
        (length,) = struct.unpack('>H', data[i + 2:i + 4])
        i += 2 + length
    return False


def fetch(s3, key, size):
    """Returns (bytes, partial?)."""
    if size <= HEAD:
        return s3.get_object(Bucket=BUCKET, Key=key)['Body'].read(), False
    head = s3.get_object(Bucket=BUCKET, Key=key, Range=f'bytes=0-{HEAD - 1}')['Body'].read()
    if head[:2] == b'\xff\xd8':
        if jpeg_header_complete(head):
            return head, True
        if size <= BIG_HEAD:
            return s3.get_object(Bucket=BUCKET, Key=key)['Body'].read(), False
        return s3.get_object(Bucket=BUCKET, Key=key, Range=f'bytes=0-{BIG_HEAD - 1}')['Body'].read(), True
    return head + s3.get_object(Bucket=BUCKET, Key=key, Range=f'bytes={HEAD}-')['Body'].read(), False


FIELDS = ['key', 'kind', 'kinds', 'size', 'last_modified', 'format', 'needs_strip', 'gps', 'exif',
          'exif_private', 'xmp', 'iptc', 'comment', 'secondary', 'orientation', 'make', 'model', 'date',
          'has_icc', 'partial', 'reasons', 'warnings', 'error']


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('out')
    ap.add_argument('--refs', default=WORKDIR + '/db-image-refs.tsv')
    ap.add_argument('--sample-results', type=int, default=0)
    ap.add_argument('--sample-thumbnails', type=int, default=0)
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--keys', help='file with one key per line: audit only these')
    ap.add_argument('--threads', type=int, default=24)
    a = ap.parse_args()

    os.makedirs(TMP, exist_ok=True)
    s3 = s3_client(max_pool=a.threads + 8)
    refs = load_refs(a.refs)

    objects = []
    for page in s3.get_paginator('list_objects_v2').paginate(Bucket=BUCKET):
        for o in page.get('Contents', []):
            objects.append((o['Key'], o['Size'], o['LastModified'].strftime('%Y-%m-%d')))
    print(f'listed {len(objects)} objects', flush=True)

    if a.keys:
        wanted = {line.strip() for line in open(a.keys) if line.strip()}
        todo = [o for o in objects if o[0] in wanted]
    else:
        originals = [o for o in objects if not o[0].startswith('thumbnails/') and '/results/' not in o[0]
                     and o[1] > 0 and not o[0].endswith('/')]
        rnd = random.Random(20260930)
        results = [o for o in objects if '/results/' in o[0]]
        thumbs = [o for o in objects if o[0].startswith('thumbnails/')]
        todo = originals
        if a.sample_results:
            todo += rnd.sample(results, min(a.sample_results, len(results)))
        if a.sample_thumbnails:
            todo += rnd.sample(thumbs, min(a.sample_thumbnails, len(thumbs)))
    if a.limit:
        todo = todo[:a.limit]
    print(f'auditing {len(todo)} objects', flush=True)

    out = open(a.out, 'w', newline='')
    wr = csv.DictWriter(out, fieldnames=FIELDS)
    wr.writeheader()
    lock = threading.Lock()
    done = [0]
    started = time.time()

    def work(item):
        key, size, modified = item
        kinds = refs.get(key, set())
        kind = next((k for k in KIND_PRIORITY if k in kinds), None) or prefix_kind(key)
        if '/results/' in key:
            kind = 'sample:players/*/results/'
        elif key.startswith('thumbnails/'):
            kind = 'sample:thumbnails/'
        row = {'key': key, 'kind': kind, 'kinds': ';'.join(sorted(kinds)), 'size': size,
               'last_modified': modified}
        tmp = f'{TMP}/{threading.get_ident()}{os.path.splitext(key)[1][:6]}'
        try:
            data, partial = fetch(s3, key, size)
            with open(tmp, 'wb') as f:
                f.write(data)
            verdict = classify(exiftool().tags(tmp, fast=partial))
            row.update({k: verdict[k] for k in ('format', 'needs_strip', 'gps', 'exif', 'exif_private',
                                                 'xmp', 'iptc', 'comment', 'secondary', 'orientation',
                                                 'make', 'model', 'date', 'has_icc')})
            row['partial'] = partial
            row['reasons'] = ' '.join(verdict['reasons'][:12])
            row['warnings'] = ' | '.join(verdict['warnings'][:3])
        except Exception as e:  # noqa: BLE001 - one bad object must not stop the audit
            row['error'] = str(e)[:200]
        finally:
            if os.path.exists(tmp):
                os.unlink(tmp)
        with lock:
            wr.writerow(row)
            done[0] += 1
            if done[0] % 2000 == 0:
                out.flush()
                rate = done[0] / (time.time() - started)
                print(f'{done[0]}/{len(todo)} {rate:.0f}/s', flush=True)

    with ThreadPoolExecutor(a.threads) as ex:
        list(ex.map(work, todo))
    out.close()
    print(f'DONE {done[0]} in {time.time() - started:.0f}s', flush=True)


if __name__ == '__main__':
    sys.exit(main())
