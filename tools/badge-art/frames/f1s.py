"""F1S-A ribbon seal: the single-tier / one-off base (owner decision 2026-10-10, designed by the lab's agent SB;
copied from BK1 recipe/specials.py `ribbon_seal`). `freeze_bases.py` exports one under/over pair per SET COLOUR.

A one-off badge = this base in a set colour + ANY icon in the tiered bases' slot (F1 plate 308, icon 543 px, the same
optical fit) + the icon's accent in a set colour (registry: `set_colour`, optional `accent`, default = set colour).
  under  a white paper seal (r 402) with a set-colour ring around the plate (ring 72 px), the navy seal outline and
         the white plate - everything drawn before the icon;
  over   a ribbon banner across the bottom, in front: smile-curved band + notched tails folded behind, with a white
         rim light on the band - drawn after the icon (it never touches the icon: its inner edge clears the icon's
         fit circle by 18 px, so ANY icon fits).
Never a tier: no scallops, no hanging tails, no sunburst, no crown, no stars/sparkles; set colours are brand tones
(never a metal). No white gloss arc on the seal (owner 2026-10-10, consistent with F1).
"""
import math

from .f1 import PLATE
from .kit import C, LIMIT, OW, WHITE, Polygon, Svg, circle, fill, highlight_stroke, outline, plate, smooth, wash

# Set colours: S1's measured brand tones (frame: light / base / shade / deep) + the icon colourway built with IK1's
# method (icon = the accent wash base, pale = the pale pair). Coral = the house colourway (the -msp icon file).
SETS = {
    "coral": dict(name="Coral", light="#FDD5D2", base="#FB8180", shade="#EE6769", deep="#D24F52", streak=0.85,
                  icon="#fb8180", pale=("#fff0ee", "#fdd6d2")),
    "sky": dict(name="Sky", light="#CFE5FD", base="#75B5FA", shade="#5A9FF0", deep="#3F86DA", streak=0.85,
                icon="#75b5fa", pale=("#eef6ff", "#c9e1fd")),
    "lavender": dict(name="Lavender", light="#E3E0FC", base="#9A93F4", shade="#7D76E3", deep="#5F57CC", streak=0.85,
                     icon="#9a93f4", pale=("#f3f1ff", "#d8d3fb")),
    "teal": dict(name="Teal", light="#D6F1F2", base="#8FD5DA", shade="#69C3C8", deep="#46A9AE", streak=0.85,
                 icon="#8fd5da", pale=("#effafb", "#cfeff1")),
}
# white paper: S1 faces #eef1f9 .. #f0f3fc, shade = ground shadow #dbe5f4
PAPER = dict(name="Paper", light="#FFFFFF", base="#F3F6FC", shade="#D9E2F1", deep="#C3CEE3", streak=1.0)

# ring_w 72 = the narrowest ring whose mean colour stays farther from every tier than the ladder's weakest colour step,
# in every set colour (lavender vs T4 platinum is the tight pair: dE 12.9 vs 12.1).
RIBBON = dict(rs=402.0, ring_w=72.0, body="white", off=360.0, gap=18.0, h=100.0, phi=24.0, tail_k=46.0,
              tail_drop=40.0, notch=32.0, tail_tilt=14.0, tail_len=None, band_role="base", fold_tone="deep",
              banner=True)


def ribbon_params(P=None):
    P = dict(RIBBON, **(P or {}))
    P["clear"] = P.get("clear") or PLATE + P["gap"]
    return P


def _local(E, u, n, pts):
    return [(E[0] + s * u[0] + t * n[0], E[1] + s * u[1] + t * n[1]) for s, t in pts]


def _ray_circle(P, d, ctr, R):
    """Smallest t >= 0 with |P + t d - ctr| = R (P inside the circle)."""
    px, py = P[0] - ctr[0], P[1] - ctr[1]
    bq = px * d[0] + py * d[1]
    cq = px * px + py * py - R * R
    return -bq + math.sqrt(max(bq * bq - cq, 0.0))


def banner_geometry(P):
    """A ribbon banner curved like a smile below the plate, as plain geometry (deterministic):
    - band: the ring between R1 = off + clear and R1 + h around (C, C - off), so the disc of radius `clear` around the
      badge centre (the icon's fit circle + half an outline + a gap) stays inside R1: the band never touches ANY icon;
    - each end shares ONE frame (u = along the tail, outward; n = across it, down): the band is cut along n through
      the centre-line point at +-phi, the tail runs outward from k under the band (dropped by d, V-notch), the fold
      (the ribbon's back side) is the triangle under the band's bottom corner A: A, A - k u, A - k u + d n.
    Returns (band, [(tail, fold)], (yc, R1, R2))."""
    off, clear, h, phi = P["off"], P["clear"], P["h"], P["phi"]
    k, d, L0, notch, tilt = P["tail_k"], P["tail_drop"], P.get("tail_len"), P["notch"], P["tail_tilt"]
    yc, R1 = C - off, off + clear
    R2, rm = R1 + h, R1 + h / 2
    ctr = (C, yc)
    ext = math.radians(phi + 10.0)
    ps = [-ext + 2 * ext * i / 160 for i in range(161)]
    band = Polygon([(C + R2 * math.sin(p), yc + R2 * math.cos(p)) for p in ps] +
                   [(C + R1 * math.sin(p), yc + R1 * math.cos(p)) for p in reversed(ps)])
    ends = []
    for sgn in (-1, 1):
        p = math.radians(sgn * phi)
        E = (C + rm * math.sin(p), yc + rm * math.cos(p))
        u0 = (sgn * math.cos(p), -sgn * math.sin(p))          # along the band, outward
        n0 = (math.sin(p), math.cos(p))                         # away from the curve centre (down/out)
        tr = math.radians(tilt)                                 # the tail hangs `tilt` degrees below the band's tangent
        u = (u0[0] * math.cos(tr) + n0[0] * math.sin(tr), u0[1] * math.cos(tr) + n0[1] * math.sin(tr))
        n = (-u[1] * sgn, u[0] * sgn)                           # perpendicular to u, pointing down/out
        if n[0] * n0[0] + n[1] * n0[1] < 0:
            n = (-n[0], -n[1])
        cut = Polygon(_local(E, u, n, [(0, -900), (900, -900), (900, 900), (0, 900)]))
        band = band.difference(cut)
        ends.append((E, u, n))
    parts = []
    for E, u, n in ends:
        tA = _ray_circle(E, n, ctr, R2)                         # band's bottom corner A at the end (exact)

        def tail_at(L):
            return Polygon(_local(E, u, n, [(-k, tA + d - h), (L, tA + d - h), (L - notch, tA + d - h / 2),
                                            (L, tA + d), (-k, tA + d)]))
        if L0:
            L = L0
        else:                                                   # longest tail inside the 6 % padding
            lo, hi = 40.0, 420.0
            for _ in range(36):
                mid = (lo + hi) / 2
                x0, y0, x1, y1 = tail_at(mid).bounds
                ok = x0 - OW / 2 >= C - LIMIT and x1 + OW / 2 <= C + LIMIT and y1 + OW / 2 <= C + LIMIT
                lo, hi = (mid, hi) if ok else (lo, mid)
            L = lo
        tail = smooth(tail_at(L), r_convex=6.0)
        fold = Polygon(_local(E, u, n, [(0, tA), (-k, tA + d), (-k, tA - 60), (0, tA - 60)]))
        parts.append((tail, fold))
    return band, parts, (yc, R1, R2)


def draw_banner(svg, t, P):
    """Ribbon banner in front of the body: tails (shade), folds (deep) and the band (base) with a rim light."""
    band, parts, _ = banner_geometry(P)
    rt = PAPER if P.get("ribbon", "set") == "paper" else t
    for tail, _f in parts:
        wash(svg, tail, rt, role=P.get("tail_role", "shade"), shade_k=0.0)
    for _t, fold in parts:
        fill(svg, fold, rt[P.get("fold_tone", "deep")])
        outline(svg, fold)
    wash(svg, band, rt, role=P.get("band_role", "base"), shade_k=0.10)
    highlight_stroke(svg, band, inset=OW * 1.05, width=OW * 0.5, color=WHITE, opacity=0.9, direction=(-0.35, -1),
                     thresh=0.55, trim=0.16, min_len=60)
    return band, [p[0] for p in parts]


def under(set_key):
    """Paper seal + set-colour ring + seal outline + white plate (everything before the icon)."""
    P = ribbon_params()
    t = dict(SETS[set_key])
    svg = Svg(prefix="u")
    seal = circle(C, C, P["rs"])
    wash(svg, seal, PAPER, shade_k=0.05, line=False)
    ring = circle(C, C, PLATE + P["ring_w"])
    wash(svg, ring, t, shade_k=0.06, line=False)
    outline(svg, seal)
    plate(svg, PLATE)
    return svg


def over(set_key):
    """The ribbon banner (drawn after the icon, in front)."""
    svg = Svg(prefix="o")
    draw_banner(svg, dict(SETS[set_key]), ribbon_params())
    return svg


# key -> (under, over, status, title, file stem). One base per set colour; keys are what registry.py's set_colour
# selects (single-<set colour>).
BASES = {f"single-{k}": ((lambda k=k: under(k)), (lambda k=k: over(k)), "frozen",
                         f"F1S-A ribbon seal, set colour {k}: paper seal + {k} ring + {k} ribbon banner",
                         f"f1s-a-{k}") for k in SETS}
