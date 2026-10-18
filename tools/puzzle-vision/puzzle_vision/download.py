"""Originals -> local cache: EXIF-oriented, alpha flattened, long side <= 1600 px, keyed by imageFingerprint (a new
picture always gets a new fingerprint, so a fingerprint is never downloaded twice). <= 4 requests/s, retries."""

from __future__ import annotations

import hashlib
import logging
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

import httpx

from . import USER_AGENT
from .imaging import UndecodableImage, decode, fit, normalise
from .workstate import WorkState

log = logging.getLogger(__name__)

CACHE_MAX_SIDE = 1600


class ImageGone(RuntimeError):
    """404 / 410 for the original - the picture was replaced or removed meanwhile."""


@dataclass
class LocalImage:
    puzzle_id: str
    fingerprint: str
    path: str
    sha256: str
    width: int
    height: int
    original_width: int
    original_height: int


class RateLimiter:
    def __init__(self, per_second: float, clock: Callable[[], float] = time.monotonic, sleep: Callable[[float], None] = time.sleep) -> None:
        self.interval = 1.0 / per_second if per_second > 0 else 0.0
        self.clock = clock
        self.sleep = sleep
        self._next = 0.0
        self._lock = threading.Lock()

    def wait(self) -> None:
        with self._lock:
            now = self.clock()
            if self._next > now:
                self.sleep(self._next - now)
                now = self._next
            self._next = now + self.interval


class Downloader:
    def __init__(self, cache_dir: Path, workstate: WorkState, per_second: float = 4.0, max_side: int = CACHE_MAX_SIDE,
                 transport: httpx.BaseTransport | None = None, retries: int = 5, sleep: Callable[[float], None] = time.sleep) -> None:
        self.cache_dir = Path(cache_dir)
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        self.workstate = workstate
        self.max_side = max_side
        self.retries = retries
        self.sleep = sleep
        self.limiter = RateLimiter(per_second, sleep=sleep)
        self._client = httpx.Client(transport=transport, timeout=120.0, follow_redirects=True, headers={"User-Agent": USER_AGENT})

    def close(self) -> None:
        self._client.close()

    def fetch_bytes(self, url: str) -> bytes:
        attempt = 0
        while True:
            self.limiter.wait()
            try:
                resp = self._client.get(url)
            except httpx.TransportError:
                attempt += 1
                if attempt > self.retries:
                    raise
                self.sleep(min(60.0, 2.0 ** attempt))
                continue
            if resp.status_code in (404, 410):
                raise ImageGone(f"{resp.status_code} for {url}")
            if resp.status_code == 429 or resp.status_code >= 500:
                attempt += 1
                if attempt > self.retries:
                    resp.raise_for_status()
                self.sleep(min(120.0, 2.0 ** attempt))
                continue
            resp.raise_for_status()
            return resp.content

    def get(self, puzzle_id: str, fingerprint: str, url: str) -> LocalImage:
        known = self.workstate.download(fingerprint)
        if known and Path(known["path"]).exists():
            return LocalImage(puzzle_id, fingerprint, known["path"], known["sha256"], known["width"], known["height"],
                              known["original_width"] or known["width"], known["original_height"] or known["height"])
        data = self.fetch_bytes(url)
        sha = hashlib.sha256(data).hexdigest()
        try:
            raw = decode(data)
        except UndecodableImage:
            raise
        ow, oh = raw.size
        im = fit(normalise(raw), self.max_side)
        path = self.cache_dir / fingerprint[:2] / f"{fingerprint}.jpg"
        path.parent.mkdir(parents=True, exist_ok=True)
        # JPEG q95 4:4:4: ~1 MB at 1600 px, deterministic for one Pillow version (both Macs pin the same one)
        im.save(path, "JPEG", quality=95, subsampling=0)
        w, h = im.size
        self.workstate.record_download(fingerprint, puzzle_id, str(path), sha, w, h, ow, oh, path.stat().st_size)
        return LocalImage(puzzle_id, fingerprint, str(path), sha, w, h, ow, oh)

    def original(self, url: str) -> tuple[bytes, str]:
        """The full-resolution original (crop proposals) - not cached."""
        data = self.fetch_bytes(url)
        return data, hashlib.sha256(data).hexdigest()
