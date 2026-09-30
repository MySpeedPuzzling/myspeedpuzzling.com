#!/usr/bin/env python3
"""Fetches two fresh imgproxy renderings of every key from the ORIGIN (cdn.*, no Cloudflare):
a 400 px WebP (= puzzle_medium) and a 1200 px JPEG (= puzzle_large). The query string only makes
the nginx cache key unique - imgproxy renders from whatever is stored right now. Run once before
and once after the strip: identical bytes = identical picture, orientation included."""
import hashlib
import os
import sys
import urllib.request

keys_file, tag, out_dir = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(out_dir, exist_ok=True)
variants = {'medium.webp': 'rs:fit:400:400/el:1', 'large.jpg': 'rs:fit:1200:1200/eth:0/f:jpg'}
for i, key in enumerate(k.strip() for k in open(keys_file) if k.strip()):
    for name, opts in variants.items():
        url = f'https://cdn.myspeedpuzzling.com/{opts}/plain/{key}?render={tag}'
        req = urllib.request.Request(url, headers={'User-Agent': 'msp-exif-pilot/2026-09-30'})
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = resp.read()
            status, ctype = resp.status, resp.headers.get('Content-Type')
        path = os.path.join(out_dir, f'{i:02d}.{name}')
        open(path, 'wb').write(body)
        print(f'{i:02d} {name:12s} {status} {ctype:11s} {len(body):8d} {hashlib.sha256(body).hexdigest()[:16]} {key}')
