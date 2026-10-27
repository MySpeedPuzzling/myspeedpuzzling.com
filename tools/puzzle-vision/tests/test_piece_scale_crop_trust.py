"""`piece_scale` (CPU raw analyzer) and `crop_trust` (derived) - payload shapes, the numbers on pictures whose answer
is known, and the frozen trust model. The prototype's numbers were reproduced on 300 catalogue crops offline (every
number equal up to the payload's 5-decimal rounding; image-taxonomy.md §11.1)."""

import numpy as np
import pytest

from puzzle_vision import stages
from puzzle_vision.analyzers import INFO
from puzzle_vision.derive import crop_trust as ct
from puzzle_vision.stages import piece_scale as ps

from .test_payloads import wrap

BLOCK_KEYS = {"pieces", "grid", "tilePx", "nnMedian", "nnP25", "nnShareBelow003", "nnShareBelow006", "nnShareBelow010",
              "logCandidates006", "logCandidates010", "lowDetailShare", "fineTextureMedian", "fineTextureP25", "pileHhi16",
              "pileEntropy16", "pileHhi32", "colourNnMedian", "textureNnMedian", "largestPileShare16", "boundaryEdgeShare",
              "logCandidatesColour006", "hueEntropy", "nnHistogram", "pileShares16", "uniformShare", "lookalike3Share",
              "largestUniformRegion"}


def picture(w=900, h=600, seed=0):
    """Smooth random colour blobs (a painting) - every piece distinct."""
    import cv2

    rng = np.random.default_rng(seed)
    small = rng.integers(0, 255, (h // 40, w // 40, 3), dtype=np.uint8)
    return cv2.resize(small, (w, h), interpolation=cv2.INTER_CUBIC)


def test_the_grid_matches_the_piece_layout():
    assert ps.grid_shape(1000, 70, 50) == (37, 27)  # a 1000-piece 70 × 50 cm puzzle is cut 37 × 27 (= 999)
    assert ps.grid_shape(500, 49, 36) == (26, 19)
    assert ps.grid_shape(4, 100, 100) == (2, 2)


def test_payload_shape_and_the_extra_piece_counts():
    p = wrap(ps.piece_scale(picture(), 300))
    assert set(p) >= {"real", "p500", "p1000", "cropSize", "pieceCountKnown"} and p["cropSize"] == [900, 600]
    for key in ("real", "p500", "p1000"):
        assert set(p[key]) == BLOCK_KEYS, key
        assert len(p[key]["nnHistogram"]) == 16 and abs(sum(p[key]["nnHistogram"]) - 1) < 1e-3
        assert len(p[key]["pileShares16"]) <= 16 and p[key]["pileShares16"] == sorted(p[key]["pileShares16"], reverse=True)
        assert abs(sum(p[key]["pileShares16"]) - 1) < 1e-3 and p[key]["largestPileShare16"] == p[key]["pileShares16"][0]
    assert p["real"]["pieces"] == 300 and p["p500"]["pieces"] == 500 and p["p1000"]["pieces"] == 1000
    assert p["p1000"]["tilePx"] < p["p500"]["tilePx"] < p["real"]["tilePx"]
    same = ps.piece_scale(picture(), 500)
    assert same["p500"] == same["real"] and same["p1000"]["pieces"] == 1000  # computed once for the real count
    unknown = ps.piece_scale(picture(), None)
    assert unknown["real"]["pieces"] == 500 and unknown["pieceCountKnown"] is False


def test_a_single_colour_is_all_one_pile_and_a_painting_is_not():
    flat = np.full((600, 900, 3), (40, 90, 200), np.uint8)
    b = ps.piece_scale(flat, 500)["real"]
    assert b["nnMedian"] == 0 and b["nnShareBelow003"] == 1 and b["uniformShare"] == 1 and b["lookalike3Share"] == 1
    assert b["largestUniformRegion"] == 1 and b["lowDetailShare"] == 1 and b["boundaryEdgeShare"] == 0
    assert b["hueEntropy"] == 0 and b["nnHistogram"][0] == 1
    paint = ps.piece_scale(picture(), 500)["real"]
    assert paint["nnMedian"] > 0.01 and paint["hueEntropy"] > 1.0 and paint["uniformShare"] < 0.5
    assert paint["pileEntropy16"] > 2.0 and paint["largestUniformRegion"] < b["largestUniformRegion"]


def test_a_sky_over_a_painting_is_the_largest_uniform_region():
    a = picture()
    a[:300] = (150, 200, 240)  # the upper half: one flat sky
    b = ps.piece_scale(a, 500)["real"]
    assert 0.4 < b["largestUniformRegion"] <= 0.6 and 0.4 < b["uniformShare"] < 0.7


def test_too_fine_a_grid_is_null():
    assert ps.piece_scale(picture(120, 80), 1000)["p1000"] is None  # 3 px cells


def test_the_stage_is_cpu_and_never_takes_the_model_lock():
    assert stages.STAGES["piece_scale"] is ps.PieceScaleStage and INFO["piece_scale"].unit == "cpu"
    assert ps.PieceScaleStage.needs_model_lock is False and ps.PieceScaleStage.uses_crop is True
    assert INFO["piece_scale"].requires == ("artwork",)


# --- crop_trust -------------------------------------------------------------------------------------------------------
def trim_crop():
    return {"kind": "flat", "method": "trim", "bbox": {"x": 0.01, "y": 0.0, "w": 0.98, "h": 1.0},
            "quality": {"reasons": [], "artworkShare": 0.98},
            "firstPass": {"kind": "flat", "method": "trim", "bbox": [0.01, 0.0, 0.99, 1.0], "vlmBbox": None},
            "signals": {"finalSides": [{"side": s, "support": 0.9, "similarity": 0.1, "photoEdge": s in ("top", "bottom")} for s in ("top", "right", "bottom", "left")]}}


def face_crop():
    """A box photo cropped by the face pass on the VLM's line: the picture continues across two sides."""
    return {"kind": "box_front", "method": "vlm+widen+face", "bbox": {"x": 0.2, "y": 0.15, "w": 0.55, "h": 0.6},
            "quality": {"reasons": ["not_digital_artwork", "low_resolution_artwork"], "artworkShare": 0.25},
            "firstPass": {"kind": "box_front", "method": "vlm", "bbox": [0.1, 0.1, 0.9, 0.9], "vlmBbox": [0.1, 0.1, 0.9, 0.9]},
            "signals": {"finalSides": [{"side": s, "support": 0.1, "similarity": 0.95, "photoEdge": False} for s in ("top", "right", "bottom", "left")]}}


def test_the_frozen_model():
    m = ct.load_model()
    assert m["features"] == list(ct.FEATURES) and len(m["logistic"]["coef"]) == len(ct.FEATURES) == 8
    assert m["probe"]["dim"] == 1152 and ct._probe_weights(dict(m["probe"])).shape == (1152,)
    assert set(m["fill"]) == set(ct.FEATURES)
    ev = m["evaluation"]
    assert ev["set"] == "reference" and ev["auc"] >= 0.85 and ev["brier"] < ev["brierBaseRate"]
    assert m["trainedOn"]["sets"] == ["tuning", "feedback"]
    coef = dict(zip(m["features"], m["logistic"]["coef"]))
    assert coef["flatlike"] > 0 and coef["meanSupportInner"] > 0 and coef["maxSimilarityInner"] < 0 and coef["boxKind"] < 0


def test_signals_read_both_box_shapes():
    s = ct.raw_signals(face_crop())
    assert s["iouVlm"] == pytest.approx((0.55 * 0.6) / 0.64) and s["iouFirstPass"] == s["iouVlm"]
    assert s["meanSupportInner"] == pytest.approx(0.1) and s["maxSimilarityInner"] == 0.95
    assert (s["trim"], s["boxKind"], s["assembled"]) == (0.0, 1.0, 0.0)
    t = ct.raw_signals(trim_crop())
    assert t["iouVlm"] is None and t["trim"] == 1.0 and t["meanSupportInner"] == pytest.approx(0.9)


def test_trust_and_the_marks():
    model = ct.load_model()
    good = wrap(ct.payload(model, trim_crop(), None, 0, "1.1.0+siglip2-q3vl4b"))
    bad = wrap(ct.payload(model, face_crop(), None, 300, "1.1.0+siglip2-q3vl4b"))
    assert good["trust"] > 0.8 > 0.5 > bad["trust"] and not good["cropNeedsLook"] and bad["cropNeedsLook"]
    assert good["pictureKind"] == "digital" and bad["pictureKind"] == "photo"
    assert good["resolution"] is None and bad["resolution"] == ["low_resolution_artwork"]
    assert good["signals"]["flatlike"] is None  # no crop vector: the model's fill value is used
    assert bad["priority"] == 8 and good["priority"] == 0  # floor(log2(1 + solvedCount))
    assert bad["estimateLowConfidence"] is (bad["trust"] < 0.3)  # artwork 25 % of the photo
    gone = face_crop()
    gone["quality"]["reasons"].append("not_the_picture")
    p = ct.payload(model, gone, None, 1, None)
    assert p["notThePicture"] and p["estimateLowConfidence"]


def test_the_probe_reads_the_crop_vector():
    model = ct.load_model()
    model["probe"] = dict(model["probe"])
    w = ct._probe_weights(model["probe"])
    assert ct.flatlike(model, w * 10) > ct.flatlike(model, -w * 10)  # along the probe = looks like flat artwork
    assert ct.flatlike(model, np.ones(16)) is None  # another embedding size: unknown, filled


def test_compute_skips_crops_without_a_box_and_is_stable():
    rows = {"a": {"result": trim_crop(), "analyzerVersion": "v"}, "b": {"result": {"kind": "flat"}, "analyzerVersion": "v"}}
    out = ct.compute(rows, {}, {"a": 3})
    assert list(out) == ["a"] and out["a"]["priority"] == 2 and out["a"]["artworkVersion"] == "v"
    assert ct.compute(rows, {}, {"a": 3}) == out
