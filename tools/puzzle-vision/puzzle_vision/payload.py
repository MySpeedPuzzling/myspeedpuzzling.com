"""Payload helpers: the common envelope (image-taxonomy.md §11), the 64 KB limit measured like the server measures
it (PHP json_encode with unescaped slashes + unicode), NUL stripping, base64 float16 vectors."""

from __future__ import annotations

import base64
import json
import math
from typing import Any

import numpy as np

MAX_RESULT_BYTES = 65536


class PayloadTooLarge(ValueError):
    pass


def clean(value: Any) -> Any:
    """Postgres refuses NUL characters anywhere in jsonb, and JSON has no NaN/Infinity: strip / null them."""
    if isinstance(value, str):
        return value.replace("\x00", "")
    if isinstance(value, dict):
        return {clean(str(k)): clean(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [clean(v) for v in value]
    if isinstance(value, (np.floating,)):
        value = float(value)
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, (np.bool_,)):
        return bool(value)
    if isinstance(value, float) and not math.isfinite(value):
        return None
    return value


def encoded_size(result: dict[str, Any]) -> int:
    return len(json.dumps(result, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


def check_size(result: dict[str, Any], limit: int = MAX_RESULT_BYTES) -> int:
    size = encoded_size(result)
    if size > limit:
        raise PayloadTooLarge(f"result is {size} bytes of JSON, the limit is {limit}")
    return size


def numeric_key_paths(value: Any, path: str = "result") -> list[str]:
    """Maps whose keys are all numbers come back from the API as lists (internal-api.md "Payload notes")."""
    found: list[str] = []
    if isinstance(value, dict):
        if value and all(str(k).lstrip("-").isdigit() for k in value):
            found.append(path)
        for k, v in value.items():
            found += numeric_key_paths(v, f"{path}.{k}")
    elif isinstance(value, list):
        for i, v in enumerate(value):
            found += numeric_key_paths(v, f"{path}[{i}]")
    return found


def envelope(
    result: dict[str, Any],
    *,
    schema: int,
    model: str,
    source_sha256: str | None,
    crop: dict[str, Any] | None,
    runtime_ms: float,
) -> dict[str, Any]:
    """The common envelope fields first, then the analyzer's own fields (jsonb does not keep key order anyway)."""
    out: dict[str, Any] = {
        "schema": schema,
        "model": model,
        "sourceSha256": source_sha256,
        "crop": crop,
        "runtimeMs": int(round(runtime_ms)),
    }
    for key, value in result.items():
        if key not in out:
            out[key] = value
    return clean(out)


def vector_to_b64(vector: Any) -> str:
    """L2-normalised float16 little-endian, base64 (~3 KB for 1152 dims)."""
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    norm = float(np.linalg.norm(v))
    if norm > 0:
        v = v / norm
    return base64.b64encode(v.astype("<f2").tobytes()).decode("ascii")


def b64_to_vector(data: str) -> np.ndarray:
    return np.frombuffer(base64.b64decode(data), dtype="<f2").astype(np.float32)
