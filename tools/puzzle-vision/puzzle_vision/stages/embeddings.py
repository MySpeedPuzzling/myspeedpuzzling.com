"""Image embeddings (image-taxonomy.md §11.1), base64 float16 L2-normalised inside the JSON (~3 KB a vector):

- `embed_siglip2` - SigLIP 2 so400m/14-384 of the artwork **crop** (`vector`), needs `artwork`;
- `embed_siglip2_full` - the same model on the **whole image** (`vector`, envelope `crop: null`), no dependency;
- `embed_dinov2` - DINOv2-L/14 CLS of the crop (`cls`), 224 px short side, centre crop;
- `embed_dinov2_patches` - the same model's patch tokens of the **whole** crop (224×224 squash, no centre crop): their
  mean (`mean`) and a 2×2 grid of means (`grid`, row-major from the top left, each L2-normalised) - region-level
  similarity for finding a puzzle from a photo of a part of it (a half-done puzzle, a corner of a box). Its own
  analyzer so the bulk reads of `embed_dinov2` stay small (~14 KB a payload, 4× the CLS one).

SigLIP gets a fixed 384×384 input (squash - the processor squashes to that anyway) so MPS never recompiles kernels for
a new input shape (5× slower for the first ~30 s otherwise, §19). Batches of 8.
"""

from __future__ import annotations

import base64
from typing import Any

import numpy as np
from PIL import Image

from ..analyzers import DINOV2_L, SIGLIP2
from ..payload import vector_to_b64
from .base import Prepared, Stage

SIGLIP_SIDE = 384
DINOV2_SIDE = 224  # 16×16 patches of 14 px
GRID = 2


def siglip_payload(vector: Any) -> dict[str, Any]:
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    return {"dim": int(v.size), "dtype": "f16", "norm": "l2", "vector": vector_to_b64(v), "preprocess": "384 squash, siglip2 processor"}


def dinov2_payload(vector: Any) -> dict[str, Any]:
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    return {"dim": int(v.size), "dtype": "f16", "norm": "l2", "cls": vector_to_b64(v), "preprocess": "224 short side, centre"}


def squash(rgb: np.ndarray, side: int = SIGLIP_SIDE) -> Image.Image:
    return Image.fromarray(rgb).resize((side, side), Image.BICUBIC)


def dinov2_patches_payload(mean: Any, grid: Any) -> dict[str, Any]:
    m = np.asarray(mean, dtype=np.float32).reshape(-1)
    g = np.asarray(grid, dtype=np.float32).reshape(GRID * GRID, -1)
    return {"dim": int(m.size), "dtype": "f16", "norm": "l2", "mean": vector_to_b64(m), "grid": cells_to_b64(g),
            "gridShape": [GRID, GRID], "preprocess": "224 squash, dinov2 processor without resize/crop"}


def cells_to_b64(cells: np.ndarray) -> str:
    """Several unit vectors in one base64 float16 string, each keeping its own norm (vector_to_b64 would rescale the
    whole) - read back with b64_to_vector(...).reshape(cells, dim)."""
    return base64.b64encode(np.asarray(cells, dtype=np.float32).astype("<f2").tobytes()).decode("ascii")


def pooled_patches(tokens: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Patch tokens (side, side, dim) -> (L2 mean, GRID×GRID L2 cell means, row-major from the top left)."""
    side = tokens.shape[0]
    cells = [tokens[r * side // GRID:(r + 1) * side // GRID, c * side // GRID:(c + 1) * side // GRID].mean(axis=(0, 1))
             for r in range(GRID) for c in range(GRID)]
    unit = lambda v: v / max(float(np.linalg.norm(v)), 1e-12)  # noqa: E731
    return unit(tokens.mean(axis=(0, 1))), np.stack([unit(c) for c in cells])


class _Torch:
    def _device(self) -> str:
        import torch

        return "mps" if torch.backends.mps.is_available() else "cpu"


class Siglip2Stage(Stage, _Torch):
    analyzer = "embed_siglip2"
    upstream = ("artwork",)
    uses_crop = True
    batch_size = 8

    @property
    def model(self) -> str:
        return f"{SIGLIP2} fp16"

    def load(self) -> None:
        import torch
        from transformers import AutoModel, AutoProcessor

        self.torch = torch
        self.device = self._device()
        self.net = AutoModel.from_pretrained(SIGLIP2, dtype=torch.float16).to(self.device).eval()
        self.proc = AutoProcessor.from_pretrained(SIGLIP2)

    def vectors(self, images: list[Image.Image]) -> np.ndarray:
        torch = self.torch
        with torch.no_grad():
            x = self.proc(images=images, return_tensors="pt").to(self.device)
            f = self.net.get_image_features(pixel_values=x["pixel_values"].half())
            f = getattr(f, "pooler_output", f)
            return torch.nn.functional.normalize(f.float(), dim=-1).cpu().numpy()

    def analyse_batch(self, ps: list[Prepared]) -> list[dict[str, Any] | Exception]:
        try:
            vs = self.vectors([squash(p.subject) for p in ps])
        except Exception as e:  # noqa: BLE001 - fall back to one at a time to isolate the bad image
            if len(ps) == 1:
                return [e]
            return [r for p in ps for r in self.analyse_batch([p])]
        return [siglip_payload(v) for v in vs]


class Siglip2FullStage(Siglip2Stage):
    analyzer = "embed_siglip2_full"
    upstream = ()
    uses_crop = False


class Dinov2Stage(Stage, _Torch):
    analyzer = "embed_dinov2"
    upstream = ("artwork",)
    uses_crop = True
    batch_size = 8

    @property
    def model(self) -> str:
        return f"{DINOV2_L} fp16"

    def load(self) -> None:
        import torch
        from transformers import AutoImageProcessor, AutoModel

        self.torch = torch
        self.device = self._device()
        self.net = AutoModel.from_pretrained(DINOV2_L, dtype=torch.float16).to(self.device).eval()
        self.proc = AutoImageProcessor.from_pretrained(DINOV2_L)

    def analyse_batch(self, ps: list[Prepared]) -> list[dict[str, Any] | Exception]:
        torch = self.torch
        try:
            with torch.no_grad():
                x = self.proc(images=[Image.fromarray(p.subject) for p in ps], return_tensors="pt").to(self.device)
                v = self.net(pixel_values=x["pixel_values"].half()).last_hidden_state[:, 0]
                vs = torch.nn.functional.normalize(v.float(), dim=-1).cpu().numpy()
        except Exception as e:  # noqa: BLE001
            if len(ps) == 1:
                return [e]
            return [r for p in ps for r in self.analyse_batch([p])]
        return [dinov2_payload(v) for v in vs]


class Dinov2PatchesStage(Dinov2Stage):
    analyzer = "embed_dinov2_patches"

    def analyse_batch(self, ps: list[Prepared]) -> list[dict[str, Any] | Exception]:
        torch = self.torch
        try:
            images = [squash(p.subject, DINOV2_SIDE) for p in ps]
            with torch.no_grad():
                x = self.proc(images=images, do_resize=False, do_center_crop=False, return_tensors="pt").to(self.device)
                t = self.net(pixel_values=x["pixel_values"].half()).last_hidden_state[:, 1:].float().cpu().numpy()
        except Exception as e:  # noqa: BLE001
            if len(ps) == 1:
                return [e]
            return [r for p in ps for r in self.analyse_batch([p])]
        side = DINOV2_SIDE // 14
        return [dinov2_patches_payload(*pooled_patches(v.reshape(side, side, -1))) for v in t]
