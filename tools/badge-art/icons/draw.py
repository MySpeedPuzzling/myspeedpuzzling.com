"""The illustrated MSP badge icons: one drawing function per icon (badge lab IK1, 2026-10-10; own work).

Which badge type uses which function is declared ONLY in ../registry.py (type <-> icon <-> drawing function <->
tiered/single). A function that no registry line references is never built.

Each function draws in a 100-unit box at roughly the final size; illus.fit() then centres the stroked bounding box on
(50, 50) with its longer side = 80 (line widths stay 4.5). Layer roles: face = constant fills, accent = the tier tint,
outline = navy lines (one weight) + navy ink. class="asym" marks deliberate asymmetric details (the stopwatch hand and
wedge, the compass needle, the summit flag): the QA gate measures the structural mirror symmetry without them and
reports the full silhouette symmetry as well. README.md "Icons" explains the rules and how to add an icon.
"""
import math

from .illus import Icon, Path, SW, arc, circle, clock, jigsaw_piece, line, polyline, rim, rot, rrect, sector


# ----------------------------------------------------------------------------------------------- path helpers
def reverse(p):
    """Reverse an open path made of one M followed by L / C / Q / A segments (arc sweep flips)."""
    segs, cur = [], None
    for cmd in p.c:
        op = cmd[0]
        if op == "M":
            cur = (cmd[1], cmd[2])
            continue
        end = (cmd[-2], cmd[-1])
        segs.append((op, cur, cmd[1:], end))
        cur = end
    out = Path().M(*cur)
    for op, start, args, end in reversed(segs):
        if op == "L":
            out.L(*start)
        elif op == "C":
            out.C(args[2], args[3], args[0], args[1], *start)
        elif op == "Q":
            out.Q(args[0], args[1], *start)
        elif op == "A":
            out.A(args[0], *start, args[1], 1 - args[2])
    return out


def join(*paths, close=True):
    """Concatenate open paths (each continues from the previous end; their own M is dropped)."""
    out = Path()
    for i, p in enumerate(paths):
        for cmd in p.c:
            if cmd[0] == "M" and i > 0:
                continue
            out.c.append(list(cmd))
    if close:
        out.Z()
    return out


def mirror_closed(right_half, cx=50.0):
    """right_half: open path from the top centre point down the right side to the bottom centre point."""
    return join(right_half, reverse(right_half.mirrored(cx)))


def seg_intersect(p1, p2, p3, p4):
    (x1, y1), (x2, y2), (x3, y3), (x4, y4) = p1, p2, p3, p4
    den = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4)
    t = ((x1 - x3) * (y3 - y4) - (y1 - y3) * (x3 - x4)) / den
    return x1 + t * (x2 - x1), y1 + t * (y2 - y1)


def lerp(a, b, t):
    return a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t


def round_poly(pts, radii):
    """Closed polygon, each corner rounded by a quadratic fillet that starts `radius` units along both edges."""
    n = len(pts)
    p = Path()
    for i in range(n):
        a, b, c = pts[i - 1], pts[i], pts[(i + 1) % n]
        r = radii[i]
        if r < 0.05:                                   # sharp corner (hidden under a stroke or another shape)
            (p.M if i == 0 else p.L)(*b)
            continue
        da = math.hypot(a[0] - b[0], a[1] - b[1])
        dc = math.hypot(c[0] - b[0], c[1] - b[1])
        p0 = (b[0] + (a[0] - b[0]) * r / da, b[1] + (a[1] - b[1]) * r / da)
        p1 = (b[0] + (c[0] - b[0]) * r / dc, b[1] + (c[1] - b[1]) * r / dc)
        (p.M if i == 0 else p.L)(*p0)
        p.Q(b[0], b[1], *p1)
    p.Z()
    return p


def light(ic, layer, shape, tl=None, br=None, glint=True, rim_w=1.9, shade_w=2.2, shade_op=0.40, shade_col="acc-deep",
          clip=None, shade=True):
    """The house light on one shape (S1): white rim light inside the outline on the top-left, a soft deep-tone bevel
    shade on the bottom-right, one glint dot just past the upper end of the rim light. clip = a clip_out id when the
    shape is partly behind another one."""
    r = rim(shape, **(tl or {}))
    if r.c:
        ic.hl(layer, r, rim_w, clip=clip)
        if glint:
            ends = [(c[-2], c[-1]) for c in (r.c[0], r.c[-1])]
            (ex, ey), (ox, oy) = sorted(ends, key=lambda p: p[0] - p[1], reverse=True)
            last = r.c[-1] if (r.c[-1][-2], r.c[-1][-1]) == (ex, ey) else r.c[0]
            # direction: from the previous control point towards the end
            if last[0] == "C" and (last[-2], last[-1]) == (ex, ey):
                dx, dy = ex - last[3], ey - last[4]
            else:
                first = r.c[1]
                dx, dy = ex - first[1], ey - first[2]
            dl = math.hypot(dx, dy) or 1.0
            ic.dot(layer, ex + dx / dl * 3.1, ey + dy / dl * 3.1, 1.05)
    if not shade:
        return
    sh = rim(shape, **({"side": "br"} | (br or {})))
    if sh.c:
        ic.hl(layer, sh, shade_w, opacity=shade_op, color=shade_col, clip=clip)


# =============================================================================================== 1 speed
SW_CX, SW_CY, SW_R, SW_r = 50.0, 56.0, 30.0, 19.4        # stopwatch: dial centre, bezel outer, dial (centre lines)


def stopwatch(ic, dial_accent=None, dial_ink=None):
    """The shared MSP stopwatch body (speed, speed-1000): chunky bezel ring (accent), crown cap + two symmetric pushers
    at +-45 deg on short solid navy necks, white dial. dial_accent(ic) adds tinted dial contents (drawn on the dial,
    under the rim lights); dial_ink(ic) adds the navy dial contents on top."""
    cx, cy, R, r = SW_CX, SW_CY, SW_R, SW_r
    # ---- crown: accent cap on a short navy neck (neck shorter than the stroke: the outlines merge into one solid
    # connector, so no hairline of fill can show between them)
    nw, nh = 7.6, 3.2
    yb = cy - R
    yn = yb - nh
    cw, ch = 22.5, 9.6
    ic.fill("accent", rrect(cx, yn - ch / 2, cw, ch, 4.4), "acc")
    ic.hl("accent", line(cx - cw / 2 + 4.6, yn - ch + 2.6, cx - cw / 2 + 10.2, yn - ch + 2.6), 1.8)
    yi = cy - math.sqrt(R * R - (nw / 2) ** 2)
    ic.line(line(cx - nw / 2, yn, cx - nw / 2, yi))
    ic.line(line(cx + nw / 2, yn, cx + nw / 2, yi))
    ic.line(rrect(cx, yn - ch / 2, cw, ch, 4.4))
    # ---- pushers at +-45 deg: accent cap (a pill across the radius) on a short navy neck
    for sgn in (-1, 1):
        a = 45.0 * sgn
        pw, pl = 6.2, 3.2                   # neck width, neck length (< stroke: solid navy connector)
        kw, kh = 13.0, 7.8                  # cap: tangential width, radial height
        rk = R + pl + kh / 2
        ux, uy = clock(0, 0, 1, a)
        vx, vy = -uy, ux
        kc = (cx + ux * rk, cy + uy * rk)
        ic.fill("accent", rrect(kc[0], kc[1], kw, kh, 3.7, a), "acc")
        r_in, r_bz = R + pl, math.sqrt(R * R - (pw / 2) ** 2)
        for t in (-pw / 2, pw / 2):
            ic.line(line(cx + ux * r_in + vx * t, cy + uy * r_in + vy * t,
                         cx + ux * r_bz + vx * t, cy + uy * r_bz + vy * t))
        ic.line(rrect(kc[0], kc[1], kw, kh, 3.7, a))
        ts, hv = -sgn, kh / 2 - 2.4         # highlight on the half of the cap that faces up
        ic.hl("accent", line(kc[0] + ux * hv + vx * ts * (kw / 2 - 3.4), kc[1] + uy * hv + vy * ts * (kw / 2 - 3.4),
                             kc[0] + ux * hv + vx * ts * 0.6, kc[1] + uy * hv + vy * ts * 0.6), 1.6)
    # ---- body: dial (face) + bezel ring (accent ring, never covers the dial)
    ic.fill("face", circle(cx, cy, r + 0.4), "face")
    ic.fill("accent", circle(cx, cy, R).extend(circle(cx, cy, r)), "acc", rule="evenodd")
    if dial_accent:
        dial_accent(ic)
    rr = R - SW / 2 - 1.35
    ic.hl("accent", arc(cx, cy, rr, -74, -26), 1.9)
    ic.dot("accent", *clock(cx, cy, rr, -15), 1.05)
    ic.hl("accent", arc(cx, cy, r + SW / 2 + 1.1, 105, 200), 1.9, opacity=0.38, color="acc-deep")
    ic.line(circle(cx, cy, R))
    ic.line(circle(cx, cy, r))
    if dial_ink:
        dial_ink(ic)
    return ic


def speed():
    ic = Icon("speed", "Speed Demon - stopwatch", symmetric=True,
              notes="round body with a thick bezel ring (accent), crown cap on a short neck, two symmetric pushers "
                    "at +-45 deg (accent caps), white dial with bold 12/3/6/9 ticks, one hand at 2 o'clock over a "
                    "pale elapsed wedge (accent pale); rim light + glint top-left, bevel shade bottom-right; "
                    "no motion dashes")
    cx, cy, r = SW_CX, SW_CY, SW_r

    def wedge(ic):                                                                   # elapsed 12 -> 2 o'clock
        ic.fill("accent", sector(cx, cy, r - 1.2, 0, 60), "acc-pale", cls="asym")

    def ink(ic):
        for a in (0, 90, 180, 270):                                                  # bold 12/3/6/9 ticks
            ic.line(line(*clock(cx, cy, 11.4, a), *clock(cx, cy, 12.9, a)), width=4.2)
        ic.line(line(cx, cy, *clock(cx, cy, 10.6, 60)), cls="asym")                 # the one hand
        ic.ink(circle(cx, cy, 3.5))

    return stopwatch(ic, wedge, ink)


def speed_1000():
    ic = Icon("speed-1000", "Speed Demon 1000 - stopwatch with a lightning dial", symmetric=True,
              notes="the speed stopwatch (same body, same tints) with a big lightning bolt filling the dial instead "
                    "of ticks + hand: the faster, bigger sibling; no motion dashes")
    cx, cy = SW_CX, SW_CY
    # point-symmetric bolt (180 deg), tips softened; asym = not mirror-symmetric (the symmetry gate skips it)
    pts = [(5.2, -12.6), (-9.6, 4.4), (-1.5, 4.4), (-5.2, 12.6), (9.6, -4.4), (1.5, -4.4)]
    bolt = round_poly([(cx + x, cy + y) for x, y in pts], [3.2, 1.8, 1.2, 3.2, 1.8, 1.2])

    def fill_bolt(ic):
        ic.fill("accent", bolt, "acc", cls="asym")

    def ink(ic):
        ic.line(bolt, width=3.6, cls="asym")

    return stopwatch(ic, fill_bolt, ink)


# =============================================================================================== 2 streak
def streak():
    ic = Icon("streak", "On Fire - flame", symmetric=True,
              notes="two-layer flame: outer flame with a tall centre tongue and two side tongues (accent ring), "
                    "inner butter glow with a white-hot core (face); tips rounded by the round join")
    right = Path().M(50.0, 6.0)
    right.C(53.5, 15.5, 58.0, 26.0, 60.5, 37.5)       # centre tongue, right flank -> notch
    right.C(64.0, 35.0, 69.5, 32.5, 72.5, 27.0)       # side tongue, inner flank -> tip
    right.C(78.0, 35.5, 79.8, 50.0, 79.5, 63.0)       # side tongue, outer flank -> widest point
    right.C(79.2, 80.5, 66.5, 91.0, 50.0, 91.0)       # bowl
    outer = mirror_closed(right)
    ri = Path().M(50.0, 40.0)
    ri.C(53.5, 48.0, 63.5, 56.0, 63.5, 67.5)
    ri.C(63.5, 76.0, 57.5, 80.5, 50.0, 80.5)
    inner = mirror_closed(ri)
    core = Path().M(50.0, 60.5)
    core.C(52.0, 64.0, 56.0, 67.0, 56.0, 71.0).C(56.0, 74.8, 53.2, 76.6, 50.0, 76.6)
    core.C(46.8, 76.6, 44.0, 74.8, 44.0, 71.0).C(44.0, 67.0, 48.0, 64.0, 50.0, 60.5).Z()
    ic.fill("face", inner, "glow")
    ic.fill("face", core, "#ffffff", opacity=0.85)
    ic.fill("accent", Path().extend(outer).extend(inner), "acc", rule="evenodd")
    light(ic, "accent", outer, tl={"thresh": 0.45, "trim": 0.10}, br={"thresh": 0.5})
    ic.line(outer)
    ic.line(inner)
    return ic


# =============================================================================================== 3 explorer
def explorer():
    ic = Icon("explorer", "Puzzle Explorer - jigsaw piece + compass", symmetric=True,
              notes="geometrically correct die-cut piece as the accent body (4 identical round knobs on short "
                    "necks, tangent construction, exact arcs) framing a big white compass card with a classic "
                    "north-south needle (north half coral)")
    cx = cy = 50.0
    S = 42.7
    # thick-stroke anatomy: bigger head, fillet radius > stroke/2, so the visible knob stays a round head on a short
    # neck (not a ball on a stalk): visible head 30 % of the side, visible neck 0.72 x head
    piece = jigsaw_piece(cx, cy, S, ("out", "out", "out", "out"), R=0.185, h=0.20, f=0.055)
    rc = 17.0                     # big compass card: the compass leads, the knobs frame it (less "plus" at 24 px)
    ic.fill("face", circle(cx, cy, rc), "face")
    ic.fill("accent", Path().extend(piece).extend(circle(cx, cy, rc)), "acc", rule="evenodd")
    light(ic, "accent", piece, tl={"thresh": 0.35, "max_runs": 3, "min_len": 5.0, "trim": 0.15},
          br={"thresh": 0.35, "max_runs": 3, "min_len": 5.0, "trim": 0.15}, glint=False)
    nl, nw = 11.9, 6.1            # classic north-south needle, north half coral
    ic.fill("face", polyline([(cx, cy - nl), (cx + nw, cy), (cx - nw, cy)], closed=True), "coral")
    ic.fill("face", polyline([(cx, cy + nl), (cx - nw, cy), (cx + nw, cy)], closed=True), "#ffffff")
    ic.line(piece)
    ic.line(circle(cx, cy, rc))
    ic.line(polyline([(cx, cy - nl), (cx + nw, cy), (cx, cy + nl), (cx - nw, cy)], closed=True), width=2.8)
    ic.ink(circle(cx, cy, 2.1))
    return ic


# =============================================================================================== 4 team-spirit
HEAD = "skin"


def bell(cx, top, bottom, w):
    """Shoulders: a bell-shaped dome on a flat base with rounded corners."""
    hw, h = w / 2, bottom - top
    p = Path().M(cx, top)
    p.C(cx + hw * 0.55, top, cx + hw * 0.92, top + h * 0.22, cx + hw * 0.98, top + h * 0.62)
    p.L(cx + hw, bottom - 3.0).Q(cx + hw, bottom, cx + hw - 3.0, bottom)
    p.L(cx - hw + 3.0, bottom).Q(cx - hw, bottom, cx - hw, bottom - 3.0)
    p.L(cx - hw * 0.98, top + h * 0.62)
    p.C(cx - hw * 0.92, top + h * 0.22, cx - hw * 0.55, top, cx, top)
    p.Z()
    return p


def team_spirit():
    ic = Icon("team-spirit", "Team Spirit - three puzzlers", symmetric=True,
              notes="three friendly puzzlers: the front one wears the tier colour, the two team-mates behind "
                    "wear sky and lavender; hidden lines clipped by the front figure")
    back = []
    for sgn, col in ((-1, "sky"), (1, "lavender")):
        bx = 50 + sgn * 23.5
        b, h = bell(bx, 46.0, 80.0, 36.0), circle(bx, 32.0, 9.2)
        back.append((b, h))
        ic.fill("face", b, col)
        ic.fill("face", h, HEAD)
    # tuned 2026-10-10: the front (tier-coloured) puzzler 46 -> 51 wide and a touch taller, so the tier tint covers
    # >= 25 % of the icon (was 0.23)
    fb, fh = bell(50.0, 50.0, 89.0, 51.0), circle(50.0, 36.0, 11.8)
    ic.fill("accent", fb, "acc")
    ic.fill("face", fh, HEAD)
    light(ic, "accent", fb, tl={"thresh": 0.30, "trim": 0.08}, br={"thresh": 0.45})
    ic.clip_out("front", Path().extend(fb).extend(fh))
    for b, h in back:
        ic.line(b, clip="front")
        ic.line(h, clip="front")
    ic.line(fb)
    ic.line(fh)
    return ic


# =============================================================================================== 5 brand-explorer
def tag_shape(cx, top, bottom, w, roof=0.34, rc=4.2, tip=5.5):
    hw = w / 2
    ys = top + w * roof
    return round_poly([(cx, top), (cx + hw, ys), (cx + hw, bottom), (cx - hw, bottom), (cx - hw, ys)],
                      [tip, rc, rc, rc, rc])


def brand_explorer():
    ic = Icon("brand-explorer", "Brand Explorer - brand tag", symmetric=True,
              notes="an illustrated swing tag in the tier colour with an eyelet, a string loop and a white print "
                    "of the classic die-cut piece (2 knobs, 2 sockets): the brand label of a puzzle box")
    body = tag_shape(50.0, 21.0, 88.0, 48.0, roof=0.32, rc=4.4, tip=6.0)
    ic.fill("accent", body, "acc")
    light(ic, "accent", body, tl={"thresh": 0.30, "trim": 0.10}, br={"thresh": 0.45})
    # print = the classic piece (knobs top + right, sockets bottom + left) as a white silhouette (a print, like the
    # piece prints on the boxes of the site's puzzle-database art: no outline), its bbox centred on the tag face
    ps = 20.0
    pc = jigsaw_piece(50.0 - 0.175 * ps, 63.0 + 0.175 * ps, ps, ("out", "out", "in", "in"), stroke=0.0)
    ic.fill("accent", pc, "#ffffff", opacity=0.96, cls="asym")
    ic.line(body)
    ey, er = 34.5, 4.4
    ic.fill("face", circle(50.0, ey, er), "#ffffff")
    ic.line(circle(50.0, ey, er), width=3.4)
    loop = Path().M(50.0, ey - er).C(44.0, 26.0, 38.5, 15.5, 44.0, 10.5).C(47.0, 8.0, 53.0, 8.0, 56.0, 10.5)
    loop.C(61.5, 15.5, 56.0, 26.0, 50.0, ey - er)
    ic.line(loop)
    return ic


# =============================================================================================== 6 marathoner
def ground_hit(p0, p1, gc, gr):
    """Where the line p0 -> p1 (beyond p0) meets the ground circle (centre gc, radius gr), p0 inside it."""
    dx, dy = p1[0] - p0[0], p1[1] - p0[1]
    fx, fy = p0[0] - gc[0], p0[1] - gc[1]
    a, b, c = dx * dx + dy * dy, 2 * (fx * dx + fy * dy), fx * fx + fy * fy - gr * gr
    t = (-b + math.sqrt(b * b - 4 * a * c)) / (2 * a)
    return p0[0] + dx * t, p0[1] + dy * t


def marathoner():
    ic = Icon("marathoner", "Marathoner - summit with a flag", symmetric=True,
              notes="a big mountain (accent) with a two-drip snow cap and a coral summit flag, two lower peaks "
                    "behind (sky, lavender) on a gently rounded ground: conquer the big ones")
    # tuned 2026-10-10: the ground is a gentle convex arc instead of a straight line - the straight ground's ends were
    # the farthest points (the badge shrank the icon to 45 %); on the arc the range stays inside the badge plate
    A, yb = (50.0, 22.0), 84.0
    GR = 58.0
    GC = (50.0, yb - GR)                                # the ground arc touches y = yb under the summit
    def ground(x):
        return GC[1] + math.sqrt(GR * GR - (x - GC[0]) ** 2)
    def garc(pa, pb):                                   # ground arc from pa to pb (left -> right, under the range)
        return Path().M(*pa).A(GR, *pb, 0, 0)
    LA, RA = (23.0, 45.0), (77.0, 45.0)
    LB0, RB0 = ground_hit(LA, (5.0, yb), GC, GR), ground_hit(RA, (95.0, yb), GC, GR)
    BL, BR = ground_hit(A, (19.0, yb), GC, GR), ground_hit(A, (81.0, yb), GC, GR)
    LB1, RB1 = (46.0, ground(46.0)), (54.0, ground(54.0))
    xl, xr = seg_intersect(LA, LB1, A, BL), seg_intersect(RA, RB1, A, BR)
    ml, mr = (34.0, ground(34.0)), (66.0, ground(66.0))
    left = Path().M(*LB0).L(*LA).L(*xl).L(*ml).A(GR, *LB0, 0, 1).Z()
    right = Path().M(*RB0).L(*RA).L(*xr).L(*mr).A(GR, *RB0, 0, 0).Z()
    ic.fill("face", left, "sky")
    ic.fill("face", right, "lavender")
    ys = 46.0
    t = (ys - A[1]) / (BL[1] - A[1])
    PL, PR = lerp(A, BL, t), lerp(A, BR, t)
    sl = Path().M(*PL)
    sl.C(PL[0] + 2.0, ys + 2.0, 40.5, 52.5, 43.5, 52.5)
    sl.C(46.5, 52.5, 46.0, 47.0, 50.0, 47.0)
    snow_line = join(sl, reverse(sl.mirrored(50.0)), close=False)
    ra = 3.2                                            # rounded apex (quadratic fillet)
    da = math.hypot(BL[0] - A[0], BL[1] - A[1])
    a0 = (A[0] + (BL[0] - A[0]) * ra / da, A[1] + (BL[1] - A[1]) * ra / da)
    a1 = (A[0] + (BR[0] - A[0]) * ra / da, A[1] + (BR[1] - A[1]) * ra / da)
    snow = join(Path().M(*a1).Q(*A, *a0).L(*PL), snow_line, Path().M(*PR).L(*a1))
    body = join(Path().M(*PL).L(*BL).A(GR, *BR, 0, 0).L(*PR), reverse(snow_line))
    ic.fill("face", snow, "snow")
    ic.fill("accent", body, "acc")
    light(ic, "accent", body, tl={"side": (-1.0, -0.45), "thresh": 0.6, "trim": 0.14},
          br={"side": (1.0, -0.45), "thresh": 0.6, "trim": 0.14})
    ic.line(Path().M(*LB0).L(*LA).L(*xl))
    ic.line(Path().M(*RB0).L(*RA).L(*xr))
    ic.line(Path().M(*BL).L(*a0).Q(*A, *a1).L(*BR))
    ic.line(garc(LB0, RB0))
    ic.line(snow_line, width=3.6)
    pen = round_poly([(50.0, 6.5), (64.0, 10.6), (50.0, 15.6)], [0, 1.6, 0])
    ic.fill("face", pen, "coral", cls="asym")
    ic.line(pen, width=3.6, cls="asym")
    ic.line(line(50.0, A[1] - 1.0, 50.0, 6.0), cls="asym")
    return ic


# =============================================================================================== 7 piece-cruncher
def piece_at(cx, cy, S, sides, ang=0.0, **kw):
    """A jigsaw_piece rotated by ang degrees about its body centre."""
    p = jigsaw_piece(cx, cy, S, sides, **kw)
    return p.mapped(lambda x, y: rot(x, y, ang, cx, cy)) if ang else p


def piece_cruncher():
    ic = Icon("piece-cruncher", "Piece Cruncher - a 2x2 jigsaw", symmetric=False,
              notes="four interlocked die-cut pieces - a complete 2x2 jigsaw: one knob or socket on every inner side, "
                    "the outer sides are the straight border of the puzzle with big rounded corners; checkerboard: "
                    "two pieces in the tier colour, two in sky and lavender. Not mirror-symmetric inside: an "
                    "interlock always points one way")
    S = 37.5
    kw = dict(R=0.19, h=0.205, f=0.06)
    c0 = 50.0
    # tuned 2026-10-10: the outer sides are the puzzle's straight border (border pieces, like a real jigsaw and the
    # last-piece reveal cut) with big rounded corners - a square with notched sides cannot round its corners (the
    # sockets sit there), so its corners left the badge plate and the icon shrank to 43 %
    ro, ri = 24.0 / S, 0.085
    spec = [((c0 - S / 2, c0 - S / 2), ("flat", "out", "in", "flat"), "acc", (ro, ri, ri, ri)),       # TL
            ((c0 + S / 2, c0 - S / 2), ("flat", "flat", "out", "in"), "sky", (ri, ro, ri, ri)),       # TR
            ((c0 - S / 2, c0 + S / 2), ("out", "in", "flat", "flat"), "lavender", (ri, ri, ri, ro)),  # BL
            ((c0 + S / 2, c0 + S / 2), ("in", "flat", "flat", "out"), "acc", (ri, ri, ro, ri))]       # BR
    pieces = []
    for (x, y), sides, paint, corners in spec:
        p = jigsaw_piece(x, y, S, sides, corner=corners, border=True, **kw)
        pieces.append((p, paint))
        ic.fill("face" if paint != "acc" else "accent", p, paint)
    for p, paint in pieces:
        if paint == "acc":
            light(ic, "accent", p, tl={"thresh": 0.35, "max_runs": 2, "min_len": 5.0, "trim": 0.15}, glint=False,
                  shade=False)
    for p, _ in pieces:
        ic.line(p, width=2.6)                       # inner seams: interior detail weight (S1: 0.5-0.7 x)
    ic.line(rrect(c0, c0, 2 * S, 2 * S, ro * S))    # the outer silhouette (the puzzle's border) at the one weight
    return ic


# =============================================================================================== 8 zen-puzzler
def petal(bx, by, L, W, ang):
    loc = Path().M(0, 0)
    loc.C(W * 0.55, -L * 0.04, W * 1.0, -L * 0.33, W * 0.66, -L * 0.66)
    loc.C(W * 0.42, -L * 0.86, W * 0.12, -L * 0.96, 0, -L)
    loc.C(-W * 0.12, -L * 0.96, -W * 0.42, -L * 0.86, -W * 0.66, -L * 0.66)
    loc.C(-W * 1.0, -L * 0.33, -W * 0.55, -L * 0.04, 0, 0).Z()
    return loc.mapped(lambda x, y: rot(bx + x, by + y, ang, bx, by))


def zen_puzzler():
    ic = Icon("zen-puzzler", "Zen Puzzler - lotus", symmetric=True,
              notes="a lotus of three big petals (centre in the tier colour, sides in the pale tier tint) rising from "
                    "a teal water leaf that hides their bases; calm, symmetric, few lines")
    bx, by = 50.0, 84.0
    sides = [petal(bx - s * 9.0, by, 50.0, 19.0, s * 56.0) for s in (-1, 1)]
    centre = petal(bx, by, 64.0, 19.0, 0.0)
    leaf = Path().M(14.0, 73.0).C(21.0, 93.0, 79.0, 93.0, 86.0, 73.0).C(70.0, 76.0, 30.0, 76.0, 14.0, 73.0).Z()
    ic.fill("face", leaf, "teal")
    ic.clip_out("leaf", leaf)
    for p in sides:
        ic.fill("accent", p, "acc-pale", clip="leaf")
    ic.fill("accent", centre, "acc", clip="leaf")
    light(ic, "accent", centre, tl={"thresh": 0.3, "trim": 0.12}, br={"thresh": 0.45}, clip="leaf")
    ic.clip_out("before-sides", Path().extend(leaf).extend(centre))
    for p in sides:
        ic.line(p, clip="before-sides")
    ic.line(centre, clip="leaf")
    ic.line(leaf)
    return ic


# =============================================================================================== 9 first-try
def first_try():
    ic = Icon("first-try", "First Try - arrow in the bullseye", symmetric=True,
              notes="a target (accent rings, white ring) with one arrow in the bullseye, coming in from the upper "
                    "right; its coral fletching rests on the outer ring, so the whole icon stays round and fills "
                    "the badge's plate like the other round icons")
    cx, cy = 50.0, 50.0
    r1, r2, r3 = 36.0, 25.0, 14.0
    # arrow from the upper right (45 deg): shaft to the bullseye, coral fletching ON the outer ring (tuned
    # 2026-10-10: fletching outside the ring made the arrow tail the farthest point, the badge shrank the icon to 47 %)
    u = (math.cos(math.radians(-45)), math.sin(math.radians(-45)))
    v = (-u[1], u[0])
    def P(r, s=0.0):
        return cx + u[0] * r + v[0] * s, cy + u[1] * r + v[1] * s
    vanes = [round_poly([P(r1 - 6.4, 0.0), P(r1 - 2.6, sgn * 5.6), P(r1 + 2.8, sgn * 5.6), P(r1 - 0.2, 0.0)],
                        [0, 1.4, 1.4, 0]) for sgn in (-1, 1)]
    ic.clip_out("vanes", Path().extend(vanes[0]).extend(vanes[1]))
    ic.fill("face", circle(cx, cy, r2), "face")
    ic.fill("accent", circle(cx, cy, r1).extend(circle(cx, cy, r2)), "acc", rule="evenodd")
    ic.fill("accent", circle(cx, cy, r3), "acc")
    light(ic, "accent", circle(cx, cy, r1), tl={"thresh": 0.45}, br={"thresh": 0.5})
    for vane in vanes:                       # constant coral, in the accent layer so it lies on top of the ring
        ic.fill("accent", vane, "coral", cls="asym")
    ic.line(circle(cx, cy, r1), clip="vanes")
    ic.line(circle(cx, cy, r2))
    ic.line(circle(cx, cy, r3))
    for vane in vanes:
        ic.line(vane, width=4.0, cls="asym")
    ic.line(line(*P(2.0), *P(r1 - 1.4)), cls="asym")
    ic.ink(circle(cx, cy, 3.4))
    return ic


# =============================================================================================== 10 unboxed
def unboxed():
    """REJECTED 2026-10-10 (wrong meaning: Unboxed is a solve WITHOUT the box picture); kept as a variant."""
    ic = Icon("unboxed", "Unboxed - open puzzle box", symmetric=False,
              notes="an open puzzle box (tier colour) with a big white die-cut piece rising out of it: solved straight "
                    "out of the box, without the box picture")
    # tuned 2026-10-10: the box 76 -> 58 wide with rounder bottom corners (5 -> 12): its bottom corners were the
    # farthest points and the badge shrank the icon to 44 %; now the box sits inside the plate
    wall = round_poly([(26.0, 66.0), (32.0, 58.0), (68.0, 58.0), (74.0, 66.0)], [0, 2.0, 2.0, 0])
    pc = piece_at(47.0, 49.0, 38.0, ("out", "out", "in", "in"), 6.0, R=0.19, h=0.21, f=0.064)
    front = round_poly([(21.0, 66.0), (79.0, 66.0), (79.0, 89.0), (21.0, 89.0)], [2.0, 2.0, 12.0, 12.0])
    ic.fill("face", wall, "metal")
    ic.fill("face", pc, "#ffffff")
    ic.fill("accent", front, "acc")
    light(ic, "accent", front, tl={"thresh": 0.3}, br={"thresh": 0.45})
    ic.clip_out("front", front)
    ic.clip_out("front-pc", Path().extend(front).extend(pc))
    ic.line(wall, clip="front-pc")
    ic.line(pc, clip="front")
    ic.line(front)
    return ic


# ----------------------------------------------------------------------------------------------- unboxed options
# Owner 2026-10-10: "Unboxed" = solving WITHOUT looking at the picture on the box (blind solve, no reference image).
# Concepts drawn: A eye-off over a box, B "?" box, C blindfolded piece (badge-lab/review/unboxed-options.png). The owner
# picked A without the box -> unboxed_eye below, shipped via registry.py. The other functions are rejected variants;
# each draws an icon named "unboxed", so any of them is still a one-word switch in registry.py.
def shapely_path(geom):
    """A shapely polygon (outer ring only) as an icon Path (straight segments)."""
    pts = list(geom.exterior.coords)[:-1]
    return polyline([(round(x, 3), round(y, 3)) for x, y in pts], closed=True)


def picture_box(ic, cx, cy, w, h, rc, clip=None):
    """A puzzle box lid in the tier colour (a wide accent frame) whose middle shows the box picture (face: sky and a
    teal hill). Returns (lid, picture) paths; outlines are left to the caller."""
    lid = rrect(cx, cy, w, h, rc)
    pw, ph = w - 24.0, h - 18.0
    pic = rrect(cx, cy, pw, ph, 3.0)
    x0, x1, y1 = cx - pw / 2, cx + pw / 2, cy + ph / 2
    hill = Path().M(x0, y1).L(x0, y1 - ph * 0.30).Q(cx - pw * 0.10, y1 - ph * 0.95, x1, y1 - ph * 0.32).L(x1, y1).Z()
    ic.fill("face", pic, "sky-pale", clip=clip)
    ic.fill("face", hill, "teal", clip=clip)
    ic.fill("accent", Path().extend(lid).extend(pic), "acc", rule="evenodd", clip=clip)
    return lid, pic


def question_mark(cx, cy, r, half, a0=-100):
    """A chunky question mark: the centre line (hook of radius r from clock angle a0 over the top, a short drop)
    buffered by `half` with round caps and joins, + its dot. Returns (glyph path, dot circle). a0 = -100: the hook's
    end points down-left, so its counter stays open (a later start closes it into a hairline sliver)."""
    from shapely.geometry import LineString
    hook = [clock(cx, cy, r, a) for a in range(a0, 181, 10)] + [(cx, cy + r + 3.6)]
    q = LineString(hook).buffer(half, quad_segs=6, cap_style="round", join_style="round")
    return shapely_path(q), circle(cx, cy + r + 4.6 + 2.6 * half, half * 1.08)


def unboxed_eye_off():
    """Option A: an eye with a diagonal slash over a puzzle box whose lid shows its picture."""
    ic = Icon("unboxed", "Unboxed - eyes off the box picture (option A)", symmetric=True,
              notes="option A: an eye (tier-coloured iris) with a diagonal slash in front of a puzzle box whose lid "
                    "shows the picture - solved without looking at the box")
    eye_c, ew, eh = (50.0, 35.0), 52.0, 28.0
    R = ((ew / 2) ** 2 + (eh / 2) ** 2) / eh
    eye = Path().M(eye_c[0] - ew / 2, eye_c[1]).A(R, eye_c[0] + ew / 2, eye_c[1], 0, 1).A(R, eye_c[0] - ew / 2,
                                                                                           eye_c[1], 0, 1).Z()
    ic.clip_out("eye", eye)
    lid, pic = picture_box(ic, 50.0, 67.0, 58.0, 38.0, 11.0, clip="eye")
    light(ic, "accent", lid, tl={"thresh": 0.3}, br={"thresh": 0.45}, clip="eye")
    ic.fill("face", eye, "face")
    iris = circle(eye_c[0], eye_c[1], 7.6)
    ic.fill("accent", iris, "acc")
    ic.line(lid, clip="eye")
    ic.line(pic, width=2.6, clip="eye")
    ic.line(eye)
    ic.line(iris, width=3.0)
    ic.ink(circle(eye_c[0], eye_c[1], 3.4))
    lid_top = 67.0 - 19.0                   # the slash ends exactly on the lid's top edge (no hairline gap)
    ic.line(line(eye_c[0] - 21.0, eye_c[1] - 19.0, eye_c[0] + 21.0 * (lid_top - eye_c[1]) / 19.0, lid_top),
            cls="asym")                                                                             # the slash
    return ic


def unboxed_question_box():
    """Option B: a puzzle box with a big question mark where the picture would be."""
    ic = Icon("unboxed", "Unboxed - no picture on the box (option B)", symmetric=True,
              notes="option B: a puzzle box (tier colour, lid seam across the top) with a big white question mark "
                    "where the picture would be - solved without the reference picture")
    box = rrect(50.0, 52.0, 70.0, 58.0, 13.0)
    seam_y = 52.0 - 29.0 + 11.0
    glyph, dot = question_mark(50.0, 51.5, 6.6, 3.2)
    ic.fill("accent", box, "acc")
    light(ic, "accent", box, tl={"thresh": 0.3}, br={"thresh": 0.45}, glint=False)
    ic.fill("accent", glyph, "#ffffff", cls="asym")             # constant white, on top of the tinted box
    ic.fill("accent", dot, "#ffffff", cls="asym")
    ic.line(box)
    ic.line(line(50.0 - 35.0, seam_y, 50.0 + 35.0, seam_y), width=3.2)   # the lid seam
    ic.line(glyph, width=3.2, cls="asym")
    ic.line(dot, width=3.2, cls="asym")
    return ic


def unboxed_blindfold():
    """Option C: a puzzle piece wearing a blindfold band (no face)."""
    ic = Icon("unboxed", "Unboxed - a blindfolded piece (option C)", symmetric=False,
              notes="option C: a big die-cut puzzle piece in the tier colour crossed by a coral blindfold band, tied "
                    "in a knot at its right side with the notched loose ends hanging - solved blind, without the "
                    "picture (no face)")
    cx = cy = 50.0
    S = 44.0
    piece = jigsaw_piece(cx, cy, S, ("out", "in", "out", "in"), R=0.185, h=0.20, f=0.055)
    bx0, bx1, by0, by1 = cx - S / 2 - 3.0, cx + S / 2 + 2.0, 43.0, 56.0
    band = round_poly([(bx0, by0), (bx1, by0), (bx1, by1), (bx0, by1)], [3.0, 3.0, 3.0, 3.0])
    kx, ky = bx1 + 1.0, (by0 + by1) / 2
    knot = circle(kx, ky, 5.2)
    # the two loose ends as ONE ribbon with a V-notch (two separate tails left a hairline gap at the knot)
    tails = [round_poly([(kx - 2.0, ky + 2.0), (kx + 4.0, ky + 1.0), (kx + 14.0, ky + 17.0), (kx + 8.5, ky + 15.0),
                         (kx + 5.0, ky + 21.0)], [0, 0, 2.6, 1.2, 2.6])]
    ic.clip_out("knot", knot)
    ic.clip_out("front", Path().extend(band).extend(knot))
    ic.clip_out("all-front", Path().extend(band).extend(knot).extend(tails[0]))
    ic.fill("accent", piece, "acc", clip="all-front")
    light(ic, "accent", piece, tl={"thresh": 0.35, "max_runs": 3, "min_len": 5.0, "trim": 0.15},
          br={"thresh": 0.35, "max_runs": 3, "min_len": 5.0, "trim": 0.15}, glint=False, clip="all-front")
    for t in tails:
        ic.fill("face", t, "coral", cls="asym")
    ic.fill("face", band, "coral", cls="asym")
    ic.fill("face", knot, "coral", cls="asym")
    ic.line(piece, clip="all-front")
    for t in tails:
        ic.line(t, clip="front", cls="asym")
    ic.line(band, clip="knot", cls="asym")
    ic.line(knot, cls="asym")
    return ic


def unboxed_eye(outline_gap=True):
    """SHIPPED (owner pick 2026-10-10, option A without the box): a big crossed-out eye - solved without looking at
    the box picture. The almond is the tier tint (+ the house light), the iris a constant white, the pupil navy; the
    navy slash lies in a white keyline band (a fill: no stroke may be wider than the outline) so it reads in front.
    outline_gap: the almond outline also stops at the keyline where the slash crosses it; that gapped outline is
    declared asymmetric together with the slash (the almond fill, iris and pupil stay mirror-symmetric and checked)."""
    ic = Icon("unboxed", "Unboxed - eyes off the box picture", symmetric=True,
              notes="a big crossed-out eye: the almond in the tier colour, a white iris with a navy pupil, a navy "
                    "slash with a white keyline in front of it - solved without looking at the picture on the box")
    cx = cy = 50.0
    ew, eh = 80.0, 46.0
    R = ((ew / 2) ** 2 + (eh / 2) ** 2) / eh
    eye = Path().M(cx - ew / 2, cy).A(R, cx + ew / 2, cy, 0, 1).A(R, cx - ew / 2, cy, 0, 1).Z()
    iris = circle(cx, cy, 14.5)
    half, gap = 36.5, 2.6                                    # slash half-length (45 deg) and the keyline gap
    u = (math.cos(math.radians(45.0)), math.sin(math.radians(45.0)))
    slash = line(cx - u[0] * half, cy - u[1] * half, cx + u[0] * half, cy + u[1] * half)
    kw = SW + 2 * gap
    key = rrect(cx, cy, 2 * half + kw, kw, kw / 2, 45.0)
    ic.fill("accent", Path().extend(eye).extend(iris), "acc", rule="evenodd")
    light(ic, "accent", eye, tl={"thresh": 0.45, "trim": 0.10}, br={"thresh": 0.5})
    ic.fill("face", iris, "face")
    ic.fill("accent", key, "#ffffff", cls="asym")            # the keyline, on top of the tinted almond and the iris
    if outline_gap:
        ic.clip_out("key", key)
        ic.line(eye, clip="key", cls="asym")
    else:
        ic.line(eye)
    ic.line(iris, width=3.4)
    ic.ink(circle(cx, cy, 6.6))
    ic.line(slash, cls="asym")
    return ic


# rejected variants kept for the record: unboxed (open box + piece, wrong meaning), options A (eye over a box),
# B (box with "?"), C (blindfolded piece); the shipped icon is unboxed_eye (registry.py)
UNBOXED_OPTIONS = {"A": unboxed_eye_off, "B": unboxed_question_box, "C": unboxed_blindfold, "final": unboxed_eye}


# =============================================================================================== 11 photographer
def photographer():
    ic = Icon("photographer", "Photographer - camera", symmetric=True,
              notes="a chunky camera: body + viewfinder hump in the tier colour, metal lens barrel with a sky glass "
                    "and a glint, a coral shutter button and a small flash")
    body = rrect(50.0, 59.0, 78.0, 50.0, 9.0)
    hump = round_poly([(35.0, 36.0), (40.0, 24.0), (60.0, 24.0), (65.0, 36.0)], [0, 3.0, 3.0, 0])
    btn = rrect(74.0, 32.0, 12.0, 10.0, 3.2)
    ic.fill("face", btn, "coral", cls="asym")
    ic.fill("accent", hump, "acc")
    ic.fill("accent", body, "acc")
    light(ic, "accent", body, tl={"thresh": 0.3}, br={"thresh": 0.45})
    barrel, glass = circle(50.0, 59.0, 18.0), circle(50.0, 59.0, 9.0)
    ic.fill("accent", barrel, "metal")
    ic.fill("accent", glass, "sky")
    ic.hl("accent", arc(50.0, 59.0, 5.4, -80, -20), 1.8, opacity=0.9)
    ic.dot("accent", *clock(50.0, 59.0, 5.4, 5), 1.0, opacity=0.9)
    flash = rrect(23.5, 43.0, 9.5, 6.0, 1.8)
    ic.fill("accent", flash, "#ffffff", cls="asym")
    ic.clip_out("body", body)
    ic.line(hump, clip="body")
    ic.line(btn, clip="body", cls="asym")
    ic.line(body)
    ic.line(barrel)
    ic.line(glass, width=3.0)
    ic.line(flash, width=2.6, cls="asym")
    return ic


# =============================================================================================== 12 steady-hands
def steady_hands():
    ic = Icon("steady-hands", "Steady Hands - every quarter ticked", symmetric=True,
              notes="a calendar: tier-coloured header with navy binder rings, white page with four tier-tinted "
                    "cells, each with a bold tick - a solve in every quarter, no gaps")
    # tuned 2026-10-10: rounder corners (r 8 -> 19) so the corners stay inside the badge plate (45.5 % -> ~51 % of the
    # badge) and stronger cells (tier tint at 55 % instead of 45 %) so the tier tint covers >= 25 % of the icon
    x0, x1, y0, yh, y1, rc = 19.0, 81.0, 24.0, 43.0, 86.0, 19.0
    head = round_poly([(x0, yh), (x0, y0), (x1, y0), (x1, yh)], [0, rc, rc, 0])
    page = round_poly([(x0, yh), (x1, yh), (x1, y1), (x0, y1)], [0, 0, rc, rc])
    ic.fill("face", page, "face")
    ic.fill("accent", head, "acc")
    light(ic, "accent", head, tl={"thresh": 0.3, "trim": 0.08}, br={"thresh": 0.6}, glint=False, shade=False)
    cells = ((35.0, 55.5), (65.0, 55.5), (35.0, 72.0), (65.0, 72.0))
    for (cx, cy) in cells:
        ic.fill("accent", rrect(cx, cy, 23.0, 13.0, 4.5), "acc", opacity=0.55)
    ic.line(round_poly([(x0, y0), (x1, y0), (x1, y1), (x0, y1)], [rc, rc, rc, rc]))
    ic.line(line(x0, yh, x1, yh))
    for (cx, cy) in cells:
        ic.line(Path().M(cx - 5.2, cy + 0.2).L(cx - 1.6, cy + 3.6).L(cx + 5.4, cy - 3.6), width=3.8, cls="asym")
    for rx in (35.0, 65.0):
        ic.ink(rrect(rx, 24.0, 6.4, 15.0, 3.2))
    return ic


# =============================================================================================== 13 librarian
def librarian():
    ic = Icon("librarian", "Librarian - open book", symmetric=True,
              notes="an open book: white pages with a few navy lines, the cover in the tier colour around them, a "
                    "coral bookmark ribbon at the spine")
    # tuned 2026-10-10: pages 72 -> 64 wide and the cover 3 units deeper, so the tier-coloured cover shows more around
    # the pages (visible tint 0.20 -> >= 0.25 of the icon)
    def page(sgn):
        o = 50 + sgn * 32.0
        p = Path().M(50.0, 30.0)
        p.C(50 + sgn * 10.0, 22.5, 50 + sgn * 22.0, 21.0, o, 24.0)
        p.L(o, 74.0)
        p.C(50 + sgn * 22.0, 71.0, 50 + sgn * 10.0, 72.0, 50.0, 80.0)
        p.Z()
        return p
    left, right = page(-1), page(1)
    cover = round_poly([(4.0, 35.0), (50.0, 43.0), (96.0, 35.0), (96.0, 90.0), (50.0, 98.0), (4.0, 90.0)],
                       [4.0, 0, 4.0, 5.0, 4.0, 5.0])
    ribbon = round_poly([(45.5, 89.0), (54.5, 89.0), (54.5, 105.0), (50.0, 101.0), (45.5, 105.0)], [0, 0, 1.0, 0.5, 1.0])
    pages = Path().extend(left).extend(right)
    ic.clip_out("pages", pages)
    ic.fill("face", ribbon, "coral")
    ic.fill("face", left, "face")
    ic.fill("face", right, "face")
    ic.fill("accent", cover, "acc", clip="pages")
    ic.clip_out("cover-pages", Path().extend(pages).extend(cover))
    ic.line(ribbon, width=3.2, clip="cover-pages")
    ic.line(cover, clip="pages")
    ic.line(left)
    ic.line(right)
    for sgn in (-1, 1):
        for k, (y, l) in enumerate(((38.0, 18.0), (48.0, 18.0), (58.0, 12.0))):
            xa = 50 + sgn * 8.5
            ic.line(line(xa, y + 1.2, xa + sgn * l, y - 1.0), width=2.6)
    return ic


# =============================================================================================== 14 weekend-puzzler
def weekend_puzzler():
    ic = Icon("weekend-puzzler", "Weekend Puzzler - cosy sofa", symmetric=True,
              notes="a cosy sofa in the tier colour with two pale seat cushions and little navy feet")
    back = rrect(50.0, 45.0, 62.0, 30.0, 9.0)
    arms = [rrect(16.5, 63.0, 17.0, 32.0, 7.5), rrect(83.5, 63.0, 17.0, 32.0, 7.5)]
    base = rrect(50.0, 75.0, 52.0, 12.0, 3.0)
    cush = [rrect(37.5, 64.0, 25.0, 13.0, 4.5), rrect(62.5, 64.0, 25.0, 13.0, 4.5)]
    ic.fill("accent", back, "acc")
    ic.fill("accent", base, "acc")
    for c in cush:
        ic.fill("accent", c, "acc-pale")
    for a in arms:
        ic.fill("accent", a, "acc")
    light(ic, "accent", back, tl={"thresh": 0.3}, glint=True, shade=False)
    light(ic, "accent", arms[0], tl={"thresh": 0.35}, glint=False, shade=False)
    front = Path().extend(arms[0]).extend(arms[1]).extend(cush[0]).extend(cush[1]).extend(base)
    ic.clip_out("front", front)
    ic.clip_out("arms", Path().extend(arms[0]).extend(arms[1]))
    ic.line(back, clip="front")
    ic.line(base, clip="arms")
    for c in cush:
        ic.line(c)
    for a in arms:
        ic.line(a)
    for fx in (22.0, 78.0):
        ic.ink(rrect(fx, 83.5, 7.0, 6.0, 2.4))
    return ic


# =============================================================================================== 15 cataloger
def cataloger():
    ic = Icon("cataloger", "Cataloger - clipboard + new entry", symmetric=False,
              notes="a clipboard in the tier colour with a metal clip, a white sheet with a coral die-cut piece "
                    "print and two lines, and a coral plus badge: a new puzzle added to the catalogue. The board "
                    "is centred and mirror-symmetric (silhouette IoU 1.000); the badge in front makes the drawing "
                    "deliberately asymmetric")
    # tuned 2026-10-10: a slightly smaller sheet (44x52 -> 40x50) shows more of the tier-coloured board (visible tint
    # 0.23 -> >= 0.25) and rounder board corners (7 -> 11) keep the icon inside the badge plate
    board = rrect(50.0, 56.0, 58.0, 74.0, 11.0)
    sheet = rrect(50.0, 57.0, 40.0, 50.0, 3.0)
    clip = round_poly([(39.0, 32.0), (41.0, 20.0), (59.0, 20.0), (61.0, 32.0)], [2.0, 4.0, 4.0, 2.0])
    badge = circle(68.5, 82.0, 10.5)
    ic.fill("face", sheet, "face")
    ic.clip_out("sheet", sheet)
    ic.fill("accent", board, "acc", clip="sheet")
    light(ic, "accent", board, tl={"thresh": 0.3}, br={"thresh": 0.45}, clip="sheet")
    pc = jigsaw_piece(39.0, 45.0, 10.5, ("out", "out", "in", "in"), stroke=0.0)
    ic.fill("accent", pc, "coral", cls="asym")
    ic.fill("accent", clip, "metal")
    ic.fill("accent", badge, "coral", cls="asym")
    ic.hl("accent", line(68.5, 77.0, 68.5, 87.0), 3.4, opacity=1.0, cls="asym")
    ic.hl("accent", line(63.5, 82.0, 73.5, 82.0), 3.4, opacity=1.0, cls="asym")
    ic.clip_out("front", Path().extend(clip).extend(badge))
    ic.clip_out("badge", badge)
    ic.line(board, clip="front")
    ic.line(sheet, clip="front")
    ic.line(clip)
    ic.ink(rrect(50.0, 23.6, 6.0, 3.4, 1.7))
    ic.line(line(49.0, 44.0, 63.0, 44.0), width=2.6, clip="badge", cls="asym")
    ic.line(line(36.0, 57.0, 63.0, 57.0), width=2.6, clip="badge", cls="asym")
    ic.line(line(36.0, 67.0, 54.0, 67.0), width=2.6, clip="badge", cls="asym")
    ic.line(badge, cls="asym")
    return ic


# =============================================================================================== 16 supporter
def supporter():
    ic = Icon("supporter", "Supporter - heart", symmetric=True,
              notes="a big friendly heart in the tier colour with the house light (single-tier badge: use -msp)")
    heart = Path().M(50.0, 31.0)
    heart.C(46.0, 21.0, 38.0, 16.0, 30.0, 16.0)
    heart.C(19.0, 16.0, 11.0, 25.0, 11.0, 37.0)
    heart.C(11.0, 56.0, 31.0, 70.0, 50.0, 86.0)
    heart.C(69.0, 70.0, 89.0, 56.0, 89.0, 37.0)
    heart.C(89.0, 25.0, 81.0, 16.0, 70.0, 16.0)
    heart.C(62.0, 16.0, 54.0, 21.0, 50.0, 31.0).Z()
    ic.fill("accent", heart, "acc")
    light(ic, "accent", heart, tl={"thresh": 0.35, "trim": 0.1}, br={"thresh": 0.45})
    ic.line(heart)
    return ic
