"""Replays the current `artwork` code on stored results, CPU only - what a version bump would change before anything
is re-run (image-pipeline.md §4 "Measuring an artwork version").

For every stored result (a JSON-lines file of {puzzleId, imageFingerprint, result} rows - the bulk read
`GET /internal-api/puzzle-image-analyses?analyzer=artwork&onlyCurrent=1`, saved read-only) and its picture in the local
image cache, the stage's refine pass runs on the stored first pass with the stored VLM answers (`PriorAnswers`) and no
model: a question the stored result cannot answer (the face outline changed) marks the picture `needsVlm` instead of
loading a model. Answers per picture: whether the crop changed (IoU of the old and new crop regions, the largest side
move), the new method / kind, and whether a re-run needs the VLM - the share of crops a version moves (every moved
crop makes the crop-based analyzers of that picture stale) and the GPU time of the re-run.

    python -m puzzle_vision.artwork_replay --rows artwork.jsonl --out replay.jsonl [--workers 4] [--only-photos]
"""

from __future__ import annotations

import argparse
import json
import os
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path
from typing import Any, Iterator

import numpy as np

from .artwork_eval import Candidate, crop_polygon, _iou
from .imaging import bbox_to_xyxy, load_rgb
from .stages.artwork import SNAP_SIDE
from .stages.artwork_refine import first_pass_from, refine

CHANGED_IOU = 0.995    # crops closer than this are the same crop (rounding, a pixel)
CHANGED_SIDE = 0.005   # ... and no side moved more than this share of the image


class NeedsVlm(LookupError):
    pass


def _no_model(*_: Any, **__: Any) -> Any:
    raise NeedsVlm()


def image_path(cache_dir: Path, fingerprint: str) -> Path:
    return cache_dir / fingerprint[:2] / f"{fingerprint}.jpg"


def replay_one(row: dict[str, Any], cache_dir: str) -> dict[str, Any]:
    pid, fp, old = row["puzzleId"], row["imageFingerprint"], row["result"]
    out: dict[str, Any] = {"puzzleId": pid, "imageFingerprint": fp, "oldMethod": old.get("method"), "oldKind": old.get("kind")}
    path = image_path(Path(cache_dir), fp)
    first = first_pass_from(old)
    if first is None or not path.exists():
        return out | {"status": "no first pass" if first is None else "no image"}
    rgb = load_rgb(str(path))
    a = rgb
    if max(a.shape[:2]) > SNAP_SIDE:
        from PIL import Image

        im = Image.fromarray(a)
        im.thumbnail((SNAP_SIDE, SNAP_SIDE), Image.LANCZOS)
        a = np.asarray(im)
    orig = (old.get("quality") or {}).get("originalSize") or {}
    original = (int(orig.get("width") or rgb.shape[1]), int(orig.get("height") or rgb.shape[0]))
    try:
        new = refine(rgb, a, first, (rgb.shape[1], rgb.shape[0]), original, ask_kind=_no_model, ask_face=_no_model, prior=old)
    except NeedsVlm:
        return out | {"status": "needs vlm"}
    aspect = rgb.shape[1] / rgb.shape[0]
    po, _ = crop_polygon(Candidate(old.get("kind") or "other", bbox_to_xyxy(old["bbox"]), old.get("corners")), aspect)
    pn, _ = crop_polygon(Candidate(new["kind"], bbox_to_xyxy(new["bbox"]), new.get("corners")), aspect)
    iou = _iou(po, pn, aspect)
    move = float(np.abs(po - pn).max())
    return out | {"status": "replayed", "iou": round(iou, 4), "maxMove": round(move, 4),
                  "changed": bool(iou < CHANGED_IOU or move > CHANGED_SIDE),
                  "method": new["method"], "kind": new["kind"], "result": new}


def read_rows(path: Path, only_photos: bool) -> Iterator[dict[str, Any]]:
    with path.open() as f:
        for line in f:
            if not line.strip():
                continue
            r = json.loads(line)
            row = {"puzzleId": r.get("puzzleId") or r.get("pid"), "imageFingerprint": r.get("imageFingerprint") or r.get("fp"),
                   "result": r["result"]}
            fp = row["result"].get("firstPass") or {}
            if only_photos and (fp.get("method") or row["result"].get("method")) == "trim" and row["result"].get("method") == "trim":
                continue  # a trimmed flat picture without a box opinion: 1.2 changes nothing on that path
            yield row


def _work(args: tuple[dict[str, Any], str]) -> dict[str, Any]:
    try:
        return replay_one(*args)
    except Exception as e:  # noqa: BLE001 - one broken picture must not stop the replay
        return {"puzzleId": args[0]["puzzleId"], "status": f"error: {type(e).__name__}: {e}"}


def summarise(rows: list[dict[str, Any]], total: int | None = None) -> dict[str, Any]:
    done = [r for r in rows if r.get("status") == "replayed"]
    changed = [r for r in done if r["changed"]]
    by: dict[str, list[int]] = {}
    for r in done:
        b = by.setdefault(r.get("oldMethod") or "?", [0, 0])
        b[0] += 1
        b[1] += int(r["changed"])
    status: dict[str, int] = {}
    for r in rows:
        status[r.get("status", "?").split(":")[0]] = status.get(r.get("status", "?").split(":")[0], 0) + 1
    return {"rows": len(rows), "catalogue": total, "status": status, "changed": len(changed),
            "changedShareOfReplayed": round(len(changed) / max(1, len(done)), 4),
            "changedByOldMethod": {k: {"n": v[0], "changed": v[1]} for k, v in sorted(by.items(), key=lambda kv: -kv[1][0])}}


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="python -m puzzle_vision.artwork_replay")
    p.add_argument("--rows", required=True, help="stored artwork rows (JSON lines: puzzleId, imageFingerprint, result)")
    p.add_argument("--out", required=True, help="per-picture replay rows (JSON lines)")
    p.add_argument("--cache", default=str(Path.home() / "Library/Caches/puzzle-vision/images"))
    p.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) // 4))
    p.add_argument("--only-photos", action="store_true", help="skip trimmed flat pictures (a version that does not touch the trim path)")
    p.add_argument("--limit", type=int)
    a = p.parse_args(argv)
    rows = list(read_rows(Path(a.rows), a.only_photos))
    total = sum(1 for _ in Path(a.rows).open())
    if a.limit:
        rows = rows[: a.limit]
    out = []
    with Path(a.out).open("w") as f, ProcessPoolExecutor(a.workers) as ex:
        for r in ex.map(_work, [(row, a.cache) for row in rows], chunksize=8):
            f.write(json.dumps(r) + "\n")
            out.append({k: v for k, v in r.items() if k != "result"})
    print(json.dumps(summarise(out, total), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
