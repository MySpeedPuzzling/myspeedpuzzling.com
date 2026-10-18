"""`zeroshot` - taxonomy key scores from the stored SigLIP 2 embeddings (image-taxonomy.md §11.2): the cosine of each
puzzle's image vector (crop, else full image) with each key's prompt, turned into a **percentile within the corpus**
(comparable across keys, calibrated). A new key = new prompts, no image. The text side needs the SigLIP 2 text encoder
(loaded under the model lock, its features cached on disk per prompt bank)."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import numpy as np

from ..analyzers import SIGLIP2
from .taxonomy import PROMPT_BANK_VERSION, TAXONOMY_VERSION, prompt_bank

log = logging.getLogger(__name__)


def text_features(keys: list[str], prompts: list[str], cache_dir: Path) -> np.ndarray:
    """L2-normalised SigLIP 2 text features of the prompts, cached in `<cache>/zeroshot-<bank>.npz`."""
    cache = Path(cache_dir) / f"zeroshot-{PROMPT_BANK_VERSION}.npz"
    if cache.exists():
        z = np.load(cache, allow_pickle=False)
        if list(z["keys"]) == keys:
            return z["features"].astype(np.float32)
    import torch
    from transformers import AutoModel, AutoTokenizer

    model = AutoModel.from_pretrained(SIGLIP2).eval()
    tok = AutoTokenizer.from_pretrained(SIGLIP2)
    with torch.no_grad():
        x = tok([f"this is {p}." for p in prompts], padding="max_length", max_length=64, return_tensors="pt")
        t = model.get_text_features(**x)
        t = getattr(t, "pooler_output", t)
        feats = torch.nn.functional.normalize(t, dim=-1).numpy().astype(np.float32)
    cache.parent.mkdir(parents=True, exist_ok=True)
    np.savez(cache, keys=np.array(keys), features=feats)
    return feats


def percentiles(cos: np.ndarray) -> np.ndarray:
    """Column-wise percentile rank in 0..1 (ties share the average rank)."""
    from scipy.stats import rankdata

    n = cos.shape[0]
    if n <= 1:
        return np.ones_like(cos)
    return (rankdata(cos, axis=0, method="average") - 1) / (n - 1)


def compute(puzzle_ids: list[str], vectors: np.ndarray, sources: list[str], keys: list[str], text: np.ndarray) -> dict[str, dict[str, Any]]:
    cos = vectors @ text.T
    pct = percentiles(cos)
    out: dict[str, dict[str, Any]] = {}
    for i, pid in enumerate(puzzle_ids):
        out[pid] = {
            "taxonomy": TAXONOMY_VERSION,
            "prompts": PROMPT_BANK_VERSION,
            "scale": "percentile",
            "input": sources[i],
            "corpusSize": len(puzzle_ids),
            "scores": {k: round(float(pct[i, j]), 3) for j, k in enumerate(keys)},
        }
    return out


def bank() -> tuple[list[str], list[str]]:
    b = prompt_bank()
    keys = list(b)
    return keys, [b[k] for k in keys]
