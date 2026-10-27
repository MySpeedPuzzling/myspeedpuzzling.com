"""The finished_photo analyzer (puzzle_vision/finished_photo.py + finished_photo_stage.py) on synthetic pictures: the
puzzle found in a photo by registering the catalogue artwork, straightened, measured; the stage against the fake API.
CPU only - no model is loaded (the embedder is a fake)."""

from __future__ import annotations

import io
import json
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import pytest
from PIL import Image

from puzzle_vision import finished_photo as fp
from puzzle_vision import profiles
from puzzle_vision.api import ApiError
from puzzle_vision.finished_photo_stage import PhotoCache, priority, run
from puzzle_vision.api import FinishedPhotoPuzzle

from .conftest import FakeServer, pid


def artwork(w: int = 600, h: int = 450, seed: int = 1) -> np.ndarray:
    """A textured synthetic picture: coloured ellipses and lines on a gradient - plenty of SIFT features."""
    rng = np.random.default_rng(seed)
    a = np.zeros((h, w, 3), np.uint8)
    a[:] = np.linspace(40, 200, w, dtype=np.uint8)[None, :, None]
    for _ in range(140):
        c = tuple(int(x) for x in rng.integers(0, 255, 3))
        centre = (int(rng.integers(0, w)), int(rng.integers(0, h)))
        axes = (int(rng.integers(4, 40)), int(rng.integers(4, 40)))
        cv2.ellipse(a, centre, axes, float(rng.integers(0, 180)), 0, 360, c, -1)
    for _ in range(60):
        c = tuple(int(x) for x in rng.integers(0, 255, 3))
        cv2.line(a, (int(rng.integers(0, w)), int(rng.integers(0, h))), (int(rng.integers(0, w)), int(rng.integers(0, h))), c, 2)
    return a


def photo_of(art: np.ndarray, corners: np.ndarray, size: tuple[int, int] = (1200, 900)) -> np.ndarray:
    """The artwork warped onto a plain 'table' at the given corners (TL TR BR BL, photo px)."""
    w, h = size
    table = np.full((h, w, 3), (150, 120, 90), np.uint8)
    ah, aw = art.shape[:2]
    m = cv2.getPerspectiveTransform(np.float32([[0, 0], [aw, 0], [aw, ah], [0, ah]]), corners.astype(np.float32))
    warped = cv2.warpPerspective(art, m, (w, h))
    mask = cv2.warpPerspective(np.full((ah, aw), 255, np.uint8), m, (w, h)) > 0
    table[mask] = warped[mask]
    return table


CORNERS = np.array([[180, 120], [1030, 160], [1000, 790], [150, 760]], float)


def test_registers_the_artwork_in_a_skewed_photo() -> None:
    art = artwork()
    loc = fp.locate(photo_of(art, CORNERS), art)
    assert loc.method == "register"
    assert loc.inliers >= fp.REGISTER_MIN_INLIERS
    assert np.abs(loc.quad - CORNERS).max() < 6  # px at 1200 × 900


def test_a_photo_of_something_else_does_not_register() -> None:
    art = artwork()
    # another picture never registers (the quad path may still find an outline - derive checks it by the embeddings)
    assert fp.register(cv2.resize(artwork(seed=7), (1200, 900)), art).method == "none"
    # a plain photo: nothing at all, the reason stored
    res, crop = fp.measure(np.full((900, 1200, 3), 128, np.uint8), art, None, 500)
    assert crop is None
    assert res["locate"]["method"] == "none" and res["locate"]["reason"]
    assert "pieceScale" not in res


def test_quad_fallback_for_a_picture_without_texture() -> None:
    art = np.full((450, 600, 3), (240, 210, 60), np.uint8)  # one colour: nothing to register; lighter than the table
    photo = photo_of(art, CORNERS)
    loc = fp.locate(photo, art)
    assert loc.method == "quad"
    assert np.abs(loc.quad - CORNERS).max() < 15
    assert loc.aspect_error is not None and loc.aspect_error < fp.QUAD_MAX_ASPECT_ERROR


def test_measure_straightens_and_measures_the_whole_puzzle() -> None:
    art = artwork()
    res, crop = fp.measure(photo_of(art, CORNERS), art, None, 500)
    assert res["locate"]["method"] == "register"
    assert crop is not None
    # straightened in the artwork's aspect
    assert abs(crop.shape[1] / crop.shape[0] - 600 / 450) < 0.03
    assert res["rectified"]["visibleShare"] >= fp.FULL_VISIBLE
    assert res["quality"]["printCorrelation"] > 0.9
    assert res["pieceScale"]["pieces"] == 500
    assert set(res["seams"]) >= {"cols", "rows", "gridX", "gridY", "artworkGridX", "artworkGridY"}
    assert res["glare"]["share"] < 0.01
    json.dumps(res)  # a payload


def test_a_puzzle_running_out_of_the_frame_gets_no_grid_numbers() -> None:
    art = artwork()
    corners = CORNERS + [[-300, 0], [0, 0], [0, 0], [-300, 0]]  # the left part outside the photo
    res, crop = fp.measure(photo_of(art, corners), art, None, 500)
    assert res["locate"]["method"] == "register"
    assert crop is not None
    assert res["rectified"]["visibleShare"] < fp.FULL_VISIBLE
    assert res["pieceScale"] is None and res["seams"] is None
    assert res["glare"] is not None  # measured on the visible part


def test_inner_box_holds_only_valid_pixels() -> None:
    valid = np.ones((100, 200), bool)
    valid[:, :30] = False
    valid[:10, :] = False
    x0, y0, x1, y1 = fp.inner_box(valid)
    assert valid[y0:y1, x0:x1].all()
    assert (x1 - x0) * (y1 - y0) > 0.6 * 200 * 100


def _seamed(lines: str, cols: int = 26, rows: int = 19, w: int = 1040, h: int = 760) -> np.ndarray:
    rng = np.random.default_rng(3)
    a = np.full((h, w, 3), 170, np.uint8)
    a += rng.integers(0, 40, (h, w, 1), dtype=np.uint8)  # a little print texture
    if lines == "grid":
        for c in range(1, cols):
            cv2.line(a, (c * w // cols, 0), (c * w // cols, h), (40, 40, 40), 1)
        for r in range(1, rows):
            cv2.line(a, (0, r * h // rows), (w, r * h // rows), (40, 40, 40), 1)
    else:  # random cut: the same amount of seams, wandering
        for _ in range(cols + rows):
            pts = np.cumsum(rng.integers(-25, 26, (40, 2)), axis=0) + [rng.integers(0, w), rng.integers(0, h)]
            cv2.polylines(a, [pts.astype(np.int32).reshape(-1, 1, 2)], False, (40, 40, 40), 1)
    return a


def test_a_grid_cut_lines_its_seams_up_and_a_random_cut_does_not() -> None:
    grid = fp.grid_regularity(_seamed("grid"), 494)
    random = fp.grid_regularity(_seamed("random"), 494)
    assert (grid["cols"], grid["rows"]) == (26, 19)
    assert grid["gridX"] > random["gridX"] + 1.0
    assert grid["gridY"] > random["gridY"] + 1.0
    assert grid["peakCols"] == 26 and grid["peakRows"] == 19


def test_periodicity_needs_pieces_of_a_few_pixels() -> None:
    assert fp.periodicity(np.zeros((4, 100)), 40) == (None, None)


def test_glare_counts_colourless_highlights() -> None:
    a = np.full((400, 400, 3), (120, 60, 40), np.uint8)
    for x in range(40, 400, 60):  # small glints, as a lamp leaves them on a glossy print
        cv2.circle(a, (x, 200), 6, (255, 255, 255), -1)
    g = fp.glare(a, np.full((400, 400, 3), (120, 60, 40), np.uint8))
    assert 0.003 < g["share"] < 0.006  # 7 glints of r 6 = 0.5 % of the picture
    assert g["vsArtwork"] == pytest.approx(g["share"], abs=1e-3)
    assert fp.glare(np.full((400, 400, 3), (120, 60, 40), np.uint8))["share"] == 0


# --- the stage against the fake API ----------------------------------------------------------------------------------
class FakeEmbedder:
    def __init__(self) -> None:
        self.batches: list[int] = []

    def embed(self, crops: list[np.ndarray]) -> list[dict[str, Any]]:
        self.batches.append(len(crops))
        return [{"siglip2": {"dim": 2, "vector": "AAA="}, "dinov2": {"dim": 2, "cls": "AAA="}} for _ in crops]


class FakeLock:
    def __init__(self) -> None:
        self.acquired = self.released = 0

    def acquire(self, timeout: float | None = None, stop: Any = None) -> None:
        self.acquired += 1

    def release(self) -> None:
        self.released += 1


def _jpeg(a: np.ndarray) -> bytes:
    buf = io.BytesIO()
    Image.fromarray(a).save(buf, "JPEG", quality=92)
    return buf.getvalue()


def _setup(server: FakeServer, tmp_path: Path) -> tuple[str, str, Path]:
    art = artwork()
    p = pid(0)
    cat = tmp_path / "catalogue.png"
    Image.fromarray(art).save(cat)
    server.analyses[("artwork", p)] = {"puzzleId": p, "analyzer": "artwork", "analyzerVersion": "1.1.0", "receivedAt": "x",
                                       "imageFingerprint": server.puzzles[p]["imageFingerprint"], "analyzedAt": None,
                                       "result": {"kind": "box_front", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}}}
    server.add_finished_photo(p, "aaaaaaaaaaaaaaa1", _jpeg(photo_of(art, CORNERS)))
    server.add_finished_photo(p, "aaaaaaaaaaaaaaa2", _jpeg(np.full((900, 1200, 3), 128, np.uint8)))  # not the puzzle
    return p, str(cat), tmp_path / "photos"


def test_the_stage_measures_embeds_and_stores_each_photo(api: Any, server: FakeServer, tmp_path: Path) -> None:
    p, cat, photos = _setup(server, tmp_path)
    embedder, lock = FakeEmbedder(), FakeLock()
    summary = run(api, lambda *_: cat, PhotoCache(photos), "1.0.0+test", embedder=embedder, workers=1, lock=lock)
    assert summary["stored"] == 2
    assert summary["methods"] == {"register": 1, "none": 1}
    found = server.finished_analyses[(p, "aaaaaaaaaaaaaaa1")]["result"]
    assert found["locate"]["method"] == "register"
    assert found["embeddings"]["siglip2"]["dim"] == 2
    assert found["schema"] == 1 and found["sourceSha256"]
    not_found = server.finished_analyses[(p, "aaaaaaaaaaaaaaa2")]["result"]
    assert "embeddings" not in not_found and not_found["locate"]["reason"]
    assert embedder.batches == [1] and lock.acquired == lock.released == 1
    # the photos came through the internal API with the token, and are cached by fingerprint
    assert (photos / "aa" / "aaaaaaaaaaaaaaa1.jpg").exists()
    # nothing is left to do for the version; another version re-does them
    assert run(api, lambda *_: cat, PhotoCache(photos), "1.0.0+test", embedder=embedder, workers=1, lock=lock)["photos"] == 0
    assert run(api, lambda *_: cat, PhotoCache(photos), "1.0.1+test", embedder=embedder, workers=1, lock=lock, dry_run=True)["photos"] == 2


def test_a_dry_run_stores_nothing_and_needs_no_model(api: Any, server: FakeServer, tmp_path: Path) -> None:
    _, cat, photos = _setup(server, tmp_path)
    summary = run(api, lambda *_: cat, PhotoCache(photos), "1.0.0+test", embedder=None, workers=1, dry_run=True, lock=FakeLock())
    assert summary["photos"] == 2 and summary["stored"] == 2
    assert server.finished_analyses == {}


def test_a_puzzle_without_a_current_artwork_crop_waits(api: Any, server: FakeServer, tmp_path: Path) -> None:
    p, cat, photos = _setup(server, tmp_path)
    server.change_picture(p, "ffffffffffffffff")
    summary = run(api, lambda *_: cat, PhotoCache(photos), "1.0.0+test", embedder=FakeEmbedder(), workers=1, lock=FakeLock())
    assert summary["photos"] == 0 and summary["skipped"]["no current artwork"] == 2


def test_the_token_never_goes_to_another_host(api: Any) -> None:
    with pytest.raises(ApiError):
        api.finished_photo_bytes(f"https://elsewhere.test/internal-api/puzzles/{pid(0)}/finished-photos/aaaaaaaaaaaaaaa1")
    with pytest.raises(ApiError):
        api.finished_photo_bytes("https://msp.test/internal-api/puzzle-images")


def test_box_photos_of_few_measured_puzzles_go_first() -> None:
    def puzzle(i: int) -> FinishedPhotoPuzzle:
        return FinishedPhotoPuzzle(pid(i), 500, "u", "f")

    kinds = {pid(1): "flat", pid(2): "box_front", pid(3): "assembled", pid(4): "box_front"}
    counts = {pid(1): 0, pid(2): 12, pid(3): 2, pid(4): 0}
    ordered = sorted([puzzle(i) for i in (1, 2, 3, 4)], key=lambda x: priority(x, kinds, counts))
    assert [x.puzzle_id for x in ordered] == [pid(3), pid(4), pid(1), pid(2)]


def test_every_profile_with_the_section_names_the_same_version() -> None:
    versions = {n: profiles.load(n).finished_photo.get("version") for n in profiles.available()}
    named = {v for v in versions.values() if v}
    assert len(named) == 1, versions
    assert versions["m3-solo"] and versions["m3-max"]
