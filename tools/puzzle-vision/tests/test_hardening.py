"""The review fixes before the unattended week-long backfill (PR #261 review)."""

import json
import signal
import threading

import httpx
import numpy as np
import pytest

from puzzle_vision import cli, config, profiles
from puzzle_vision.api import ApiError, WorkItem
from puzzle_vision.derive import Deriver, needs_push
from puzzle_vision.download import Downloader
from puzzle_vision.lock import ModelLock
from puzzle_vision.payload import as_map, envelope, normalised
from puzzle_vision.profiles import AnalyzerSettings
from puzzle_vision.runner import EXIT_DEADLINE, EXIT_STOPPED, Runner, RunOptions, upstream_active, usable_work
from puzzle_vision.stages import apple_vision as av
from puzzle_vision.stages import artwork as aw
from puzzle_vision.stages import segmentation as sg
from puzzle_vision.stages.base import ArtifactSource
from puzzle_vision.worker import FLUSH_SECONDS, StageWorker
from puzzle_vision.workstate import WorkState

from .conftest import pid
from .test_derive import VERSIONS, fake_text, put
from .test_worker import FakeStage, settings


@pytest.fixture
def ws(tmp_path):
    return WorkState(tmp_path / "ws.sqlite")


@pytest.fixture
def dl(tmp_path, server, ws):
    return Downloader(tmp_path / "img", ws, per_second=0, transport=httpx.MockTransport(server.handler), sleep=lambda s: None)


# 1. empty maps --------------------------------------------------------------------------------------------------------
def test_empty_maps_are_sent_as_null_and_read_back_safely():
    seg = sg.summarise(np.zeros((16, 16), np.int32), [], {})
    assert seg["classes"] is None and seg["instances"] is None
    p = envelope({"a": {}, "b": [{"c": {}}], "d": {"e": 1}}, schema=1, model="m", source_sha256=None, crop=None, runtime_ms=0)
    assert p["a"] is None and p["b"] == [{"c": None}] and p["d"] == {"e": 1}
    assert as_map([]) == {} and as_map(None) == {} and as_map({"x": 1}) == {"x": 1}
    assert normalised({"a": {}, "b": [], "c": None, "d": 1}) == normalised({"d": 1}) == {"d": 1}


def test_tags_derive_survives_maps_the_api_returns_as_lists(api, server, tmp_path):
    for i in range(3):
        p = pid(i)
        put(server, "segmentation", p, {"classes": {}, "instances": {}, "grid16": [255] * 256})   # comes back as []
        put(server, "describe", p, {"themes": ["animals"], "counts": {}, "subjects": []})
        put(server, "traits", p, {"borderBand": {}, "uniformShare": 0.3})
        put(server, "vision_labels", p, {"faces": [], "humans": 0, "labels": []})
        put(server, "artwork", p, {"kind": "flat", "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}, "source": {}})
    first = Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run(only=["tags"])
    assert first["tags"]["stored"] == 3
    assert "theme.animals" in server.analyses[("tags", pid(0))]["result"]["assigned"]
    again = Deriver(api, VERSIONS, tmp_path, text_features=fake_text).run(only=["tags"])
    assert again["tags"]["changed"] == 0  # [] (read back) ≡ null (sent) - nothing re-pushed


def test_needs_push_treats_empty_map_list_and_null_alike():
    old = {"analyzerVersion": "v", "imageFingerprint": "f", "result": {"candidates": [], "assigned": {"k": 1}, "skills": []}}
    assert not needs_push("tags", old, "v", "f", {"candidates": None, "assigned": {"k": 1}, "skills": {}})
    assert not needs_push("zeroshot", {"analyzerVersion": "v", "imageFingerprint": "f", "result": []}, "v", "f", {"scores": {}})


# 2. leases, slow stages, pre-fetch ------------------------------------------------------------------------------------
def test_lease_covers_three_times_the_batch_plus_the_store_interval():
    describe = profiles.load("m3-max").analyzers["describe"]
    assert describe.lease_seconds() >= describe.claim_limit * describe.seconds_per_image * 3 + FLUSH_SECONDS
    assert describe.slow and not profiles.load("m1-air").analyzers["ocr"].slow


def test_slow_stages_store_every_result_at_once(api, server, ws, dl):
    slow = AnalyzerSettings("ocr", "1.0.0+fake", (), claim_limit=5, seconds_per_image=10)
    StageWorker(FakeStage(), slow, api, dl, ws, "w").run()
    stores = [r for r in server.requests if r["path"] == "/internal-api/puzzle-image-analyses" and r["method"] == "POST"]
    assert [len(r["json"]["items"]) for r in stores] == [1, 1, 1, 1, 1]


def test_the_next_claim_goes_out_when_the_batch_is_80_percent_done(api, server, ws, dl):
    for i in range(5, 10):
        server.add_puzzle(i)
    order = []
    stage = FakeStage(on_analyse=lambda p: order.append(("analyse", p.item.puzzle_id)))
    original = api.claim

    def claim(*a, **k):
        items = original(*a, **k)
        order.append(("claim", len(items)))
        return items

    api.claim = claim
    StageWorker(stage, settings(limit=5), api, dl, ws, "w").run()
    first_batch = [x for x in order[:7]]
    assert first_batch[0] == ("claim", 5)
    analysed_before_prefetch = [x for x in order[1:order.index(("claim", 5), 1)] if x[0] == "analyse"]
    assert len(analysed_before_prefetch) == 4  # 80 % of 5


def test_stop_is_checked_before_claiming(api, server, ws, dl):
    stop = threading.Event()
    stop.set()
    StageWorker(FakeStage(), settings(), api, dl, ws, "w", stop=stop).run()
    assert not [r for r in server.requests if r["path"] == "/internal-api/puzzle-image-claims"]


# 4. stop handling -----------------------------------------------------------------------------------------------------
def test_runner_reports_a_stop_and_a_deadline_and_restores_signal_handlers(api, server, tmp_path):
    prof = profiles.load("m3-max")
    before = signal.getsignal(signal.SIGTERM)
    r = Runner(RunOptions(prof, "https://msp.test", daily=True), api, WorkState(tmp_path / "w.sqlite"))
    r.run_phase = lambda phase: (r.stop.set(), 0)[1]  # noqa: E731 - SIGTERM arrives during the first phase
    r.caffeinate = lambda: None
    assert r.run() == EXIT_STOPPED and signal.getsignal(signal.SIGTERM) is before
    r2 = Runner(RunOptions(prof, "https://msp.test", hours=-1), api, WorkState(tmp_path / "w.sqlite"))
    r2.caffeinate = lambda: None
    assert r2.run() == EXIT_DEADLINE


def test_a_run_ended_early_runs_no_derive(monkeypatch, tmp_path, api):
    monkeypatch.setenv("PUZZLE_VISION_HOME", str(tmp_path))
    args = cli.parser().parse_args(["run", "--profile", "m3-max", "--daily"])
    ctx = cli.Context(args)
    ctx._api = api
    called = []
    monkeypatch.setattr("puzzle_vision.runner.Runner.run", lambda self: EXIT_DEADLINE)
    monkeypatch.setattr(cli, "cmd_derive", lambda *a: called.append("derive"))
    monkeypatch.setattr(cli, "cmd_propose_crops", lambda *a: called.append("crops"))
    assert cli.cmd_run(args, ctx) == EXIT_DEADLINE and called == []
    assert not cli.pid_file(ctx).exists()


def test_plist_lets_a_stop_finish():
    from puzzle_vision import launchd

    assert "<key>ExitTimeOut</key>\n    <integer>300</integer>" in launchd.TEMPLATE.read_text()


# 5. / 6. probe, lazy load, work_left ----------------------------------------------------------------------------------
def test_probe_skips_given_up_and_other_workers_images(api, server, ws):
    s = AnalyzerSettings("ocr", "v", ())
    for p in sorted(server.puzzles)[:3]:
        for _ in range(3):
            ws.record_failure("ocr", "v", p, server.puzzles[p]["imageFingerprint"], "boom")
    api.claim("ocr", "v", "other-mac", limit=5)  # the rest is held by another worker
    assert not usable_work(api, ws, s, "me")
    api.release("other-mac")
    assert usable_work(api, ws, s, "me")


def test_no_model_is_loaded_when_a_claim_brings_nothing_usable(api, server, ws, dl):
    for p in server.puzzles:
        for _ in range(3):
            ws.record_failure("ocr", "1.0.0+fake", p, server.puzzles[p]["imageFingerprint"], "boom")
    loaded = []
    StageWorker(FakeStage(), settings(limit=10), api, dl, ws, "w", loader=lambda: loaded.append(1)).run()
    assert loaded == [] and not server.claims
    ws2 = WorkState(":memory:")
    StageWorker(FakeStage(), settings(limit=10), api, dl, ws2, "w", loader=lambda: loaded.append(1)).run()
    assert loaded == [1]


def test_work_left_respects_requires_and_waits_only_for_an_active_upstream(api, server, tmp_path):
    prof = profiles.load("m1-air")
    ws = WorkState(tmp_path / "w.sqlite")
    r = Runner(RunOptions(prof, "https://msp.test"), api, ws)
    phase_b = prof.phase("B")
    assert not r.work_left([phase_b])  # nothing has artwork, and nobody works on artwork
    api.claim("artwork", prof.version("artwork"), "mac-m3-max", limit=1)
    assert upstream_active(api, "artwork", prof.version("artwork")) and r.work_left([phase_b])  # the M3 is on it


# 7. / 8. downloads, upstream cache ------------------------------------------------------------------------------------
def test_downloads_never_leave_a_temporary_file(tmp_path, server, ws, dl):
    img = dl.get(pid(0), server.puzzles[pid(0)]["imageFingerprint"], server.puzzles[pid(0)]["imageUrl"])
    assert not list((tmp_path / "img").rglob("*.tmp-*")) and img.path.endswith(".jpg")


def test_the_upstream_cache_follows_the_listed_version(api, server, ws):
    p = pid(0)
    fp = server.puzzles[p]["imageFingerprint"]
    ws.put_artifact("artwork", p, fp, "1.0.0+old", {"bbox": {"x": 0, "y": 0, "w": 1, "h": 1}})
    put(server, "artwork", p, {"bbox": {"x": 0.1, "y": 0.1, "w": 0.5, "h": 0.5}}, version="1.1.0+new")
    item = WorkItem.from_api(server._item(p))
    src = ArtifactSource(api, ws)
    assert src.get("artwork", item) is None  # cached 1.0.0, the list says 1.1.0
    src.ensure("artwork", [item])
    assert src.get("artwork", item)["bbox"]["x"] == 0.1


# 9. / 10. OCR cap, artwork parse ---------------------------------------------------------------------------------------
def test_ocr_payload_stays_under_60_kb():
    lines = [av.OcrLine("x" * 500, 0.9, (0.0, i / 2000, 0.5, 0.0004)) for i in range(400)]
    p = av.ocr_payload(lines, None, ["en"])
    assert p["truncated"] and len(json.dumps(p, ensure_ascii=False, separators=(",", ":")).encode()) < 60_000
    assert all(len(line["t"]) <= 200 for line in p["lines"]) and len(p["lines"]) < 400


def test_artwork_refuses_an_unparsable_vlm_answer(tmp_path):
    from puzzle_vision.api import WorkItem as W
    from puzzle_vision.download import LocalImage
    from puzzle_vision.stages.base import Prepared

    ws = WorkState(tmp_path / "w.sqlite")
    ws.put_gate("fp", aw.GATE_VERSION, {"flat": 0.0, "box": 0.3}, False)
    stage = aw.ArtworkStage("v", workstate=ws)
    stage._vlm_answer = lambda rgb: "I cannot tell."
    p = Prepared(W("p", "u", "fp"), LocalImage("p", "fp", "x", "s", 64, 48, 64, 48), np.zeros((48, 64, 3), np.uint8))
    with pytest.raises(ValueError, match="no usable kind"):
        stage.analyse(p)


# 11. / 12. flush, lock ------------------------------------------------------------------------------------------------
def test_a_failed_store_keeps_the_results(api, server, ws, dl):
    w = StageWorker(FakeStage(), settings(), api, dl, ws, "w")
    from puzzle_vision.api import AnalysisResult

    w._pending = [AnalysisResult(pid(0), "ocr", "v", server.puzzles[pid(0)]["imageFingerprint"], {"a": 1})]
    server.fail_next["POST /internal-api/puzzle-image-analyses"] = [httpx.Response(500)] * 20
    api.max_retries = 0
    with pytest.raises(ApiError):
        w.flush(force=True)
    assert len(w._pending) == 1
    server.fail_next.clear()
    w.flush(force=True)
    assert not w._pending and ("ocr", pid(0)) in server.analyses


def test_lock_measures_memory_before_taking_the_lock(tmp_path):
    d = tmp_path / "lock"
    calls = []

    def memory():
        calls.append(d.exists())
        return 10

    assert not ModelLock("x", lock_dir=d, memory=memory).try_acquire()
    assert calls == [False] and not d.exists()


# low ones -------------------------------------------------------------------------------------------------------------
def test_upc_e_check_digit_is_checked_on_its_upc_a_expansion():
    assert av.upce_to_upca("01234565") == "012345000065"
    assert av.code_ok("UPCE", "01234565") is True and av.code_ok("UPCE", "01234566") is False
    assert av.code_ok("EAN13", "4005556173709") is True and av.code_ok("QR", "x") is None


def test_the_keychain_token_goes_to_production_only(monkeypatch):
    monkeypatch.delenv(config.TOKEN_ENV, raising=False)
    assert config.token_provider_for("https://myspeedpuzzling.com/") is config.read_token
    provider = config.token_provider_for("http://localhost:8080")
    with pytest.raises(config.TokenMissing, match="Keychain"):
        provider()
    monkeypatch.setenv(config.TOKEN_ENV, "dev-token")
    assert provider() == "dev-token"


def test_crop_review_approve_refuses_audit_ids_caps_and_skips_conflicts(api, server, tmp_path, monkeypatch, capsys):
    from puzzle_vision.proposals import review

    monkeypatch.setenv("PUZZLE_VISION_HOME", str(tmp_path))
    for i in range(60):
        server.add_puzzle(100 + i)
    ids = []
    for i in range(60):
        p = pid(100 + i)
        ids.append(api.propose_image_change(p, b"x", confidence=0.8, model_version="artwork-crop 1.0.0",
                                            source_image_fingerprint=server.puzzles[p]["imageFingerprint"], tags=["artwork_crop"])["changeRequestId"])
    audit = [c for c in ids if review.is_audit(c)]
    normal = [c for c in ids if not review.is_audit(c)]
    args = cli.parser().parse_args(["crop-review-approve", "--profile", "m3-max", *audit[:1], *normal[:25]])
    ctx = cli.Context(args)
    ctx._api = api
    assert cli.cmd_crop_review_approve(args, ctx) == 1
    out = capsys.readouterr().out
    if audit:
        assert "audit sample" in out
    assert out.count(": approved (claude-review:") == 20 and "cap of 20" in out
    server.change_requests[[c["id"] for c in server.change_requests].index(normal[25])]["status"] = "pending"
    original = api.approve_change_request

    def conflict(*a, **k):
        from puzzle_vision.api import Conflict

        raise Conflict(409, "decided meanwhile")

    api.approve_change_request = conflict
    args2 = cli.parser().parse_args(["crop-review-approve", "--profile", "m3-max", normal[25]])
    monkeypatch.setattr(review, "CLAUDE_DAILY_CAP", 1000)
    assert cli.cmd_crop_review_approve(args2, ctx) == 0 and "refused" in capsys.readouterr().out
    api.approve_change_request = original


def test_propose_crops_remembers_low_scores(api, server, ws):
    import io

    from PIL import Image

    from puzzle_vision.proposals import crops

    from .test_crops import artwork, photo

    p = pid(0)
    buf = io.BytesIO()
    Image.fromarray(photo()).save(buf, "JPEG")
    server.images["img-0.png"] = buf.getvalue()
    put(server, "artwork", p, artwork(conf_quad=0.2))
    fetched = []

    def fetch(url):
        fetched.append(url)
        return server.images[url.rsplit("/", 1)[1]]

    crops.propose(api, fetch, lambda rgb: -0.1, "v", limit=5, workstate=ws)
    again = crops.propose(api, fetch, lambda rgb: -0.1, "v", limit=5, workstate=ws)
    assert len(fetched) == 1 and again["remembered"] == 1


def test_describe_parity_skips_a_renamed_puzzle(api, server):
    from puzzle_vision.verify import parity_targets

    put(server, "describe", pid(0), {"title": "Old name", "caption": "x"}, version="d1")
    put(server, "describe", pid(1), {"title": server.puzzles[pid(1)]["name"], "caption": "x"}, version="d1")
    assert [i.puzzle_id for i, _ in parity_targets(api, "d1", 20)] == [pid(1)]
