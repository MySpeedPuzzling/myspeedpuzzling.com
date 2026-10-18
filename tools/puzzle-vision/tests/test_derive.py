import numpy as np
import pytest

from puzzle_vision.derive import Deriver, difficulty_prior, needs_push
from puzzle_vision.derive.taxonomy import prompt_bank
from puzzle_vision.payload import vector_to_b64

from .conftest import pid
from .test_payloads import MODEL_ANSWER

VERSIONS = {"box_text": "1.0.0+rules", "zeroshot": "1.0.0+so400m@tax1.0.0", "neighbours": "1.0.0+siglip2-dinov2l", "tags": "1.0.0+fusion@tax1.0.0"}


def put(server, analyzer, p, result, version="v1"):
    server.analyses[(analyzer, p)] = {"puzzleId": p, "analyzer": analyzer, "analyzerVersion": version, "imageFingerprint": server.puzzles[p]["imageFingerprint"],
                                      "analyzedAt": None, "receivedAt": "x", "result": {"sourceSha256": "s" * 64, **result}}


@pytest.fixture
def filled(server):
    import json

    rng = np.random.default_rng(0)
    for i in range(5):
        p = pid(i)
        put(server, "ocr", p, {"lines": [{"t": "1000 Teile", "c": 0.9, "b": [0.1, 0.1, 0.2, 0.05]}, {"t": "Brandy", "c": 0.9, "b": [0, 0, 0.1, 0.1]}], "languages": ["de"]})
        put(server, "barcode", p, {"codes": [{"sym": "EAN13", "v": "4005556173709", "checksumOk": True}]})
        put(server, "embed_siglip2", p, {"dim": 16, "vector": vector_to_b64(rng.normal(size=16))})
        put(server, "embed_dinov2", p, {"dim": 8, "cls": vector_to_b64(rng.normal(size=8))})
        put(server, "describe", p, json.loads(MODEL_ANSWER))
        put(server, "artwork", p, {"kind": "flat", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}, "source": {"width": 1000, "height": 800}})
    put(server, "embed_siglip2_full", pid(0), {"dim": 16, "vector": vector_to_b64(rng.normal(size=16))})
    return server


def fake_text(keys, prompts):
    return np.random.default_rng(1).normal(size=(len(keys), 16)).astype(np.float32)


def test_derive_computes_and_pushes_every_derived_analyzer_then_only_changes(api, filled, tmp_path):
    d = Deriver(api, VERSIONS, tmp_path, text_features=fake_text)
    summary = d.run()
    for a in VERSIONS:
        assert summary[a]["stored"] == 5, a
        row = filled.analyses[(a, pid(0))]
        assert row["analyzerVersion"] == VERSIONS[a] and row["result"]["schema"] == 1 and row["result"]["crop"] is None
    bt = filled.analyses[("box_text", pid(1))]["result"]
    assert bt["pieces"][0]["v"] == 1000 and bt["brandMentions"] == ["Brandy"] and bt["eans"] == ["4005556173709"]
    assert len(filled.analyses[("zeroshot", pid(0))]["result"]["scores"]) == len(prompt_bank())
    assert len(filled.analyses[("neighbours", pid(0))]["result"]["siglip"]) == 4
    assert "theme.landscape_nature" in filled.analyses[("tags", pid(2))]["result"]["assigned"]
    again = Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run()
    assert all(again[a]["changed"] == 0 for a in VERSIONS)


def test_dry_run_pushes_nothing(api, filled, tmp_path):
    summary = Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run(only=["box_text"], dry_run=True)
    assert summary["box_text"]["changed"] == 5 and ("box_text", pid(0)) not in filled.analyses


def test_a_stale_input_picture_is_left_out(api, filled, tmp_path):
    filled.change_picture(pid(3), "ffffffffffffffff")
    Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run(only=["box_text"])
    assert ("box_text", pid(3)) not in filled.analyses


def test_needs_push_rules():
    assert needs_push("tags", None, "v", "f", {"a": 1})
    old = {"analyzerVersion": "v", "imageFingerprint": "f", "result": {"schema": 1, "runtimeMs": 3, "a": 1}}
    assert not needs_push("tags", old, "v", "f", {"a": 1})
    assert needs_push("tags", old, "v2", "f", {"a": 1}) and needs_push("tags", old, "v", "g", {"a": 1})
    zs = {"analyzerVersion": "v", "imageFingerprint": "f", "result": {"scores": {"k": 0.5}}}
    assert not needs_push("zeroshot", zs, "v", "f", {"scores": {"k": 0.51}}) and needs_push("zeroshot", zs, "v", "f", {"scores": {"k": 0.6}})


def test_difficulty_prior_refuses_until_phase_5(api, filled, tmp_path):
    filled.phase5 = False  # a production without the Phase 5 endpoints
    with pytest.raises(difficulty_prior.NotAvailableYet, match="not deployed yet"):
        difficulty_prior.run(api)
    summary = Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run(only=["difficulty_prior"])
    assert "not deployed yet" in summary["difficulty_prior"]["skipped"]
    assert not any(r["path"].startswith("/internal-api/puzzle-difficulty-estimates") for r in filled.requests if r["method"] != "GET")


def test_difficulty_prior_refuses_even_when_the_endpoints_exist(api, filled):
    with pytest.raises(difficulty_prior.NotAvailableYet, match="still a stub"):
        difficulty_prior.run(api)
    assert not any(r["method"] in ("PUT", "POST") and r["path"].startswith("/internal-api/puzzle-difficulty-estimates") for r in filled.requests)
