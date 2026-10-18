"""Image embeddings (image-taxonomy.md §11.1), base64 float16 L2-normalised inside the JSON (~3 KB a vector):

- `embed_siglip2` - SigLIP 2 so400m/14-384 of the artwork **crop** (`vector`), needs `artwork`;
- `embed_siglip2_full` - the same model on the **whole image** (`vector`, envelope `crop: null`), no dependency;
- `embed_dinov2` - DINOv2-L/14 CLS of the crop (`cls`), 224 px short side, centre crop.

SigLIP gets a fixed 384×384 input (squash - the processor squashes to that anyway) so MPS never recompiles kernels for
a new input shape (5× slower for the first ~30 s otherwise, §19). Batches of 8.
"""

from __future__ import annotations

from typing import Any

import numpy as np
from PIL import Image

from ..analyzers import DINOV2_L, SIGLIP2
from ..payload import vector_to_b64
from .base import Prepared, Stage

SIGLIP_SIDE = 384


def siglip_payload(vector: Any) -> dict[str, Any]:
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    return {"dim": int(v.size), "dtype": "f16", "norm": "l2", "vector": vector_to_b64(v), "preprocess": "384 squash, siglip2 processor"}


def dinov2_payload(vector: Any) -> dict[str, Any]:
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    return {"dim": int(v.size), "dtype": "f16", "norm": "l2", "cls": vector_to_b64(v), "preprocess": "224 short side, centre"}


def squash(rgb: np.ndarray, side: int = SIGLIP_SIDE) -> Image.Image:
    return Image.fromarray(rgb).resize((side, side), Image.BICUBIC)


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
