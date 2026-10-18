"""Derived analyzers, recomputed from stored raw outputs only - no image touched (image-taxonomy.md §9.2, §11.2):
`box_text`, `zeroshot`, `neighbours`, `tags`, then the `difficulty_prior` step (plan §5.3 - not an image analyzer:
it pushes difficulty estimates + a model record through its own endpoints, see `difficulty_prior.py`).

Reads every input through the bulk endpoint (`onlyCurrent=1`), computes, and pushes only rows that changed (another
version, another picture, or another content) in batches of <= 200.
"""

from __future__ import annotations

import logging
import time
from typing import Any, Callable, Iterable

import numpy as np

from ..analyzers import DERIVED_INPUTS, schema_major
from ..api import AnalysisResult, InternalApi, WorkItem
from ..payload import as_map, b64_to_vector, check_size, envelope, normalised
from . import box_text, difficulty_prior, neighbours, tags, zeroshot

log = logging.getLogger(__name__)

ORDER = ("box_text", "zeroshot", "neighbours", "tags")
MODELS = {
    "box_text": "rules (regex over ocr + barcode)",
    "zeroshot": "google/siglip2-so400m-patch14-384 text prompts, corpus percentiles",
    "neighbours": "cosine top 30, siglip2 + dinov2-large",
    "tags": "fusion rules v1",
}


def _content(result: Any) -> Any:
    """The payload without the envelope, normalised (an empty map ≡ [] ≡ null - the API returns `{}` as `[]`)."""
    return normalised({k: v for k, v in as_map(result).items() if k not in ("schema", "model", "sourceSha256", "crop", "runtimeMs", "corpusSize")})


def needs_push(analyzer: str, old: dict[str, Any] | None, version: str, fingerprint: str, new: dict[str, Any]) -> bool:
    if old is None or old.get("analyzerVersion") != version or old.get("imageFingerprint") != fingerprint:
        return True
    old_result = as_map(old.get("result"))
    if analyzer == "neighbours":
        return neighbours.changed(old_result, new)
    if analyzer == "zeroshot":
        a, b = as_map(old_result.get("scores")), as_map(new.get("scores"))
        if set(a) != set(b):
            return True
        return any(abs(float(a[k]) - float(b[k])) > 0.02 for k in b)
    return _content(old_result) != _content(new)


class Deriver:
    def __init__(self, api: InternalApi, versions: dict[str, str], cache_dir: Any,
                 text_features: Callable[[list[str], list[str]], np.ndarray] | None = None,
                 model_lock: Callable[[], Any] | None = None, lock_stop: Any = None, lock_timeout: float | None = None,
                 report_dir: Any = None) -> None:
        self.api = api
        self.versions = versions
        self.cache_dir = cache_dir
        self._text_features = text_features
        self._model_lock = model_lock
        self._lock_stop = lock_stop
        self._lock_timeout = lock_timeout
        self.report_dir = report_dir
        self.meta: dict[str, WorkItem] = {}
        self.inputs: dict[str, dict[str, dict[str, Any]]] = {}
        self.summary: dict[str, Any] = {}

    def load_meta(self) -> None:
        self.meta = {i.puzzle_id: i for i in self.api.iter_images(status="all", limit=1000)}

    def load(self, analyzer: str) -> dict[str, dict[str, Any]]:
        """Current rows of one analyzer whose picture is the puzzle's present one: puzzleId -> row."""
        if analyzer not in self.inputs:
            rows = {}
            for row in self.api.iter_analyses(analyzer, only_current=True, limit=1000):
                m = self.meta.get(row["puzzleId"])
                if m is not None and row.get("imageFingerprint") == m.fingerprint:
                    rows[row["puzzleId"]] = row
            self.inputs[analyzer] = rows
        return self.inputs[analyzer]

    def existing(self, analyzer: str) -> dict[str, dict[str, Any]]:
        return {r["puzzleId"]: r for r in self.api.iter_analyses(analyzer, only_current=False, limit=1000)}

    # computing -------------------------------------------------------------------------------------------------------
    def compute_box_text(self) -> dict[str, dict[str, Any]]:
        ocr, barcode = self.load("ocr"), self.load("barcode")
        brands = sorted({(m.manufacturer or {}).get("name", "") for m in self.meta.values()} - {""})
        out = {}
        for pid, row in ocr.items():
            out[pid] = box_text.from_payloads(as_map(row["result"]), as_map(as_map(barcode.get(pid)).get("result")), brands)
        return out

    def _siglip(self) -> tuple[list[str], np.ndarray, list[str]]:
        crop, full = self.load("embed_siglip2"), self.load("embed_siglip2_full")
        ids, vecs, sources = [], [], []
        for pid in sorted(set(crop) | set(full)):
            if pid in crop:
                vecs.append(b64_to_vector(crop[pid]["result"]["vector"]))
                sources.append("crop")
            else:
                vecs.append(b64_to_vector(full[pid]["result"]["vector"]))
                sources.append("full")
            ids.append(pid)
        return ids, (np.vstack(vecs) if vecs else np.zeros((0, 1152), np.float32)), sources

    def compute_zeroshot(self) -> dict[str, dict[str, Any]]:
        ids, vecs, sources = self._siglip()
        if not ids:
            return {}
        keys, prompts = zeroshot.bank()
        if self._text_features is not None:
            text = self._text_features(keys, prompts)
        else:
            lock = self._model_lock() if self._model_lock else None
            try:
                if lock is not None:
                    lock.acquire(timeout=self._lock_timeout, stop=self._lock_stop)
                text = zeroshot.text_features(keys, prompts, self.cache_dir)
            finally:
                if lock is not None:
                    lock.release()
        return zeroshot.compute(ids, vecs, sources, keys, text)

    def compute_neighbours(self) -> dict[str, dict[str, Any]]:
        ids, vecs, _ = self._siglip()
        dino = self.load("embed_dinov2")
        dids = sorted(dino)
        dvecs = np.vstack([b64_to_vector(dino[p]["result"]["cls"]) for p in dids]) if dids else np.zeros((0, 1024), np.float32)
        return neighbours.compute(ids, vecs, dids, dvecs)

    def compute_tags(self, fresh: dict[str, dict[str, dict[str, Any]]]) -> dict[str, dict[str, Any]]:
        raw_names = [a for a in DERIVED_INPUTS["tags"] if a not in ("zeroshot", "box_text")]
        raw = {a: self.load(a) for a in raw_names}
        zs = fresh.get("zeroshot") or {pid: as_map(r["result"]) for pid, r in self.load("zeroshot").items()}
        bt = fresh.get("box_text") or {pid: as_map(r["result"]) for pid, r in self.load("box_text").items()}
        pids = set().union(*[set(v) for v in raw.values()]) if raw else set()
        per = {pid: {a: (raw[a].get(pid) or {}).get("result") for a in raw_names} | {"zeroshot": zs.get(pid), "box_text": bt.get(pid)} for pid in pids}
        corpus = tags.Corpus.build([tags.trait_values(p) for p in per.values()])
        return {pid: tags.fuse(p, corpus) for pid, p in per.items() if p.get("describe") or p.get("traits") or p.get("artwork")}

    # pushing ---------------------------------------------------------------------------------------------------------
    def _sha(self, pid: str, analyzers: Iterable[str]) -> str | None:
        for a in analyzers:
            row = self.inputs.get(a, {}).get(pid)
            if row and isinstance(row.get("result"), dict) and row["result"].get("sourceSha256"):
                return row["result"]["sourceSha256"]
        return None

    def push(self, analyzer: str, results: dict[str, dict[str, Any]], dry_run: bool = False) -> dict[str, int]:
        version = self.versions[analyzer]
        old = self.existing(analyzer)
        batch: list[AnalysisResult] = []
        for pid, result in results.items():
            m = self.meta.get(pid)
            if m is None:
                continue
            if not needs_push(analyzer, old.get(pid), version, m.fingerprint, result):
                continue
            payload = envelope(result, schema=schema_major(version), model=MODELS[analyzer],
                               source_sha256=self._sha(pid, DERIVED_INPUTS[analyzer]), crop=None, runtime_ms=0)
            check_size(payload)
            batch.append(AnalysisResult(pid, analyzer, version, m.fingerprint, payload))
        counts = {"computed": len(results), "changed": len(batch), "stored": 0, "stale_fingerprint": 0, "not_found": 0, "invalid": 0}
        if batch and not dry_run:
            for o in self.api.store(batch):
                counts[o.status] = counts.get(o.status, 0) + 1
        return counts

    def _rows(self, analyzer: str) -> Iterable[dict[str, Any]]:
        """For difficulty_prior: what an earlier step loaded already (no second download), else a streaming read."""
        if analyzer in self.inputs:
            return self.inputs[analyzer].values()
        return self.api.iter_analyses(analyzer, only_current=True, limit=1000)

    def run(self, only: Iterable[str] | None = None, dry_run: bool = False, force_model: bool = False) -> dict[str, Any]:
        selected = list(only) if only else [*ORDER, "difficulty_prior"]
        started = time.monotonic()
        self.load_meta()
        fresh: dict[str, dict[str, dict[str, Any]]] = {}
        for analyzer in ORDER:
            if analyzer not in selected:
                continue
            t = time.monotonic()
            results = {
                "box_text": self.compute_box_text,
                "zeroshot": self.compute_zeroshot,
                "neighbours": self.compute_neighbours,
                "tags": lambda: self.compute_tags(fresh),
            }[analyzer]()
            fresh[analyzer] = results
            self.summary[analyzer] = self.push(analyzer, results, dry_run) | {"seconds": round(time.monotonic() - t, 1)}
            log.info("[derive] %s: %s", analyzer, self.summary[analyzer])
        if "difficulty_prior" in selected:
            try:
                self.summary["difficulty_prior"] = difficulty_prior.run(
                    self.api, meta=self.meta, rows=self._rows, force_model=force_model, dry_run=dry_run, report_dir=self.report_dir)
            except difficulty_prior.NotAvailableYet as e:
                self.summary["difficulty_prior"] = {"skipped": str(e)}
            log.info("[derive] difficulty_prior: %s", {k: v for k, v in self.summary["difficulty_prior"].items() if k != "overall"})
        self.summary["seconds"] = round(time.monotonic() - started, 1)
        return self.summary
