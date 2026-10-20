"""`artwork` 1.1: the quad check, widening, the face pass, the kind's second opinion, quality - and the scoring
every artwork version runs first (artwork_eval)."""

import numpy as np
import pytest

from puzzle_vision.api import WorkItem
from puzzle_vision.artwork_eval import Candidate, crop_polygon, score
from puzzle_vision.download import LocalImage
from puzzle_vision.stages import artwork as aw
from puzzle_vision.stages import artwork_refine as ar
from puzzle_vision.stages.artwork_check import lab_image, side_measures
from puzzle_vision.stages.base import Prepared
from puzzle_vision.workstate import WorkState

W, H = 800, 600
PIC = (0.2, 0.15, 0.8, 0.85)  # the picture on the table: x0, y0, x1, y1


def scene(pic=PIC, seed=1, band=None):
    """A plain table with a picture on it (smooth random colour fields - no straight lines inside); `band` = (y0, y1)
    of a flat title band printed across the top of the picture's box."""
    import cv2

    rng = np.random.default_rng(seed)
    img = np.full((H, W, 3), (205, 190, 160), np.uint8)
    img = np.clip(img.astype(int) + rng.integers(-3, 4, img.shape), 0, 255).astype(np.uint8)
    x0, y0, x1, y1 = int(pic[0] * W), int(pic[1] * H), int(pic[2] * W), int(pic[3] * H)
    noise = cv2.GaussianBlur(rng.normal(0, 1, (y1 - y0, x1 - x0, 3)).astype(np.float32), (0, 0), 6)
    noise = (noise - noise.min()) / (noise.max() - noise.min() + 1e-6)
    img[y0:y1, x0:x1] = (40 + 200 * noise).astype(np.uint8)
    if band is not None:
        img[int(band[0] * H):int(band[1] * H), x0:x1] = (20, 40, 160)
    return img


def first_pass(quad=None, vlm_bbox=PIC, kind="assembled", method=None, vlm_kind=None, gate=None):
    return {"kind": kind, "method": method or ("vlm+quad" if quad else "vlm"), "bbox": list(vlm_bbox),
            "quad": quad, "gateScores": gate or {"flat": 0.1, "box": 0.2, "assembled": 0.3, "other": 0.1},
            "gateMargin": -0.2, "vlmKind": vlm_kind or kind, "vlmBbox": list(vlm_bbox), "quadIou": 0.9 if quad else None}


# --- the first pass of a stored result ---------------------------------------------------------------------------------
def test_first_pass_is_read_from_a_1_0_result():
    stored = {"kind": "assembled", "bbox": {"x": 0.1, "y": 0.1, "w": 0.8, "h": 0.8}, "method": "vlm+quad",
              "corners": [[0.1, 0.12], [0.9, 0.1], [0.9, 0.9], [0.1, 0.88]], "kindScores": {"flat": 0.1},
              "signals": {"gateMargin": -0.1, "vlmKind": "assembled", "vlmBbox": {"x": 0.05, "y": 0.05, "w": 0.9, "h": 0.9}, "quadIou": 0.8}}
    fp = ar.first_pass_from(stored)
    assert fp["quad"] == stored["corners"] and fp["vlmBbox"] == [0.05, 0.05, 0.95, 0.95] and fp["vlmKind"] == "assembled"
    no_corners = dict(stored, corners=None)
    assert ar.first_pass_from(no_corners)["quad"] == [[0.1, 0.1], [0.9, 0.1], [0.9, 0.9], [0.1, 0.9]]  # a straight quad
    assert ar.first_pass_from({"kind": "flat", "method": "trim", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}})["quad"] is None
    assert ar.first_pass_from({"firstPass": {"method": "vlm"}}) == {"method": "vlm"}  # 1.1 and later keep it
    assert ar.first_pass_from(None) is None and ar.first_pass_from({"kind": "flat"}) is None


# --- quads ----------------------------------------------------------------------------------------------------------------
def test_a_quad_on_the_picture_edges_is_kept():
    a = scene()
    quad = ar.box_quad(PIC)
    g = ar.crop_geometry(a, first_pass(quad=quad))
    assert g["signals"]["quad"] == "accepted" and g["quad"] == quad


def test_a_quad_inside_the_picture_is_refused_and_the_crop_never_cuts_it():
    a = scene()
    inner = [[0.3, 0.25], [0.8, 0.15], [0.7, 0.85], [0.2, 0.75]]  # sheared, sides across the picture
    g = ar.crop_geometry(a, first_pass(quad=inner))
    assert g["signals"]["quad"] in ("rejected", "partly")
    t = Candidate("assembled", ar.quad_to_box(ar.box_quad(PIC)), None)
    c = Candidate("assembled", g["bbox"], g["quad"])
    assert score(c, {"kind": "assembled", "corners": ar.box_quad(PIC)}, W / H)["cut"] <= 0.02  # the inset only
    assert score(t, {"kind": "assembled", "corners": ar.box_quad(PIC)}, W / H)["cut"] <= 0.02


def test_side_measures_tell_a_real_edge_from_a_line_through_the_picture():
    lab = lab_image(scene())
    on = {m["side"]: m for m in side_measures(lab, ar.box_quad(PIC))}
    through = {m["side"]: m for m in side_measures(lab, ar.box_quad((0.35, 0.3, 0.65, 0.7)))}
    assert min(m["support"] for m in on.values()) >= ar.QUAD_SIDE_SUPPORT
    assert max(m["support"] for m in through.values()) < ar.QUAD_SIDE_SUPPORT
    edge = side_measures(lab, ar.box_quad((0.0, 0.0, 1.0, 1.0)))
    assert all(m["photoEdge"] for m in edge)


def test_hybrid_keeps_confirmed_sides_and_moves_the_others_out():
    quad = [[0.2, 0.15], [0.8, 0.2], [0.75, 0.85], [0.2, 0.85]]
    out = ar.hybrid_quad(quad, [True, False, True, True], [0.1, 0.1, 0.9, 0.9], W / H)
    assert out is not None
    assert out[1][0] == pytest.approx(0.9, abs=1e-3) and out[2][0] == pytest.approx(0.9, abs=1e-3)  # right side = box
    assert out[0] == pytest.approx([0.2, 0.15], abs=1e-3)  # TL untouched (top and left confirmed)


# --- widening ---------------------------------------------------------------------------------------------------------------
def test_a_vlm_box_stopping_inside_the_picture_moves_out_to_its_edge():
    a = scene(pic=(0.2, 0.15, 0.8, 0.85))
    vlm_short = (0.2, 0.15, 0.62, 0.85)  # stops inside the picture on the right
    fp = first_pass(vlm_bbox=vlm_short)
    old_pad, ar.VLM_PAD = ar.VLM_PAD, 0.0
    try:
        g = ar.crop_geometry(a, fp)
    finally:
        ar.VLM_PAD = old_pad
    assert "right" in g["signals"].get("widened", {})
    assert g["bbox"][2] >= 0.79  # at the picture's edge (or beyond), never inside it


# --- the face pass ------------------------------------------------------------------------------------------------------------
def test_the_face_pass_trims_a_band_on_a_real_edge():
    a = scene(band=(0.15, 0.27))
    box = ar.box_quad(PIC)
    # the VLM says: the picture starts below the band (face coordinates; the band is 0.12 / 0.70 of the face)
    new, info = ar.face_pass(a, box, lambda face: [0.0, 0.17, 1.0, 1.0])
    assert info["sides"]["top"]["result"] == "trimmed" and new is not None
    top = ar.quad_to_box(new)[1]
    assert 0.15 + 0.17 * 0.70 - 0.03 <= top <= 0.27  # just above the picture (back-off), never into it


def test_the_face_pass_never_trims_where_the_picture_crosses_the_line():
    a = scene()
    new, info = ar.face_pass(a, ar.box_quad(PIC), lambda face: [0.0, 0.2, 1.0, 1.0])  # no band: art crosses the line
    assert new is None and info["sides"]["top"]["result"] == "no edge on the line"
    new, info = ar.face_pass(a, ar.box_quad(PIC), lambda face: [0.0, 0.5, 1.0, 1.0])
    assert new is None and info["sides"]["top"]["result"] == "too deep"
    assert ar.face_pass(a, ar.box_quad(PIC), lambda face: None) == (None, {"face": {"width": 480, "height": 420}, "answer": None})


# --- kind ---------------------------------------------------------------------------------------------------------------------
def test_the_kind_gets_a_second_opinion_for_other_and_disagreements():
    gate = {"flat": 0.1, "box": 0.3, "assembled": 0.2, "other": 0.1}
    assert ar.needs_kind_check(first_pass(kind="other", vlm_kind="other", gate=gate))
    assert ar.needs_kind_check(first_pass(kind="assembled", gate=gate))  # gate says box
    assert not ar.needs_kind_check(first_pass(kind="box_front", gate=gate))
    assert not ar.needs_kind_check(first_pass(kind="flat", method="trim", gate=gate))
    fp = first_pass(kind="assembled", gate=gate)
    assert ar.decide_kind(fp, "box_front") == ("box_front", "second agrees with the gate")
    assert ar.decide_kind(fp, "assembled") == ("assembled", "confirmed")
    assert ar.decide_kind(fp, "flat") == ("assembled", "kept")
    # `other` stays: the second opinion called picture-less products and multi-packs boxes on the tuning set
    assert ar.decide_kind(first_pass(kind="other", vlm_kind="other", gate={"box": 0.5}), "box_front") == ("other", "other kept")


# --- the whole pass -----------------------------------------------------------------------------------------------------------
def test_refine_builds_the_1_1_payload():
    rgb = scene()
    asked = []
    box_gate = {"flat": 0.1, "box": 0.4, "assembled": 0.2, "other": 0.1}
    out = ar.refine(rgb, rgb, first_pass(kind="assembled", gate=box_gate), (W, H), (400, 300),
                    ask_kind=lambda im: asked.append("kind") or "box_front", ask_face=lambda face, kind: asked.append("face") or None)
    assert asked == ["kind", "face"] and out["kind"] == "box_front"
    assert out["firstPass"]["vlmKind"] == "assembled" and out["signals"]["kindCheck"]["second"] == "box_front"
    q = out["quality"]
    assert "small_original" in q["reasons"] and "low_resolution_artwork" in q["reasons"] and q["needsBetterImage"]
    assert "not_digital_artwork" in q["reasons"]  # a box photo is never a perfect catalogue picture
    assert q["originalSize"] == {"width": 400, "height": 300}
    assert out["geometry"]["view"] in ("front", "angled") and 0 <= out["confidence"] <= 1
    assert set(out["bbox"]) == {"x", "y", "w", "h"}


def test_the_stage_reuses_the_stored_first_pass_without_the_gate_or_the_first_vlm_call(tmp_path):
    ws = WorkState(tmp_path / "w.sqlite")  # nothing gated
    stage = aw.ArtworkStage("1.1.0+t", workstate=ws)
    stage._vlm_answer = lambda *a, **k: pytest.fail("the first pass must not run again")
    stage.ask_face = lambda face, kind="box_front": None
    stage.ask_kind = lambda rgb: pytest.fail("no second opinion needed")
    stored = {"kind": "box_front", "bbox": {"x": 0.2, "y": 0.15, "w": 0.6, "h": 0.7}, "method": "vlm",
              "kindScores": {"flat": 0.0, "box": 0.3, "assembled": 0.1, "other": 0.0},
              "signals": {"gateMargin": -0.3, "vlmKind": "box_front", "vlmBbox": {"x": 0.2, "y": 0.15, "w": 0.6, "h": 0.7}}}
    p = Prepared(WorkItem("p", "u", "fp"), LocalImage("p", "fp", "x", "s", W, H, 2000, 1500), scene(), upstream={"artwork": stored})
    out = stage.analyse(p)
    assert out["signals"]["firstPassReused"] and out["kind"] == "box_front"


def test_the_gate_skips_pictures_with_a_stored_result(tmp_path):
    class Api:
        def iter_images(self, **kw):
            yield WorkItem("a", "u", "fa", analyses={"artwork": {"imageFingerprint": "fa", "analyzerVersion": "1.0.0+x"}})
            yield WorkItem("b", "u", "fb", analyses={"artwork": {"imageFingerprint": "old", "analyzerVersion": "1.0.0+x"}})

    class Dl:
        def get(self, pid, fp, url):
            raise RuntimeError("downloaded " + pid)

    import threading

    ws = WorkState(tmp_path / "w.sqlite")
    seen = []
    aw.run_gate(Api(), Dl(), ws, "1.1.0+t", threading.Event(), gate_factory=lambda: seen.append(1))
    # "a" has a result for its picture: skipped; "b" only for an old picture: gated (its download fails here)
    assert ws.gate("fa", aw.GATE_VERSION) is None and seen == []


# --- scoring --------------------------------------------------------------------------------------------------------------------
def test_scoring_counts_a_cut_and_box_print_beyond_the_allowance():
    truth = {"kind": "box_front", "corners": ar.box_quad((0.2, 0.2, 0.8, 0.8)), "outer": {"top": 0.1}}
    exact = score(Candidate("box_front", [0.2, 0.2, 0.8, 0.8], None), truth, 1.0)
    assert not exact["artworkCut"] and not exact["printKept"] and exact["iou"] > 0.95
    cut = score(Candidate("box_front", [0.2, 0.3, 0.8, 0.8], None), truth, 1.0)
    assert cut["artworkCut"] and cut["cut"] == pytest.approx(0.1 / 0.6 + 0.01 * 0.5 / 0.6, abs=0.01)
    allowed = score(Candidate("box_front", [0.2, 0.16, 0.8, 0.8], None), truth, 1.0)  # 4 % of 0.6 above: inside `outer`
    assert not allowed["printKept"]
    loose = score(Candidate("box_front", [0.1, 0.2, 0.8, 0.8], None), truth, 1.0)
    assert loose["printKept"] and not loose["artworkCut"]


def test_the_scored_crop_is_the_one_imaging_crop_cuts():
    poly, how = crop_polygon(Candidate("x", [0.0, 0.0, 1.0, 1.0], None), 1.0)
    assert how == "box" and poly[0] == pytest.approx([0.01, 0.01])
    poly, how = crop_polygon(Candidate("x", [0, 0, 1, 1], [[0.0, 0.1], [1.0, 0.0], [1.0, 1.0], [0.0, 0.9]]), 1.0)
    assert how == "perspective"


# --- crop stages never read an artwork of another version ------------------------------------------------------------------
def test_a_crop_stage_skips_an_artwork_result_of_another_version(tmp_path):
    from puzzle_vision import profiles
    from puzzle_vision.worker import StageWorker

    prof = profiles.load("m3-solo")
    s = prof.analyzers["colors"]
    assert dict(s.upstream_versions) == {"artwork": prof.version("artwork")}
    skipped = []

    class W(StageWorker):
        def __init__(self):  # only what _prepare touches
            self.settings, self.stage, self.workstate = s, type("S", (), {"upstream": ("artwork",), "optional_upstream": (), "uses_crop": True})(), None
            self.analyzer_name = "colors"

        def _skip(self, item, reason):
            skipped.append(reason)

    class Ws:
        def given_up(self, *a):
            return False

    w = W()
    w.workstate = Ws()
    w.downloader = type("D", (), {"get": lambda self, *a: LocalImage("p", "fp", str(tmp_path / "x.png"), "s", 4, 4, 4, 4)})()
    from PIL import Image
    Image.new("RGB", (4, 4)).save(tmp_path / "x.png")
    old = WorkItem("p", "u", "fp", analyses={"artwork": {"analyzerVersion": "1.0.0+siglip2-q3vl4b", "imageFingerprint": "fp"}})
    assert w._prepare(old, None) is None and "is not this profile's" in skipped[0]


def test_a_widened_side_may_be_trimmed_back_further():
    caps = ar.face_caps([0.0, 0.0, 1.0, 1.0], [0.3, 0.1, 0.9, 0.9])
    assert caps["left"] == pytest.approx(ar.FACE_MAX_TRIM + 0.3) and caps["top"] == pytest.approx(ar.FACE_MAX_TRIM + 0.1)
    assert ar.face_caps([0, 0, 1, 1], None) == {}
