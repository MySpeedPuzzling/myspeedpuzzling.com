"""Holo medal data for the achievement detail sheet (docs/features/xp-levels/ux-polish/design-spec.md §2).

The sheet shows the medal at 200 px and turns it over to its back on a tap; Platinum (t4) gets a foil rim and Diamond
(t5) the full "Masterpiece" finish (assets/holo_medal/). Everything the effect needs to know about a picture is worked
out here, once, at build time - the browser does no image analysis (no canvas, no getImageData, no CORS question).
Per tiered badge, from the shipped SVG rendered at HOLO_PX (2x of the 200 px display) - the masks for t4/t5 only:

  <stem>-foil.png   alpha = where the foil may shine: the light fills of the frame band in full, inside it only the
                    light, unsaturated areas - the navy ink stays ink and a coloured icon keeps its colour
  <stem>-rim.png    alpha = the foil limited to the frame band (the t4 "foil rim")
  back-<hash>.png   the medal's back plate: the badge body (largest shape, holes filled) opened by OPEN_FRACTION of
                    the canvas so thin rays and tips do not make a lumpy coin, mirrored like a turned coin, painted
                    in a wash of the rim tone with an outline in the edge tone and an engraved groove. Its alpha is
                    also the coin's silhouette for the edge between the faces. Named by its content: the back
                    depends on the frame only, so every badge of a tier shares one file (one download, one cache entry)

and two tones sampled from the art (manifest "holo"): `rim` (the frame band's fills) and `edge` (the outline).
`field` is where the back's text goes: centre x/y and radius, in percent of the picture.

Deterministic: numpy + scipy.ndimage on the rsvg render, Pillow's lossless PNG - the golden check in qa.py rebuilds
these byte for byte like the rest of the art. Written for MySpeedPuzzling; no third-party effect code.
"""
import hashlib
import io

import numpy as np
from PIL import Image
from scipy import ndimage

HOLO_PX = 400                 # 2x of the sheet's 200 px medal
HOLO_TIERS = ("t4", "t5")     # the finishes with a foil: t4 foil rim, t5 Masterpiece; t1-t3 stay still (back only)
MEDAL_TIERS = ("t1", "t2", "t3", "t4", "t5")   # every tier turns over: they all get a back
OPEN_FRACTION = 0.04          # back silhouette: morphological opening radius, share of the canvas
RIM_BAND = (0.20, 0.32)       # frame band: full foil up to 20 % of the inradius from the edge, fading out by 32 %
WHITE = np.array([255.0, 255.0, 255.0])
NAVY = np.array([43.0, 52.0, 69.0])


def smoothstep(e0, e1, x):
    t = np.clip((x - e0) / (e1 - e0), 0.0, 1.0)
    return t * t * (3.0 - 2.0 * t)


def _to_linear(c):
    c = c / 255.0
    return np.where(c <= 0.04045, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)


def _to_srgb(c):
    c = np.where(c <= 0.0031308, c * 12.92, 1.055 * np.power(np.maximum(c, 0.0), 1 / 2.4) - 0.055)
    return np.clip(np.round(c * 255.0), 0, 255)


def _hex(rgb):
    return "#%02x%02x%02x" % tuple(int(v) for v in rgb)


def _luminance(rgb):
    lin = _to_linear(np.asarray(rgb, dtype=np.float64))
    return float(0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2])


def _body(alpha):
    """The badge body: the largest 4-connected shape of alpha > 0.5 (loose dots drop out), holes filled."""
    labels, count = ndimage.label(alpha > 0.5)
    if count == 0:
        raise ValueError("holo: the picture is empty")
    sizes = np.bincount(labels.ravel())
    sizes[0] = 0
    return ndimage.binary_fill_holes(labels == int(np.argmax(sizes)))


def _mask_png(alpha):
    """A mask file: white with the given alpha (0..1); CSS masks read the alpha channel."""
    a = np.clip(np.round(alpha * 255.0), 0, 255).astype(np.uint8)
    im = Image.fromarray(np.dstack([np.full_like(a, 255), a]), "LA")
    buf = io.BytesIO()
    im.save(buf, "PNG", optimize=True)
    return buf.getvalue()


def _rgba_png(rgb, alpha):
    a = np.clip(np.round(alpha * 255.0), 0, 255).astype(np.uint8)
    c = np.clip(np.round(rgb), 0, 255).astype(np.uint8)
    c[a == 0] = 0                                             # no colour noise under transparent pixels
    buf = io.BytesIO()
    Image.fromarray(np.dstack([c, a]), "RGBA").save(buf, "PNG", optimize=True)
    return buf.getvalue()


def _mix(a, b, t):
    t = np.asarray(t)[..., None] if np.ndim(t) else t
    return a + (b - a) * t


def analyse(rgba):
    """rgba: HxWx4 uint8 render of the badge. -> dict of the masks (float arrays 0..1), the plate and the tones."""
    px = rgba.astype(np.float64)
    size = px.shape[0]
    alpha = px[..., 3] / 255.0
    rgb = px[..., :3]
    luma = (0.2126 * rgb[..., 0] + 0.7152 * rgb[..., 1] + 0.0722 * rgb[..., 2]) / 255.0
    chroma = (rgb.max(-1) - rgb.min(-1)) / 255.0

    body = _body(alpha)
    dist = ndimage.distance_transform_edt(body)
    inradius = max(float(dist.max()), 1.0)

    # Foil: a fill, not ink (navy outlines stay ink); the frame band takes it fully, inside it only light neutral areas
    fill = smoothstep(0.30, 0.50, luma)
    band = np.where(body, 1.0 - smoothstep(RIM_BAND[0] * inradius, RIM_BAND[1] * inradius, dist), 0.0)
    interior = 0.9 * (1.0 - 0.85 * smoothstep(0.22, 0.45, chroma))
    foil = alpha * fill * (band + (1.0 - band) * interior)
    rim = alpha * fill * band

    # Tones in linear light: the frame band's light fills, and the outermost outline
    weight = alpha * body
    rim_sel = (dist > 0.06 * inradius) & (dist < 0.24 * inradius) & (fill > 0.5)
    edge_sel = body & (dist < 0.035 * inradius)
    lin = _to_linear(rgb)

    def tone(sel, fallback):
        w = weight * sel
        if w.sum() <= 0:
            return np.array(fallback, dtype=np.float64)
        return _to_srgb((lin * w[..., None]).sum((0, 1)) / w.sum())

    rim_tone = tone(rim_sel, (200, 210, 230))
    edge_tone = tone(edge_sel, NAVY)

    # Back silhouette: the body opened by r (erode, then dilate): shapes narrower than 2r go, wider ones come back
    # exactly, and the back never leaves the front's silhouette. Anti-aliased by the distance to the eroded core.
    r_open = OPEN_FRACTION * size
    core = dist > r_open
    to_core = ndimage.distance_transform_edt(~core)
    body_alpha = np.where(body & (dist > 2.5), 1.0, np.where(body, np.maximum(alpha, 0.0), 0.0))
    back_alpha = body_alpha * smoothstep(r_open + 0.8, r_open - 0.8, to_core)
    back_alpha = back_alpha[:, ::-1]                          # a turned coin shows its silhouette mirrored
    back_in = back_alpha > 0.5
    back_dist = ndimage.distance_transform_edt(back_in)
    back_r = max(float(back_dist.max()), 1.0)
    cy, cx = np.unravel_index(int(np.argmax(back_dist)), back_dist.shape)

    plate = _plate(back_dist, back_r, (cx, cy), rim_tone, edge_tone)
    groove = 0.17 * back_r

    return {
        "foil": foil,
        "rim": rim,
        "back_rgb": plate,
        "back_alpha": back_alpha,
        "rim_tone": rim_tone,
        "edge_tone": edge_tone,
        "field": [round(float(cx) / size * 100, 2), round(float(cy) / size * 100, 2),
                  round((back_r - groove) / size * 100, 2)],
    }


def _plate(dist, radius, centre, rim_tone, edge_tone):
    """The back plate's colour: a wash of the rim tone (light top-left, deeper bottom-right, as the house light),
    an outline in the edge tone (navy for outlined art), an engraved groove with a light line inside it and a soft
    highlight of turned metal. Smooth on purpose: it stays a small PNG and the text on it stays readable."""
    size = dist.shape[0]
    yy, xx = np.mgrid[0:size, 0:size].astype(np.float64)
    u = (xx - centre[0]) / radius
    v = (yy - centre[1]) / radius
    rr = np.sqrt(u * u + v * v)

    light = _mix(rim_tone, WHITE, 0.72)
    base = _mix(rim_tone, WHITE, 0.30)
    deep = _mix(rim_tone, edge_tone, 0.22)
    outline = edge_tone if _luminance(edge_tone) < 0.08 else _mix(rim_tone, edge_tone, 0.65)

    t = np.clip((u * 0.55 + v * 0.83 + 1.05) / 2.1, 0.0, 1.0)
    c = _mix(light, base, smoothstep(0.05, 0.55, t))
    c = _mix(c, deep, smoothstep(0.62, 1.0, t) * 0.7)

    groove_at, groove_w = 0.17 * radius, 0.022 * radius
    inside = dist > groove_at + groove_w
    with np.errstate(invalid="ignore", divide="ignore"):
        cos2 = np.where(rr > 1e-6, ((u - v) ** 2) / (2.0 * rr * rr), 0.0)
    bow = cos2 ** 3 * 0.2 * smoothstep(0.1, 0.6, rr) * inside
    c = _mix(c, WHITE, bow)

    g = dist - groove_at
    in_groove = smoothstep(-1.2, 0.0, g) * (1.0 - smoothstep(groove_w, groove_w + 1.2, g))
    c = _mix(c, outline, in_groove * 0.42)
    h = dist - (groove_at + groove_w + 1.5)
    c = _mix(c, WHITE, np.clip(1.0 - np.abs(h - 0.75) / 1.75, 0.0, 1.0) * 0.5)

    out_w = 0.062 * radius
    c = _mix(outline, c, smoothstep(out_w - 0.8, out_w + 0.8, dist))
    return c


def files_for(stem, rgba):
    """The medal files of one tiered badge + its manifest entry: the back for every tier, the foil and rim masks for
    t4/t5 (null otherwise). rgba: the HOLO_PX render of the shipped SVG."""
    a = analyse(rgba)
    back = _rgba_png(a["back_rgb"], a["back_alpha"])
    back_name = f"holo/back-{hashlib.sha256(back).hexdigest()[:10]}.png"
    files = {back_name: back}
    foil = rim = None
    if stem.rsplit("-", 1)[1] in HOLO_TIERS:
        foil, rim = f"holo/{stem}-foil.png", f"holo/{stem}-rim.png"
        files[foil] = _mask_png(a["foil"])
        files[rim] = _mask_png(a["rim"])
    entry = {
        "foil": foil,
        "rim": rim,
        "back": back_name,
        "tones": {"rim": _hex(a["rim_tone"]), "edge": _hex(a["edge_tone"])},
        "field": a["field"],
    }
    return files, entry
