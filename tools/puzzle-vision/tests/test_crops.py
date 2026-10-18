import io

import cv2
import numpy as np
import pytest
from PIL import Image

from puzzle_vision.proposals import crops

from .conftest import pid


def photo(w=1600, h=1200, rng_seed=0):
    rng = np.random.default_rng(rng_seed)
    a = np.full((h, w, 3), 120, np.uint8)
    a[:] = rng.integers(100, 140, (h, w, 3), dtype=np.uint8)
    art = rng.integers(0, 255, (h // 60, w // 60, 3), dtype=np.uint8)
    art = cv2.resize(art, (int(w * 0.6), int(h * 0.6)), interpolation=cv2.INTER_CUBIC)  # no straight lines inside
    y0, x0 = int(h * 0.2), int(w * 0.2)
    a[y0:y0 + art.shape[0], x0:x0 + art.shape[1]] = art
    return a


def jpeg(a):
    buf = io.BytesIO()
    Image.fromarray(a).save(buf, "JPEG", quality=95)
    return buf.getvalue()


def test_edge_straightness_finds_a_real_edge_and_not_noise():
    a = photo()
    assert crops.edge_straightness(a, [0.2, 0.2, 0.8, 0.8]) > 0.7
    assert crops.edge_straightness(a, [0.3, 0.3, 0.7, 0.7]) < 0.1  # inside the picture, no frame edge
    assert crops.edge_straightness(a, [0.23, 0.23, 0.77, 0.77]) < 0.1  # 3 % off
    noise = np.random.default_rng(3).integers(0, 255, (800, 800, 3), dtype=np.uint8)
    assert crops.edge_straightness(noise, [0.2, 0.2, 0.8, 0.8]) < 0.3


def test_aspect_fill_and_combine():
    assert crops.aspect_plausibility(700, 500) == 1.0 and crops.aspect_plausibility(980, 330) == 1.0
    assert crops.aspect_plausibility(1000, 1000) == 1.0 and crops.aspect_plausibility(1000, 600) < 0.2
    assert crops.fill_score({"w": 0.6, "h": 0.6}, 1300) > crops.fill_score({"w": 0.3, "h": 0.3}, 700)
    assert crops.combine({k: 1.0 for k in crops.WEIGHTS}) == 1.0
    low = crops.combine({"iou": 0.95, "gate": 0.95, "straight": 0.05, "aspect": 1, "fill": 1})
    assert low < 0.6  # one weak signal pulls it down
    assert crops.gate_probability(0.0) == 0.5 and crops.gate_probability(0.1) > 0.99
    assert crops.iou_signal({"method": "vlm+quad", "signals": {"quadIou": 0.93}}) == 0.93
    assert crops.iou_signal({"method": "vlm", "signals": {}}) == 0.4


def artwork(conf_quad=0.95):
    return {"kind": "box_front", "method": "vlm+quad", "bbox": {"x": 0.2, "y": 0.2, "w": 0.6, "h": 0.6}, "corners": None,
            "signals": {"quadIou": conf_quad}}


def test_build_renders_the_crop_from_the_original():
    a = photo()
    prop = crops.build(type("I", (), {"puzzle_id": "p", "fingerprint": "f"})(), artwork(), jpeg(a), lambda rgb: 0.08)
    assert prop is not None and prop.image[:2] == b"\xff\xd8"
    assert abs(prop.width - 950) < 15 and abs(prop.height - 712) < 15
    assert prop.confidence > 0.8 and set(prop.signals) == set(crops.WEIGHTS)


def test_propose_files_change_requests_once(api, server):
    for i in range(3):
        p = pid(i)
        server.images[f"img-{i}.png"] = jpeg(photo(rng_seed=i))
        server.analyses[("artwork", p)] = {"puzzleId": p, "analyzer": "artwork", "analyzerVersion": "1.0.0+siglip2-q3vl4b",
                                           "imageFingerprint": server.puzzles[p]["imageFingerprint"], "analyzedAt": None, "receivedAt": "x",
                                           "result": artwork() if i != 2 else {"kind": "flat", "method": "trim", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}}}
    fetch = lambda url: server.images[url.rsplit("/", 1)[1]]  # noqa: E731
    out = crops.propose(api, fetch, lambda rgb: 0.08, "1.0.0+siglip2-q3vl4b", limit=10)
    assert out["candidates"] == 2 and out["proposed"] == 2
    cr = server.change_requests[0]
    assert cr["source"] == "image_pipeline" and cr["tags"] == ["artwork_crop"] and cr["sourceImageFingerprint"] == server.puzzles[pid(0)]["imageFingerprint"]
    assert cr["modelVersion"] == "artwork-crop 1.0.0 (1.0.0+siglip2-q3vl4b)" and 0.6 <= cr["confidence"] <= 1
    again = crops.propose(api, fetch, lambda rgb: 0.08, "1.0.0+siglip2-q3vl4b", limit=10)
    assert again["candidates"] == 0 and len(server.change_requests) == 2


def test_low_confidence_proposes_nothing(api, server):
    p = pid(0)
    server.images["img-0.png"] = jpeg(photo())
    server.analyses[("artwork", p)] = {"puzzleId": p, "analyzer": "artwork", "analyzerVersion": "v", "imageFingerprint": server.puzzles[p]["imageFingerprint"],
                                       "analyzedAt": None, "receivedAt": "x", "result": artwork(conf_quad=0.2)}
    out = crops.propose(api, lambda url: server.images[url.rsplit("/", 1)[1]], lambda rgb: -0.1, "v", limit=5)
    assert out["low_confidence"] == 1 and not server.change_requests
