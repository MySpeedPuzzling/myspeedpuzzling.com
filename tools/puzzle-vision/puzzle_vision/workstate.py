"""Temporary local work state (SQLite). Production is the truth - this is only a cache: the download index, per-image
failures, per-stage progress (throughput), cached upstream payloads (artwork / ocr for crop stages and describe),
the artwork gate's scores and markers (e.g. describe parity passed). Deleting it loses nothing but time."""

from __future__ import annotations

import json
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Iterable

MAX_ATTEMPTS = 3

SCHEMA = """
CREATE TABLE IF NOT EXISTS downloads (
    fingerprint TEXT PRIMARY KEY, puzzle_id TEXT NOT NULL, path TEXT NOT NULL, sha256 TEXT NOT NULL,
    width INTEGER NOT NULL, height INTEGER NOT NULL, original_width INTEGER, original_height INTEGER,
    bytes INTEGER NOT NULL, fetched_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS failures (
    analyzer TEXT NOT NULL, version TEXT NOT NULL, puzzle_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
    attempts INTEGER NOT NULL, last_error TEXT, last_at REAL NOT NULL,
    PRIMARY KEY (analyzer, version, puzzle_id, fingerprint)
);
CREATE TABLE IF NOT EXISTS progress (
    analyzer TEXT NOT NULL, version TEXT NOT NULL, puzzle_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
    status TEXT NOT NULL, worker TEXT, at REAL NOT NULL,
    PRIMARY KEY (analyzer, version, puzzle_id, fingerprint)
);
CREATE INDEX IF NOT EXISTS progress_at ON progress (analyzer, at);
CREATE TABLE IF NOT EXISTS artifacts (
    analyzer TEXT NOT NULL, puzzle_id TEXT NOT NULL, fingerprint TEXT NOT NULL, version TEXT NOT NULL,
    result TEXT NOT NULL, fetched_at REAL NOT NULL,
    PRIMARY KEY (analyzer, puzzle_id, fingerprint)
);
CREATE TABLE IF NOT EXISTS gate (
    fingerprint TEXT NOT NULL, gate_version TEXT NOT NULL, scores TEXT NOT NULL, flat INTEGER NOT NULL, at REAL NOT NULL,
    PRIMARY KEY (fingerprint, gate_version)
);
CREATE TABLE IF NOT EXISTS markers (key TEXT PRIMARY KEY, value TEXT NOT NULL, at REAL NOT NULL);
"""


class WorkState:
    def __init__(self, path: Path | str) -> None:
        self.path = str(path)
        if self.path != ":memory:":
            Path(self.path).parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.RLock()
        self._db = sqlite3.connect(self.path, check_same_thread=False, timeout=30)
        self._db.execute("PRAGMA journal_mode=WAL")
        self._db.executescript(SCHEMA)
        self._db.commit()

    def close(self) -> None:
        with self._lock:
            self._db.close()

    def _exec(self, sql: str, args: Iterable[Any] = ()) -> sqlite3.Cursor:
        with self._lock:
            cur = self._db.execute(sql, tuple(args))
            self._db.commit()
            return cur

    def _all(self, sql: str, args: Iterable[Any] = ()) -> list[tuple]:
        with self._lock:
            return self._db.execute(sql, tuple(args)).fetchall()

    # downloads -----------------------------------------------------------------------------------------------------
    def download(self, fingerprint: str) -> dict[str, Any] | None:
        rows = self._all("SELECT puzzle_id, path, sha256, width, height, original_width, original_height, bytes FROM downloads WHERE fingerprint = ?", [fingerprint])
        if not rows:
            return None
        r = rows[0]
        return dict(puzzle_id=r[0], path=r[1], sha256=r[2], width=r[3], height=r[4], original_width=r[5], original_height=r[6], bytes=r[7])

    def record_download(self, fingerprint: str, puzzle_id: str, path: str, sha256: str, width: int, height: int,
                        original_width: int, original_height: int, size: int) -> None:
        self._exec(
            "INSERT OR REPLACE INTO downloads VALUES (?,?,?,?,?,?,?,?,?,?)",
            [fingerprint, puzzle_id, path, sha256, width, height, original_width, original_height, size, time.time()],
        )

    def cache_bytes(self) -> int:
        return int(self._all("SELECT COALESCE(SUM(bytes), 0) FROM downloads")[0][0])

    # failures ------------------------------------------------------------------------------------------------------
    def record_failure(self, analyzer: str, version: str, puzzle_id: str, fingerprint: str, error: str) -> int:
        self._exec(
            """INSERT INTO failures VALUES (?,?,?,?,1,?,?)
               ON CONFLICT (analyzer, version, puzzle_id, fingerprint)
               DO UPDATE SET attempts = attempts + 1, last_error = excluded.last_error, last_at = excluded.last_at""",
            [analyzer, version, puzzle_id, fingerprint, error[:2000], time.time()],
        )
        return self.attempts(analyzer, version, puzzle_id, fingerprint)

    def attempts(self, analyzer: str, version: str, puzzle_id: str, fingerprint: str) -> int:
        rows = self._all("SELECT attempts FROM failures WHERE analyzer=? AND version=? AND puzzle_id=? AND fingerprint=?",
                         [analyzer, version, puzzle_id, fingerprint])
        return int(rows[0][0]) if rows else 0

    def given_up(self, analyzer: str, version: str, puzzle_id: str, fingerprint: str) -> bool:
        """Failed MAX_ATTEMPTS times for this version: skipped until a version bump (vision-analysis.md §8.4)."""
        return self.attempts(analyzer, version, puzzle_id, fingerprint) >= MAX_ATTEMPTS

    def failures(self, analyzer: str | None = None, limit: int = 50) -> list[dict[str, Any]]:
        sql = "SELECT analyzer, version, puzzle_id, fingerprint, attempts, last_error, last_at FROM failures"
        args: list[Any] = []
        if analyzer:
            sql += " WHERE analyzer = ?"
            args.append(analyzer)
        sql += " ORDER BY last_at DESC LIMIT ?"
        args.append(limit)
        keys = ("analyzer", "version", "puzzleId", "fingerprint", "attempts", "lastError", "lastAt")
        return [dict(zip(keys, r)) for r in self._all(sql, args)]

    def failure_counts(self) -> dict[str, int]:
        return {a: int(n) for a, n in self._all("SELECT analyzer, COUNT(*) FROM failures GROUP BY analyzer")}

    # progress ------------------------------------------------------------------------------------------------------
    def record_progress(self, analyzer: str, version: str, puzzle_id: str, fingerprint: str, status: str, worker: str = "") -> None:
        self._exec("INSERT OR REPLACE INTO progress VALUES (?,?,?,?,?,?,?)",
                   [analyzer, version, puzzle_id, fingerprint, status, worker, time.time()])

    def stored_since(self, analyzer: str, since: float) -> int:
        return int(self._all("SELECT COUNT(*) FROM progress WHERE analyzer=? AND status='stored' AND at >= ?", [analyzer, since])[0][0])

    def progress_counts(self, analyzer: str) -> dict[str, int]:
        return {s: int(n) for s, n in self._all("SELECT status, COUNT(*) FROM progress WHERE analyzer=? GROUP BY status", [analyzer])}

    # cached upstream payloads ----------------------------------------------------------------------------------------
    def put_artifact(self, analyzer: str, puzzle_id: str, fingerprint: str, version: str, result: dict[str, Any]) -> None:
        self._exec("INSERT OR REPLACE INTO artifacts VALUES (?,?,?,?,?,?)",
                   [analyzer, puzzle_id, fingerprint, version, json.dumps(result), time.time()])

    def artifact(self, analyzer: str, puzzle_id: str, fingerprint: str) -> dict[str, Any] | None:
        rows = self._all("SELECT version, result FROM artifacts WHERE analyzer=? AND puzzle_id=? AND fingerprint=?",
                         [analyzer, puzzle_id, fingerprint])
        if not rows:
            return None
        return {"analyzerVersion": rows[0][0], "result": json.loads(rows[0][1])}

    # artwork gate ----------------------------------------------------------------------------------------------------
    def put_gate(self, fingerprint: str, gate_version: str, scores: dict[str, float], flat: bool) -> None:
        self._exec("INSERT OR REPLACE INTO gate VALUES (?,?,?,?,?)", [fingerprint, gate_version, json.dumps(scores), int(flat), time.time()])

    def gate(self, fingerprint: str, gate_version: str) -> dict[str, Any] | None:
        rows = self._all("SELECT scores, flat FROM gate WHERE fingerprint=? AND gate_version=?", [fingerprint, gate_version])
        if not rows:
            return None
        return {"scores": json.loads(rows[0][0]), "flat": bool(rows[0][1])}

    # markers ---------------------------------------------------------------------------------------------------------
    def set_marker(self, key: str, value: Any) -> None:
        self._exec("INSERT OR REPLACE INTO markers VALUES (?,?,?)", [key, json.dumps(value), time.time()])

    def marker(self, key: str) -> Any:
        rows = self._all("SELECT value FROM markers WHERE key=?", [key])
        return json.loads(rows[0][0]) if rows else None

    def delete_marker(self, key: str) -> None:
        self._exec("DELETE FROM markers WHERE key=?", [key])
