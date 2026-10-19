"""The worker contract (implementation-plan.md §2.2) for one stage in one process:

1. claim {analyzer, version, worker, limit, leaseSeconds, requires};
2. pre-fetch the next claim (and its downloads) while working on this one - a plain second claim;
3. download, run, batch-store (<= 200 per batch);
4. a per-image failure is recorded in the workstate and its claim released (after 3 failures the image is held
   until the stage ends, so it does not come back first on every claim). Released or expired images this run already
   handled come back first (lowest ids, stale); a claim of only those is no end - the next claim reaches past them;
5. on SIGTERM / Ctrl-C / the memory watchdog / the deadline: store what is done, release all own claims, exit.

A 409 / `stale_fingerprint` (the picture changed meanwhile) is skipped; production is the truth, the workstate is a cache.
"""

from __future__ import annotations

import logging
import threading
import time
from concurrent.futures import Future, ThreadPoolExecutor
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any

from .analyzers import schema_major
from .api import AnalysisResult, InternalApi, WorkItem
from .download import Downloader, ImageGone, LocalImage
from .imaging import UndecodableImage, artwork_crop, load_rgb
from .payload import MAX_RESULT_BYTES, PayloadTooLarge, check_size, envelope
from typing import Callable

from .profiles import AnalyzerSettings
from .stages.base import ArtifactSource, Defer, Prepared, Skip, Stage, StageBroken
from .workstate import WorkState

log = logging.getLogger(__name__)

FLUSH_SECONDS = 120.0
PREFETCH_AT = 0.8  # the next claim goes out when this share of the batch in hand is done


@dataclass
class WorkerStats:
    claimed: int = 0
    stored: int = 0
    stale: int = 0
    not_found: int = 0
    failed: int = 0
    skipped: int = 0
    deferred: int = 0
    invalid: int = 0
    released: int = 0
    seconds: float = 0.0
    analyse_seconds: float = 0.0
    stop_reason: str = ""
    errors: list[str] = field(default_factory=list)

    def line(self, analyzer: str) -> str:
        rate = self.analyse_seconds / max(1, self.stored + self.failed)
        return (f"[{analyzer}] stored {self.stored}, stale {self.stale}, failed {self.failed}, skipped {self.skipped}, "
                f"deferred {self.deferred}, {rate:.2f} s/img, {self.seconds / 60:.1f} min ({self.stop_reason or 'running'})")


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class StageWorker:
    def __init__(
        self,
        stage: Stage,
        settings: AnalyzerSettings,
        api: InternalApi,
        downloader: Downloader,
        workstate: WorkState,
        worker_id: str,
        stop: threading.Event | None = None,
        max_images: int | None = None,
        deadline: float | None = None,
        download_threads: int = 4,
        flush_size: int = 200,
        loader: Callable[[], None] | None = None,
    ) -> None:
        self.stage = stage
        self.settings = settings
        self.api = api
        self.downloader = downloader
        self.workstate = workstate
        self.worker_id = worker_id
        self.stop = stop or threading.Event()
        self.max_images = max_images
        self.deadline = deadline
        self.flush_size = 1 if settings.slow else min(200, flush_size)
        self._loader = loader  # takes the model lock and loads the model - only once there is usable work
        self._loaded = loader is None
        self._upcoming: Future | None = None
        self.artifacts = ArtifactSource(api, workstate)
        self.stats = WorkerStats()
        self._pool = ThreadPoolExecutor(max_workers=download_threads, thread_name_prefix="download")
        self._pending: list[AnalysisResult] = []
        self._last_flush = time.monotonic()
        self._seen: set[str] = set()
        self._done = 0

    @property
    def analyzer(self) -> str:
        return self.settings.name

    # claims ----------------------------------------------------------------------------------------------------------
    def _claim(self) -> list[WorkItem]:
        limit = self.settings.claim_limit
        if self.max_images is not None:
            limit = max(1, min(limit, self.max_images - self._done))
        items = self.api.claim(self.analyzer, self.settings.version, self.worker_id, limit=limit,
                               lease_seconds=self.settings.lease_seconds(), requires=self.settings.requires)
        self.stats.claimed += len(items)
        return items

    def _claim_and_download(self) -> tuple[list[WorkItem], dict[str, Future]]:
        if self._should_stop():  # never claim what will not be worked on
            return [], {}
        items = self._claim()
        fresh = [i for i in items if i.puzzle_id not in self._seen]
        return items, {i.puzzle_id: self._pool.submit(self.downloader.get, i.puzzle_id, i.fingerprint, i.image_url) for i in fresh}

    def _should_stop(self) -> bool:
        if self.stop.is_set():
            self.stats.stop_reason = self.stats.stop_reason or "stopped"
            return True
        if self.deadline is not None and time.time() >= self.deadline:
            self.stats.stop_reason = "time cap reached"
            return True
        if self.max_images is not None and self._done >= self.max_images:
            self.stats.stop_reason = "image limit reached"
            return True
        return False

    # the loop ----------------------------------------------------------------------------------------------------------
    def run(self) -> WorkerStats:
        started = time.monotonic()
        try:
            batch, downloads = self._claim_and_download()
            handled_again: set[str] = set()  # ids of the claims in a row that brought nothing new
            while not self._should_stop():
                fresh = [i for i in batch if i.puzzle_id not in self._seen]
                if not fresh:
                    # Only images this run already handled came back. Failed / skipped ones are released without a
                    # result (and held ones lose their lease), so they stay stale and the server hands them out first
                    # again (lowest ids). This claim made them our own again, and our own claims come last - claim once
                    # more to reach past them. It is the end only when a claim brings back nothing but ids it already
                    # brought back in this row (the server has nothing else left for us).
                    ids = {i.puzzle_id for i in batch}
                    if not ids or ids <= handled_again:
                        self.stats.stop_reason = self.stats.stop_reason or "no more work"
                        break
                    handled_again |= ids
                    batch, downloads = self._claim_and_download()
                    continue
                handled_again.clear()
                self._seen.update(i.puzzle_id for i in fresh)
                self._upcoming = None
                more = self.max_images is None or self._done + len(fresh) < self.max_images
                self._work(fresh, downloads, prefetch_at=max(1, int(len(fresh) * PREFETCH_AT + 0.999)) if more else None)
                if not more:
                    self.stats.stop_reason = self.stats.stop_reason or "image limit reached"
                    break
                if self._upcoming is None:  # the batch ended before the pre-fetch point (skips, failures)
                    self._upcoming = self._pool.submit(self._claim_and_download)
                batch, downloads = self._upcoming.result()
        except StageBroken as e:
            self.stats.stop_reason = f"stage broken: {e}"
            log.error("[%s] the stage cannot go on in this process: %s", self.analyzer, e)
        finally:
            try:
                self.flush(force=True)
            except Exception as e:  # noqa: BLE001 - the claims are still released; the images come back next run
                log.error("[%s] could not store the last results: %s", self.analyzer, e)
            try:
                self.stats.released += self.api.release(self.worker_id, analyzer=self.analyzer)
            except Exception as e:  # noqa: BLE001 - claims expire anyway
                log.warning("could not release the claims of %s: %s", self.analyzer, e)
            self._pool.shutdown(wait=False, cancel_futures=True)
            self.stats.seconds = time.monotonic() - started
            log.info(self.stats.line(self.analyzer))
        return self.stats

    def _maybe_prefetch(self, processed: int, prefetch_at: int | None) -> None:
        if prefetch_at is not None and processed >= prefetch_at and self._upcoming is None:
            self._upcoming = self._pool.submit(self._claim_and_download)

    def _work(self, items: list[WorkItem], downloads: dict[str, Future], prefetch_at: int | None = None) -> None:
        for upstream in self.stage.upstream:
            self.artifacts.ensure(upstream, items)
        size = max(1, self.stage.batch_size)
        processed = 0
        for start in range(0, len(items), size):
            if self._should_stop():
                return
            chunk = items[start:start + size]
            processed += len(chunk)
            prepared: list[Prepared] = []
            for item in chunk:
                p = self._prepare(item, downloads.get(item.puzzle_id))
                if p is not None:
                    prepared.append(p)
            if not prepared:
                self._maybe_prefetch(processed, prefetch_at)
                continue
            if not self._loaded:
                assert self._loader is not None
                self._loader()  # the model lock + the model, only now that there is usable work
                self._loaded = True
            t = time.monotonic()
            results = self.stage.analyse_batch(prepared)
            elapsed = time.monotonic() - t
            self.stats.analyse_seconds += elapsed
            per_image_ms = 1000 * elapsed / len(prepared)
            for p, result in zip(prepared, results):
                self._done += 1
                if isinstance(result, Defer):
                    self.stats.deferred += 1
                    continue
                if isinstance(result, Skip):
                    self._skip(p.item, str(result))
                    continue
                if isinstance(result, Exception):
                    self._fail(p.item, f"{type(result).__name__}: {result}")
                    continue
                self._accept(p, result, per_image_ms)
            self.flush()
            self._maybe_prefetch(processed, prefetch_at)

    def _prepare(self, item: WorkItem, download: Future | None) -> Prepared | None:
        if self.workstate.given_up(self.analyzer, self.settings.version, item.puzzle_id, item.fingerprint):
            self.stats.skipped += 1  # held until the stage ends, retried after a version bump
            return None
        try:
            image: LocalImage = download.result() if download is not None else self.downloader.get(item.puzzle_id, item.fingerprint, item.image_url)
            rgb = load_rgb(image.path)
        except ImageGone as e:
            self._skip(item, str(e))
            return None
        except (UndecodableImage, OSError, Exception) as e:  # noqa: BLE001 - per-image failure
            self._fail(item, f"download/decode: {type(e).__name__}: {e}")
            return None
        p = Prepared(item=item, image=image, rgb=rgb)
        for upstream in self.stage.upstream + self.stage.optional_upstream:
            payload = self.artifacts.get(upstream, item)
            if payload is not None:
                p.upstream[upstream] = payload
            elif upstream in self.stage.upstream:
                self._skip(item, f"no current {upstream} result")
                return None
        if self.stage.uses_crop:
            p.crop, p.crop_info = artwork_crop(rgb, p.upstream.get("artwork"))
        return p

    def _accept(self, p: Prepared, result: dict[str, Any], runtime_ms: float) -> None:
        payload = envelope(result, schema=schema_major(self.settings.version), model=self.stage.model,
                           source_sha256=p.image.sha256, crop=p.crop_info, runtime_ms=result.pop("_runtimeMs", runtime_ms))
        try:
            check_size(payload, MAX_RESULT_BYTES)
        except PayloadTooLarge as e:
            self._fail(p.item, str(e))
            return
        self._pending.append(AnalysisResult(p.item.puzzle_id, self.analyzer, self.settings.version, p.item.fingerprint, payload, utc_now()))

    def _fail(self, item: WorkItem, error: str) -> None:
        self.stats.failed += 1
        if len(self.stats.errors) < 20:
            self.stats.errors.append(f"{item.puzzle_id}: {error}")
        attempts = self.workstate.record_failure(self.analyzer, self.settings.version, item.puzzle_id, item.fingerprint, error)
        log.warning("[%s] %s failed (attempt %d): %s", self.analyzer, item.puzzle_id, attempts, error)
        self._release_one(item)

    def _skip(self, item: WorkItem, reason: str) -> None:
        self.stats.skipped += 1
        log.info("[%s] %s skipped: %s", self.analyzer, item.puzzle_id, reason)
        self._release_one(item)

    def _release_one(self, item: WorkItem) -> None:
        try:
            self.stats.released += self.api.release(self.worker_id, analyzer=self.analyzer, puzzle_ids=[item.puzzle_id])
        except Exception as e:  # noqa: BLE001
            log.warning("release of %s failed: %s", item.puzzle_id, e)

    # storing -------------------------------------------------------------------------------------------------------------
    def flush(self, force: bool = False) -> None:
        if not self._pending:
            return
        if not force and len(self._pending) < self.flush_size and time.monotonic() - self._last_flush < FLUSH_SECONDS:
            return
        pending, self._pending = self._pending, []
        self._last_flush = time.monotonic()
        try:
            outcomes = self.api.store(pending)
        except Exception:
            self._pending = pending + self._pending  # nothing is lost - the next flush (or the final one) tries again
            raise
        for outcome, result in zip(outcomes, pending):
            status = outcome.status
            self.workstate.record_progress(self.analyzer, self.settings.version, result.puzzle_id, result.fingerprint, status, self.worker_id)
            if status == "stored":
                self.stats.stored += 1
            elif status == "stale_fingerprint":
                self.stats.stale += 1  # the picture changed - fine, the next claim brings the new one
            elif status == "not_found":
                self.stats.not_found += 1  # deleted, merged or hidden meanwhile
            else:
                self.stats.invalid += 1
                self.workstate.record_failure(self.analyzer, self.settings.version, result.puzzle_id, result.fingerprint,
                                              f"invalid: {outcome.errors or outcome.message}")
                log.error("[%s] the API refused %s as invalid: %s", self.analyzer, result.puzzle_id, outcome.errors or outcome.message)
