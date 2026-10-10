"""Illustrated MSP icon toolkit (badge lab, agent IK1, 2026-10-10). Own work, no third-party code or assets.

An icon is drawn as clean SVG geometry (lines, cubic Beziers, CIRCULAR arcs only) in a 100 x 100 box and written with
three tagged layers, always in this z-order:

    <g id="face">     fills that never change with the tier (dial, inner glow, compass card, side figures, snow ...)
    <g id="accent">   fills a tier tints (stopwatch bezel + crown, outer flame, piece body ...) + their rim lights
    <g id="outline">  every navy line: one weight (SW) with round caps/joins, plus navy ink details (hands, pivots)

Rules the builder enforces (README "Rules"):
  * the bounding box INCLUDING the stroke is centred on (50, 50) and its longer side is FIT (80) units; the fit is a
    uniform scale + translate of the geometry only, line widths stay in output units (SW = 4.5 everywhere);
  * accent shapes never cover face shapes (they are rings / cut-outs or sit in front), so the fixed layer order works;
  * hidden outline segments are removed by construction or by an evenodd clip of the front shape ("clip_out").
"""
import math
import re

from svgpathtools import parse_path

NAVY = "#2b3445"   # guide token (reference/msp-design-system.md); S1 says pick one ink per pipeline: X5/E1 frames use it
SW = 4.5           # the one outline weight (100-unit box): 1.35 px when the icon bbox is 24 px, 2.8 % of a 48 px badge
FIT = 80.0         # longer side of the stroked bounding box
HL = "#ffffff"


# ----------------------------------------------------------------------------------------------- colour (CIELAB)
def _hex(c):
    c = c.lstrip("#")
    return tuple(int(c[i:i + 2], 16) / 255 for i in (0, 2, 4))


def _lin(u):
    return u / 12.92 if u <= 0.04045 else ((u + 0.055) / 1.055) ** 2.4


def _gam(u):
    u = max(0.0, min(1.0, u))
    return 12.92 * u if u <= 0.0031308 else 1.055 * u ** (1 / 2.4) - 0.055


def to_lab(c):
    r, g, b = (_lin(u) for u in _hex(c))
    x = (0.4124 * r + 0.3576 * g + 0.1805 * b) / 0.95047
    y = 0.2126 * r + 0.7152 * g + 0.0722 * b
    z = (0.0193 * r + 0.1192 * g + 0.9505 * b) / 1.08883
    f = lambda t: t ** (1 / 3) if t > 0.008856 else 7.787 * t + 16 / 116
    fx, fy, fz = f(x), f(y), f(z)
    return 116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)


def _lab_lin(L, a, b):
    fy = (L + 16) / 116
    fx, fz = fy + a / 500, fy - b / 200
    inv = lambda t: t ** 3 if t ** 3 > 0.008856 else (t - 16 / 116) / 7.787
    x, y, z = inv(fx) * 0.95047, inv(fy), inv(fz) * 1.08883
    return (3.2406 * x - 1.5372 * y - 0.4986 * z, -0.9689 * x + 1.8758 * y + 0.0415 * z,
            0.0557 * x - 0.2040 * y + 1.0570 * z)


def from_lab(L, a, b):
    return "#" + "".join(f"{round(_gam(u) * 255):02x}" for u in _lab_lin(L, a, b))


def shift(c, dL=0.0, kC=1.0):
    """Same hue, lightness +dL, chroma x kC; chroma is reduced further until the colour fits sRGB (hue never drifts)."""
    L, a, b = to_lab(c)
    L2 = max(0.0, min(99.0, L + dL))
    k = kC
    while k > 0.02:
        if all(-0.002 <= u <= 1.002 for u in _lab_lin(L2, a * k, b * k)):
            break
        k -= 0.02
    return from_lab(L2, a * k, b * k)


def mix(c1, c2, t):
    a, b = _hex(c1), _hex(c2)
    return "#" + "".join(f"{round((x + (y - x) * t) * 255):02x}" for x, y in zip(a, b))


# ----------------------------------------------------------------------------------------------- palettes
def wash(base, dl_light=9.0, dl_shade=-6.0, k_shade=1.12):
    """S1 wash: same hue, lighter towards the top-left (+dL*), a touch deeper and more chromatic bottom-right."""
    return [(0.0, shift(base, dl_light, 0.80)), (0.52, base), (1.0, shift(base, dl_shade, k_shade))]


# Accent ladders. "msp" = the house coral (default, untiered). t1..t5 = S1 ladder A pastel metals, SATURATED so that
# silver reads polished (cool blue steel with a hard highlight, never grey) and diamond is the most colourful = the prize
# (the logo prism: pink -> violet -> sky -> teal). Each: main wash stops, pale tint (wedges, small fills), deep (shade).
TIERS = {
    "msp": {"name": "MSP coral (default, no tier)",
            "main": wash("#fb8180"), "pale": [(0, "#fff0ee"), (1, "#fdd6d2")], "deep": "#e45f62", "rim": 0.80},
    "t1": {"name": "T1 Bronze (copper)",
           "main": wash("#ec9762", 10.0, -7.0, 1.10), "pale": [(0, "#fff1e6"), (1, "#fbd8c0")], "deep": "#c9733f",
           "rim": 0.80},
    "t2": {"name": "T2 Silver (polished blue steel)",
           "main": [(0.0, "#f4f8ff"), (0.30, "#c9d6ee"), (0.62, "#9fb3d9"), (1.0, "#7d93c2")],
           "pale": [(0, "#f6f9ff"), (1, "#dde6f6")], "deep": "#6d82b0", "rim": 0.95},
    "t3": {"name": "T3 Gold (butter gold)",
           "main": wash("#fcc84e", 9.0, -6.0, 1.06), "pale": [(0, "#fffaea"), (1, "#fdebb5")], "deep": "#e69c2c",
           "rim": 0.85},
    "t4": {"name": "T4 Platinum (lavender pearl)",
           "main": [(0.0, "#f1efff"), (0.34, "#c8c0fa"), (0.70, "#a79cf2"), (1.0, "#8b7fe6")],
           "pale": [(0, "#f7f5ff"), (1, "#e3defd")], "deep": "#7a6ddc", "rim": 0.90},
    "t5": {"name": "T5 Diamond (saturated logo prism)",
           "main": [(0.0, "#ffd0dc"), (0.22, "#ff8fb1"), (0.48, "#b590f6"), (0.74, "#77b2fb"), (1.0, "#4fd0cc")],
           "pale": [(0, "#ffe6ee"), (0.5, "#ece4ff"), (1, "#dcf6f8")], "deep": "#7465d8", "rim": 0.95},
}
TIER_KEYS = ["msp", "t1", "t2", "t3", "t4", "t5"]

# Constant (face) paints, all from S1's measured palette.
FACE = {
    "face": [(0.0, "#ffffff"), (0.55, "#f1f4fb"), (1.0, "#e1e8f5")],        # dial / card: white, faint blue
    "metal": [(0.0, "#e9eefb"), (1.0, "#b9c7ea")],                          # stopwatch metal (S1 #c9d5f5)
    "sky": wash("#75b5fa"),
    "sky-pale": [(0.0, "#eef6ff"), (1.0, "#c9e1fd")],
    "lavender": wash("#9a93f4"),
    "lav-pale": [(0.0, "#f3f1ff"), (1.0, "#d8d3fb")],
    "teal": wash("#8fd5da"),
    "coral": wash("#fb8180"),
    "glow": [(0.0, "#fffbee"), (0.55, "#ffeeb6"), (1.0, "#ffd77c")],        # inner flame (butter, S1 gold highlight)
    "snow": [(0.0, "#ffffff"), (1.0, "#e4ebf7")],
    "skin": [(0.0, "#fff3ea"), (1.0, "#fbd9c5")],
}


# ----------------------------------------------------------------------------------------------- path builder
class Path:
    """Absolute commands only: M L C Q A(circular) Z. Uniform scale/rotation keeps circular arcs exact."""

    def __init__(self):
        self.c = []

    def M(self, x, y):
        self.c.append(["M", x, y]); return self

    def L(self, x, y):
        self.c.append(["L", x, y]); return self

    def C(self, x1, y1, x2, y2, x, y):
        self.c.append(["C", x1, y1, x2, y2, x, y]); return self

    def Q(self, x1, y1, x, y):
        self.c.append(["Q", x1, y1, x, y]); return self

    def A(self, r, x, y, large=0, sweep=1):
        self.c.append(["A", r, large, sweep, x, y]); return self

    def Z(self):
        self.c.append(["Z"]); return self

    def extend(self, other):
        self.c.extend([list(x) for x in other.c]); return self

    def mapped(self, f, s=1.0, flip=False):
        """f: (x, y) -> (x, y) affine similarity with scale s; flip=True for a mirror (arc sweep inverts)."""
        out = Path()
        for cmd in self.c:
            op = cmd[0]
            if op in "ML":
                out.c.append([op, *f(cmd[1], cmd[2])])
            elif op == "C":
                out.c.append([op, *f(cmd[1], cmd[2]), *f(cmd[3], cmd[4]), *f(cmd[5], cmd[6])])
            elif op == "Q":
                out.c.append([op, *f(cmd[1], cmd[2]), *f(cmd[3], cmd[4])])
            elif op == "A":
                sweep = 1 - cmd[3] if flip else cmd[3]
                out.c.append([op, cmd[1] * s, cmd[2], sweep, *f(cmd[4], cmd[5])])
            else:
                out.c.append(["Z"])
        return out

    def mirrored(self, cx=50.0):
        return self.mapped(lambda x, y: (2 * cx - x, y), 1.0, flip=True)

    def d(self, prec=2):
        fmt = lambda v: (f"{v:.{prec}f}".rstrip("0").rstrip(".") if abs(v) > 10 ** -prec else "0")
        parts = []
        for cmd in self.c:
            op = cmd[0]
            if op == "A":
                r, large, sweep, x, y = cmd[1:]
                parts.append(f"A{fmt(r)} {fmt(r)} 0 {large} {sweep} {fmt(x)} {fmt(y)}")
            elif op == "Z":
                parts.append("Z")
            else:
                parts.append(op + " ".join(fmt(v) for v in cmd[1:]))
        return "".join(parts)


def rot(px, py, ang_deg, cx=0.0, cy=0.0):
    a = math.radians(ang_deg)
    ca, sa = math.cos(a), math.sin(a)
    dx, dy = px - cx, py - cy
    return cx + dx * ca - dy * sa, cy + dx * sa + dy * ca


def clock(cx, cy, r, ang):
    """Point on a circle by clock angle (0 = 12 o'clock, clockwise, degrees) in y-down coordinates."""
    a = math.radians(ang)
    return cx + r * math.sin(a), cy - r * math.cos(a)


def circle(cx, cy, r):
    return Path().M(cx - r, cy).A(r, cx + r, cy, 1, 1).A(r, cx - r, cy, 1, 1).Z()


def arc(cx, cy, r, a0, a1):
    """Open arc by clock angles a0 -> a1 (clockwise if a1 > a0)."""
    x0, y0 = clock(cx, cy, r, a0)
    x1, y1 = clock(cx, cy, r, a1)
    large = 1 if abs(a1 - a0) > 180 else 0
    sweep = 1 if a1 > a0 else 0
    return Path().M(x0, y0).A(r, x1, y1, large, sweep)


def sector(cx, cy, r, a0, a1):
    x0, y0 = clock(cx, cy, r, a0)
    x1, y1 = clock(cx, cy, r, a1)
    large = 1 if abs(a1 - a0) > 180 else 0
    return Path().M(cx, cy).L(x0, y0).A(r, x1, y1, large, 1).Z()


def rrect(cx, cy, w, h, r, ang=0.0):
    """Rounded rectangle centred on (cx, cy), rotated by ang degrees (clockwise on screen)."""
    r = min(r, w / 2, h / 2)
    hw, hh = w / 2, h / 2
    if r < 0.05:                                   # square corners: a plain 4-point path
        pts = [rot(cx + x, cy + y, ang, cx, cy) for x, y in ((-hw, -hh), (hw, -hh), (hw, hh), (-hw, hh))]
        return polyline(pts, closed=True)
    loc = Path()
    loc.M(-hw + r, -hh).L(hw - r, -hh).A(r, hw, -hh + r, 0, 1).L(hw, hh - r).A(r, hw - r, hh, 0, 1)
    loc.L(-hw + r, hh).A(r, -hw, hh - r, 0, 1).L(-hw, -hh + r).A(r, -hw + r, -hh, 0, 1).Z()
    return loc.mapped(lambda x, y: rot(x + cx, y + cy, ang, cx, cy))


def line(x0, y0, x1, y1):
    return Path().M(x0, y0).L(x1, y1)


def polyline(pts, closed=False):
    p = Path().M(*pts[0])
    for q in pts[1:]:
        p.L(*q)
    if closed:
        p.Z()
    return p


# ----------------------------------------------------------------------------------------------- jigsaw piece
def knob_geometry(S, R=0.17, h=0.18, f=0.035):
    """Tangent construction of ONE connector in local coords: edge on y=0, outward = -y, centred on x=0.
    Head circle (0, -h*S) radius R*S; two fillet circles radius f*S tangent to the edge line and to the head.
    Returns (xf, tangent point on the right fillet/head, R, f, h) in absolute units."""
    R, h, f = R * S, h * S, f * S
    xf = math.sqrt((R + f) ** 2 - (h - f) ** 2)
    fx, fy = xf, -f
    hx, hy = 0.0, -h
    dl = math.hypot(hx - fx, hy - fy)
    tx, ty = fx + f * (hx - fx) / dl, fy + f * (hy - fy) / dl
    return xf, (tx, ty), R, f, h


PIECES = []   # every jigsaw_piece() call logs its proportions here (the build checks rule 5 per icon)


def piece_metrics(S, sides, R, h, f, stroke):
    """Rule-5 anatomy of a piece as the eye sees it: the fill inside the stroke (S1 measured the house art, where
    strokes are thin, so for thick icon outlines the centre line must be wider than the target)."""
    Rk, hk, fk = R * S, h * S, f * S
    xf = math.sqrt((Rk + fk) ** 2 - (hk - fk) ** 2)
    neck_c = 2 * (xf - fk * xf / (Rk + fk))                  # narrowest neck, centre line
    return {"side": round(S, 3), "sides": list(sides), "stroke": stroke, "R": R, "h": h, "f": f,
            "head_frac": round((2 * Rk - stroke) / (S - stroke), 4),
            "neck_over_head": round((neck_c - stroke) / (2 * Rk - stroke), 4),
            "protrusion_frac": round(h + R, 4),
            "fillet_vs_half_stroke": round(fk / (stroke / 2), 3) if stroke else None}


def jigsaw_piece(cx, cy, S, sides=("out", "out", "out", "out"), corner=0.085, R=0.17, h=0.18, f=0.035, stroke=SW,
                 border=False):
    """A geometrically correct die-cut piece (BRIEF-PHASE2 rule 5): rounded-square body of side S, exactly ONE
    connector per side (top, right, bottom, left): 'out' = round knob on a short narrow neck, 'in' = the identical
    socket, 'flat' = straight edge. Tangent construction, exact circular arcs only: head radius R*S centred h*S beyond
    the edge, two fillet circles (radius f*S) tangent to the edge and to the head; identical on every side, centred
    on each side. Defaults (thin print, no stroke): head 34 % of the side, neck 0.71 x head, protrusion 35 %.
    With a thick outline pass the stroke and tune R/h/f so the VISIBLE fill keeps S1's anatomy (head 29-35 % of
    the side, neck 0.62-0.75 x head) and f*S > stroke/2 (a smooth inner fillet; see explorer).
    corner: the body-corner radius as a share of S - one value for all four corners, or a tuple (top-left,
    top-right, bottom-right, bottom-left), e.g. a big outer corner on the corner pieces of an assembly.
    border=True: a border piece of an assembly (a complete puzzle): its outer sides may be 'flat' (the puzzle's
    straight edge); every other side still carries exactly one knob or socket (the rule-5 gate checks that)."""
    Rk, hk, fk = R * S, h * S, f * S
    xf = math.sqrt((Rk + fk) ** 2 - (hk - fk) ** 2)          # fillet centre offset along the edge
    PIECES.append(dict(piece_metrics(S, sides, R, h, f, stroke), border=border))
    Tu = xf - fk * xf / (Rk + fk)                            # fillet/head tangent point (local u, v)
    Tv = fk + fk * (hk - fk) / (Rk + fk)
    hs = S / 2
    if isinstance(corner, (tuple, list)):                    # per corner: TL, TR, BR, BL
        rcs = [c * S for c in corner]
        start_rc, end_rc = [rcs[0], rcs[1], rcs[2], rcs[3]], [rcs[1], rcs[2], rcs[3], rcs[0]]
    else:
        rc = corner * S
        start_rc, end_rc = [rc] * 4, [rc] * 4
    # clockwise on screen; per side: sharp start corner, edge direction t, outward normal n.
    # local (u along t, v along n) -> screen has det -1 on every side, so a local counter-clockwise turn = sweep 0.
    frames = [((cx - hs, cy - hs), (1, 0), (0, -1)), ((cx + hs, cy - hs), (0, 1), (1, 0)),
              ((cx + hs, cy + hs), (-1, 0), (0, 1)), ((cx - hs, cy + hs), (0, -1), (-1, 0))]
    p = Path()
    for i, ((sx, sy), (tx, ty), (nx, ny)) in enumerate(frames):
        kind = sides[i]
        mx, my = sx + tx * hs, sy + ty * hs

        def P(u, v, mx=mx, my=my, tx=tx, ty=ty, nx=nx, ny=ny):
            return mx + tx * u + nx * v, my + ty * u + ny * v

        if i == 0:
            p.M(*P(-hs + start_rc[0], 0))
        if kind == "flat":
            p.L(*P(hs - end_rc[i], 0))
        else:
            sg = 1 if kind == "out" else -1
            p.L(*P(-xf, 0))
            p.A(fk, *P(-Tu, sg * Tv), 0, 0 if sg == 1 else 1)   # fillet: turn outward
            p.A(Rk, *P(Tu, sg * Tv), 1, 1 if sg == 1 else 0)    # round head over the far side
            p.A(fk, *P(xf, 0), 0, 0 if sg == 1 else 1)          # fillet back onto the edge
            p.L(*P(hs - end_rc[i], 0))
        ex, ey = P(hs, 0)
        tnx, tny = frames[(i + 1) % 4][1]
        rc = end_rc[i]
        p.A(rc, ex + tnx * rc, ey + tny * rc, 0, 1)              # convex body corner
    p.Z()
    return p


# ----------------------------------------------------------------------------------------------- icon model
class Icon:
    """Collects items per layer. Items: ('fill', path, paint, extra) | ('stroke', path, width, color, opacity, extra)
    | ('dot', cx, cy, r, color, opacity). Paints are roles: 'acc', 'acc-pale', 'acc-deep' (tier) or FACE keys or
    a hex colour."""

    def __init__(self, name, title, symmetric=True, notes=""):
        self.name, self.title, self.symmetric, self.notes = name, title, symmetric, notes
        self.layers = {"face": [], "accent": [], "outline": []}
        self.clips = {}       # id -> (path, outside: bool)

    # -- face / accent fills
    def fill(self, layer, path, paint, opacity=1.0, rule="nonzero", clip=None, cls=None):
        self.layers[layer].append(("fill", path, paint, opacity, rule, clip, cls))

    def hl(self, layer, path, width=1.9, opacity=None, color=HL, clip=None, cls=None):
        """Rim light / highlight stroke (white, round caps), inside a fill."""
        self.layers[layer].append(("stroke", path, width, color, opacity, clip, cls))

    def dot(self, layer, cx, cy, r, color=HL, opacity=None, cls=None):
        self.layers[layer].append(("dot", cx, cy, r, color, opacity, cls))

    # -- outline
    def line(self, path, width=SW, clip=None, cls=None, color=NAVY):
        self.layers["outline"].append(("stroke", path, width, color, 1.0, clip, cls))

    def ink(self, path, cls=None):
        """Navy filled detail (pivot, hand head)."""
        self.layers["outline"].append(("fill", path, NAVY, 1.0, "nonzero", None, cls))

    def clip_out(self, cid, front_path):
        """Clip that keeps everything EXCEPT the inside of front_path (hides back outlines behind a front shape)."""
        self.clips[cid] = front_path
        return cid

    # -- geometry pass: every path through f
    def mapped(self, f, s):
        out = Icon(self.name, self.title, self.symmetric, self.notes)
        for k, items in self.layers.items():
            for it in items:
                if it[0] == "fill":
                    out.layers[k].append(("fill", it[1].mapped(f, s), *it[2:]))
                elif it[0] == "stroke":
                    out.layers[k].append(("stroke", it[1].mapped(f, s), *it[2:]))
                else:
                    x, y = f(it[1], it[2])
                    out.layers[k].append(("dot", x, y, it[3] * s, *it[4:]))
        out.clips = {cid: p.mapped(f, s) for cid, p in self.clips.items()}
        return out


def _bbox_of(path):
    pp = parse_path(path.d(4))
    return pp.bbox()  # xmin, xmax, ymin, ymax


def stroked_bbox(icon):
    """Bounding box of the outline layer including the stroke (round caps/joins: geometry +- width/2)."""
    xs0, xs1, ys0, ys1 = [], [], [], []
    for it in icon.layers["outline"]:
        if it[0] == "stroke":
            x0, x1, y0, y1 = _bbox_of(it[1])
            w = it[2] / 2
            xs0.append(x0 - w); xs1.append(x1 + w); ys0.append(y0 - w); ys1.append(y1 + w)
        elif it[0] == "fill":
            x0, x1, y0, y1 = _bbox_of(it[1])
            xs0.append(x0); xs1.append(x1); ys0.append(y0); ys1.append(y1)
    return min(xs0), max(xs1), min(ys0), max(ys1)


def fit(icon, size=FIT, centre=50.0):
    """Uniform scale of the geometry (line widths fixed) so that the stroked bbox has longer side `size`, centred."""
    s = 1.0
    out = icon
    for _ in range(4):
        x0, x1, y0, y1 = stroked_bbox(out)
        w, h = x1 - x0, y1 - y0
        L = max(w, h)
        # strokes don't scale: (L - SW) * k + SW = size
        k = (size - SW) / (L - SW)
        cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
        f = lambda x, y, k=k, cx=cx, cy=cy: (centre + (x - cx) * k, centre + (y - cy) * k)
        out = out.mapped(f, k)
        s *= k
        if abs(k - 1) < 1e-6 and abs(cx - centre) < 1e-6 and abs(cy - centre) < 1e-6:
            break
    return out, s


# ----------------------------------------------------------------------------------------------- SVG writer
def _grad(gid, stops, x1=0.12, y1=0.04, x2=0.88, y2=0.96):
    st = "".join(f'<stop offset="{o:g}" stop-color="{c}"/>' for o, c in stops)
    return f'<linearGradient id="{gid}" x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}">{st}</linearGradient>'


def svg_text(icon, tier="msp", comment=""):
    T = TIERS[tier]
    pre = f"{icon.name}-{tier}-"
    used = set()

    def paint(p):
        if p == "acc":
            used.add("acc"); return f"url(#{pre}acc)"
        if p == "acc-pale":
            used.add("acc-pale"); return f"url(#{pre}acc-pale)"
        if p == "acc-deep":
            return T["deep"]
        if p in FACE:
            used.add(p); return f"url(#{pre}{p})"
        return p

    def attrs_common(it_clip, cls):
        a = ""
        if it_clip:
            a += f' clip-path="url(#{pre}{it_clip})"'
        if cls:
            a += f' class="{cls}"'
        return a

    out_layers = {}
    for lname in ("face", "accent", "outline"):
        rows = []
        for it in icon.layers[lname]:
            if it[0] == "fill":
                _, path, p, op, rule, clip, cls = it
                a = f' fill="{paint(p)}"'
                if op != 1.0:
                    a += f' fill-opacity="{op:g}"'
                if rule != "nonzero":
                    a += f' fill-rule="{rule}"'
                if lname == "outline":
                    a += ' stroke="none"'
                rows.append(f'<path d="{path.d()}"{a}{attrs_common(clip, cls)}/>')
            elif it[0] == "stroke":
                _, path, w, col, op, clip, cls = it
                if lname == "outline":
                    a = ""
                    if abs(w - SW) > 1e-9:
                        a += f' stroke-width="{w:g}"'
                    if col != NAVY:
                        a += f' stroke="{col}"'
                    rows.append(f'<path d="{path.d()}"{a}{attrs_common(clip, cls)}/>')
                else:
                    o = T["rim"] if op is None else op
                    col = T["deep"] if col == "acc-deep" else col
                    rows.append(f'<path d="{path.d()}" fill="none" stroke="{col}" stroke-opacity="{o:g}" '
                                f'stroke-width="{w:g}" stroke-linecap="round" stroke-linejoin="round"'
                                f'{attrs_common(clip, cls)}/>')
            else:
                _, x, y, r, col, op, cls = it
                o = T["rim"] if (op is None and lname != "outline") else (1.0 if op is None else op)
                a = f' fill-opacity="{o:g}"' if o != 1.0 else ""
                c = f' class="{cls}"' if cls else ""
                rows.append(f'<circle cx="{x:.2f}" cy="{y:.2f}" r="{r:.2f}" fill="{col}"{a}{c}/>')
        out_layers[lname] = rows

    defs = []
    if "acc" in used:
        defs.append(_grad(f"{pre}acc", T["main"]))
    if "acc-pale" in used:
        defs.append(_grad(f"{pre}acc-pale", T["pale"]))
    for k in FACE:
        if k in used:
            defs.append(_grad(f"{pre}{k}", FACE[k]))
    for cid, p in icon.clips.items():
        defs.append(f'<clipPath id="{pre}{cid}"><path clip-rule="evenodd" d="M-10 -10H110V110H-10Z{p.d()}"/></clipPath>')

    head = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">\n'
            f'<!-- MSP illustrated icon "{icon.name}" ({icon.title}) - {T["name"]}. Own work (badge lab IK1), free to '
            f'use. Layers: face (constant), accent (tier tint), outline (navy {NAVY}, {SW:g}/100, round). {comment} -->\n')
    body = (f'<defs>{"".join(defs)}</defs>\n'
            f'<g id="face">\n' + "\n".join(out_layers["face"]) + '\n</g>\n'
            f'<g id="accent">\n' + "\n".join(out_layers["accent"]) + '\n</g>\n'
            f'<g id="outline" fill="none" stroke="{NAVY}" stroke-width="{SW:g}" stroke-linecap="round" '
            f'stroke-linejoin="round">\n' + "\n".join(out_layers["outline"]) + '\n</g>\n</svg>\n')
    return head + body


def strip_classes(svg, cls):
    """Remove every element carrying class=cls (QA: structural symmetry without the declared asymmetric details)."""
    return re.sub(r'<(path|circle)[^>]*class="' + re.escape(cls) + r'"[^>]*/>\n?', "", svg)


# ----------------------------------------------------------------------------------------------- automatic rims
def rim(path, side="tl", inset=None, thresh=0.5, trim=0.12, min_len=7.0, max_runs=1, n=480, step=6):
    """Rim light (side='tl') or bevel shade (side='br', or any (x, y) direction) for ONE closed outline: the part of
    the outline whose outward normal faces `side`, offset `inset` units inside (default: just inside the stroke),
    trimmed at both ends and smoothed into cubic Beziers. Works on any shape, so every icon gets the same light
    logic (S1: lighter top-left, rim light ~1 stroke inside the outline on the lit side)."""
    from shapely.geometry import Point, Polygon
    from svgpathtools import parse_path
    inset = SW / 2 + 1.25 if inset is None else inset
    vec = {"tl": (-1.0, -1.0), "br": (1.0, 1.0), "l": (-1.0, 0.0), "r": (1.0, 0.0)}.get(side, side)
    vl = math.hypot(*vec)
    vec = (vec[0] / vl, vec[1] / vl)
    sp = parse_path(path.d(4))
    L = sp.length()
    pts = []
    for i in range(n):
        try:
            pts.append(sp.point(sp.ilength(min(L * i / n, L * (1 - 1e-7)), s_tol=1e-6)))
        except ValueError:
            pts.append(sp.point(i / n))
    poly = Polygon([(p.real, p.imag) for p in pts]).buffer(0)
    ok, qs = [], []
    for i in range(n):
        t = pts[(i + 1) % n] - pts[i - 1]
        t = t / abs(t) if abs(t) else complex(1, 0)
        nrm = complex(t.imag, -t.real)
        probe = pts[i] + nrm * 0.4
        if poly.contains(Point(probe.real, probe.imag)):
            nrm = -nrm                                   # outward
        q = pts[i] - nrm * inset
        good = (nrm.real * vec[0] + nrm.imag * vec[1] > thresh
                and poly.contains(Point(q.real, q.imag))
                and poly.exterior.distance(Point(q.real, q.imag)) >= inset - 0.35)
        ok.append(good)
        qs.append(q)
    # cyclic runs of good points
    runs, i0 = [], None
    start = next((i for i in range(n) if not ok[i]), None)
    if start is None:
        return Path()
    for k in range(1, n + 1):
        i = (start + k) % n
        if ok[i] and i0 is None:
            i0 = k
        if (not ok[i] or k == n) and i0 is not None:
            runs.append([qs[(start + j) % n] for j in range(i0, k if not ok[i] else k)])
            i0 = None
    runs = [r for r in runs if len(r) > 3]
    runs.sort(key=lambda r: -sum(abs(r[j + 1] - r[j]) for j in range(len(r) - 1)))
    out = Path()
    for r in runs[:max_runs]:
        length = sum(abs(r[j + 1] - r[j]) for j in range(len(r) - 1))
        if length < min_len:
            continue
        cut = int(len(r) * trim)
        r = r[cut:len(r) - cut] if len(r) - 2 * cut > 3 else r
        r = r[::step] + ([r[-1]] if (len(r) - 1) % step else [])
        if len(r) < 2:
            continue
        out.M(r[0].real, r[0].imag)
        for j in range(len(r) - 1):
            p0 = r[j - 1] if j > 0 else r[j]
            p1, p2 = r[j], r[j + 1]
            p3 = r[j + 2] if j + 2 < len(r) else r[j + 1]
            c1 = p1 + (p2 - p0) / 6
            c2 = p2 - (p3 - p1) / 6
            out.C(c1.real, c1.imag, c2.real, c2.imag, p2.real, p2.imag)
    return out
