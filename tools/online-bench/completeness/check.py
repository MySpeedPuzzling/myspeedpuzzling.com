"""
The completeness checks on real photos (spikes.md S2): a finish photo, the empty-mat photo and / or the reference
motif → the verdicts as JSON (+ the photo with the gaps drawn).

    python check.py --finish finish.jpg --pieces 1000 [--mat empty-mat.jpg] [--reference box-motif.jpg]
                    [--grid 25x40] [--detector akaze|orb] [--draw out.jpg]

With --mat: background holes. With --reference: reference-grid matching (the grid from --grid, else about --pieces
square cells over the reference). With both: also the combined verdict. Footage and photos stay outside the
repository (README.md of the bench).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import cv2
import numpy as np

from completeness import COMPLETE, GAPS, UNSURE, GridOptions, check_grid, check_holes, combine, grid_shape

COLOURS = {COMPLETE: (60, 170, 60), GAPS: (40, 40, 220), UNSURE: (0, 160, 230)}


def read(path: Path | None):
    if path is None:
        return None

    image = cv2.imread(str(path), cv2.IMREAD_COLOR)

    if image is None:
        raise SystemExit(f"Cannot read {path}")

    return image


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--finish", type=Path, required=True)
    parser.add_argument("--pieces", type=int, required=True)
    parser.add_argument("--mat", type=Path)
    parser.add_argument("--reference", type=Path)
    parser.add_argument("--grid", help="rows x columns of the pieces, e.g. 25x40")
    parser.add_argument("--detector", choices=["akaze", "orb"], default="akaze")
    parser.add_argument("--draw", type=Path, help="write the finish photo with the verdicts drawn on it")
    args = parser.parse_args()

    finish = read(args.finish)
    mat = read(args.mat)
    reference = read(args.reference)
    result = {}
    verdicts = {}

    if mat is not None:
        verdicts["holes"] = check_holes(mat, finish, args.pieces)

    if reference is not None:
        if args.grid:
            rows, columns = (int(value) for value in args.grid.lower().split("x"))
        else:
            rows, columns = grid_shape(args.pieces, reference.shape[1] / reference.shape[0])

        verdicts["grid"] = check_grid(reference, finish, rows, columns, mat_bgr=mat, options=GridOptions(detector=args.detector))

    if "holes" in verdicts and "grid" in verdicts:
        verdicts["both"] = combine(verdicts["holes"], verdicts["grid"])

    if not verdicts:
        parser.error("give --mat (background holes) and / or --reference (grid matching)")

    for name, verdict in verdicts.items():
        result[name] = verdict.as_dict()

    print(json.dumps(result, indent=1, default=float))

    if args.draw:
        canvas = finish.copy()
        final = verdicts.get("both") or next(iter(verdicts.values()))

        if "holes" in verdicts and "quad" in verdicts["holes"].details:
            quad = verdicts["holes"].details["quad"]
            cv2.polylines(canvas, [np.int32(quad).reshape(-1, 1, 2)], True, (255, 200, 0), 3)

        for gap in final.gaps:
            cv2.rectangle(canvas, (gap["x"], gap["y"]), (gap["x"] + gap["w"], gap["y"] + gap["h"]), COLOURS[GAPS], 4)

        label = f"{final.verdict} ({final.confidence:.2f}) - {final.reason}"[:120]
        cv2.rectangle(canvas, (0, 0), (canvas.shape[1], 46), (255, 255, 255), -1)
        cv2.putText(canvas, label, (12, 32), cv2.FONT_HERSHEY_SIMPLEX, 0.9, COLOURS.get(final.verdict, (0, 0, 0)), 2, cv2.LINE_AA)
        cv2.imwrite(str(args.draw), canvas)

    return 0


if __name__ == "__main__":
    sys.exit(main())
