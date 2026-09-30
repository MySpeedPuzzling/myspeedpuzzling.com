#!/usr/bin/env python3
"""After the pilot strip: what does the public side serve now?

For every key the manifest marks stripped:
  - origin  cdn.*/original/<key>          (nginx -> imgproxy raw -> bucket, no Cloudflare)
  - edge    img.*/original/<key>?v=...     (through Cloudflare, cache-busted = what a purge yields)
  - cached  img.*/original/<key>           (the canonical URL: Cloudflare's copy until purged)
  - bytes served == the bytes the job uploaded, exiftool finds nothing identifying in them
  - imgproxy renderings before/after (render.py) byte-identical
Also writes pairs.txt (backup|served) for the pixel decode check."""
import csv
import hashlib
import os
import sys
import time
import urllib.request

from mspexif import classify, exiftool

run_dir = sys.argv[1]
rows = [r for r in csv.DictReader(open(os.path.join(run_dir, 'manifest.csv'))) if r['status'] == 'stripped']
keys = [k.strip() for k in open(os.path.join(run_dir, '..', 'pilot-keys.txt')) if k.strip()]
served_dir = os.path.join(run_dir, 'served')
os.makedirs(served_dir, exist_ok=True)
stamp = str(int(time.time()))


def fetch(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'msp-exif-pilot/2026-09-30'})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read(), resp.headers


pairs = []
ok_all = True
for r in rows:
    key = r['key']
    i = keys.index(key)
    origin, h1 = fetch(f'https://cdn.myspeedpuzzling.com/original/{key}')
    edge, h2 = fetch(f'https://img.myspeedpuzzling.com/original/{key}?v={stamp}')
    cached, h3 = fetch(f'https://img.myspeedpuzzling.com/original/{key}')
    new, old = r['new_sha256'], r['orig_sha256']
    sha = lambda b: hashlib.sha256(b).hexdigest()  # noqa: E731
    served = os.path.join(served_dir, f'{i:02d}{os.path.splitext(key)[1]}')
    open(served, 'wb').write(origin)
    verdict = classify(exiftool().tags(served))
    renders = []
    for name in ('medium.webp', 'large.jpg'):
        before = open(os.path.join(run_dir, '..', 'render-before', f'{i:02d}.{name}'), 'rb').read()
        after = open(os.path.join(run_dir, '..', 'render-after', f'{i:02d}.{name}'), 'rb').read()
        renders.append('same' if before == after else 'DIFF')
    cached_state = 'new' if sha(cached) == new else ('OLD' if sha(cached) == old else 'other')
    ok = (sha(origin) == new and sha(edge) == new and not verdict['needs_strip'] and not verdict['gps']
          and renders == ['same', 'same'] and h1.get('Content-Type') == r['content_type'])
    ok_all &= ok
    print(f"{'OK ' if ok else 'BAD'} {i:02d} origin={'new' if sha(origin) == new else 'OLD'} "
          f"edge(busted)={'new' if sha(edge) == new else 'OLD'} {h2.get('cf-cache-status')} "
          f"edge(canonical)={cached_state} {h3.get('cf-cache-status')} type={h1.get('Content-Type')} "
          f"left={verdict['reasons'][:3]} gps={verdict['gps']} renders={renders} "
          f"{int(r['orig_size']) / 1e6:.2f}->{int(r['new_size']) / 1e6:.2f} MB {key}")
    pairs.append(f"{r['backup']}|{served}")
open(os.path.join(run_dir, 'pairs.txt'), 'w').write('\n'.join(pairs) + '\n')
print('ALL OK' if ok_all else 'SOMETHING FAILED')
