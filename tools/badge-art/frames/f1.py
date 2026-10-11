"""F1 seal ladder v2 (owner decision 2026-10-10): the tier frames, split into the layer UNDER the icon and the layer
OVER it (only the T5 crown is drawn over the icon). Copied from the badge lab (BK1 frames.f1_seal); `freeze_bases.py`
exports these layers once into bases/*.svg and the build only ever reads those frozen files.

Ladder (a non-colour silhouette step at every tier + the tone ladder):
  T1 Bronze    plain circle seal
  T2 Silver    6 big scallops (hexafoil)
  T3 Gold      12 scallops
  T4 Platinum  12 scallops, a touch smaller + rosette pleats + two diagonal ribbon tails
  T5 Diamond   18-ray sunburst + tails + crown (over the icon), saturated logo prism. NO sparkle stars (owner).
Every tier: navy outline, white plate (radius PLATE, no navy ring). No white gloss arcs (owner 2026-10-10: the
metal streak + glint dot on the band read weird) - the bases keep only the soft same-hue wash.

The icon slot is a frame constant (same in every tier): the icon's bounding box longer side = ICON (0.53 x canvas),
centred on the canvas centre, optically shrunk only if its farthest opaque point would leave FIT_R (PLATE - 8).
"""
import math

from .kit import (C, LIMIT, NAVY, OW_IN, TIERS, W, Polygon, Svg, circle, clip, crown, fill, fitted_tail, outline,
                  plate, scallop, stroke_line, sunburst, wash)

ICON = 0.53 * W        # icon bbox longer side (542.72 px) - constant in every tier
PLATE = 308.0          # white plate radius
FIT_R = PLATE - 8      # the icon's farthest opaque point must stay inside this radius (optical fit)
F1 = dict(r1=376.0, tail_w=0.180 * W, tail_ang=37.0, pleats=True, crown_w=0.290 * W)

def seal_shape(tier):
    if tier == 1:
        return circle(C, C, F1["r1"])
    if tier == 2:      # 6 BIG scallops: a hexafoil, lobes clearly separated
        return scallop(6, r_c=268.0, r_b=146.0, r_base=372.0, fillet=30.0)
    if tier == 3:      # 12 scallops
        return scallop(12, r_c=372.0, r_b=64.0, r_base=392.0, fillet=16.0)
    if tier == 4:      # the same 12 scallops, a touch smaller so the tails hang free (rosette)
        return scallop(12, r_c=352.0, r_b=60.0, r_base=370.0, fillet=15.0)
    return sunburst(18, r_out=418.0, r_in=364.0, round_out=13.0, round_in=10.0)


def pleats(svg, n, r0, r1, rot=-90.0, offset=0.5):
    """Rosette pleats: n radial folds in the band (thin navy lines, alternate segments a touch darker)."""
    for i in range(n):
        a0 = math.radians(rot + 360.0 * (i + offset) / n)
        a1 = math.radians(rot + 360.0 * (i + offset + 1) / n)
        if i % 2 == 0:
            seg = Polygon([(C + r0 * math.cos(a0), C + r0 * math.sin(a0)), (C + r1 * math.cos(a0), C + r1 * math.sin(a0)),
                           (C + r1 * math.cos(a1), C + r1 * math.sin(a1)), (C + r0 * math.cos(a1), C + r0 * math.sin(a1))])
            fill(svg, seg, NAVY, opacity=0.07)
        stroke_line(svg, [(C + r0 * math.cos(a0), C + r0 * math.sin(a0)), (C + r1 * math.cos(a0), C + r1 * math.sin(a0))],
                    OW_IN * 0.8, color=NAVY)


def under(shape, tone):
    """Everything drawn BEFORE the icon. shape = silhouette tier 1..5, tone = colour dict (kit.TIERS[n])."""
    svg = Svg(prefix="u")
    if shape >= 4:
        for k, sgn in enumerate((-1, 1)):
            g = fitted_tail(C, C, sgn * F1["tail_ang"], F1["tail_w"], 0.070 * W, max_y=C + LIMIT)
            wash(svg, g, tone, role="base" if k == 0 else "light", shade_k=0.06)
    seal = seal_shape(shape)
    wash(svg, seal, tone, shade_k=0.05)
    if shape == 4 and F1["pleats"]:
        cid = clip(svg, seal)
        svg.add(f'<g clip-path="url(#{cid})">')
        pleats(svg, 12, PLATE - 4, 420.0)
        svg.add('</g>')
        outline(svg, seal)
    plate(svg, PLATE)
    return svg


def over(shape):
    """Everything drawn AFTER the icon: the T5 crown (owner 2026-10-10: no sparkle stars - the crown, the sunburst
    edge, the tails and the prism tones carry the prize)."""
    svg = Svg(prefix="o")
    if shape == 5:
        crown(svg, C, C - 312, F1["crown_w"], hk=0.62)
    return svg


# key -> (under, over, status, title, file stem). Keys t1..t5 = BadgeTier 1..5.
BASES = {
    "t1": (lambda: under(1, TIERS[1]), lambda: over(1), "frozen", "F1 T1 Bronze: circle seal", "f1-t1"),
    "t2": (lambda: under(2, TIERS[2]), lambda: over(2), "frozen", "F1 T2 Silver: 6 scallops", "f1-t2"),
    "t3": (lambda: under(3, TIERS[3]), lambda: over(3), "frozen", "F1 T3 Gold: 12 scallops", "f1-t3"),
    "t4": (lambda: under(4, TIERS[4]), lambda: over(4), "frozen",
           "F1 T4 Platinum: 12 scallops + pleats + ribbon tails", "f1-t4"),
    "t5": (lambda: under(5, TIERS[5]), lambda: over(5), "frozen",
           "F1 T5 Diamond: 18-ray sunburst + tails + crown, logo prism, no sparkles", "f1-t5"),
}
