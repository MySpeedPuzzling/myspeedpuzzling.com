import json
import os
import plistlib
import subprocess

import httpx
import numpy as np
import pytest
from PIL import Image

from puzzle_vision import config, launchd, profiles
from puzzle_vision.download import Downloader, ImageGone, RateLimiter
from puzzle_vision.lock import ModelLock, parse_memory_pressure
from puzzle_vision.payload import PayloadTooLarge, check_size, clean, encoded_size
from puzzle_vision.workstate import WorkState


# config ---------------------------------------------------------------------------------------------------------------
def test_env_token_overrides_the_keychain(monkeypatch):
    monkeypatch.setenv(config.TOKEN_ENV, " abc ")
    assert config.read_token(run=lambda *a, **k: pytest.fail("keychain must not be asked")) == "abc"


def test_keychain_is_asked_for_service_and_user(monkeypatch):
    monkeypatch.delenv(config.TOKEN_ENV, raising=False)
    seen = {}

    def run(cmd, **kw):
        seen["cmd"] = cmd
        return subprocess.CompletedProcess(cmd, 0, stdout="s3cret\n", stderr="")

    assert config.read_token(run=run) == "s3cret"
    assert seen["cmd"][:4] == ["security", "find-generic-password", "-s", "msp-internal-api"] and seen["cmd"][-1] == "-w"


def test_missing_token_message_never_contains_one(monkeypatch):
    monkeypatch.delenv(config.TOKEN_ENV, raising=False)
    with pytest.raises(config.TokenMissing) as e:
        config.read_token(run=lambda cmd, **kw: subprocess.CompletedProcess(cmd, 44, stdout="", stderr="not found"))
    assert "security add-generic-password" in str(e.value)


def test_worker_id_and_settings_repr():
    assert config.worker_id("m1-air", "Jans-MacBook-Air.local") == "jans-macbook-air-m1-air"
    assert len(config.worker_id("p", "x" * 100)) == 64
    s = config.Settings(token_provider=lambda: "tok-123")
    assert "tok-123" not in repr(s)


# lock -----------------------------------------------------------------------------------------------------------------
def test_memory_pressure_parsing():
    assert parse_memory_pressure("The system has 38654705664 (9437184 pages ...)\nSystem-wide memory free percentage: 81%\n") == 81
    assert parse_memory_pressure("garbage") is None


def test_lock_needs_free_memory_and_never_removes_a_foreign_lock(tmp_path):
    d = tmp_path / "msp-model-lock"
    low = ModelLock("x", lock_dir=d, memory=lambda: 30)
    assert not low.try_acquire() and not d.exists()
    ok = ModelLock("x", lock_dir=d, memory=lambda: 80)
    assert ok.try_acquire() and d.is_dir() and json.loads((d / "puzzle-vision.json").read_text())["pid"] == os.getpid()
    other = ModelLock("y", lock_dir=d, memory=lambda: 80)
    assert not other.try_acquire()
    ok.release()
    assert not d.exists()
    d.mkdir()  # a shell script's lock (no owner file)
    sleeps = []
    foreign = ModelLock("z", lock_dir=d, memory=lambda: 80, poll_seconds=1, sleep=sleeps.append)
    with pytest.raises(Exception):
        foreign.acquire(timeout=0)
    assert d.exists()


def test_own_stale_lock_of_a_dead_process_is_cleared(tmp_path):
    d = tmp_path / "lock"
    d.mkdir()
    import socket

    (d / "puzzle-vision.json").write_text(json.dumps({"pid": 999999, "host": socket.gethostname()}))
    assert ModelLock("x", lock_dir=d, memory=lambda: 90).try_acquire()


# workstate ------------------------------------------------------------------------------------------------------------
def test_workstate_roundtrip(tmp_path):
    ws = WorkState(tmp_path / "s.sqlite")
    ws.record_download("fp", "p", "/x.jpg", "sha", 10, 20, 100, 200, 1234)
    assert ws.download("fp")["sha256"] == "sha" and ws.cache_bytes() == 1234
    for _ in range(3):
        ws.record_failure("ocr", "v", "p", "fp", "boom")
    assert ws.given_up("ocr", "v", "p", "fp") and not ws.given_up("ocr", "v2", "p", "fp")
    ws.record_progress("ocr", "v", "p", "fp", "stored")
    assert ws.stored_since("ocr", 0) == 1 and ws.failure_counts() == {"ocr": 1}
    ws.put_artifact("artwork", "p", "fp", "a1", {"bbox": {"x": 0}})
    assert ws.artifact("artwork", "p", "fp")["result"] == {"bbox": {"x": 0}} and ws.artifact("artwork", "p", "other") is None
    ws.put_gate("fp", "g", {"flat": 0.1}, True)
    assert ws.gate("fp", "g") == {"scores": {"flat": 0.1}, "flat": True}
    ws.set_marker("describe-parity", {"passed": True})
    assert ws.marker("describe-parity") == {"passed": True}
    ws.delete_marker("describe-parity")
    assert ws.marker("describe-parity") is None


# download -------------------------------------------------------------------------------------------------------------
def _jpeg_rotated() -> bytes:
    import io

    im = Image.new("RGB", (3000, 1000), (10, 200, 10))
    exif = Image.Exif()
    exif[0x0112] = 6  # rotate 90° when shown
    buf = io.BytesIO()
    im.save(buf, "JPEG", exif=exif)
    return buf.getvalue()


def test_download_normalises_and_caches_by_fingerprint(tmp_path):
    calls = []
    data = _jpeg_rotated()

    def handler(req):
        calls.append(str(req.url))
        if req.url.path.endswith("gone.jpg"):
            return httpx.Response(404)
        if len(calls) == 1:
            return httpx.Response(503)
        return httpx.Response(200, content=data)

    ws = WorkState(tmp_path / "s.sqlite")
    d = Downloader(tmp_path / "img", ws, per_second=0, transport=httpx.MockTransport(handler), sleep=lambda s: None)
    img = d.get("p", "fp1", "https://img.test/original/a.jpg")
    assert (img.width, img.height) == (533, 1600) and (img.original_width, img.original_height) == (3000, 1000)
    assert len(img.sha256) == 64 and len(calls) == 2
    d.get("p", "fp1", "https://img.test/original/a.jpg")
    assert len(calls) == 2  # never twice
    with pytest.raises(ImageGone):
        d.get("q", "fp2", "https://img.test/original/gone.jpg")


def test_rate_limiter_spaces_requests():
    t = [0.0]
    slept = []
    rl = RateLimiter(4, clock=lambda: t[0], sleep=lambda s: (slept.append(s), t.__setitem__(0, t[0] + s)))
    for _ in range(3):
        rl.wait()
    assert slept == [0.25, 0.25]


def test_payload_size_like_php_and_cleaning():
    assert encoded_size({"a": "é/"}) == len('{"a":"é/"}'.encode())
    with pytest.raises(PayloadTooLarge):
        check_size({"x": "y" * 70000})
    assert clean({"a\x00": ["b\x00", float("nan"), np.float32(1.5), np.int64(2)]}) == {"a": ["b", None, 1.5, 2]}


# launchd --------------------------------------------------------------------------------------------------------------
def test_launchd_plists(tmp_path):
    m1 = plistlib.loads(launchd.render(profiles.load("m1-air"), tmp_path, uv="/opt/homebrew/bin/uv").encode())
    assert m1["Label"] == "com.myspeedpuzzling.puzzle-vision.m1-air" and m1["KeepAlive"] is True
    assert m1["ProgramArguments"][-4:] == ["run", "--profile", "m1-air", "--follow"]
    assert m1["StandardOutPath"].startswith(str(tmp_path))
    m3 = plistlib.loads(launchd.render(profiles.load("m3-max"), tmp_path, uv="/opt/homebrew/bin/uv").encode())
    assert "KeepAlive" not in m3 and m3["StartCalendarInterval"] == {"Hour": 1, "Minute": 30}
    assert m3["ProgramArguments"][-3:] == ["--daily", "--hours", "6"]
    text = launchd.render(profiles.load("m3-max"), tmp_path)
    assert "MSP_INTERNAL_API_TOKEN" not in text and "Bearer" not in text
