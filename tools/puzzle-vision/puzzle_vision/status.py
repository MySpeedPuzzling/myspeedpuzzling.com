"""Coverage per analyzer and version (done / stale / missing / waiting on requires / claimed), throughput from the
results' receivedAt (all machines) and from the local workstate (this machine), failures, ETA - one walk of the work
list (~42 requests for 41k puzzles)."""

from __future__ import annotations

import time
from datetime import datetime
from typing import Any, Iterable

from .analyzers import DERIVED, RAW, REQUIRES
from .api import WorkItem
from .profiles import Profile
from .workstate import WorkState


def _ts(value: str | None) -> float | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
    except ValueError:
        return None


def coverage(items: Iterable[WorkItem], versions: dict[str, str], now: float | None = None) -> dict[str, dict[str, Any]]:
    now = now or time.time()
    out = {a: {"version": v, "total": 0, "done": 0, "stale": 0, "missing": 0, "waiting": 0, "claimed": 0,
               "receivedLastHour": 0, "receivedLast6h": 0, "receivedLast24h": 0, "claimedBy": {}} for a, v in versions.items()}
    for item in items:
        for a, version in versions.items():
            c = out[a]
            c["total"] += 1
            row = item.analyses.get(a)
            if row and row.get("imageFingerprint") == item.fingerprint and row.get("analyzerVersion") == version:
                c["done"] += 1
                t = _ts(row.get("receivedAt"))
                if t:
                    c["receivedLastHour"] += t >= now - 3600
                    c["receivedLast6h"] += t >= now - 6 * 3600
                    c["receivedLast24h"] += t >= now - 86400
                continue
            if row:
                c["stale"] += 1
            else:
                c["missing"] += 1
            if any(not item.has_current(r) for r in REQUIRES.get(a, ())):
                c["waiting"] += 1
            claim = item.claims.get(a)
            if claim:
                c["claimed"] += 1
                w = claim.get("worker", "?")
                c["claimedBy"][w] = c["claimedBy"].get(w, 0) + 1
    for c in out.values():
        remaining = c["stale"] + c["missing"]
        rate = c["receivedLast6h"] / 6.0
        c["remaining"] = remaining
        c["etaHours"] = round(remaining / rate, 1) if rate > 0 and remaining else (0.0 if not remaining else None)
        c["percentDone"] = round(100.0 * c["done"] / c["total"], 1) if c["total"] else 0.0
    return out


def report(api: Any, profile: Profile, workstate: WorkState | None = None, all_analyzers: bool = False, report_dir: Any = None) -> dict[str, Any]:
    versions = {a: profile.version(a) for a in RAW if a in profile.analyzers}
    versions |= {a: profile.version(a) for a in DERIVED if a in profile.derived_versions}
    if all_analyzers:
        from . import profiles as P

        for name in P.available():
            other = P.load(name)
            for a in RAW:
                if a in other.analyzers:
                    versions.setdefault(a, other.version(a))
    cov = coverage(api.iter_images(status="all", limit=1000), versions)
    local: dict[str, Any] = {}
    if workstate is not None:
        now = time.time()
        for a in versions:
            local[a] = {"storedLastHour": workstate.stored_since(a, now - 3600), "storedLast24h": workstate.stored_since(a, now - 86400),
                        "progress": workstate.progress_counts(a)}
        local["failures"] = workstate.failure_counts()
        local["recentFailures"] = workstate.failures(limit=10)
        local["cacheBytes"] = workstate.cache_bytes()
    from .derive.difficulty_prior import blocked_lines

    return {"profile": profile.name, "coverage": cov, "thisMachine": local, "difficultyPriorBlocked": blocked_lines(report_dir)}


def render(rep: dict[str, Any]) -> str:
    lines = [f"profile {rep['profile']}", f"{'analyzer':<20}{'version':<28}{'done':>8}{'%':>7}{'stale':>7}{'missing':>9}{'waiting':>9}{'claimed':>9}{'/h (6h)':>9}{'ETA h':>8}"]
    for a, c in rep["coverage"].items():
        eta = "-" if c["etaHours"] is None else f"{c['etaHours']:.1f}"
        lines.append(f"{a:<20}{c['version'][:27]:<28}{c['done']:>8}{c['percentDone']:>7.1f}{c['stale']:>7}{c['missing']:>9}"
                     f"{c['waiting']:>9}{c['claimed']:>9}{c['receivedLast6h'] / 6:>9.0f}{eta:>8}")
    local = rep.get("thisMachine") or {}
    if local:
        lines.append("this machine (last hour / 24 h stored): " + ", ".join(
            f"{a} {v['storedLastHour']}/{v['storedLast24h']}" for a, v in local.items() if isinstance(v, dict) and "storedLastHour" in v and (v["storedLastHour"] or v["storedLast24h"])))
        lines.append(f"failures: {local.get('failures') or 'none'}; image cache {local.get('cacheBytes', 0) / 1e9:.1f} GB")
    lines.extend(rep.get("difficultyPriorBlocked") or [])
    return "\n".join(lines)
