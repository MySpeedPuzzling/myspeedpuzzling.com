"""`tags` - the fusion of every analysis into taxonomy keys (image-taxonomy.md §10, Appendix A.8 traits, A.9 skills).

Rules v1 (thresholds are drafts to calibrate on moderated labels, §14 - a change bumps the `tags` version):
- theme: the VLM's first two themes count when zero-shot does not contradict them (percentile >= 0.5); a third theme
  needs zero-shot agreement (>= 0.80); zero-shot alone assigns at >= 0.98. Subthemes need their parent and >= 0.90.
- style, scene: the VLM's value; `uncertain` when zero-shot's best key of the facet is another one.
- season, time, weather, mood: the VLM's value (not `none`); holiday needs the VLM **and** zero-shot (>= 0.80).
- people: the largest count of the VLM, Apple humans/faces and segmentation persons, bucketed.
- special: box text rules (and the VLM's seek-and-find / collage only as candidates).
- licence: two sources (VLM characters + OCR text / zero-shot >= 0.95); one source = candidate.
- trait: the numeric value is always stored; the label applies at the threshold (absolute or corpus percentile).
- skill: weighted sum of the corpus percentiles of the trait values (0..1).
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

import numpy as np

from ..imaging import bbox_to_xyxy
from ..payload import as_map
from ..stages.apple_vision import text_area_share
from .taxonomy import IMAGE_KINDS, LICENCE_WORDS, TAXONOMY_VERSION, THEMES

ZS_KEEP = 0.5
ZS_AGREE = 0.80
ZS_SUB = 0.90
ZS_LICENCE = 0.95
ZS_ALONE = 0.98

# trait key -> (rule, threshold): "abs>=" / "abs<=" absolute, "pct>=" / "pct<=" corpus percentile
TRAIT_RULES: dict[str, tuple[str, float]] = {
    "large_uniform_areas": ("abs>=", 0.25),
    "featureless_pieces": ("abs>=", 0.20),
    "lookalike_pieces": ("abs>=", 0.30),
    "distinct_pieces": ("pct>=", 0.75),
    "large_sky": ("abs>=", 0.25),
    "large_water": ("abs>=", 0.20),
    "foliage_texture": ("abs>=", 0.30),
    "smooth_gradients": ("abs>=", 0.15),
    "repeating_pattern": ("pct>=", 0.90),
    "busy_detail": ("pct>=", 0.80),
    "low_colour": ("pct<=", 0.15),
    "many_colour_regions": ("pct>=", 0.75),
    "colourful": ("pct>=", 0.75),
    "dark": ("abs>=", 0.40),
    "bright_white": ("abs>=", 0.40),
    "low_contrast": ("pct<=", 0.15),
    "text_heavy": ("abs>=", 0.05),
    "strong_lines": ("pct>=", 0.80),
    "faces_prominent": ("abs>=", 0.05),
    "many_objects": ("abs>=", 30),
    "collage_grid": ("abs>=", 1),
    "uniform_border": ("abs>=", 0.5),
    "symmetric": ("abs>=", 0.8),
}

SKILL_WEIGHTS: dict[str, dict[str, float]] = {
    "shape_sorting": {"featureless_pieces": 0.4, "large_uniform_areas": 0.3, "large_sky": 0.15, "large_water": 0.15},
    "shade_discrimination": {"smooth_gradients": 0.5, "low_contrast_inv": 0.3, "low_colour_inv": 0.2},
    "texture_reading": {"foliage_texture": 0.6, "lookalike_pieces": 0.4},
    "pattern_memory": {"repeating_pattern": 0.6, "lookalike_pieces": 0.2, "collage_grid": 0.2},
    "detail_scanning": {"busy_detail": 0.5, "many_objects": 0.3, "text_heavy": 0.2},
    "region_building": {"many_colour_regions": 0.5, "colourful": 0.3, "distinct_pieces": 0.2},
    "line_following": {"strong_lines": 0.6, "buildings": 0.2, "map_style": 0.2},
    "box_reference": {"busy_detail": 0.4, "distinct_pieces": 0.3, "many_objects": 0.3},
    "low_light": {"dark": 0.7, "low_contrast_inv": 0.3},
    "edge_start": {"uniform_border": 1.0},
}


def _seg_share(seg: dict[str, Any] | None, words: tuple[str, ...]) -> float | None:
    if not seg:
        return None
    return round(sum(float(v) for k, v in as_map(seg.get("classes")).items() if any(w in k.lower() for w in words)), 4)


def trait_values(p: dict[str, dict[str, Any]]) -> dict[str, float | None]:
    """The numeric source of every A.8 trait for one puzzle (None = input missing)."""
    t, c, d, vl = as_map(p.get("traits")), as_map(p.get("colors")), as_map(p.get("describe")), as_map(p.get("vision_labels"))
    seg = p.get("segmentation") if isinstance(p.get("segmentation"), dict) else None
    ocr = p.get("ocr") if isinstance(p.get("ocr"), dict) else None
    art = p.get("artwork") if isinstance(p.get("artwork"), dict) else None
    text_share = None
    if ocr:
        text_share = ocr.get("textAreaShareInArtwork")
        if text_share is None and art and isinstance(art.get("bbox"), dict):
            text_share = text_area_share(ocr.get("lines") or [], bbox_to_xyxy(art["bbox"]))
    objects = None
    counts, instances = as_map(d.get("counts")), as_map((seg or {}).get("instances"))
    if counts or instances:
        objects = max(float(counts.get("distinctObjects", 0) or 0), float(sum(instances.values())))
    faces = vl.get("faces")
    zs = as_map(as_map(p.get("zeroshot")).get("scores"))
    collage = None
    if "collageGrid" in d:
        collage = 1.0 if d.get("collageGrid") and zs.get("theme.collage_collection.collage_grid", 0) >= 0.9 else 0.0
    return {
        "large_uniform_areas": t.get("uniformShare"),
        "featureless_pieces": t.get("pieceFlatShare"),
        "lookalike_pieces": t.get("pieceLookalikeShare"),
        "distinct_pieces": t.get("pieceDistinctiveness"),
        "large_sky": _seg_share(seg, ("sky",)),
        "large_water": _seg_share(seg, ("sea", "river", "water")),
        "foliage_texture": _seg_share(seg, ("tree", "grass", "plant")),
        "smooth_gradients": t.get("smoothGradientShare"),
        "repeating_pattern": t.get("regularity"),
        "busy_detail": t.get("edgeDensity"),
        "low_colour": c.get("paletteEffectiveColors"),
        "many_colour_regions": t.get("colorRegions80"),
        "colourful": c.get("colorfulness"),
        "dark": c.get("darkShare"),
        "bright_white": c.get("lightShare"),
        "low_contrast": c.get("lightnessStd"),
        "text_heavy": text_share,
        "strong_lines": t.get("lineStrength"),
        "faces_prominent": max((float(as_map(f).get("area", 0) or 0) for f in faces), default=0.0) if isinstance(faces, list) else None,
        "many_objects": objects,
        "collage_grid": collage,
        "uniform_border": as_map(t.get("borderBand")).get("uniformShare"),
        "symmetric": t.get("symmetry"),
        "buildings": _seg_share(seg, ("building", "house", "roof", "bridge")),
        "map_style": (1.0 if d.get("style") == "map_cartographic" else 0.0) if d.get("style") else None,
    }


@dataclass
class Corpus:
    """Sorted value arrays per trait across the corpus - percentiles and percentile thresholds."""

    values: dict[str, np.ndarray] = field(default_factory=dict)

    @classmethod
    def build(cls, per_puzzle: list[dict[str, float | None]]) -> "Corpus":
        keys = set().union(*[set(v) for v in per_puzzle]) if per_puzzle else set()
        return cls({k: np.sort(np.array([float(v[k]) for v in per_puzzle if v.get(k) is not None], dtype=float)) for k in keys})

    def percentile(self, key: str, value: float) -> float:
        arr = self.values.get(key)
        if arr is None or arr.size == 0:
            return 0.5
        lo = np.searchsorted(arr, value, side="left")
        hi = np.searchsorted(arr, value, side="right")
        return float((lo + hi) / 2 / arr.size)


def _assign(bucket: dict[str, Any], key: str, s: float, src: list[str], **extra: Any) -> None:
    bucket[key] = {"s": round(float(s), 3), "src": src, **extra}


def fuse(p: dict[str, dict[str, Any]], corpus: Corpus, box_text: dict[str, Any] | None = None) -> dict[str, Any]:
    d = as_map(p.get("describe"))
    zs = as_map(as_map(p.get("zeroshot")).get("scores"))
    assigned: dict[str, Any] = {}
    candidates: dict[str, Any] = {}

    # themes + subthemes
    vlm_themes = list(d.get("themes") or [])
    for rank, top in enumerate(vlm_themes):
        z = zs.get(f"theme.{top}")
        if rank < 2 and (z is None or z >= ZS_KEEP):
            _assign(assigned, f"theme.{top}", max(0.7, z or 0.0), ["describe"] + (["zeroshot"] if z is not None else []))
        elif z is not None and z >= ZS_AGREE:
            _assign(assigned, f"theme.{top}", z, ["describe", "zeroshot"])
        else:
            _assign(candidates, f"theme.{top}", z or 0.5, ["describe"])
    for top in THEMES:
        z = zs.get(f"theme.{top}")
        if z is not None and z >= ZS_ALONE and f"theme.{top}" not in assigned:
            _assign(assigned, f"theme.{top}", z, ["zeroshot"])
    for top, subs in THEMES.items():
        for sub in subs:
            key, z = f"theme.{top}.{sub}", zs.get(f"theme.{top}.{sub}")
            if z is None:
                continue
            if f"theme.{top}" in assigned and z >= ZS_SUB:
                _assign(assigned, key, z, ["zeroshot"])
            elif z >= ZS_SUB:
                _assign(candidates, key, z, ["zeroshot"])

    # single-value facets
    for facet in ("style", "scene"):
        v = d.get(facet)
        if v:
            facet_scores = {k: s for k, s in zs.items() if k.startswith(f"{facet}.")}
            best = max(facet_scores, key=facet_scores.get) if facet_scores else None
            agree = best == f"{facet}.{v}" if best else None
            _assign(assigned, f"{facet}.{v}", 0.9 if agree else 0.6, ["describe"] + (["zeroshot"] if agree else []),
                    **({"uncertain": True} if agree is False else {}))
    for facet in ("season", "time", "weather"):
        v = d.get(facet)
        if v and v != "none":
            _assign(assigned, f"{facet}.{v}", 0.8, ["describe"])
    for mood in d.get("mood") or []:
        _assign(assigned, f"mood.{mood}", 0.7, ["describe"])
    hol = d.get("holiday")
    if hol and hol not in ("none", "other"):
        z = zs.get(f"holiday.{hol}")
        target = assigned if z is not None and z >= ZS_AGREE else candidates
        _assign(target, f"holiday.{hol}", z or 0.5, ["describe"] + (["zeroshot"] if z is not None and z >= ZS_AGREE else []))

    # people
    vl = as_map(p.get("vision_labels"))
    seg = as_map(p.get("segmentation"))
    counts = [float(as_map(d.get("counts")).get("people", 0) or 0), float(vl.get("humans", 0) or 0),
              float(as_map(seg.get("instances")).get("person", 0) or 0)]
    n_people = max(counts)
    bucket = "none" if n_people == 0 else "few" if n_people <= 3 else "many" if n_people <= 15 else "crowd"
    if d or vl or seg:
        _assign(assigned, f"people.{bucket}", 0.8, [s for s, c in zip(("describe", "vision_labels", "segmentation"), counts) if c == n_people])
    faces = vl.get("faces") if isinstance(vl.get("faces"), list) else []
    if any(float(as_map(f).get("area", 0)) >= 0.05 for f in faces):
        _assign(assigned, "people.prominent_faces", 0.9, ["vision_labels"])

    # special
    bt = as_map(box_text) or as_map(p.get("box_text"))
    for sp in bt.get("specialMentions") or []:
        _assign(assigned, f"special.{sp}", 0.9, ["box_text"])
    if d.get("seekAndFind") and "special.seek_and_find" not in assigned:
        _assign(candidates, "special.seek_and_find", 0.6, ["describe"])

    # licence
    char_text = " ".join(str(c).lower() for c in d.get("characters") or [])
    ocr_text = " ".join(str(as_map(line).get("t", "")).lower() for line in (as_map(p.get("ocr")).get("lines") or []))
    for lic, ws in LICENCE_WORDS.items():
        sources = []
        if any(re.search(rf"(?<!\w){re.escape(w)}(?!\w)", char_text) for w in ws):
            sources.append("describe")
        if any(re.search(rf"(?<!\w){re.escape(w)}(?!\w)", ocr_text) for w in ws):
            sources.append("ocr")
        if (zs.get(f"licence.{lic}") or 0) >= ZS_LICENCE:
            sources.append("zeroshot")
        if len(sources) >= 2:
            _assign(assigned, f"licence.{lic}", 0.9, sources)
        elif sources:
            _assign(candidates, f"licence.{lic}", 0.5, sources)
    if d.get("characters") and not any(k.startswith("licence.") for k in list(assigned) + list(candidates)):
        _assign(candidates, "licence.other", 0.5, ["describe"])

    # traits + skills
    values = trait_values(p)
    traits: dict[str, float] = {}
    pct: dict[str, float] = {}
    for key, (rule, thr) in TRAIT_RULES.items():
        v = values.get(key)
        if v is None:
            continue
        traits[f"trait.{key}"] = round(float(v), 4)
        pct[key] = corpus.percentile(key, float(v))
        hit = {"abs>=": v >= thr, "abs<=": v <= thr, "pct>=": pct[key] >= thr, "pct<=": pct[key] <= thr}[rule]
        if hit:
            _assign(assigned, f"trait.{key}", pct[key], ["traits"])
    for extra in ("buildings", "map_style"):
        if values.get(extra) is not None:
            pct[extra] = corpus.percentile(extra, float(values[extra]))
    if "low_contrast" in pct:
        pct["low_contrast_inv"] = 1 - pct["low_contrast"]
    if "low_colour" in pct:
        pct["low_colour_inv"] = 1 - pct["low_colour"]
    skills = {}
    for skill, weights in SKILL_WEIGHTS.items():
        have = {k: w for k, w in weights.items() if k in pct}
        if have:
            total = sum(have.values())
            skills[f"skill.{skill}"] = round(sum(pct[k] * w for k, w in have.items()) / total, 3)

    # image facet
    art = as_map(p.get("artwork"))
    if art.get("kind") in IMAGE_KINDS:
        _assign(assigned, IMAGE_KINDS[art["kind"]], 0.9, ["artwork"])
        src, bb = as_map(art.get("source")), as_map(art.get("bbox"))
        if src and bb and max(src.get("width", 0) * bb.get("w", 0), src.get("height", 0) * bb.get("h", 0)) < 600:
            _assign(assigned, "image.low_resolution", 0.9, ["artwork"])

    entities = {
        "subjects": list(d.get("subjects") or []),
        "place": d.get("place"),
        "characters": list(d.get("characters") or []),
        "artist": d.get("artistSignature"),
    }
    # empty maps are sent as null (they would come back from the API as lists)
    return {"taxonomy": TAXONOMY_VERSION, "assigned": assigned or None, "candidates": candidates or None, "skills": skills or None,
            "traits": traits or None, "entities": entities}
