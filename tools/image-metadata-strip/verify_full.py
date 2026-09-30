#!/usr/bin/env python3
"""Full verification of a strip run - every stripped key, no sampling (except the rendering part).

  a  manifest   final status per key; every error / changed / uploaded-mismatch / uploading row
  b  checksums  HEAD of every stripped key: stored ETag (= MD5) and size equal what the job verified
  c  pixels     every stripped key: the backup and the object as stored NOW (fresh GET, its SHA-256
                must equal the manifest) decoded the way a browser shows them, compared by shown size
                and SHA-256 of the decoded pixels (decode_worker.php in the app image, nice'd, 1 CPU each)
  d  rendering  puzzle_medium + puzzle_large through cdn.* (origin, no Cloudflare) for ~1,000 random
                stripped keys + every non-JPEG one; a cache-busting query makes nginx miss, so imgproxy
                renders from the stripped object; HTTP 200 + an image that decodes

Writes <run-dir>/verify-<part>.csv and prints a summary. Streaming: download -> compare -> delete.
Usage: verify_full.py --run-dir DIR --part a|b|c|d [--threads N] [--keys FILE]
"""
import argparse
import csv
import hashlib
import json
import os
import random
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

from mspexif import BUCKET, WORKDIR, s3_client

APP_IMAGE = 'ghcr.io/myspeedpuzzling/website:main'
SHM = '/dev/shm/msp-verify'
ATTENTION = ('error', 'changed', 'uploaded-mismatch', 'uploading')


def load(run_dir):
    """Final row per key + keys whose PUT may have landed although the final row is not 'stripped'."""
    final, uploaded = {}, {}
    for r in csv.DictReader(open(os.path.join(run_dir, 'manifest.csv'))):
        final[r['key']] = r
        if r['status'] in ('uploading', 'stripped', 'uploaded-mismatch'):
            uploaded[r['key']] = r
    return final, uploaded


class Worker:
    """One decode_worker.php in its own container (1 CPU, nice 19, no network)."""

    def __init__(self, run_dir):
        self.proc = subprocess.Popen(
            ['docker', 'run', '--rm', '-i', '--network', 'none', '--cpus', '1', '-m', '4g',
             '-v', f'{WORKDIR}:{WORKDIR}:ro', '-v', f'{SHM}:{SHM}:ro',
             '--entrypoint', 'nice', APP_IMAGE, '-n', '19', 'php', f'{WORKDIR}/decode_worker.php'],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, bufsize=1,
        )
        self.lock = threading.Lock()

    def ask(self, line):
        with self.lock:
            self.proc.stdin.write(line + '\n')
            self.proc.stdin.flush()
            answer = self.proc.stdout.readline()
        if not answer:
            raise RuntimeError('decode worker died')
        return json.loads(answer)

    def close(self):
        try:
            self.proc.stdin.close()
            self.proc.wait(timeout=30)
        except Exception:  # noqa: BLE001
            self.proc.kill()


def backup_of(row):
    return row['backup'] if os.path.isabs(row['backup']) else os.path.join(WORKDIR, row['backup'])


def part_a(run_dir):
    final, uploaded = load(run_dir)
    counts = {}
    for r in final.values():
        counts[r['status']] = counts.get(r['status'], 0) + 1
    print('final status per key:', dict(sorted(counts.items())))
    attention = [r for r in final.values() if r['status'] in ATTENTION]
    landed_then_failed = [k for k, r in final.items() if r['status'] not in ('stripped', 'clean') and k in uploaded]
    with open(os.path.join(run_dir, 'verify-a.csv'), 'w', newline='') as f:
        w = csv.writer(f)
        w.writerow(['key', 'status', 'detail'])
        for r in attention:
            w.writerow([r['key'], r['status'], r['detail']])
    print(f'{len(attention)} keys need attention (listed in verify-a.csv); '
          f'{len(landed_then_failed)} of them had a PUT announced')
    for r in attention[:50]:
        print(f"  {r['status']:18s} {r['key']}  {r['detail'][:160]}")


def part_b(run_dir, threads, only):
    final, uploaded = load(run_dir)
    keys = [k for k, r in uploaded.items() if not only or k in only]
    s3 = s3_client(max_pool=threads + 8)
    out = open(os.path.join(run_dir, 'verify-b.csv'), 'w', newline='')
    w = csv.writer(out)
    w.writerow(['key', 'ok', 'final_status', 'stored_etag', 'new_etag', 'stored_size', 'new_size', 'detail'])
    lock, stats = threading.Lock(), {'ok': 0, 'bad': 0}

    def check(key):
        r = uploaded[key]
        try:
            h = s3.head_object(Bucket=BUCKET, Key=key)
            ok = (h['ETag'] == r['new_etag'] and str(h['ContentLength']) == r['new_size']
                  and h.get('ContentType') == r['content_type'])
            row = [key, ok, final[key]['status'], h['ETag'], r['new_etag'], h['ContentLength'], r['new_size'],
                   '' if ok else ('still the original' if h['ETag'] == r['orig_etag'] else 'differs')]
        except Exception as e:  # noqa: BLE001
            row = [key, False, final[key]['status'], '', r['new_etag'], '', r['new_size'], str(e)[:160]]
        with lock:
            w.writerow(row)
            stats['ok' if row[1] is True else 'bad'] += 1

    started = time.time()
    with ThreadPoolExecutor(threads) as ex:
        list(ex.map(check, keys))
    out.close()
    print(f'b: {len(keys)} keys HEADed in {time.time() - started:.0f}s: {stats}')


def part_c(run_dir, threads, only):
    final, uploaded = load(run_dir)
    # Every key whose PUT went out - including one whose job was killed right after it (a later
    # pass then found the object clean): its bytes must be the ones announced and verified
    keys = [k for k in uploaded if not only or k in only]
    final = uploaded
    os.makedirs(SHM, exist_ok=True)
    s3 = s3_client(max_pool=threads + 8)
    workers = [Worker(run_dir) for _ in range(threads)]
    free = list(workers)
    pool_lock = threading.Lock()
    out = open(os.path.join(run_dir, 'verify-c.csv'), 'w', newline='')
    w = csv.writer(out)
    w.writerow(['key', 'ok', 'bytes_match', 'before_size', 'after_size', 'before_sig', 'after_sig', 'detail'])
    lock = threading.Lock()
    stats = {'ok': 0, 'bad': 0, 'bytes': 0}
    started = time.time()

    def check(key):
        r = final[key]
        with pool_lock:
            worker = free.pop()
        path = f'{SHM}/{threading.get_ident()}{os.path.splitext(key)[1][:8]}'
        try:
            body = s3.get_object(Bucket=BUCKET, Key=key)['Body'].read()
            bytes_match = hashlib.sha256(body).hexdigest() == r['new_sha256']
            with open(path, 'wb') as f:
                f.write(body)
            res = worker.ask(f"C|{key}|{backup_of(r)}|{path}")
            ok = bool(res.get('ok')) and bytes_match
            b, a = res.get('before', {}), res.get('after', {})
            row = [key, ok, bytes_match, b.get('size'), a.get('size'), b.get('signature', '')[:16],
                   a.get('signature', '')[:16], res.get('error', '')]
            n = len(body)
        except Exception as e:  # noqa: BLE001
            row, n = [key, False, '', '', '', '', '', f'{type(e).__name__}: {str(e)[:160]}'], 0
        finally:
            if os.path.exists(path):
                os.unlink(path)
            with pool_lock:
                free.append(worker)
        with lock:
            w.writerow(row)
            stats['ok' if row[1] is True else 'bad'] += 1
            stats['bytes'] += n
            done = stats['ok'] + stats['bad']
            if done % 1000 == 0:
                out.flush()
                el = time.time() - started
                print(f'c: {done}/{len(keys)} {done / el:.1f} keys/s {stats["bytes"] / el / 1e6:.0f} MB/s '
                      f'ok={stats["ok"]} bad={stats["bad"]}', flush=True)

    with ThreadPoolExecutor(threads) as ex:
        list(ex.map(check, keys))
    for worker in workers:
        worker.close()
    out.close()
    el = time.time() - started
    print(f'c: {len(keys)} keys in {el:.0f}s ({len(keys) / el:.1f} keys/s, {stats["bytes"] / 1e9:.1f} GB read): '
          f'ok={stats["ok"]} bad={stats["bad"]}', flush=True)


def part_d(run_dir, threads, only):
    final, _ = load(run_dir)
    stripped = [r for r in final.values() if r['status'] == 'stripped' and (not only or r['key'] in only)]
    jpeg = [r['key'] for r in stripped if r['file_type'] == 'JPEG']
    other = [r['key'] for r in stripped if r['file_type'] != 'JPEG']
    rnd = random.Random(20260930)
    keys = (rnd.sample(jpeg, min(1000, len(jpeg))) if not only else jpeg) + other
    os.makedirs(SHM, exist_ok=True)
    workers = [Worker(run_dir) for _ in range(2)]
    stamp = str(int(time.time()))
    out = open(os.path.join(run_dir, 'verify-d.csv'), 'w', newline='')
    w = csv.writer(out)
    w.writerow(['key', 'file_type', 'preset', 'ok', 'http', 'content_type', 'bytes', 'decoded', 'detail'])
    lock = threading.Lock()
    stats = {'ok': 0, 'bad': 0}
    started = time.time()

    def check(i_key):
        i, key = i_key
        worker = workers[i % len(workers)]
        for preset in ('puzzle_medium', 'puzzle_large'):
            url = (f'https://cdn.myspeedpuzzling.com/preset:{preset}/plain/'
                   f'{urllib.parse.quote(key, safe="/")}?verify={stamp}')
            path = f'{SHM}/render-{threading.get_ident()}'
            try:
                req = urllib.request.Request(url, headers={'User-Agent': 'msp-exif-verify/2026-09-30'})
                with urllib.request.urlopen(req, timeout=90) as resp:
                    body, status, ctype = resp.read(), resp.status, resp.headers.get('Content-Type', '')
                open(path, 'wb').write(body)
                res = worker.ask(f'D|{key}|{path}')
                ok = status == 200 and ctype.startswith('image/') and bool(res.get('ok'))
                row = [key, final[key]['file_type'], preset, ok, status, ctype, len(body),
                       f"{res.get('format', '')} {res.get('size', '')}", res.get('error', '')]
            except Exception as e:  # noqa: BLE001
                row = [key, final[key]['file_type'], preset, False, getattr(e, 'code', ''), '', '', '',
                       f'{type(e).__name__}: {str(e)[:160]}']
            finally:
                if os.path.exists(path):
                    os.unlink(path)
            with lock:
                w.writerow(row)
                stats['ok' if row[3] is True else 'bad'] += 1

    with ThreadPoolExecutor(threads) as ex:
        list(ex.map(check, list(enumerate(keys))))
    for worker in workers:
        worker.close()
    out.close()
    print(f'd: {len(keys)} keys ({min(1000, len(jpeg))} random JPEG + {len(other)} non-JPEG) x 2 presets '
          f'in {time.time() - started:.0f}s: {stats}', flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--run-dir', required=True)
    ap.add_argument('--part', required=True, choices=['a', 'b', 'c', 'd'])
    ap.add_argument('--threads', type=int, default=6)
    ap.add_argument('--keys')
    a = ap.parse_args()
    only = {k.strip() for k in open(a.keys) if k.strip()} if a.keys else set()
    {'a': lambda: part_a(a.run_dir), 'b': lambda: part_b(a.run_dir, a.threads, only),
     'c': lambda: part_c(a.run_dir, a.threads, only), 'd': lambda: part_d(a.run_dir, a.threads, only)}[a.part]()


if __name__ == '__main__':
    sys.exit(main())
