"""`neighbours` - the most similar puzzles by embedding (image-taxonomy.md §11.2): top 30 by cosine for SigLIP 2
(crop, else full image) and DINOv2, the puzzle itself left out. Lists of [puzzleId, score] pairs (never a map with
id keys). Hidden puzzles are not in the bulk read, so they are never named; consumers still re-check every named id
(a puzzle may become secret later)."""

from __future__ import annotations

import numpy as np

TOP = 30


def top_k(ids: list[str], vectors: np.ndarray, k: int = TOP, block: int = 1024) -> dict[str, list[list]]:
    n = len(ids)
    out: dict[str, list[list]] = {}
    if n == 0:
        return out
    v = vectors.astype(np.float32)
    kk = min(k, n - 1)
    for start in range(0, n, block):
        sims = v[start:start + block] @ v.T
        for r in range(sims.shape[0]):
            sims[r, start + r] = -np.inf
        if kk <= 0:
            for r in range(sims.shape[0]):
                out[ids[start + r]] = []
            continue
        part = np.argpartition(-sims, kk - 1, axis=1)[:, :kk]
        for r in range(sims.shape[0]):
            idx = part[r][np.argsort(-sims[r, part[r]], kind="stable")]
            out[ids[start + r]] = [[ids[j], round(float(sims[r, j]), 4)] for j in idx]
    return out


def compute(siglip_ids: list[str], siglip: np.ndarray, dino_ids: list[str], dino: np.ndarray) -> dict[str, dict]:
    s = top_k(siglip_ids, siglip)
    d = top_k(dino_ids, dino)
    corpus = len(set(siglip_ids) | set(dino_ids))
    return {pid: {"siglip": s.get(pid, []), "dinov2": d.get(pid, []), "corpusSize": corpus} for pid in sorted(set(s) | set(d))}


def changed(old: dict | None, new: dict) -> bool:
    """Push only when the named neighbours changed (scores move a little whenever the corpus grows)."""
    if not isinstance(old, dict) or not old:
        return True
    for key in ("siglip", "dinov2"):
        if [x[0] for x in (old.get(key) or [])] != [x[0] for x in (new.get(key) or [])]:
            return True
    return False
