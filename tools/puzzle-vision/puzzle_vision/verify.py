"""`verify`:
- `--describe-parity` (plan §2.3): this machine re-runs `describe` on N puzzles another machine already described
  (same version) and compares the model's answers byte for byte (canonical JSON - jsonb keeps no key order). Only when
  all N are identical does the marker open this profile's describe phase; otherwise the machine never claims describe.
- `--end-to-end --limit N` (§2.8): every analyzer of the profile runs for N images against production (real claims,
  real stores - backfill work, harmless), then the counts are read back.
"""

from __future__ import annotations

import json
import logging
import time
from typing import Any, Callable

from .api import InternalApi, WorkItem, uuid_before
from .stages.describe import parity_key
from .workstate import WorkState

log = logging.getLogger(__name__)

PARITY_MARKER = "describe-parity"


def parity_targets(api: InternalApi, version: str, limit: int) -> list[tuple[WorkItem, dict[str, Any]]]:
    """The first `limit` puzzles (id order) whose current describe result has this version - deterministic, so both
    machines pick the same ones."""
    out: list[tuple[WorkItem, dict[str, Any]]] = []
    for row in api.iter_analyses("describe", only_current=True, limit=200):
        if row.get("analyzerVersion") != version:
            continue
        page = api.list_images(status="all", after=uuid_before(row["puzzleId"]), limit=1)
        items = [i for i in page["puzzles"] if i.puzzle_id == row["puzzleId"] and i.fingerprint == row["imageFingerprint"]]
        if items:
            out.append((items[0], row["result"]))
        if len(out) >= limit:
            break
    return out


def compare(local: dict[str, Any], remote: dict[str, Any]) -> list[str]:
    """Fields that differ (empty = identical)."""
    a, b = json.loads(parity_key(local)), json.loads(parity_key(remote))
    return sorted(k for k in set(a) | set(b) if a.get(k) != b.get(k))


def describe_parity(targets: list[tuple[WorkItem, dict[str, Any]]], describe: Callable[[WorkItem], dict[str, Any]],
                    workstate: WorkState, version: str, min_n: int = 20) -> dict[str, Any]:
    diffs = []
    for item, remote in targets:
        local = describe(item)
        d = compare(local, remote)
        if d:
            diffs.append({"puzzleId": item.puzzle_id, "fields": d})
    passed = len(targets) >= min_n and not diffs
    result = {"passed": passed, "version": version, "compared": len(targets), "different": diffs, "at": time.time()}
    if passed:
        workstate.set_marker(PARITY_MARKER, result)
    else:
        workstate.delete_marker(PARITY_MARKER)
        if len(targets) < min_n:
            result["reason"] = f"only {len(targets)} described puzzles to compare (need {min_n}) - try again later"
    return result
