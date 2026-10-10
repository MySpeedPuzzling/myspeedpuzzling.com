"""SVG optimiser for the composed badges (own code: deterministic, no Node/svgo, no network).

The F1 master SVG is heavy (T5 ~ 310 kB) because shapely writes dense polylines (2-decimal absolute points every
0.2-0.5 px along every rounded corner) and every frame part appears three times (fill, shade clip, outline). This
rewrites it without a visible change (qa.py gate "optimiser": the shipped SVG rendered at 1024 px differs from the
master by <= 1 level on >= 99.9 % of pixels):
  lossless   path data re-encoded with exact decimal arithmetic (shortest of absolute/relative per segment, H/V for
             axis-parallel lines, implicit commands, minimal number syntax); a path used several times (fill + clip +
             outline) is defined once and referenced by <use>; identical gradients / filters / clip paths merged; all
             <defs> hoisted into one; numbers and colours shortened; comments, classes and unused ids dropped;
  invisible  paths under a Gaussian blur (the soft shade bands) are simplified (Douglas-Peucker, tolerance
             BLUR_TOL x stdDeviation = 0.02-0.12 px): the blur hides the change. Measured on all tiers: worst
             0.02 % of pixels > 1 level (0.04 x sigma reached 0.12 %: rsvg quantises blurs to 8 bit, so moved
             blurred geometry shows as +-1..4 level speckle); the lossless steps alone are pixel-identical.
Ids get a per-file prefix (`id_prefix`), so several badge SVGs can be inlined into one HTML page.
"""
import re
import xml.etree.ElementTree as ET
from decimal import Decimal

SVG_NS = "http://www.w3.org/2000/svg"
NUMERIC_ATTRS = {"x1", "y1", "x2", "y2", "cx", "cy", "r", "fx", "fy", "offset", "stdDeviation", "stroke-width",
                 "fill-opacity", "stroke-opacity", "stop-opacity", "opacity"}
COLOUR_ATTRS = {"fill", "stroke", "stop-color"}
DEFAULT_ONE = {"stop-opacity", "fill-opacity", "stroke-opacity", "opacity"}
SHARE_MIN_LEN = 48          # share a path's data via <use> only when it is longer than this
BLUR_TOL = 0.01             # Douglas-Peucker tolerance of a blurred path = BLUR_TOL x stdDeviation (px)
_TOKEN = re.compile(r"[MmLlHhVvCcSsQqTtAaZz]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?")
_ARGS = {"M": 2, "L": 2, "H": 1, "V": 1, "C": 6, "S": 4, "Q": 4, "T": 2, "A": 7, "Z": 0}


# ----------------------------------------------------------------------------------------------- numbers
def fmt(d):
    """Minimal decimal syntax of an exact Decimal: 0.50 -> .5, -0.5 -> -.5, 27.00 -> 27."""
    if d == 0:
        return "0"
    s = format(d.normalize(), "f")
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    if s.startswith("0."):
        s = s[1:]
    elif s.startswith("-0."):
        s = "-" + s[2:]
    return s


def fmt_str(s):
    return fmt(Decimal(s))


def join_nums(strs):
    out, prev = [], None
    for s in strs:
        if prev is not None and not (s[0] == "-" or (s[0] == "." and ("." in prev or "e" in prev))):
            out.append(" ")
        out.append(s)
        prev = s
    return "".join(out)


def short_colour(c):
    m = re.fullmatch(r"#([0-9a-fA-F]{6})", c)
    if not m:
        return c
    h = m.group(1).lower()
    if h[0] == h[1] and h[2] == h[3] and h[4] == h[5]:
        return "#" + h[0] + h[2] + h[4]
    return "#" + h


# ----------------------------------------------------------------------------------------------- path data
def parse_path(d):
    """-> list of (CMD, [Decimal, ...]) with ABSOLUTE coordinates; implicit repeats expanded."""
    toks = _TOKEN.findall(d)
    out, i, cmd = [], 0, None
    cx = cy = sx = sy = Decimal(0)
    while i < len(toks):
        t = toks[i]
        if t.isalpha():
            cmd = t
            i += 1
            if cmd in "Zz":
                out.append(("Z", []))
                cx, cy = sx, sy
                continue
        elif cmd is None:
            raise ValueError(f"path data does not start with a command: {d[:40]!r}")
        n = _ARGS[cmd.upper()]
        args = [Decimal(x) for x in toks[i:i + n]]
        if len(args) != n:
            raise ValueError(f"truncated path data near {' '.join(toks[i:i + 8])!r}")
        i += n
        rel, up = cmd.islower(), cmd.upper()
        if up == "H":
            x = args[0] + (cx if rel else 0)
            out.append(("L", [x, cy]))
            cx = x
        elif up == "V":
            y = args[0] + (cy if rel else 0)
            out.append(("L", [cx, y]))
            cy = y
        elif up == "A":
            x, y = args[5] + (cx if rel else 0), args[6] + (cy if rel else 0)
            out.append(("A", args[:5] + [x, y]))
            cx, cy = x, y
        else:
            pts = [(args[k] + (cx if rel else 0), args[k + 1] + (cy if rel else 0)) for k in range(0, n, 2)]
            out.append((up, [v for p in pts for v in p]))
            cx, cy = pts[-1]
            if up == "M":
                sx, sy = cx, cy
                cmd = "l" if rel else "L"          # implicit repeats after a moveto are linetos
    return out


def encode_path(segs):
    """Shortest exact encoding: per segment absolute or relative (whichever is shorter), H/V when axis-parallel,
    command letters omitted when implied."""
    parts, prev_letter, last_num = [], None, None
    cx = cy = sx = sy = Decimal(0)
    for cmd, a in segs:
        if cmd == "Z":
            parts.append("Z")
            prev_letter, last_num = "Z", None
            cx, cy = sx, sy
            continue
        if cmd == "L" and a[1] == cy:
            cands = [("H", [a[0]]), ("h", [a[0] - cx])]
        elif cmd == "L" and a[0] == cx:
            cands = [("V", [a[1]]), ("v", [a[1] - cy])]
        elif cmd == "A":
            cands = [("A", a), ("a", a[:5] + [a[5] - cx, a[6] - cy])]
        else:
            rel = [v - (cx if k % 2 == 0 else cy) for k, v in enumerate(a)]
            cands = [(cmd, a), (cmd.lower(), rel)]
        implied = {"M": "L", "m": "l"}.get(prev_letter, prev_letter)
        best = None
        for letter, vals in cands:
            nums = [fmt(v) for v in vals]
            body = join_nums(nums)
            if letter == implied and last_num is not None:
                tight = body[0] == "-" or (body[0] == "." and ("." in last_num or "e" in last_num))
                s = body if tight else " " + body
            else:
                s = letter + body
            if best is None or len(s) < len(best[0]):
                best = (s, letter, nums[-1])
        parts.append(best[0])
        prev_letter, last_num = best[1], best[2]
        if cmd == "A":
            cx, cy = a[5], a[6]
        else:
            cx, cy = a[-2], a[-1]
        if cmd == "M":
            sx, sy = cx, cy
    return "".join(parts)


def _dp(pts, tol):
    """Douglas-Peucker on an open polyline of float points (endpoints kept)."""
    keep = [False] * len(pts)
    keep[0] = keep[-1] = True
    stack = [(0, len(pts) - 1)]
    while stack:
        i, j = stack.pop()
        (x0, y0), (x1, y1) = pts[i], pts[j]
        dx, dy = x1 - x0, y1 - y0
        L = (dx * dx + dy * dy) ** 0.5
        best, bk = -1.0, -1
        for k in range(i + 1, j):
            px, py = pts[k]
            dist = abs(dy * (px - x0) - dx * (py - y0)) / L if L > 0 else ((px - x0) ** 2 + (py - y0) ** 2) ** 0.5
            if dist > best:
                best, bk = dist, k
        if bk >= 0 and best > tol:
            keep[bk] = True
            stack += [(i, bk), (bk, j)]
    return [k for k, v in enumerate(keep) if v]


def simplify_polylines(segs, tol):
    """Douglas-Peucker per closed polyline subpath (M L... Z). Returns None if the path has any curve/arc."""
    if any(c not in ("M", "L", "Z") for c, _ in segs):
        return None
    out, ring = [], []

    def flush(closed):
        if not ring:
            return
        if closed and len(ring) >= 4:
            pts = [(float(x), float(y)) for x, y in ring]
            far = max(range(len(pts)), key=lambda k: (pts[k][0] - pts[0][0]) ** 2 + (pts[k][1] - pts[0][1]) ** 2)
            a = _dp(pts[:far + 1], tol)
            b = [far + k for k in _dp(pts[far:] + [pts[0]], tol)][1:-1]
            idx = a + b
            if len(idx) < 3:
                idx = list(range(len(ring)))
            kept = [ring[k] for k in idx]
        else:
            kept = list(ring)
        out.append(("M", list(kept[0])))
        out.extend(("L", list(p)) for p in kept[1:])
        if closed:
            out.append(("Z", []))

    for c, a in segs:
        if c == "M":
            flush(False)
            ring = [(a[0], a[1])]
        elif c == "L":
            ring.append((a[0], a[1]))
        else:
            flush(True)
            ring = []
    flush(False)
    return out


# ----------------------------------------------------------------------------------------------- tree helpers
def _local(tag):
    return tag.split("}", 1)[-1]


def _serialise(el):
    attrs = "".join(f' {k}="{_esc(v)}"' for k, v in el.attrib.items())
    kids = "".join(_serialise(c) for c in el)
    if kids:
        return f"<{el.tag}{attrs}>{kids}</{el.tag}>"
    return f"<{el.tag}{attrs}/>"


def _esc(v):
    return v.replace("&", "&amp;").replace("<", "&lt;").replace('"', "&quot;")


def _canon(el):
    """Content key of a def without its own id (to merge identical defs)."""
    a = {k: v for k, v in el.attrib.items() if k != "id"}
    return el.tag + repr(sorted(a.items())) + "".join(_serialise(c) for c in el)


def _refs(el):
    out = []
    for k, v in el.attrib.items():
        out += re.findall(r"url\(#([^)]+)\)", v)
        if k == "href" and v.startswith("#"):
            out.append(v[1:])
    return out


def _rewrite_refs(root, mapping):
    for el in root.iter():
        for k, v in list(el.attrib.items()):
            if "url(#" in v:
                el.set(k, re.sub(r"url\(#([^)]+)\)", lambda m: f"url(#{mapping.get(m.group(1), m.group(1))})", v))
            elif k == "href" and v.startswith("#"):
                el.set(k, "#" + mapping.get(v[1:], v[1:]))


def _transform(v):
    def nums(m):
        return m.group(1) + "(" + join_nums([fmt_str(x) for x in re.split(r"[\s,]+", m.group(2).strip())]) + ")"
    return re.sub(r"(\w+)\s*\(([^)]*)\)", nums, v).replace(") ", ")")


def _b36(n):
    s = ""
    while True:
        n, r = divmod(n, 36)
        s = "0123456789abcdefghijklmnopqrstuvwxyz"[r] + s
        if n == 0:
            return s


# ----------------------------------------------------------------------------------------------- optimise
def optimise(svg_text, id_prefix):
    root = ET.fromstring(svg_text)
    for el in root.iter():
        el.tag = _local(el.tag)
    if root.tag != "svg":
        raise ValueError("not an SVG document")
    parent = {c: p for p in root.iter() for c in p}

    # 1. hoist every <defs> child into one <defs> at the top (document order)
    defs_children = []
    for d in [e for e in root.iter() if e.tag == "defs"]:
        defs_children.extend(list(d))
        parent[d].remove(d)
    defs = ET.Element("defs")
    for c in defs_children:
        defs.append(c)
    root.insert(0, defs)
    parent = {c: p for p in root.iter() for c in p}

    # 2. attribute cleanup: classes, numbers, colours, transforms, defaults
    for el in root.iter():
        el.attrib.pop("class", None)
        for k, v in list(el.attrib.items()):
            if k in NUMERIC_ATTRS:
                v = fmt_str(v)
                if k in DEFAULT_ONE and v == "1":
                    del el.attrib[k]
                    continue
            elif k in COLOUR_ATTRS:
                v = short_colour(v)
            elif k == "transform":
                v = _transform(v)
            el.set(k, v)
        if el.tag == "path" and parent.get(el) is not None and parent[el].tag == "clipPath":
            el.attrib.pop("fill-rule", None)        # clipping uses clip-rule; fill-rule is a no-op there

    # 3. path data: exact re-encoding; blurred paths simplified below the blur's resolution
    blur = {}
    for f in defs.iter("filter"):
        g = [c for c in f if c.tag == "feGaussianBlur"]
        if len(g) == 1 and len(f) == 1:
            blur[f.get("id")] = float(g[0].get("stdDeviation"))
    for el in root.iter():
        if "d" not in el.attrib:
            continue
        segs = parse_path(el.get("d"))
        fid = re.findall(r"url\(#([^)]+)\)", el.get("filter", ""))
        if fid and fid[0] in blur:
            simp = simplify_polylines(segs, BLUR_TOL * blur[fid[0]])
            if simp is not None:
                segs = simp
        el.set("d", encode_path(segs))

    # 4. merge identical defs (gradients, filters, clip paths) - repeat until stable
    while True:
        seen, mapping = {}, {}
        for c in list(defs):
            if c.get("id") is None:
                continue
            key = _canon(c)
            if key in seen:
                mapping[c.get("id")] = seen[key]
                defs.remove(c)
            else:
                seen[key] = c.get("id")
        if not mapping:
            break
        _rewrite_refs(root, mapping)

    # 5. path data used more than once -> one <path> in <defs> + <use> (inherits fill/stroke from the <use>)
    counts = {}
    for el in root.iter("path"):
        counts[el.get("d")] = counts.get(el.get("d"), 0) + 1
    shared = {}
    parent = {c: p for p in root.iter() for c in p}
    for el in list(root.iter("path")):
        d = el.get("d")
        if counts[d] < 2 or len(d) < SHARE_MIN_LEN or parent[el] is defs:
            continue
        if d not in shared:
            pid = f"__shared{len(shared)}"
            p = ET.Element("path", {"id": pid, "d": d})
            defs.append(p)
            shared[d] = pid
        use = ET.Element("use", {"href": "#" + shared[d]})
        for k, v in el.attrib.items():
            if k != "d":
                use.set(k, v)
        par = parent[el]
        idx = list(par).index(el)
        par.remove(el)
        par.insert(idx, use)
        parent[use] = par

    # 6. ids: drop unreferenced ones, rename the rest to short per-file ids (in document order)
    used = set()
    for el in root.iter():
        used.update(_refs(el))
    mapping, n = {}, 0
    for el in root.iter():
        i = el.get("id")
        if i is None:
            continue
        if i not in used:
            del el.attrib["id"]
            continue
        mapping[i] = id_prefix + _b36(n)
        n += 1
        el.set("id", mapping[i])
    _rewrite_refs(root, mapping)

    # 7. drop empty groups / defs
    changed = True
    while changed:
        changed = False
        parent = {c: p for p in root.iter() for c in p}
        for el in list(root.iter()):
            if el.tag in ("g", "defs") and len(el) == 0 and el is not root:
                parent[el].remove(el)
                changed = True

    root.attrib = {"xmlns": SVG_NS, **{k: root.attrib[k] for k in ("width", "height", "viewBox") if k in root.attrib}}
    return _serialise(root) + "\n"
