"""`puzzle-vision finished-photos` - the `finished_photo` analyzer over players' photos of the finished puzzle
(docs/features/insights/finished-photos.md, runbook image-pipeline.md §7 "finished_photo").

Not a claim stage of the phase runner: its unit is a photo, not a catalogue picture, and one machine runs it (the work
list's `status=stale` is the to-do list; no leases). Per run:
1. the work list (GET /internal-api/finished-photos?status=stale&version=…): puzzles with their catalogue picture and
   up to `per_puzzle` photos each, known only by fingerprint - no player, no solving time, no stored path;
2. the order: puzzles whose catalogue picture is a box / assembled photo and that have fewer than 5 measured first
   tries first (the picture estimate decides their prediction and the second view is for them), then the rest;
3. per photo (CPU, `workers` single-threaded processes): the photo through the internal API (the image proxy's 1200 px
   JPEG, metadata stripped; cached by fingerprint), the catalogue picture from the image cache + its stored `artwork`
   crop, finished_photo.measure() - found / straightened / measured;
4. the straightened crops' SigLIP 2 + DINOv2 embeddings (GPU, under the model lock, batches of 16) - the same models,
   preprocessing and payload format as `embed_siglip2` / `embed_dinov2` of the catalogue crop, so the two views compare;
5. stored in batches (POST /internal-api/finished-photo-analyses). `stale_fingerprint` (the catalogue picture or the
   photo changed meanwhile) and `not_found` are skipped; a photo failing 3 times is left until the next version.
A photo where the puzzle was not found is stored too (method `none` + why), so it is not tried again for the version.
"""

from __future__ import annotations

import hashlib
import logging
import os
import time
from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from typing import Any, Callable, Iterable, Protocol

import numpy as np

from .api import ApiError, FinishedPhotoPuzzle, FinishedPhotoResult, NotFound
from .payload import PayloadTooLarge, check_size, envelope

log = logging.getLogger(__name__)

ANALYZER = "finished_photo"
SCHEMA = 1
EMBED_BATCH = 16
CHUNK = 64  # photos downloaded, then measured in the worker processes, at a time
STORE_EVERY = 100
MAX_ATTEMPTS = 3
EMBED_SIDE = 768  # the crop handed to the embedders (SigLIP squashes to 384, DINOv2 to 224)
MODEL = "opencv sift->homography / edge quad + numpy measures; google/siglip2-so400m-patch14-384 fp16 + facebook/dinov2-large fp16 of the straightened crop"
PRIORITY_MAX_INDICES = 5


class Embedder(Protocol):
    def embed(self, crops: list[np.ndarray]) -> list[dict[str, Any]]:
        """{"siglip2": siglip_payload, "dinov2": dinov2_payload} per crop."""


class ModelEmbedder:
    """SigLIP 2 + DINOv2-L through the catalogue stages' own code (stages/embeddings.py), loaded on first use."""

    def __init__(self) -> None:
        self._siglip: Any = None
        self._dino: Any = None

    def load(self) -> None:
        from .stages.embeddings import Dinov2Stage, Siglip2Stage

        self._siglip = Siglip2Stage("finished_photo")
        self._siglip.load()
        self._dino = Dinov2Stage("finished_photo")
        self._dino.load()

    def embed(self, crops: list[np.ndarray]) -> list[dict[str, Any]]:
        from .stages.embeddings import siglip_payload, squash

        if self._siglip is None:
            self.load()
        vectors = self._siglip.vectors([squash(c) for c in crops])
        dino = self._dino.analyse_batch([SimpleNamespace(subject=c) for c in crops])
        out = []
        for v, d in zip(vectors, dino):
            if isinstance(d, Exception):
                raise d
            out.append({"siglip2": siglip_payload(v), "dinov2": d})
        return out


@dataclass
class Job:
    puzzle_id: str
    fingerprint: str
    pieces: int | None
    photo_fingerprint: str
    photo_path: str
    catalogue_path: str
    artwork: dict[str, Any]


def priority(puzzle: FinishedPhotoPuzzle, kinds: dict[str, str], index_counts: dict[str, int]) -> tuple[int, str]:
    """0 = a box / assembled catalogue picture with < 5 measured first tries (the second view is for these), 1 = other
    under-measured, 2 = the rest; then puzzle id."""
    kind = kinds.get(puzzle.puzzle_id)
    few = index_counts.get(puzzle.puzzle_id, 0) < PRIORITY_MAX_INDICES
    if few and kind in ("box_front", "box_angled", "assembled"):
        return 0, puzzle.puzzle_id
    return (1 if few else 2), puzzle.puzzle_id


def _measure_job(job: Job) -> tuple[dict[str, Any] | None, np.ndarray | None, str | None]:
    """In a worker process: the CPU payload and the crop for the embedders (<= EMBED_SIDE), or the error."""
    try:
        import cv2

        from .finished_photo import measure
        from .imaging import load_rgb

        start = time.perf_counter()
        result, crop = measure(load_rgb(job.photo_path), load_rgb(job.catalogue_path), job.artwork, job.pieces)
        result["_runtimeMs"] = (time.perf_counter() - start) * 1000
        if crop is not None:
            h, w = crop.shape[:2]
            s = min(1.0, EMBED_SIDE / max(h, w))
            if s < 1.0:
                crop = cv2.resize(crop, (max(1, round(w * s)), max(1, round(h * s))), interpolation=cv2.INTER_AREA)
        return result, crop, None
    except Exception as e:  # noqa: BLE001 - per-photo failure
        return None, None, f"{type(e).__name__}: {e}"


def _single_thread() -> None:
    for var in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS", "VECLIB_MAXIMUM_THREADS"):
        os.environ[var] = "1"
    import cv2

    cv2.setNumThreads(1)


class PhotoCache:
    """The photos as the API handed them over, by fingerprint - only ever the pipeline's cache, never shown."""

    def __init__(self, root: Path) -> None:
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)

    def path(self, photo_fingerprint: str) -> Path:
        return self.root / photo_fingerprint[:2] / f"{photo_fingerprint}.jpg"

    def get(self, photo_fingerprint: str, fetch: Callable[[], bytes]) -> tuple[Path, str]:
        path = self.path(photo_fingerprint)
        if not path.exists():
            data = fetch()
            path.parent.mkdir(parents=True, exist_ok=True)
            tmp = path.with_name(f"{path.name}.tmp-{os.getpid()}")
            tmp.write_bytes(data)
            os.replace(tmp, path)
        return path, hashlib.sha256(path.read_bytes()).hexdigest()


def run(
    api: Any,
    catalogue: Callable[[str, str, str], str],
    photo_cache: PhotoCache,
    version: str,
    *,
    embedder: Embedder | None,
    per_puzzle: int = 4,
    workers: int = 3,
    max_photos: int | None = None,
    dry_run: bool = False,
    stop: Any = None,
    failures: dict[tuple[str, str], int] | None = None,
    lock: Any = None,
) -> dict[str, Any]:
    """One pass over the photos still to do for `version`. `catalogue(puzzleId, fingerprint, url)` = the local path of
    the catalogue picture (the image cache); `embedder` None = no embeddings (testing only - never for a stored run:
    the payload then has no `embeddings` and the estimate cannot use the photo's view)."""
    summary: dict[str, Any] = {"version": version, "puzzles": 0, "photos": 0, "stored": 0, "skipped": {}, "methods": {},
                               "failed": 0, "dryRun": dry_run}
    failures = failures if failures is not None else {}
    artwork = _current_rows(api, "artwork")
    kinds = {p: str((r.get("result") or {}).get("kind") or "") for p, r in artwork.items()}
    index_counts = _index_counts(api)
    puzzles = sorted(api.iter_finished_photos(version=version, status="stale", per_puzzle=per_puzzle),
                     key=lambda p: priority(p, kinds, index_counts))
    summary["puzzles"] = len(puzzles)

    jobs: list[Job] = []
    skipped: dict[str, int] = {}
    shas: dict[str, str] = {}
    for puzzle in puzzles:
        row = artwork.get(puzzle.puzzle_id)
        if row is None or row.get("imageFingerprint") != puzzle.fingerprint:
            skipped["no current artwork"] = skipped.get("no current artwork", 0) + len(puzzle.photos)
            continue
        try:
            cat_path = catalogue(puzzle.puzzle_id, puzzle.fingerprint, puzzle.image_url)
        except Exception as e:  # noqa: BLE001 - the next run tries again
            log.warning("[finished_photo] catalogue picture of %s: %s", puzzle.puzzle_id, e)
            skipped["catalogue picture"] = skipped.get("catalogue picture", 0) + len(puzzle.photos)
            continue
        for photo in puzzle.photos:
            key = (puzzle.puzzle_id, photo.photo_fingerprint)
            if failures.get(key, 0) >= MAX_ATTEMPTS:
                skipped["given up"] = skipped.get("given up", 0) + 1
                continue
            if max_photos is not None and len(jobs) >= max_photos:
                break
            jobs.append(Job(puzzle.puzzle_id, puzzle.fingerprint, puzzle.pieces, photo.photo_fingerprint, "",
                            cat_path, row["result"]))
    summary["skipped"] = skipped

    pending: list[FinishedPhotoResult] = []
    embed_queue: list[tuple[Job, dict[str, Any], np.ndarray]] = []

    def flush_embeddings() -> None:
        if not embed_queue:
            return
        vectors = embedder.embed([c for _, _, c in embed_queue]) if embedder is not None else [None] * len(embed_queue)
        for (job, result, _), emb in zip(embed_queue, vectors):
            if emb is not None:
                result["embeddings"] = emb
            pending.append(_result(job, result, version, shas.get(job.photo_fingerprint)))
        embed_queue.clear()

    def flush_store(force: bool = False) -> None:
        if not pending or (len(pending) < STORE_EVERY and not force):
            return
        if dry_run:
            summary["stored"] += len(pending)
        else:
            for outcome, r in zip(api.store_finished_photo_analyses(pending), pending):
                if outcome.status == "stored":
                    summary["stored"] += 1
                else:
                    summary["skipped"][outcome.status] = summary["skipped"].get(outcome.status, 0) + 1
                    if outcome.status == "invalid":
                        log.warning("[finished_photo] refused %s/%s: %s", r.puzzle_id, r.photo_fingerprint, outcome.errors)
        pending.clear()

    urls = {(p.puzzle_id, ph.photo_fingerprint): ph.photo_url for p in puzzles for ph in p.photos}

    def fetched(jobs_: Iterable[Job]) -> Iterable[Job]:
        for job in jobs_:
            if stop is not None and stop.is_set():
                return
            url = urls[(job.puzzle_id, job.photo_fingerprint)]
            try:
                path, sha = photo_cache.get(job.photo_fingerprint, lambda: api.finished_photo_bytes(url))
            except NotFound:
                summary["skipped"]["photo gone"] = summary["skipped"].get("photo gone", 0) + 1
                continue
            except (ApiError, OSError) as e:
                log.warning("[finished_photo] photo %s/%s: %s", job.puzzle_id, job.photo_fingerprint, e)
                failures[(job.puzzle_id, job.photo_fingerprint)] = failures.get((job.puzzle_id, job.photo_fingerprint), 0) + 1
                summary["failed"] += 1
                continue
            shas[job.photo_fingerprint] = sha
            job.photo_path = str(path)
            yield job

    locked = False

    def handle(job: Job, measured: tuple[dict[str, Any] | None, np.ndarray | None, str | None]) -> None:
        nonlocal locked
        result, crop, error = measured
        summary["photos"] += 1
        if error is not None or result is None:
            key = (job.puzzle_id, job.photo_fingerprint)
            failures[key] = failures.get(key, 0) + 1
            summary["failed"] += 1
            log.warning("[finished_photo] %s/%s: %s", job.puzzle_id, job.photo_fingerprint, error)
            return
        method = result["locate"]["method"]
        summary["methods"][method] = summary["methods"].get(method, 0) + 1
        if crop is None:
            pending.append(_result(job, result, version, shas.get(job.photo_fingerprint)))
            return
        if embedder is not None and lock is not None and not locked:
            lock.acquire(stop=stop)  # the GPU only once there is a crop to embed
            locked = True
        embed_queue.append((job, result, crop))
        if len(embed_queue) >= EMBED_BATCH:
            flush_embeddings()

    try:
        with ProcessPoolExecutor(max_workers=max(1, workers), initializer=_single_thread) as pool:
            for start in range(0, len(jobs), CHUNK):
                if stop is not None and stop.is_set():
                    break
                ready = list(fetched(jobs[start:start + CHUNK]))
                for job, measured in zip(ready, pool.map(_measure_job, ready)):
                    handle(job, measured)
                flush_store()
        if stop is None or not stop.is_set():
            flush_embeddings()
        flush_store(force=True)
    finally:
        if locked:
            lock.release()
    summary["stopped"] = bool(stop is not None and stop.is_set())
    return summary


def _result(job: Job, result: dict[str, Any], version: str, sha: str | None) -> FinishedPhotoResult:
    runtime = float(result.pop("_runtimeMs", 0.0))
    payload = envelope(result, schema=SCHEMA, model=MODEL, source_sha256=sha, crop=None, runtime_ms=runtime)
    try:
        check_size(payload)
    except PayloadTooLarge:
        payload.pop("pieceScale", None)  # the biggest optional block; the embeddings are what the estimate needs most
        check_size(payload)
    return FinishedPhotoResult(job.puzzle_id, job.photo_fingerprint, job.fingerprint, version, payload,
                               analyzed_at=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))


def _current_rows(api: Any, analyzer: str) -> dict[str, dict[str, Any]]:
    return {r["puzzleId"]: r for r in api.iter_analyses(analyzer, only_current=True)}


def _index_counts(api: Any) -> dict[str, int]:
    try:
        return {lb.puzzle_id: lb.index_count for lb in api.iter_difficulty_labels()}
    except ApiError as e:
        log.info("[finished_photo] no labels for the order (%s) - puzzle id order", e)
        return {}
