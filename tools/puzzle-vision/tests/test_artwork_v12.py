"""`artwork` 1.2: the no-edge fallback of the face pass, the re-used VLM answers, the stored hypotheses, SAM 2.1's
agreement rule, the whole-image probe signal, the version switch and the catalogue replay."""

import base64
import json

import numpy as np
import pytest

from puzzle_vision import artwork_replay as rp
from puzzle_vision.api import WorkItem
from puzzle_vision.download import LocalImage
from puzzle_vision.stages import artwork as aw
from puzzle_vision.stages import artwork_refine as ar
from puzzle_vision.stages.artwork_check import lab_image
from puzzle_vision.stages.artwork_sam import mask_edges
from puzzle_vision.stages.base import Prepared
from puzzle_vision.workstate import WorkState

from .test_artwork_v11 import H, PIC, W, first_pass, scene


# --- the no-edge fallback ----------------------------------------------------------------------------------------------
def test_a_side_on_the_vlms_line_without_an_edge_goes_no_deeper_than_the_first_pass():
    a = scene()
    face = ar.box_quad(PIC)
    first = (PIC[0], PIC[1] + 0.05 * (PIC[3] - PIC[1]), PIC[2], PIC[3])  # the first pass cut 5 % of the face on top
    new, info = ar.face_pass(a, face, lambda f: [0.0, 0.2, 1.0, 1.0], held_at=first)
    top = info["sides"]["top"]
    assert top["result"] == ar.HELD and top["vlmTrim"] == pytest.approx(0.2) and top["trim"] == pytest.approx(0.05, abs=0.005)
    assert ar.quad_to_box(new)[1] == pytest.approx(first[1], abs=0.005)  # the outer of the two lines
    assert info["heldAt"]["top"] == pytest.approx(0.05, abs=0.005)
    # the first pass's side further in than the VLM's: the VLM's line stays (the outer of the two)
    deeper = (PIC[0], PIC[1] + 0.3 * (PIC[3] - PIC[1]), PIC[2], PIC[3])
    _, info = ar.face_pass(a, face, lambda f: [0.0, 0.2, 1.0, 1.0], held_at=deeper)
    assert info["sides"]["top"]["result"] == "the VLM's line" and info["sides"]["top"]["trim"] == pytest.approx(0.2)
    # the first pass's side at (or outside) the face: nothing is trimmed on that side
    new, info = ar.face_pass(a, face, lambda f: [0.0, 0.2, 1.0, 1.0], held_at=PIC)
    assert new is None and info["sides"]["top"]["result"] == ar.HELD and "trim" not in info["sides"]["top"]


def test_a_side_snapped_to_a_real_edge_is_not_held():
    a = scene(band=(0.15, 0.27))
    new, info = ar.face_pass(a, ar.box_quad(PIC), lambda f: [0.0, 0.17, 1.0, 1.0], held_at=PIC)
    assert info["sides"]["top"]["result"] == "trimmed" and new is not None


def test_trims_in_face_of_an_upright_face_are_the_box_sides():
    a = scene()
    face, h = ar.straighten(a, ar.box_quad(PIC))
    fh, fw = face.shape[:2]
    t = ar.trims_in_face([0.26, 0.22, 0.74, 0.78], h, (fw, fh), (W, H))
    assert t["left"] == pytest.approx(0.1, abs=0.005) and t["top"] == pytest.approx(0.1, abs=0.005)
    assert t["right"] == pytest.approx(0.1, abs=0.005) and t["bottom"] == pytest.approx(0.1, abs=0.005)
    assert ar.trims_in_face([0.0, 0.0, 1.0, 1.0], h, (fw, fh), (W, H))["top"] < 0  # outside the face


def test_the_1_1_rules_stay_exactly_1_1():
    rgb = scene()
    fp = first_pass(kind="box_front", gate={"flat": 0.0, "box": 0.4, "assembled": 0.1, "other": 0.0},
                    vlm_bbox=(PIC[0], PIC[1] + 0.05 * (PIC[3] - PIC[1]), PIC[2], PIC[3]))
    ask = lambda face, kind: [0.0, 0.2, 1.0, 1.0]  # noqa: E731
    old = ar.refine(rgb, rgb, fp, (W, H), (2000, 1500), ask_face=ask, v12=False)
    new = ar.refine(rgb, rgb, fp, (W, H), (2000, 1500), ask_face=ask)
    assert "decidedBy" not in old["signals"] and "conservative" not in old["signals"]
    assert old["signals"]["face"]["sides"]["top"]["result"] == "the VLM's line"
    assert new["signals"]["face"]["sides"]["top"]["result"] == ar.HELD
    assert new["bbox"]["y"] < old["bbox"]["y"]  # 1.2 cuts less deep where no edge confirms the VLM
    assert new["signals"]["decidedBy"]["top"] == "first pass"
    c = new["signals"]["conservative"]
    assert c["y"] <= min(new["bbox"]["y"], fp["bbox"][1]) + 1e-6


def test_the_version_switches_the_rules():
    assert aw.rules_from("1.1.0+siglip2-q3vl4b") == (1, 1) and aw.rules_from("1.2.0+siglip2-q3vl4b") == (1, 2)
    assert aw.rules_from("eval") >= (1, 2)
    assert not aw.ArtworkStage("1.1.0+x").v12 and aw.ArtworkStage("1.1.0+x").fetch_optional == ("artwork",)
    assert aw.ArtworkStage("1.2.0+x").v12 and "embed_siglip2_full" in aw.ArtworkStage("1.2.0+x").fetch_optional


# --- re-used VLM answers ---------------------------------------------------------------------------------------------
def stored_1_1(rgb):
    """What 1.1 stored for a box photo: its first pass, the kind check and the face pass's answer for its outline."""
    fp = first_pass(kind="box_front", gate={"flat": 0.0, "box": 0.4, "assembled": 0.1, "other": 0.0})
    return ar.refine(rgb, rgb, fp, (W, H), (2000, 1500), ask_face=lambda face, kind: [0.05, 0.2, 0.95, 0.95], v12=False)


def test_a_rerun_reuses_the_stored_answers_for_the_same_question():
    rgb = scene()
    prior = stored_1_1(rgb)
    out = ar.refine(rgb, rgb, ar.first_pass_from(prior), (W, H), (2000, 1500),
                    ask_face=lambda face, kind: pytest.fail("the stored answer must be used"), prior=prior)
    assert out["signals"]["vlmReused"] == {"kind": 0, "face": 1}
    assert out["signals"]["face"]["answer"] == prior["signals"]["face"]["answer"]


def test_a_changed_face_outline_asks_the_model_again():
    answers = ar.PriorAnswers({"signals": {"face": {"outline": [[0, 0], [1, 0], [1, 1], [0, 1]], "answer": [0, 0.1, 1, 1]},
                                           "kindCheck": {"second": "box_front"}}})
    asked = []
    same = answers.face_for([[0, 0], [1, 0], [1, 1], [0, 1]], lambda f: asked.append(1) or [0, 0, 1, 1])
    other = answers.face_for([[0.1, 0], [1, 0], [1, 1], [0.1, 1]], lambda f: asked.append(1) or [0, 0, 1, 1])
    assert same(None) == [0, 0.1, 1, 1] and other(None) == [0, 0, 1, 1] and asked == [1]
    assert answers.ask_kind(lambda rgb: pytest.fail("stored"))(None) == "box_front"
    assert ar.PriorAnswers(None).ask_kind(lambda rgb: "flat")(None) == "flat"  # nothing stored: the model answers
    assert ar.PriorAnswers({"signals": {"kindCheck": {"second": None}}}).ask_kind(lambda rgb: "flat")(None) is None  # a stored "no answer" too


def test_the_stage_reuses_the_stored_answers_and_scores_the_probe(tmp_path):
    rgb = scene()
    prior = stored_1_1(rgb)
    stage = aw.ArtworkStage("1.2.0+t", workstate=WorkState(tmp_path / "w.sqlite"))
    stage._generate = lambda *a, **k: pytest.fail("no model needed: every question was asked by 1.1")
    coef = np.frombuffer(base64.b64decode(json.loads(aw.FLAT_PROBE_PATH.read_text())["coef"]), dtype="<f4")
    vec = base64.b64encode(coef.astype("<f2").tobytes()).decode("ascii")  # a vector along the probe: very "flat"
    p = Prepared(WorkItem("p", "u", "fp"), LocalImage("p", "fp", "x", "s", W, H, 2000, 1500), rgb,
                 upstream={"artwork": prior, "embed_siglip2_full": {"vector": vec}})
    out = stage.analyse(p)
    assert out["signals"]["vlmReused"]["face"] == 1 and out["signals"]["flatProbe"] > 2
    assert out["kind"] == "box_front"  # the probe is a signal, never the kind
    assert aw.flat_probe(None) is None and aw.flat_probe({"vector": base64.b64encode(np.zeros(3, "<f2").tobytes()).decode()}) is None


# --- SAM 2.1 --------------------------------------------------------------------------------------------------------
def test_mask_edges_measures_each_side_of_a_mask():
    m = np.zeros((200, 100), bool)
    m[20:180, 10:95] = True
    e = mask_edges(m)
    assert e["top"] == pytest.approx(0.1) and e["bottom"] == pytest.approx(0.1) and e["left"] == pytest.approx(0.1)
    assert e["right"] == pytest.approx(0.05) and e["spread"]["top"] == 0 and e["area"] == pytest.approx(0.68)
    assert mask_edges(np.zeros((10, 10), bool)) is None
    ragged = m.copy()
    ragged[20:60, 10:50] = False
    assert mask_edges(ragged)["spread"]["top"] > 0.05


def test_sam_keeps_the_vlms_line_only_when_mask_step_and_line_agree():
    a = scene(band=(0.15, 0.27))
    face, _ = ar.straighten(a, ar.box_quad(PIC))
    lab = lab_image(face)
    t = (0.27 - 0.15) / 0.70  # the band's lower edge, as a trim of the face
    good = {"left": 0.0, "top": t, "right": 0.0, "bottom": 0.0, "score": 0.95, "spread": {"top": 0.002}}
    assert ar.sam_agreement(lab, good, "top", t) == pytest.approx(t, abs=0.01)
    assert ar.sam_agreement(lab, dict(good, top=t + 0.03), "top", t) is None  # the mask elsewhere
    assert ar.sam_agreement(lab, dict(good, score=0.5), "top", t) is None  # SAM unsure of its mask
    assert ar.sam_agreement(lab, dict(good, spread={"top": 0.05}), "top", t) is None  # a ragged edge
    plain = lab_image(ar.straighten(scene(), ar.box_quad(PIC))[0])
    assert ar.sam_agreement(plain, good, "top", t) is None  # no colour step on the line


def test_sam_is_asked_once_per_face_and_only_for_a_held_side(monkeypatch):
    monkeypatch.setattr(ar, "SAM_SIDES", True)
    a = scene()
    asked = []
    first = (PIC[0], PIC[1] + 0.05 * (PIC[3] - PIC[1]), PIC[2], PIC[3])
    ar.face_pass(a, ar.box_quad(PIC), lambda f: [0.05, 0.2, 0.95, 1.0], held_at=first,
                 ask_mask=lambda face, rect: asked.append(rect) or None)
    assert len(asked) == 1 and asked[0][1] == pytest.approx(0.05, abs=0.005)  # prompted with the first pass's box
    asked.clear()
    ar.face_pass(scene(band=(0.15, 0.27)), ar.box_quad(PIC), lambda f: [0.0, 0.17, 1.0, 1.0], held_at=PIC,
                 ask_mask=lambda face, rect: asked.append(rect) or None)
    assert asked == []  # the side snapped to an edge: nothing held, SAM not needed


# --- the catalogue replay ---------------------------------------------------------------------------------------------
def test_the_replay_reports_a_changed_crop_without_any_model(tmp_path):
    from PIL import Image

    rgb = scene()
    prior = stored_1_1(rgb)
    (tmp_path / "ab").mkdir()
    Image.fromarray(rgb).save(tmp_path / "ab" / "abcd.jpg", quality=98)
    row = {"puzzleId": "p", "imageFingerprint": "abcd", "result": prior}
    out = rp.replay_one(row, str(tmp_path))
    assert out["status"] == "replayed" and out["oldMethod"] == prior["method"]
    assert out["changed"] and out["iou"] < 1.0  # the no-edge top side moved back out to the first pass
    assert out["result"]["signals"]["vlmReused"]["face"] == 1
    assert rp.replay_one(dict(row, imageFingerprint="ffff"), str(tmp_path))["status"] == "no image"
    s = rp.summarise([{k: v for k, v in out.items() if k != "result"}], 10)
    assert s["changed"] == 1 and s["status"] == {"replayed": 1}


def test_a_grown_face_keeps_both_questions_for_the_next_rerun():
    rgb = scene()
    fp = first_pass(kind="box_front", gate={"flat": 0.0, "box": 0.4, "assembled": 0.1, "other": 0.0})
    asked = []
    first = ar.refine(rgb, rgb, fp, (W, H), (2000, 1500), ask_face=lambda face, kind: asked.append(1) or [0.0, 0.2, 1.0, 1.0])
    assert "+grow" in first["method"] and len(asked) == 2 and first["signals"]["face"]["beforeGrow"]["answer"] is not None
    again = ar.refine(rgb, rgb, ar.first_pass_from(first), (W, H), (2000, 1500),
                      ask_face=lambda face, kind: pytest.fail("both answers are stored"), prior=first)
    assert again["signals"]["vlmReused"]["face"] == 2 and again["bbox"] == first["bbox"]
