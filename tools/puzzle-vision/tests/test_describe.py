import json

import pytest

from puzzle_vision.stages import describe as ds
from puzzle_vision.verify import compare, describe_parity
from puzzle_vision.workstate import WorkState

from .test_payloads import MODEL_ANSWER


def test_schema_follows_the_bake_off():
    s = ds.json_schema()
    assert list(s["properties"]) == ds.KEYS and s["required"] == ds.KEYS and s["additionalProperties"] is False
    p = s["properties"]
    assert "none" in p["time"]["enum"] and "none" in p["weather"]["enum"]
    assert p["characters"]["type"] == "array" and p["characters"]["maxItems"] == 6
    assert "specialHints" not in p and p["extraTags"]["maxItems"] == 8
    assert p["themes"]["maxItems"] == 3 and len(p["themes"]["items"]["enum"]) == 23
    assert p["caption"]["maxLength"] == 600 and p["altText"]["maxLength"] == 140


def test_prompt_v2_with_the_brand_line():
    assert "never the puzzle brand or publisher" in ds.SYSTEM_PROMPT
    assert "licensed_entertainment (ONLY recognisable franchise characters" in ds.SYSTEM_PROMPT
    assert ds.CROP_SIDE == 512 and ds.MAX_TOKENS == 700


def test_user_message_title_and_ocr_lines_inside_the_crop():
    ocr = {"lines": [{"t": "RAVENSBURGER", "c": 0.99, "b": [0.0, 0.0, 0.5, 0.05]},          # outside the crop
                     {"t": "Moraine Lake", "c": 0.9, "b": [0.3, 0.5, 0.3, 0.05]},
                     {"t": "x", "c": 0.9, "b": [0.3, 0.6, 0.3, 0.05]},                     # too short
                     {"t": "blurry", "c": 0.2, "b": [0.3, 0.7, 0.3, 0.05]}]
           + [{"t": f"line {i}", "c": 0.9, "b": [0.3, 0.3, 0.01, 0.01]} for i in range(20)]}
    art = {"bbox": {"x": 0.1, "y": 0.2, "w": 0.8, "h": 0.7}}
    lines = ds.ocr_lines_in_crop(ocr, art)
    assert lines[0] == "Moraine Lake" and "RAVENSBURGER" not in lines and len(lines) == 12
    text = ds.user_text("Moraine Lake 1000", lines[:2])
    assert text.splitlines() == ["Catalogue title (may not describe the picture): Moraine Lake 1000",
                                 "Text read inside the picture (OCR, may contain errors): Moraine Lake / line 0",
                                 "Return the JSON object."]
    assert ds.messages(None, [])[1]["content"] == "Return the JSON object."


@pytest.mark.parametrize("word,expected", [("Cats", "cat"), ("butterflies", "butterfly"), ("Pine Trees", "pine tree"), ("boxes", "box"),
                                            ("leaves", "leaf"), ("sheep", "sheep"), ("glass", "glass"), ("people", "person"),
                                            ("tomatoes", "tomato"), ("bus", "bus"), ("hot air balloons", "hot air balloon")])
def test_singular(word, expected):
    assert ds.singular(word) == expected


def test_invalid_answers_are_failures():
    with pytest.raises(ValueError):
        ds.build_payload("sorry, no JSON", [], None)
    bad = json.loads(MODEL_ANSWER) | {"scene": "spaceship"}
    with pytest.raises(ValueError, match="scene=spaceship"):
        ds.build_payload(json.dumps(bad), [], None)


def test_parity_ignores_timings_and_key_order(tmp_path):
    a = ds.build_payload(MODEL_ANSWER, ["x"], "T", {"prompt": 1, "generation": 2})
    b = json.loads(json.dumps(dict(reversed(list(a.items()))))) | {"tokens": {"prompt": 9}, "runtimeMs": 5}
    assert compare(a, b) == []
    assert compare(a, b | {"caption": "Another caption."}) == ["caption"]
    ws = WorkState(tmp_path / "w.sqlite")
    item = type("I", (), {"puzzle_id": "p"})()
    ok = describe_parity([(item, b)] * 3, lambda i: a, ws, "1.0.0+qwen35-9b-4bit", min_n=3)
    assert ok["passed"] and ws.marker("describe-parity")["version"] == "1.0.0+qwen35-9b-4bit"
    bad = describe_parity([(item, b | {"caption": "no"})] * 3, lambda i: a, ws, "1.0.0+qwen35-9b-4bit", min_n=3)
    assert not bad["passed"] and ws.marker("describe-parity") is None
    few = describe_parity([(item, b)], lambda i: a, ws, "v", min_n=20)
    assert not few["passed"] and "only 1" in few["reason"]
