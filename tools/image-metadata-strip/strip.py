#!/usr/bin/env python3
"""Removes identifying metadata (GPS, camera, dates, XMP, IPTC, comments, MPF secondary images)
from stored originals in the myspeedpuzzling bucket, losslessly, under the SAME key.

Per object (idempotent - a clean object is only recorded, never rewritten):
  1. HEAD + GET (If-Match on the ETag it saw).
  2. exiftool verdict (mspexif.classify). Clean -> manifest row "clean", done.
  3. Backup of the original bytes to <run-dir>/backup/<key> (fsync) BEFORE anything is written.
  4. exiftool -all= keeping the ICC profile, plus the EXIF Orientation (JPEG/PNG/WebP - HEIF keeps
     its rotation in the container) and the PNG gAMA/sRGB chunks: the image data is not touched.
  5. Checks on the stripped file: nothing identifying left, no GPS, same file type, same image-data
     hash as the original (= the compressed pixels are byte-identical), same dimensions, same
     orientation. Any failure -> not uploaded, manifest row "error".
  6. HEAD again: the object must still carry the ETag we read (else "changed", skipped).
  7. PUT under the same key with the same Content-Type / Cache-Control / Content-Disposition /
     user metadata; HEAD afterwards must show the new size + MD5 ETag + same Content-Type.
Every step is recorded in <run-dir>/manifest.csv (append-only, flushed per row) - the rollback
reads it: `strip.py --rollback --run-dir DIR` puts every backup back if the object still holds
the bytes this job wrote.

Usage (on the box, from /root/msp-exif-2026-09-30):
  strip.py --run-dir DIR --keys FILE [--threads 16] [--dry-run]
  strip.py --run-dir DIR --from-audit audit.csv [--kinds k1,k2] [--include-unreferenced] [--limit N]
  strip.py --run-dir DIR --list-containing /results/     (share PNGs - classifies every one, ~420 GB read)
  strip.py --run-dir DIR --rollback [--keys FILE] [--dry-run]
A clean object costs one download; a run can be stopped (Ctrl-C / kill) and restarted with the same
--run-dir at any time - finished keys are skipped, an interrupted key is simply done again.
"""
import argparse
import base64
import csv
import hashlib
import json
import os
import shutil
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor

from botocore.exceptions import ClientError

from mspexif import BUCKET, WORKDIR, classify, exiftool, s3_client

KEEP_ORIENTATION_TYPES = {'JPEG', 'PNG', 'WEBP', 'Extended WEBP', 'Extended WEBP (lossless)', 'WEBP (lossless)'}
HEIF_TYPES = {'HEIC', 'HEIF', 'AVIF'}
MANIFEST_FIELDS = ['ts', 'key', 'status', 'detail', 'content_type', 'orig_size', 'new_size', 'orig_etag',
                   'new_etag', 'orig_sha256', 'new_sha256', 'image_data_hash', 'orientation', 'file_type',
                   'removed', 'backup', 'headers']
COPY_HEADERS = ('ContentType', 'CacheControl', 'ContentDisposition', 'ContentEncoding', 'ContentLanguage',
                'Expires')


class Run:
    def __init__(self, run_dir, dry_run):
        # Absolute, so the manifest's backup paths work from any working directory
        run_dir = os.path.abspath(run_dir)
        self.dir = run_dir
        self.dry_run = dry_run
        os.makedirs(os.path.join(run_dir, 'backup'), exist_ok=True)
        os.makedirs(os.path.join(run_dir, 'work'), exist_ok=True)
        path = os.path.join(run_dir, 'manifest.csv')
        new = not os.path.exists(path)
        self.fh = open(path, 'a', newline='')
        self.wr = csv.DictWriter(self.fh, fieldnames=MANIFEST_FIELDS)
        if new:
            self.wr.writeheader()
        self.lock = threading.Lock()
        self.counts = {}

    def record(self, row):
        row['ts'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        with self.lock:
            self.wr.writerow(row)
            self.fh.flush()
            os.fsync(self.fh.fileno())
            self.counts[row['status']] = self.counts.get(row['status'], 0) + 1


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def done_keys(run_dir):
    """Keys this run already finished (stripped/clean) - a restart skips them."""
    path = os.path.join(run_dir, 'manifest.csv')
    if not os.path.exists(path):
        return set()
    return {r['key'] for r in csv.DictReader(open(path)) if r['status'] in ('stripped', 'clean', 'dry-run')}


def backup_path(run_dir, key, data):
    path = os.path.join(run_dir, 'backup', key)
    if os.path.exists(path) and sha256(open(path, 'rb').read()) != sha256(data):
        # An older backup of this key holds different bytes - never overwrite it
        path = f'{path}.{sha256(data)[:12]}'
    return path


def write_backup(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    if os.path.exists(path) and sha256(open(path, 'rb').read()) == sha256(data):
        return
    tmp = path + '.part'
    with open(tmp, 'wb') as f:
        f.write(data)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)


def exiftool_args(verdict, work):
    # No -q: in -stay_open mode it also swallows the {ready} marker the reader waits for
    args = ['-m', '-overwrite_original', '-all=', '--icc_profile:all']
    copy_back = []
    if verdict['format'] in KEEP_ORIENTATION_TYPES and verdict['orientation'] not in (None, '', 1, '1'):
        copy_back.append('-Orientation')
    if verdict['format'] == 'PNG':
        copy_back += ['-PNG:Gamma', '-PNG:SRGBRendering']
    if copy_back:
        args += ['-tagsfromfile', '@'] + copy_back
    return args + [work]


def same_orientation(before, after, file_type):
    if file_type in HEIF_TYPES:
        return True  # HEIF rotates via irot/imir, which exiftool leaves alone (checked by the hash)
    norm = lambda v: 1 if v in (None, '', 0, '0') else int(v)  # noqa: E731 - absent == 1 (upright)
    return norm(before) == norm(after)


def process(run, s3, key):
    et = exiftool()
    row = {'key': key}
    try:
        head = s3.head_object(Bucket=BUCKET, Key=key)
    except ClientError as e:
        row.update(status='error', detail=f'head: {e}')
        return run.record(row)
    etag = head['ETag']
    headers = {h: head[h] for h in COPY_HEADERS if head.get(h) not in (None, '')}
    metadata = head.get('Metadata') or {}
    data = s3.get_object(Bucket=BUCKET, Key=key, IfMatch=etag)['Body'].read()
    row.update(content_type=head.get('ContentType', ''), orig_size=len(data), orig_etag=etag,
               orig_sha256=sha256(data),
               headers=json.dumps({'metadata': metadata, **{k: str(v) for k, v in headers.items()}}))
    if len(data) != head['ContentLength']:
        row.update(status='error', detail='short read')
        return run.record(row)

    # No extension: ExifTool refuses to write a PNG stored as "...jpeg" (it happens) when the name
    # disagrees with the content; without one it goes by the content alone
    ext = '.bin'
    work = os.path.join(run.dir, 'work', f'{threading.get_ident()}{ext}')
    orig = work + '.orig' + ext
    try:
        with open(orig, 'wb') as f:
            f.write(data)
        before_tags = et.tags(orig)
        before = classify(before_tags)
        row.update(file_type=before['format'], orientation=before['orientation'] or '')
        if not before['needs_strip']:
            row.update(status='clean')
            return run.record(row)
        row['removed'] = ' '.join(before['reasons'][:40])
        if run.dry_run:
            row.update(status='dry-run', detail='would strip')
            return run.record(row)

        # 3. backup first
        bpath = backup_path(run.dir, key, data)
        write_backup(bpath, data)
        row['backup'] = bpath

        # 4. strip a copy
        shutil.copyfile(orig, work)
        result = et.run(exiftool_args(before, work))
        if '1 image files updated' not in result:
            row.update(status='error', detail='exiftool: ' + ' '.join(result.split())[:200])
            return run.record(row)
        stripped = open(work, 'rb').read()

        # 5. checks (-ee: nothing may survive inside an embedded image either)
        after_tags = et.tags(work, embedded=True)
        after = classify(after_tags)
        h_before = et.image_data_hash(orig)
        h_after = et.image_data_hash(work)
        problems = []
        if after['needs_strip'] or after['gps']:
            problems.append('still carries: ' + ' '.join(after['reasons'][:10]))
        # (an "Extended WEBP" without EXIF/XMP legitimately becomes a plain WEBP - same MIME type)
        if after['mime'] != before['mime']:
            problems.append(f'type {before["mime"]} -> {after["mime"]}')
        if not h_before or h_before != h_after:
            problems.append(f'image data hash {h_before[:16]} -> {h_after[:16]}')
        if before_tags.get('Composite:ImageSize') != after_tags.get('Composite:ImageSize'):
            problems.append(f'size {before_tags.get("Composite:ImageSize")} -> {after_tags.get("Composite:ImageSize")}')
        if not same_orientation(before['orientation'], after['orientation'], before['format']):
            problems.append(f'orientation {before["orientation"]} -> {after["orientation"]}')
        if before['has_icc'] and not after['has_icc']:
            problems.append('lost the ICC profile')
        if len(stripped) > len(data) + 2048:
            # Only the minimal Orientation EXIF may be added back - anything bigger is not a strip
            problems.append(f'grew ({len(data)} -> {len(stripped)})')
        row.update(new_size=len(stripped), new_sha256=sha256(stripped), image_data_hash=h_after)
        if problems:
            row.update(status='error', detail='; '.join(problems))
            return run.record(row)

        # 6. still the object we read?
        now = s3.head_object(Bucket=BUCKET, Key=key)
        if now['ETag'] != etag:
            row.update(status='changed', detail=f'etag {etag} -> {now["ETag"]} while working')
            return run.record(row)

        # 7. same key, same headers - announced first, so a crash right after the PUT stays undoable
        md5 = hashlib.md5(stripped).digest()
        row['new_etag'] = '"' + md5.hex() + '"'
        run.record(dict(row, status='uploading'))
        s3.put_object(Bucket=BUCKET, Key=key, Body=stripped, Metadata=metadata,
                      ContentMD5=base64.b64encode(md5).decode(), **headers)
        check = s3.head_object(Bucket=BUCKET, Key=key)
        if (check['ContentLength'] != len(stripped) or check['ETag'].strip('"') != md5.hex()
                or check.get('ContentType') != head.get('ContentType')
                or (check.get('Metadata') or {}) != metadata):
            row.update(status='uploaded-mismatch',
                       detail=f'HEAD after PUT: {check["ContentLength"]} B {check["ETag"]} '
                              f'{check.get("ContentType")} {check.get("Metadata")}')
            return run.record(row)
        row.update(status='stripped')
        return run.record(row)
    except Exception as e:  # noqa: BLE001 - one object must never stop the run
        row.update(status='error', detail=f'{type(e).__name__}: {str(e)[:200]}')
        return run.record(row)
    finally:
        for p in (work, orig):
            if os.path.exists(p):
                os.unlink(p)


def rollback(run, s3, only):
    # Last row per key wins; "uploading" alone = the job died around its PUT - restored only if it landed
    latest = {}
    for r in csv.DictReader(open(os.path.join(run.dir, 'manifest.csv'))):
        if r['status'] in ('stripped', 'uploaded-mismatch', 'uploading'):
            latest[r['key']] = r
    rows = [r for k, r in latest.items() if not only or k in only]
    for r in rows:
        key = r['key']
        now = s3.head_object(Bucket=BUCKET, Key=key)
        if now['ETag'] != r['new_etag']:
            print(f'SKIP {key}: object changed since the strip ({now["ETag"]} != {r["new_etag"]})')
            continue
        # The pilot (run from the work dir) wrote relative backup paths
        backup = r['backup'] if os.path.isabs(r['backup']) else os.path.join(WORKDIR, r['backup'])
        data = open(backup, 'rb').read()
        if sha256(data) != r['orig_sha256']:
            print(f'SKIP {key}: backup does not match the manifest')
            continue
        saved = json.loads(r['headers'])
        metadata = saved.pop('metadata')
        headers = {k: v for k, v in saved.items() if k in ('ContentType', 'CacheControl', 'ContentDisposition',
                                                            'ContentEncoding', 'ContentLanguage')}
        if run.dry_run:
            print(f'WOULD RESTORE {key} ({len(data)} B)')
            continue
        s3.put_object(Bucket=BUCKET, Key=key, Body=data, Metadata=metadata, **headers)
        back = s3.head_object(Bucket=BUCKET, Key=key)
        ok = back['ContentLength'] == len(data) and back['ETag'].strip('"') == hashlib.md5(data).hexdigest()
        print(f'{"RESTORED" if ok else "RESTORE MISMATCH"} {key} ({len(data)} B)')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--run-dir', required=True)
    ap.add_argument('--keys')
    ap.add_argument('--from-audit')
    ap.add_argument('--kinds', help='comma list of audit kinds (default: every referenced kind)')
    ap.add_argument('--include-unreferenced', action='store_true')
    ap.add_argument('--list-containing', help='list the bucket and take every key containing this text '
                                              '(e.g. /results/ for the share PNGs the audit only sampled)')
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--threads', type=int, default=16)
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--rollback', action='store_true')
    a = ap.parse_args()

    run = Run(a.run_dir, a.dry_run)
    s3 = s3_client(max_pool=a.threads + 8)
    only = [line.strip() for line in open(a.keys) if line.strip()] if a.keys else []

    if a.rollback:
        return rollback(run, s3, set(only))

    if a.from_audit:
        kinds = set(a.kinds.split(',')) if a.kinds else None
        for r in csv.DictReader(open(a.from_audit)):
            if r['needs_strip'] != 'True' or r['error']:
                continue
            if r['kind'].startswith('sample:'):
                continue
            if r['kind'].startswith('unreferenced:') and not a.include_unreferenced:
                continue
            if kinds and r['kind'] not in kinds:
                continue
            only.append(r['key'])
    if a.list_containing:
        for page in s3.get_paginator('list_objects_v2').paginate(Bucket=BUCKET):
            only += [o['Key'] for o in page.get('Contents', []) if a.list_containing in o['Key'] and o['Size'] > 0]
    skip = done_keys(a.run_dir)
    todo = [k for k in dict.fromkeys(only) if k not in skip]
    if a.limit:
        todo = todo[:a.limit]
    print(f'{len(todo)} objects to process ({len(skip)} already done in this run dir)', flush=True)
    started = time.time()
    counter = [0]

    def work(key):
        process(run, s3, key)
        with run.lock:
            counter[0] += 1
            if counter[0] % 500 == 0:
                print(f'{counter[0]}/{len(todo)} {counter[0] / (time.time() - started):.1f}/s {run.counts}',
                      flush=True)

    with ThreadPoolExecutor(a.threads) as ex:
        list(ex.map(work, todo))
    print(f'DONE in {time.time() - started:.0f}s: {run.counts}', flush=True)


if __name__ == '__main__':
    sys.exit(main())
