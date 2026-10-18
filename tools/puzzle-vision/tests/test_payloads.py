"""Every analyzer of image-taxonomy.md §11 (+ embed_siglip2_full) has an implementation and a payload of the
documented shape, within 64 KB, without number-keyed maps, JSON-clean - built from pure functions and fake model
outputs (no model is loaded)."""

import json

import cv2
import numpy as np
import pytest

from puzzle_vision import derive, stages
from puzzle_vision.analyzers import ALL, DERIVED, RAW
from puzzle_vision.derive import box_text, neighbours, tags, zeroshot
from puzzle_vision.payload import b64_to_vector, check_size, envelope, numeric_key_paths
from puzzle_vision.stages import apple_vision as av
from puzzle_vision.stages import artwork as aw
from puzzle_vision.stages import colors_traits as ct
from puzzle_vision.stages import describe as ds
from puzzle_vision.stages import embeddings as em
from puzzle_vision.stages import segmentation as sg

ENVELOPE = {"schema", "model", "sourceSha256", "crop", "runtimeMs"}


def wrap(result):
    p = envelope(result, schema=1, model="m", source_sha256="a" * 64, crop=None, runtime_ms=12.3)
    check_size(p)
    assert numeric_key_paths(p) == []
    assert json.loads(json.dumps(p, allow_nan=False)) == p
    assert ENVELOPE <= set(p)
    return p


def test_every_analyzer_has_an_implementation():
    assert set(stages.STAGES) == set(RAW)
    assert set(derive.ORDER) == set(DERIVED)
    assert set(ALL) == set(RAW) | set(DERIVED)
    assert "embed_siglip2_full" in stages.STAGES


def letterboxed():
    a = np.full((300, 400, 3), 255, np.uint8)
    rng = np.random.default_rng(0)
    a[40:260, 60:340] = rng.integers(0, 255, (220, 280, 3), dtype=np.uint8)
    return a


def box_photo():
    a = np.full((600, 800, 3), 90, np.uint8)
    q = np.array([[180, 120], [640, 140], [620, 480], [170, 460]], np.int32)
    cv2.fillConvexPoly(a, q, (240, 200, 40))
    cv2.fillConvexPoly(a, (q * 0.98 + [6, 5]).astype(np.int32), (30, 140, 200))
    return a


def test_artwork_trim_payload():
    p = wrap(aw.trim_payload(letterboxed(), {"flat": 0.2, "box": 0.1, "assembled": 0.05, "other": 0.0}, 0.1, (400, 300)))
    assert p["kind"] == "flat" and p["method"] == "trim" and p["corners"] is None
    b = p["bbox"]
    assert set(b) == {"x", "y", "w", "h"} and abs(b["x"] - 0.15) < 0.02 and abs(b["w"] - 0.7) < 0.02
    assert set(p) >= {"kind", "bbox", "corners", "method", "confidence", "kindScores", "source", "signals"}


def test_artwork_vlm_snapped_to_quad():
    a = box_photo()
    vlm = aw.parse_vlm('Here: {"kind": "box_angled", "bbox_2d": [215, 195, 805, 805], "corners": [[225,200],[800,233],[775,800],[212,767]]}')
    assert vlm["kind"] == "box_angled" and vlm["box"][0] == pytest.approx(0.215)
    h = aw.hybrid(a, vlm)
    assert h["method"] == "vlm+quad" and h["quadIou"] > 0.75
    p = wrap(aw.payload(h["kind"], h["box"], h["quad"], h["method"], {"flat": 0.0}, -0.05, (800, 600), vlm, h["quadIou"]))
    assert p["corners"] is not None and len(p["corners"]) == 4
    assert all(0 <= v <= 1 for c in p["corners"] for v in c)
    assert p["bbox"]["x"] + p["bbox"]["w"] <= 1 and p["confidence"] == pytest.approx(h["quadIou"])


def test_artwork_parse_garbage_and_gate_decision():
    assert aw.parse_vlm("no json") == {"kind": None, "box": None, "quad": None}
    flat, margin = aw.gate_decision({"flat": 0.30, "box": 0.25, "assembled": 0.1, "other": 0.0})
    assert flat and margin == 0.05
    assert not aw.gate_decision({"flat": 0.25, "box": 0.245})[0]


def test_ocr_payload_top_left_in_artwork_cap():
    lines = [av.OcrLine("500 PIECES", 0.98, (0.6, 0.91, 0.3, 0.05)), av.OcrLine("Lake", 0.9, (0.4, 0.4, 0.1, 0.05)),
             av.OcrLine("\x00", 0.9, (0, 0, 0.1, 0.1))]
    p = wrap(av.ocr_payload(lines, {"bbox": {"x": 0.1, "y": 0.2, "w": 0.8, "h": 0.7}}, ["en"]))
    assert p["lines"][0]["t"] == "500 PIECES" and p["lines"][0]["b"] == [0.6, 0.04, 0.3, 0.05] and p["lines"][0]["inArtwork"] is False
    assert p["lines"][1]["inArtwork"] is True and p["textAreaShareInArtwork"] > 0 and not p["truncated"]
    assert set(p) >= {"languages", "lines", "truncated", "textAreaShareInArtwork"}
    many = [av.OcrLine(f"line {i}", 0.9, (0.0, i / 1000, 0.01, 0.001)) for i in range(450)]
    q = wrap(av.ocr_payload(many, None, []))
    assert len(q["lines"]) == 400 and q["truncated"] and q["lines"][0]["inArtwork"] is None


def test_barcode_payload():
    p = wrap(av.barcode_payload([("VNBarcodeSymbologyEAN13", "4005556173709", (0.1, 0.1, 0.2, 0.05)),
                                 ("VNBarcodeSymbologyEAN13", "4005556173708", (0.1, 0.1, 0.2, 0.05)),
                                 ("VNBarcodeSymbologyQR", "https://x", (0, 0, 0.1, 0.1))]))
    assert [c["checksumOk"] for c in p["codes"]] == [True, False, None]
    assert p["codes"][0] == {"sym": "EAN13", "v": "4005556173709", "b": [0.1, 0.85, 0.2, 0.05], "checksumOk": True, "reader": "apple"}


def test_vision_labels_payload():
    p = wrap(av.vision_labels_payload([("sky", 0.78), ("tiny", 0.05)] + [(f"l{i}", 0.5) for i in range(50)], [(0.012, 0.9)], 3, [(0.1, 0.5, 0.4, 0.3)]))
    assert len(p["labels"]) == 40 and p["labels"][0] == ["sky", 0.78] and p["humans"] == 3
    assert p["faces"] == [{"area": 0.012, "c": 0.9}] and p["saliency"]["bbox"] == [0.1, 0.2, 0.4, 0.3]


@pytest.mark.parametrize("cls", [em.Siglip2Stage, em.Siglip2FullStage])
def test_siglip_payloads_carry_one_vector(cls):
    v = np.random.default_rng(1).normal(size=1152)
    p = wrap(em.siglip_payload(v))
    assert set(p) - ENVELOPE == {"dim", "dtype", "norm", "vector", "preprocess"} and p["dim"] == 1152
    back = b64_to_vector(p["vector"])
    assert back.shape == (1152,) and abs(np.linalg.norm(back) - 1) < 1e-2
    assert np.allclose(back, v / np.linalg.norm(v), atol=2e-3)
    assert len(p["vector"]) == 3072
    assert cls.uses_crop == (cls.analyzer == "embed_siglip2") and cls.upstream == (("artwork",) if cls.uses_crop else ())


def test_dinov2_payload():
    p = wrap(em.dinov2_payload(np.ones(1024)))
    assert p["dim"] == 1024 and "cls" in p and p["preprocess"] == "224 short side, centre"


def test_segmentation_summary():
    ids = np.zeros((64, 64), np.int32)
    ids[:32] = 1   # sky (stuff)
    ids[40:50, 10:20] = 2  # person
    ids[40:50, 30:40] = 3  # person
    p = wrap(sg.summarise(ids, [{"id": 1, "label_id": 119}, {"id": 2, "label_id": 0}, {"id": 3, "label_id": 0}], {119: "sky-other-merged", 0: "person"}))
    assert p["classes"]["sky-other-merged"] == 0.5 and p["instances"] == {"person": 2}
    assert len(p["grid16"]) == 256 and p["grid16"][0] == 119 and p["grid16"][-1] == 255 and p["classList"] == "coco-panoptic-133"


def test_colors_and_traits_on_a_real_image():
    a = cv2.resize(letterboxed(), (240, 180))
    c = wrap(ct.colors(a))
    assert {"palette", "families", "dominantShare", "paletteEffectiveColors", "colorEntropyBits", "colorfulness", "lightnessMean",
            "lightnessStd", "darkShare", "lightShare", "chromaMean", "mutedShare"} <= set(c)
    assert len(c["palette"]) == 8 and abs(sum(x["share"] for x in c["palette"]) - 1) < 0.01
    t = wrap(ct.traits(a, pieces=1000))
    assert {"edgeDensity", "flatShare", "uniformShare", "largestColorRegion", "pieceGrid", "pieceFlatShare", "pieceLookalikeShare",
            "pieceDistinctiveness", "regularity", "symmetry", "lineStrength", "grid16", "borderBand", "pieceLevel", "textShare"} <= set(t)
    assert set(t["pieceLevel"]) == {"real", "p500", "p1000"} and t["pieceLevel"]["real"]["pieces"] == 1000
    assert {k: len(v) for k, v in t["grid16"].items()} == {"flat": 256, "lightness": 256, "chroma": 256, "gradient": 256}
    assert t["textShare"] is None  # Apple Vision is not available in the test environment
    assert 0 <= t["symmetry"] <= 1 and 0 <= t["lineStrength"] <= 1


MODEL_ANSWER = json.dumps({
    "caption": "A lake below snowy mountains.", "altText": "Mountain lake", "themes": ["landscape_nature", "travel_places"],
    "subjects": ["Mountains", "lake", "Pine Trees", "mountain"], "scene": "landscape", "style": "photo", "season": "winter",
    "holiday": "none", "time": "day", "weather": "partly_cloudy", "mood": ["serene"], "place": "Banff, Canada",
    "characters": [], "artistSignature": "none", "people": "none", "textInArtwork": "none",
    "counts": {"distinctObjects": 4, "animals": 0, "people": 0}, "areas": {"sky": 30, "water": 25, "foliageGrass": 35, "buildings": 0, "plainBackground": 10},
    "collageGrid": False, "seekAndFind": False, "extraTags": ["alpine"]})


def test_describe_payload():
    p = wrap(ds.build_payload(MODEL_ANSWER, ["BANFF"], "Moraine Lake", {"prompt": 1200, "generation": 300}))
    assert p["subjects"] == ["mountain", "lake", "pine tree"] and p["artistSignature"] is None and p["place"] == "Banff, Canada"
    assert p["characters"] == [] and "specialHints" not in p and p["extraTags"] == ["alpine"]
    assert p["promptVersion"] == "v2.1" and p["titleContext"] is True and p["ocrLines"] == 1
    assert list(p)[5:5 + len(ds.KEYS)] == ds.KEYS


def test_box_text_payload():
    p = wrap(box_text.parse(["Ravensburger", "1000 Teile", "70 x 50 cm", "Art. 17370", "4005556173709", "Ab 14 Jahren", "© 2017 Somebody", "Glow in the dark"],
                            [{"v": "4005556173709", "checksumOk": True}], ["Ravensburger", "Brandy"], ["de"]))
    assert p["pieces"] == [{"v": 1000, "evidence": "1000 Teile"}] and p["dimensionsCm"] == [70, 50]
    assert p["eans"] == ["4005556173709"] and p["productCodes"] == ["17370"] and p["brandMentions"] == ["Ravensburger"]
    assert p["ageMin"] == 14 and p["specialMentions"] == ["glow_in_dark"] and p["copyright"] == ["© 2017 Somebody"]
    assert set(p) == {"pieces", "multiPack", "dimensionsCm", "eans", "productCodes", "brandMentions", "ageMin", "specialMentions",
                      "seriesMentions", "copyright", "languages"} | ENVELOPE


def test_box_text_multi_pack_and_other_languages():
    p = box_text.parse(["2 x 500 pièces", "Wasgij Mystery", "3000 dílků"], [], [], [])
    assert p["multiPack"] == {"count": 2, "pieces": 500} and "multi_pack" in p["specialMentions"] and "wasgij" in p["specialMentions"]
    assert [x["v"] for x in p["pieces"]] == [500, 3000] and p["seriesMentions"] == ["Wasgij"]


def test_zeroshot_payload():
    keys, prompts = zeroshot.bank()
    assert len(keys) >= 230 and all(prompts)
    rng = np.random.default_rng(0)
    vec = rng.normal(size=(6, 16)).astype(np.float32)
    text = rng.normal(size=(len(keys), 16)).astype(np.float32)
    out = zeroshot.compute([f"p{i}" for i in range(6)], vec, ["crop"] * 6, keys, text)
    p = wrap(out["p0"])
    assert p["scale"] == "percentile" and p["taxonomy"] == "1.0.0" and len(p["scores"]) == len(keys)
    assert all(0 <= s <= 1 for s in p["scores"].values())
    col = [out[f"p{i}"]["scores"][keys[0]] for i in range(6)]
    assert sorted(col) == [0.0, 0.2, 0.4, 0.6, 0.8, 1.0]


def test_neighbours_payload():
    rng = np.random.default_rng(0)
    ids = [f"0190-{i:03d}" for i in range(40)]
    v = rng.normal(size=(40, 8)).astype(np.float32)
    v /= np.linalg.norm(v, axis=1, keepdims=True)
    out = neighbours.compute(ids, v, ids[:10], v[:10])
    p = wrap(out[ids[0]])
    assert len(p["siglip"]) == 30 and len(p["dinov2"]) == 9 and p["corpusSize"] == 40
    assert all(n[0] != ids[0] for n in p["siglip"]) and p["siglip"][0][1] >= p["siglip"][-1][1]
    assert not neighbours.changed(p, out[ids[0]])


def test_tags_payload():
    d = ds.postprocess(json.loads(MODEL_ANSWER))
    p_in = {
        "describe": d, "zeroshot": {"scores": {"theme.landscape_nature": 0.9, "theme.landscape_nature.mountains": 0.95, "style.photo": 0.9,
                                               "scene.landscape": 0.8, "holiday.christmas": 0.1}},
        "traits": {"uniformShare": 0.3, "pieceFlatShare": 0.1, "regularity": 0.05, "edgeDensity": 0.1, "colorRegions80": 20,
                   "lineStrength": 0.1, "symmetry": 0.2, "borderBand": {"uniformShare": 0.6}, "pieceDistinctiveness": 0.05,
                   "pieceLookalikeShare": 0.2, "smoothGradientShare": 0.2},
        "colors": {"paletteEffectiveColors": 4, "colorfulness": 50, "darkShare": 0.1, "lightShare": 0.2, "lightnessStd": 0.2},
        "segmentation": {"classes": {"sky-other-merged": 0.3, "tree-merged": 0.2}, "instances": {}},
        "vision_labels": {"faces": [], "humans": 0}, "artwork": {"kind": "flat", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}, "source": {"width": 1500, "height": 1000}},
        "ocr": {"lines": [], "textAreaShareInArtwork": 0.0},
    }
    corpus = tags.Corpus.build([tags.trait_values(p_in)])
    p = wrap(tags.fuse(p_in, corpus, box_text={"specialMentions": ["glow_in_dark"]}))
    a = p["assigned"]
    assert {"theme.landscape_nature", "theme.travel_places", "theme.landscape_nature.mountains", "style.photo", "scene.landscape",
            "season.winter", "time.day", "mood.serene", "people.none", "special.glow_in_dark", "image.flat_artwork",
            "trait.large_uniform_areas", "trait.large_sky", "trait.uniform_border", "trait.smooth_gradients"} <= set(a)
    assert "holiday.christmas" not in a and p["entities"]["place"] == "Banff, Canada"
    assert set(p["skills"]) >= {"skill.shape_sorting", "skill.edge_start"} and all(0 <= v <= 1 for v in p["skills"].values())
    assert p["traits"]["trait.large_sky"] == 0.3
