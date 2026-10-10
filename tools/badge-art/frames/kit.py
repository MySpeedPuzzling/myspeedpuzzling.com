"""F1 frame kit: the MSP house style for the badge frames (shapely geometry -> SVG). Only what F1 needs.

Trimmed copy of the badge lab's X5 `mspkit` + BK1 `bk1kit` (2026-10-10). The function bodies are kept verbatim on
purpose: `freeze_bases.py` must reproduce the lab's F1 output bit for bit (README "Decisions"). Do not "tidy" the
maths - a changed float operation order changes the frozen bases.

Style rules implemented here:
- ONE navy (#2b3445) outline weight OW = 27 px on the 1024 canvas (2.64 %), round caps/joins, frame AND icon;
- fills are same-hue pastel washes: a diagonal light -> base gradient (+8 L* top-left) and a soft, blurred shade
  band in the tier's shade colour on the bottom-right; T5 = the saturated logo prism;
- a white plate WITHOUT a navy ring (two navy rings max: the frame outline + the icon's own outline);
- no white gloss arcs on the bases (owner 2026-10-10): only the soft wash; the icons keep their own highlights;
- rounded friendly geometry (shapely buffer opening/closing rounds every corner).
Deterministic: no randomness anywhere.
"""
import math

from shapely import affinity
from shapely.geometry import LineString, Point, Polygon, box
from shapely.ops import unary_union

W = 1024
C = W / 2.0
OW = 27.0                 # ONE navy weight: 2.64 % of the canvas, frame AND icon
OW_IN = 0.6 * OW          # interior detail lines (pleats, crown band)
NAVY = "#2b3445"
WHITE = "#ffffff"
LIMIT = 0.44 * W          # 6 % padding: geometry (incl. half the outline) stays inside C +- 451 px
QS = 48                   # quad segments for circles / buffers

# --------------------------------------------------------------------------------------------- tier tones
# Ladder v2 (pastel metals): light / base / shade / deep (+ streak strength). T5 = the logo prism.
TIERS = {
    1: dict(name="Bronze", light="#FAD3BA", base="#E28F60", shade="#C9724A", deep="#A9572F", streak=0.80),
    2: dict(name="Silver", light="#F4F8FD", base="#A8C2E0", shade="#86A2C6", deep="#6A86AE", streak=1.0),
    3: dict(name="Gold", light="#FEF3D2", base="#FDD26F", shade="#F6B24C", deep="#D4952B", streak=0.85),
    4: dict(name="Platinum", light="#EEEBFE", base="#B9B0F4", shade="#9389E8", deep="#7468D8", streak=0.85),
    5: dict(name="Diamond", light="#FFE6EE", base="#B49CEE", shade="#5F54C9", deep="#5248BC", streak=1.0,
            prism=True),
}
# The logo's own sweep (top-left -> bottom-right): coral-pink, pink, mauve, teal, periwinkle, purple
PRISM = [(0.00, "#FF9EB0"), (0.22, "#F987AE"), (0.42, "#C09AEA"), (0.58, "#62C8D2"), (0.80, "#74AEF6"),
         (1.00, "#8E9CF2")]
GOLD = dict(light="#FFF2C4", base="#FFCB52", shade="#F2A93A", deep="#D58E22")   # crown (prize gold)
CORAL = dict(light="#FFC9C4", base="#F48C86", shade="#DD6A66", deep="#BF4F4D")
FACE = ["#ffffff", "#fbfcff", "#e9eff8"]


# --------------------------------------------------------------------------------------------- colour (CIELAB D65)
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
    f = lambda t: t ** (1 / 3) if t > 0.008856 else 7.787 * t + 16 / 116  # noqa: E731
    fx, fy, fz = f(x), f(y), f(z)
    return 116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)


def _lab_rgb(L, a, b):
    fy = (L + 16) / 116
    fx, fz = fy + a / 500, fy - b / 200
    inv = lambda t: t ** 3 if t ** 3 > 0.008856 else (t - 16 / 116) / 7.787  # noqa: E731
    x, y, z = inv(fx) * 0.95047, inv(fy), inv(fz) * 1.08883
    return (3.2406 * x - 1.5372 * y - 0.4986 * z, -0.9689 * x + 1.8758 * y + 0.0415 * z,
            0.0557 * x - 0.2040 * y + 1.0570 * z)


def from_lab(L, a, b):
    return "#" + "".join(f"{round(_gam(u) * 255):02x}" for u in _lab_rgb(L, a, b))


def lift(c, dL=8.0, dC=-0.12):
    """Same hue, dL lighter, chroma a bit lower; chroma is reduced further until inside sRGB (hue never drifts)."""
    L, a, b = to_lab(c)
    L2 = min(L + dL, 99.0)
    k = 1 + dC
    while k > 0.05:
        lin = _lab_rgb(L2, a * k, b * k)
        if all(-0.002 <= u <= 1.002 for u in lin):
            break
        k -= 0.02
    return from_lab(L2, a * k, b * k)


def darken(c, dL):
    L, a, b = to_lab(c)
    return from_lab(max(L - dL, 5), a * 1.04, b * 1.04)


def mix(c1, c2, k):
    a, b = _hex(c1), _hex(c2)
    return "#" + "".join(f"{round((x * (1 - k) + y * k) * 255):02x}" for x, y in zip(a, b))


def tone(t):
    return {"light": t["light"], "base": t["base"], "shade": t["shade"], "deep": t["deep"], "glow": []}


# --------------------------------------------------------------------------------------------- svg builder
class Svg:
    """Collects <defs> and body markup. `prefix` keeps the ids of separately exported layers apart."""

    def __init__(self, prefix="", size=W):
        self.size = size
        self.prefix = prefix
        self.defs = []
        self.body = []
        self._n = 0

    def uid(self, p="i"):
        self._n += 1
        return f"{self.prefix}{p}{self._n}"

    def add(self, s):
        self.body.append(s)

    def defn(self, s):
        self.defs.append(s)


def _ring(coords):
    pts = list(coords)
    if len(pts) > 1 and pts[0] == pts[-1]:
        pts = pts[:-1]
    return "M" + " L".join(f"{x:.2f},{y:.2f}" for x, y in pts) + " Z"


def polys(g):
    if g is None or g.is_empty:
        return []
    if g.geom_type == "Polygon":
        return [g]
    out = []
    for x in getattr(g, "geoms", []):
        out.extend(polys(x))
    return out


def d_of(g):
    parts = []
    for p in polys(g):
        parts.append(_ring(p.exterior.coords))
        for h in p.interiors:
            parts.append(_ring(h.coords))
    return " ".join(parts)


def line_d(coords):
    pts = list(coords)
    return "M" + " L".join(f"{x:.2f},{y:.2f}" for x, y in pts)


def lin_grad(svg, stops, x1, y1, x2, y2):
    gid = svg.uid("lg")
    st = "".join(f'<stop offset="{o:.3f}" stop-color="{c}" stop-opacity="{a:.3f}"/>' for o, c, a in stops)
    svg.defn(f'<linearGradient id="{gid}" gradientUnits="userSpaceOnUse" x1="{x1:.1f}" y1="{y1:.1f}" '
             f'x2="{x2:.1f}" y2="{y2:.1f}">{st}</linearGradient>')
    return f"url(#{gid})"


def rad_grad(svg, stops, cx, cy, r, fx=None, fy=None):
    gid = svg.uid("rg")
    st = "".join(f'<stop offset="{o:.3f}" stop-color="{c}" stop-opacity="{a:.3f}"/>' for o, c, a in stops)
    fxy = "" if fx is None else f' fx="{fx:.1f}" fy="{fy:.1f}"'
    svg.defn(f'<radialGradient id="{gid}" gradientUnits="userSpaceOnUse" cx="{cx:.1f}" cy="{cy:.1f}" '
             f'r="{r:.1f}"{fxy}>{st}</radialGradient>')
    return f"url(#{gid})"


def clip(svg, g):
    cid = svg.uid("cp")
    svg.defn(f'<clipPath id="{cid}"><path d="{d_of(g)}" fill-rule="evenodd"/></clipPath>')
    return cid


# --------------------------------------------------------------------------------------------- geometry
def circle(cx, cy, r):
    return Point(cx, cy).buffer(r, quad_segs=QS)


def rrect(cx, cy, w, h, r):
    r = min(r, w / 2 - 0.01, h / 2 - 0.01)
    b = box(cx - w / 2 + r, cy - h / 2 + r, cx + w / 2 - r, cy + h / 2 - r)
    return b.buffer(r, quad_segs=QS)


def smooth(g, r_concave=0.0, r_convex=0.0):
    if r_concave > 0:
        g = g.buffer(r_concave, quad_segs=QS).buffer(-r_concave, quad_segs=QS)
    if r_convex > 0:
        g = g.buffer(-r_convex, quad_segs=QS).buffer(r_convex, quad_segs=QS)
    return g


def star_poly(cx, cy, r_out, r_in_ratio=0.48, n=5, rot=-90.0, round_r=None):
    pts = []
    for i in range(2 * n):
        a = math.radians(rot + i * 180.0 / n)
        r = r_out if i % 2 == 0 else r_out * r_in_ratio
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    g = Polygon(pts)
    rr = round_r if round_r is not None else r_out * 0.10
    return smooth(g, r_concave=rr * 0.6, r_convex=rr)


def scallop(n, r_c, r_b, r_base, fillet, rot=-90.0, cx=C, cy=C):
    """Seal edge: union of a base circle and n bump circles, concave cusps rounded by `fillet`."""
    parts = [circle(cx, cy, r_base)]
    for i in range(n):
        a = math.radians(rot + 360.0 * i / n)
        parts.append(circle(cx + r_c * math.cos(a), cy + r_c * math.sin(a), r_b))
    return smooth(unary_union(parts), r_concave=fillet)


def sunburst(n, r_out, r_in, round_out, round_in, rot=-90.0, cx=C, cy=C):
    g = star_poly(cx, cy, r_out, r_in_ratio=r_in / r_out, n=n, rot=rot, round_r=round_out)
    return smooth(g, r_concave=round_in)


# --------------------------------------------------------------------------------------------- painting
def outline(svg, g, ow=OW, color=NAVY, opacity=1.0):
    if g is None or g.is_empty:
        return
    op = "" if opacity >= 1 else f' stroke-opacity="{opacity:.3f}"'
    svg.add(f'<path d="{d_of(g)}" fill="none" stroke="{color}" stroke-width="{ow:.2f}" '
            f'stroke-linejoin="round" stroke-linecap="round"{op}/>')


def fill(svg, g, paint, opacity=1.0, extra=""):
    if g is None or g.is_empty:
        return
    op = "" if opacity >= 1 else f' fill-opacity="{opacity:.3f}"'
    svg.add(f'<path d="{d_of(g)}" fill="{paint}" fill-rule="evenodd"{op}{extra}/>')


def stroke_line(svg, coords, width, color=NAVY, opacity=1.0, cap="round"):
    op = "" if opacity >= 1 else f' stroke-opacity="{opacity:.3f}"'
    svg.add(f'<path d="{line_d(coords)}" fill="none" stroke="{color}" stroke-width="{width:.2f}" '
            f'stroke-linecap="{cap}" stroke-linejoin="round"{op}/>')


def dot(svg, cx, cy, r, color=NAVY):
    g = circle(cx, cy, r)
    fill(svg, g, color)
    return g


def highlight_stroke(svg, g, inset, width, color=WHITE, opacity=0.85, direction=(-1, -1), thresh=0.55,
                     trim=0.10, min_len=40):
    """White highlight line inside the shape along the edge that faces the light (top-left)."""
    inner = g.buffer(-inset, quad_segs=QS)
    dx, dy = direction
    ln = math.hypot(dx, dy)
    dx, dy = dx / ln, dy / ln
    for p in polys(inner):
        pts = list(p.exterior.coords)[:-1]
        n = len(pts)
        if n < 8:
            continue
        # orientation: shapely exteriors from buffer are CCW in math coords; compute normals robustly
        area = sum(pts[i][0] * pts[(i + 1) % n][1] - pts[(i + 1) % n][0] * pts[i][1] for i in range(n)) / 2
        sign = 1 if area > 0 else -1
        good = []
        for i in range(n):
            x0, y0 = pts[i - 1]
            x1, y1 = pts[(i + 1) % n]
            tx, ty = x1 - x0, y1 - y0
            tl = math.hypot(tx, ty) or 1
            # outward normal for CCW ring (in y-down coords the sign flips; handled by `sign`)
            nx, ny = (ty / tl) * sign, (-tx / tl) * sign
            good.append(nx * dx + ny * dy > thresh)
        # collect runs (circular)
        runs = []
        start_candidates = [k for k in range(n) if not good[k]]
        if not start_candidates:
            runs = [list(range(n))]
        else:
            s0 = start_candidates[0]
            cur = []
            for k in range(1, n + 1):
                idx = (s0 + k) % n
                if good[idx]:
                    cur.append(idx)
                else:
                    if cur:
                        runs.append(cur)
                    cur = []
            if cur:
                runs.append(cur)
        best = None
        for run in runs:
            coords = [pts[k] for k in run]
            if len(coords) < 3:
                continue
            ls = LineString(coords)
            if best is None or ls.length > best.length:
                best = ls
        for ls in ([best] if best is not None else []):
            if ls.length < min_len:
                continue
            a = ls.length * trim
            b = ls.length * (1 - trim)
            sub = [ls.interpolate(a + (b - a) * j / 40) for j in range(41)]
            stroke_line(svg, [(q.x, q.y) for q in sub], width, color=color, opacity=opacity)


def _wash(svg, g, t, shade_k=0.075, shade_op=0.60, base_paint=None, soft=True):
    """Same-hue gradient base -> +8 L* towards the top-left (never a hue gradient) and a soft-edged shade band in
    the tone's shade colour on the bottom-right (the X5 wash without its unused glow / highlight options)."""
    if g is None or g.is_empty:
        return
    minx, miny, maxx, maxy = g.bounds
    size = max(maxx - minx, maxy - miny)
    paint = base_paint or lin_grad(svg, [(0, lift(t["base"], 8.0), 1), (0.55, t["base"], 1), (1, t["base"], 1)],
                                   minx, miny, maxx, maxy)
    fill(svg, g, paint)
    if shade_k > 0:
        k = size * shade_k
        sh = g.difference(affinity.translate(g, -k * 0.75, -k))
        sh = smooth(sh, r_convex=k * 0.30)
        if not sh.is_empty:
            cid = clip(svg, g)
            blur = ""
            if soft:
                fid = svg.uid("bl")
                svg.defn(f'<filter id="{fid}" x="-20%" y="-20%" width="140%" height="140%">'
                         f'<feGaussianBlur stdDeviation="{max(2.0, k * 0.22):.1f}"/></filter>')
                blur = f' filter="url(#{fid})"'
            op = f' fill-opacity="{shade_op:.2f}"'
            svg.add(f'<g clip-path="url(#{cid})"><path d="{d_of(sh)}" fill="{t["shade"]}" fill-rule="evenodd"{op}{blur}/></g>')


def prism_paint(svg, dL=0.0, x0=C - 430, y0=C - 430, x1=C + 430, y1=C + 430):
    """Canvas-wide diagonal logo prism (userSpaceOnUse): every T5 part shares ONE continuous sweep."""
    stops = [(o, lift(c, dL, -0.02) if dL > 0 else (darken(c, -dL) if dL < 0 else c), 1) for o, c in PRISM]
    return lin_grad(svg, stops, x0, y0, x1, y1)


def wash(svg, g, t, role="base", shade_k=0.05, shade_op=None, ow=OW, line=True, soft=True):
    """Tier wash on a frame part (+ its navy outline). t = a tone dict (TIERS[n] or a single-base tone).
    role: 'base' | 'light' (lighter twin, e.g. the second ribbon tail) | 'shade'."""
    if g is None or g.is_empty:
        return
    if t.get("prism"):
        dL = {"base": 0.0, "light": 9.0, "shade": -9.0}[role]
        tt = {"light": t["light"], "base": t["base"], "shade": t["shade"], "deep": t["deep"], "glow": []}
        _wash(svg, g, tt, shade_k=shade_k, shade_op=0.30 if shade_op is None else shade_op,
              base_paint=prism_paint(svg, dL), soft=soft)
    else:
        if role == "light":
            tt = {"light": lift(t["light"], 3), "base": mix(t["light"], t["base"], 0.45), "shade": t["base"],
                  "deep": t["shade"], "glow": []}
        elif role == "shade":
            tt = {"light": t["base"], "base": t["shade"], "shade": t["deep"], "deep": t["deep"], "glow": []}
        else:
            tt = tone(t)
        _wash(svg, g, tt, shade_k=shade_k, shade_op=0.55 if shade_op is None else shade_op, soft=soft)
    if line:
        outline(svg, g, ow=ow)


def plate(svg, r, cx=C, cy=C):
    """White face WITHOUT a navy ring. Faint blue-grey edge."""
    g = circle(cx, cy, r)
    p = rad_grad(svg, [(0, FACE[0], 1), (0.64, FACE[1], 1), (1, FACE[2], 1)], cx, cy, r, cx - 0.30 * r, cy - 0.36 * r)
    fill(svg, g, p)
    return g


# --------------------------------------------------------------------------------------------- ornaments
def crown_shape(cx, base_y, w, hk=1.0):
    """MSP crown: rounded band + 3 points topped with balls (centre point tallest); hk < 1 flattens it."""
    u = w
    pts = [(-0.48, 0.0), (-0.53, -0.56), (-0.25, -0.30), (0.0, -0.66), (0.25, -0.30), (0.53, -0.56), (0.48, 0.0)]
    body = Polygon([(cx + x * u, base_y + y * u * hk) for x, y in pts])
    body = smooth(body, r_concave=0.035 * u, r_convex=0.045 * u)
    balls = [circle(cx - 0.53 * u, base_y - 0.585 * u * hk, 0.072 * u), circle(cx, base_y - 0.70 * u * hk, 0.082 * u),
             circle(cx + 0.53 * u, base_y - 0.585 * u * hk, 0.072 * u)]
    band = rrect(cx, base_y - 0.10 * u, 1.00 * u, 0.24 * u, 0.09 * u)
    sil = unary_union([body, band] + balls)
    return sil, band, balls, body


def crown(svg, cx, base_y, w, ow=OW, hk=1.0):
    sil, band, balls, body = crown_shape(cx, base_y, w, hk)
    for g in (body,):
        _wash(svg, g, tone(GOLD), shade_k=0.10, shade_op=0.5)
        outline(svg, g, ow=ow)
    for g in balls:
        _wash(svg, g, tone(GOLD), shade_k=0.16, shade_op=0.5)
        outline(svg, g, ow=ow)
    _wash(svg, band, tone(GOLD), shade_k=0.18, shade_op=0.6)
    outline(svg, band, ow=ow)
    gem = circle(cx, base_y - 0.10 * w, 0.075 * w)
    _wash(svg, gem, tone(CORAL), shade_k=0.18, shade_op=0.6)
    outline(svg, gem, ow=OW_IN)
    for sx in (-1, 1):
        dot(svg, cx + sx * 0.30 * w, base_y - 0.10 * w, 0.040 * w, color="#7FB6FA")
    stroke_line(svg, [(cx - 0.40 * w, base_y - 0.42 * w * hk), (cx - 0.36 * w, base_y - 0.30 * w * hk)], OW * 0.5,
                color=WHITE)
    return sil


def ribbon_tail(cx, cy, angle_deg, width, length, notch):
    hw = width / 2
    g = smooth(Polygon([(-hw, 0), (hw, 0), (hw, length), (0, length - notch), (-hw, length)]), r_convex=8)
    g = affinity.rotate(g, -angle_deg, origin=(0, 0))
    return affinity.translate(g, cx, cy)


def fitted_tail(cx, cy, angle_deg, width, notch, max_y, max_len=0.6 * W):
    """Longest ribbon tail that stays inside the 6 % padding (outline included); bisection, deterministic."""
    lo, hi = 0.04 * W, max_len
    for _ in range(32):
        mid = (lo + hi) / 2
        g = ribbon_tail(cx, cy, angle_deg, width, mid, notch)
        b = g.bounds
        if b[3] + OW / 2 <= max_y and b[0] - OW / 2 >= C - LIMIT and b[2] + OW / 2 <= C + LIMIT:
            lo = mid
        else:
            hi = mid
    return ribbon_tail(cx, cy, angle_deg, width, lo, notch)
