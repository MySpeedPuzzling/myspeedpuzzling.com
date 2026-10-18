"""`bench --stage S --limit N`: times a stage on N real images against the measured M3 Max numbers
(image-taxonomy.md §19, ±30 %). Reads the work list, downloads, runs the model - stores nothing."""

from __future__ import annotations

import statistics
import time
from typing import Any

from .analyzers import M3_SECONDS_PER_IMAGE

TOLERANCE = 0.30


def verdict(stage: str, seconds: list[float]) -> dict[str, Any]:
    expected = M3_SECONDS_PER_IMAGE.get(stage)
    median = statistics.median(seconds) if seconds else None
    out: dict[str, Any] = {"stage": stage, "images": len(seconds), "medianSeconds": round(median, 3) if median is not None else None,
                           "meanSeconds": round(statistics.mean(seconds), 3) if seconds else None, "expectedM3": expected}
    if expected and median is not None:
        ratio = median / expected
        out["ratio"] = round(ratio, 2)
        out["withinTolerance"] = (1 - TOLERANCE) <= ratio <= (1 + TOLERANCE)
    return out


def time_calls(fn: Any, inputs: list[Any], warmup: int = 2) -> list[float]:
    for x in inputs[:warmup]:
        fn(x)
    out = []
    for x in inputs:
        t = time.perf_counter()
        fn(x)
        out.append(time.perf_counter() - t)
    return out
