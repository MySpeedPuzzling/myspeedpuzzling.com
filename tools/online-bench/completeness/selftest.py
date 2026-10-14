"""
Synthetic self-test of the completeness checks (docs/features/online-competitions/spikes.md S2, README §5.8) - the
measure that matters is a false "complete" (a missing piece passed).

Each case builds a puzzle the way a phone would photograph it:
- a procedural motif (no downloads): `texture` (busy, many colours), `smooth` (photo-like regions) or `low_texture`
  (a sky gradient - the case README §5.8 warns about), cut into a grid of 300 / 500 / 1000 cells with seams;
- 0, 1 or 2 cells dropped to the mat (with a little shadow at their edges), the dropped pieces lying loose on the mat;
- laid on a mat in a contrasting colour (`contrasting`), or on a table close to the motif's colours (`table` - the S1
  "once your normal table"), or with an arm reaching over the puzzle (`occluded` - in half of the gap cases right over
  the hole, which then cannot be seen at all);
- photographed at an angle (a random perspective), the empty-mat photo with the phone bumped by a few pixels, the
  light drifting between the two photos (gain, gradient, colour), sensor noise, blur and JPEG compression.

Then background holes (empty mat vs finish), reference-grid matching (the clean motif vs finish) and both combined
judge every case; the confusion matrices are printed per approach and condition. A gap is "found" when every visible
missing cell's centre lies in a reported box.

    python selftest.py [--per-cell 6] [--seed 7] [--out DIR]    # DIR: a JSON of every case + example images
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import time
from pathlib import Path

import cv2
import numpy as np

# The checks live with the CV worker (tools/online-cv/completeness.py) - one implementation for the bench and the app
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "online-cv"))

from completeness import COMPLETE, GAPS, UNSURE, check_grid, check_holes, combine  # noqa: E402

MOTIFS = ["texture", "smooth", "low_texture"]
GRIDS = [(15, 20), (20, 25), (25, 40)]
CONDITIONS = ["contrasting", "occluded", "table"]
VERDICTS = [COMPLETE, GAPS, UNSURE]
IMAGE_W, IMAGE_H = 1920, 1080
PLANE_W, PLANE_H = 2400, 1600
MAT_COLOURS = {
    "green": (45, 85, 40),
    "black": (30, 30, 30),
    "blue": (95, 48, 22),
    "burgundy": (45, 32, 115),
}


def make_motif(kind: str, width: int, height: int, rng: np.random.Generator) -> np.ndarray:
    if kind == "texture":
        image = np.full((height, width, 3), rng.integers(40, 220, 3), np.uint8)

        for _ in range(int(width * height / 2500)):
            colour = tuple(int(c) for c in rng.integers(0, 256, 3))
            x, y = int(rng.integers(0, width)), int(rng.integers(0, height))
            size = int(rng.integers(6, max(8, width // 14)))
            shape = rng.integers(0, 4)

            if shape == 0:
                cv2.circle(image, (x, y), size, colour, -1, cv2.LINE_AA)
            elif shape == 1:
                cv2.rectangle(image, (x, y), (x + size, y + int(size * rng.uniform(0.3, 1.5))), colour, -1)
            elif shape == 2:
                cv2.line(image, (x, y), (x + int(rng.integers(-80, 80)), y + int(rng.integers(-80, 80))), colour, int(rng.integers(2, 7)), cv2.LINE_AA)
            else:
                cv2.ellipse(image, (x, y), (size, max(3, size // 2)), float(rng.uniform(0, 180)), 0, 360, colour, -1, cv2.LINE_AA)

        for _ in range(int(width * height / 40000)):
            text = "".join(chr(int(rng.integers(65, 91))) for _ in range(int(rng.integers(2, 6))))
            cv2.putText(image, text, (int(rng.integers(0, width)), int(rng.integers(0, height))), cv2.FONT_HERSHEY_SIMPLEX,
                        float(rng.uniform(0.6, 1.6)), tuple(int(c) for c in rng.integers(0, 256, 3)), 2, cv2.LINE_AA)

        image = cv2.GaussianBlur(image, (0, 0), 0.8)

        return np.clip(image + rng.normal(0, 6, image.shape), 0, 255).astype(np.uint8)

    if kind == "smooth":
        coarse = rng.integers(30, 230, (4, 6, 3)).astype(np.uint8)
        image = cv2.resize(coarse, (width, height), interpolation=cv2.INTER_CUBIC)

        for _ in range(10):
            colour = tuple(int(c) for c in rng.integers(20, 240, 3))
            centre = (int(rng.integers(0, width)), int(rng.integers(0, height)))
            axes = (int(rng.integers(width // 20, width // 6)), int(rng.integers(height // 20, height // 6)))
            cv2.ellipse(image, centre, axes, float(rng.uniform(0, 180)), 0, 360, colour, -1, cv2.LINE_AA)

        image = cv2.GaussianBlur(image, (0, 0), 5)

        for _ in range(25):
            cv2.line(image, (int(rng.integers(0, width)), int(rng.integers(0, height))),
                     (int(rng.integers(0, width)), int(rng.integers(0, height))),
                     tuple(int(c) for c in rng.integers(0, 256, 3)), 2, cv2.LINE_AA)

        return np.clip(image + rng.normal(0, 3, image.shape), 0, 255).astype(np.uint8)

    # low_texture: a sky from blue to near white, a few faint clouds
    top = np.array([225, 175, 115], np.float32) + rng.normal(0, 8, 3)
    bottom = np.array([248, 238, 226], np.float32) + rng.normal(0, 4, 3)
    ramp = np.linspace(0, 1, height, dtype=np.float32)[:, None, None]
    image = top * (1 - ramp) + bottom * ramp
    image = np.repeat(image, width, axis=1)
    clouds = np.zeros((height, width), np.float32)

    for _ in range(6):
        cv2.ellipse(clouds, (int(rng.integers(0, width)), int(rng.integers(0, height // 2))),
                    (int(rng.integers(width // 12, width // 5)), int(rng.integers(height // 20, height // 9))), 0, 0, 360, 1.0, -1)

    clouds = cv2.GaussianBlur(clouds, (0, 0), 25)[:, :, None]
    image = image * (1 - 0.35 * clouds) + 252 * 0.35 * clouds

    return np.clip(image + rng.normal(0, 1.5, image.shape), 0, 255).astype(np.uint8)


def lab_share_near(image: np.ndarray, colour: tuple[int, int, int], distance: float = 22.0) -> float:
    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).reshape(-1, 3).astype(np.float32)
    target = cv2.cvtColor(np.uint8([[colour]]), cv2.COLOR_BGR2LAB).reshape(3).astype(np.float32)

    return float((np.sqrt(((lab - target) ** 2).sum(axis=1)) < distance).mean())


def make_mat(colour: tuple[int, int, int], rng: np.random.Generator, wood: bool = False) -> np.ndarray:
    plane = np.full((PLANE_H, PLANE_W, 3), colour, np.float32)

    if wood:
        grain = cv2.resize(rng.normal(0, 1, (PLANE_H // 40, 6)).astype(np.float32), (PLANE_W, PLANE_H), interpolation=cv2.INTER_CUBIC)
        plane *= (1 + 0.08 * grain)[:, :, None]

    shading = cv2.resize(rng.normal(0, 1, (4, 6)).astype(np.float32), (PLANE_W, PLANE_H), interpolation=cv2.INTER_CUBIC)
    plane *= (1 + 0.03 * shading)[:, :, None]

    return np.clip(plane + rng.normal(0, 2.5, plane.shape), 0, 255).astype(np.uint8)


def camera(rng: np.random.Generator) -> np.ndarray:
    """A homography from the table plane to an angled phone camera."""
    tilt = rng.uniform(0.62, 0.85)
    bottom_w = 2250
    top_w = bottom_w * tilt
    points = np.float32([
        [IMAGE_W / 2 - top_w / 2, rng.uniform(40, 130)],
        [IMAGE_W / 2 + top_w / 2, rng.uniform(40, 130)],
        [IMAGE_W / 2 + bottom_w / 2, IMAGE_H + rng.uniform(60, 140)],
        [IMAGE_W / 2 - bottom_w / 2, IMAGE_H + rng.uniform(60, 140)],
    ])
    angle = math.radians(rng.uniform(-3, 3))
    centre = np.float32([IMAGE_W / 2, IMAGE_H / 2])
    rotation = np.float32([[math.cos(angle), -math.sin(angle)], [math.sin(angle), math.cos(angle)]])
    points = (points - centre) @ rotation.T + centre + rng.uniform(-25, 25, (4, 2)).astype(np.float32)
    plane = np.float32([[0, 0], [PLANE_W, 0], [PLANE_W, PLANE_H], [0, PLANE_H]])

    return cv2.getPerspectiveTransform(plane, points)


def shaken(homography: np.ndarray, rng: np.random.Generator, pixels: float = 3.0, degrees: float = 0.3) -> np.ndarray:
    angle = math.radians(rng.uniform(-degrees, degrees))
    dx, dy = rng.uniform(-pixels, pixels, 2)
    cx, cy = IMAGE_W / 2, IMAGE_H / 2
    shake = np.array([
        [math.cos(angle), -math.sin(angle), cx - cx * math.cos(angle) + cy * math.sin(angle) + dx],
        [math.sin(angle), math.cos(angle), cy - cx * math.sin(angle) - cy * math.cos(angle) + dy],
        [0, 0, 1],
    ])

    return shake @ homography


def photograph(plane: np.ndarray, homography: np.ndarray, light: dict, rng: np.random.Generator) -> np.ndarray:
    floor = np.full((IMAGE_H, IMAGE_W, 3), (115, 118, 122), np.uint8)
    image = cv2.warpPerspective(plane, homography, (IMAGE_W, IMAGE_H), dst=floor, borderMode=cv2.BORDER_TRANSPARENT).astype(np.float32)
    ys, xs = np.mgrid[0:IMAGE_H, 0:IMAGE_W].astype(np.float32)
    field = light["gain"] * (1 + light["gx"] * (xs / IMAGE_W - 0.5) + light["gy"] * (ys / IMAGE_H - 0.5))
    image *= field[:, :, None] * np.float32(light["tint"])[None, None, :]
    image = cv2.GaussianBlur(image, (0, 0), light["blur"])
    image += rng.normal(0, light["noise"], image.shape)
    image = np.clip(image, 0, 255).astype(np.uint8)
    ok, jpeg = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, int(light["jpeg"])])

    return cv2.imdecode(jpeg, cv2.IMREAD_COLOR)


def build_case(index: int, motif_kind: str, dropped_count: int, condition: str, seed: int) -> dict:
    rng = np.random.default_rng(seed * 1000 + index)
    rows, columns = GRIDS[int(rng.integers(0, len(GRIDS)))]
    puzzle_w = int(rng.integers(1150, 1350))
    cell = puzzle_w / columns
    puzzle_h = int(round(cell * rows))
    motif = make_motif(motif_kind, puzzle_w, puzzle_h, rng)

    if condition == "table":
        # A table close to the motif's own colours: its median, a little darker
        colour = tuple(int(c) for c in np.clip(np.median(motif.reshape(-1, 3), axis=0) * 0.92, 0, 255))
        mat_name = "table"
    else:
        shares = {name: lab_share_near(motif, colour) for name, colour in MAT_COLOURS.items()}
        mat_name = min(shares, key=shares.get)
        colour = MAT_COLOURS[mat_name]

    mat_plane = make_mat(colour, rng, wood=condition == "table")
    left = int((PLANE_W - puzzle_w) / 2 + rng.integers(-90, 90))
    top = int((PLANE_H - puzzle_h) / 2 + rng.integers(-70, 70))
    plane = mat_plane.copy()
    pieces = motif.copy()

    # Seams between the pieces
    for column in range(1, columns):
        x = int(round(column * cell))
        cv2.line(pieces, (x, 0), (x, puzzle_h), (0, 0, 0), 1)

    for row in range(1, rows):
        y = int(round(row * cell))
        cv2.line(pieces, (0, y), (puzzle_w, y), (0, 0, 0), 1)

    pieces = cv2.addWeighted(pieces, 0.7, motif, 0.3, 0)
    plane[top:top + puzzle_h, left:left + puzzle_w] = pieces
    cells = [(r, c) for r in range(1, rows - 1) for c in range(1, columns - 1)]
    order = rng.permutation(len(cells))
    dropped = [cells[i] for i in order[:dropped_count]]

    for row, column in dropped:
        x0, y0 = left + int(round(column * cell)), top + int(round(row * cell))
        x1, y1 = left + int(round((column + 1) * cell)), top + int(round((row + 1) * cell))
        plane[y0:y1, x0:x1] = mat_plane[y0:y1, x0:x1]
        # The hole's walls cast a little shadow on the mat
        shadow = plane[y0:y1, x0:x1].astype(np.float32)
        shadow[:3, :] *= 0.8
        shadow[:, :3] *= 0.85
        plane[y0:y1, x0:x1] = shadow.astype(np.uint8)
        # The piece lies loose somewhere on the mat, turned
        patch = motif[int(round(row * cell)):int(round((row + 1) * cell)), int(round(column * cell)):int(round((column + 1) * cell))]

        for _ in range(50):
            px, py = int(rng.integers(100, PLANE_W - 200)), int(rng.integers(100, PLANE_H - 200))

            if px + patch.shape[1] < left - 60 or px > left + puzzle_w + 60 or py + patch.shape[0] < top - 60 or py > top + puzzle_h + 60:
                turn = cv2.getRotationMatrix2D((patch.shape[1] / 2, patch.shape[0] / 2), float(rng.uniform(0, 360)), 1.0)
                mask = cv2.warpAffine(np.full(patch.shape[:2], 255, np.uint8), turn, (patch.shape[1], patch.shape[0]))
                turned = cv2.warpAffine(patch, turn, (patch.shape[1], patch.shape[0]))
                region = plane[py:py + patch.shape[0], px:px + patch.shape[1]]
                region[mask > 0] = turned[mask > 0]
                break

    homography = None

    for _ in range(20):
        candidate = camera(rng)
        corners = np.float32([[left, top], [left + puzzle_w, top], [left + puzzle_w, top + puzzle_h], [left, top + puzzle_h]]).reshape(-1, 1, 2)
        projected = cv2.perspectiveTransform(corners, candidate).reshape(-1, 2)

        if projected[:, 0].min() > 30 and projected[:, 0].max() < IMAGE_W - 30 and projected[:, 1].min() > 30 and projected[:, 1].max() < IMAGE_H - 30:
            homography = candidate
            break

    if homography is None:
        raise RuntimeError("no camera sees the whole puzzle")

    light_mat = {
        "gain": rng.uniform(0.9, 1.08), "gx": rng.uniform(-0.15, 0.15), "gy": rng.uniform(-0.15, 0.15),
        "tint": [1 + rng.uniform(-0.03, 0.03), 1.0, 1 + rng.uniform(-0.03, 0.03)],
        "noise": rng.uniform(2, 5), "blur": rng.uniform(0.4, 0.9), "jpeg": rng.integers(80, 93),
    }
    light_finish = {
        **light_mat,
        "gain": light_mat["gain"] * rng.uniform(0.85, 1.15),
        "gx": light_mat["gx"] + rng.uniform(-0.1, 0.1),
        "gy": light_mat["gy"] + rng.uniform(-0.1, 0.1),
        "tint": [light_mat["tint"][0] + rng.uniform(-0.03, 0.03), 1.0, light_mat["tint"][2] + rng.uniform(-0.03, 0.03)],
        "noise": rng.uniform(2, 5), "jpeg": rng.integers(80, 93),
    }
    mat_photo = photograph(mat_plane, shaken(homography, rng), light_mat, rng)
    finish_photo = photograph(plane, homography, light_finish, rng)

    def centre_of(row: int, column: int) -> list[float]:
        point = np.float32([[[left + (column + 0.5) * cell, top + (row + 0.5) * cell]]])

        return [float(v) for v in cv2.perspectiveTransform(point, homography).reshape(2)]

    hidden = []

    if condition == "occluded":
        # An arm from the near edge of the photo, its hand over the puzzle - over the hole in half of the gap cases
        if dropped and rng.random() < 0.5:
            target = dropped[0]
            hidden.append(target)
        else:
            target = (int(rng.integers(rows // 2, rows)), int(rng.integers(0, columns)))

        hand = np.int32(centre_of(*target))
        start = np.int32([hand[0] + rng.integers(-200, 200), IMAGE_H + 20])
        skin = (int(rng.integers(110, 140)), int(rng.integers(140, 170)), int(rng.integers(190, 225)))
        thickness = int(rng.integers(80, 120))
        cv2.line(finish_photo, tuple(int(v) for v in start), tuple(int(v) for v in hand), skin, thickness, cv2.LINE_AA)
        cv2.ellipse(finish_photo, tuple(int(v) for v in hand), (int(thickness * 0.75), int(thickness * 0.6)),
                    float(rng.uniform(0, 180)), 0, 360, skin, -1, cv2.LINE_AA)
        # Every other missing cell under the arm is hidden too
        arm = np.zeros(finish_photo.shape[:2], np.uint8)
        cv2.line(arm, tuple(int(v) for v in start), tuple(int(v) for v in hand), 1, thickness)
        cv2.ellipse(arm, tuple(int(v) for v in hand), (int(thickness * 0.75), int(thickness * 0.6)), 0, 0, 360, 1, -1)

        for missing in dropped:
            x, y = (int(v) for v in centre_of(*missing))

            if missing not in hidden and 0 <= y < IMAGE_H and 0 <= x < IMAGE_W and arm[y, x]:
                hidden.append(missing)

    return {
        "index": index,
        "motif": motif_kind,
        "condition": condition,
        "mat": mat_name,
        "rows": rows,
        "columns": columns,
        "dropped": dropped,
        "hidden": hidden,
        "truth": GAPS if dropped else COMPLETE,
        "centres": {f"{r},{c}": centre_of(r, c) for r, c in dropped},
        "cellPx": cell * abs(np.linalg.det(homography[:2, :2])) ** 0.5,
        "mat_photo": mat_photo,
        "finish_photo": finish_photo,
        "reference": motif,
    }


def found_all(verdict: dict, case: dict) -> bool:
    """Every visible missing cell's centre lies in a reported box (half a cell of slack)."""
    slack = case["cellPx"] / 2

    for key, (x, y) in case["centres"].items():
        row, column = (int(v) for v in key.split(","))

        if (row, column) in case["hidden"]:
            continue

        if not any(box["x"] - slack <= x <= box["x"] + box["w"] + slack and box["y"] - slack <= y <= box["y"] + box["h"] + slack for box in verdict["gaps"]):
            return False

    return True


def matrix(records: list[dict], approach: str) -> dict:
    table = {truth: {verdict: 0 for verdict in VERDICTS} for truth in (COMPLETE, GAPS)}

    for record in records:
        table[record["truth"]][record[approach]["verdict"]] += 1

    return table


def print_matrix(title: str, records: list[dict], approach: str) -> None:
    table = matrix(records, approach)
    gap_cases = sum(table[GAPS].values())
    complete_cases = sum(table[COMPLETE].values())
    found = sum(1 for record in records if record["truth"] == GAPS and record[approach]["verdict"] == GAPS and record[approach]["found"])
    print(f"  {title:<34} complete   gaps unsure")
    print(f"    truth complete (n={complete_cases:>3})       {table[COMPLETE][COMPLETE]:>5} {table[COMPLETE][GAPS]:>6} {table[COMPLETE][UNSURE]:>6}")
    print(f"    truth gaps     (n={gap_cases:>3})       {table[GAPS][COMPLETE]:>5} {table[GAPS][GAPS]:>6} {table[GAPS][UNSURE]:>6}"
          f"   <- false complete: {table[GAPS][COMPLETE]}; gaps located: {found} of {table[GAPS][GAPS]}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--per-cell", type=int, default=6, help="cases per motif and missing-piece count on a contrasting mat")
    parser.add_argument("--others", type=int, default=2, help="cases per motif and count with an arm over the puzzle / on a table")
    parser.add_argument("--seed", type=int, default=7)
    parser.add_argument("--out", type=Path, default=None, help="write cases.json and example photos here")
    args = parser.parse_args()

    plan = []

    for condition in CONDITIONS:
        repeat = args.per_cell if condition == "contrasting" else args.others

        for motif_kind in MOTIFS:
            for dropped_count in (0, 1, 2):
                for _ in range(repeat):
                    plan.append((motif_kind, dropped_count, condition))

    records = []
    started = time.time()

    if args.out:
        args.out.mkdir(parents=True, exist_ok=True)

    for index, (motif_kind, dropped_count, condition) in enumerate(plan):
        case = build_case(index, motif_kind, dropped_count, condition, args.seed)
        timings = {}
        t0 = time.time()
        holes_verdict = check_holes(case["mat_photo"], case["finish_photo"], case["rows"] * case["columns"])
        timings["holes"] = time.time() - t0
        t0 = time.time()
        grid_verdict = check_grid(case["reference"], case["finish_photo"], case["rows"], case["columns"])
        timings["grid"] = time.time() - t0
        holes, grid = holes_verdict.as_dict(), grid_verdict.as_dict()
        both = combine(holes_verdict, grid_verdict).as_dict()

        for verdict in (holes, grid, both):
            verdict["found"] = verdict["verdict"] == GAPS and found_all(verdict, case)

        record = {
            key: case[key] for key in ("index", "motif", "condition", "mat", "rows", "columns", "dropped", "hidden", "truth")
        }
        record.update({"holes": holes, "grid": grid, "both": both, "seconds": {k: round(v, 3) for k, v in timings.items()}})
        records.append(record)

        if args.out and index % 9 == 0:
            cv2.imwrite(str(args.out / f"case-{index:03d}-finish.jpg"), case["finish_photo"])
            cv2.imwrite(str(args.out / f"case-{index:03d}-mat.jpg"), case["mat_photo"])

        print(f"case {index:3d} {condition:<11} {motif_kind:<11} {case['rows'] * case['columns']:>4} pcs missing {len(case['dropped'])}"
              f"{' (hidden ' + str(len(case['hidden'])) + ')' if case['hidden'] else '':<11} -> holes {holes['verdict']:<8} grid {grid['verdict']:<8} both {both['verdict']}",
              file=sys.stderr)

    print(f"\nCompleteness self-test: {len(records)} synthetic finish photos, seed {args.seed}, {time.time() - started:.0f} s "
          f"(holes {np.median([r['seconds']['holes'] for r in records]):.2f} s, grid {np.median([r['seconds']['grid'] for r in records]):.2f} s per photo, median)\n")

    for approach, title in (("holes", "Background holes"), ("grid", "Reference-grid matching"), ("both", "Both (complete only if both)")):
        print(f"{title}")
        print_matrix("all cases", records, approach)

        for condition in CONDITIONS:
            print_matrix(f"{condition}", [r for r in records if r["condition"] == condition], approach)

        for motif_kind in MOTIFS:
            print_matrix(f"{motif_kind} (contrasting mat)", [r for r in records if r["motif"] == motif_kind and r["condition"] == "contrasting"], approach)

        print()

    false_complete = {approach: sum(1 for r in records if r["truth"] == GAPS and r[approach]["verdict"] == COMPLETE) for approach in ("holes", "grid", "both")}
    print("False complete (a missing piece passed): " + ", ".join(f"{k} {v}" for k, v in false_complete.items()))

    if args.out:
        (args.out / "cases.json").write_text(json.dumps(records, indent=1, default=float))
        print(f"Cases written to {args.out / 'cases.json'}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
