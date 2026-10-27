"""Stage registry: analyzer -> Stage class. The modules import only light libraries at module level."""

from __future__ import annotations

from typing import Any

from .apple_vision import BarcodeStage, OcrStage, VisionLabelsStage
from .artwork import ArtworkStage
from .base import Stage
from .colors_traits import ColorsStage, TraitsStage
from .describe import DescribeStage
from .embeddings import Dinov2PatchesStage, Dinov2Stage, Siglip2FullStage, Siglip2Stage
from .piece_scale import PieceScaleStage
from .segmentation import SegmentationStage

STAGES: dict[str, type[Stage]] = {
    "artwork": ArtworkStage,
    "ocr": OcrStage,
    "barcode": BarcodeStage,
    "vision_labels": VisionLabelsStage,
    "embed_siglip2": Siglip2Stage,
    "embed_siglip2_full": Siglip2FullStage,
    "embed_dinov2": Dinov2Stage,
    "embed_dinov2_patches": Dinov2PatchesStage,
    "segmentation": SegmentationStage,
    "colors": ColorsStage,
    "traits": TraitsStage,
    "piece_scale": PieceScaleStage,
    "describe": DescribeStage,
}


def create(analyzer: str, version: str, workers: int = 1, workstate: Any = None) -> Stage:
    cls = STAGES[analyzer]
    if cls is ArtworkStage:
        return ArtworkStage(version, workers, workstate=workstate)
    return cls(version, workers)
