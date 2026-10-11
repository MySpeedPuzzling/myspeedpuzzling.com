"""Badge composition: frozen base (under) + icon in the fixed slot + frozen base (over) -> one master SVG.

The icon placement reproduces the badge lab's BK1 `place_icon` / `fitted_size` exactly (the committed art is verified
pixel-identical to BK1 `render.py F1 <icon>`, README "Verification"):
- the icon's 100-unit viewBox maps so that its 80-unit bbox (IK1 contract) gets the slot size `size` (px);
- every stroke is rescaled so the icon outline (4.5 units) renders at the frame's outline weight (27 px);
- optical fit: the icon shrinks only if its farthest opaque point (alpha > 127, rendered by rsvg-convert at 1024 px)
  lies outside the slot's fit radius; up to 3 refinements. Boxy icons (camera, sofa, clipboard) shrink ~10-15 %.
"""
import io
import re
from dataclasses import dataclass

import numpy as np
from PIL import Image

from .raster import rsvg_png

CANVAS = 1024
SVG_HEAD = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{CANVAS}" height="{CANVAS}" '
            f'viewBox="0 0 {CANVAS} {CANVAS}">')
ICON_BOX_FIT = 0.80        # IK1 contract: the icon bbox longer side is 80 of its 100-unit viewBox
ICON_OUTLINE = 4.5         # IK1 contract: the icon outline weight in viewBox units (the widest stroke in a file)


class CompositionError(Exception):
    pass


@dataclass(frozen=True)
class Layer:
    defs: str
    body: str


_BASE_RE = re.compile(re.escape(SVG_HEAD) + r"<defs>(.*?)</defs>(.*)</svg>", re.S)


def parse_layer(text, where):
    """A frozen base layer file: optional leading comments, then SVG_HEAD<defs>..</defs>body</svg>."""
    body = re.sub(r"<!--.*?-->", "", text, flags=re.S).strip()
    m = _BASE_RE.fullmatch(body)
    if not m:
        raise CompositionError(f"{where}: not a frozen base layer (expected '{SVG_HEAD}<defs>...</defs>...</svg>')")
    return Layer(m.group(1), m.group(2))


def layer_text(svg, comment):
    """Serialise a frames.kit.Svg as a frozen base layer file."""
    return f"<!-- {comment} -->\n{SVG_HEAD}<defs>{''.join(svg.defs)}</defs>{''.join(svg.body)}</svg>\n"


# ----------------------------------------------------------------------------------------------- icon placement
def viewbox(raw):
    m = re.search(r'viewBox="([^"]+)"', raw)
    if not m:
        raise CompositionError("icon SVG without a viewBox")
    return [float(v) for v in m.group(1).replace(",", " ").split()]


def stroke_basis(raw):
    """The icon's widest stroke (BK1: the stroke that is rescaled to the frame outline). Must be the outline."""
    sw = [float(v) for v in re.findall(r'stroke-width="([0-9.]+)"', raw)]
    basis = max(sw) if sw else ICON_OUTLINE
    if abs(basis - ICON_OUTLINE) > 1e-9:
        raise CompositionError(f"the widest stroke is {basis:g}, not the outline weight {ICON_OUTLINE:g}: no stroke "
                               f"in an icon may be wider than its outline")
    return basis


def _inner(raw):
    inner = re.sub(r"^.*?<svg[^>]*>", "", raw, flags=re.S)
    return re.sub(r"</svg>\s*$", "", inner, flags=re.S)


def _scale_strokes(inner, f):
    return re.sub(r'stroke-width="([0-9.]+)"', lambda m: f'stroke-width="{float(m.group(1)) * f:.3f}"', inner)


def _namespace(inner, pref):
    inner = re.sub(r'id="([^"]+)"', lambda m: f'id="{pref}{m.group(1)}"', inner)
    inner = re.sub(r"url\(#([^)]+)\)", lambda m: f"url(#{pref}{m.group(1)})", inner)
    return re.sub(r'href="#([^"]+)"', lambda m: f'href="#{pref}{m.group(1)}"', inner)


def icon_group(raw, size, outline_px, cx=CANVAS / 2.0, cy=CANVAS / 2.0, prefix="i-"):
    """The icon as one <g transform>: bbox longer side = size px, centred on (cx, cy), outline = outline_px."""
    vx, vy, vw, vh = viewbox(raw)
    s = size / (max(vw, vh) * ICON_BOX_FIT)        # px per icon unit
    f = (outline_px / s) / stroke_basis(raw)       # rescale every stroke so the outline = outline_px
    tx, ty = cx - (vx + vw / 2) * s, cy - (vy + vh / 2) * s
    inner = _namespace(_scale_strokes(_inner(raw), f), prefix)
    return f'<g transform="translate({tx:.2f},{ty:.2f}) scale({s:.5f})">{inner}</g>'


def farthest_point(raw, size, outline_px):
    """Farthest opaque point (alpha > 127) of the icon alone, from the canvas centre, in px at bbox size `size`."""
    probe = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{CANVAS}" height="{CANVAS}" '
             f'viewBox="0 0 {CANVAS} {CANVAS}"><defs></defs>{icon_group(raw, size, outline_px, prefix="p-")}</svg>')
    a = np.asarray(Image.open(io.BytesIO(rsvg_png(probe.encode(), CANVAS))).convert("RGBA"))[:, :, 3] > 127
    ys, xs = np.nonzero(a)
    c = CANVAS / 2.0
    return float(np.sqrt((xs + 0.5 - c) ** 2 + (ys + 0.5 - c) ** 2).max())


def fitted_size(raw, nominal, fit_r, outline_px):
    """Optical fit (BK1 fitted_size): shrink only if the farthest point leaves fit_r. Returns (size, probe trace)."""
    s = nominal
    trace = []
    for _ in range(3):
        r = farthest_point(raw, s, outline_px)
        trace.append({"size_px": round(s, 3), "farthest_px": round(r, 3)})
        if r <= fit_r:
            break
        s = s * fit_r / r
    return s, trace


def compose(under, over, icon_g):
    """Master SVG = base under-layer, then the icon, then the base over-layer (e.g. the T5 crown)."""
    return f"{SVG_HEAD}<defs>{under.defs}{over.defs}</defs>{under.body}{icon_g}{over.body}</svg>"
