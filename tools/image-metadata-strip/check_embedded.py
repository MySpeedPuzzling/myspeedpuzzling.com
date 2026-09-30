#!/usr/bin/env python3
"""Downloads what the bucket holds now for each stripped key and looks INSIDE embedded images too
(exiftool -ee: MPF secondary images, JPEG trailers, HEIF auxiliary items) + counts JPEG SOI markers
after the first EOI (a trailer image that survived would show up there)."""
import csv
import json
import os
import sys

from mspexif import BUCKET, classify, exiftool, s3_client

run_dir = sys.argv[1]
s3 = s3_client()
et = exiftool()
for r in csv.DictReader(open(os.path.join(run_dir, 'manifest.csv'))):
    if r['status'] != 'stripped':
        continue
    body = s3.get_object(Bucket=BUCKET, Key=r['key'])['Body'].read()
    path = '/dev/shm/msp-check' + os.path.splitext(r['key'])[1]
    open(path, 'wb').write(body)
    out = et.run(['-j', '-G0:1', '-a', '-n', '-ee', path])
    tags = json.loads(out)[0] if out.strip() else {}
    verdict = classify(tags)
    trailer = ''
    if body[:2] == b'\xff\xd8':
        # A trailer image (MPF gain map, depth map) starts right where the main image ends
        has_trailer = body.find(b'\xff\xd9\xff\xd8') != -1
        trailer = f' second-SOI-after-EOI={has_trailer}'
    print(f"{'CLEAN' if not verdict['needs_strip'] and not verdict['gps'] else 'LEFT '} "
          f"{verdict['reasons'][:4]} gps={verdict['gps']}{trailer} {r['key']}")
    os.unlink(path)
