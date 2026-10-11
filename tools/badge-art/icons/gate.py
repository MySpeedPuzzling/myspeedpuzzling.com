"""Icon QA gate (ported from the badge lab's IK1 qa.py, 2026-10-10; qa.py runs it). Works on ANY icon SVG that follows
the contract (README "Icons"): viewBox 0 0 100 100, groups face / accent / outline (in this order), navy outline.
Every function takes SVG TEXT (no files, no temp files).

Every check renders the real SVG with rsvg-convert (the same renderer as the PNG exports) and measures pixels:

  centring    alpha bbox at 1024 px: centre offset |dx|,|dy| <= 0.5 units (0.5 % of the 100 box), longer side 80 +- 0.5
  symmetry    mirror IoU about x = 50 at 512 px: silhouette and navy ink, with class="asym" details removed
              (structural) and as shipped (full). Gate for symmetric objects: silhouette >= 0.98, ink >= 0.93
  outline     outline group rendered alone at 1024 px; skeleton + distance transform -> line width along the OUTER
              outline (skeleton within SW/2 + 1 unit of the silhouette edge): median 4.5 +- 0.3 units, p10 >= 3.9;
              source: every stroke in #outline is navy, round caps/joins, width >= 0.55 x 4.5
  dashes      connected components of the alpha mask at 512 px: no detached component at all (a centred single
              object never has one); elongated detached bits are reported as "dash" (speed/motion lines)
  slivers     opaque light regions narrower than 1.8 units, >= 2 units long and elongated >= 2.5 (hairline gaps
              between near-parallel outlines, e.g. a neck shorter than the stroke or a tick ending 1 unit before its
              ring - they look like scratches at 192+ px): none allowed; tapering acute corners are not slivers
  legibility  rendered with the icon bbox = 24 px (100 box at 30 px) on white:
              ink coverage (L* < 40 inside the silhouette) 0.15-0.50 (above = a dark blob; one big simple shape
              such as the heart scores 0.16-0.18 and is crisp, faint line icons fail edge density too), edge density
              (|grad L*| > 22 L*/px inside the bbox) >= 0.18 (below = no readable inner structure), accent visibility
              (pixels within dE 22 of an accent colour, near-whites excluded) >= 0.10 (the tier tint shows at 24 px),
              and at 1024 px the thin-fill share (fill area thinner than 4 units = 1.2 px at 24 px: details that fuse
              into ink) <= 0.15 (catches clutter: a 12-tick + second-hand stopwatch scores 0.24, ours 0.06)
  tints       (multi-file, tint_integrity) face + outline groups byte-identical across colourways (only the accent
              layer changes), resolved accent colours distinct, accent mean colour dE76 >= 12 from the white face,
              consecutive tiers T1..T5 >= 10 mean per-pixel dE76 over the accent area (a multi-hue T5 prism has the
              same MEAN as a lavender T4, so the mean alone cannot judge it)
  self-test   selftest() builds negative controls (dashes, one pusher, off-centre, thin outline, clutter) from the
              speed icon and asserts that each one FAILS its gate
  contract    viewBox, the three tagged groups in order, no <text>/<image>/filter, outline navy only
"""
import io
import re
import subprocess

import numpy as np
from PIL import Image
from scipy import ndimage
from skimage.morphology import disk, skeletonize, opening

NAVY = "#2b3445"
SW = 4.5
FIT = 80.0

GATES = {
    "centre_off_max": 0.5, "long_side": (79.5, 80.5),
    "sym_sil_min": 0.98, "sym_ink_min": 0.93,
    "outline_median": (4.2, 4.8), "outline_p10_min": 3.9, "detail_min": 0.55 * SW,
    "detached_max": 0,
    "ink24": (0.15, 0.50), "edge24_min": 0.18, "accent24_min": 0.10, "thinfill_max": 0.15,
    "tint_face_de_min": 12.0, "tint_step_de_min": 10.0,
    "sliver_width": 1.8, "sliver_len_min": 2.0, "sliver_elong_min": 2.5, "slivers_max": 0,
}


# ----------------------------------------------------------------------------------------------- helpers
def render(svg_text, px):
    r = subprocess.run(["rsvg-convert", "-w", str(px), "-h", str(px)], input=svg_text.encode(),
                       capture_output=True, check=True)
    return np.asarray(Image.open(io.BytesIO(r.stdout)).convert("RGBA")).astype(np.float64) / 255.0


def strip_group(svg, gid):
    return re.sub(r'<g id="%s"[^>]*>.*?</g>\n?' % re.escape(gid), "", svg, flags=re.S)


def strip_asym(svg):
    return re.sub(r'<(path|circle|ellipse|rect|polygon|polyline|line)\b[^>]*class="asym"[^>]*/>\n?', "", svg)


def hex_rgb(c):
    c = c.lstrip("#")
    if len(c) == 3:
        c = "".join(ch * 2 for ch in c)
    return np.array([int(c[i:i + 2], 16) for i in (0, 2, 4)], float) / 255.0


def to_lab(rgb):
    rgb = np.asarray(rgb, float)
    lin = np.where(rgb <= 0.04045, rgb / 12.92, ((rgb + 0.055) / 1.055) ** 2.4)
    m = np.array([[0.4124, 0.3576, 0.1805], [0.2126, 0.7152, 0.0722], [0.0193, 0.1192, 0.9505]])
    xyz = lin @ m.T
    xyz = xyz / np.array([0.95047, 1.0, 1.08883])
    f = np.where(xyz > 0.008856, np.cbrt(xyz), 7.787 * xyz + 16 / 116)
    L = 116 * f[..., 1] - 16
    a = 500 * (f[..., 0] - f[..., 1])
    b = 200 * (f[..., 1] - f[..., 2])
    return np.stack([L, a, b], -1)


def on_white(rgba, bg=(1.0, 1.0, 1.0)):
    a = rgba[..., 3:4]
    return rgba[..., :3] * a + np.array(bg) * (1 - a)


def mirror_iou(mask):
    m2 = mask[:, ::-1]
    inter = np.logical_and(mask, m2).sum()
    union = np.logical_or(mask, m2).sum()
    return float(inter / max(union, 1))


def accent_colours(svg):
    """Stop colours of every gradient referenced inside the accent group + solid accent fills/strokes (not white)."""
    m = re.search(r'<g id="accent"[^>]*>(.*?)</g>', svg, re.S)
    if not m:
        return []
    body = m.group(1)
    cols = set()
    for gid in re.findall(r'url\(#([^)]+)\)', body):
        g = re.search(r'<(?:linear|radial)Gradient id="%s"[^>]*>(.*?)</(?:linear|radial)Gradient>' % re.escape(gid), svg,
                      re.S)
        if g:
            cols.update(re.findall(r'stop-color="(#[0-9a-fA-F]{6})"', g.group(1)))
    cols.update(re.findall(r'fill="(#[0-9a-fA-F]{6})"', body))
    return [c for c in cols if to_lab(hex_rgb(c))[0] <= 90.0]


# ----------------------------------------------------------------------------------------------- checks
def contract(svg):
    issues = []
    if 'viewBox="0 0 100 100"' not in svg:
        issues.append("viewBox is not 0 0 100 100")
    order = [m.group(1) for m in re.finditer(r'<g id="(face|accent|outline)"', svg)]
    if order != ["face", "accent", "outline"]:
        issues.append(f"tagged groups {order} != face, accent, outline")
    if re.search(r"<text|<image|<filter|filter=", svg):
        issues.append("text / image / filter present")
    og = re.search(r'<g id="outline"([^>]*)>(.*?)</g>', svg, re.S)
    widths = []
    if og:
        attrs, body = og.group(1), og.group(2)
        for need in (f'stroke="{NAVY}"', 'stroke-linecap="round"', 'stroke-linejoin="round"', f'stroke-width="{SW:g}"'):
            if need not in attrs:
                issues.append(f"#outline lacks {need}")
        for c in re.findall(r'stroke="(#[0-9a-fA-F]{6})"', body):
            if c.lower() != NAVY:
                issues.append(f"non-navy stroke {c} in #outline")
        for c in re.findall(r'fill="(#[0-9a-fA-F]{6})"', body):
            if c.lower() != NAVY:
                issues.append(f"non-navy fill {c} in #outline")
        widths = [float(w) for w in re.findall(r'stroke-width="([0-9.]+)"', body)]
        for w in widths:
            if w < GATES["detail_min"] - 1e-9:
                issues.append(f"detail stroke {w} < {GATES['detail_min']:.2f}")
    return issues, sorted(set([SW] + widths))


def centring(svg):
    a = render(svg, 1024)[..., 3] >= 0.5
    ys, xs = np.nonzero(a)
    k = 1024 / 100.0
    x0, x1, y0, y1 = xs.min() / k, (xs.max() + 1) / k, ys.min() / k, (ys.max() + 1) / k
    return {"dx": round((x0 + x1) / 2 - 50, 3), "dy": round((y0 + y1) / 2 - 50, 3),
            "w": round(x1 - x0, 2), "h": round(y1 - y0, 2), "long": round(max(x1 - x0, y1 - y0), 2)}


def symmetry(svg):
    out = {}
    for tag, s in (("full", svg), ("struct", strip_asym(svg))):
        img = render(s, 512)
        sil = img[..., 3] >= 0.5
        rgb = on_white(img)
        ink = sil & (np.linalg.norm(rgb - hex_rgb(NAVY), axis=-1) < 0.16)
        out[f"sil_{tag}"] = round(mirror_iou(sil), 4)
        out[f"ink_{tag}"] = round(mirror_iou(ink), 4)
    return out


def outline_width(svg):
    k = 1024 / 100.0
    full = render(svg, 1024)[..., 3] >= 0.5
    lines = strip_group(strip_group(svg, "face"), "accent")
    ink = render(lines, 1024)[..., 3] >= 0.5
    skel = skeletonize(ink)
    edt = ndimage.distance_transform_edt(ink)
    d_edge = ndimage.distance_transform_edt(full)
    w_all = (2 * edt[skel] - 1) / k
    outer_sel = skel & (d_edge <= (SW / 2 + 1.0) * k)
    w_out = (2 * edt[outer_sel] - 1) / k
    return {"outer_median": round(float(np.median(w_out)), 3), "outer_p10": round(float(np.percentile(w_out, 10)), 3),
            "outer_p90": round(float(np.percentile(w_out, 90)), 3), "all_median": round(float(np.median(w_all)), 3),
            "n_outer_px": int(outer_sel.sum())}


def dashes(svg):
    img = render(svg, 512)
    a = img[..., 3] >= 0.25
    lab, n = ndimage.label(a, structure=np.ones((3, 3)))
    if n == 0:
        return {"components": 0, "detached": 0, "dashes": 0, "items": []}
    sizes = ndimage.sum(a, lab, range(1, n + 1))
    main = int(np.argmax(sizes)) + 1
    items = []
    for i in range(1, n + 1):
        if i == main:
            continue
        ys, xs = np.nonzero(lab == i)
        area = len(xs) / sizes[main - 1]
        if len(xs) >= 3:
            cov = np.cov(np.stack([xs, ys]))
            ev = np.sort(np.linalg.eigvalsh(cov))
            elong = float(np.sqrt(ev[1] / max(ev[0], 1e-6)))
        else:
            elong = 1.0
        items.append({"area_frac": round(float(area), 5), "elongation": round(elong, 2),
                      "centre": [round(float(xs.mean()) / 5.12, 1), round(float(ys.mean()) / 5.12, 1)],
                      "dash": bool(elong >= 2.5)})
    return {"components": int(n), "detached": len(items), "dashes": sum(1 for it in items if it["dash"]),
            "items": items}


def legibility(svg):
    out = {}
    # 24 px: the 80-unit bbox = 24 px -> 100 box at 30 px
    img = render(svg, 30)
    sil = img[..., 3] >= 0.5
    rgb = on_white(img)
    L = to_lab(rgb)
    out["ink24"] = round(float((L[..., 0] < 40)[sil].mean()), 3)
    gx = ndimage.sobel(L[..., 0], axis=1) / 8.0
    gy = ndimage.sobel(L[..., 0], axis=0) / 8.0
    mag = np.hypot(gx, gy)
    ys, xs = np.nonzero(img[..., 3] > 0.02)
    box = (slice(ys.min(), ys.max() + 1), slice(xs.min(), xs.max() + 1))
    out["edge24"] = round(float((mag[box] > 22).mean()), 3)
    acc = accent_colours(svg)
    if acc:
        al = to_lab(np.array([hex_rgb(c) for c in acc]))
        d = np.min(np.linalg.norm(L[sil][:, None, :] - al[None, :, :], axis=-1), axis=1)
        out["accent24"] = round(float((d < 22).mean()), 3)
    else:
        out["accent24"] = None
    # thin fill share at 1024 px: fills narrower than 4 units vanish into ink at 24 px
    big = render(svg, 1024)
    silb = big[..., 3] >= 0.5
    Lb = to_lab(on_white(big))[..., 0]
    fill = silb & (Lb >= 45)
    rad = int(round(2.0 * 10.24))
    opened = opening(fill, disk(rad))
    out["thinfill"] = round(float(1 - opened.sum() / max(fill.sum(), 1)), 3)
    out["sil_area24"] = round(float(sil.mean()), 3)
    return out


def slivers(svg, px=1024):
    """Hairline artefacts: fully opaque light (fill) regions narrower than sliver_width units that are long and
    elongated - the gap left between two near-parallel outlines (e.g. a neck shorter than the stroke, a tick ending
    1 unit before a ring). Tapering acute corners are short and not elongated, so they are not slivers."""
    k = px / 100.0
    big = render(svg, px)
    solid = big[..., 3] >= 0.98
    L = to_lab(on_white(big))[..., 0]
    fill = solid & (L >= 45)
    opened = opening(fill, disk(max(1, int(round(GATES["sliver_width"] / 2 * k)))))
    thin = fill & ~opened
    lab, n = ndimage.label(thin, structure=np.ones((3, 3)))
    found = []
    for i in range(1, n + 1):
        ys, xs = np.nonzero(lab == i)
        if len(xs) < 6:
            continue
        ev = np.sort(np.linalg.eigvalsh(np.cov(np.stack([xs, ys]))))
        elong = float(np.sqrt(ev[1] / max(ev[0], 1e-6)))
        length = float(4 * np.sqrt(ev[1])) / k
        if length >= GATES["sliver_len_min"] and elong >= GATES["sliver_elong_min"]:
            found.append({"at": [round(float(xs.mean()) / k, 1), round(float(ys.mean()) / k, 1)],
                          "length": round(length, 1), "area": round(len(xs) / k / k, 2), "elongation": round(elong, 1)})
    return found


def check(svg, symmetric=True, label=""):
    res = {"file": label}
    res["contract_issues"], res["stroke_widths"] = contract(svg)
    res["centring"] = centring(svg)
    res["symmetry"] = symmetry(svg)
    res["outline"] = outline_width(svg)
    res["dashes"] = dashes(svg)
    res["legibility"] = legibility(svg)
    res["slivers"] = slivers(svg)
    g, c, s, o, d, lg = GATES, res["centring"], res["symmetry"], res["outline"], res["dashes"], res["legibility"]
    gates = {
        "contract": not res["contract_issues"],
        "centred": abs(c["dx"]) <= g["centre_off_max"] and abs(c["dy"]) <= g["centre_off_max"],
        "long_side_80": g["long_side"][0] <= c["long"] <= g["long_side"][1],
        "symmetric": (not symmetric) or (s["sil_struct"] >= g["sym_sil_min"] and s["ink_struct"] >= g["sym_ink_min"]),
        "outline_width": g["outline_median"][0] <= o["outer_median"] <= g["outline_median"][1]
                         and o["outer_p10"] >= g["outline_p10_min"],
        "no_dashes": d["detached"] <= g["detached_max"] and d["dashes"] == 0,
        "no_slivers": len(res["slivers"]) <= g["slivers_max"],
        "legible_24": g["ink24"][0] <= lg["ink24"] <= g["ink24"][1] and lg["edge24"] >= g["edge24_min"]
                      and (lg["accent24"] is None or lg["accent24"] >= g["accent24_min"])
                      and lg["thinfill"] <= g["thinfill_max"],
    }
    res["gates"] = gates
    res["pass"] = all(gates.values())
    return res


def _norm_layer(svg, gid):
    m = re.search(r'<g id="%s"[^>]*>(.*?)</g>' % gid, svg, re.S)
    body = m.group(1) if m else ""
    return re.sub(r'(url\(#|id=")[a-z0-9-]+?-(msp|t[1-5])-', r'\1', body)


def accent_render(svg, px=256):
    img = render(strip_group(strip_group(svg, "face"), "outline"), px)
    return img


def accent_signature(svg):
    """Accent group with the colours of every gradient it references resolved (what a tier actually changes)."""
    m = re.search(r'<g id="accent"[^>]*>(.*?)</g>', svg, re.S)
    body = m.group(1) if m else ""
    stops = []
    for gid in re.findall(r'url\(#([^)]+)\)', body):
        g = re.search(r'<(?:linear|radial)Gradient id="%s"[^>]*>(.*?)</(?:linear|radial)Gradient>' % re.escape(gid),
                      svg, re.S)
        stops.append(g.group(1) if g else gid)
    return re.sub(r'(url\(#|id=")[a-z0-9-]+?-(msp|t[1-5])-', r'\1', body) + "|" + "|".join(stops)


def tint_integrity(items):
    """items: ordered (label, svg text) colourways of ONE icon (msp, t1..t5). Only the accent layer may differ; the
    accent must show against the white face; consecutive tiers T1..T5 must differ per pixel (mean dE76 over the
    accent area)."""
    files = [label for label, _ in items]
    svgs = [text for _, text in items]
    issues = []
    for gid in ("face", "outline"):
        ref = _norm_layer(svgs[0], gid)
        for f, s in zip(files[1:], svgs[1:]):
            if _norm_layer(s, gid) != ref:
                issues.append(f"#{gid} differs in {f.split('/')[-1]}")
    if len({accent_signature(s) for s in svgs}) != len(svgs):
        issues.append("two colourways have the same accent colours")
    face = to_lab(hex_rgb("#f1f4fb"))
    imgs = [accent_render(s) for s in svgs]
    labs = [to_lab(im[..., :3]) for im in imgs]
    masks = [im[..., 3] >= 0.9 for im in imgs]
    means = [lab[m].mean(axis=0) for lab, m in zip(labs, masks)]
    de_face = [round(float(np.linalg.norm(mu - face)), 1) for mu in means]
    de_step = []
    for i in range(1, len(svgs) - 1):              # t1->t2 ... t4->t5 (index 0 = msp)
        m = masks[i] & masks[i + 1]
        de_step.append(round(float(np.linalg.norm(labs[i + 1][m] - labs[i][m], axis=-1).mean()), 1))
    ok = (not issues and min(de_face) >= GATES["tint_face_de_min"]
          and (not de_step or min(de_step) >= GATES["tint_step_de_min"]))
    return {"issues": issues, "accent_dE_vs_face": de_face, "accent_dE_tier_steps": de_step,
            "accent_mean_lab": [[round(float(v), 1) for v in mu] for mu in means], "pass": bool(ok)}


def selftest(base):
    """Negative controls built from a passing icon (svg text): each must FAIL the named gate (else the gate is
    useless)."""
    navy = f'stroke="{NAVY}" stroke-width="{SW:g}" stroke-linecap="round" fill="none"'
    controls = {
        "no_dashes": base.replace("</svg>", f'<path d="M3 40H11M1 50H9M4 60H10" {navy}/></svg>'),
        "centred": base.replace('<g id="face">', '<g transform="translate(2.2 0)"><g id="face">', 1)
                       .replace("</svg>", "</g></svg>"),
        "outline_width": re.sub(r'(<g id="outline"[^>]*?)stroke-width="4.5"', r'\1stroke-width="2.6"', base),
    }
    # hairline: two navy strokes 5.2 apart over the white dial leave a 0.7-unit light gap (a neck shorter than the
    # stroke does the same)
    controls["no_slivers"] = base.replace("</svg>", f'<path d="M38 62H62M38 67.2H62" {navy}/></svg>')
    # one-sided side button (the library stopwatches' flaw): an extra pill on the right only
    pill = (f'<path d="M86 44h4a3.5 3.5 0 0 1 0 7h-4a3.5 3.5 0 0 1 0-7z" fill="#fb8180" stroke="{NAVY}" '
            f'stroke-width="{SW:g}" stroke-linejoin="round"/>')
    controls["symmetric"] = base.replace("</svg>", pill + "</svg>")
    report = {}
    for gate, svg in controls.items():
        r = check(svg, symmetric=True, label=f"control-{gate}")
        report[gate] = {"caught": not r["gates"][gate], "gates_failed": [k for k, v in r["gates"].items() if not v]}
    # clutter control: 12 ticks + second hand + dot ring on the speed stopwatch
    from . import draw, illus
    from .illus import circle, clock, line
    ic = draw.speed()
    for a in range(0, 360, 30):
        if a % 90:
            ic.line(line(*clock(50.0, 56.0, 14.2, a), *clock(50.0, 56.0, 15.5, a)), width=2.6)
    for a in range(15, 360, 30):
        ic.ink(circle(*clock(50.0, 56.0, 25.4, a), 1.3))
    ic.line(line(50.0, 56.0, *clock(50.0, 56.0, 16.0, 200)), width=2.6)
    ic.line(circle(50.0, 56.0, 9.0), width=2.6)
    fi, _ = illus.fit(ic)
    r = check(illus.svg_text(fi, "msp"), symmetric=False, label="control-clutter")
    report["legible_24"] = {"caught": not r["gates"]["legible_24"], "thinfill": r["legibility"]["thinfill"],
                            "gates_failed": [k for k, v in r["gates"].items() if not v]}
    return report


def accent_area(svg):
    """VISIBLE accent share: pixels of the accent layer (what the tier tints) not covered by the navy outline layer,
    over the icon's silhouette, at 1024 px (alpha >= 0.5). Design rule (README "Icons"): >= 0.25. Reported as a
    WARNING with the measured value, not a gate (the binding check is accent visibility at 24 px)."""
    full = render(svg, 1024)[..., 3] >= 0.5
    acc = render(strip_group(strip_group(svg, "face"), "outline"), 1024)[..., 3] >= 0.5
    ink = render(strip_group(strip_group(svg, "face"), "accent"), 1024)[..., 3] >= 0.5
    return round(float((acc & ~ink & full).sum() / max(full.sum(), 1)), 3)


