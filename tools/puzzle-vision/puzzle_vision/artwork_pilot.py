"""A pilot of a new `artwork` version on random production results - run locally, nothing stored.

    python -m puzzle_vision.artwork_pilot select --profile m3-solo --n 200 --out DIR     # random current results
    python -m puzzle_vision.artwork_pilot run --out DIR [--no-vlm]                       # the new version on them
    python -m puzzle_vision.artwork_pilot sheets --out DIR                                # original | old crop | new crop

`select` draws from the work list + the bulk read (the API never lists secret puzzles) and leaves out the reference
and tuning sets. Every crop on a sheet is cut by `imaging.crop` itself, from the same cached pixels the pipeline reads.
"""

from __future__ import annotations

import argparse
import json
import random
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image, ImageDraw

from .artwork_eval import REFERENCE_DIR
from .imaging import bbox_to_xyxy, crop, load_rgb

TILE = 360


def _outline(result: dict[str, Any]) -> list[list[float]]:
    if result.get("corners"):
        return [[float(x), float(y)] for x, y in result["corners"]]
    x0, y0, x1, y1 = bbox_to_xyxy(result["bbox"])
    return [[x0, y0], [x1, y0], [x1, y1], [x0, y1]]


def _crop_of(rgb: np.ndarray, result: dict[str, Any]) -> np.ndarray:
    out, _ = crop(rgb, bbox_to_xyxy(result["bbox"]), result.get("corners"))
    return out


def _tile(im: np.ndarray | Image.Image) -> Image.Image:
    im = Image.fromarray(im) if isinstance(im, np.ndarray) else im
    im = im.copy()
    im.thumbnail((TILE, TILE), Image.LANCZOS)
    t = Image.new("RGB", (TILE, TILE), (221, 221, 221))
    t.paste(im, ((TILE - im.width) // 2, (TILE - im.height) // 2))
    return t


def sheet(path: str, old: dict[str, Any], new: dict[str, Any], out: str, previous: dict[str, Any] | None = None) -> None:
    """original | old crop | (previous try) | new crop."""
    rgb = load_rgb(path)
    h, w = rgb.shape[:2]
    orig = Image.fromarray(rgb)
    d = ImageDraw.Draw(orig)
    lw = max(2, max(w, h) // 200)
    for res, col in ((old, (255, 140, 0)), (new, (0, 200, 255))):
        pts = [(x * w, y * h) for x, y in _outline(res)]
        d.line(pts + [pts[0]], fill=col, width=lw)
    tiles = [_tile(orig), _tile(_crop_of(rgb, old))]
    labels = ["original: old orange, new blue", "old crop (1.0)"]
    if previous is not None:
        tiles.append(_tile(_crop_of(rgb, previous)))
        labels.append("previous 1.1 try")
    tiles.append(_tile(_crop_of(rgb, new)))
    labels.append(f"new crop (1.1) - {new.get('kind')}, {new.get('method')}")
    canvas = Image.new("RGB", (len(tiles) * (TILE + 10) - 10, TILE + 26), (255, 255, 255))
    cd = ImageDraw.Draw(canvas)
    for k, (t, label) in enumerate(zip(tiles, labels)):
        canvas.paste(t, (k * (TILE + 10), 26))
        cd.text((k * (TILE + 10) + 4, 6), label, fill=(0, 0, 0))
    canvas.save(out, quality=85)


def cmd_select(a: argparse.Namespace) -> int:
    from .cli import Context

    ctx = Context(argparse.Namespace(profile=a.profile, base_url=a.base_url))
    excluded = set()
    for name in ("reference", "tuning"):
        excluded |= {i["id"] for i in json.loads((REFERENCE_DIR / f"artwork-items-{name}.json").read_text())}
    images = {i.puzzle_id: i for i in ctx.api.iter_images(status="all", limit=1000)}
    rows = []
    for row in ctx.api.iter_analyses("artwork", only_current=True, limit=1000):
        item = images.get(row["puzzleId"])
        if item is None or row.get("imageFingerprint") != item.fingerprint or row["puzzleId"] in excluded:
            continue
        rows.append({"id": item.puzzle_id, "name": item.name, "imageUrl": item.image_url, "fingerprint": item.fingerprint,
                     "pieces": item.pieces_count, "storedVersion": row.get("analyzerVersion"), "stored": row["result"]})
    random.Random(a.seed).shuffle(rows)
    out = Path(a.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "pilot.json").write_text(json.dumps(rows[: a.n], indent=1, ensure_ascii=False))
    print(f"{min(a.n, len(rows))} of {len(rows)} current results -> {out / 'pilot.json'}")
    return 0


def cmd_run(a: argparse.Namespace) -> int:
    from .download import Downloader
    from .stages.artwork_refine import first_pass_from, refine
    from .workstate import WorkState

    out = Path(a.out)
    rows = json.loads((out / "pilot.json").read_text())
    dl = Downloader(out / "cache" / "images", WorkState(out / "cache" / "workstate.sqlite"))
    stage = lock = None
    if not a.no_vlm:
        from .lock import ModelLock
        from .stages.artwork import ArtworkStage

        lock = ModelLock("artwork-pilot")
        lock.acquire(timeout=3600)
        stage = ArtworkStage("pilot")
    try:
        for r in rows:
            local = dl.get(r["id"], r["fingerprint"], r["imageUrl"])
            r["path"] = local.path
            fp = first_pass_from(r["stored"])
            if fp is None:
                r["new"] = None
                continue
            r["new"] = refine(load_rgb(local.path), load_rgb(local.path, 1024), fp, (local.width, local.height),
                              (local.original_width or local.width, local.original_height or local.height),
                              stage.ask_kind if stage else None, stage.ask_face if stage else None,
                              stage.ask_strip if stage else None)
    finally:
        if lock is not None:
            lock.release()
    (out / "pilot.json").write_text(json.dumps(rows, indent=1, ensure_ascii=False))
    print(f"ran {sum(1 for r in rows if r.get('new'))} of {len(rows)}")
    return 0


def cmd_sheets(a: argparse.Namespace) -> int:
    out = Path(a.out)
    rows = json.loads((out / "pilot.json").read_text())
    (out / "sheets").mkdir(exist_ok=True)
    for n, r in enumerate(rows, 1):
        if r.get("new"):
            r["sheet"] = f"sheets/{n:03d}.jpg"
            sheet(r["path"], r["stored"], r["new"], str(out / r["sheet"]), r.get("previous"))
    (out / "pilot.json").write_text(json.dumps(rows, indent=1, ensure_ascii=False))
    print(f"{sum(1 for r in rows if r.get('sheet'))} sheets in {out / 'sheets'}")
    return 0


def main(argv: list[str] | None = None) -> int:
    from .config import DEFAULT_BASE_URL

    p = argparse.ArgumentParser(prog="python -m puzzle_vision.artwork_pilot")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("select")
    s.add_argument("--profile", default="m3-solo")
    s.add_argument("--base-url", default=DEFAULT_BASE_URL)
    s.add_argument("--n", type=int, default=200)
    s.add_argument("--seed", type=int, default=20261020)
    s.add_argument("--out", required=True)
    s = sub.add_parser("run")
    s.add_argument("--out", required=True)
    s.add_argument("--no-vlm", action="store_true")
    s = sub.add_parser("sheets")
    s.add_argument("--out", required=True)
    a = p.parse_args(argv)
    return {"select": cmd_select, "run": cmd_run, "sheets": cmd_sheets}[a.cmd](a)


if __name__ == "__main__":
    raise SystemExit(main())
