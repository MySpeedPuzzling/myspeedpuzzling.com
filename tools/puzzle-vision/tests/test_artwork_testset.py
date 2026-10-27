"""The artwork test set (stratified uniform random draw with weights) and artwork_eval's catalogue-weighted rates."""

import json

import pytest

from puzzle_vision import artwork_eval as ae
from puzzle_vision import artwork_testset as ts


def art(kind, method, fp_kind=None, fp_method=None):
    r = {"kind": kind, "method": method, "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}}
    if fp_kind:
        r["firstPass"] = {"kind": fp_kind, "method": fp_method}
    return r


def test_the_stratum_is_the_first_pass():
    assert ae.stratum(art("flat", "trim")) == "flat"
    assert ae.stratum(art("box_front", "vlm+widen+face", "box_front", "vlm")) == "box_vlm"
    assert ae.stratum(art("flat", "trim", "box_angled", "vlm+quad")) == "box_quad"  # the 1.1 kind re-check does not move it
    assert ae.stratum(art("assembled", "vlm+quad")) == "asm_quad" and ae.stratum(art("assembled", "vlm")) == "asm_vlm"
    assert ae.stratum(art("other", "vlm")) == "other" and ae.stratum(None) == "other"
    assert set(ae.STRATA) == set(ts.ALLOCATION)


def test_the_allocation_scales_and_keeps_every_stratum():
    assert ts.allocation(200) == ts.ALLOCATION and sum(ts.ALLOCATION.values()) == 200
    small = ts.allocation(50)
    assert sum(small.values()) == 50 and min(small.values()) >= 1 and small["box_vlm"] > small["flat"] > small["other"]


def test_the_draw_is_stratified_weighted_reproducible_and_skips_labelled_pictures():
    rows = [{"puzzleId": f"f{i:04d}", "result": art("flat", "trim")} for i in range(1000)]
    rows += [{"puzzleId": f"b{i:04d}", "result": art("box_front", "vlm")} for i in range(300)]
    rows += [{"puzzleId": f"o{i:04d}", "result": art("other", "vlm")} for i in range(2)]
    chosen, strata = ts.draw(rows, 200, seed=1, excluded={"b0000", "b0001"})
    assert strata["flat"] == {"population": 1000, "drawn": 40, "weight": 25.0}
    assert strata["box_vlm"]["drawn"] == 85 and strata["box_vlm"]["weight"] == round(300 / 85, 3)
    assert strata["other"]["drawn"] == 2 and strata["asm_quad"]["drawn"] == 0  # a stratum smaller than its share: all of it
    assert not {"b0000", "b0001"} & {r["puzzleId"] for r in chosen}
    assert len({r["puzzleId"] for r in chosen}) == len(chosen) == 127
    again, _ = ts.draw(rows, 200, seed=1, excluded={"b0000", "b0001"})
    assert [r["puzzleId"] for r in again] == [r["puzzleId"] for r in chosen]


def test_import_refuses_unmarked_or_foreign_rows():
    item = {"id": "p", "fingerprint": "abc", "stored": art("flat", "trim")}
    row = ts.template_row(item)
    assert row["corners"] == [[0, 0], [1, 0], [1, 1], [0, 1]] and row["kind"] == "flat" and row["checked"] is False
    assert ts.validate(row, item) == ["not marked yet"]
    assert ts.validate(row | {"checked": True}, item) == []
    assert "another picture" in ts.validate(row | {"checked": True, "fingerprint": "zzz"}, item)
    assert ts.validate(row | {"checked": True, "kind": "poster"}, item)[0].startswith("kind")
    assert ts.validate(row, None) == ["not in items.json"]


def rows_of(cut_rates):
    out = []
    for s, (n, bad) in cut_rates.items():
        for i in range(n):
            cut = 0.1 if i < bad else 0.0
            out.append({"stratum": s, "cut": cut, "extra": 0.0, "iou": 0.9 if cut else 1.0, "artworkCut": cut > 0, "bigCut": cut > 0.05,
                        "printKept": False, "kindGroup": True, "kindExact": True, "wrongQuad": False,
                        "noSinglePicture": False, "truthKind": "flat"})
    return out


def test_catalogue_weighted_rates():
    population = {"flat": 9000, "box_vlm": 1000}
    rows = rows_of({"flat": (10, 0), "box_vlm": (10, 5)})  # a set enriched with box photos
    ae.weigh(rows, population)
    w = ae.weighted(rows, population)
    assert w["artworkCut"] == pytest.approx(0.05) and w["within5"] == pytest.approx(0.95)  # counted: 25 %
    assert w["coverage"] == 1.0 and w["effectiveN"] < 20
    summary = ae.summarise(rows_of({"flat": (10, 0), "box_vlm": (10, 5)}), population)
    assert summary["weighted"]["artworkCut"] == pytest.approx(0.05)
    partial = rows_of({"flat": (4, 0)})
    ae.weigh(partial, {"flat": 9000, "box_vlm": 1000})
    assert ae.weighted(partial, {"flat": 9000, "box_vlm": 1000})["coverage"] == 0.9


def test_selection_weights_win_over_the_population():
    rows = rows_of({"flat": (2, 0), "box_vlm": (2, 2)})
    for r, sw in zip(rows, (1.0, 1.0, 8.0, 8.0)):
        r["selectionWeight"] = sw
    ae.weigh(rows, {"flat": 1, "box_vlm": 1})
    assert ae.weighted(rows, {})["artworkCut"] == pytest.approx(16 / 18, abs=1e-4)


def test_the_population_file_lists_every_stratum():
    pop = ae.load_population()
    assert set(pop) == set(ae.STRATA) and sum(pop.values()) > 40000
    doc = json.loads((ae.REFERENCE_DIR / "population-strata.json").read_text())
    assert doc["date"] and doc["counts"] == pop
