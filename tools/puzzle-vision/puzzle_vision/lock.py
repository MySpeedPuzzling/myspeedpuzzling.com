"""The machine-wide model lock shared with ComfyUI, the labs and every other local model user.

Convention (vision-analysis.md §8.6): `mkdir /tmp/msp-model-lock` is the lease; take it only with >= 40 % free memory
(`memory_pressure`), never remove someone else's lock. We write an owner file inside the directory so our own stale
lock (a crashed run) can be recognised; a lock without our owner file (a shell script, ComfyUI) is never touched.
"""

from __future__ import annotations

import json
import logging
import os
import re
import socket
import subprocess
import threading
import time
from pathlib import Path
from typing import Callable

log = logging.getLogger(__name__)

LOCK_DIR = Path("/tmp/msp-model-lock")
OWNER_FILE = "puzzle-vision.json"
MIN_FREE_PERCENT = 40
ABORT_BELOW_PERCENT = 25


class LockTimeout(RuntimeError):
    pass


def parse_memory_pressure(output: str) -> int | None:
    """`System-wide memory free percentage: 81%` -> 81."""
    for line in reversed(output.strip().splitlines()):
        m = re.search(r"free percentage:\s*(\d+)\s*%", line)
        if m:
            return int(m.group(1))
    return None


def free_memory_percent(run: Callable[..., subprocess.CompletedProcess] = subprocess.run) -> int:
    try:
        out = run(["memory_pressure"], capture_output=True, text=True, check=False).stdout
    except FileNotFoundError:
        return 100  # not a Mac (tests on Linux) - nothing to measure
    value = parse_memory_pressure(out or "")
    return 100 if value is None else value


def _pid_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


class ModelLock:
    def __init__(
        self,
        name: str,
        lock_dir: Path = LOCK_DIR,
        min_free: int = MIN_FREE_PERCENT,
        poll_seconds: float = 30.0,
        memory: Callable[[], int] = free_memory_percent,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self.name = name
        self.lock_dir = Path(lock_dir)
        self.min_free = min_free
        self.poll_seconds = poll_seconds
        self.memory = memory
        self.sleep = sleep
        self.held = False

    def _owner_path(self) -> Path:
        return self.lock_dir / OWNER_FILE

    def _clear_own_stale(self) -> None:
        """A lock our crashed process left behind (owner file with a dead pid on this host) is removed."""
        try:
            owner = json.loads(self._owner_path().read_text())
        except (OSError, ValueError):
            return
        if owner.get("host") == socket.gethostname() and not _pid_alive(int(owner.get("pid", 0))):
            log.warning("removing a stale model lock of a dead puzzle-vision process (pid %s)", owner.get("pid"))
            self._owner_path().unlink(missing_ok=True)
            try:
                self.lock_dir.rmdir()
            except OSError:
                pass

    def try_acquire(self) -> bool:
        self._clear_own_stale()
        try:
            self.lock_dir.mkdir()
        except FileExistsError:
            return False
        free = self.memory()
        if free < self.min_free:
            log.info("model lock free but only %d %% memory free (need %d %%) - waiting", free, self.min_free)
            self.lock_dir.rmdir()
            return False
        self._owner_path().write_text(json.dumps({"pid": os.getpid(), "host": socket.gethostname(), "stage": self.name, "since": time.time()}))
        self.held = True
        log.info("model lock acquired for %s (%d %% memory free)", self.name, free)
        return True

    def acquire(self, timeout: float | None = None, stop: threading.Event | None = None) -> None:
        start = time.monotonic()
        while not self.try_acquire():
            if stop is not None and stop.is_set():
                raise LockTimeout("stopped while waiting for the model lock")
            if timeout is not None and time.monotonic() - start > timeout:
                raise LockTimeout(f"model lock {self.lock_dir} not acquired within {timeout:.0f} s")
            log.info("waiting for the model lock %s / memory", self.lock_dir)
            self.sleep(self.poll_seconds)

    def release(self) -> None:
        if not self.held:
            return
        try:
            owner = json.loads(self._owner_path().read_text())
            if int(owner.get("pid", -1)) != os.getpid():
                log.warning("model lock is not ours any more - leaving it")
                self.held = False
                return
        except (OSError, ValueError):
            pass
        self._owner_path().unlink(missing_ok=True)
        try:
            self.lock_dir.rmdir()
        except OSError:
            pass
        self.held = False

    def __enter__(self) -> "ModelLock":
        self.acquire()
        return self

    def __exit__(self, *exc: object) -> None:
        self.release()


class MemoryWatchdog(threading.Thread):
    """Sets `stop` when free memory falls under 25 % (the bake-off's watchdog): the stage then flushes, releases its
    claims and exits instead of pushing the machine into swap."""

    def __init__(self, stop: threading.Event, interval: float = 10.0, threshold: int = ABORT_BELOW_PERCENT,
                 memory: Callable[[], int] = free_memory_percent) -> None:
        super().__init__(daemon=True)
        self.stop_event = stop
        self.interval = interval
        self.threshold = threshold
        self.memory = memory
        self.tripped = False

    def run(self) -> None:
        while not self.stop_event.is_set():
            if self.memory() < self.threshold:
                log.error("memory critical (< %d %% free) - stopping the stage", self.threshold)
                self.tripped = True
                self.stop_event.set()
                return
            self.stop_event.wait(self.interval)
