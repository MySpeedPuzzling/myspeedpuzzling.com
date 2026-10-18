"""Runs a machine profile: phases in order; in a phase the stages one after another, each **its own process** (one
stage = one process = one model, memory returned between stages), the CPU sidecar stages alongside in their own
processes. `caffeinate -i` while running. SIGTERM / Ctrl-C reach every stage, which stores what is done and releases
its claims.

Modes:
- default (backfill): passes until no analyzer of the selected phases has work left; while the other Mac still
  produces what a stage requires (e.g. describe waits on the M1's OCR), it sleeps `idle_minutes` and tries again;
- `follow`: never ends (launchd KeepAlive on the M1) - after a pass without work it sleeps and claims again;
- `daily`: one pass over the profile's daily phases, then derive and crop proposals when the profile says so.
"""

from __future__ import annotations

import json
import logging
import os
import signal
import subprocess
import sys
import tempfile
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .api import InternalApi
from .profiles import Phase, Profile
from .workstate import WorkState

log = logging.getLogger(__name__)

PARITY_MARKER = "describe-parity"


@dataclass
class RunOptions:
    profile: Profile
    base_url: str
    hours: float | None = None
    phase: str | None = None
    follow: bool = False
    daily: bool = False
    idle_minutes: float = 10.0
    max_images: int | None = None


def gate_open(gate: str | None, profile: Profile, workstate: WorkState) -> bool:
    if gate is None:
        return True
    if gate == PARITY_MARKER:
        marker = workstate.marker(PARITY_MARKER) or {}
        return bool(marker.get("passed")) and marker.get("version") == profile.version("describe")
    return bool(workstate.marker(gate))


def stage_command(profile: Profile, analyzer: str, base_url: str, stats_file: str, deadline: float | None,
                  max_images: int | None, gate: bool = False) -> list[str]:
    cmd = [sys.executable, "-m", "puzzle_vision.cli", "stage-worker", "--profile", profile.name, "--analyzer", analyzer,
           "--base-url", base_url, "--stats-file", stats_file]
    if deadline:
        cmd += ["--deadline", str(int(deadline))]
    if max_images:
        cmd += ["--max-images", str(max_images)]
    if gate:
        cmd.append("--gate")
    return cmd


class Runner:
    def __init__(self, opts: RunOptions, api: InternalApi, workstate: WorkState) -> None:
        self.opts = opts
        self.api = api
        self.workstate = workstate
        self.stop = threading.Event()
        self.children: list[subprocess.Popen] = []
        self.deadline = time.time() + opts.hours * 3600 if opts.hours else None
        self._tmp = tempfile.mkdtemp(prefix="puzzle-vision-")

    # signals -----------------------------------------------------------------------------------------------------
    def install_signals(self) -> None:
        def handler(signum: int, _frame: Any) -> None:
            log.info("signal %d - stopping the stages (they store what is done and release their claims)", signum)
            self.stop.set()
            for c in self.children:
                if c.poll() is None:
                    try:
                        c.send_signal(signal.SIGTERM)
                    except ProcessLookupError:
                        pass

        signal.signal(signal.SIGTERM, handler)
        signal.signal(signal.SIGINT, handler)

    def caffeinate(self) -> subprocess.Popen | None:
        try:
            return subprocess.Popen(["caffeinate", "-i", "-w", str(os.getpid())])
        except FileNotFoundError:
            return None

    # stages ------------------------------------------------------------------------------------------------------
    def _spawn(self, analyzer: str, gate: bool = False, env_extra: dict[str, str] | None = None) -> tuple[subprocess.Popen, str]:
        stats = os.path.join(self._tmp, f"{analyzer}{'-gate' if gate else ''}-{time.time_ns()}.json")
        env = dict(os.environ)
        env.update(env_extra or {})
        p = subprocess.Popen(stage_command(self.opts.profile, analyzer, self.opts.base_url, stats, self.deadline,
                                           self.opts.max_images, gate), env=env)
        self.children.append(p)
        return p, stats

    @staticmethod
    def _stats(path: str) -> dict[str, Any]:
        try:
            return json.loads(Path(path).read_text())
        except (OSError, ValueError):
            return {}

    def run_stage(self, analyzer: str) -> dict[str, Any]:
        if analyzer == "artwork":
            p, stats = self._spawn(analyzer, gate=True)
            p.wait()
            if self.stop.is_set():
                return self._stats(stats)
        p, stats = self._spawn(analyzer)
        p.wait()
        return self._stats(stats)

    def run_phase(self, phase: Phase) -> int:
        """Answers how many results the phase stored."""
        if not gate_open(phase.gate, self.opts.profile, self.workstate):
            log.info("phase %s skipped: %s has not passed on this machine (run `puzzle-vision verify --describe-parity`)", phase.name, phase.gate)
            return 0
        log.info("phase %s: %s%s", phase.name, ", ".join(phase.stages),
                 f" (+ CPU: {', '.join(phase.sidecar)})" if phase.sidecar else "")
        sidecars = [self._spawn(a, env_extra={"OMP_NUM_THREADS": "1", "VECLIB_MAXIMUM_THREADS": "1"}) for a in phase.sidecar]
        stored = 0
        for analyzer in phase.stages:
            if self.stop.is_set() or self._past_deadline():
                break
            s = self.run_stage(analyzer)
            stored += int(s.get("stored", 0))
            log.info("[%s] %s", analyzer, s.get("line", s))
        for p, stats in sidecars:
            p.wait()
            s = self._stats(stats)
            stored += int(s.get("stored", 0))
            log.info("[sidecar] %s", s.get("line", s))
        return stored

    def _past_deadline(self) -> bool:
        return self.deadline is not None and time.time() >= self.deadline

    def work_left(self, phases: list[Phase], max_pages: int = 5) -> bool:
        """Any analyzer of these phases with a picture still to do that this machine has not given up on (failed 3
        times for this version) - else a backfill would wait forever for pictures nobody can analyse."""
        for phase in phases:
            if not gate_open(phase.gate, self.opts.profile, self.workstate):
                continue
            for analyzer in phase.stages + phase.sidecar:
                version = self.opts.profile.version(analyzer)
                after = None
                for _ in range(max_pages):
                    page = self.api.list_images(analyzer=analyzer, version=version, status="stale", limit=200, after=after)
                    if any(not self.workstate.given_up(analyzer, version, i.puzzle_id, i.fingerprint) for i in page["puzzles"]):
                        return True
                    after = page["nextCursor"]
                    if not after:
                        break
        return False

    def selected_phases(self) -> list[Phase]:
        prof = self.opts.profile
        if self.opts.phase:
            return [prof.phase(self.opts.phase)]
        if self.opts.daily:
            return [prof.phase(n) for n in prof.daily_phases]
        return list(prof.phases)

    def run(self) -> int:
        self.install_signals()
        caff = self.caffeinate()
        phases = self.selected_phases()
        try:
            while not self.stop.is_set() and not self._past_deadline():
                stored = sum(self.run_phase(p) for p in phases if not self.stop.is_set())
                if self.opts.daily or self.stop.is_set() or self._past_deadline():
                    break
                if stored == 0:
                    if not self.opts.follow and not self.work_left(phases):
                        log.info("nothing left to do for %s - done", ", ".join(p.name for p in phases))
                        break
                    log.info("no claimable work right now - sleeping %.0f min", self.opts.idle_minutes)
                    self.stop.wait(self.opts.idle_minutes * 60)
            return 0
        finally:
            for c in self.children:
                if c.poll() is None:
                    c.send_signal(signal.SIGTERM)
                    try:
                        c.wait(timeout=300)
                    except subprocess.TimeoutExpired:
                        c.kill()
            if caff is not None:
                caff.terminate()
