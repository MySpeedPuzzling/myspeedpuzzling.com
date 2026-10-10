#!/usr/bin/env python3
"""Last-piece reveal geometry: the 3 x 3 jigsaw cut used by the badge reveal and the XP launch reveal.

    tools/badge-art/.venv/bin/python -B tools/badge-art/reveal/geometry.py            # (re)write the two files
    tools/badge-art/.venv/bin/python -B tools/badge-art/reveal/geometry.py --check    # exit 1 if they would change

Writes (both committed, read by assets/last_piece_reveal/reveal.js and the reveal templates/CSS):
  assets/last_piece_reveal/geometry.json   piece outlines + seams + confetti shapes (slim, compact JSON)
  assets/img/last-piece-reveal-tray.svg    the unrevealed tray: the cut + a Rubik 700 question mark
Self-check report and a visual check: tools/badge-art/work/reveal-geometry-report.txt + reveal-geometry-check.svg.

Moved from the badge lab without changing the geometry (2026-10-10): poc/P2-last-piece-reveal/recipe/geometry.py
(the cut, D06's knob_template) + RV2's make_tray.py (slim JSON + tray). Only the paths changed; the output is
byte-identical to the files RV2 committed (verified). The question mark needs the VARIABLE Rubik font instanced at
wght 700 (fonts/Rubik[wght].ttf, OFL, pinned by sha256): the static Rubik-Bold.ttf in assets/fonts has integer-
rounded outlines and would change the tray's bytes.

Geometry rule (BRIEF-PHASE2 rule 5, the MSP "puzzle piece geometry"): every interior side of every piece carries
exactly ONE connector, centred on the side: a circular knob on a short narrow neck, or the identical socket. All
connectors have the same size. Outer sides are flat (border pieces of a real jigsaw), the puzzle's outer corners are
rounded. Deterministic: no randomness.
"""
import argparse
import hashlib
import json
import math
import os
import pathlib
import re
import sys

os.environ.setdefault("PYTHONDONTWRITEBYTECODE", "1")
sys.dont_write_bytecode = True
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from shapely.geometry import LineString, MultiLineString, MultiPolygon, Point, Polygon, box  # noqa: E402
from shapely.ops import linemerge, unary_union  # noqa: E402

from lib import paths  # noqa: E402

FONT = pathlib.Path(__file__).resolve().parent / "fonts" / "Rubik[wght].ttf"
FONT_SHA256 = "1b3a7437ba2af80e465e773ed60c5036d1ba6ace492d89046dbcf18fb31e4e88"   # Rubik v2.300 (Google Fonts)
OUT_JSON = paths.REPO / "assets" / "last_piece_reveal" / "geometry.json"
OUT_TRAY = paths.REPO / "assets" / "img" / "last-piece-reveal-tray.svg"

S = 300.0          # puzzle side in units (the page maps 1 unit = 1 CSS px at the default 300 px stage)
N = 3              # 3 x 3
CELL = S / N
R_OUT = 26.0       # outer corner radius of the whole puzzle (8.7 % of the side; MSP rects >= 10 % of a piece)
MARGIN = 3         # bbox margin around a piece for its outline stroke (units)

# connector anatomy in fractions of the cell side (D06 knob_template parameters)
KNOB = dict(nw=0.100,   # neck half width   -> neck 0.20 of the side (0.63 x head)
            rh=0.158,   # head radius       -> head 0.316 of the side
            hc=0.185,   # head centre beyond the edge -> protrusion 0.343
            k=0.042)    # fillet radius (closing)


# ------------------------------------------------------------------ D06 geometry (copied unchanged)
def knob_template(nw, rh, hc, k):
    """Boundary of 'flat edge with one knob' in (l, w) space, l: 0..1 along the edge, w>0 = knob side."""
    H = unary_union([box(-0.6, -1.0, 1.6, 0.0),
                     Point(0.5, hc).buffer(rh, quad_segs=96),
                     box(0.5 - nw, -0.05, 0.5 + nw, hc)])
    H = H.buffer(k, quad_segs=48).buffer(-k, quad_segs=48)  # closing: smooth fillets in the concave corners
    ring = LineString(list(H.exterior.coords))
    seg = ring.intersection(box(0.0, -0.5, 1.0, 2.0))
    if isinstance(seg, MultiLineString):
        seg = linemerge(seg)
    if isinstance(seg, MultiLineString):  # pick the longest (the top boundary)
        seg = max(seg.geoms, key=lambda g: g.length)
    pts = list(seg.coords)
    if pts[0][0] > pts[-1][0]:
        pts = pts[::-1]
    pts[0] = (0.0, 0.0)
    pts[-1] = (1.0, 0.0)
    return pts


def edge_pts(a, b, sign_normal, tpl):
    """map the knob template onto the world edge a->b; knob protrudes along sign_normal (unit vector)."""
    ax, ay = a
    bx, by = b
    L = math.hypot(bx - ax, by - ay)
    ux, uy = (bx - ax) / L, (by - ay) / L
    mx, my = sign_normal
    return [(ax + l * L * ux + w * L * mx, ay + l * L * uy + w * L * my) for l, w in tpl]


def layout_signs_pinwheel():
    """D06 'pinwheel' layout: the centre has 4 knobs (the hero piece), the ring turns like a pinwheel, so every
    border piece has a mix of knobs and sockets (real-puzzle look). h[(r,c)]: edge between row r and r+1 in
    column c (+1 = knob points down); v[(r,c)]: edge between col c and c+1 in row r (+1 = knob points right)."""
    h = {(0, 1): -1, (1, 1): +1}
    v = {(1, 0): -1, (1, 1): +1}
    v.update({(0, 0): +1, (0, 1): +1, (2, 0): -1, (2, 1): -1})
    h.update({(0, 2): +1, (1, 2): +1, (0, 0): -1, (1, 0): -1})
    return h, v


# ------------------------------------------------------------------ helpers
def rounded_square(x0, y0, x1, y1, r):
    return box(x0 + r, y0 + r, x1 - r, y1 - r).buffer(r, quad_segs=48)


def d_of(geom, nd=2, tol=0.04):
    """polygon -> SVG path data (simplified by tol units: invisible at 2x DPR)."""
    if tol:
        geom = geom.simplify(tol, preserve_topology=True)
    polys = list(geom.geoms) if isinstance(geom, MultiPolygon) else [geom]
    out = []
    f = "%%.%df" % nd

    def num(v):
        s = (f % v).rstrip("0").rstrip(".")
        return "0" if s in ("-0", "") else s

    for pg in polys:
        c = list(pg.exterior.coords)[:-1]
        out.append("M" + " ".join(num(x) + " " + num(y) for x, y in c) + "Z")
    return "".join(out)


def line_d(pts, nd=2):
    def num(v):
        s = ("%.*f" % (nd, v)).rstrip("0").rstrip(".")
        return "0" if s in ("-0", "") else s
    return "M" + " ".join(num(x) + " " + num(y) for x, y in pts)


# ------------------------------------------------------------------ the 3 x 3 cut
def build_puzzle():
    tpl = knob_template(KNOB["nw"], KNOB["rh"], KNOB["hc"], KNOB["k"])
    X = [i * CELL for i in range(N + 1)]
    outer = rounded_square(0, 0, S, S, R_OUT)
    h, v = layout_signs_pinwheel()
    H, V = {}, {}
    for (r, c), sg in h.items():
        a, b = (X[c], X[r + 1]), (X[c + 1], X[r + 1])
        H[(r, c)] = edge_pts(a, b, (0, sg), tpl)
    for (r, c), sg in v.items():
        a, b = (X[c + 1], X[r]), (X[c + 1], X[r + 1])
        V[(r, c)] = edge_pts(a, b, (sg, 0), tpl)
    pieces = []
    for r in range(N):
        for c in range(N):
            x0, x1, y0, y1 = X[c], X[c + 1], X[r], X[r + 1]
            pts = []
            pts += [(x0, y0), (x1, y0)] if r == 0 else H[(r - 1, c)]
            pts += [(x1, y0), (x1, y1)] if c == N - 1 else V[(r, c)]
            pts += [(x1, y1), (x0, y1)] if r == N - 1 else H[(r, c)][::-1]
            pts += [(x0, y1), (x0, y0)] if c == 0 else V[(r, c - 1)][::-1]
            poly = Polygon(pts).buffer(0).intersection(outer)
            # connector kinds per side, seen from this piece: +1 knob, -1 socket, 0 flat
            sides = {
                "top": 0 if r == 0 else (+1 if h[(r - 1, c)] < 0 else -1),
                "right": 0 if c == N - 1 else (+1 if v[(r, c)] > 0 else -1),
                "bottom": 0 if r == N - 1 else (+1 if h[(r, c)] > 0 else -1),
                "left": 0 if c == 0 else (+1 if v[(r, c - 1)] < 0 else -1),
            }
            pieces.append(dict(r=r, c=c, poly=poly, sides=sides, slot=((x0 + x1) / 2, (y0 + y1) / 2)))
    seams = [line_d(list(LineString(p).simplify(0.04).coords)) for p in list(H.values()) + list(V.values())]
    return pieces, seams, outer, tpl


def verify(pieces, tpl):
    """Rule 5 self-check: one connector per interior side, centred, all the same size; pieces tile the square."""
    report = []
    union = unary_union([p["poly"] for p in pieces])
    outer = rounded_square(0, 0, S, S, R_OUT)
    gap = outer.symmetric_difference(union).area
    overlap = sum(p["poly"].area for p in pieces) - union.area
    report.append(f"tiling: area gap {gap:.4f}, overlap {overlap:.4f} (units^2, should be ~0)")
    ys = [w for _, w in tpl]
    xs_at_peak = [l for l, w in tpl if w > max(ys) - 1e-6]
    report.append(f"knob: protrusion {max(ys) * CELL:.2f} u = {max(ys):.3f} of the side, apex at l="
                  f"{sum(xs_at_peak) / len(xs_at_peak):.4f} (0.5 = centred)")
    # symmetry: the knob region (template closed along w=0) mirrored about l=0.5 equals itself
    region = Polygon(tpl + [(1.0, -0.01), (0.0, -0.01)]).buffer(0)
    from shapely import affinity
    mirrored = affinity.scale(region, xfact=-1, yfact=1, origin=(0.5, 0))
    asym = region.symmetric_difference(mirrored).area / max(region.area - 0.01, 1e-9)
    report.append(f"knob symmetry: mirror mismatch {asym * 100:.3f} % of the knob area (0 = symmetric)")
    report.append(f"knob head: diameter {2 * KNOB['rh']:.3f}, neck {2 * KNOB['nw']:.3f} of the side "
                  f"(neck/head {KNOB['nw'] / KNOB['rh']:.2f})")
    for p in pieces:
        n_conn = {k: (0 if s == 0 else 1) for k, s in p["sides"].items()}
        interior = [k for k, s in p["sides"].items() if s != 0]
        assert all(n_conn[k] == 1 for k in interior)
        report.append(f"piece r{p['r']}c{p['c']}: " + " ".join(
            f"{k}={'knob' if s > 0 else 'socket' if s < 0 else 'flat'}" for k, s in p["sides"].items()))
    return report


# ------------------------------------------------------------------ confetti pieces (interior pieces, 4 connectors)
def confetti_piece(sides):
    """unit piece: body side 1 centred on (0,0); sides = (top, right, bottom, left) with +1 knob / -1 socket."""
    tpl = knob_template(KNOB["nw"], KNOB["rh"], KNOB["hc"], KNOB["k"])
    a, b, c, d = (-0.5, -0.5), (0.5, -0.5), (0.5, 0.5), (-0.5, 0.5)
    pts = []
    pts += edge_pts(a, b, (0, -1 * sides[0]), tpl)
    pts += edge_pts(b, c, (1 * sides[1], 0), tpl)
    pts += edge_pts(c, d, (0, 1 * sides[2]), tpl)
    pts += edge_pts(d, a, (-1 * sides[3], 0), tpl)
    poly = Polygon(pts).buffer(0)
    poly = poly.buffer(-0.02, quad_segs=16).buffer(0.02, quad_segs=16)  # soften the 4 body corners a hair
    return d_of(poly, nd=3, tol=0.002)


def bbox_int(poly, m=MARGIN):
    x0, y0, x1, y1 = poly.bounds
    return [math.floor(x0 - m), math.floor(y0 - m), math.ceil(x1 + m) - math.floor(x0 - m),
            math.ceil(y1 + m) - math.floor(y0 - m)]


def full_geometry():
    """P2's full geometry dict (what the lab wrote to work/geometry.json) + its self-check report + check SVG."""
    pieces, seams, outer, tpl = build_puzzle()
    report = verify(pieces, tpl)
    out = dict(
        size=S, cell=CELL, r_out=R_OUT, knob=KNOB,
        outer=d_of(outer),
        seams=seams,
        pieces=[dict(
            id=f"r{p['r']}c{p['c']}", r=p["r"], c=p["c"], d=d_of(p["poly"]),
            bbox=bbox_int(p["poly"]),
            slot=[round(p["slot"][0], 2), round(p["slot"][1], 2)],
            centroid=[round(p["poly"].centroid.x, 2), round(p["poly"].centroid.y, 2)],
            sides=p["sides"]) for p in pieces],
        # confetti: two classic interior pieces (alternating = 2 knobs opposite; adjacent = 2 knobs side by side)
        confetti=[confetti_piece((+1, -1, +1, -1)), confetti_piece((+1, +1, -1, -1)), confetti_piece((-1, +1, +1, +1))],
    )
    parts = []                                   # visual check: exploded pieces + the confetti shapes
    for p, q in zip(pieces, out["pieces"]):
        dx = (q["slot"][0] - S / 2) * 0.22
        dy = (q["slot"][1] - S / 2) * 0.22
        fill = "#fdd26f" if (p["r"], p["c"]) == (1, 1) else "#eef1f9"
        parts.append(f'<path d="{q["d"]}" transform="translate({dx + 40:.1f},{dy + 40:.1f})" fill="{fill}" '
                     f'stroke="#2b3445" stroke-width="2.4" stroke-linejoin="round"/>')
    for i, cd in enumerate(out["confetti"]):
        parts.append(f'<path d="{cd}" transform="translate({460 + i * 95},{120}) scale(60)" fill="#fb8180" '
                     f'stroke="#2b3445" stroke-width="{2.4 / 60:.4f}" stroke-linejoin="round"/>')
    check_svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="760" height="380" viewBox="0 0 760 380">'
                 f'<rect width="760" height="380" fill="#fff"/>' + "".join(parts) + "</svg>")
    return out, report, check_svg


# ------------------------------------------------------------------ RV2: the app's slim JSON + the tray (unchanged)
def r1(d):
    """round every number in path data to 1 decimal (the tray is a picture: 0.1 unit is invisible)"""
    def f(m):
        s = ('%.1f' % float(m.group(0))).rstrip('0').rstrip('.')
        return '0' if s in ('-0', '') else s
    return re.sub(r'-?\d+(?:\.\d+)?', f, d)


def app_files(g, font_path):
    """g = P2's geometry dict (as read back from its JSON). -> (geometry.json text, tray svg text)."""
    from fontTools.pens.boundsPen import BoundsPen
    from fontTools.pens.svgPathPen import SVGPathPen
    from fontTools.pens.transformPen import TransformPen
    from fontTools.ttLib import TTFont
    from fontTools.varLib import instancer

    slim = dict(
        size=g['size'],
        outer=g['outer'],
        seams=g['seams'],
        pieces=[dict(id=p['id'], d=p['d'], bbox=p['bbox'], slot=p['slot'], centroid=p['centroid']) for p in g['pieces']],
        confetti=g['confetti'],
    )
    geometry_json = json.dumps(slim, separators=(',', ':')) + '\n'

    font = instancer.instantiateVariableFont(TTFont(font_path), {'wght': 700})
    gs = font.getGlyphSet()
    name = font.getBestCmap()[ord('?')]
    bp = BoundsPen(gs)
    gs[name].draw(bp)
    x0, y0, x1, y1 = bp.bounds
    k = 66 / font['head'].unitsPerEm               # P2: Rubik 700, 66 px in the 300-unit tray
    cx, cy = 150, 151                              # P2: text-anchor middle at 150, central baseline at 152
    dx = cx - (x0 + x1) / 2 * k
    dy = cy + (y0 + y1) / 2 * k
    pen = SVGPathPen(gs, ntos=lambda v: ('%.1f' % v).rstrip('0').rstrip('.'))
    gs[name].draw(TransformPen(pen, (k, 0, 0, -k, dx, dy)))
    q = pen.getCommands()

    outer = r1(g['outer'])
    seams = ''.join(f'<path d="{r1(s)}"/>' for s in g['seams'])
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 304 304">'
        '<!-- Last-piece reveal tray (the unrevealed look): the 3x3 cut of assets/last_piece_reveal/geometry.json '
        '(badge lab P2, D06 knob_template) and a Rubik 700 question mark. -->'
        '<defs><linearGradient id="t" x1="0" y1="0" x2="0" y2="1">'
        '<stop offset="0" stop-color="#2b3445" stop-opacity=".07"/><stop offset=".22" stop-color="#2b3445" stop-opacity="0"/>'
        '</linearGradient></defs>'
        f'<path d="{outer}" fill="#eef2f9"/><path d="{outer}" fill="url(#t)"/>'
        f'<g fill="none" stroke="#cfd9e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{seams}</g>'
        f'<path d="{outer}" fill="none" stroke="#cfd9e8" stroke-width="2.4"/>'
        f'<path d="{q}" fill="#b9c6dc"/>'
        '</svg>\n'
    )
    return geometry_json, svg


def build():
    """-> ({path: bytes} for the two app files, self-check report lines, check svg). Strict about the font."""
    if not FONT.is_file():
        raise SystemExit(f"reveal: missing {paths.rel(FONT)}")
    if hashlib.sha256(FONT.read_bytes()).hexdigest() != FONT_SHA256:
        raise SystemExit(f"reveal: {paths.rel(FONT)} is not the pinned Rubik[wght].ttf (sha256 differs)")
    full, report, check_svg = full_geometry()
    g = json.loads(json.dumps(full, indent=1))     # the lab chain: P2 wrote this JSON, RV2 read it back
    geometry_json, tray = app_files(g, FONT)
    return {OUT_JSON: geometry_json.encode(), OUT_TRAY: tray.encode()}, report, check_svg


def main(argv):
    ap = argparse.ArgumentParser(description="Rebuild the last-piece reveal geometry JSON + tray SVG.")
    ap.add_argument("--check", action="store_true", help="only compare with the committed files (exit 1 if they differ)")
    a = ap.parse_args(argv)
    paths.check_layout()
    files, report, check_svg = build()
    paths.WORK.mkdir(exist_ok=True)
    (paths.WORK / "reveal-geometry-report.txt").write_text("\n".join(report) + "\n")
    (paths.WORK / "reveal-geometry-check.svg").write_text(check_svg)
    diff = [p for p, data in files.items() if not p.is_file() or p.read_bytes() != data]
    if a.check:
        for p in diff:
            print(f"reveal: {paths.rel(p)} differs from a fresh build")
        print("reveal: " + ("OK - committed files are byte-identical to a fresh build" if not diff else "FAIL"))
        return 1 if diff else 0
    for p in diff:
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(files[p])
    print("\n".join(report))
    print(f"reveal: {len(diff)} of {len(files)} files changed ({', '.join(paths.rel(p) for p in files)})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
