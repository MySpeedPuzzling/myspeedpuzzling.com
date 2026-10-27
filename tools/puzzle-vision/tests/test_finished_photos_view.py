"""derive/finished_photos.py: which stored finished-photo analyses count for a puzzle's second view, and how they are
averaged."""

from __future__ import annotations

import math
from typing import Any

import numpy as np

from puzzle_vision.derive import finished_photos as fv
from puzzle_vision.payload import vector_to_b64


def unit(*v: float) -> np.ndarray:
    a = np.array(v, float)
    return a / np.linalg.norm(a)


def payload(method: str = "register", visible: float = 1.0, ppp: float = 30.0, print_corr: float | None = 0.9,
            siglip: np.ndarray | None = None, dino: np.ndarray | None = None, grid_x: float | None = 2.0) -> dict[str, Any]:
    siglip = unit(1, 0, 0) if siglip is None else siglip
    dino = unit(0, 1) if dino is None else dino
    return {
        "locate": {"method": method},
        "rectified": {"visibleShare": visible, "pixelsPerPiece": ppp},
        "quality": {"sharpness": 2.0, "printCorrelation": print_corr},
        "glare": {"share": 0.01},
        "seams": {"gridX": grid_x, "gridY": 1.0, "artworkGridX": 0.5, "artworkGridY": 0.5} if grid_x is not None else None,
        "pieceScale": {"hueEntropy": 1.5},
        "embeddings": {"siglip2": {"vector": vector_to_b64(siglip)}, "dinov2": {"cls": vector_to_b64(dino)}},
    }


def test_a_registered_whole_photo_counts() -> None:
    v = fv.view([payload()], None)
    assert v is not None and v.photos == 1
    assert np.allclose(v.siglip, unit(1, 0, 0), atol=1e-3)
    names = list(fv.NUMBER_NAMES)
    assert v.numbers[names.index("gridXVsArtwork")] == 1.5
    assert v.numbers[names.index("photo.hueEntropy")] == 1.5
    assert math.isnan(v.numbers[names.index("photo.nnMedian")])


def test_what_does_not_count() -> None:
    assert fv.view([payload(method="none")], None) is None
    assert fv.view([payload(visible=0.4)], None) is None
    assert fv.view([payload(print_corr=0.999)], None) is None  # the catalogue picture itself
    no_embeddings = payload()
    del no_embeddings["embeddings"]
    assert fv.view([no_embeddings], None) is None


def test_a_quad_photo_counts_only_when_it_looks_like_the_catalogue_picture() -> None:
    assert fv.view([payload(method="quad")], None) is None
    assert fv.view([payload(method="quad")], unit(0, 1, 0)) is None
    assert fv.view([payload(method="quad")], unit(1, 0.1, 0)) is not None


def test_photos_weigh_their_visible_share_and_resolution() -> None:
    a = payload(siglip=unit(1, 0, 0), grid_x=3.0)  # whole, sharp: weight 1
    b = payload(siglip=unit(0, 1, 0), grid_x=None, visible=0.7, ppp=10)  # 0.7 × 0.5 = 0.35, no grid numbers
    v = fv.view([a, b], None)
    assert v is not None and v.photos == 2 and v.weight == 1.35
    assert v.siglip[0] > v.siglip[1] > 0
    assert abs(float(np.linalg.norm(v.siglip)) - 1) < 1e-9
    assert v.numbers[list(fv.NUMBER_NAMES).index("gridX")] == 3.0  # only the photo that has it


def test_views_keep_the_current_picture_and_the_newest_version() -> None:
    rows = [
        {"puzzleId": "p1", "imageFingerprint": "f1", "analyzerVersion": "1.0.0+x", "current": True, "result": payload(siglip=unit(1, 0, 0))},
        {"puzzleId": "p1", "imageFingerprint": "f1", "analyzerVersion": "1.1.0+x", "current": True, "result": payload(siglip=unit(0, 1, 0))},
        {"puzzleId": "p2", "imageFingerprint": "old", "analyzerVersion": "1.1.0+x", "current": True, "result": payload()},
    ]
    out = fv.views(rows, {"p1": "f1", "p2": "f2"}, {})
    assert set(out) == {"p1"}
    assert np.allclose(out["p1"].siglip, unit(0, 1, 0), atol=1e-3)
