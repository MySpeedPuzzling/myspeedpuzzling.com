import threading

import httpx
import pytest

from puzzle_vision.download import Downloader
from puzzle_vision.profiles import AnalyzerSettings
from puzzle_vision.stages.base import Defer, Stage
from puzzle_vision.worker import StageWorker
from puzzle_vision.workstate import WorkState

from .conftest import pid


class FakeStage(Stage):
    analyzer = "ocr"
    needs_model_lock = False

    def __init__(self, fail=(), defer=(), big=(), on_analyse=None):
        super().__init__("1.0.0+fake")
        self.fail, self.defer, self.big, self.on_analyse = set(fail), set(defer), set(big), on_analyse
        self.seen = []

    @property
    def model(self):
        return "fake-model"

    def analyse(self, p):
        self.seen.append(p.item.puzzle_id)
        if self.on_analyse:
            self.on_analyse(p)
        if p.item.puzzle_id in self.fail:
            raise RuntimeError("boom")
        if p.item.puzzle_id in self.defer:
            raise Defer("later")
        if p.item.puzzle_id in self.big:
            return {"blob": "x" * 70000}
        return {"lines": [], "w": int(p.subject.shape[1])}


class CropStage(FakeStage):
    analyzer = "colors"
    upstream = ("artwork",)
    uses_crop = True


@pytest.fixture
def ws(tmp_path):
    return WorkState(tmp_path / "ws.sqlite")


@pytest.fixture
def dl(tmp_path, server, ws):
    return Downloader(tmp_path / "img", ws, per_second=0, transport=httpx.MockTransport(server.handler), sleep=lambda s: None)


def settings(name="ocr", limit=2, requires=()):
    return AnalyzerSettings(name, "1.0.0+fake", tuple(requires), claim_limit=limit, seconds_per_image=1)


def stored(server, analyzer="ocr"):
    return sorted(p for (a, p) in server.analyses if a == analyzer)


def test_processes_everything_with_prefetched_claims_and_stores(api, server, ws, dl):
    stage = FakeStage()
    stats = StageWorker(stage, settings(), api, dl, ws, "mac-m1-air", flush_size=200).run()
    assert stored(server) == [pid(i) for i in range(5)]
    assert stats.stored == 5 and stats.stop_reason == "no more work"
    row = server.analyses[("ocr", pid(0))]
    r = row["result"]
    assert row["analyzerVersion"] == "1.0.0+fake" and r["schema"] == 1 and r["model"] == "fake-model"
    assert len(r["sourceSha256"]) == 64 and r["crop"] is None and isinstance(r["runtimeMs"], int)
    paths = [x["path"] for x in server.requests if x["path"].startswith("/internal-api/")]
    # the second claim goes out before the first batch is stored (pre-fetch)
    assert paths.index("/internal-api/puzzle-image-claims", 1) < paths.index("/internal-api/puzzle-image-analyses")
    assert not server.claims  # everything stored or released


def test_a_failure_is_recorded_and_released_and_the_rest_continues(api, server, ws, dl):
    stats = StageWorker(FakeStage(fail={pid(1)}), settings(), api, dl, ws, "w").run()
    assert stored(server) == [pid(0), pid(2), pid(3), pid(4)]
    assert stats.failed == 1 and ws.attempts("ocr", "1.0.0+fake", pid(1), server.puzzles[pid(1)]["imageFingerprint"]) == 1
    releases = [r["json"] for r in server.requests if r["path"].endswith("/release")]
    assert {"worker": "w", "analyzer": "ocr", "puzzleIds": [pid(1)]} in releases
    assert not server.claims


def test_given_up_images_are_skipped_after_three_failures(api, server, ws, dl):
    fp = server.puzzles[pid(1)]["imageFingerprint"]
    for _ in range(3):
        ws.record_failure("ocr", "1.0.0+fake", pid(1), fp, "boom")
    stage = FakeStage()
    StageWorker(stage, settings(), api, dl, ws, "w").run()
    assert pid(1) not in stage.seen and pid(1) not in stored(server)


def test_a_picture_changed_meanwhile_is_skipped(api, server, ws, dl):
    def change(p):
        if p.item.puzzle_id == pid(2):
            server.change_picture(pid(2), "ffffffffffffffff")

    stats = StageWorker(FakeStage(on_analyse=change), settings(limit=5), api, dl, ws, "w").run()
    assert stats.stale == 1 and pid(2) not in stored(server)
    assert ws.progress_counts("ocr") == {"stored": 4, "stale_fingerprint": 1}


def test_stop_stores_what_is_done_and_releases_all_own_claims(api, server, ws, dl):
    stop = threading.Event()

    def stop_after_first(p):
        stop.set()

    stats = StageWorker(FakeStage(on_analyse=stop_after_first), settings(limit=3), api, dl, ws, "w", stop=stop).run()
    assert stored(server) == [pid(0)]
    assert stats.stop_reason == "stopped" and not server.claims
    assert server.requests[-1]["json"] == {"worker": "w", "analyzer": "ocr"}


def test_too_large_payload_is_a_failure_and_never_pushed(api, server, ws, dl):
    stats = StageWorker(FakeStage(big={pid(0)}), settings(), api, dl, ws, "w").run()
    assert pid(0) not in stored(server) and stats.failed == 1
    assert "65536" in ws.failures("ocr")[0]["lastError"]


def test_deferred_images_keep_their_claim_until_the_stage_ends(api, server, ws, dl):
    stats = StageWorker(FakeStage(defer={pid(0)}), settings(), api, dl, ws, "w").run()
    assert stats.deferred == 1 and pid(0) not in stored(server) and len(stored(server)) == 4
    assert not server.claims  # released at the end


def test_crop_stage_reads_the_artwork_and_records_the_crop(api, server, ws, dl):
    for i in (0, 3):
        p = pid(i)
        server.analyses[("artwork", p)] = {"puzzleId": p, "analyzer": "artwork", "analyzerVersion": "a1", "imageFingerprint": server.puzzles[p]["imageFingerprint"],
                                           "analyzedAt": None, "receivedAt": "x", "result": {"bbox": {"x": 0.25, "y": 0.25, "w": 0.5, "h": 0.5}, "corners": None}}
    stage = CropStage()
    StageWorker(stage, settings("colors", requires=("artwork",)), api, dl, ws, "w").run()
    assert stored(server, "colors") == [pid(0), pid(3)]
    r = server.analyses[("colors", pid(0))]["result"]
    assert r["crop"]["bbox"] == {"x": 0.25, "y": 0.25, "w": 0.5, "h": 0.5} and r["w"] < 64


def test_max_images_and_downloads_are_never_repeated(api, server, ws, dl):
    StageWorker(FakeStage(), settings(limit=2), api, dl, ws, "w", max_images=2).run()
    assert len(stored(server)) == 2
    image_gets = [r for r in server.requests if r["path"].startswith("/original/")]
    n = len(image_gets)
    StageWorker(FakeStage(), settings(limit=5), api, dl, ws, "w2").run()
    image_gets = [r for r in server.requests if r["path"].startswith("/original/")]
    assert len(image_gets) == n + 3  # the first two pictures came from the cache
