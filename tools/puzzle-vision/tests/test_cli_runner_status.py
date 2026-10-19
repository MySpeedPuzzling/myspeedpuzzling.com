import json
import plistlib

import pytest

from puzzle_vision import cli, profiles
from puzzle_vision.bench import verdict
from puzzle_vision.checks import model_downloaded, profile_models
from puzzle_vision.runner import Runner, RunOptions, gate_open, stage_command
from puzzle_vision.status import coverage, render
from puzzle_vision.workstate import WorkState

from .conftest import pid


@pytest.mark.parametrize("command", ["setup-check", "run", "status", "derive", "propose-crops", "crop-review-plan", "crop-review-approve",
                                     "bench", "verify", "launchd-plist", "stage-worker"])
def test_every_command_has_help(command, capsys):
    with pytest.raises(SystemExit) as e:
        cli.parser().parse_args([command, "--help"])
    assert e.value.code == 0


def test_run_modes_are_exclusive():
    with pytest.raises(SystemExit):
        cli.parser().parse_args(["run", "--follow", "--daily"])


def test_launchd_plist_command(tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("PUZZLE_VISION_HOME", str(tmp_path))
    assert cli.main(["launchd-plist", "--profile", "m1-air"]) == 0
    plist = plistlib.loads(capsys.readouterr().out.encode())
    assert plist["KeepAlive"] is True


def test_profile_is_required(monkeypatch):
    monkeypatch.delenv("PUZZLE_VISION_PROFILE", raising=False)
    with pytest.raises(SystemExit, match="which machine"):
        cli.main(["status"])


def test_crop_review_plan_command_against_the_fake(api, server, tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("PUZZLE_VISION_HOME", str(tmp_path))
    args = cli.parser().parse_args(["crop-review-plan", "--profile", "m3-max", "--json"])
    ctx = cli.Context(args)
    ctx._api = api
    p = server.puzzles[pid(0)]
    api.propose_image_change(pid(0), b"x", confidence=0.99, model_version="m", source_image_fingerprint=p["imageFingerprint"], tags=["artwork_crop"])
    assert cli.cmd_crop_review_plan(args, ctx) == 0
    out = json.loads(capsys.readouterr().out)
    assert out["earned"] is False and out["to_approve"] == [] and len(out["middle_band"]) == 1


def test_stage_command_and_gates(tmp_path):
    prof = profiles.load("m1-air")
    cmd = stage_command(prof, "ocr", "https://x", "/tmp/s.json", 123.0, 10)
    assert cmd[1:4] == ["-m", "puzzle_vision.cli", "stage-worker"] and "--max-images" in cmd and "--deadline" in cmd
    ws = WorkState(tmp_path / "w.sqlite")
    assert gate_open(None, prof, ws) and not gate_open("describe-parity", prof, ws)
    ws.set_marker("describe-parity", {"passed": True, "version": "0.9.0+old"})
    assert not gate_open("describe-parity", prof, ws)  # a parity check of another version does not count
    ws.set_marker("describe-parity", {"passed": True, "version": prof.version("describe")})
    assert gate_open("describe-parity", prof, ws)


def test_work_left_probes_the_stale_list(api, server, tmp_path):
    prof = profiles.load("m3-max")
    ws = WorkState(tmp_path / "w.sqlite")
    r = Runner(RunOptions(prof, "https://msp.test"), api, ws)
    assert r.work_left([prof.phase("artwork")])
    for p in server.puzzles:  # pictures this machine gave up on do not keep a backfill waiting
        for _ in range(3):
            ws.record_failure("artwork", prof.version("artwork"), p, server.puzzles[p]["imageFingerprint"], "boom")
    assert not r.work_left([prof.phase("artwork")])
    for p in server.puzzles:
        server.analyses[("artwork", p)] = {"puzzleId": p, "analyzer": "artwork", "analyzerVersion": prof.version("artwork"),
                                           "imageFingerprint": server.puzzles[p]["imageFingerprint"], "analyzedAt": None, "receivedAt": "x", "result": {"k": 1}}
    assert not r.work_left([prof.phase("artwork")])


def test_coverage_counts(api, server):
    p0, p1 = pid(0), pid(1)
    fp0 = server.puzzles[p0]["imageFingerprint"]
    server.analyses[("artwork", p0)] = {"puzzleId": p0, "analyzer": "artwork", "analyzerVersion": "a1", "imageFingerprint": fp0,
                                        "analyzedAt": None, "receivedAt": "2026-10-18T10:00:00+00:00", "result": {}}
    server.analyses[("artwork", p1)] = {"puzzleId": p1, "analyzer": "artwork", "analyzerVersion": "a0", "imageFingerprint": "old",
                                        "analyzedAt": None, "receivedAt": "2026-10-18T10:00:00+00:00", "result": {}}
    api.claim("artwork", "a1", "mac-m3-max", limit=1)
    cov = coverage(api.iter_images(status="all"), {"artwork": "a1", "colors": "c1"}, now=1_760_781_600)
    assert cov["artwork"] | {} == cov["artwork"]
    a = cov["artwork"]
    assert (a["done"], a["stale"], a["missing"], a["claimed"], a["total"]) == (1, 1, 3, 1, 5)
    assert a["claimedBy"] == {"mac-m3-max": 1}
    c = cov["colors"]
    assert c["missing"] == 5 and c["waiting"] == 4  # only puzzle 0 has a current artwork
    text = render({"profile": "m3-max", "coverage": cov, "thisMachine": {}})
    assert "artwork" in text and "colors" in text


def test_bench_verdict():
    v = verdict("describe", [10.0, 11.0, 12.0])
    assert v["withinTolerance"] and v["ratio"] == pytest.approx(1.02, abs=0.01)
    assert not verdict("artwork-gate", [0.5])["withinTolerance"]


def test_setup_check_model_lookup(tmp_path):
    snap = tmp_path / "models--google--siglip2-so400m-patch14-384" / "snapshots" / "abc"
    snap.mkdir(parents=True)
    assert not model_downloaded("google/siglip2-so400m-patch14-384", tmp_path)
    (snap / "config.json").write_text("{}")
    (snap / "model.safetensors").write_bytes(b"x")
    assert model_downloaded("google/siglip2-so400m-patch14-384", tmp_path)
    assert profile_models(profiles.load("m3-max")) == ["google/siglip2-so400m-patch14-384", "mlx-community/Qwen3-VL-4B-Instruct-4bit",
                                                       "mlx-community/Qwen3.5-9B-MLX-4bit"]
    m1 = profiles.load("m1-air")
    assert "tue-mps/coco_panoptic_eomt_large_640" in profile_models(m1)
    assert "mlx-community/Qwen3.5-9B-MLX-4bit" in profile_models(m1) and "mlx-community/Qwen3.5-9B-MLX-4bit" not in profile_models(m1, gated=False)


def test_stage_worker_command_runs_a_stage_and_writes_its_stats(api, server, tmp_path, monkeypatch):
    import httpx

    from puzzle_vision import stages
    from puzzle_vision.download import Downloader

    from .test_worker import FakeStage

    monkeypatch.setenv("PUZZLE_VISION_HOME", str(tmp_path))
    monkeypatch.setattr(stages, "create", lambda *a, **k: FakeStage())
    stats = tmp_path / "stats.json"
    args = cli.parser().parse_args(["stage-worker", "--profile", "m1-air", "--analyzer", "ocr", "--stats-file", str(stats)])
    ctx = cli.Context(args)
    ctx._api = api
    ctx._downloader = Downloader(tmp_path / "img", ctx.workstate, per_second=0, transport=httpx.MockTransport(server.handler))
    assert cli.cmd_stage_worker(args, ctx) == 0
    out = json.loads(stats.read_text())
    assert out["stored"] == 5 and out["line"].startswith("[ocr] stored 5")
    assert sorted(p for (a, p) in server.analyses if a == "ocr") == [pid(i) for i in range(5)]
    # nothing left: the next run does not even load the model
    assert cli.cmd_stage_worker(args, ctx) == 0
    assert json.loads(stats.read_text())["line"] == "[ocr] nothing to do"


def test_setup_check_reports_without_loading_anything(tmp_path):
    from puzzle_vision.checks import run_checks
    from puzzle_vision.config import Settings, TokenMissing

    def no_token():
        raise TokenMissing("no internal API token: add it with security add-generic-password …")

    s = Settings(cache_dir=tmp_path / "c", state_dir=tmp_path / "s", log_dir=tmp_path / "l", token_provider=no_token)
    checks = {c.name: c for c in run_checks(profiles.load("m1-air"), s, api_factory=None, cache=tmp_path / "hub")}
    assert not checks["token"].ok and "API" not in checks
    assert not checks["model google/siglip2-so400m-patch14-384"].ok
    assert checks["model mlx-community/Qwen3.5-9B-MLX-4bit"].ok  # behind the describe-parity gate
    assert all(c.line().startswith(("PASS", "FAIL")) for c in checks.values())
    solo = {c.name: c for c in run_checks(profiles.load("m3-solo"), s, api_factory=None, cache=tmp_path / "hub")}
    for repo in ("google/siglip2-so400m-patch14-384", "mlx-community/Qwen3-VL-4B-Instruct-4bit", "facebook/dinov2-large",
                 "tue-mps/coco_panoptic_eomt_large_640", "mlx-community/Qwen3.5-9B-MLX-4bit"):
        assert not solo[f"model {repo}"].ok  # one Mac needs every model, describe's included (no gate)
