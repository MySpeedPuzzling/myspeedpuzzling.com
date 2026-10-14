"""
The bench's completeness self-test (tools/online-bench/completeness/selftest.py) through the CV worker's HTTP endpoint:
the same synthetic finish photos - contrasting mat, an arm over the puzzle (`occluded`), a table close to the motif's
colours (`table`) - plus `unfinished` ones (a contiguous block of 15-40 % of the pieces not placed, at an edge or
inside, those pieces lying around), served from an in-process HTTP server the worker downloads them from, as the app's
presigned URLs would be. Two passes per photo:

- `mat+reference`: the empty-mat photo and the clean motif - the worker combines both checks (method holes+grid);
- `mat only`: the empty-mat photo alone (method holes) - production's likely case while the reference is a box photo.

Prints the confusion matrices (truth complete / gaps / unfinished x verdict complete / gaps / unsure) per condition
and overall, the false "complete" (a missing piece passed - must be 0), and the time per photo: the worker's own `ms`
(downloads included) and `checkMs`, and the client's round trip.

    python http_selftest.py --worker http://p3-cv:8000 --serve-host p3-fixtures [--serve-port 8080]
                            [--per-cell 6] [--others 2] [--unfinished 2] [--seed 7] [--json OUT]

The photos are served as PNG: they went through JPEG once already (selftest.photograph, like a phone's upload) - a
second compression would make them unlike both a phone's photo and the bench's run.
"""

from __future__ import annotations

import argparse
import http.client
import json
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

import cv2
import numpy as np

HERE = Path(__file__).resolve()
sys.path.insert(0, str(HERE.parent))
sys.path.insert(0, str(HERE.parents[1] / "online-bench" / "completeness"))

import selftest  # noqa: E402
from completeness import COMPLETE, GAPS, UNSURE  # noqa: E402

UNFINISHED = "unfinished"
TRUTHS = [COMPLETE, GAPS, UNFINISHED]
VERDICTS = [COMPLETE, GAPS, UNSURE]
CONDITIONS = [*selftest.CONDITIONS, UNFINISHED]
PASSES = {"mat+reference": ("mat", "reference"), "mat only": ("mat",)}


def build_unfinished(index: int, motif_kind: str, seed: int) -> dict:
    """
    An unfinished puzzle photographed like selftest.build_case: a contiguous block of 15-40 % of the cells not placed
    - a band at an edge or a rectangle inside -, many of those pieces lying around on the mat.
    """
    rng = np.random.default_rng(seed * 1000 + 700 + index)
    rows, columns = selftest.GRIDS[int(rng.integers(0, len(selftest.GRIDS)))]
    puzzle_w = int(rng.integers(1150, 1350))
    cell = puzzle_w / columns
    puzzle_h = int(round(cell * rows))
    motif = selftest.make_motif(motif_kind, puzzle_w, puzzle_h, rng)
    shares = {name: selftest.lab_share_near(motif, colour) for name, colour in selftest.MAT_COLOURS.items()}
    mat_name = min(shares, key=shares.get)
    mat_plane = selftest.make_mat(selftest.MAT_COLOURS[mat_name], rng)
    left = int((selftest.PLANE_W - puzzle_w) / 2 + rng.integers(-90, 90))
    top = int((selftest.PLANE_H - puzzle_h) / 2 + rng.integers(-70, 70))
    plane = mat_plane.copy()
    pieces = motif.copy()

    for column in range(1, columns):
        x = int(round(column * cell))
        cv2.line(pieces, (x, 0), (x, puzzle_h), (0, 0, 0), 1)

    for row in range(1, rows):
        y = int(round(row * cell))
        cv2.line(pieces, (0, y), (puzzle_w, y), (0, 0, 0), 1)

    pieces = cv2.addWeighted(pieces, 0.7, motif, 0.3, 0)
    plane[top:top + puzzle_h, left:left + puzzle_w] = pieces
    share = float(rng.uniform(0.15, 0.40))
    edge = bool(rng.random() < 0.5)

    if edge:
        # A band along one side: whole columns on the right or whole rows at the bottom
        if rng.random() < 0.5:
            count = max(1, round(share * columns))
            missing = [(r, c) for r in range(rows) for c in range(columns - count, columns)]
        else:
            count = max(1, round(share * rows))
            missing = [(r, c) for r in range(rows - count, rows) for c in range(columns)]
    else:
        # A rectangle inside, a cell of the puzzle all round it
        area = share * rows * columns
        height = int(np.clip(round(np.sqrt(area * rows / columns) * rng.uniform(0.8, 1.25)), 1, rows - 2))
        width = int(np.clip(round(area / height), 1, columns - 2))
        r0 = int(rng.integers(1, rows - height))
        c0 = int(rng.integers(1, columns - width))
        missing = [(r, c) for r in range(r0, r0 + height) for c in range(c0, c0 + width)]

    for row, column in missing:
        x0, y0 = left + int(round(column * cell)), top + int(round(row * cell))
        x1, y1 = left + int(round((column + 1) * cell)), top + int(round((row + 1) * cell))
        plane[y0:y1, x0:x1] = mat_plane[y0:y1, x0:x1]

    # Up to 60 of the pieces lie around, turned, never on the puzzle
    for row, column in [missing[i] for i in rng.permutation(len(missing))[:60]]:
        patch = motif[int(round(row * cell)):int(round((row + 1) * cell)), int(round(column * cell)):int(round((column + 1) * cell))]

        for _ in range(50):
            px, py = int(rng.integers(60, selftest.PLANE_W - 120)), int(rng.integers(60, selftest.PLANE_H - 120))

            if px + patch.shape[1] < left - 40 or px > left + puzzle_w + 40 or py + patch.shape[0] < top - 40 or py > top + puzzle_h + 40:
                turn = cv2.getRotationMatrix2D((patch.shape[1] / 2, patch.shape[0] / 2), float(rng.uniform(0, 360)), 1.0)
                mask = cv2.warpAffine(np.full(patch.shape[:2], 255, np.uint8), turn, (patch.shape[1], patch.shape[0]))
                turned = cv2.warpAffine(patch, turn, (patch.shape[1], patch.shape[0]))
                region = plane[py:py + patch.shape[0], px:px + patch.shape[1]]
                region[mask > 0] = turned[mask > 0]
                break

    homography = None

    for _ in range(20):
        candidate = selftest.camera(rng)
        corners = np.float32([[left, top], [left + puzzle_w, top], [left + puzzle_w, top + puzzle_h], [left, top + puzzle_h]]).reshape(-1, 1, 2)
        projected = cv2.perspectiveTransform(corners, candidate).reshape(-1, 2)

        if projected[:, 0].min() > 30 and projected[:, 0].max() < selftest.IMAGE_W - 30 and projected[:, 1].min() > 30 and projected[:, 1].max() < selftest.IMAGE_H - 30:
            homography = candidate
            break

    if homography is None:
        raise RuntimeError("no camera sees the whole puzzle")

    light_mat = {
        "gain": rng.uniform(0.9, 1.08), "gx": rng.uniform(-0.15, 0.15), "gy": rng.uniform(-0.15, 0.15),
        "tint": [1 + rng.uniform(-0.03, 0.03), 1.0, 1 + rng.uniform(-0.03, 0.03)],
        "noise": rng.uniform(2, 5), "blur": rng.uniform(0.4, 0.9), "jpeg": rng.integers(80, 93),
    }
    light_finish = {**light_mat, "gain": light_mat["gain"] * rng.uniform(0.85, 1.15), "noise": rng.uniform(2, 5), "jpeg": rng.integers(80, 93)}

    return {
        "index": index,
        "motif": motif_kind,
        "condition": UNFINISHED,
        "mat": mat_name,
        "rows": rows,
        "columns": columns,
        "dropped": missing,
        "hidden": [],
        "truth": UNFINISHED,
        "missingShare": round(len(missing) / (rows * columns), 3),
        "edge": edge,
        "centres": {},
        "cellPx": cell,
        "mat_photo": selftest.photograph(mat_plane, selftest.shaken(homography, rng), light_mat, rng),
        "finish_photo": selftest.photograph(plane, homography, light_finish, rng),
        "reference": motif,
    }


class Files:
    """The fixture store: path -> PNG bytes, served to the worker"""

    def __init__(self, host: str, port: int):
        self.files = {}
        files = self.files

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, format, *args):  # noqa: A002
                pass

            def do_GET(self):  # noqa: N802
                body = files.get(urlsplit(self.path).path)

                if body is None:
                    self.send_response(404)
                    self.send_header("Content-Length", "0")
                    self.end_headers()

                    return

                self.send_response(200)
                self.send_header("Content-Type", "image/png")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        self.server = ThreadingHTTPServer((host, port), Handler)
        self.server.daemon_threads = True
        threading.Thread(target=self.server.serve_forever, daemon=True).start()


def post(worker: str, body: dict) -> tuple[int, dict, float]:
    parts = urlsplit(worker)
    connection = http.client.HTTPConnection(parts.hostname, parts.port or 80, timeout=120)
    data = json.dumps(body).encode()
    started = time.monotonic()
    connection.request("POST", "/check", body=data, headers={"Content-Type": "application/json"})
    response = connection.getresponse()
    payload = json.loads(response.read() or b"{}")
    connection.close()

    return response.status, payload, (time.monotonic() - started) * 1000


def wait_for(worker: str) -> dict:
    parts = urlsplit(worker)

    for _ in range(120):
        try:
            connection = http.client.HTTPConnection(parts.hostname, parts.port or 80, timeout=2)
            connection.request("GET", "/health")
            response = connection.getresponse()

            if response.status == 200:
                return json.loads(response.read())
        except OSError:
            pass

        time.sleep(0.5)

    raise SystemExit(f"The worker at {worker} does not answer")


def percentile(values: list[float], p: float) -> float:
    return float(np.percentile(values, p)) if values else float("nan")


def print_matrix(title: str, records: list[dict], pass_name: str) -> None:
    table = {truth: {verdict: 0 for verdict in VERDICTS} for truth in TRUTHS}

    for record in records:
        table[record["truth"]][record[pass_name]["verdict"]] += 1

    print(f"  {title:<34} complete   gaps unsure")

    for truth in TRUTHS:
        n = sum(table[truth].values())

        if n == 0:
            continue

        line = f"    truth {truth:<10} (n={n:>3})  {table[truth][COMPLETE]:>5} {table[truth][GAPS]:>6} {table[truth][UNSURE]:>6}"

        if truth == GAPS:
            found = sum(1 for r in records if r["truth"] == GAPS and r[pass_name]["verdict"] == GAPS and r[pass_name]["found"])
            line += f"   gaps located: {found} of {table[GAPS][GAPS]}"

        if truth != COMPLETE and table[truth][COMPLETE] > 0:
            line += f"   <- FALSE COMPLETE: {table[truth][COMPLETE]}"

        print(line)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--worker", required=True, help="the worker's base URL, e.g. http://p3-cv:8000")
    parser.add_argument("--serve-host", required=True, help="the name the worker reaches this script's file server by")
    parser.add_argument("--serve-port", type=int, default=8080)
    parser.add_argument("--bind", default="0.0.0.0")
    parser.add_argument("--per-cell", type=int, default=6, help="cases per motif and missing-piece count on a contrasting mat")
    parser.add_argument("--others", type=int, default=2, help="cases per motif and count with an arm over the puzzle / on a table")
    parser.add_argument("--unfinished", type=int, default=2, help="unfinished cases per motif")
    parser.add_argument("--seed", type=int, default=7)
    parser.add_argument("--json", type=Path, default=None)
    args = parser.parse_args()

    health = wait_for(args.worker)
    files = Files(args.bind, args.serve_port)
    base = f"http://{args.serve_host}:{args.serve_port}"
    plan = []

    for condition in selftest.CONDITIONS:
        repeat = args.per_cell if condition == "contrasting" else args.others

        for motif_kind in selftest.MOTIFS:
            for dropped_count in (0, 1, 2):
                for _ in range(repeat):
                    plan.append((motif_kind, dropped_count, condition))

    for motif_kind in selftest.MOTIFS:
        for _ in range(args.unfinished):
            plan.append((motif_kind, None, UNFINISHED))

    records = []
    started = time.time()
    unfinished_index = 0

    for index, (motif_kind, dropped_count, condition) in enumerate(plan):
        if condition == UNFINISHED:
            case = build_unfinished(unfinished_index, motif_kind, args.seed)
            unfinished_index += 1
        else:
            case = selftest.build_case(index, motif_kind, dropped_count, condition, args.seed)

        prefix = f"/case-{index:03d}"
        files.files[f"{prefix}/finish.png"] = cv2.imencode(".png", case["finish_photo"])[1].tobytes()
        files.files[f"{prefix}/mat.png"] = cv2.imencode(".png", case["mat_photo"])[1].tobytes()
        files.files[f"{prefix}/reference.png"] = cv2.imencode(".png", case["reference"])[1].tobytes()
        record = {key: case[key] for key in ("index", "motif", "condition", "mat", "rows", "columns", "truth")}
        record["missing"] = len(case["dropped"])
        record["hidden"] = len(case["hidden"])

        for pass_name, inputs in PASSES.items():
            body = {
                "finishUrl": f"{base}{prefix}/finish.png?X-Amz-Signature=test",
                "emptyMatUrl": f"{base}{prefix}/mat.png" if "mat" in inputs else None,
                "referenceUrl": f"{base}{prefix}/reference.png" if "reference" in inputs else None,
                "pieceCount": case["rows"] * case["columns"],
            }
            status, answer, round_trip = post(args.worker, body)

            if status != 200:
                raise SystemExit(f"case {index} {pass_name}: the worker answered {status} {answer}")

            answer["found"] = answer["verdict"] == GAPS and (case["truth"] != GAPS or selftest.found_all(answer, case))
            answer["roundTripMs"] = round(round_trip)
            record[pass_name] = {key: answer[key] for key in ("verdict", "confidence", "reason", "method", "ms", "checkMs", "roundTripMs", "found", "gaps")}

        for path in [f"{prefix}/finish.png", f"{prefix}/mat.png", f"{prefix}/reference.png"]:
            del files.files[path]

        records.append(record)
        print(f"case {index:3d} {condition:<11} {motif_kind:<11} {case['rows'] * case['columns']:>4} pcs missing {record['missing']:>3}"
              f"{' (hidden ' + str(record['hidden']) + ')' if record['hidden'] else '':<11}"
              f" -> mat+reference {record['mat+reference']['verdict']:<8} ({record['mat+reference']['method']}, {record['mat+reference']['ms']} ms)"
              f"  mat only {record['mat only']['verdict']:<8} ({record['mat only']['ms']} ms)", file=sys.stderr, flush=True)

    print(f"\nCompleteness self-test through the worker's HTTP endpoint ({args.worker}, version {health['version']}): "
          f"{len(records)} synthetic finish photos, seed {args.seed}, {time.time() - started:.0f} s\n")

    for pass_name in PASSES:
        method = sorted({record[pass_name]["method"] for record in records})
        print(f"Pass '{pass_name}' (method {', '.join(method)})")
        print_matrix("all cases", records, pass_name)

        for condition in CONDITIONS:
            print_matrix(condition, [r for r in records if r["condition"] == condition], pass_name)

        for motif_kind in selftest.MOTIFS:
            print_matrix(f"{motif_kind} (contrasting mat)", [r for r in records if r["motif"] == motif_kind and r["condition"] == "contrasting"], pass_name)

        worker_ms = [r[pass_name]["ms"] for r in records]
        check_ms = [r[pass_name]["checkMs"] for r in records]
        round_trip = [r[pass_name]["roundTripMs"] for r in records]
        print(f"  time per photo: worker ms median {percentile(worker_ms, 50):.0f}, p90 {percentile(worker_ms, 90):.0f}, max {max(worker_ms)}"
              f" (check only: median {percentile(check_ms, 50):.0f}, p90 {percentile(check_ms, 90):.0f}, max {max(check_ms)});"
              f" round trip median {percentile(round_trip, 50):.0f}, p90 {percentile(round_trip, 90):.0f}, max {max(round_trip)}")
        print()

    false_complete = {
        pass_name: sum(1 for r in records if r["truth"] != COMPLETE and r[pass_name]["verdict"] == COMPLETE)
        for pass_name in PASSES
    }
    print("False complete (a missing piece or an unfinished puzzle passed): " + ", ".join(f"{k} {v}" for k, v in false_complete.items()))

    if args.json:
        args.json.write_text(json.dumps(records, indent=1, default=float))
        print(f"Cases written to {args.json}")

    return 0 if all(value == 0 for value in false_complete.values()) else 1


if __name__ == "__main__":
    sys.exit(main())
