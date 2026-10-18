"""`segmentation` of the artwork crop - EoMT-L COCO panoptic 640 (MIT, `tue-mps/coco_panoptic_eomt_large_640`), fp16 on
MPS: class shares (>= 0.002), instance counts of the "thing" classes and a 16×16 grid of the dominant class id
(image-taxonomy.md §11.1). The class list is COCO panoptic's 133 (80 things, then 53 stuff)."""

from __future__ import annotations

from typing import Any

import numpy as np
from PIL import Image

from ..analyzers import EOMT_L
from .base import Prepared, Stage

MIN_SHARE = 0.002
THING_CLASSES = 80
VOID = 255


def summarise(seg_ids: np.ndarray, segments: list[dict[str, Any]], id2label: dict[int, str]) -> dict[str, Any]:
    """seg_ids: H×W segment ids (0 / unknown = void); segments: [{id, label_id}]."""
    total = seg_ids.size
    label_of = {int(s["id"]): int(s["label_id"]) for s in segments}
    shares: dict[str, float] = {}
    instances: dict[str, int] = {}
    class_map = np.full(seg_ids.shape, VOID, dtype=np.int32)
    for sid, lid in label_of.items():
        mask = seg_ids == sid
        n = int(mask.sum())
        if n == 0:
            continue
        class_map[mask] = lid
        name = id2label.get(lid, str(lid))
        shares[name] = shares.get(name, 0.0) + n / total
        if lid < THING_CLASSES:
            instances[name] = instances.get(name, 0) + 1
    classes = {k: round(v, 4) for k, v in sorted(shares.items(), key=lambda kv: -kv[1]) if v >= MIN_SHARE}
    # an empty map is sent as null (it would come back from the API as a list)
    return {"classes": classes or None, "instances": dict(sorted(instances.items())) or None, "grid16": grid16(class_map),
            "classList": "coco-panoptic-133"}


def grid16(class_map: np.ndarray) -> list[int]:
    """Dominant class id per cell of a 16×16 grid (255 = no segment)."""
    h, w = class_map.shape
    out = []
    for r in range(16):
        for c in range(16):
            cell = class_map[r * h // 16:max(r * h // 16 + 1, (r + 1) * h // 16), c * w // 16:max(c * w // 16 + 1, (c + 1) * w // 16)]
            vals, counts = np.unique(cell, return_counts=True)
            out.append(int(vals[np.argmax(counts)]))
    return out


class SegmentationStage(Stage):
    analyzer = "segmentation"
    upstream = ("artwork",)
    uses_crop = True

    @property
    def model(self) -> str:
        return f"{EOMT_L} fp16 640"

    def load(self) -> None:
        import torch
        from transformers import AutoImageProcessor, EomtForUniversalSegmentation

        self.torch = torch
        self.device = "mps" if torch.backends.mps.is_available() else "cpu"
        self.proc = AutoImageProcessor.from_pretrained(EOMT_L)
        self.net = EomtForUniversalSegmentation.from_pretrained(EOMT_L, dtype=torch.float16).to(self.device).eval()
        self.id2label = {int(k): str(v) for k, v in self.net.config.id2label.items()}

    def analyse(self, p: Prepared) -> dict[str, Any]:
        torch = self.torch
        im = Image.fromarray(p.subject)
        with torch.no_grad():
            x = self.proc(images=im, return_tensors="pt").to(self.device)
            out = self.net(pixel_values=x["pixel_values"].to(torch.float16))
            seg = self.proc.post_process_panoptic_segmentation(out, target_sizes=[(im.height, im.width)])[0]
        return summarise(seg["segmentation"].cpu().numpy(), seg["segments_info"], self.id2label)
